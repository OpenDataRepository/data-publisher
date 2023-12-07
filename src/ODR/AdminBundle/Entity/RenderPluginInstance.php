<?php

/**
 * Open Data Repository Data Publisher
 * RenderPluginInstance Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RenderPluginInstance Entity is automatically generated from
 * ./Resources/config/doctrine/RenderPluginInstance.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;


class RenderPluginInstance
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var boolean
     */
    private $active;

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
    private $renderPluginOptions;    // TODO - get rid of this

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginMap;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginOptionsMap;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeRenderPluginInstance;

    /**
     * @var \ODR\AdminBundle\Entity\RenderPlugin
     */
    private $renderPlugin;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->renderPluginOptions = new \Doctrine\Common\Collections\ArrayCollection();
        $this->renderPluginMap = new \Doctrine\Common\Collections\ArrayCollection();
        $this->renderPluginOptionsMap = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themeRenderPluginInstance = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set active
     *
     * @param boolean $active
     * @return RenderPluginInstance
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
     * Set created
     *
     * @param \DateTime $created
     * @return RenderPluginInstance
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
     * @return RenderPluginInstance
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
     * @return RenderPluginInstance
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
     * @deprecated
     * Add renderPluginOptions
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginOptions $renderPluginOptions
     * @return RenderPluginInstance
     */
    public function addRenderPluginOption(\ODR\AdminBundle\Entity\RenderPluginOptions $renderPluginOptions)
    {
        $this->renderPluginOptions[] = $renderPluginOptions;

        return $this;
    }

    /**
     * @deprecated
     * Remove renderPluginOptions
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginOptions $renderPluginOptions
     */
    public function removeRenderPluginOption(\ODR\AdminBundle\Entity\RenderPluginOptions $renderPluginOptions)
    {
        $this->renderPluginOptions->removeElement($renderPluginOptions);
    }

    /**
     * @deprecated
     * Get renderPluginOptions
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getRenderPluginOptions()
    {
        return $this->renderPluginOptions;
    }

    /**
     * Add renderPluginMap
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginMap $renderPluginMap
     * @return RenderPluginInstance
     */
    public function addRenderPluginMap(\ODR\AdminBundle\Entity\RenderPluginMap $renderPluginMap)
    {
        $this->renderPluginMap[] = $renderPluginMap;

        return $this;
    }

    /**
     * Remove renderPluginMap
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginMap $renderPluginMap
     */
    public function removeRenderPluginMap(\ODR\AdminBundle\Entity\RenderPluginMap $renderPluginMap)
    {
        $this->renderPluginMap->removeElement($renderPluginMap);
    }

    /**
     * Get renderPluginMap
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getRenderPluginMap()
    {
        return $this->renderPluginMap;
    }

    /**
     * Add renderPluginOptionsMap.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginOptionsMap $renderPluginOptionsMap
     *
     * @return RenderPluginInstance
     */
    public function addRenderPluginOptionsMap(\ODR\AdminBundle\Entity\RenderPluginOptionsMap $renderPluginOptionsMap)
    {
        $this->renderPluginOptionsMap[] = $renderPluginOptionsMap;

        return $this;
    }

    /**
     * Remove renderPluginOptionsMap.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginOptionsMap $renderPluginOptionsMap
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeRenderPluginOptionsMap(\ODR\AdminBundle\Entity\RenderPluginOptionsMap $renderPluginOptionsMap)
    {
        return $this->renderPluginOptionsMap->removeElement($renderPluginOptionsMap);
    }

    /**
     * Get renderPluginOptionsMap.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRenderPluginOptionsMap()
    {
        return $this->renderPluginOptionsMap;
    }

    /**
     * Add themeRenderPluginInstance.
     *
     * @param \ODR\AdminBundle\Entity\ThemeRenderPluginInstance $themeRenderPluginInstance
     *
     * @return RenderPluginInstance
     */
    public function addThemeRenderPluginInstance(\ODR\AdminBundle\Entity\ThemeRenderPluginInstance $themeRenderPluginInstance)
    {
        $this->themeRenderPluginInstance[] = $themeRenderPluginInstance;

        return $this;
    }

    /**
     * Remove themeRenderPluginInstance.
     *
     * @param \ODR\AdminBundle\Entity\ThemeRenderPluginInstance $themeRenderPluginInstance
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeThemeRenderPluginInstance(\ODR\AdminBundle\Entity\ThemeRenderPluginInstance $themeRenderPluginInstance)
    {
        return $this->themeRenderPluginInstance->removeElement($themeRenderPluginInstance);
    }

    /**
     * Get themeRenderPluginInstance.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getThemeRenderPluginInstance()
    {
        return $this->themeRenderPluginInstance;
    }

    /**
     * Set renderPlugin
     *
     * @param \ODR\AdminBundle\Entity\RenderPlugin $renderPlugin
     * @return RenderPluginInstance
     */
    public function setRenderPlugin(\ODR\AdminBundle\Entity\RenderPlugin $renderPlugin = null)
    {
        $this->renderPlugin = $renderPlugin;

        return $this;
    }

    /**
     * Get renderPlugin
     *
     * @return \ODR\AdminBundle\Entity\RenderPlugin 
     */
    public function getRenderPlugin()
    {
        return $this->renderPlugin;
    }

    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return RenderPluginInstance
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
     * Set dataField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataField
     * @return RenderPluginInstance
     */
    public function setDataField(\ODR\AdminBundle\Entity\DataFields $dataField = null)
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
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return RenderPluginInstance
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
     * @return RenderPluginInstance
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
