<?php

/**
 * Open Data Repository Data Publisher
 * DataTypeMeta Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The DataTypeMeta Entity is responsible for storing the properties
 * of the DataType Entity that are subject to change, and is
 * automatically generated from ./Resources/config/doctrine/DataTypeMeta.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * DataTypeMeta
 */
class DataTypeMeta
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $searchSlug;

    /**
     * @var string
     */
    private $shortName;

    /**
     * @var string
     */
    private $longName;

    /**
     * @var string
     */
    private $description;

    /**
     * @var string
     */
    private $xml_shortName;

    /**
     * @var boolean
     */
    private $useShortResults;

    /**
     * @var integer
     */
    private $display_type;

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
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $externalIdField;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $nameField;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $sortField;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $backgroundImageField;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\AdminBundle\Entity\RenderPlugin
     */
    private $renderPlugin;


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
     * Set searchSlug
     *
     * @param string $searchSlug
     * @return DataTypeMeta
     */
    public function setSearchSlug($searchSlug)
    {
        $this->searchSlug = $searchSlug;

        return $this;
    }

    /**
     * Get searchSlug
     *
     * @return string 
     */
    public function getSearchSlug()
    {
        return $this->searchSlug;
    }

    /**
     * Set shortName
     *
     * @param string $shortName
     * @return DataTypeMeta
     */
    public function setShortName($shortName)
    {
        $this->shortName = $shortName;

        return $this;
    }

    /**
     * Get shortName
     *
     * @return string 
     */
    public function getShortName()
    {
        return $this->shortName;
    }

    /**
     * Set longName
     *
     * @param string $longName
     * @return DataTypeMeta
     */
    public function setLongName($longName)
    {
        $this->longName = $longName;

        return $this;
    }

    /**
     * Get longName
     *
     * @return string 
     */
    public function getLongName()
    {
        return $this->longName;
    }

    /**
     * Set description
     *
     * @param string $description
     * @return DataTypeMeta
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
     * Set xml_shortName
     *
     * @param string $xmlShortName
     * @return DataTypeMeta
     */
    public function setXmlShortName($xmlShortName)
    {
        $this->xml_shortName = $xmlShortName;

        return $this;
    }

    /**
     * Get xml_shortName
     *
     * @return string 
     */
    public function getXmlShortName()
    {
        return $this->xml_shortName;
    }

    /**
     * Set useShortResults
     *
     * @param boolean $useShortResults
     * @return DataTypeMeta
     */
    public function setUseShortResults($useShortResults)
    {
        $this->useShortResults = $useShortResults;

        return $this;
    }

    /**
     * Get useShortResults
     *
     * @return boolean 
     */
    public function getUseShortResults()
    {
        return $this->useShortResults;
    }

    /**
     * Set display_type
     *
     * @param integer $displayType
     * @return DataTypeMeta
     */
    public function setDisplayType($displayType)
    {
        $this->display_type = $displayType;

        return $this;
    }

    /**
     * Get display_type
     *
     * @return integer 
     */
    public function getDisplayType()
    {
        return $this->display_type;
    }

    /**
     * Set publicDate
     *
     * @param \DateTime $publicDate
     * @return DataTypeMeta
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
     * @return DataTypeMeta
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
     * @return DataTypeMeta
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
     * Set externalIdField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $externalIdField
     * @return DataTypeMeta
     */
    public function setExternalIdField(\ODR\AdminBundle\Entity\DataFields $externalIdField = null)
    {
        $this->externalIdField = $externalIdField;

        return $this;
    }

    /**
     * Get externalIdField
     *
     * @return \ODR\AdminBundle\Entity\DataFields 
     */
    public function getExternalIdField()
    {
        return $this->externalIdField;
    }

    /**
     * Set nameField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $nameField
     * @return DataTypeMeta
     */
    public function setNameField(\ODR\AdminBundle\Entity\DataFields $nameField = null)
    {
        $this->nameField = $nameField;

        return $this;
    }

    /**
     * Get nameField
     *
     * @return \ODR\AdminBundle\Entity\DataFields 
     */
    public function getNameField()
    {
        return $this->nameField;
    }

    /**
     * Set sortField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $sortField
     * @return DataTypeMeta
     */
    public function setSortField(\ODR\AdminBundle\Entity\DataFields $sortField = null)
    {
        $this->sortField = $sortField;

        return $this;
    }

    /**
     * Get sortField
     *
     * @return \ODR\AdminBundle\Entity\DataFields 
     */
    public function getSortField()
    {
        return $this->sortField;
    }

    /**
     * Set backgroundImageField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $backgroundImageField
     * @return DataTypeMeta
     */
    public function setBackgroundImageField(\ODR\AdminBundle\Entity\DataFields $backgroundImageField = null)
    {
        $this->backgroundImageField = $backgroundImageField;

        return $this;
    }

    /**
     * Get backgroundImageField
     *
     * @return \ODR\AdminBundle\Entity\DataFields 
     */
    public function getBackgroundImageField()
    {
        return $this->backgroundImageField;
    }

    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return DataTypeMeta
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
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return DataTypeMeta
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
     * Set renderPlugin
     *
     * @param \ODR\AdminBundle\Entity\RenderPlugin $renderPlugin
     * @return DataTypeMeta
     */
    public function setRenderPlugin(\ODR\AdminBundle\Entity\RenderPlugin $renderPlugin = null)
    {
        $this->renderPlugin = $renderPlugin;

        return $this;
    }

    /**
     * Get renderPlugin
     *
     * @return \ODR\AdminBundle\Entity\RenderPlugin 
     */
    public function getRenderPlugin()
    {
        return $this->renderPlugin;
    }
}
