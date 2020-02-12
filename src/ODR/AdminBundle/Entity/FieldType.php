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
    private $typeClass;

    /**
     * @var string
     */
    private $typeName;

    /**
     * @var string
     */
    private $description;

    /**
     * @var bool
     */
    private $canBeRequired;

    /**
     * @var bool
     */
    private $canBeUnique;

    /**
     * @var bool
     */
    private $canBeSortField;

    /**
     * @var bool
     */
    private $canBeMetadataNameField;

    /**
     * @var bool
     */
    private $canBeMetadataDescField;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \DateTime
     */
    private $created;

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
     * Set canBeRequired.
     *
     * @param bool $canBeRequired
     *
     * @return FieldType
     */
    public function setCanBeRequired($canBeRequired)
    {
        $this->canBeRequired = $canBeRequired;

        return $this;
    }

    /**
     * Get canBeRequired.
     *
     * @return bool
     */
    public function getCanBeRequired()
    {
        return $this->canBeRequired;
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
     * Set canBeMetadataNameField.
     *
     * @param bool $canBeMetadataNameField
     *
     * @return FieldType
     */
    public function setCanBeMetadataNameField($canBeMetadataNameField)
    {
        $this->canBeMetadataNameField = $canBeMetadataNameField;

        return $this;
    }

    /**
     * Get canBeMetadataNameField.
     *
     * @return bool
     */
    public function getCanBeMetadataNameField()
    {
        return $this->canBeMetadataNameField;
    }

    /**
     * Set canBeMetadataDescField.
     *
     * @param bool $canBeMetadataDescField
     *
     * @return FieldType
     */
    public function setCanBeMetadataDescField($canBeMetadataDescField)
    {
        $this->canBeMetadataDescField = $canBeMetadataDescField;

        return $this;
    }

    /**
     * Get canBeMetadataDescField.
     *
     * @return bool
     */
    public function getCanBeMetadataDescField()
    {
        return $this->canBeMetadataDescField;
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
}
