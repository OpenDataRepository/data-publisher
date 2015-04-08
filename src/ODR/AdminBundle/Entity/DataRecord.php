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
 * ODR\AdminBundle\Entity\DataRecord
 */
class DataRecord
{
    /**
     * @var integer $id
     */
    private $id;

    /**
     * @var ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

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
     * Set dataType
     *
     * @param ODR\AdminBundle\Entity\DataType $dataType
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
     * @return ODR\AdminBundle\Entity\DataType 
     */
    public function getDataType()
    {
        return $this->dataType;
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
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataRecordFields;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->dataRecordFields = new \Doctrine\Common\Collections\ArrayCollection();
    }
    
    /**
     * Add dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     * @return DataRecord
     */
    public function addDataRecordFields(\ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields)
    {
        $this->dataRecordFields[] = $dataRecordFields;
    
        return $this;
    }

    /**
     * Remove dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     */
    public function removeDataRecordFields(\ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields)
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
     * TODO: short description.
     * 
     * @return TODO
     */
    private function getDataRecordFieldsAsArray() {
        $datarecordfields = array();
        foreach($this->getDataRecordFields as $datarecordfield) {
            $datarecordfields[$datarecordfield->getId()] = $datarecordfield->toArray();
        }

        return $datarecordfields;
    }

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function toArray() 
    {
        $datarecord  = array();
        $datarecord['id'] = $this->getId();
        $datarecord['data_type_id'] = $this->getDataType()->getId();
        $datarecord['parent_id'] = $this->getParentId();

        return $datarecord;
    }


    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $grandparent;

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
     * @var \Doctrine\Common\Collections\Collection
     */
    private $children;


    /**
     * Add children
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $children
     * @return DataRecord
     */
    public function addChildren(\ODR\AdminBundle\Entity\DataRecord $children)
    {
        $this->children[] = $children;
    
        return $this;
    }

    /**
     * Remove children
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $children
     */
    public function removeChildren(\ODR\AdminBundle\Entity\DataRecord $children)
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
     * @var \DateTime
     */
    private $publicDate;


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
     * @var \Doctrine\Common\Collections\Collection
     */
    private $grandchildren;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $parent;


    /**
     * Add grandchildren
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $grandchildren
     * @return DataRecord
     */
    public function addGrandchildren(\ODR\AdminBundle\Entity\DataRecord $grandchildren)
    {
        $this->grandchildren[] = $grandchildren;
    
        return $this;
    }

    /**
     * Remove grandchildren
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $grandchildren
     */
    public function removeGrandchildren(\ODR\AdminBundle\Entity\DataRecord $grandchildren)
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
     * Utility function to return the value of this DataRecord's instance of the DataField used to provide unique "names" for DataRecords of this DataType
     *
     * @return string
     */
    public function getNameFieldValue()
    {
        $name_datafield = $this->getDataType()->getNameField();
        if ($name_datafield !== null) {
            foreach ($this->getDataRecordFields() as $drf) {
                if ($drf->getDataField()->getId() == $name_datafield->getId()) {
                    return $drf->getAssociatedEntity()->getValue();
                }
            }
        }
        else {
            return $this->getId();
        }
    }

    /**
     * Utility function to return the value of this DataRecord's instance of the DataField used to provide sorting values for this DataType
     *
     * @return string
     */
    public function getSortFieldValue()
    {
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
     * @var string
     */
    private $external_id;


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
        return $this->external_id;
    }
}
