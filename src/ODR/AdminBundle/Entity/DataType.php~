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
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * DataType
 */
class DataType
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var integer
     */
    private $revision;

    /**
     * @var boolean
     */
    private $has_shortresults;

    /**
     * @var boolean
     */
    private $has_textresults;

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
    private $dataTypeMeta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeDataType;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themes;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $groups;

    /**
     * @var \ODR\AdminBundle\Entity\RenderPlugin
     */
    private $renderPlugin;

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
     * Constructor
     */
    public function __construct()
    {
        $this->dataTypeMeta = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themeDataType = new \Doctrine\Common\Collections\ArrayCollection();
        $this->dataFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themes = new \Doctrine\Common\Collections\ArrayCollection();
        $this->groups = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Add dataTypeMeta
     *
     * @param \ODR\AdminBundle\Entity\DataTypeMeta $dataTypeMeta
     * @return DataType
     */
    public function addDataTypeMetum(\ODR\AdminBundle\Entity\DataTypeMeta $dataTypeMeta)
    {
        $this->dataTypeMeta[] = $dataTypeMeta;

        return $this;
    }

    /**
     * Remove dataTypeMeta
     *
     * @param \ODR\AdminBundle\Entity\DataTypeMeta $dataTypeMeta
     */
    public function removeDataTypeMetum(\ODR\AdminBundle\Entity\DataTypeMeta $dataTypeMeta)
    {
        $this->dataTypeMeta->removeElement($dataTypeMeta);
    }

    /**
     * Get dataTypeMeta
     *
     * @return \ODR\AdminBundle\Entity\DataTypeMeta
     */
    public function getDataTypeMeta()
    {
        return $this->dataTypeMeta->first();
    }

    /**
     * Add themeDataType
     *
     * @param \ODR\AdminBundle\Entity\ThemeDataType $themeDataType
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
     * @param \ODR\AdminBundle\Entity\ThemeDataType $themeDataType
     */
    public function removeThemeDataType(\ODR\AdminBundle\Entity\ThemeDataType $themeDataType)
    {
        $this->themeDataType->removeElement($themeDataType);
    }

    /**
     * Get themeDataType
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getThemeDataType()
    {
        return $this->themeDataType;
    }

    /**
     * Add dataFields
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataFields
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
     * @param \ODR\AdminBundle\Entity\DataFields $dataFields
     */
    public function removeDataField(\ODR\AdminBundle\Entity\DataFields $dataFields)
    {
        $this->dataFields->removeElement($dataFields);
    }

    /**
     * Get dataFields
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getDataFields()
    {
        return $this->dataFields;
    }

    /**
     * Add themes
     *
     * @param \ODR\AdminBundle\Entity\Theme $themes
     * @return DataType
     */
    public function addTheme(\ODR\AdminBundle\Entity\Theme $themes)
    {
        $this->themes[] = $themes;

        return $this;
    }

    /**
     * Remove themes
     *
     * @param \ODR\AdminBundle\Entity\Theme $themes
     */
    public function removeTheme(\ODR\AdminBundle\Entity\Theme $themes)
    {
        $this->themes->removeElement($themes);
    }

    /**
     * Get themes
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getThemes()
    {
        return $this->themes;
    }

    /**
     * Add group
     *
     * @param \ODR\AdminBundle\Entity\Group $group
     *
     * @return DataType
     */
    public function addGroup(\ODR\AdminBundle\Entity\Group $group)
    {
        $this->groups[] = $group;

        return $this;
    }

    /**
     * Remove group
     *
     * @param \ODR\AdminBundle\Entity\Group $group
     */
    public function removeGroup(\ODR\AdminBundle\Entity\Group $group)
    {
        $this->groups->removeElement($group);
    }

    /**
     * Get groups
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getGroups()
    {
        return $this->groups;
    }

    /**
     * Set renderPlugin
     * @internal Requred by Doctrine, but should not be used...this value should be saved in the associated datatypeMeta entry.
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
     * Set deletedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $deletedBy
     * @return DataType
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
     * Is public
     *
     * @return boolean
     */
    public function isPublic()
    {
        if ($this->getPublicDate()->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
            return false;
        else
            return true;
    }


    /**
     * Get searchSlug
     *
     * @return string
     */
    public function getSearchSlug()
    {
        return $this->getDataTypeMeta()->getSearchSlug();
    }

    /**
     * Get shortName
     *
     * @return string
     */
    public function getShortName()
    {
        return $this->getDataTypeMeta()->getShortName();
    }

    /**
     * Get longName
     *
     * @return string
     */
    public function getLongName()
    {
        return $this->getDataTypeMeta()->getLongName();
    }

    /**
     * Get description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->getDataTypeMeta()->getDescription();
    }

    /**
     * Get xml_shortName
     *
     * @return string
     */
    public function getXmlShortName()
    {
        return $this->getDataTypeMeta()->getXmlShortName();
    }

    /**
     * Get useShortResults
     *
     * @return boolean
     */
    public function getUseShortResults()
    {
        return $this->getDataTypeMeta()->getUseShortResults();
    }

    /**
     * Get publicDate
     *
     * @return \DateTime
     */
    public function getPublicDate()
    {
        return $this->getDataTypeMeta()->getPublicDate();
    }

    /**
     * Get externalIdField
     *
     * @return \ODR\AdminBundle\Entity\DataFields
     */
    public function getExternalIdField()
    {
        return $this->getDataTypeMeta()->getExternalIdField();
    }

    /**
     * Get nameField
     *
     * @return \ODR\AdminBundle\Entity\DataFields
     */
    public function getNameField()
    {
        return $this->getDataTypeMeta()->getNameField();
    }

    /**
     * Get sortField
     *
     * @return \ODR\AdminBundle\Entity\DataFields
     */
    public function getSortField()
    {
        return $this->getDataTypeMeta()->getSortField();
    }

    /**
     * Get backgroundImageField
     *
     * @return \ODR\AdminBundle\Entity\DataFields
     */
    public function getBackgroundImageField()
    {
        return $this->getDataTypeMeta()->getBackgroundImageField();
    }

    /**
     * Get renderPlugin
     *
     * @return \ODR\AdminBundle\Entity\RenderPlugin
     */
    public function getRenderPlugin()
    {
        return $this->getDataTypeMeta()->getRenderPlugin();
    }
    /**
     * @var boolean
     */
    private $is_master_type;

    /**
     * @var integer
     */
    private $master_published_revision;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $relatedMasterTypes;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $masterDataType;


    /**
     * Set isMasterType
     *
     * @param boolean $isMasterType
     *
     * @return DataType
     */
    public function setIsMasterType($isMasterType)
    {
        $this->is_master_type = $isMasterType;

        return $this;
    }

    /**
     * Get isMasterType
     *
     * @return boolean
     */
    public function getIsMasterType()
    {
        return $this->is_master_type;
    }

    /**
     * Set masterPublishedRevision
     *
     * @param integer $masterPublishedRevision
     *
     * @return DataType
     */
    public function setMasterPublishedRevision($masterPublishedRevision)
    {
        $this->master_published_revision = $masterPublishedRevision;

        return $this;
    }

    /**
     * Get masterPublishedRevision
     *
     * @return integer
     */
    public function getMasterPublishedRevision()
    {
        return $this->master_published_revision;
    }

    /**
     * Add relatedMasterType
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $relatedMasterType
     *
     * @return DataType
     */
    public function addRelatedMasterType(\ODR\AdminBundle\Entity\DataRecord $relatedMasterType)
    {
        $this->relatedMasterTypes[] = $relatedMasterType;

        return $this;
    }

    /**
     * Remove relatedMasterType
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $relatedMasterType
     */
    public function removeRelatedMasterType(\ODR\AdminBundle\Entity\DataRecord $relatedMasterType)
    {
        $this->relatedMasterTypes->removeElement($relatedMasterType);
    }

    /**
     * Get relatedMasterTypes
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRelatedMasterTypes()
    {
        return $this->relatedMasterTypes;
    }

    /**
     * Set masterDataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $masterDataType
     *
     * @return DataType
     */
    public function setMasterDataType(\ODR\AdminBundle\Entity\DataType $masterDataType = null)
    {
        $this->masterDataType = $masterDataType;

        return $this;
    }

    /**
     * Get masterDataType
     *
     * @return \ODR\AdminBundle\Entity\DataType
     */
    public function getMasterDataType()
    {
        return $this->masterDataType;
    }
    /**
     * @var string
     */
    private $setup_step;


    /**
     * Set setupStep
     *
     * @param string $setupStep
     *
     * @return DataType
     */
    public function setSetupStep($setupStep)
    {
        $this->setup_step = $setupStep;

        return $this;
    }

    /**
     * Get setupStep
     *
     * @return string
     */
    public function getSetupStep()
    {
        return $this->setup_step;
    }
}
