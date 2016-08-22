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

use Doctrine\Common\Collections\ArrayCollection;
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
    private $created;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $boolean;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $integerValue;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $decimalValue;

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
     * @var \Doctrine\Common\Collections\Collection
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
        $this->boolean = new \Doctrine\Common\Collections\ArrayCollection();
        $this->integerValue = new \Doctrine\Common\Collections\ArrayCollection();
        $this->decimalValue = new \Doctrine\Common\Collections\ArrayCollection();
        $this->longText = new \Doctrine\Common\Collections\ArrayCollection();
        $this->longVarchar = new \Doctrine\Common\Collections\ArrayCollection();
        $this->mediumVarchar = new \Doctrine\Common\Collections\ArrayCollection();
        $this->shortVarchar = new \Doctrine\Common\Collections\ArrayCollection();
        $this->datetimeValue = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Add boolean
     *
     * @param \ODR\AdminBundle\Entity\Boolean $boolean
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
     * @param \ODR\AdminBundle\Entity\Boolean $boolean
     */
    public function removeBoolean(\ODR\AdminBundle\Entity\Boolean $boolean)
    {
        $this->boolean->removeElement($boolean);
    }

    /**
     * Get boolean
     *
     * @return \ODR\AdminBundle\Entity\Boolean
     */
    public function getBoolean()
    {
        return $this->boolean->first();
    }

    /**
     * Add integerValue
     *
     * @param \ODR\AdminBundle\Entity\IntegerValue $integerValue
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
     * @param \ODR\AdminBundle\Entity\IntegerValue $integerValue
     */
    public function removeIntegerValue(\ODR\AdminBundle\Entity\IntegerValue $integerValue)
    {
        $this->integerValue->removeElement($integerValue);
    }

    /**
     * Get integerValue
     *
     * @return \ODR\AdminBundle\Entity\IntegerValue
     */
    public function getIntegerValue()
    {
        return $this->integerValue->first();
    }

    /**
     * Add decimalValue
     *
     * @param \ODR\AdminBundle\Entity\DecimalValue $decimalValue
     * @return DataRecordFields
     */
    public function addDecimalValue(\ODR\AdminBundle\Entity\DecimalValue $decimalValue)
    {
        $this->decimalValue[] = $decimalValue;

        return $this;
    }

    /**
     * Remove decimalValue
     *
     * @param \ODR\AdminBundle\Entity\DecimalValue $decimalValue
     */
    public function removeDecimalValue(\ODR\AdminBundle\Entity\DecimalValue $decimalValue)
    {
        $this->decimalValue->removeElement($decimalValue);
    }

    /**
     * Get decimalValue
     *
     * @return \ODR\AdminBundle\Entity\DecimalValue
     */
    public function getDecimalValue()
    {
        return $this->decimalValue->first();
    }

    /**
     * Add longText
     *
     * @param \ODR\AdminBundle\Entity\LongText $longText
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
     * @param \ODR\AdminBundle\Entity\LongText $longText
     */
    public function removeLongText(\ODR\AdminBundle\Entity\LongText $longText)
    {
        $this->longText->removeElement($longText);
    }

    /**
     * Get longText
     *
     * @return \ODR\AdminBundle\Entity\LongText
     */
    public function getLongText()
    {
        return $this->longText->first();
    }

    /**
     * Add longVarchar
     *
     * @param \ODR\AdminBundle\Entity\LongVarchar $longVarchar
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
     * @param \ODR\AdminBundle\Entity\LongVarchar $longVarchar
     */
    public function removeLongVarchar(\ODR\AdminBundle\Entity\LongVarchar $longVarchar)
    {
        $this->longVarchar->removeElement($longVarchar);
    }

    /**
     * Get longVarchar
     *
     * @return \ODR\AdminBundle\Entity\LongVarchar
     */
    public function getLongVarchar()
    {
        return $this->longVarchar->first();
    }

    /**
     * Add mediumVarchar
     *
     * @param \ODR\AdminBundle\Entity\MediumVarchar $mediumVarchar
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
     * @param \ODR\AdminBundle\Entity\MediumVarchar $mediumVarchar
     */
    public function removeMediumVarchar(\ODR\AdminBundle\Entity\MediumVarchar $mediumVarchar)
    {
        $this->mediumVarchar->removeElement($mediumVarchar);
    }

    /**
     * Get mediumVarchar
     *
     * @return \ODR\AdminBundle\Entity\MediumVarchar
     */
    public function getMediumVarchar()
    {
        return $this->mediumVarchar->first();
    }

    /**
     * Add shortVarchar
     *
     * @param \ODR\AdminBundle\Entity\ShortVarchar $shortVarchar
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
     * @param \ODR\AdminBundle\Entity\ShortVarchar $shortVarchar
     */
    public function removeShortVarchar(\ODR\AdminBundle\Entity\ShortVarchar $shortVarchar)
    {
        $this->shortVarchar->removeElement($shortVarchar);
    }

    /**
     * Get shortVarchar
     *
     * @return \ODR\AdminBundle\Entity\ShortVarchar
     */
    public function getShortVarchar()
    {
        return $this->shortVarchar->first();
    }

    /**
     * Add datetimeValue
     *
     * @param \ODR\AdminBundle\Entity\DatetimeValue $datetimeValue
     * @return DataRecordFields
     */
    public function addDatetimeValue(\ODR\AdminBundle\Entity\DatetimeValue $datetimeValue)
    {
        $this->datetimeValue[] = $datetimeValue;

        return $this;
    }

    /**
     * Remove datetimeValue
     *
     * @param \ODR\AdminBundle\Entity\DatetimeValue $datetimeValue
     */
    public function removeDatetimeValue(\ODR\AdminBundle\Entity\DatetimeValue $datetimeValue)
    {
        $this->datetimeValue->removeElement($datetimeValue);
    }

    /**
     * Get datetimeValue
     *
     * @return \ODR\AdminBundle\Entity\DatetimeValue
     */
    public function getDatetimeValue()
    {
        return $this->datetimeValue->first();
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
//        return $this->image;

        // Adapted from http://stackoverflow.com/a/16707694
        $iterator = $this->image->getIterator();
        $iterator->uasort(function ($a, $b) {
            // Sort by display order first if possible
            /** @var Image $a */
            /** @var Image $b */
            $a_display_order = $a->getDisplayorder();
            $b_display_order = $b->getDisplayorder();
            if ($a_display_order < $b_display_order)
                return -1;
            else if ($a_display_order > $b_display_order)
                return 1;
            else
                // otherwise, sort by image_id
                return ($a->getId() < $b->getId()) ? -1 : 1;
        });
        return new ArrayCollection(iterator_to_array($iterator));
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
