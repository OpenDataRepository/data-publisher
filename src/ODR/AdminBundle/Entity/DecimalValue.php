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
*
* Unlike other storage entities, getValue() and setValue() have
* to calculate the value "stored" in here from a (base, exponent)
* pair.
*/


namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ODR\AdminBundle\Entity\DecimalValue
 */
class DecimalValue
{
    /**
     * @var integer $id
     */
    private $id;

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
     * @var float
     */
    private $value;

    /**
     * Save value
     *
     * @param decimal $value
     * @return DecimalValue
     */
    public function setValue($value)
    {
        $value = strval(trim($value));
        $base = 0;
        $exponent = 0;

        // Save the approximation for searching purposes
        $this->value = floatval($value);

        // Preserve negative
        $negative = false;
        if ( substr($value, 0, 1) == '-' ) {
            $negative = true;
            $value = substr($value, 1);
        }

        // Always remove leading zeros from left side
        $leading = 0;
        for ($i = 0; $i < strlen($value)-1; $i++) {
            if ( substr($value, $i, 1) == '0' )
                $leading++;
            else
                break;
        }
        $value = substr($value, $leading);

        $period = strpos($value, '.');
        if ( $period === false ) {
            // Remove trailing zeros from base and compress
            for ($i = strlen($value)-1; $i > 0; $i--) {
                if ( substr($value, $i, 1) == '0' )
                    $exponent++;
                else
                    break;
            }
            $base = intval( substr($value, 0, strlen($value)-$exponent) );
        }
        else {
            // Break into right/left halves based on period
            $left = substr($value, 0, $period);
            $right = substr($value, $period+1);

            // Strip trailing zeros from right side
            $trailing = 0;
            for ($i = strlen($right)-1; $i > 0; $i--) {
                if ( substr($right, $i, 1) == '0' )
                    $trailing++;
                else
                    break;
            }
            $right = substr($right, 0, strlen($right)-$trailing);
                
            if ( intval($left) == 0 ) {
                // If number is purely fractional...i.e. -1 < x < 1...convert right side to int to get rid of zeros between decimal point and rest of number
                $base = intval($right);
            }
            else {
                // ...otherwise, just get rid of the decimal point
                $base = $left.$right;
            }

            // Exponent is always negative in this case
            $exponent = intval( '-'.strlen($right) );
        }

        // Re-apply negative
        if ($negative)
            $base = '-'.$base;

        // If value was zero, ensure everything is zero
        if ( intval($base) == 0 )
            $base = $exponent = 0;

        // Save the results
        $this->base = intval($base);
        $this->exponent = intval($exponent);

        return $this;
    }

    /**
     * Compute value
     *
     * @return string
     */
    public function getValue()
    {
        $value = 0;

        $base = $this->base;
        $exponent = $this->exponent;

        $value = self::DecimalToString( $base, $exponent );
        return $value;
    }

    /**
     * Convert decimal format to string, made available to controllers.
     *
     * @param integer $base
     * @param integer $exponent
     */
    public static function DecimalToString($base, $exponent)
    {
        if ($exponent == 0) {
            // No changes to base
            $value = strval($base);
        }
        else if ( $exponent < 0 ) {
            // Need to shift the decimal point to the left...
            $negative = false;
            if (intval($base) < 0) {
                $negative = true;
                $base = $base * -1;
            }

            $exponent = $exponent * -1;
            $new_base = strval($base);
            if ($exponent >= strlen($base)) {
                // Need to prepend a number of zeros before the base
                for ($i = strlen($new_base); $i < $exponent; $i++)
                    $new_base = '0'.$new_base;
                $value = '0.'.$new_base;
            }
            else {
                // Insert a decimal point at the correct place
                $value = substr($base, 0, strlen($base)-$exponent).'.'.substr($base, strlen($base)-$exponent);
            }

            // Append negative if it was dropped
            if ($negative)
               $value = '-'.$value;
        }
        else {
            // Need to shift the decimal point to the right...append trailing zeros to base
            $value = $base;
            for ($i = 0; $i < $exponent; $i++)
                $value.= '0';
        }

        return $value;
    }

    /**
     * Set dataField
     *
     * @param ODR\AdminBundle\Entity\DataFields $dataField
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
    /**
     * @var \ODR\AdminBundle\Entity\DataRecordFields
     */
    private $dataRecordFields;


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
     * @var integer
     */
    private $exponent;

    /**
     * Set exponent
     *
     * @param integer $exponent
     * @return DecimalValue
     */
    public function setExponent($exponent)
    {
        $this->exponent = $exponent;
    
        return $this;
    }

    /**
     * Get exponent
     *
     * @return integer 
     */
    public function getExponent()
    {
        return $this->exponent;
    }
    /**
     * @var integer
     */
    private $base;


    /**
     * Set base
     *
     * @param integer $base
     * @return DecimalValue
     */
    public function setBase($base)
    {
        $this->base = $base;
    
        return $this;
    }

    /**
     * Get base
     *
     * @return integer 
     */
    public function getBase()
    {
        return $this->base;
    }

}
