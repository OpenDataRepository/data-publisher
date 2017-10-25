<?php

/**
 * Open Data Repository Data Publisher
 * Theme Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Theme Entity is automatically generated from
 * ./Resources/config/doctrine/Theme.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Theme
 */
class Theme
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $themeType;

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
    private $themeMeta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeElements;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themePreferences;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $relatedThemes;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $relatedSourceThemes;

    /**
     * @var \ODR\AdminBundle\Entity\Theme
     */
    private $parentTheme;

    /**
     * @var \ODR\AdminBundle\Entity\Theme
     */
    private $sourceTheme;

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
        $this->themeMeta = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themeElements = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themePreferences = new \Doctrine\Common\Collections\ArrayCollection();
        $this->relatedThemes = new \Doctrine\Common\Collections\ArrayCollection();
        $this->relatedSourceThemes = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set themeType
     *
     * @param string $themeType
     * @return Theme
     */
    public function setThemeType($themeType)
    {
        $this->themeType = $themeType;

        return $this;
    }

    /**
     * Get themeType
     *
     * @return string 
     */
    public function getThemeType()
    {
        return $this->themeType;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return Theme
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
     * @return Theme
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
     * @return Theme
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
     * Add themeMeta
     *
     * @param \ODR\AdminBundle\Entity\ThemeMeta $themeMeta
     * @return Theme
     */
    public function addThemeMetum(\ODR\AdminBundle\Entity\ThemeMeta $themeMeta)
    {
        $this->themeMeta[] = $themeMeta;

        return $this;
    }

    /**
     * Remove themeMeta
     *
     * @param \ODR\AdminBundle\Entity\ThemeMeta $themeMeta
     */
    public function removeThemeMetum(\ODR\AdminBundle\Entity\ThemeMeta $themeMeta)
    {
        $this->themeMeta->removeElement($themeMeta);
    }

    /**
     * Get themeMeta
     *
     * @return \ODR\AdminBundle\Entity\ThemeMeta
     */
    public function getThemeMeta()
    {
        return $this->themeMeta->first();
    }

    /**
     * Add themeElements
     *
     * @param \ODR\AdminBundle\Entity\ThemeElement $themeElements
     * @return Theme
     */
    public function addThemeElement(\ODR\AdminBundle\Entity\ThemeElement $themeElements)
    {
        $this->themeElements[] = $themeElements;

        return $this;
    }

    /**
     * Remove themeElements
     *
     * @param \ODR\AdminBundle\Entity\ThemeElement $themeElements
     */
    public function removeThemeElement(\ODR\AdminBundle\Entity\ThemeElement $themeElements)
    {
        $this->themeElements->removeElement($themeElements);
    }

    /**
     * Get themeElements
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getThemeElements()
    {
        return $this->themeElements;
    }

    /**
     * Add themePreferences
     *
     * @param \ODR\AdminBundle\Entity\ThemePreferences $themePreferences
     * @return Theme
     */
    public function addThemePreference(\ODR\AdminBundle\Entity\ThemePreferences $themePreferences)
    {
        $this->themePreferences[] = $themePreferences;

        return $this;
    }

    /**
     * Remove themePreferences
     *
     * @param \ODR\AdminBundle\Entity\ThemePreferences $themePreferences
     */
    public function removeThemePreference(\ODR\AdminBundle\Entity\ThemePreferences $themePreferences)
    {
        $this->themePreferences->removeElement($themePreferences);
    }

    /**
     * Get themePreferences
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getThemePreferences()
    {
        return $this->themePreferences;
    }

    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return Theme
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
     * Add relatedTheme
     *
     * @param \ODR\AdminBundle\Entity\Theme $relatedTheme
     *
     * @return Theme
     */
    public function addRelatedTheme(\ODR\AdminBundle\Entity\Theme $relatedTheme)
    {
        $this->relatedThemes[] = $relatedTheme;

        return $this;
    }

    /**
     * Remove relatedTheme
     *
     * @param \ODR\AdminBundle\Entity\Theme $relatedTheme
     */
    public function removeRelatedTheme(\ODR\AdminBundle\Entity\Theme $relatedTheme)
    {
        $this->relatedThemes->removeElement($relatedTheme);
    }

    /**
     * Get relatedThemes
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRelatedThemes()
    {
        return $this->relatedThemes;
    }

    /**
     * Add relatedSourceTheme
     *
     * @param \ODR\AdminBundle\Entity\Theme $relatedSourceTheme
     *
     * @return Theme
     */
    public function addRelatedSourceTheme(\ODR\AdminBundle\Entity\Theme $relatedSourceTheme)
    {
        $this->relatedSourceThemes[] = $relatedSourceTheme;

        return $this;
    }

    /**
     * Remove relatedSourceTheme
     *
     * @param \ODR\AdminBundle\Entity\Theme $relatedSourceTheme
     */
    public function removeRelatedSourceTheme(\ODR\AdminBundle\Entity\Theme $relatedSourceTheme)
    {
        $this->relatedSourceThemes->removeElement($relatedSourceTheme);
    }

    /**
     * Get relatedSourceThemes
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRelatedSourceThemes()
    {
        return $this->relatedSourceThemes;
    }

    /**
     * Set parentTheme
     *
     * @param \ODR\AdminBundle\Entity\Theme $parentTheme
     *
     * @return Theme
     */
    public function setParentTheme(\ODR\AdminBundle\Entity\Theme $parentTheme = null)
    {
        $this->parentTheme = $parentTheme;

        return $this;
    }

    /**
     * Get parentTheme
     *
     * @return \ODR\AdminBundle\Entity\Theme
     */
    public function getParentTheme()
    {
        return $this->parentTheme;
    }

    /**
     * Set sourceTheme
     *
     * @param \ODR\AdminBundle\Entity\Theme $sourceTheme
     *
     * @return Theme
     */
    public function setSourceTheme(\ODR\AdminBundle\Entity\Theme $sourceTheme = null)
    {
        $this->sourceTheme = $sourceTheme;

        return $this;
    }

    /**
     * Get sourceTheme
     *
     * @return \ODR\AdminBundle\Entity\Theme
     */
    public function getSourceTheme()
    {
        return $this->sourceTheme;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return Theme
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
     * @return Theme
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
     * @return Theme
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
     * Get templateName
     *
     * @return string
     */
    public function getTemplateName()
    {
        return $this->getThemeMeta()->getTemplateName();
    }

    /**
     * Get templateDescription
     *
     * @return string
     */
    public function getTemplateDescription()
    {
        return $this->getThemeMeta()->getTemplateDescription();
    }

    /**
     * Get isDefault
     *
     * @return boolean
     */
    public function isDefault()
    {
        return $this->getThemeMeta()->getIsDefault();
    }

    /**
     * Get displayOrder
     *
     * @return int
     */
    public function getDisplayOrder()
    {
        return $this->getThemeMeta()->getDisplayOrder();
    }

    /**
     * Get shared
     *
     * @return bool
     */
    public function isShared()
    {
        return $this->getThemeMeta()->getShared();
    }

    /**
     * Get sourceSyncCheck
     *
     * @return \DateTime
     */
    public function getSourceSyncCheck()
    {
        return $this->getThemeMeta()->getSourceSyncCheck();
    }
}
