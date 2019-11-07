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
        if ($value === '' || is_null($value)) {     // needs to be '==='... a value of 0 == ''
            $this->original_value = null;
            $this->value = null;

            return $this;
        }

        $value = strval(trim($value));

        // Store whether this was given a valid decimal value or not...
        $original_value_is_valid = ValidUtility::isValidDecimal($value);

        // Preserve negative
        $negative = false;
        if ( substr($value, 0, 1) == '-' ) {
            $negative = true;
            $value = substr($value, 1);
        }

        // Always remove leading zeros
        $leading = 0;
        for ($i = 0; $i < strlen($value)-1; $i++) {
            if ( substr($value, $i, 1) == '0' )
                $leading++;
            else
                break;
        }
        $value = substr($value, $leading);

        $period = strpos($value, '.');
        if ( $period !== false ) {
            // Break into right/left halves based on period
            $left = substr($value, 0, $period);
            $right = substr($value, $period+1);

            // Strip trailing zeros from right side
            // Actually, don't want to do this..."4.500" is valid, indicating confidence out to thousandths
//            $trailing = 0;
//            for ($i = strlen($right)-1; $i > 0; $i--) {
//                if ( substr($right, $i, 1) == '0' )
//                    $trailing++;
//                else
//                    break;
//            }
//            $right = substr($right, 0, strlen($right)-$trailing);

            // If nothing remaining on the left side, default to 0
            if ($left == '')
                $left = '0';

            // If nothing remaining on the right side, default to the left side...otherwise, recombine the two halves
            if ($right == '' || $right == '0')
                $value = $left;
            else
                $value = $left.'.'.$right;
        }

        // Re-apply negative if necessary
        if ($value == '')
            $value = 0;
        else if ($negative && $value !== '0')
            $value = '-'.$value;

        // Save the approximation for searching purposes
        $this->value = floatval($value);

        // If the original string was a valid decimal value, then store it...otherwise, store
        //  whatever floatval() ended up returning
        if ( $original_value_is_valid )
            $this->original_value = $value;
        else
            $this->original_value = strval($this->value);

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
