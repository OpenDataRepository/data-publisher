<?php

/**
* Open Data Repository Data Publisher
* RenderPluginOptions Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The RenderPluginOptions Entity is automatically generated from 
* ./Resources/config/doctrine/RenderPluginOptions.orm.yml
*
*/


namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * RenderPluginOptions
 */
class RenderPluginOptions
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $optionName;

    /**
     * @var string
     */
    private $optionValue;

    /**
     * @var boolean
     */
    private $active;

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
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set optionName
     *
     * @param string $optionName
     * @return RenderPluginOptions
     */
    public function setOptionName($optionName)
    {
        $this->optionName = $optionName;
    
        return $this;
    }

    /**
     * Get optionName
     *
     * @return string 
     */
    public function getOptionName()
    {
        return $this->optionName;
    }

    /**
     * Set optionValue
     *
     * @param string $optionValue
     * @return RenderPluginOptions
     */
    public function setOptionValue($optionValue)
    {
        $this->optionValue = $optionValue;
    
        return $this;
    }

    /**
     * Get optionValue
     *
     * @return string 
     */
    public function getOptionValue()
    {
        return $this->optionValue;
    }

    /**
     * Set active
     *
     * @param boolean $active
     * @return RenderPluginOptions
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
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return RenderPluginOptions
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
     * @return RenderPluginOptions
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
     * @return RenderPluginOptions
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
     * Set renderPlugin
     *
     * @param \ODR\AdminBundle\Entity\RenderPlugin $renderPlugin
     * @return RenderPluginOptions
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
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return RenderPluginOptions
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
     * @return RenderPluginOptions
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
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;


    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return RenderPluginOptions
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
     * @return RenderPluginOptions
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
     * @var \ODR\AdminBundle\Entity\RenderPluginInstance
     */
    private $renderPluginInstance;


    /**
     * Set renderPluginInstance
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance
     * @return RenderPluginOptions
     */
    public function setRenderPluginInstance(\ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance = null)
    {
        $this->renderPluginInstance = $renderPluginInstance;
    
        return $this;
    }

    /**
     * Get renderPluginInstance
     *
     * @return \ODR\AdminBundle\Entity\RenderPluginInstance 
     */
    public function getRenderPluginInstance()
    {
        return $this->renderPluginInstance;
    }
}
