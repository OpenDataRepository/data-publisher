<?php

/**
* Open Data Repository Data Publisher
* DataRecord Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The DataRecord Entity is automatically generated from
* ./Resources/config/doctrine/DataFields.orm.yml
*
* There are also several utility functions here to reduce
* code duplication elsewhere.
*/

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * DataRecord
 */
class DataRecord
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $external_id;

    /**
     * @var string
     */
    private $namefield_value;

    /**
     * @var string
     */
    private $sortfield_value;

    /**
     * @var \DateTime
     */
    private $publicDate;

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
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataRecordFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $grandchildren;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $children;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $parent;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $grandparent;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var boolean
     */
    private $provisioned;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->dataRecordFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->grandchildren = new \Doctrine\Common\Collections\ArrayCollection();
        $this->children = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set external_id
     *
     * @param string $externalId
     * @return DataRecord
     */
    public function setExternalId($externalId)
    {
        $this->external_id = $externalId;

        return $this;
    }

    /**
     * Get external_id
     *
     * @return string 
     */
    public function getExternalId()
    {
//        return $this->external_id;

        $external_id_datafield = $this->getDataType()->getExternalIdField();
        if ($external_id_datafield !== null) {
            $typename = $external_id_datafield->getFieldType()->getTypeName();

            foreach ($this->getDataRecordFields() as $drf) {
                if ($drf->getDataField()->getId() == $external_id_datafield->getId()) {
                    $value = $drf->getAssociatedEntity()->getValue();

                    if ($typename == 'DateTime')
                        return $value->format('Y-m-d H:i:s');
                    else
                        return $value;
                }
            }
        }
        else {
//            return $this->getId();
            return '';
        }
    }

    /**
     * Set namefield_value
     *
     * @param string $namefieldValue
     * @return DataRecord
     */
    public function setNamefieldValue($namefieldValue)
    {
        $this->namefield_value = $namefieldValue;

        return $this;
    }

    /**
     * Get namefield_value
     *
     * @return string 
     */
    public function getNamefieldValue()
    {
//        return $this->namefield_value;

        $name_datafield = $this->getDataType()->getNameField();
        if ($name_datafield !== null) {
            $typename = $name_datafield->getFieldType()->getTypeName();

            foreach ($this->getDataRecordFields() as $drf) {
                if ($drf->getDataField()->getId() == $name_datafield->getId()) {
                    $value = $drf->getAssociatedEntity()->getValue();

                    if ($typename == 'DateTime')
                        return $value->format('Y-m-d H:i:s');
                    else
                        return $value;
                }
            }
        }
        else {
            return $this->getId();
        }
    }

    /**
     * Set sortfield_value
     *
     * @param string $sortfieldValue
     * @return DataRecord
     */
    public function setSortfieldValue($sortfieldValue)
    {
        $this->sortfield_value = $sortfieldValue;

        return $this;
    }

    /**
     * Get sortfield_value
     *
     * @return string 
     */
    public function getSortfieldValue()
    {
//        return $this->sortfield_value;

        $sort_datafield = $this->getDataType()->getSortField();
        if ($sort_datafield !== null) {
            $typename = $sort_datafield->getFieldType()->getTypeName();

            foreach ($this->getDataRecordFields() as $drf) {
                if ($drf->getDataField()->getId() == $sort_datafield->getId()) {
                    $value = $drf->getAssociatedEntity()->getValue();

                    if ($typename == 'DateTime')
                        return $value->format('Y-m-d H:i:s');
                    else
                        return $value;
                }
            }
        }
        else {
            return $this->getId();
        }
    }

    /**
     * Set publicDate
     *
     * @param \DateTime $publicDate
     * @return DataRecord
     */
    public function setPublicDate($publicDate)
    {
        $this->publicDate = $publicDate;

        return $this;
    }

    /**
     * Get publicDate
     *
     * @return \DateTime 
     */
    public function getPublicDate()
    {
        return $this->publicDate;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return DataRecord
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
     * @return DataRecord
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
     * @return DataRecord
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
     * Add dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     * @return DataRecord
     */
    public function addDataRecordField(\ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields)
    {
        $this->dataRecordFields[] = $dataRecordFields;

        return $this;
    }

    /**
     * Remove dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     */
    public function removeDataRecordField(\ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields)
    {
        $this->dataRecordFields->removeElement($dataRecordFields);
    }

    /**
     * Get dataRecordFields
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getDataRecordFields()
    {
        return $this->dataRecordFields;
    }

    /**
     * Add grandchildren
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $grandchildren
     * @return DataRecord
     */
    public function addGrandchild(\ODR\AdminBundle\Entity\DataRecord $grandchildren)
    {
        $this->grandchildren[] = $grandchildren;

        return $this;
    }

    /**
     * Remove grandchildren
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $grandchildren
     */
    public function removeGrandchild(\ODR\AdminBundle\Entity\DataRecord $grandchildren)
    {
        $this->grandchildren->removeElement($grandchildren);
    }

    /**
     * Get grandchildren
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getGrandchildren()
    {
        return $this->grandchildren;
    }

    /**
     * Add children
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $children
     * @return DataRecord
     */
    public function addChild(\ODR\AdminBundle\Entity\DataRecord $children)
    {
        $this->children[] = $children;

        return $this;
    }

    /**
     * Remove children
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $children
     */
    public function removeChild(\ODR\AdminBundle\Entity\DataRecord $children)
    {
        $this->children->removeElement($children);
    }

    /**
     * Get children
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * Set parent
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $parent
     * @return DataRecord
     */
    public function setParent(\ODR\AdminBundle\Entity\DataRecord $parent = null)
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * Get parent
     *
     * @return \ODR\AdminBundle\Entity\DataRecord 
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Set grandparent
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $grandparent
     * @return DataRecord
     */
    public function setGrandparent(\ODR\AdminBundle\Entity\DataRecord $grandparent = null)
    {
        $this->grandparent = $grandparent;

        return $this;
    }

    /**
     * Get grandparent
     *
     * @return \ODR\AdminBundle\Entity\DataRecord 
     */
    public function getGrandparent()
    {
        return $this->grandparent;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return DataRecord
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
     * @return DataRecord
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
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return DataRecord
     */
    public function setDataType(\ODR\AdminBundle\Entity\DataType $dataType = null)
    {
        $this->dataType = $dataType;

        return $this;
    }

    /**
     * Get dataType
     *
     * @return \ODR\AdminBundle\Entity\DataType 
     */
    public function getDataType()
    {
        return $this->dataType;
    }

    /**
     * Is public
     *
     * @return boolean
     */
    public function isPublic()
    {
        if ($this->publicDate->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
            return false;
        else
            return true;
    }

    /**
     * Set provisioned
     *
     * @param boolean $provisioned
     * @return DataRecord
     */
    public function setProvisioned($provisioned)
    {
        $this->provisioned = $provisioned;

        return $this;
    }

    /**
     * Get provisioned
     *
     * @return boolean 
     */
    public function getProvisioned()
    {
        return $this->provisioned;
    }
}
