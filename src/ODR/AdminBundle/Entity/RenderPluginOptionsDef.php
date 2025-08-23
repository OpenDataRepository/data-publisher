<?php

/**
 * Open Data Repository Data Publisher
 * RenderPluginOptionsDef Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RenderPluginOptionsDef Entity is automatically generated from
 * ./Resources/config/doctrine/RenderPluginOptionsDef.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

/**
 * TODO - rename to RenderPluginOptions
 */
class RenderPluginOptionsDef
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $displayName;

    /**
     * @var string|null
     */
    private $defaultValue;

    /**
     * @var string|null
     */
    private $choices;

    /**
     * @var string|null
     */
    private $description;

    /**
     * @var int
     */
    private $display_order;

    /**
     * @var bool
     */
    private $uses_custom_render;

    /**
     * @var bool
     */
    private $uses_layout_settings;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \DateTime|null
     */
    private $deletedAt;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginOptionsMap;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginThemeOptionsMap;

    /**
     * @var \ODR\AdminBundle\Entity\RenderPlugin
     */
    private $renderPlugin;

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
        $this->renderPluginOptionsMap = new \Doctrine\Common\Collections\ArrayCollection();
        $this->renderPluginThemeOptionsMap = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name.
     *
     * @param string $name
     *
     * @return RenderPluginOptionsDef
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set displayName.
     *
     * @param string $displayName
     *
     * @return RenderPluginOptionsDef
     */
    public function setDisplayName($displayName)
    {
        $this->displayName = $displayName;

        return $this;
    }

    /**
     * Get displayName.
     *
     * @return string
     */
    public function getDisplayName()
    {
        return $this->displayName;
    }

    /**
     * Set defaultValue.
     *
     * @param string|null $defaultValue
     *
     * @return RenderPluginOptionsDef
     */
    public function setDefaultValue($defaultValue = null)
    {
        $this->defaultValue = $defaultValue;

        return $this;
    }

    /**
     * Get defaultValue.
     *
     * @return string|null
     */
    public function getDefaultValue()
    {
        return $this->defaultValue;
    }

    /**
     * Set choices.
     *
     * @param string|null $choices
     *
     * @return RenderPluginOptionsDef
     */
    public function setChoices($choices = null)
    {
        $this->choices = $choices;

        return $this;
    }

    /**
     * Get choices.
     *
     * @return string|null
     */
    public function getChoices()
    {
        return $this->choices;
    }

    /**
     * Set description.
     *
     * @param string|null $description
     *
     * @return RenderPluginOptionsDef
     */
    public function setDescription($description = null)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get description.
     *
     * @return string|null
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set displayOrder.
     *
     * @param int $displayOrder
     *
     * @return RenderPluginOptionsDef
     */
    public function setDisplayOrder($displayOrder)
    {
        $this->display_order = $displayOrder;

        return $this;
    }

    /**
     * Get displayOrder.
     *
     * @return int
     */
    public function getDisplayOrder()
    {
        return $this->display_order;
    }

    /**
     * Set usesCustomRender.
     *
     * @param bool $usesCustomRender
     *
     * @return RenderPluginOptionsDef
     */
    public function setUsesCustomRender($usesCustomRender)
    {
        $this->uses_custom_render = $usesCustomRender;

        return $this;
    }

    /**
     * Get usesCustomRender.
     *
     * @return bool
     */
    public function getUsesCustomRender()
    {
        return $this->uses_custom_render;
    }

    /**
     * Set usesLayoutSettings.
     *
     * @param bool $usesLayoutSettings
     *
     * @return RenderPluginOptionsDef
     */
    public function setUsesLayoutSettings($usesLayoutSettings)
    {
        $this->uses_layout_settings = $usesLayoutSettings;

        return $this;
    }

    /**
     * Get usesLayoutSettings.
     *
     * @return bool
     */
    public function getUsesLayoutSettings()
    {
        return $this->uses_layout_settings;
    }

    /**
     * Set created.
     *
     * @param \DateTime $created
     *
     * @return RenderPluginOptionsDef
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created.
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set updated.
     *
     * @param \DateTime $updated
     *
     * @return RenderPluginOptionsDef
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * Get updated.
     *
     * @return \DateTime
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Set deletedAt.
     *
     * @param \DateTime|null $deletedAt
     *
     * @return RenderPluginOptionsDef
     */
    public function setDeletedAt($deletedAt = null)
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    /**
     * Get deletedAt.
     *
     * @return \DateTime|null
     */
    public function getDeletedAt()
    {
        return $this->deletedAt;
    }

    /**
     * Add renderPluginOptionsMap.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginOptionsMap $renderPluginOptionsMap
     *
     * @return RenderPluginOptionsDef
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
     * Add renderPluginThemeOptionsMap.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginThemeOptionsMap $renderPluginThemeOptionsMap
     *
     * @return RenderPluginOptionsDef
     */
    public function addRenderPluginThemeOptionsMap(\ODR\AdminBundle\Entity\RenderPluginThemeOptionsMap $renderPluginThemeOptionsMap)
    {
        $this->renderPluginThemeOptionsMap[] = $renderPluginThemeOptionsMap;

        return $this;
    }

    /**
     * Remove renderPluginThemeOptionsMap.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginThemeOptionsMap $renderPluginThemeOptionsMap
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeRenderPluginThemeOptionsMap(\ODR\AdminBundle\Entity\RenderPluginThemeOptionsMap $renderPluginThemeOptionsMap)
    {
        return $this->renderPluginThemeOptionsMap->removeElement($renderPluginThemeOptionsMap);
    }

    /**
     * Get renderPluginThemeOptionsMap.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRenderPluginThemeOptionsMap()
    {
        return $this->renderPluginThemeOptionsMap;
    }

    /**
     * Set renderPlugin.
     *
     * @param \ODR\AdminBundle\Entity\RenderPlugin|null $renderPlugin
     *
     * @return RenderPluginOptionsDef
     */
    public function setRenderPlugin(\ODR\AdminBundle\Entity\RenderPlugin $renderPlugin = null)
    {
        $this->renderPlugin = $renderPlugin;

        return $this;
    }

    /**
     * Get renderPlugin.
     *
     * @return \ODR\AdminBundle\Entity\RenderPlugin|null
     */
    public function getRenderPlugin()
    {
        return $this->renderPlugin;
    }

    /**
     * Set createdBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $createdBy
     *
     * @return RenderPluginOptionsDef
     */
    public function setCreatedBy(\ODR\OpenRepository\UserBundle\Entity\User $createdBy = null)
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * Get createdBy.
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User|null
     */
    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    /**
     * Set updatedBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $updatedBy
     *
     * @return RenderPluginOptionsDef
     */
    public function setUpdatedBy(\ODR\OpenRepository\UserBundle\Entity\User $updatedBy = null)
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    /**
     * Get updatedBy.
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User|null
     */
    public function getUpdatedBy()
    {
        return $this->updatedBy;
    }
}
