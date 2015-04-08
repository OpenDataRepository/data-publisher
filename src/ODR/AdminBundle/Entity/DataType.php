<?php

/**
* Open Data Repository Data Publisher
* DataType Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The DataType Entity is automatically generated from 
* ./Resources/config/doctrine/DataType.orm.yml
*
* TODO - remove Gedmo stuff because it's already dealt with in the ORM file?
*/

namespace ODR\AdminBundle\Entity;

use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\ORM\Mapping as ORM;

/**
 * ODR\AdminBundle\Entity\DataType
 * @Gedmo\SoftDeleteable(fieldName="deletedAt")
 */
class DataType
{
    /**
     * @var integer $id
     */
    private $id;

    /**
     * @Gedmo\Versioned
     * @var string $shortName
     */
    private $shortName;

    /**
     * @Gedmo\Versioned
     * @var string $longName
     */
    private $longName;

    /**
     * @Gedmo\Versioned
     * @var string $description
     */
    private $description;

    /**
     * @Gedmo\Versioned
     * @var boolean $multipleRecordsPerParent
     */
    private $multipleRecordsPerParent;


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
     * Set shortName
     *
     * @param string $shortName
     * @return DataType
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
     * @return DataType
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
     * Set multipleRecordsPerParent
     *
     * @param boolean $multipleRecordsPerParent
     * @return DataType
     */
    public function setMultipleRecordsPerParent($multipleRecordsPerParent)
    {
        $this->multipleRecordsPerParent = $multipleRecordsPerParent;
    
        return $this;
    }

    /**
     * Get multipleRecordsPerParent
     *
     * @return boolean 
     */
    public function getMultipleRecordsPerParent()
    {
        return $this->multipleRecordsPerParent;
    }

    /**
     * Set description
     *
     * @param string $description
     * @return DataType
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
     * @var \Doctrine\Common\Collections\ArrayCollection
     */
    private $dataFields;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->publicDate = '1999-01-01 00:00:00';
        $this->dataFields = new \Doctrine\Common\Collections\ArrayCollection();
    }
    
    /**
     * Add dataFields
     *
     * @param ODR\AdminBundle\Entity\DataFields $dataFields
     * @return DataType
     */
    public function addDataField(\ODR\AdminBundle\Entity\DataFields $dataFields)
    {
        $this->dataFields[] = $dataFields;
    
        return $this;
    }

    /**
     * Remove dataFields
     *
     * @param ODR\AdminBundle\Entity\DataFields $dataFields
     */
    public function removeDataField(\ODR\AdminBundle\Entity\DataFields $dataFields)
    {
        $this->dataFields->removeElement($dataFields);
    }

    /**
     * Get dataFields
     *
     * @return Doctrine\Common\Collections\Collection 
     */
    public function getDataFields()
    {
        return $this->dataFields;
    }
    /**
     * @var \Doctrine\Common\Collections\ArrayCollection
     */
    private $themeDataType;


    /**
     * Add themeDataType
     *
     * @param ODR\AdminBundle\Entity\ThemeDataType $themeDataType
     * @return DataType
     */
    public function addThemeDataType(\ODR\AdminBundle\Entity\ThemeDataType $themeDataType)
    {
        $this->themeDataType[] = $themeDataType;
    
        return $this;
    }

    /**
     * Remove themeDataType
     *
     * @param ODR\AdminBundle\Entity\ThemeDataType $themeDataType
     */
    public function removeThemeDataType(\ODR\AdminBundle\Entity\ThemeDataType $themeDataType)
    {
        $this->themeDataType->removeElement($themeDataType);
    }

    /**
     * Get themeDataType
     *
     * @return Doctrine\Common\Collections\Collection 
     */
    public function getThemeDataType()
    {
        return $this->themeDataType;
    }
    /**
     * @Gedmo\Versioned
     * @var \DateTime $publicDate
     */
    private $publicDate;


    /**
     * Set publicDate
     *
     * @param \DateTime $publicDate
     * @return DataType
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
     * @var \DateTime
     */
    private $deletedAt;


    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return DataType
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
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $updated;


    /**
     * Set created
     *
     * @param \DateTime $created
     * @return DataType
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
     * @return DataType
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
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     * @Gedmo\Blameable(on="create")
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     * @Gedmo\Blameable
     */
    private $updatedBy;


    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return DataType
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
     * @return DataType
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
     * @var \ODR\AdminBundle\Entity\RenderPlugin
     */
    private $renderPlugin;


    /**
     * Set renderPlugin
     *
     * @param \ODR\AdminBundle\Entity\RenderPlugin $renderPlugin
     * @return DataType
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
    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeElement;


    /**
     * Add themeElement
     *
     * @param \ODR\AdminBundle\Entity\ThemeElement $themeElement
     * @return DataType
     */
    public function addThemeElement(\ODR\AdminBundle\Entity\ThemeElement $themeElement)
    {
        $this->themeElement[] = $themeElement;
    
        return $this;
    }

    /**
     * Remove themeElement
     *
     * @param \ODR\AdminBundle\Entity\ThemeElement $themeElement
     */
    public function removeThemeElement(\ODR\AdminBundle\Entity\ThemeElement $themeElement)
    {
        $this->themeElement->removeElement($themeElement);
    }

    /**
     * Get themeElement
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getThemeElement()
    {
        return $this->themeElement;
    }

    /**
     * Get XMLShortName
     *
     * @return string 
     */
    public function getXMLShortName()
    {
        $search = array(" ", "\'", "\"", "<", ">", "&", "?", "(", ")");
        $replacements = array("_", "", "", "&lt;", "&gt;", "&amp;", "", "", "");

//        $search = array(" ", "\'", "\"", "/");
//        $replacements = array("_", "", "", "");

        return str_replace($search, $replacements, $this->shortName);
    }

    /**
     * @var boolean
     */
    private $useShortResults;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $sortField;


    /**
     * Set useShortResults
     *
     * @param boolean $useShortResults
     * @return DataType
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
     * Set sortField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $sortField
     * @return DataType
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
     * @var integer
     */
    private $display_type;

    /**
     * Set display_type
     *
     * @param integer $displayType
     * @return DataType
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
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $nameField;


    /**
     * Set nameField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $nameField
     * @return DataType
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
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $backgroundImageField;


    /**
     * Set backgroundImageField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $backgroundImageField
     * @return DataType
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
     * @var integer
     */
    private $revision;


    /**
     * Set revision
     *
     * @param integer $revision
     * @return DataType
     */
    public function setRevision($revision)
    {
        $this->revision = $revision;
    
        return $this;
    }

    /**
     * Get revision
     *
     * @return integer 
     */
    public function getRevision()
    {
        return $this->revision;
    }
    /**
     * @var string
     */
    private $searchSlug;


    /**
     * Set searchSlug
     *
     * @param string $searchSlug
     * @return DataType
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
     * @var boolean
     */
    private $has_shortresults;

    /**
     * @var boolean
     */
    private $has_textresults;


    /**
     * Set has_shortresults
     *
     * @param boolean $hasShortresults
     * @return DataType
     */
    public function setHasShortresults($hasShortresults)
    {
        $this->has_shortresults = $hasShortresults;

        return $this;
    }

    /**
     * Get has_shortresults
     *
     * @return boolean 
     */
    public function getHasShortresults()
    {
        return $this->has_shortresults;
    }

    /**
     * Set has_textresults
     *
     * @param boolean $hasTextresults
     * @return DataType
     */
    public function setHasTextresults($hasTextresults)
    {
        $this->has_textresults = $hasTextresults;

        return $this;
    }

    /**
     * Get has_textresults
     *
     * @return boolean 
     */
    public function getHasTextresults()
    {
        return $this->has_textresults;
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

}
