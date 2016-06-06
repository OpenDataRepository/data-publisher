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
    private $pluginClassName;

    /**
     * @var boolean
     */
    private $active;

    /**
     * @var boolean
     */
    private $overrideChild;

    /**
     * @var boolean
     */
    private $overrideFields;

    /**
     * @var integer
     */
    private $plugin_type;

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
    private $dataFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataType;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginInstance;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $renderPluginFields;

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
        $this->dataFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->dataType = new \Doctrine\Common\Collections\ArrayCollection();
        $this->renderPluginInstance = new \Doctrine\Common\Collections\ArrayCollection();
        $this->renderPluginFields = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Add dataFields
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataFields
     * @return RenderPlugin
     */
    public function addDataField(\ODR\AdminBundle\Entity\DataFields $dataFields)
    {
        $this->dataFields[] = $dataFields;

        return $this;
    }

    /**
     * Remove dataFields
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataFields
     */
    public function removeDataField(\ODR\AdminBundle\Entity\DataFields $dataFields)
    {
        $this->dataFields->removeElement($dataFields);
    }

    /**
     * Get dataFields
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getDataFields()
    {
        return $this->dataFields;
    }

    /**
     * Add dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return RenderPlugin
     */
    public function addDataType(\ODR\AdminBundle\Entity\DataType $dataType)
    {
        $this->dataType[] = $dataType;

        return $this;
    }

    /**
     * Remove dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     */
    public function removeDataType(\ODR\AdminBundle\Entity\DataType $dataType)
    {
        $this->dataType->removeElement($dataType);
    }

    /**
     * Get dataType
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getDataType()
    {
        return $this->dataType;
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
