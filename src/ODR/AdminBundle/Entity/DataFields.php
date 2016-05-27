<?php

/**
 * Open Data Repository Data Publisher
 * DataFields Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The DataFields Entity is automatically generated from
 * ./Resources/config/doctrine/DataFields.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * DataFields
 */
class DataFields
{
    /**
     * @var integer
     */
    private $id;

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
     * @var boolean
     */
    private $allow_multiple_uploads;

    /**
     * @var boolean
     */
    private $shorten_filename;

    /**
     * @var integer
     */
    private $displayOrder;

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
     * @var integer
     */
    private $searchable;

    /**
     * @var boolean
     */
    private $user_only_search;

    /**
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataRecordFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeDataFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $radioOptions;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataFieldMeta;

    /**
     * @var \ODR\AdminBundle\Entity\FieldType
     */
    private $fieldType;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

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
        $this->dataRecordFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themeDataFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->radioOptions = new \Doctrine\Common\Collections\ArrayCollection();
        $this->dataFieldMeta = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set created
     *
     * @param \DateTime $created
     * @return DataFields
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
     * @return DataFields
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
     * Set fieldName
     * @deprecated
     *
     * @param string $fieldName
     * @return DataFields
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
        return $this->getDataFieldMeta()->getFieldName();
    }

    /**
     * Set description
     * @deprecated
     *
     * @param string $description
     * @return DataFields
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
        return $this->getDataFieldMeta()->getDescription();
    }

    /**
     * Set xml_fieldName
     * @deprecated
     *
     * @param string $xmlFieldName
     * @return DataFields
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
        return $this->getDataFieldMeta()->getXmlFieldName();
    }

    /**
     * Set markdownText
     * @deprecated
     *
     * @param string $markdownText
     * @return DataFields
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
        return $this->getDataFieldMeta()->getMarkdownText();
    }

    /**
     * Set regexValidator
     * @deprecated
     *
     * @param string $regexValidator
     * @return DataFields
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
        return $this->getDataFieldMeta()->getRegexValidator();
    }

    /**
     * Set phpValidator
     * @deprecated
     *
     * @param string $phpValidator
     * @return DataFields
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
        return $this->getDataFieldMeta()->getPhpValidator();
    }

    /**
     * Set required
     * @deprecated
     *
     * @param boolean $required
     * @return DataFields
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
        return $this->getDataFieldMeta()->getRequired();
    }

    /**
     * Set is_unique
     * @deprecated
     *
     * @param boolean $isUnique
     * @return DataFields
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
        return $this->getDataFieldMeta()->getIsUnique();
    }

    /**
     * Set allow_multiple_uploads
     * @deprecated
     *
     * @param boolean $allowMultipleUploads
     * @return DataFields
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
        return $this->getDataFieldMeta()->getAllowMultipleUploads();
    }

    /**
     * Set shorten_filename
     * @deprecated
     *
     * @param boolean $shortenFilename
     * @return DataFields
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
        return $this->getDataFieldMeta()->getShortenFilename();
    }

    /**
     * Set displayOrder
     * @deprecated
     *
     * @param integer $displayOrder
     * @return DataFields
     */
    public function setDisplayOrder($displayOrder)
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    /**
     * Get displayOrder
     * @deprecated 
     *
     * @return integer
     */
    public function getDisplayOrder()
    {
        return $this->getDataFieldMeta()->getDisplayOrder();
    }

    /**
     * Set children_per_row
     * @deprecated
     *
     * @param integer $childrenPerRow
     * @return DataFields
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
        return $this->getDataFieldMeta()->getChildrenPerRow();
    }

    /**
     * Set radio_option_name_sort
     * @deprecated
     *
     * @param boolean $radioOptionNameSort
     * @return DataFields
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
        return $this->getDataFieldMeta()->getRadioOptionNameSort();
    }

    /**
     * Set radio_option_display_unselected
     * @deprecated
     *
     * @param boolean $radioOptionDisplayUnselected
     * @return DataFields
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
        return $this->getDataFieldMeta()->getRadioOptionDisplayUnselected();
    }

    /**
     * Set searchable
     * @deprecated
     *
     * @param integer $searchable
     * @return DataFields
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
        return $this->getDataFieldMeta()->getSearchable();
    }

    /**
     * Set user_only_search
     * @deprecated
     *
     * @param boolean $userOnlySearch
     * @return DataFields
     */
    public function setUserOnlySearch($userOnlySearch)
    {
        $this->user_only_search = $userOnlySearch;

        return $this;
    }

    /**
     * Get user_only_search
     *
     * @return boolean
     */
    public function getUserOnlySearch()
    {
        return $this->getDataFieldMeta()->getUserOnlySearch();
    }

    /**
     * Set updated
     * @deprecated
     *
     * @param \DateTime $updated
     * @return DataFields
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
     * Add dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     * @return DataFields
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
     * Add themeDataFields
     *
     * @param \ODR\AdminBundle\Entity\ThemeDataField $themeDataFields
     * @return DataFields
     */
    public function addThemeDataField(\ODR\AdminBundle\Entity\ThemeDataField $themeDataFields)
    {
        $this->themeDataFields[] = $themeDataFields;

        return $this;
    }

    /**
     * Remove themeDataFields
     *
     * @param \ODR\AdminBundle\Entity\ThemeDataField $themeDataFields
     */
    public function removeThemeDataField(\ODR\AdminBundle\Entity\ThemeDataField $themeDataFields)
    {
        $this->themeDataFields->removeElement($themeDataFields);
    }

    /**
     * Get themeDataFields
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getThemeDataFields()
    {
        return $this->themeDataFields;
    }

    /**
     * Add radioOptions
     *
     * @param \ODR\AdminBundle\Entity\RadioOptions $radioOptions
     * @return DataFields
     */
    public function addRadioOption(\ODR\AdminBundle\Entity\RadioOptions $radioOptions)
    {
        $this->radioOptions[] = $radioOptions;

        return $this;
    }

    /**
     * Remove radioOptions
     *
     * @param \ODR\AdminBundle\Entity\RadioOptions $radioOptions
     */
    public function removeRadioOption(\ODR\AdminBundle\Entity\RadioOptions $radioOptions)
    {
        $this->radioOptions->removeElement($radioOptions);
    }

    /**
     * Get radioOptions
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRadioOptions()
    {
//        return $this->radioOptions;

        // Adapted from http://stackoverflow.com/a/16707694
        $iterator = $this->radioOptions->getIterator();
        $iterator->uasort(function ($a, $b) {
            // Sort by display order first if possible
            /** @var RadioOptions $a */
            /** @var RadioOptions $b */
            $a_display_order = $a->getDisplayOrder();
            $b_display_order = $b->getDisplayOrder();
            if ($a_display_order < $b_display_order)
                return -1;
            else if ($a_display_order > $b_display_order)
                return 1;
            else
                // otherwise, sort by radio_option_id
                return ($a->getId() < $b->getId()) ? -1 : 1;
        });
        return new ArrayCollection(iterator_to_array($iterator));
    }

    /**
     * Add dataFieldMeta
     *
     * @param \ODR\AdminBundle\Entity\DataFieldsMeta $dataFieldMeta
     * @return DataFields
     */
    public function addDataFieldMetum(\ODR\AdminBundle\Entity\DataFieldsMeta $dataFieldMeta)
    {
        $this->dataFieldMeta[] = $dataFieldMeta;

        return $this;
    }

    /**
     * Remove dataFieldMeta
     *
     * @param \ODR\AdminBundle\Entity\DataFieldsMeta $dataFieldMeta
     */
    public function removeDataFieldMetum(\ODR\AdminBundle\Entity\DataFieldsMeta $dataFieldMeta)
    {
        $this->dataFieldMeta->removeElement($dataFieldMeta);
    }

    /**
     * Get dataFieldMeta
     *
     * @return \ODR\AdminBundle\Entity\DataFieldsMeta
     */
    public function getDataFieldMeta()
    {
        return $this->dataFieldMeta->first();
    }

    /**
     * Set fieldType
     * @deprecated
     *
     * @param \ODR\AdminBundle\Entity\FieldType $fieldType
     * @return DataFields
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
        return $this->getDataFieldMeta()->getFieldType();
    }

    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return DataFields
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
     * Set renderPlugin
     * @deprecated
     *
     * @param \ODR\AdminBundle\Entity\RenderPlugin $renderPlugin
     * @return DataFields
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
        return $this->getDataFieldMeta()->getRenderPlugin();
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return DataFields
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
     * @return DataFields
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
     * @return DataFields
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
    // TODO - delete these following functions
    /**
     * Get fieldName original
     *
     * @return string
     */
    public function getFieldNameOriginal()
    {
        return $this->fieldName;
    }

    /**
     * Get description original
     *
     * @return string
     */
    public function getDescriptionOriginal()
    {
        return $this->description;
    }

    /**
     * Get xml_fieldName original
     *
     * @return string
     */
    public function getXmlFieldNameOriginal()
    {
        return $this->xml_fieldName;
    }

    /**
     * Get markdownText original
     *
     * @return string
     */
    public function getMarkdownTextOriginal()
    {
        return $this->markdownText;
    }

    /**
     * Get regexValidator original
     *
     * @return string
     */
    public function getRegexValidatorOriginal()
    {
        return $this->regexValidator;
    }

    /**
     * Get phpValidator original
     *
     * @return string
     */
    public function getPhpValidatorOriginal()
    {
        return $this->phpValidator;
    }

    /**
     * Get required original
     *
     * @return boolean
     */
    public function getRequiredOriginal()
    {
        return $this->required;
    }

    /**
     * Get is_unique original
     *
     * @return boolean
     */
    public function getIsUniqueOriginal()
    {
        return $this->is_unique;
    }

    /**
     * Get allow_multiple_uploads original
     *
     * @return boolean
     */
    public function getAllowMultipleUploadsOriginal()
    {
        return $this->allow_multiple_uploads;
    }

    /**
     * Get shorten_filename original
     *
     * @return boolean
     */
    public function getShortenFilenameOriginal()
    {
        return $this->shorten_filename;
    }

    /**
     * Get displayOrder original
     *
     * @return integer
     */
    public function getDisplayOrderOriginal()
    {
        return $this->displayOrder;
    }

    /**
     * Get children_per_row original
     *
     * @return integer
     */
    public function getChildrenPerRowOriginal()
    {
        return $this->children_per_row;
    }

    /**
     * Get radio_option_name_sort original
     *
     * @return boolean
     */
    public function getRadioOptionNameSortOriginal()
    {
        return $this->radio_option_name_sort;
    }

    /**
     * Get radio_option_display_unselected original
     *
     * @return boolean
     */
    public function getRadioOptionDisplayUnselectedOriginal()
    {
        return $this->radio_option_display_unselected;
    }

    /**
     * Get searchable original
     *
     * @return integer
     */
    public function getSearchableOriginal()
    {
        return $this->searchable;
    }

    /**
     * Get user_only_search original
     *
     * @return boolean
     */
    public function getUserOnlySearchOriginal()
    {
        return $this->user_only_search;
    }

    /**
     * Get fieldType original
     *
     * @return \ODR\AdminBundle\Entity\FieldType
     */
    public function getFieldTypeOriginal()
    {
        return $this->fieldType;
    }

    /**
     * Get renderPlugin original
     *
     * @return \ODR\AdminBundle\Entity\RenderPlugin
     */
    public function getRenderPluginOriginal()
    {
        return $this->renderPlugin;
    }
}
