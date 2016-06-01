<?php

/**
 * Open Data Repository Data Publisher
 * RadioOptionsMeta Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RadioOptionsMeta Entity is responsible for storing the properties
 * of the RadioOptions Entity that are subject to change, and is
 * automatically generated from ./Resources/config/doctrine/RadioOptionsMeta.orm.yml
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * RadioOptionsMeta
 */
class RadioOptionsMeta
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $optionName;

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
    private $deletedAt;

    /**
     * @var \ODR\AdminBundle\Entity\RadioOptions
     */
    private $radioOption;
    
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
     * Set optionName
     *
     * @param string $optionName
     * @return RadioOptionsMeta
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
        return $this->optionName;
    }

    /**
     * Set xml_optionName
     *
     * @param string $xmlOptionName
     * @return RadioOptionsMeta
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
        return $this->xml_optionName;
    }

    /**
     * Set displayOrder
     *
     * @param integer $displayOrder
     * @return RadioOptionsMeta
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
        return $this->displayOrder;
    }

    /**
     * Set isDefault
     *
     * @param boolean $isDefault
     * @return RadioOptionsMeta
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
        return $this->isDefault;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return RadioOptionsMeta
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
     * Set radioOption
     *
     * @param \ODR\AdminBundle\Entity\RadioOptions $radioOption
     * @return RadioOptionsMeta
     */
    public function setRadioOption(\ODR\AdminBundle\Entity\RadioOptions $radioOption = null)
    {
        $this->radioOption = $radioOption;

        return $this;
    }

    /**
     * Get radioOption
     *
     * @return \ODR\AdminBundle\Entity\RadioOptions
     */
    public function getRadioOption()
    {
        return $this->radioOption;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return RadioOptionsMeta
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
     * @return RadioOptionsMeta
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
     * @return RadioOptionsMeta
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
     * @return RadioOptionsMeta
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
