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
 *
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
     * @var boolean
     */
    private $provisioned;

    /**
     * @var string
     */
    private $unique_id;

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
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataRecordMeta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $linkedDatarecords;

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
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $deletedBy;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->dataRecordFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->grandchildren = new \Doctrine\Common\Collections\ArrayCollection();
        $this->children = new \Doctrine\Common\Collections\ArrayCollection();
        $this->dataRecordMeta = new \Doctrine\Common\Collections\ArrayCollection();
        $this->linkedDatarecords = new \Doctrine\Common\Collections\ArrayCollection();
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

    /**
     * Set uniqueId
     *
     * @param string $uniqueId
     *
     * @return DataRecord
     */
    public function setUniqueId($uniqueId)
    {
        $this->unique_id = $uniqueId;

        return $this;
    }

    /**
     * Get uniqueId
     *
     * @return string
     */
    public function getUniqueId()
    {
        return $this->unique_id;
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
     * Add dataRecordMeta
     *
     * @param \ODR\AdminBundle\Entity\DataRecordMeta $dataRecordMeta
     * @return DataRecord
     */
    public function addDataRecordMetum(\ODR\AdminBundle\Entity\DataRecordMeta $dataRecordMeta)
    {
        $this->dataRecordMeta[] = $dataRecordMeta;

        return $this;
    }

    /**
     * Remove dataRecordMeta
     *
     * @param \ODR\AdminBundle\Entity\DataRecordMeta $dataRecordMeta
     */
    public function removeDataRecordMetum(\ODR\AdminBundle\Entity\DataRecordMeta $dataRecordMeta)
    {
        $this->dataRecordMeta->removeElement($dataRecordMeta);
    }

    /**
     * Get dataRecordMeta
     *
     * @return \ODR\AdminBundle\Entity\DataRecordMeta
     */
    public function getDataRecordMeta()
    {
        return $this->dataRecordMeta->first();
    }

    /**
     * Add linkedDatarecord
     *
     * @param \ODR\AdminBundle\Entity\LinkedDataTree $linkedDatarecord
     *
     * @return DataRecord
     */
    public function addLinkedDatarecord(\ODR\AdminBundle\Entity\LinkedDataTree $linkedDatarecord)
    {
        $this->linkedDatarecords[] = $linkedDatarecord;

        return $this;
    }

    /**
     * Remove linkedDatarecord
     *
     * @param \ODR\AdminBundle\Entity\LinkedDataTree $linkedDatarecord
     */
    public function removeLinkedDatarecord(\ODR\AdminBundle\Entity\LinkedDataTree $linkedDatarecord)
    {
        $this->linkedDatarecords->removeElement($linkedDatarecord);
    }

    /**
     * Get linkedDatarecords
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getLinkedDatarecords()
    {
        return $this->linkedDatarecords;
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
     * Set deletedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $deletedBy
     * @return DataRecord
     */
    public function setDeletedBy(\ODR\OpenRepository\UserBundle\Entity\User $deletedBy = null)
    {
        $this->deletedBy = $deletedBy;

        return $this;
    }

    /**
     * Get deletedBy
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User
     */
    public function getDeletedBy()
    {
        return $this->deletedBy;
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
        // TODO - This function is not correct...... Public should regard today's date.
        if ($this->getPublicDate()->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
            return false;
        else
            return true;
    }

    public function setPublicDate($user, $date, $em) {
        // Always create new meta record when setting public
        $new_meta = clone $this->getDataRecordMeta();
        $new_meta->setCreatedBy($user);
        $new_meta->setUpdatedBy($user);
        $new_meta->setPublicDate($date);
        $new_meta->setUpdated(new \DateTime());
        $new_meta->setCreated(new \DateTime());
        $em->persist($new_meta);
        $em->remove($this->getDataRecordMeta());

        return $new_meta;
    }

    /**
     * Get publicDate
     *
     * @return \DateTime
     */
    public function getPublicDate()
    {
        return $this->getDataRecordMeta()->getPublicDate();
    }
}
