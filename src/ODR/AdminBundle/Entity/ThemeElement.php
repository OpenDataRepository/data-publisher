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
     * Constructor
     */
    public function __construct()
    {
        $this->themeDataFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themeDataType = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themeElementMeta = new \Doctrine\Common\Collections\ArrayCollection();
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
            /** @var ThemeDataField $a */
            /** @var ThemeDataField $b */
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
     * Get displayOrder
     *
     * @return integer
     */
    public function getDisplayOrder()
    {
        return $this->getThemeElementMeta()->getDisplayOrder();
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
     * Get cssWidthMed
     *
     * @return string
     */
    public function getCssWidthMed()
    {
        return $this->getThemeElementMeta()->getCssWidthMed();
    }
}
