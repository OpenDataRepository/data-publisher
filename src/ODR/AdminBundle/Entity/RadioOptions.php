<?php

/**
 * Open Data Repository Data Publisher
 * RadioOptions Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RadioOptions Entity is automatically generated from
 * ./Resources/config/doctrine/RadioOptions.orm.yml
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * RadioOptions
 */
class RadioOptions
{
    /**
     * @var integer
     */
    private $id;

    /**
     * NOTE - this needs to remain in synch with the option name in the associated metadata entity...if it doesn't, CSV/XML importing can't check concurrently that a RadioOption exists
     * @var string
     */
    private $optionName;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var string
     */
    private $xml_optionName;

    /**
     * @var integer
     */
    private $displayOrder;

    /**
     * @var boolean
     */
    private $isDefault;

    /**
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $radioOptionsMeta;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $deletedBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->radioOptionsMeta = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set optionName
     * NOTE - this needs to remain in synch with the option name in the associated metadata entity...if it doesn't, CSV/XML importing can't check concurrently that a RadioOption exists
     *
     * @param string $optionName
     * @return RadioOptions
     */
    public function setOptionName($optionName)
    {
        $this->optionName = $optionName;

        return $this;
    }

    /**
     * Get optionName
     *
     * @return string 
     */
    public function getOptionName()
    {
        return $this->getRadioOptionsMeta()->getOptionName();
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return RadioOptions
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
     * @return RadioOptions
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
     * Set xml_optionName
     * @deprecated
     *
     * @param string $xmlOptionName
     * @return RadioOptions
     */
    public function setXmlOptionName($xmlOptionName)
    {
        $this->xml_optionName = $xmlOptionName;

        return $this;
    }

    /**
     * Get xml_optionName
     *
     * @return string 
     */
    public function getXmlOptionName()
    {
        return $this->getRadioOptionsMeta()->getXmlOptionName();
    }

    /**
     * Set displayOrder
     * @deprecated
     *
     * @param integer $displayOrder
     * @return RadioOptions
     */
    public function setDisplayOrder($displayOrder)
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    /**
     * Get displayOrder
     *
     * @return integer 
     */
    public function getDisplayOrder()
    {
        return $this->getRadioOptionsMeta()->getDisplayOrder();
    }

    /**
     * Set isDefault
     * @deprecated
     *
     * @param boolean $isDefault
     * @return RadioOptions
     */
    public function setIsDefault($isDefault)
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    /**
     * Get isDefault
     *
     * @return boolean 
     */
    public function getIsDefault()
    {
        return $this->getRadioOptionsMeta()->getIsDefault();
    }

    /**
     * Set updated
     * @deprecated
     *
     * @param \DateTime $updated
     * @return RadioOptions
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * Get updated
     * @deprecated
     *
     * @return \DateTime 
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Add radioOptionsMeta
     *
     * @param \ODR\AdminBundle\Entity\RadioOptionsMeta $radioOptionsMeta
     * @return RadioOptions
     */
    public function addRadioOptionsMetum(\ODR\AdminBundle\Entity\RadioOptionsMeta $radioOptionsMeta)
    {
        $this->radioOptionsMeta[] = $radioOptionsMeta;

        return $this;
    }

    /**
     * Remove radioOptionsMeta
     *
     * @param \ODR\AdminBundle\Entity\RadioOptionsMeta $radioOptionsMeta
     */
    public function removeRadioOptionsMetum(\ODR\AdminBundle\Entity\RadioOptionsMeta $radioOptionsMeta)
    {
        $this->radioOptionsMeta->removeElement($radioOptionsMeta);
    }

    /**
     * Get radioOptionsMeta
     *
     * @return \ODR\AdminBundle\Entity\RadioOptionsMeta
     */
    public function getRadioOptionsMeta()
    {
        return $this->radioOptionsMeta->first();
    }

    /**
     * Set dataField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataField
     * @return RadioOptions
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
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return RadioOptions
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
     * Set deletedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $deletedBy
     * @return RadioOptions
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
     * Set updatedBy
     * @deprecated
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $updatedBy
     * @return RadioOptions
     */
    public function setUpdatedBy(\ODR\OpenRepository\UserBundle\Entity\User $updatedBy = null)
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    /**
     * Get updatedBy
     * @deprecated 
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User 
     */
    public function getUpdatedBy()
    {
        return $this->updatedBy;
    }

    // ----------------------------------------
    // TODO - delete these four functions
    /**
     * Get original optionName
     *
     * @return string
     */
    public function getOptionNameOriginal()
    {
        return $this->optionName;
    }

    /**
     * Get original xml_optionName
     *
     * @return string
     */
    public function getXmlOptionNameOriginal()
    {
        return $this->xml_optionName;
    }

    /**
     * Get original displayOrder
     *
     * @return integer
     */
    public function getDisplayOrderOriginal()
    {
        return $this->displayOrder;
    }

    /**
     * Get original isDefault
     *
     * @return boolean
     */
    public function getIsDefaultOriginal()
    {
        return $this->isDefault;
    }
}
