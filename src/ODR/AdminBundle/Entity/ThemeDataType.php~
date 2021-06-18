<?php

/**
 * Open Data Repository Data Publisher
 * ThemeDataType Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The ThemeDataType Entity is automatically generated from
 * ./Resources/config/doctrine/ThemeDataType.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ThemeDataType
 */
class ThemeDataType
{
    // Child/linked datatype is rendered with each child/linked datarecord preceeded by a header...
    //  the headers are always visible, but only one datarecord is visible at a time depending on
    //  which header was clicked last
    const ACCORDION_HEADER = 0;
    // Child/linked datatype is rendered with a prepended header, followed by a row of buttons to
    //  select the single visible child/linked datarecord
    const TABBED_HEADER = 1;
    // Child/linked datatype is rendered with a prepended header, containing a <select> element to
    //  select the single visible child/linked datarecord
    const DROPDOWN_HEADER = 2;
    // Child/linked datatype is rendered with a prepended header, and all child/linked datarecords
    //  are always visible
    const LIST_HEADER = 3;
    // Child/linked datatype is rendered with a prepended header, followed by a row of buttons to
    //  select the desired child/linked datarecord...
    const NO_HEADER = 4;


    /**
     * @var integer
     */
    private $id;

    /**
     * @var integer
     */
    private $display_type;

    /**
     * @deprecated
     * @var integer
     */
    private $hidden;

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
    private $childTheme;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

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
     * Set display_type
     *
     * @param integer $displayType
     * @return ThemeDataType
     */
    public function setDisplayType($displayType)
    {
        $this->display_type = $displayType;

        return $this;
    }

    /**
     * Get display_type
     *
     * @return integer 
     */
    public function getDisplayType()
    {
        return $this->display_type;
    }

    /**
     * @deprecated
     * Set hidden
     *
     * @param integer $hidden
     *
     * @return ThemeDataType
     */
    public function setHidden($hidden)
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * @deprecated
     * Get hidden
     *
     * @return integer
     */
    public function getHidden()
    {
        return $this->hidden;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return ThemeDataType
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
     * @return ThemeDataType
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
     * @return ThemeDataType
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
     * Set childTheme
     *
     * @param \ODR\AdminBundle\Entity\Theme $childTheme
     *
     * @return ThemeDataType
     */
    public function setChildTheme(\ODR\AdminBundle\Entity\Theme $childTheme = null)
    {
        $this->childTheme = $childTheme;

        return $this;
    }

    /**
     * Get childTheme
     *
     * @return \ODR\AdminBundle\Entity\Theme
     */
    public function getChildTheme()
    {
        return $this->childTheme;
    }

    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return ThemeDataType
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
     * Set themeElement
     *
     * @param \ODR\AdminBundle\Entity\ThemeElement $themeElement
     * @return ThemeDataType
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
     * @return ThemeDataType
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
     * @return ThemeDataType
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
     * @return ThemeDataType
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
