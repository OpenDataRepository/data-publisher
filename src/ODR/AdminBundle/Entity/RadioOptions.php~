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
     * NOTE - this needs to remain in synch with the option name in the associated metadata entity...
     * If it doesn't, CSV/XML importing can't check concurrently that a RadioOption exists
     *
     * @var string
     */
    private $optionName;

    /**
     * @var string
     */
    private $radioOptionUuid;

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
    private $radioOptionMeta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $radioSelections;

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
     * Constructor
     */
    public function __construct()
    {
        $this->radioOptionMeta = new \Doctrine\Common\Collections\ArrayCollection();
        $this->radioSelections = new \Doctrine\Common\Collections\ArrayCollection();
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
     * NOTE - this needs to remain in synch with the option name in the associated metadata entity
     * if it doesn't, CSV/XML importing can't check concurrently that a RadioOption exists
     *
     * @param string $optionName
     *
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
        return $this->getRadioOptionMeta()->getOptionName();
    }

    /**
     * Set radioOptionUuid
     *
     * @param string $radioOptionUuid
     *
     * @return RadioOptions
     */
    public function setRadioOptionUuid($radioOptionUuid)
    {
        $this->radioOptionUuid = $radioOptionUuid;

        return $this;
    }

    /**
     * Get radioOptionUuid
     *
     * @return string
     */
    public function getRadioOptionUuid()
    {
        return $this->radioOptionUuid;
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
     * Add radioOptionMeta
     *
     * @param \ODR\AdminBundle\Entity\RadioOptionsMeta $radioOptionMeta
     * @return RadioOptions
     */
    public function addRadioOptionMetum(\ODR\AdminBundle\Entity\RadioOptionsMeta $radioOptionMeta)
    {
        $this->radioOptionMeta[] = $radioOptionMeta;

        return $this;
    }

    /**
     * Remove radioOptionMeta
     *
     * @param \ODR\AdminBundle\Entity\RadioOptionsMeta $radioOptionMeta
     */
    public function removeRadioOptionMetum(\ODR\AdminBundle\Entity\RadioOptionsMeta $radioOptionMeta)
    {
        $this->radioOptionMeta->removeElement($radioOptionMeta);
    }

    /**
     * Get radioOptionMeta
     *
     * @return \ODR\AdminBundle\Entity\RadioOptionsMeta
     */
    public function getRadioOptionMeta()
    {
        return $this->radioOptionMeta->first();
    }

    /**
     * Add radioSelections
     *
     * @param \ODR\AdminBundle\Entity\RadioSelection $radioSelections
     * @return RadioOptions
     */
    public function addRadioSelection(\ODR\AdminBundle\Entity\RadioSelection $radioSelections)
    {
        $this->radioSelections[] = $radioSelections;

        return $this;
    }

    /**
     * Remove radioSelections
     *
     * @param \ODR\AdminBundle\Entity\RadioSelection $radioSelections
     */
    public function removeRadioSelection(\ODR\AdminBundle\Entity\RadioSelection $radioSelections)
    {
        $this->radioSelections->removeElement($radioSelections);
    }

    /**
     * Get radioSelections
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRadioSelections()
    {
        return $this->radioSelections;
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
     * Get xml_optionName
     *
     * @return string
     */
    public function getXmlOptionName()
    {
        return $this->getRadioOptionMeta()->getXmlOptionName();
    }

    /**
     * Get displayOrder
     *
     * @return integer
     */
    public function getDisplayOrder()
    {
        return $this->getRadioOptionMeta()->getDisplayOrder();
    }

    /**
     * Get isDefault
     *
     * @return boolean
     */
    public function getIsDefault()
    {
        return $this->getRadioOptionMeta()->getIsDefault();
    }
}
