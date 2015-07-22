<?php

/**
* Open Data Repository Data Publisher
* FieldType Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The FieldType Entity is automatically generated from
* ./Resources/config/doctrine/FieldType.orm.yml
*
*/


namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * FieldType
 */
class FieldType
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $typeName;

    /**
     * @var string
     */
    private $position;

    /**
     * @var string
     */
    private $description;

    /**
     * @var boolean
     */
    private $isImage;

    /**
     * @var boolean
     */
    private $isFile;

    /**
     * @var boolean
     */
    private $allowMultiple;

    /**
     * @var boolean
     */
    private $hasBlob;

    /**
     * @var boolean
     */
    private $insertOnCreate;

    /**
     * @var boolean
     */
    private $canBeUnique;

    /**
     * @var boolean
     */
    private $canBeSortField;

    /**
     * @var string
     */
    private $typeClass;

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
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set typeName
     *
     * @param string $typeName
     * @return FieldType
     */
    public function setTypeName($typeName)
    {
        $this->typeName = $typeName;

        return $this;
    }

    /**
     * Get typeName
     *
     * @return string 
     */
    public function getTypeName()
    {
        return $this->typeName;
    }

    /**
     * Set position
     *
     * @param string $position
     * @return FieldType
     */
    public function setPosition($position)
    {
        $this->position = $position;

        return $this;
    }

    /**
     * Get position
     *
     * @return string 
     */
    public function getPosition()
    {
        return $this->position;
    }

    /**
     * Set description
     *
     * @param string $description
     * @return FieldType
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get description
     *
     * @return string 
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set isImage
     *
     * @param boolean $isImage
     * @return FieldType
     */
    public function setIsImage($isImage)
    {
        $this->isImage = $isImage;

        return $this;
    }

    /**
     * Get isImage
     *
     * @return boolean 
     */
    public function getIsImage()
    {
        return $this->isImage;
    }

    /**
     * Set isFile
     *
     * @param boolean $isFile
     * @return FieldType
     */
    public function setIsFile($isFile)
    {
        $this->isFile = $isFile;

        return $this;
    }

    /**
     * Get isFile
     *
     * @return boolean 
     */
    public function getIsFile()
    {
        return $this->isFile;
    }

    /**
     * Set allowMultiple
     *
     * @param boolean $allowMultiple
     * @return FieldType
     */
    public function setAllowMultiple($allowMultiple)
    {
        $this->allowMultiple = $allowMultiple;

        return $this;
    }

    /**
     * Get allowMultiple
     *
     * @return boolean 
     */
    public function getAllowMultiple()
    {
        return $this->allowMultiple;
    }

    /**
     * Set hasBlob
     *
     * @param boolean $hasBlob
     * @return FieldType
     */
    public function setHasBlob($hasBlob)
    {
        $this->hasBlob = $hasBlob;

        return $this;
    }

    /**
     * Get hasBlob
     *
     * @return boolean 
     */
    public function getHasBlob()
    {
        return $this->hasBlob;
    }

    /**
     * Set insertOnCreate
     *
     * @param boolean $insertOnCreate
     * @return FieldType
     */
    public function setInsertOnCreate($insertOnCreate)
    {
        $this->insertOnCreate = $insertOnCreate;

        return $this;
    }

    /**
     * Get insertOnCreate
     *
     * @return boolean 
     */
    public function getInsertOnCreate()
    {
        return $this->insertOnCreate;
    }

    /**
     * Set canBeUnique
     *
     * @param boolean $canBeUnique
     * @return FieldType
     */
    public function setCanBeUnique($canBeUnique)
    {
        $this->canBeUnique = $canBeUnique;

        return $this;
    }

    /**
     * Get canBeUnique
     *
     * @return boolean 
     */
    public function getCanBeUnique()
    {
        return $this->canBeUnique;
    }

    /**
     * Set canBeSortField
     *
     * @param boolean $canBeSortField
     * @return FieldType
     */
    public function setCanBeSortField($canBeSortField)
    {
        $this->canBeSortField = $canBeSortField;

        return $this;
    }

    /**
     * Get canBeSortField
     *
     * @return boolean 
     */
    public function getCanBeSortField()
    {
        return $this->canBeSortField;
    }

    /**
     * Set typeClass
     *
     * @param string $typeClass
     * @return FieldType
     */
    public function setTypeClass($typeClass)
    {
        $this->typeClass = $typeClass;

        return $this;
    }

    /**
     * Get typeClass
     *
     * @return string 
     */
    public function getTypeClass()
    {
        return $this->typeClass;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return FieldType
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
     * @return FieldType
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
     * @return FieldType
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
     * @return FieldType
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
     * @return FieldType
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
