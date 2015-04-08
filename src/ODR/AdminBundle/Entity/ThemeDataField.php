<?php

/**
* Open Data Repository Data Publisher
* ThemeDataField Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The ThemeDataField Entity is automatically generated from 
* ./Resources/config/doctrine/ThemeDataField.orm.yml
*
*/


namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ODR\AdminBundle\Entity\ThemeDataField
 */
class ThemeDataField
{
    /**
     * @var integer $id
     */
    private $id;

    /**
     * @var string $templateType
     */
    private $templateType;

    /**
     * @var ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var ODR\AdminBundle\Entity\Theme
     */
    private $theme;


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
     * @return ThemeDataField
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
     * Set dataField
     *
     * @param ODR\AdminBundle\Entity\DataFields $dataField
     * @return ThemeDataField
     */
    public function setDataField(\ODR\AdminBundle\Entity\DataFields $dataField = null)
    {
        $this->dataField = $dataField;
    
        return $this;
    }

    /**
     * Get dataField
     *
     * @return ODR\AdminBundle\Entity\DataFields 
     */
    public function getDataField()
    {
        return $this->dataField;
    }

    /**
     * Set theme
     *
     * @param ODR\AdminBundle\Entity\Theme $theme
     * @return ThemeDataField
     */
    public function setTheme(\ODR\AdminBundle\Entity\Theme $theme = null)
    {
        $this->theme = $theme;
    
        return $this;
    }

    /**
     * Get theme
     *
     * @return ODR\AdminBundle\Entity\Theme 
     */
    public function getTheme()
    {
        return $this->theme;
    }
    /**
     * @var string $css
     */
    private $css;


    /**
     * Set css
     *
     * @param string $css
     * @return ThemeDataField
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
     * @var ODR\AdminBundle\Entity\DataFields
     */
    private $dataFields;


    /**
     * Set dataFields
     *
     * @param ODR\AdminBundle\Entity\DataFields $dataFields
     * @return ThemeDataField
     */
    public function setDataFields(\ODR\AdminBundle\Entity\DataFields $dataFields = null)
    {
        $this->dataFields = $dataFields;
    
        return $this;
    }

    /**
     * Get dataFields
     *
     * @return ODR\AdminBundle\Entity\DataFields 
     */
    public function getDataFields()
    {
        return $this->dataFields;
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
     * @return ThemeDataField
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
     * @return ThemeDataField
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
     * @return ThemeDataField
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
     * @return ThemeDataField
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
     * @return ThemeDataField
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
     * @var boolean
     */
    private $active;

    /**
     * Set active
     *
     * @param boolean $active
     * @return ThemeDataField
     */
    public function setActive($active)
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Get active
     *
     * @return boolean 
     */
    public function getActive()
    {
        return $this->active;
    }
    /**
     * @var string
     */
    private $cssWidthXL;

    /**
     * @var string
     */
    private $cssWidthMed;


    /**
     * Set cssWidthXL
     *
     * @param string $cssWidthXL
     * @return ThemeDataField
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
     * @return ThemeDataField
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
