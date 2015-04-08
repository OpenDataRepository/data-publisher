<?php

/**
* Open Data Repository Data Publisher
* DataRecordFields Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The DataRecordFields Entity is automatically generated from 
* ./Resources/config/doctrine/DataRecordFields.orm.yml
*
* There is also a function to return the storage entity this
* DataRecordField entity points to.
*/


namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ODR\AdminBundle\Entity\DataRecordFields
 */
class DataRecordFields
{
    /**
     * @var integer $id
     */
    private $id;

    /**
     * @var ODR\AdminBundle\Entity\DataRecord
     */
    private $dataRecord;

    /**
     * @var ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;


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
     * Set dataRecord
     *
     * @param ODR\AdminBundle\Entity\DataRecord $dataRecord
     * @return DataRecordFields
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
     * Set dataField
     *
     * @param ODR\AdminBundle\Entity\DataFields $dataField
     * @return DataRecordFields
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
     * @return DataRecordFields
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
     * @return DataRecordFields
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
     * @return DataRecordFields
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
     * @return DataRecordFields
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
     * @return DataRecordFields
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
     * Constructor
     */
    public function __construct()
    {
    }
    
    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $boolean;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $file;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $image;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $integerValue;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $longText;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $longVarchar;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $mediumVarchar;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $shortVarchar;

    /**
     * Add boolean
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $boolean
     * @return DataRecordFields
     */
    public function addBoolean(\ODR\AdminBundle\Entity\Boolean $boolean)
    {
        $this->boolean[] = $boolean;
    
        return $this;
    }

    /**
     * Remove boolean
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $boolean
     */
    public function removeBoolean(\ODR\AdminBundle\Entity\Boolean $boolean)
    {
        $this->boolean->removeElement($boolean);
    }

    /**
     * Get boolean
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getBoolean()
    {
        return $this->boolean;
    }

    /**
     * Add file
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $file
     * @return DataRecordFields
     */
    public function addFile(\ODR\AdminBundle\Entity\File $file)
    {
        $this->file[] = $file;
    
        return $this;
    }

    /**
     * Remove file
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $file
     */
    public function removeFile(\ODR\AdminBundle\Entity\File $file)
    {
        $this->file->removeElement($file);
    }

    /**
     * Get file
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * Add image
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $image
     * @return DataRecordFields
     */
    public function addImage(\ODR\AdminBundle\Entity\Image $image)
    {
        $this->image[] = $image;
    
        return $this;
    }

    /**
     * Remove image
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $image
     */
    public function removeImage(\ODR\AdminBundle\Entity\Image $image)
    {
        $this->image->removeElement($image);
    }

    /**
     * Get image
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * Add integerValue
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $integerValue
     * @return DataRecordFields
     */
    public function addIntegerValue(\ODR\AdminBundle\Entity\IntegerValue $integerValue)
    {
        $this->integerValue[] = $integerValue;
    
        return $this;
    }

    /**
     * Remove integerValue
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $integerValue
     */
    public function removeIntegerValue(\ODR\AdminBundle\Entity\IntegerValue $integerValue)
    {
        $this->integerValue->removeElement($integerValue);
    }

    /**
     * Get integerValue
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getIntegerValue()
    {
        return $this->integerValue;
    }

    /**
     * Add longText
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $longText
     * @return DataRecordFields
     */
    public function addLongText(\ODR\AdminBundle\Entity\LongText $longText)
    {
        $this->longText[] = $longText;
    
        return $this;
    }

    /**
     * Remove longText
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $longText
     */
    public function removeLongText(\ODR\AdminBundle\Entity\LongText $longText)
    {
        $this->longText->removeElement($longText);
    }

    /**
     * Get longText
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getLongText()
    {
        return $this->longText;
    }

    /**
     * Add longVarchar
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $longVarchar
     * @return DataRecordFields
     */
    public function addLongVarchar(\ODR\AdminBundle\Entity\LongVarchar $longVarchar)
    {
        $this->longVarchar[] = $longVarchar;
    
        return $this;
    }

    /**
     * Remove longVarchar
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $longVarchar
     */
    public function removeLongVarchar(\ODR\AdminBundle\Entity\LongVarchar $longVarchar)
    {
        $this->longVarchar->removeElement($longVarchar);
    }

    /**
     * Get longVarchar
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getLongVarchar()
    {
        return $this->longVarchar;
    }

    /**
     * Add mediumVarchar
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $mediumVarchar
     * @return DataRecordFields
     */
    public function addMediumVarchar(\ODR\AdminBundle\Entity\MediumVarchar $mediumVarchar)
    {
        $this->mediumVarchar[] = $mediumVarchar;
    
        return $this;
    }

    /**
     * Remove mediumVarchar
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $mediumVarchar
     */
    public function removeMediumVarchar(\ODR\AdminBundle\Entity\MediumVarchar $mediumVarchar)
    {
        $this->mediumVarchar->removeElement($mediumVarchar);
    }

    /**
     * Get mediumVarchar
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getMediumVarchar()
    {
        return $this->mediumVarchar;
    }

    /**
     * Add shortVarchar
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $shortVarchar
     * @return DataRecordFields
     */
    public function addShortVarchar(\ODR\AdminBundle\Entity\ShortVarchar $shortVarchar)
    {
        $this->shortVarchar[] = $shortVarchar;
    
        return $this;
    }

    /**
     * Remove shortVarchar
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $shortVarchar
     */
    public function removeShortVarchar(\ODR\AdminBundle\Entity\ShortVarchar $shortVarchar)
    {
        $this->shortVarchar->removeElement($shortVarchar);
    }

    /**
     * Get shortVarchar
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getShortVarchar()
    {
        return $this->shortVarchar;
    }

    /**
     * Set boolean
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $boolean
     * @return DataRecordFields
     */
    public function setBoolean(\ODR\AdminBundle\Entity\Boolean $boolean = null)
    {
        $this->boolean = $boolean;
    
        return $this;
    }

    /**
     * Set file
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $file
     * @return DataRecordFields
     */
    public function setFile(\ODR\AdminBundle\Entity\File $file = null)
    {
        $this->file = $file;
    
        return $this;
    }

    /**
     * Set image
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $image
     * @return DataRecordFields
     */
    public function setImage(\ODR\AdminBundle\Entity\Image $image = null)
    {
        $this->image = $image;
    
        return $this;
    }

    /**
     * Set integerValue
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $integerValue
     * @return DataRecordFields
     */
    public function setIntegerValue(\ODR\AdminBundle\Entity\IntegerValue $integerValue = null)
    {
        $this->integerValue = $integerValue;
    
        return $this;
    }

    /**
     * Set longText
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $longText
     * @return DataRecordFields
     */
    public function setLongText(\ODR\AdminBundle\Entity\LongText $longText = null)
    {
        $this->longText = $longText;
    
        return $this;
    }

    /**
     * Set longVarchar
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $longVarchar
     * @return DataRecordFields
     */
    public function setLongVarchar(\ODR\AdminBundle\Entity\LongVarchar $longVarchar = null)
    {
        $this->longVarchar = $longVarchar;
    
        return $this;
    }

    /**
     * Set mediumVarchar
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $mediumVarchar
     * @return DataRecordFields
     */
    public function setMediumVarchar(\ODR\AdminBundle\Entity\MediumVarchar $mediumVarchar = null)
    {
        $this->mediumVarchar = $mediumVarchar;
    
        return $this;
    }

    /**
     * Set shortVarchar
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $shortVarchar
     * @return DataRecordFields
     */
    public function setShortVarchar(\ODR\AdminBundle\Entity\ShortVarchar $shortVarchar = null)
    {
        $this->shortVarchar = $shortVarchar;
    
        return $this;
    }

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $radioSelection;


    /**
     * Add radioSelection
     *
     * @param \ODR\AdminBundle\Entity\RadioSelection $radioSelection
     * @return DataRecordFields
     */
    public function addRadioSelection(\ODR\AdminBundle\Entity\RadioSelection $radioSelection)
    {
        $this->radioSelection[] = $radioSelection;
    
        return $this;
    }

    /**
     * Remove radioSelection
     *
     * @param \ODR\AdminBundle\Entity\RadioSelection $radioSelection
     */
    public function removeRadioSelection(\ODR\AdminBundle\Entity\RadioSelection $radioSelection)
    {
        $this->radioSelection->removeElement($radioSelection);
    }

    /**
     * Get radioSelection
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getRadioSelection()
    {
        return $this->radioSelection;
    }

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $decimalValue;

    /**
     * Set decimalValue
     *
     * @param \ODR\AdminBundle\Entity\DecimalValue $decimalValue
     * @return DataRecordFields
     */
    public function setDecimalValue(\ODR\AdminBundle\Entity\DecimalValue $decimalValue = null)
    {
        $this->decimalValue = $decimalValue;
    
        return $this;
    }

    /**
     * Get decimalValue
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDecimalValue()
    {
        return $this->decimalValue;
    }


    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $datetimeValue;

    /**
     * Set datetimeValue
     *
     * @param \ODR\AdminBundle\Entity\DatetimeValue $datetimeValue
     * @return DataRecordFields
     */
    public function setDatetimeValue(\ODR\AdminBundle\Entity\DatetimeValue $datetimeValue = null)
    {
        $this->datetimeValue = $datetimeValue;

        return $this;
    }

    /**
     * Get datetimeValue
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDatetimeValue()
    {
        return $this->datetimeValue;
    }


    /**
     * Get associatedEntity
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getAssociatedEntity()
    {
        $type_class = $this->getDataField()->getFieldType()->getTypeClass();

        $my_obj = null;
        switch ($type_class) {
            case 'Boolean':
                $my_obj = $this->getBoolean();
            break;
            case 'File':
                $my_obj = $this->getFile();
            break;
            case 'Image':
                $my_obj = $this->getImage();
            break;
            case 'DecimalValue':
                $my_obj = $this->getDecimalValue();
            break;
            case 'IntegerValue':
                $my_obj = $this->getIntegerValue();
            break;
            case 'LongText':
                $my_obj = $this->getLongText();
            break;
            case 'LongVarchar':
                $my_obj = $this->getLongVarchar();
            break;
            case 'MediumVarchar':
                $my_obj = $this->getMediumVarchar();
            break;
            case 'Radio':
//                $my_obj = $this->getRadio();
                $my_obj = array();
            break;
            case 'ShortVarchar':
                $my_obj = $this->getShortVarchar();
            break;
            case 'DatetimeValue':
                $my_obj = $this->getDatetimeValue();
            break;

            case 'Markdown':
                $my_obj = null;
            break;
        }

        return $my_obj;
    }

}
