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
    // These are defined as strings instead of bitmasks because they're easier to read in the twig files
    // Datatypes in these states are usually still being copied from a master template, and shouldn't be displayed/used elsewhere
    const STATE_INITIAL = "initial";
    // Datatypes in this state are technically viewable, but lack a search results theme
//    const STATE_INCOMPLETE = "incomplete";
    // Datatypes in this state have all the theme and permission entries required to be viewable
    const STATE_OPERATIONAL = "operational";

    // Convenience state so controllers can filter out datatypes that aren't ready for general use yet
    const STATE_VIEWABLE = array(/*self::STATE_INCOMPLETE,*/ self::STATE_OPERATIONAL);

    // Datatypes in this state encountered some sort of error while cloning, and pretty much need
    //  manual intervention to be fixed...
    const STATE_CLONE_FAIL = "clone_fail";


    /**
     * @var integer
     */
    private $id;

    /**
     * @var integer
     */
    private $revision;

    /**
     * @var string
     */
    private $unique_id;

    /**
     * @var string
     */
    private $setup_step;

    /**
     * @var string|null
     */
    private $preload_status;

    /**
     * @var boolean
     */
    private $is_master_type;

    /**
     * @var string
     */
    private $template_group;

    /**
     * @var string
     */
    private $datatype_type;

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
    private $grandchildren;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $children;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $relatedMasterTypes;

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
    private $dataRecords;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themes;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $groups;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $groupDatatypePermissions;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginInstances;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataTypeSpecialFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $storedSearchKeys;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $metadata_datatype;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $metadata_for;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $parent;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $grandparent;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $masterDataType;

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
        $this->grandchildren = new \Doctrine\Common\Collections\ArrayCollection();
        $this->children = new \Doctrine\Common\Collections\ArrayCollection();
        $this->relatedMasterTypes = new \Doctrine\Common\Collections\ArrayCollection();
        $this->dataTypeMeta = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themeDataType = new \Doctrine\Common\Collections\ArrayCollection();
        $this->dataFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themes = new \Doctrine\Common\Collections\ArrayCollection();
        $this->groups = new \Doctrine\Common\Collections\ArrayCollection();
        $this->groupDatatypePermissions = new \Doctrine\Common\Collections\ArrayCollection();
        $this->renderPluginInstances = new \Doctrine\Common\Collections\ArrayCollection();
        $this->dataTypeSpecialFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->storedSearchKeys = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set uniqueId
     *
     * @param string $uniqueId
     *
     * @return DataType
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

    /**
     * Set preloadStatus.
     *
     * @param string|null $preloadStatus
     *
     * @return DataType
     */
    public function setPreloadStatus($preloadStatus = null)
    {
        $this->preload_status = $preloadStatus;

        return $this;
    }

    /**
     * Get preloadStatus.
     *
     * @return string|null
     */
    public function getPreloadStatus()
    {
        return $this->preload_status;
    }

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
     * Set templateGroup
     *
     * @param string $templateGroup
     *
     * @return DataType
     */
    public function setTemplateGroup($templateGroup)
    {
        $this->template_group = $templateGroup;

        return $this;
    }

    /**
     * Get templateGroup
     *
     * @return string
     */
    public function getTemplateGroup()
    {
        return $this->template_group;
    }

    /**
     * Set datatypeType
     *
     * @param string $datatypeType
     *
     * @return DataType
     */
    public function setDatatypeType($datatypeType)
    {
        $this->datatype_type = $datatypeType;

        return $this;
    }

    /**
     * Get datatypeType
     *
     * @return string
     */
    public function getDatatypeType()
    {
        return $this->datatype_type;
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
     * Add grandchild
     *
     * @param \ODR\AdminBundle\Entity\DataType $grandchild
     *
     * @return DataType
     */
    public function addGrandchild(\ODR\AdminBundle\Entity\DataType $grandchild)
    {
        $this->grandchildren[] = $grandchild;

        return $this;
    }

    /**
     * Remove grandchild
     *
     * @param \ODR\AdminBundle\Entity\DataType $grandchild
     */
    public function removeGrandchild(\ODR\AdminBundle\Entity\DataType $grandchild)
    {
        $this->grandchildren->removeElement($grandchild);
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
     * Add child
     *
     * @param \ODR\AdminBundle\Entity\DataType $child
     *
     * @return DataType
     */
    public function addChild(\ODR\AdminBundle\Entity\DataType $child)
    {
        $this->children[] = $child;

        return $this;
    }

    /**
     * Remove child
     *
     * @param \ODR\AdminBundle\Entity\DataType $child
     */
    public function removeChild(\ODR\AdminBundle\Entity\DataType $child)
    {
        $this->children->removeElement($child);
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
     * Add dataRecord.
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $dataRecord
     *
     * @return DataType
     */
    public function addDataRecord(\ODR\AdminBundle\Entity\DataRecord $dataRecord)
    {
        $this->dataRecords[] = $dataRecord;

        return $this;
    }

    /**
     * Remove dataRecord.
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $dataRecord
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeDataRecord(\ODR\AdminBundle\Entity\DataRecord $dataRecord)
    {
        return $this->dataRecords->removeElement($dataRecord);
    }

    /**
     * Get dataRecords.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDataRecords()
    {
        return $this->dataRecords;
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
     * NOTE: Only a top-level datatype actually "has" groups in the database...the groups are applied to child datatypes
     * through GroupDatatypePermission entities.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getGroups()
    {
        return $this->groups;
    }

    /**
     * Add groupDatatypePermission
     *
     * @param \ODR\AdminBundle\Entity\GroupDatatypePermissions $groupDatatypePermission
     *
     * @return DataType
     */
    public function addGroupDatatypePermission(\ODR\AdminBundle\Entity\GroupDatatypePermissions $groupDatatypePermission)
    {
        $this->groupDatatypePermissions[] = $groupDatatypePermission;

        return $this;
    }

    /**
     * Remove groupDatatypePermission
     *
     * @param \ODR\AdminBundle\Entity\GroupDatatypePermissions $groupDatatypePermission
     */
    public function removeGroupDatatypePermission(\ODR\AdminBundle\Entity\GroupDatatypePermissions $groupDatatypePermission)
    {
        $this->groupDatatypePermissions->removeElement($groupDatatypePermission);
    }

    /**
     * Get groupDatatypePermissions
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getGroupDatatypePermissions()
    {
        return $this->groupDatatypePermissions;
    }

    /**
     * Add renderPluginInstance.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance
     *
     * @return DataType
     */
    public function addRenderPluginInstance(\ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance)
    {
        $this->renderPluginInstances[] = $renderPluginInstance;

        return $this;
    }

    /**
     * Remove renderPluginInstance.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeRenderPluginInstance(\ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance)
    {
        return $this->renderPluginInstances->removeElement($renderPluginInstance);
    }

    /**
     * Get renderPluginInstances.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRenderPluginInstances()
    {
        return $this->renderPluginInstances;
    }

    /**
     * Add dataTypeSpecialField.
     *
     * @param \ODR\AdminBundle\Entity\DataTypeSpecialFields $dataTypeSpecialField
     *
     * @return DataType
     */
    public function addDataTypeSpecialField(\ODR\AdminBundle\Entity\DataTypeSpecialFields $dataTypeSpecialField)
    {
        $this->dataTypeSpecialFields[] = $dataTypeSpecialField;

        return $this;
    }

    /**
     * Remove dataTypeSpecialField.
     *
     * @param \ODR\AdminBundle\Entity\DataTypeSpecialFields $dataTypeSpecialField
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeDataTypeSpecialField(\ODR\AdminBundle\Entity\DataTypeSpecialFields $dataTypeSpecialField)
    {
        return $this->dataTypeSpecialFields->removeElement($dataTypeSpecialField);
    }

    /**
     * Get dataTypeSpecialFields.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDataTypeSpecialFields()
    {
        return $this->dataTypeSpecialFields;
    }

    /**
     * Add storedSearchKey.
     *
     * @param \ODR\AdminBundle\Entity\StoredSearchKey $storedSearchKey
     *
     * @return DataType
     */
    public function addStoredSearchKey(\ODR\AdminBundle\Entity\StoredSearchKey $storedSearchKey)
    {
        $this->storedSearchKeys[] = $storedSearchKey;

        return $this;
    }

    /**
     * Remove storedSearchKey.
     *
     * @param \ODR\AdminBundle\Entity\StoredSearchKey $storedSearchKey
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeStoredSearchKey(\ODR\AdminBundle\Entity\StoredSearchKey $storedSearchKey)
    {
        return $this->storedSearchKeys->removeElement($storedSearchKey);
    }

    /**
     * Get storedSearchKeys.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getStoredSearchKeys()
    {
        return $this->storedSearchKeys;
    }

    /**
     * Set metadata_datatype
     *
     * @param \ODR\AdminBundle\Entity\DataType $metadata_datatype
     *
     * @return DataType
     */
    public function setMetadataDatatype(\ODR\AdminBundle\Entity\DataType $metadata_datatype = null)
    {
        $this->metadata_datatype = $metadata_datatype;

        return $this;
    }

    /**
     * Get metadata_datatype
     *
     * @return \ODR\AdminBundle\Entity\DataType
     */
    public function getMetadataDatatype()
    {
        return $this->metadata_datatype;
    }

    /**
     * Set metadata_for
     *
     * @param \ODR\AdminBundle\Entity\DataType $metadata_for
     *
     * @return DataType
     */
    public function setMetadataFor(\ODR\AdminBundle\Entity\DataType $metadata_for = null)
    {
        $this->metadata_for = $metadata_for;

        return $this;
    }

    /**
     * Get metadata_for
     *
     * @return \ODR\AdminBundle\Entity\DataType
     */
    public function getMetadataFor()
    {
        return $this->metadata_for;
    }

    /**
     * Set parent
     *
     * @param \ODR\AdminBundle\Entity\DataType $parent
     *
     * @return DataType
     */
    public function setParent(\ODR\AdminBundle\Entity\DataType $parent = null)
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * Get parent
     *
     * @return \ODR\AdminBundle\Entity\DataType
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Set grandparent
     *
     * @param \ODR\AdminBundle\Entity\DataType $grandparent
     *
     * @return DataType
     */
    public function setGrandparent(\ODR\AdminBundle\Entity\DataType $grandparent = null)
    {
        $this->grandparent = $grandparent;

        return $this;
    }

    /**
     * Get grandparent
     *
     * @return \ODR\AdminBundle\Entity\DataType
     */
    public function getGrandparent()
    {
        return $this->grandparent;
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
     * Get masterRevision
     *
     * @return integer
     */
    public function getMasterRevision()
    {
        return $this->getDataTypeMeta()->getMasterRevision();
    }

    /**
     * Get masterPublishedRevision
     *
     * @return integer
     */
    public function getMasterPublishedRevision()
    {
        return $this->getDataTypeMeta()->getMasterPublishedRevision();
    }

    /**
     * Get trackingMasterRevision
     *
     * @return integer
     */
    public function getTrackingMasterRevision()
    {
        return $this->getDataTypeMeta()->getTrackingMasterRevision();
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
     * Get searchNotesUpper
     *
     * @return string
     */
    public function getSearchNotesUpper()
    {
        return $this->getDataTypeMeta()->getSearchNotesUpper();
    }

    /**
     * Get searchNotesLower
     *
     * @return string
     */
    public function getSearchNotesLower()
    {
        return $this->getDataTypeMeta()->getSearchNotesLower();
    }

    /**
     * Get publicDate
     *
     * @return \DateTime
     */
    public function getPublicDate()
    {
        if ( !is_bool($this->getDataTypeMeta()) )
            return $this->getDataTypeMeta()->getPublicDate();
        else
            return new \DateTime('2200-01-01 00:00:00');
    }

    /**
     * Get newRecordsArePublic
     *
     * @return bool
     */
    public function getNewRecordsArePublic()
    {
        return $this->getDataTypeMeta()->getNewRecordsArePublic();
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
     * @deprecated
     * Get nameField
     *
     * @return \ODR\AdminBundle\Entity\DataFields
     */
    public function getNameField()
    {
        return $this->getDataTypeMeta()->getNameField();
    }

    /**
     * @deprecated
     * Get sortField
     *
     * @return \ODR\AdminBundle\Entity\DataFields
     */
    public function getSortField()
    {
        return $this->getDataTypeMeta()->getSortField();
    }

    /**
     * @deprecated
     * Get backgroundImageField
     *
     * @return \ODR\AdminBundle\Entity\DataFields
     */
    public function getBackgroundImageField()
    {
        return $this->getDataTypeMeta()->getBackgroundImageField();
    }

    /**
     * Get all name fields defined for this datatype.
     * @return DataFields[]
     */
    public function getNameFields()
    {
        $namefields = array();
        if ( !is_null($this->dataTypeSpecialFields) ) {
            foreach ($this->dataTypeSpecialFields as $dtsf) {
                /** @var DataTypeSpecialFields $dtsf */
                if ( $dtsf->getFieldPurpose() === DataTypeSpecialFields::NAME_FIELD )
                    $namefields[$dtsf->getDisplayOrder()] = $dtsf->getDataField();
            }
            ksort($namefields);
            $namefields = array_values($namefields);
        }

        return $namefields;
    }

    /**
     * Get all sort fields defined for this datatype.
     * @return DataFields[]
     */
    public function getSortFields()
    {
        $sortfields = array();
        if ( !is_null($this->dataTypeSpecialFields) ) {
            foreach ($this->dataTypeSpecialFields as $dtsf) {
                /** @var DataTypeSpecialFields $dtsf */
                if ( $dtsf->getFieldPurpose() === DataTypeSpecialFields::SORT_FIELD )
                    $sortfields[$dtsf->getDisplayOrder()] = $dtsf->getDataField();
            }
            ksort($sortfields);
            $sortfields = array_values($sortfields);
        }

        return $sortfields;
    }
}
