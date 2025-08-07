<?php

/**
 * Open Data Repository Data Publisher
 * DecimalValue Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The DecimalValue Entity is automatically generated from
 * ./Resources/config/doctrine/DecimalValue.orm.yml
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use ODR\AdminBundle\Component\Utility\ValidUtility;


/**
 * DecimalValue
 */
class DecimalValue
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $original_value;

    /**
     * @var float
     */
    private $value;

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
     * @var \ODR\AdminBundle\Entity\DataRecordFields
     */
    private $dataRecordFields;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var \ODR\AdminBundle\Entity\FieldType
     */
    private $fieldType;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $dataRecord;

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
     * Set original_value
     *
     * @param string $originalValue
     * @return DecimalValue
     */
    public function setOriginalValue($originalValue)
    {
        $this->original_value = $originalValue;

        return $this;
    }

    /**
     * Get original_value
     *
     * @return string 
     */
    public function getOriginalValue()
    {
        return $this->original_value;
    }

    /**
     * Set value
     *
     * @param float $value
     * @return DecimalValue
     */
    public function setValue($value)
    {
        // Don't try to process an empty/null value
        if ( $value === '' || is_null($value) ) {
            $this->original_value = null;
            $this->value = null;

            return $this;
        }
        $value = trim($value);

        // Don't try to process an illegal value either
        if ( !ValidUtility::isValidDecimal($value) ) {
            $this->original_value = null;
            $this->value = null;

            return $this;
        }

        // floatval() will always return something sensible at this point
        $this->value = floatval($value);
        // Unlike mysql, php's floatval() doesn't choke on values like "12.34(56)"

        // There are only at most two changes that should be made to the "original" value...any
        //  leading zeros should be eliminated, and a fractional value without a leading zero
        //  e.g. ".12"  should have a leading zero added

        // Temporarily remove the negative sign if it exists
        $negative = false;
        if ( $value[0] === '-' ) {
            $negative = true;
            $value = substr($value, 1);
        }

        // Strip all leading zeros
        $value = ltrim($value, '0');

        if ( $value === '' ) {
            // If nothing remains, then the value was zero to begin with
            $value = '0';
        }
        else if ( $value[0] === '.' ) {
            // If the first remaining character is a period, then re-add a zero in front of it
            $value = '0'.$value;
        }

        // Reapply the negative sign, unless the resulting value is zero
        if ( $negative && $this->value != 0 )
            $value = '-'.$value;

        // Store the potentially modified value
        $this->original_value = $value;

        return $this;
    }

    /**
     * Get value
     *
     * @return float 
     */
    public function getValue()
    {
        //return $this->value;
        return $this->original_value;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return DecimalValue
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
     * @return DecimalValue
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
     * @return DecimalValue
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
     * Set dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     * @return DecimalValue
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

    /**
     * Set dataField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataField
     * @return DecimalValue
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
     * Set fieldType
     *
     * @param \ODR\AdminBundle\Entity\FieldType $fieldType
     * @return DecimalValue
     */
    public function setFieldType(\ODR\AdminBundle\Entity\FieldType $fieldType = null)
    {
        $this->fieldType = $fieldType;

        return $this;
    }

    /**
     * Get fieldType
     *
     * @return \ODR\AdminBundle\Entity\FieldType 
     */
    public function getFieldType()
    {
        return $this->fieldType;
    }

    /**
     * Set dataRecord
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $dataRecord
     * @return DecimalValue
     */
    public function setDataRecord(\ODR\AdminBundle\Entity\DataRecord $dataRecord = null)
    {
        $this->dataRecord = $dataRecord;

        return $this;
    }

    /**
     * Get dataRecord
     *
     * @return \ODR\AdminBundle\Entity\DataRecord 
     */
    public function getDataRecord()
    {
        return $this->dataRecord;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return DecimalValue
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
     * @return DecimalValue
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
