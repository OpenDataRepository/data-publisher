<?php

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ODR\AdminBundle\Entity\ImageSizes
 */
class ImageSizes
{
    /**
     * @var integer $id
     */
    private $id;

    /**
     * @var integer $width
     */
    private $width;

    /**
     * @var integer $height
     */
    private $height;

    /**
     * @var integer $minWidth
     */
    private $minWidth;

    /**
     * @var integer $minHeight
     */
    private $minHeight;

    /**
     * @var integer $maxWidth
     */
    private $maxWidth;

    /**
     * @var integer $maxHeight
     */
    private $maxHeight;

    /**
     * @var ODR\AdminBundle\Entity\DataFields
     */
    private $fieldType;


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
     * Set width
     *
     * @param integer $width
     * @return ImageSizes
     */
    public function setWidth($width)
    {
        $this->width = $width;
    
        return $this;
    }

    /**
     * Get width
     *
     * @return integer 
     */
    public function getWidth()
    {
        return $this->width;
    }

    /**
     * Set height
     *
     * @param integer $height
     * @return ImageSizes
     */
    public function setHeight($height)
    {
        $this->height = $height;
    
        return $this;
    }

    /**
     * Get height
     *
     * @return integer 
     */
    public function getHeight()
    {
        return $this->height;
    }

    /**
     * Set minWidth
     *
     * @param integer $minWidth
     * @return ImageSizes
     */
    public function setMinWidth($minWidth)
    {
        $this->minWidth = $minWidth;
    
        return $this;
    }

    /**
     * Get minWidth
     *
     * @return integer 
     */
    public function getMinWidth()
    {
        return $this->minWidth;
    }

    /**
     * Set minHeight
     *
     * @param integer $minHeight
     * @return ImageSizes
     */
    public function setMinHeight($minHeight)
    {
        $this->minHeight = $minHeight;
    
        return $this;
    }

    /**
     * Get minHeight
     *
     * @return integer 
     */
    public function getMinHeight()
    {
        return $this->minHeight;
    }

    /**
     * Set maxWidth
     *
     * @param integer $maxWidth
     * @return ImageSizes
     */
    public function setMaxWidth($maxWidth)
    {
        $this->maxWidth = $maxWidth;
    
        return $this;
    }

    /**
     * Get maxWidth
     *
     * @return integer 
     */
    public function getMaxWidth()
    {
        return $this->maxWidth;
    }

    /**
     * Set maxHeight
     *
     * @param integer $maxHeight
     * @return ImageSizes
     */
    public function setMaxHeight($maxHeight)
    {
        $this->maxHeight = $maxHeight;
    
        return $this;
    }

    /**
     * Get maxHeight
     *
     * @return integer 
     */
    public function getMaxHeight()
    {
        return $this->maxHeight;
    }

    /**
     * Set fieldType
     *
     * @param ODR\AdminBundle\Entity\FieldType $fieldType
     * @return ImageSizes
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
     * @return ImageSizes
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
     * @return ImageSizes
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
     * @return ImageSizes
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
     * @return ImageSizes
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
     * @return ImageSizes
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
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataFields;


    /**
     * Set dataFields
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataFields
     * @return ImageSizes
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
     * @var boolean
     */
    private $original;


    /**
     * Set original
     *
     * @param boolean $original
     * @return ImageSizes
     */
    public function setOriginal($original)
    {
        $this->original = $original;
    
        return $this;
    }

    /**
     * Get original
     *
     * @return boolean 
     */
    public function getOriginal()
    {
        return $this->original;
    }
    /**
     * @var string
     */
    private $imagetype;


    /**
     * Set imagetype
     *
     * @param string $imagetype
     * @return ImageSizes
     */
    public function setImagetype($imagetype)
    {
        $this->imagetype = $imagetype;
    
        return $this;
    }

    /**
     * Get imagetype
     *
     * @return string 
     */
    public function getImagetype()
    {
        return $this->imagetype;
    }
    /**
     * @var string
     */
    private $size_constraint;


    /**
     * Set size_constraint
     *
     * @param string $sizeConstraint
     * @return ImageSizes
     */
    public function setSizeConstraint($sizeConstraint)
    {
        $this->size_constraint = $sizeConstraint;
    
        return $this;
    }

    /**
     * Get size_constraint
     *
     * @return string 
     */
    public function getSizeConstraint()
    {
        return $this->size_constraint;
    }
}
