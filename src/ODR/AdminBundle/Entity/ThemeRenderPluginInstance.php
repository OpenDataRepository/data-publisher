<?php

namespace ODR\AdminBundle\Entity;

/**
 * ThemeRenderPluginInstance
 */
class ThemeRenderPluginInstance
{
    /**
     * @var int
     */
    private $id;

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
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set created.
     *
     * @param \DateTime $created
     *
     * @return ThemeRenderPluginInstance
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
     * @return ThemeRenderPluginInstance
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
     * @return ThemeRenderPluginInstance
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
     * @return ThemeRenderPluginInstance
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
     * Set themeElement.
     *
     * @param \ODR\AdminBundle\Entity\ThemeElement|null $themeElement
     *
     * @return ThemeRenderPluginInstance
     */
    public function setThemeElement(\ODR\AdminBundle\Entity\ThemeElement $themeElement = null)
    {
        $this->themeElement = $themeElement;

        return $this;
    }

    /**
     * Get themeElement.
     *
     * @return \ODR\AdminBundle\Entity\ThemeElement|null
     */
    public function getThemeElement()
    {
        return $this->themeElement;
    }

    /**
     * Set createdBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $createdBy
     *
     * @return ThemeRenderPluginInstance
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
     * @return ThemeRenderPluginInstance
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

    /**
     * Set deletedBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $deletedBy
     *
     * @return ThemeRenderPluginInstance
     */
    public function setDeletedBy(\ODR\OpenRepository\UserBundle\Entity\User $deletedBy = null)
    {
        $this->deletedBy = $deletedBy;

        return $this;
    }

    /**
     * Get deletedBy.
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User|null
     */
    public function getDeletedBy()
    {
        return $this->deletedBy;
    }
}
