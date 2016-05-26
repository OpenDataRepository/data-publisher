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
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
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
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $deletedAt;

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
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeDataFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeDataType;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeElementMeta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeElementField;

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
    private $deletedBy;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->themeDataFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themeDataType = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themeElementMeta = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Get publicDate
     *
     * @return \DateTime
     */
    public function getPublicDate()
    {
        return $this->getThemeElementMeta()->getPublicDate();
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
     * Set displayOrder
     * @deprecated
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
        return $this->getThemeElementMeta()->getDisplayOrder();
    }

    /**
     * Set displayInResults
     * @deprecated
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
     * @deprecated
     *
     * @return boolean 
     */
    public function getDisplayInResults()
    {
        return $this->displayInResults;
    }

    /**
     * Set cssWidthXL
     * @deprecated
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
        return $this->getThemeElementMeta()->getCssWidthXL();
    }

    /**
     * Set cssWidthMed
     * @deprecated
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
        return $this->getThemeElementMeta()->getCssWidthMed();
    }

    /**
     * Set updated
     * @deprecated
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
     * @deprecated
     *
     * @return \DateTime 
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Add themeDataFields
     *
     * @param \ODR\AdminBundle\Entity\ThemeDataField $themeDataFields
     * @return ThemeElement
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
//        return $this->themeDataFields;

        // Adapted from http://stackoverflow.com/a/16707694
        $iterator = $this->themeDataFields->getIterator();
        $iterator->uasort(function ($a, $b) {
            // Sort by display order first if possible
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
     * Add themeDataType
     *
     * @param \ODR\AdminBundle\Entity\ThemeDataType $themeDataType
     * @return ThemeElement
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
     * Add themeElementMeta
     *
     * @param \ODR\AdminBundle\Entity\ThemeElementMeta $themeElementMeta
     * @return ThemeElement
     */
    public function addThemeElementMetum(\ODR\AdminBundle\Entity\ThemeElementMeta $themeElementMeta)
    {
        $this->themeElementMeta[] = $themeElementMeta;

        return $this;
    }

    /**
     * Remove themeElementMeta
     *
     * @param \ODR\AdminBundle\Entity\ThemeElementMeta $themeElementMeta
     */
    public function removeThemeElementMetum(\ODR\AdminBundle\Entity\ThemeElementMeta $themeElementMeta)
    {
        $this->themeElementMeta->removeElement($themeElementMeta);
    }

    /**
     * Get themeElementMeta
     *
     * @return \ODR\AdminBundle\Entity\ThemeElementMeta
     */
    public function getThemeElementMeta()
    {
        return $this->themeElementMeta->first();
    }

    /**
     * Add themeElementField
     * @deprecated
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
     * @deprecated
     *
     * @param \ODR\AdminBundle\Entity\ThemeElementField $themeElementField
     */
    public function removeThemeElementField(\ODR\AdminBundle\Entity\ThemeElementField $themeElementField)
    {
        $this->themeElementField->removeElement($themeElementField);
    }

    /**
     * Get themeElementField
     * @deprecated 
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getThemeElementField()
    {
        return $this->themeElementField;
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
     * Set deletedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $deletedBy
     * @return ThemeElement
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
     * Set dataType
     * @deprecated 
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
     * @deprecated
     *
     * @return \ODR\AdminBundle\Entity\DataType 
     */
    public function getDataType()
    {
        return $this->dataType;
    }

    /**
     * Set updatedBy
     * @deprecated
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
     * @deprecated
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User 
     */
    public function getUpdatedBy()
    {
        return $this->updatedBy;
    }

    /**
     * Is public
     * 
     * @return bool
     */
    public function isPublic()
    {
        if ($this->getPublicDate()->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
            return false;
        else
            return true;
    }


    // ----------------------------------------
    // TODO - delete these following functions

    /**
     * Get display_order original
     *
     * @return string
     */
    public function getDisplayOrderOriginal()
    {
        return $this->displayOrder;
    }

    /**
     * Get display_in_results original
     *
     * @return string
     */
    public function getDisplayInResultsOriginal()
    {
        return $this->displayInResults;
    }

    /**
     * Get cssWidthXL original
     *
     * @return string
     */
    public function getCssWidthXLOriginal()
    {
        return $this->cssWidthXL;
    }

    /**
     * Get cssWidthMed original
     *
     * @return string
     */
    public function getCssWidthMedOriginal()
    {
        return $this->cssWidthMed;
    }
}
