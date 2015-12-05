<?php

/**
* Open Data Repository Data Publisher
* ThemeElement Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The ThemeElement Entity is automatically generated from 
* ./Resources/config/doctrine/ThemeElement.orm.yml
*/

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ThemeElement
 */
class ThemeElement
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $templateType;

    /**
     * @var string
     */
    private $elementType;

    /**
     * @var string
     */
    private $css;

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
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeElementField;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var \ODR\AdminBundle\Entity\Theme
     */
    private $theme;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;

    /**
     * @var integer
     */
    private $displayOrder;

    /**
     * @var boolean
     */
    private $displayInResults;

    /**
     * @var string
     */
    private $cssWidthXL;

    /**
     * @var string
     */
    private $cssWidthMed;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->themeElementField = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set templateType
     *
     * @param string $templateType
     * @return ThemeElement
     */
    public function setTemplateType($templateType)
    {
        $this->templateType = $templateType;
    
        return $this;
    }

    /**
     * Get templateType
     *
     * @return string 
     */
    public function getTemplateType()
    {
        return $this->templateType;
    }

    /**
     * Set elementType
     *
     * @param string $elementType
     * @return ThemeElement
     */
    public function setElementType($elementType)
    {
        $this->elementType = $elementType;
    
        return $this;
    }

    /**
     * Get elementType
     *
     * @return string 
     */
    public function getElementType()
    {
        return $this->elementType;
    }

    /**
     * Set css
     *
     * @param string $css
     * @return ThemeElement
     */
    public function setCss($css)
    {
        $this->css = $css;
    
        return $this;
    }

    /**
     * Get css
     *
     * @return string 
     */
    public function getCss()
    {
        return $this->css;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return ThemeElement
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
     * @return ThemeElement
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
     * @return ThemeElement
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
     * Add themeElementField
     *
     * @param \ODR\AdminBundle\Entity\ThemeElementField $themeElementField
     * @return ThemeElement
     */
    public function addThemeElementField(\ODR\AdminBundle\Entity\ThemeElementField $themeElementField)
    {
        $this->themeElementField[] = $themeElementField;
    
        return $this;
    }

    /**
     * Remove themeElementField
     *
     * @param \ODR\AdminBundle\Entity\ThemeElementField $themeElementField
     */
    public function removeThemeElementField(\ODR\AdminBundle\Entity\ThemeElementField $themeElementField)
    {
        $this->themeElementField->removeElement($themeElementField);
    }

    /**
     * Get themeElementField
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getThemeElementField()
    {
        return $this->themeElementField;
    }

    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return ThemeElement
     */
    public function setDataType(\ODR\AdminBundle\Entity\DataType $dataType)
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
     * Set theme
     *
     * @param \ODR\AdminBundle\Entity\Theme $theme
     * @return ThemeElement
     */
    public function setTheme(\ODR\AdminBundle\Entity\Theme $theme)
    {
        $this->theme = $theme;
    
        return $this;
    }

    /**
     * Get theme
     *
     * @return \ODR\AdminBundle\Entity\Theme 
     */
    public function getTheme()
    {
        return $this->theme;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return ThemeElement
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
     * @return ThemeElement
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
     * Set displayOrder
     *
     * @param integer $displayOrder
     * @return ThemeElement
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
     * Set displayInResults
     *
     * @param boolean $displayInResults
     * @return ThemeElement
     */
    public function setDisplayInResults($displayInResults)
    {
        $this->displayInResults = $displayInResults;
    
        return $this;
    }

    /**
     * Get displayInResults
     *
     * @return boolean 
     */
    public function getDisplayInResults()
    {
        return $this->displayInResults;
    }

    /**
     * Set cssWidthXL
     *
     * @param string $cssWidthXL
     * @return ThemeElement
     */
    public function setCssWidthXL($cssWidthXL)
    {
        $this->cssWidthXL = $cssWidthXL;

        return $this;
    }

    /**
     * Get cssWidthXL
     *
     * @return string 
     */
    public function getCssWidthXL()
    {
        return $this->cssWidthXL;
    }

    /**
     * Set cssWidthMed
     *
     * @param string $cssWidthMed
     * @return ThemeElement
     */
    public function setCssWidthMed($cssWidthMed)
    {
        $this->cssWidthMed = $cssWidthMed;

        return $this;
    }

    /**
     * Get cssWidthMed
     *
     * @return string 
     */
    public function getCssWidthMed()
    {
        return $this->cssWidthMed;
    }
}
