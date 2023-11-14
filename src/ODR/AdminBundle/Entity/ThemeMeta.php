<?php

/**
 * Open Data Repository Data Publisher
 * ThemeMeta Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The ThemeMeta Entity is responsible for storing the properties
 * of the Theme Entity that are subject to change, and is
 * automatically generated from ./Resources/config/doctrine/ThemeMeta.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ThemeMeta
 */
class ThemeMeta
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $templateName;

    /**
     * @var string
     */
    private $templateDescription;

    /**
     * @var int
     */
    private $defaultFor;

    /**
     * @var integer
     */
    private $displayOrder;

    /**
     * @var boolean
     */
    private $shared;

    /**
     * @var integer
     */
    private $sourceSyncVersion;

    /**
     * @var boolean
     */
    private $isTableTheme;

    /**
     * @var bool
     */
    private $displaysAllResults;

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
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set templateName
     *
     * @param string $templateName
     * @return ThemeMeta
     */
    public function setTemplateName($templateName)
    {
        $this->templateName = $templateName;

        return $this;
    }

    /**
     * Get templateName
     *
     * @return string 
     */
    public function getTemplateName()
    {
        return $this->templateName;
    }

    /**
     * Set templateDescription
     *
     * @param string $templateDescription
     * @return ThemeMeta
     */
    public function setTemplateDescription($templateDescription)
    {
        $this->templateDescription = $templateDescription;

        return $this;
    }

    /**
     * Get templateDescription
     *
     * @return string 
     */
    public function getTemplateDescription()
    {
        return $this->templateDescription;
    }

    /**
     * Set defaultFor.
     *
     * @param int $defaultFor
     *
     * @return ThemeMeta
     */
    public function setDefaultFor($defaultFor = null)
    {
        $this->defaultFor = $defaultFor;

        return $this;
    }

    /**
     * Get defaultFor.
     *
     * @return int
     */
    public function getDefaultFor()
    {
        return $this->defaultFor;
    }

    /**
     * Set displayOrder
     *
     * @param integer $displayOrder
     *
     * @return ThemeMeta
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
     * Set shared
     *
     * @param boolean $shared
     *
     * @return ThemeMeta
     */
    public function setShared($shared)
    {
        $this->shared = $shared;

        return $this;
    }

    /**
     * Get shared
     *
     * @return boolean
     */
    public function getShared()
    {
        return $this->shared;
    }

    /**
     * Set sourceSyncVersion
     *
     * @param integer $sourceSyncVersion
     *
     * @return ThemeMeta
     */
    public function setSourceSyncVersion($sourceSyncVersion)
    {
        $this->sourceSyncVersion = $sourceSyncVersion;

        return $this;
    }

    /**
     * Get sourceSyncVersion
     *
     * @return integer
     */
    public function getSourceSyncVersion()
    {
        return $this->sourceSyncVersion;
    }

    /**
     * Set isTableTheme
     *
     * @param boolean $isTableTheme
     *
     * @return ThemeMeta
     */
    public function setIsTableTheme($isTableTheme)
    {
        $this->isTableTheme = $isTableTheme;

        return $this;
    }

    /**
     * Get isTableTheme
     *
     * @return boolean
     */
    public function getIsTableTheme()
    {
        return $this->isTableTheme;
    }

    /**
     * Set displaysAllResults.
     *
     * @param bool $displaysAllResults
     *
     * @return ThemeMeta
     */
    public function setDisplaysAllResults($displaysAllResults)
    {
        $this->displaysAllResults = $displaysAllResults;

        return $this;
    }

    /**
     * Get displaysAllResults.
     *
     * @return bool
     */
    public function getDisplaysAllResults()
    {
        return $this->displaysAllResults;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return ThemeMeta
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
     * @return ThemeMeta
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
     * @return ThemeMeta
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
     * Set theme
     *
     * @param \ODR\AdminBundle\Entity\Theme $theme
     * @return ThemeMeta
     */
    public function setTheme(\ODR\AdminBundle\Entity\Theme $theme = null)
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
     * @return ThemeMeta
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
     * @return ThemeMeta
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
