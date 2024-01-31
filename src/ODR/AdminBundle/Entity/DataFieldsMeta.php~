<?php

/**
 * Open Data Repository Data Publisher
 * DataFieldsMeta Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The DataFieldsMeta Entity is responsible for storing the properties
 * of the DataFields Entity that are subject to change, and is
 * automatically generated from ./Resources/config/doctrine/DataFieldsMeta.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * DataFieldsMeta
 */
class DataFieldsMeta
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $fieldName;

    /**
     * @var string
     */
    private $description;

    /**
     * @var string
     */
    private $xml_fieldName;

    /**
     * @var string
     */
    private $internal_reference_name;

    /**
     * @var integer
     */
    private $master_published_revision;

    /**
     * @var integer
     */
    private $master_revision;

    /**
     * @var integer
     */
    private $tracking_master_revision;

    /**
     * @var string
     */
    private $markdownText;

    /**
     * @var string
     */
    private $regexValidator;

    /**
     * @var string
     */
    private $phpValidator;

    /**
     * @var boolean
     */
    private $required;

    /**
     * @var boolean
     */
    private $is_unique;

    /**
     * @var bool
     */
    private $prevent_user_edits;

    /**
     * @var boolean
     */
    private $allow_multiple_uploads;

    /**
     * @var boolean
     */
    private $shorten_filename;

    /**
     * @var bool
     */
    private $newFilesArePublic;

    /**
     * @var string
     */
    private $quality_str;

    /**
     * @var integer
     */
    private $children_per_row;

    /**
     * @var boolean
     */
    private $radio_option_name_sort;

    /**
     * @var boolean
     */
    private $radio_option_display_unselected;

    /**
     * @var boolean
     */
    private $tags_allow_multiple_levels;

    /**
     * @var boolean
     */
    private $tags_allow_non_admin_edit;

    /**
     * @var integer
     */
    private $searchable;

    /**
     * @var \DateTime
     */
    private $publicDate;

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
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var \ODR\AdminBundle\Entity\FieldType
     */
    private $fieldType;

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
     * Set fieldName
     *
     * @param string $fieldName
     * @return DataFieldsMeta
     */
    public function setFieldName($fieldName)
    {
        $this->fieldName = $fieldName;

        return $this;
    }

    /**
     * Get fieldName
     *
     * @return string
     */
    public function getFieldName()
    {
        return $this->fieldName;
    }

    /**
     * Set description
     *
     * @param string $description
     * @return DataFieldsMeta
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
     * Set xml_fieldName
     *
     * @param string $xmlFieldName
     * @return DataFieldsMeta
     */
    public function setXmlFieldName($xmlFieldName)
    {
        $this->xml_fieldName = $xmlFieldName;

        return $this;
    }

    /**
     * Get xml_fieldName
     *
     * @return string
     */
    public function getXmlFieldName()
    {
        return $this->xml_fieldName;
    }

    /**
     * Set internalReferenceName
     *
     * @param string $internalReferenceName
     *
     * @return DataFieldsMeta
     */
    public function setInternalReferenceName($internalReferenceName)
    {
        $this->internal_reference_name = $internalReferenceName;

        return $this;
    }

    /**
     * Get internalReferenceName
     *
     * @return string
     */
    public function getInternalReferenceName()
    {
        return $this->internal_reference_name;
    }

    /**
     * Set masterPublishedRevision
     *
     * @param integer $masterPublishedRevision
     *
     * @return DataFieldsMeta
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
     * Set masterRevision
     *
     * @param integer $masterRevision
     *
     * @return DataFieldsMeta
     */
    public function setMasterRevision($masterRevision)
    {
        $this->master_revision = $masterRevision;

        return $this;
    }

    /**
     * Get masterRevision
     *
     * @return integer
     */
    public function getMasterRevision()
    {
        return $this->master_revision;
    }

    /**
     * Set trackingMasterRevision
     *
     * @param integer $trackingMasterRevision
     *
     * @return DataFieldsMeta
     */
    public function setTrackingMasterRevision($trackingMasterRevision)
    {
        $this->tracking_master_revision = $trackingMasterRevision;

        return $this;
    }

    /**
     * Get trackingMasterRevision
     *
     * @return integer
     */
    public function getTrackingMasterRevision()
    {
        return $this->tracking_master_revision;
    }

    /**
     * Set markdownText
     *
     * @param string $markdownText
     * @return DataFieldsMeta
     */
    public function setMarkdownText($markdownText)
    {
        $this->markdownText = $markdownText;

        return $this;
    }

    /**
     * Get markdownText
     *
     * @return string
     */
    public function getMarkdownText()
    {
        return $this->markdownText;
    }

    /**
     * Set regexValidator
     *
     * @param string $regexValidator
     * @return DataFieldsMeta
     */
    public function setRegexValidator($regexValidator)
    {
        $this->regexValidator = $regexValidator;

        return $this;
    }

    /**
     * Get regexValidator
     *
     * @return string
     */
    public function getRegexValidator()
    {
        return $this->regexValidator;
    }

    /**
     * Set phpValidator
     *
     * @param string $phpValidator
     * @return DataFieldsMeta
     */
    public function setPhpValidator($phpValidator)
    {
        $this->phpValidator = $phpValidator;

        return $this;
    }

    /**
     * Get phpValidator
     *
     * @return string
     */
    public function getPhpValidator()
    {
        return $this->phpValidator;
    }

    /**
     * Set required
     *
     * @param boolean $required
     * @return DataFieldsMeta
     */
    public function setRequired($required)
    {
        $this->required = $required;

        return $this;
    }

    /**
     * Get required
     *
     * @return boolean
     */
    public function getRequired()
    {
        return $this->required;
    }

    /**
     * Set is_unique
     *
     * @param boolean $isUnique
     * @return DataFieldsMeta
     */
    public function setIsUnique($isUnique)
    {
        $this->is_unique = $isUnique;

        return $this;
    }

    /**
     * Get is_unique
     *
     * @return boolean
     */
    public function getIsUnique()
    {
        return $this->is_unique;
    }

    /**
     * Set preventUserEdits.
     *
     * @param bool $preventUserEdits
     *
     * @return DataFieldsMeta
     */
    public function setPreventUserEdits($preventUserEdits)
    {
        $this->prevent_user_edits = $preventUserEdits;

        return $this;
    }

    /**
     * Get preventUserEdits.
     *
     * @return bool
     */
    public function getPreventUserEdits()
    {
        return $this->prevent_user_edits;
    }

    /**
     * Set allow_multiple_uploads
     *
     * @param boolean $allowMultipleUploads
     * @return DataFieldsMeta
     */
    public function setAllowMultipleUploads($allowMultipleUploads)
    {
        $this->allow_multiple_uploads = $allowMultipleUploads;

        return $this;
    }

    /**
     * Get allow_multiple_uploads
     *
     * @return boolean
     */
    public function getAllowMultipleUploads()
    {
        return $this->allow_multiple_uploads;
    }

    /**
     * Set shorten_filename
     *
     * @param boolean $shortenFilename
     * @return DataFieldsMeta
     */
    public function setShortenFilename($shortenFilename)
    {
        $this->shorten_filename = $shortenFilename;

        return $this;
    }

    /**
     * Get shorten_filename
     *
     * @return boolean
     */
    public function getShortenFilename()
    {
        return $this->shorten_filename;
    }

    /**
     * Set newFilesArePublic.
     *
     * @param bool $newFilesArePublic
     *
     * @return DataFieldsMeta
     */
    public function setNewFilesArePublic($newFilesArePublic)
    {
        $this->newFilesArePublic = $newFilesArePublic;

        return $this;
    }

    /**
     * Get newFilesArePublic.
     *
     * @return bool
     */
    public function getNewFilesArePublic()
    {
        return $this->newFilesArePublic;
    }

    /**
     * Set qualityStr.
     *
     * @param string $qualityStr
     *
     * @return DataFieldsMeta
     */
    public function setQualityStr($qualityStr)
    {
        $this->quality_str = $qualityStr;

        return $this;
    }

    /**
     * Get qualityStr.
     *
     * @return string
     */
    public function getQualityStr()
    {
        return $this->quality_str;
    }

    /**
     * Set children_per_row
     *
     * @param integer $childrenPerRow
     * @return DataFieldsMeta
     */
    public function setChildrenPerRow($childrenPerRow)
    {
        $this->children_per_row = $childrenPerRow;

        return $this;
    }

    /**
     * Get children_per_row
     *
     * @return integer
     */
    public function getChildrenPerRow()
    {
        return $this->children_per_row;
    }

    /**
     * Set radio_option_name_sort
     *
     * @param boolean $radioOptionNameSort
     * @return DataFieldsMeta
     */
    public function setRadioOptionNameSort($radioOptionNameSort)
    {
        $this->radio_option_name_sort = $radioOptionNameSort;

        return $this;
    }

    /**
     * Get radio_option_name_sort
     *
     * @return boolean
     */
    public function getRadioOptionNameSort()
    {
        return $this->radio_option_name_sort;
    }

    /**
     * Set radio_option_display_unselected
     *
     * @param boolean $radioOptionDisplayUnselected
     * @return DataFieldsMeta
     */
    public function setRadioOptionDisplayUnselected($radioOptionDisplayUnselected)
    {
        $this->radio_option_display_unselected = $radioOptionDisplayUnselected;

        return $this;
    }

    /**
     * Get radio_option_display_unselected
     *
     * @return boolean
     */
    public function getRadioOptionDisplayUnselected()
    {
        return $this->radio_option_display_unselected;
    }

    /**
     * Set tagsAllowMultipleLevels
     *
     * @param boolean $tagsAllowMultipleLevels
     *
     * @return DataFieldsMeta
     */
    public function setTagsAllowMultipleLevels($tagsAllowMultipleLevels)
    {
        $this->tags_allow_multiple_levels = $tagsAllowMultipleLevels;

        return $this;
    }

    /**
     * Get tagsAllowMultipleLevels
     *
     * @return boolean
     */
    public function getTagsAllowMultipleLevels()
    {
        return $this->tags_allow_multiple_levels;
    }

    /**
     * Set tagsAllowNonAdminEdit
     *
     * @param boolean $tagsAllowNonAdminEdit
     *
     * @return DataFieldsMeta
     */
    public function setTagsAllowNonAdminEdit($tagsAllowNonAdminEdit)
    {
        $this->tags_allow_non_admin_edit = $tagsAllowNonAdminEdit;

        return $this;
    }

    /**
     * Get tagsAllowNonAdminEdit
     *
     * @return boolean
     */
    public function getTagsAllowNonAdminEdit()
    {
        return $this->tags_allow_non_admin_edit;
    }

    /**
     * Set searchable
     *
     * @param integer $searchable
     * @return DataFieldsMeta
     */
    public function setSearchable($searchable)
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * Get searchable
     *
     * @return integer
     */
    public function getSearchable()
    {
        return $this->searchable;
    }

    /**
     * Set publicDate
     *
     * @param \DateTime $publicDate
     * @return DataFieldsMeta
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
     * Set created
     *
     * @param \DateTime $created
     * @return DataFieldsMeta
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
     * @return DataFieldsMeta
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
     * @return DataFieldsMeta
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
     * Set dataField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataField
     * @return DataFieldsMeta
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
     * Set fieldType
     *
     * @param \ODR\AdminBundle\Entity\FieldType $fieldType
     * @return DataFieldsMeta
     */
    public function setFieldType(\ODR\AdminBundle\Entity\FieldType $fieldType = null)
    {
        $this->fieldType = $fieldType;

        return $this;
    }

    /**
     * Get fieldType
     *
     * @return \ODR\AdminBundle\Entity\FieldType
     */
    public function getFieldType()
    {
        return $this->fieldType;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return DataFieldsMeta
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
     * @return DataFieldsMeta
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
