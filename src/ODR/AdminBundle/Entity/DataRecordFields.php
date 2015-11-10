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
 * DataRecordFields
 */
class DataRecordFields
{
    /**
     * @var integer
     */
    private $id;

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
     * @var \ODR\AdminBundle\Entity\Boolean
     */
    private $boolean;

    /**
     * @var \ODR\AdminBundle\Entity\IntegerValue
     */
    private $integerValue;

    /**
     * @var \ODR\AdminBundle\Entity\DecimalValue
     */
    private $decimalValue;

    /**
     * @var \ODR\AdminBundle\Entity\LongText
     */
    private $longText;

    /**
     * @var \ODR\AdminBundle\Entity\LongVarchar
     */
    private $longVarchar;

    /**
     * @var \ODR\AdminBundle\Entity\MediumVarchar
     */
    private $mediumVarchar;

    /**
     * @var \ODR\AdminBundle\Entity\ShortVarchar
     */
    private $shortVarchar;

    /**
     * @var \ODR\AdminBundle\Entity\DatetimeValue
     */
    private $datetimeValue;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $image;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $file;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $radioSelection;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $dataRecord;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->image = new \Doctrine\Common\Collections\ArrayCollection();
        $this->file = new \Doctrine\Common\Collections\ArrayCollection();
        $this->radioSelection = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set boolean
     *
     * @param \ODR\AdminBundle\Entity\Boolean $boolean
     * @return DataRecordFields
     */
    public function setBoolean(\ODR\AdminBundle\Entity\Boolean $boolean = null)
    {
        $this->boolean = $boolean;

        return $this;
    }

    /**
     * Get boolean
     *
     * @return \ODR\AdminBundle\Entity\Boolean 
     */
    public function getBoolean()
    {
        return $this->boolean;
    }

    /**
     * Set integerValue
     *
     * @param \ODR\AdminBundle\Entity\IntegerValue $integerValue
     * @return DataRecordFields
     */
    public function setIntegerValue(\ODR\AdminBundle\Entity\IntegerValue $integerValue = null)
    {
        $this->integerValue = $integerValue;

        return $this;
    }

    /**
     * Get integerValue
     *
     * @return \ODR\AdminBundle\Entity\IntegerValue 
     */
    public function getIntegerValue()
    {
        return $this->integerValue;
    }

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
     * @return \ODR\AdminBundle\Entity\DecimalValue 
     */
    public function getDecimalValue()
    {
        return $this->decimalValue;
    }

    /**
     * Set longText
     *
     * @param \ODR\AdminBundle\Entity\LongText $longText
     * @return DataRecordFields
     */
    public function setLongText(\ODR\AdminBundle\Entity\LongText $longText = null)
    {
        $this->longText = $longText;

        return $this;
    }

    /**
     * Get longText
     *
     * @return \ODR\AdminBundle\Entity\LongText 
     */
    public function getLongText()
    {
        return $this->longText;
    }

    /**
     * Set longVarchar
     *
     * @param \ODR\AdminBundle\Entity\LongVarchar $longVarchar
     * @return DataRecordFields
     */
    public function setLongVarchar(\ODR\AdminBundle\Entity\LongVarchar $longVarchar = null)
    {
        $this->longVarchar = $longVarchar;

        return $this;
    }

    /**
     * Get longVarchar
     *
     * @return \ODR\AdminBundle\Entity\LongVarchar 
     */
    public function getLongVarchar()
    {
        return $this->longVarchar;
    }

    /**
     * Set mediumVarchar
     *
     * @param \ODR\AdminBundle\Entity\MediumVarchar $mediumVarchar
     * @return DataRecordFields
     */
    public function setMediumVarchar(\ODR\AdminBundle\Entity\MediumVarchar $mediumVarchar = null)
    {
        $this->mediumVarchar = $mediumVarchar;

        return $this;
    }

    /**
     * Get mediumVarchar
     *
     * @return \ODR\AdminBundle\Entity\MediumVarchar 
     */
    public function getMediumVarchar()
    {
        return $this->mediumVarchar;
    }

    /**
     * Set shortVarchar
     *
     * @param \ODR\AdminBundle\Entity\ShortVarchar $shortVarchar
     * @return DataRecordFields
     */
    public function setShortVarchar(\ODR\AdminBundle\Entity\ShortVarchar $shortVarchar = null)
    {
        $this->shortVarchar = $shortVarchar;

        return $this;
    }

    /**
     * Get shortVarchar
     *
     * @return \ODR\AdminBundle\Entity\ShortVarchar 
     */
    public function getShortVarchar()
    {
        return $this->shortVarchar;
    }

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
     * @return \ODR\AdminBundle\Entity\DatetimeValue 
     */
    public function getDatetimeValue()
    {
        return $this->datetimeValue;
    }

    /**
     * Add image
     *
     * @param \ODR\AdminBundle\Entity\Image $image
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
     * @param \ODR\AdminBundle\Entity\Image $image
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
     * Add file
     *
     * @param \ODR\AdminBundle\Entity\File $file
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
     * @param \ODR\AdminBundle\Entity\File $file
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
     * Set dataRecord
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $dataRecord
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
     * @return \ODR\AdminBundle\Entity\DataRecord 
     */
    public function getDataRecord()
    {
        return $this->dataRecord;
    }

    /**
     * Set dataField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataField
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
     * @return \ODR\AdminBundle\Entity\DataFields 
     */
    public function getDataField()
    {
        return $this->dataField;
    }

    /**
     * Get associatedEntity
     *
     * @return mixed
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
