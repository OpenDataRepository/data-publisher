<?php

/**
 * Open Data Repository Data Publisher
 * ThemeElementMeta Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The ThemeElementMeta Entity is responsible for storing the properties
 * of the ThemeElement Entity that are subject to change, and is
 * automatically generated from ./Resources/config/doctrine/ThemeElementMeta.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ThemeElementMeta
 */
class ThemeElementMeta
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
     * @return ThemeElementMeta
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
     * Set cssWidthMed
     *
     * @param string $cssWidthMed
     * @return ThemeElementMeta
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
     * @return ThemeElementMeta
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
     * @return ThemeElementMeta
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
     * @return ThemeElementMeta
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
     * @return ThemeElementMeta
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
     * Set themeElement
     *
     * @param \ODR\AdminBundle\Entity\ThemeElement $themeElement
     * @return ThemeElementMeta
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
     * @return ThemeElementMeta
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
     * @return ThemeElementMeta
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
