<?php

/**
 * Open Data Repository Data Publisher
 * RenderPlugin Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RenderPlugin Entity is automatically generated from
 * ./Resources/config/doctrine/RenderPlugin.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;


class RenderPlugin
{
    // These are magic numbers to define when twig rendering will call the RenderPlugin

    /*
     * Plugins with this constant are called in *_childtype.html.twig, and therefore can completely
     * override an entire child/linked datatype if they want to, such as the Comment and Reference
     * plugins.  They don't have to, however, and plugins can also selectively replace parts of
     * datafields...such as the AMCSD and IMA plugins...or even do nothing at all.
     */
    const DATATYPE_PLUGIN = 1;

    /*
     * Plugins with this constant are called in *_fieldarea.html.twig, and provides content for
     * themeElements...ODR won't allow any datafields or child/linked datatypes in the affected
     * themeElements.
     */
    const THEME_ELEMENT_PLUGIN = 2;

    /*
     * Plugins with this constant are called in *_datafield.html.twig, and can only override a single
     * datafield...sometimes to add extra functionality, like the Chemistry plugin...but can also
     * just change the displayed value, like the Currency plugin.
     */
    const DATAFIELD_PLUGIN = 3;

    /*
     * Plugins with this constant are also called in *_childtype.html.twig, but they get called prior
     * to regular datatype plugins, and return modified datatype/datarecord/theme arrays instead of
     * HTML.  Additionally, these ignore the "render" parameter...unlike the other types of plugins
     * that completely hijack the HTML structure of the page, it shouldn't really matter how many
     * plugins modify the array structure, or what order they do it in.
     */
    const ARRAY_PLUGIN = 4;

    /*
     * These technically don't "render" anything, but instead completely override the search system
     * for specific datafields.  Ideally, they still respect the search system caching.
     */
    const SEARCH_PLUGIN = 5;


    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $pluginName;

    /**
     * @var string
     */
    private $description;

    /**
     * @var string
     */
    private $category;

    /**
     * @var string
     */
    private $pluginClassName;

    /**
     * @var boolean
     */
    private $active;

    /**
     * @var string
     */
    private $render;

    /**
     * @var boolean
     */
    private $overrideChild;

    /**
     * @var boolean
     */
    private $overrideFields;

    /**
     * @var bool
     */
    private $overrideFieldReload;

    /**
     * @var bool
     */
    private $overrideTableFields;

    /**
     * @var bool
     */
    private $suppressNoFieldsNote;

    /**
     * @var integer
     */
    private $plugin_type;

    /**
     * @var integer
     */
    private $requiredThemeElements;

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
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginInstance;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginEvents;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginOptionsDef;    // TODO - rename to renderPluginOptions

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
        $this->renderPluginInstance = new \Doctrine\Common\Collections\ArrayCollection();
        $this->renderPluginFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->renderPluginEvents = new \Doctrine\Common\Collections\ArrayCollection();
        $this->renderPluginOptionsDef = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set pluginName
     *
     * @param string $pluginName
     * @return RenderPlugin
     */
    public function setPluginName($pluginName)
    {
        $this->pluginName = $pluginName;

        return $this;
    }

    /**
     * Get pluginName
     *
     * @return string 
     */
    public function getPluginName()
    {
        return $this->pluginName;
    }

    /**
     * Set description
     *
     * @param string $description
     * @return RenderPlugin
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get description
     *
     * @return string 
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set category.
     *
     * @param string $category
     *
     * @return RenderPlugin
     */
    public function setCategory($category)
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Get category.
     *
     * @return string
     */
    public function getCategory()
    {
        return $this->category;
    }

    /**
     * Set pluginClassName
     *
     * @param string $pluginClassName
     * @return RenderPlugin
     */
    public function setPluginClassName($pluginClassName)
    {
        $this->pluginClassName = $pluginClassName;

        return $this;
    }

    /**
     * Get pluginClassName
     *
     * @return string 
     */
    public function getPluginClassName()
    {
        return $this->pluginClassName;
    }

    /**
     * Set active
     *
     * @param boolean $active
     * @return RenderPlugin
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
     * Set render.
     *
     * @param string $render
     *
     * @return RenderPlugin
     */
    public function setRender($render)
    {
        $this->render = $render;

        return $this;
    }

    /**
     * Get render.
     *
     * @return string
     */
    public function getRender()
    {
        return $this->render;
    }

    /**
     * Set overrideChild
     *
     * @param boolean $overrideChild
     * @return RenderPlugin
     */
    public function setOverrideChild($overrideChild)
    {
        $this->overrideChild = $overrideChild;

        return $this;
    }

    /**
     * Get overrideChild
     *
     * @return boolean 
     */
    public function getOverrideChild()
    {
        return $this->overrideChild;
    }

    /**
     * Set overrideFields
     *
     * @param boolean $overrideFields
     * @return RenderPlugin
     */
    public function setOverrideFields($overrideFields)
    {
        $this->overrideFields = $overrideFields;

        return $this;
    }

    /**
     * Get overrideFields
     *
     * @return boolean 
     */
    public function getOverrideFields()
    {
        return $this->overrideFields;
    }

    /**
     * Set overrideFieldReload.
     *
     * @param bool $overrideFieldReload
     *
     * @return RenderPlugin
     */
    public function setOverrideFieldReload($overrideFieldReload)
    {
        $this->overrideFieldReload = $overrideFieldReload;

        return $this;
    }

    /**
     * Get overrideFieldReload.
     *
     * @return bool
     */
    public function getOverrideFieldReload()
    {
        return $this->overrideFieldReload;
    }

    /**
     * Set overrideTableFields.
     *
     * @param bool $overrideTableFields
     *
     * @return RenderPlugin
     */
    public function setOverrideTableFields($overrideTableFields)
    {
        $this->overrideTableFields = $overrideTableFields;

        return $this;
    }

    /**
     * Get overrideTableFields.
     *
     * @return bool
     */
    public function getOverrideTableFields()
    {
        return $this->overrideTableFields;
    }

    /**
     * Set suppressNoFieldsNote.
     *
     * @param bool $suppressNoFieldsNote
     *
     * @return RenderPlugin
     */
    public function setSuppressNoFieldsNote($suppressNoFieldsNote)
    {
        $this->suppressNoFieldsNote = $suppressNoFieldsNote;

        return $this;
    }

    /**
     * Get suppressNoFieldsNote.
     *
     * @return bool
     */
    public function getSuppressNoFieldsNote()
    {
        return $this->suppressNoFieldsNote;
    }

    /**
     * Set plugin_type
     *
     * @param integer $pluginType
     * @return RenderPlugin
     */
    public function setPluginType($pluginType)
    {
        $this->plugin_type = $pluginType;

        return $this;
    }

    /**
     * Get plugin_type
     *
     * @return integer 
     */
    public function getPluginType()
    {
        return $this->plugin_type;
    }

    /**
     * Set requiredThemeElements.
     *
     * @param integer $requiredThemeElements
     *
     * @return RenderPlugin
     */
    public function setRequiredThemeElements($requiredThemeElements)
    {
        $this->requiredThemeElements = $requiredThemeElements;

        return $this;
    }

    /**
     * Get requiredThemeElements.
     *
     * @return integer
     */
    public function getRequiredThemeElements()
    {
        return $this->requiredThemeElements;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return RenderPlugin
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
     * @return RenderPlugin
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
     * @return RenderPlugin
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
     * Add renderPluginInstance
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance
     * @return RenderPlugin
     */
    public function addRenderPluginInstance(\ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance)
    {
        $this->renderPluginInstance[] = $renderPluginInstance;

        return $this;
    }

    /**
     * Remove renderPluginInstance
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance
     */
    public function removeRenderPluginInstance(\ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance)
    {
        $this->renderPluginInstance->removeElement($renderPluginInstance);
    }

    /**
     * Get renderPluginInstance
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getRenderPluginInstance()
    {
        return $this->renderPluginInstance;
    }

    /**
     * Add renderPluginFields
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginFields $renderPluginFields
     * @return RenderPlugin
     */
    public function addRenderPluginField(\ODR\AdminBundle\Entity\RenderPluginFields $renderPluginFields)
    {
        $this->renderPluginFields[] = $renderPluginFields;

        return $this;
    }

    /**
     * Remove renderPluginFields
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginFields $renderPluginFields
     */
    public function removeRenderPluginField(\ODR\AdminBundle\Entity\RenderPluginFields $renderPluginFields)
    {
        $this->renderPluginFields->removeElement($renderPluginFields);
    }

    /**
     * Get renderPluginFields
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getRenderPluginFields()
    {
        return $this->renderPluginFields;
    }

    /**
     * Add renderPluginEvent.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginEvents $renderPluginEvent
     *
     * @return RenderPlugin
     */
    public function addRenderPluginEvent(\ODR\AdminBundle\Entity\RenderPluginEvents $renderPluginEvent)
    {
        $this->renderPluginEvents[] = $renderPluginEvent;

        return $this;
    }

    /**
     * Remove renderPluginEvent.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginEvents $renderPluginEvent
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeRenderPluginEvent(\ODR\AdminBundle\Entity\RenderPluginEvents $renderPluginEvent)
    {
        return $this->renderPluginEvents->removeElement($renderPluginEvent);
    }

    /**
     * Get renderPluginEvents.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRenderPluginEvents()
    {
        return $this->renderPluginEvents;
    }

    /**
     * Add renderPluginOptionsDef.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginOptionsDef $renderPluginOptionsDef
     *
     * @return RenderPlugin
     */
    public function addRenderPluginOptionsDef(\ODR\AdminBundle\Entity\RenderPluginOptionsDef $renderPluginOptionsDef)
    {
        $this->renderPluginOptionsDef[] = $renderPluginOptionsDef;

        return $this;
    }

    /**
     * Remove renderPluginOptionsDef.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginOptionsDef $renderPluginOptionsDef
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeRenderPluginOptionsDef(\ODR\AdminBundle\Entity\RenderPluginOptionsDef $renderPluginOptionsDef)
    {
        return $this->renderPluginOptionsDef->removeElement($renderPluginOptionsDef);
    }

    /**
     * Get renderPluginOptionsDef.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRenderPluginOptionsDef()
    {
        return $this->renderPluginOptionsDef;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return RenderPlugin
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
     * @return RenderPlugin
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
