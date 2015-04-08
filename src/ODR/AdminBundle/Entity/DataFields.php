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
* This is also a function to convert the Datafield's name into 
* an XML-friendly format.
*/

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ODR\AdminBundle\Entity\DataFields
 */
class DataFields
{
    /**
     * @var integer $id
     */
    private $id;

    /**
     * @var string $fieldName
     */
    private $fieldName;

    /**
     * @var string $description
     */
    private $description;

    /**
     * @var string $regexValidator
     */
    private $regexValidator;

    /**
     * @var string $phpValidator
     */
    private $phpValidator;

    /**
     * @var boolean $required
     */
    private $required;

    /**
     * @var ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var ODR\AdminBundle\Entity\FieldType
     */
    private $fieldType;

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
        return $this->fieldName;
    }

    /**
     * Set description
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
        return $this->description;
    }

    /**
     * Set regexValidator
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
        return $this->regexValidator;
    }

    /**
     * Set phpValidator
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
        return $this->phpValidator;
    }

    /**
     * Set required
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
        return $this->required;
    }

    /**
     * Set dataType
     *
     * @param ODR\AdminBundle\Entity\DataType $dataType
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
     * @return ODR\AdminBundle\Entity\DataType 
     */
    public function getDataType()
    {
        return $this->dataType;
    }

    /**
     * Set fieldType
     *
     * @param ODR\AdminBundle\Entity\FieldType $fieldType
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
     * @return ODR\AdminBundle\Entity\FieldType 
     */
    public function getFieldType()
    {
        return $this->fieldType;
    }

    /**
     * @var \Doctrine\Common\Collections\ArrayCollection
     */
    private $themeDataField;


    /**
     * Add themeDataField
     *
     * @param ODR\AdminBundle\Entity\ThemeDataField $themeDataField
     * @return DataFields
     */
    public function addThemeDataField(\ODR\AdminBundle\Entity\ThemeDataField $themeDataField)
    {
        $this->themeDataField[] = $themeDataField;
    
        return $this;
    }

    /**
     * Remove themeDataField
     *
     * @param ODR\AdminBundle\Entity\ThemeDataField $themeDataField
     */
    public function removeThemeDataField(\ODR\AdminBundle\Entity\ThemeDataField $themeDataField)
    {
        $this->themeDataField->removeElement($themeDataField);
    }

    /**
     * Get themeDataField
     *
     * @return Doctrine\Common\Collections\Collection 
     */
    public function getThemeDataField()
    {
        return $this->themeDataField;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setRenderPlugin("default");
        $this->themeDataField = new \Doctrine\Common\Collections\ArrayCollection();
    }
    
    /**
     * @var integer $searchable
     */
    private $searchable;

    /**
     * Set searchable
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
        return $this->searchable;
    }

    /**
     * @var \DateTime
     */
    private $deletedAt;

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
     * Set updated
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
     * Set updatedBy
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
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User 
     */
    public function getUpdatedBy()
    {
        return $this->updatedBy;
    }
    /**
     * @var string
     */
    private $meta;


    /**
     * Set meta
     *
     * @param string $meta
     * @return DataFields
     */
    public function setMeta($meta)
    {
        $this->meta = $meta;
    
        return $this;
    }

    /**
     * Get meta
     *
     * @return string 
     */
    public function getMeta()
    {
        return $this->meta;
    }
    /**
     * @var string
     */
    private $renderPlugin;


    /**
     * Set renderPlugin
     *
     * @param string $renderPlugin
     * @return DataFields
     */
    public function setRenderPlugin($renderPlugin)
    {
        $this->renderPlugin = $renderPlugin;
    
        return $this;
    }

    /**
     * Get renderPlugin
     *
     * @return string 
     */
    public function getRenderPlugin()
    {
        return $this->renderPlugin;
    }

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $radioOptions;


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
        return $this->radioOptions;
    }

    /**
     * Get XMLFieldName
     *
     * @return string
     */
    public function getXMLFieldName()
    {
        $search = array(" ", "\'", "\"", "<", ">", "&", "?", "(", ")");
        $replacements = array("_", "", "", "&lt;", "&gt;", "&amp;", "", "", "");

        return str_replace($search, $replacements, $this->fieldName);
    }

    /**
     * @var boolean
     */
    private $allow_multiple_uploads;


    /**
     * Set allow_multiple_uploads
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
        return $this->allow_multiple_uploads;
    }
    /**
     * @var integer
     */
    private $displayOrder;

    /**
     * @var boolean
     */
    private $shorten_filename;


    /**
     * Set displayOrder
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
     *
     * @return integer 
     */
    public function getDisplayOrder()
    {
        return $this->displayOrder;
    }

    /**
     * Set shorten_filename
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
        return $this->shorten_filename;
    }

    /**
     * @var integer
     */
    private $user_only_search;


    /**
     * Set user_only_search
     *
     * @param integer $userOnlySearch
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
     * @return integer 
     */
    public function getUserOnlySearch()
    {
        return $this->user_only_search;
    }

    /**
     * @var integer
     */
    private $children_per_row;


    /**
     * Set children_per_row
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
        return $this->children_per_row;
    }
    /**
     * @var boolean
     */
    private $radio_option_name_sort;


    /**
     * Set radio_option_name_sort
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
        return $this->radio_option_name_sort;
    }
    /**
     * @var string
     */
    private $markdownText;


    /**
     * Set markdownText
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
        return $this->markdownText;
    }
    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataRecordFields;


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
}
