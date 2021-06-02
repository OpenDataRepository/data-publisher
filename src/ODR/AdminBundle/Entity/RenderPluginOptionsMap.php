<?php

/**
 * Open Data Repository Data Publisher
 * RenderPluginOptionsMap Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RenderPluginOptionsMap Entity is automatically generated from
 * ./Resources/config/doctrine/RenderPluginOptionsMap.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

/**
 * RenderPluginOptionsMap
 */
class RenderPluginOptionsMap
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var string|null
     */
    private $value;

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
     * @var \ODR\AdminBundle\Entity\RenderPluginInstance
     */
    private $renderPluginInstance;

    /**
     * @var \ODR\AdminBundle\Entity\RenderPluginOptionsDef
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
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set value.
     *
     * @param string|null $value
     *
     * @return RenderPluginOptionsMap
     */
    public function setValue($value = null)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Get value.
     *
     * @return string|null
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set created.
     *
     * @param \DateTime $created
     *
     * @return RenderPluginOptionsMap
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
     * @return RenderPluginOptionsMap
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
     * @return RenderPluginOptionsMap
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
     * Set renderPluginInstance.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginInstance|null $renderPluginInstance
     *
     * @return RenderPluginOptionsMap
     */
    public function setRenderPluginInstance(\ODR\AdminBundle\Entity\RenderPluginInstance $renderPluginInstance = null)
    {
        $this->renderPluginInstance = $renderPluginInstance;

        return $this;
    }

    /**
     * Get renderPluginInstance.
     *
     * @return \ODR\AdminBundle\Entity\RenderPluginInstance|null
     */
    public function getRenderPluginInstance()
    {
        return $this->renderPluginInstance;
    }

    /**
     * Set renderPluginOptionsDef.
     *
     * @param \ODR\AdminBundle\Entity\RenderPluginOptionsDef|null $renderPluginOptionsDef
     *
     * @return RenderPluginOptionsMap
     */
    public function setRenderPluginOptionsDef(\ODR\AdminBundle\Entity\RenderPluginOptionsDef $renderPluginOptionsDef = null)
    {
        $this->renderPluginOptionsDef = $renderPluginOptionsDef;

        return $this;
    }

    /**
     * Get renderPluginOptionsDef.
     *
     * @return \ODR\AdminBundle\Entity\RenderPluginOptionsDef|null
     */
    public function getRenderPluginOptionsDef()
    {
        return $this->renderPluginOptionsDef;
    }

    /**
     * Set createdBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $createdBy
     *
     * @return RenderPluginOptionsMap
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
     * @return RenderPluginOptionsMap
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
