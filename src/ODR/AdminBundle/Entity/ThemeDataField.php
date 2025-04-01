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
 * ThemeDataField
 */
class ThemeDataField
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var integer
     */
    private $displayOrder;

    /**
     * @var integer
     */
    private $hidden;

    /**
     * @var bool
     */
    private $hideHeader;

    /**
     * @var bool
     */
    private $useIconInTables;

    /**
     * @var string
     */
    private $cssWidthMed;

    /**
     * @var string
     */
    private $cssWidthXL;

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
     * @var \ODR\AdminBundle\Entity\ThemeElement
     */
    private $themeElement;

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
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set displayOrder
     *
     * @param integer $displayOrder
     * @return ThemeDataField
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
     * Set hidden
     *
     * @param integer $hidden
     *
     * @return ThemeDataField
     */
    public function setHidden($hidden)
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Get hidden
     *
     * @return integer
     */
    public function getHidden()
    {
        return $this->hidden;
    }

    /**
     * Set hideHeader.
     *
     * @param bool $hideHeader
     *
     * @return ThemeDataField
     */
    public function setHideHeader($hideHeader)
    {
        $this->hideHeader = $hideHeader;

        return $this;
    }

    /**
     * Get hideHeader.
     *
     * @return bool
     */
    public function getHideHeader()
    {
        return $this->hideHeader;
    }

    /**
     * Set useIconInTables.
     *
     * @param bool $useIconInTables
     *
     * @return ThemeDataField
     */
    public function setUseIconInTables($useIconInTables)
    {
        $this->useIconInTables = $useIconInTables;

        return $this;
    }

    /**
     * Get useIconInTables.
     *
     * @return bool
     */
    public function getUseIconInTables()
    {
        return $this->useIconInTables;
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
     * Set dataField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataField
     * @return ThemeDataField
     */
    public function setDataField(\ODR\AdminBundle\Entity\DataFields $dataField)
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
     * Set themeElement
     *
     * @param \ODR\AdminBundle\Entity\ThemeElement $themeElement
     * @return ThemeDataField
     */
    public function setThemeElement(\ODR\AdminBundle\Entity\ThemeElement $themeElement = null)
    {
        $this->themeElement = $themeElement;

        return $this;
    }

    /**
     * Get themeElement
     *
     * @return \ODR\AdminBundle\Entity\ThemeElement 
     */
    public function getThemeElement()
    {
        return $this->themeElement;
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
     * Set deletedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $deletedBy
     * @return ThemeDataField
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
}
