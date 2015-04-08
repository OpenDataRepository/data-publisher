<?php

/**
* Open Data Repository Data Publisher
* Theme Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The Theme Entity is automatically generated from 
* ./Resources/config/doctrine/Theme.orm.yml
*
*/


namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ODR\AdminBundle\Entity\Theme
 */
class Theme
{
    /**
     * @var integer $id
     */
    private $id;

    /**
     * @var string $templateName
     */
    private $templateName;

    /**
     * @var string $templateDescription
     */
    private $templateDescription;

    /**
     * @var string $templatePreview
     */
    private $templatePreview;

    /**
     * @var boolean $isDefault
     */
    private $isDefault;


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
     * Set templateName
     *
     * @param string $templateName
     * @return Theme
     */
    public function setTemplateName($templateName)
    {
        $this->templateName = $templateName;
    
        return $this;
    }

    /**
     * Get templateName
     *
     * @return string 
     */
    public function getTemplateName()
    {
        return $this->templateName;
    }

    /**
     * Set templateDescription
     *
     * @param string $templateDescription
     * @return Theme
     */
    public function setTemplateDescription($templateDescription)
    {
        $this->templateDescription = $templateDescription;
    
        return $this;
    }

    /**
     * Get templateDescription
     *
     * @return string 
     */
    public function getTemplateDescription()
    {
        return $this->templateDescription;
    }

    /**
     * Set templatePreview
     *
     * @param string $templatePreview
     * @return Theme
     */
    public function setTemplatePreview($templatePreview)
    {
        $this->templatePreview = $templatePreview;
    
        return $this;
    }

    /**
     * Get templatePreview
     *
     * @return string 
     */
    public function getTemplatePreview()
    {
        return $this->templatePreview;
    }

    /**
     * Set isDefault
     *
     * @param boolean $isDefault
     * @return Theme
     */
    public function setIsDefault($isDefault)
    {
        $this->isDefault = $isDefault;
    
        return $this;
    }

    /**
     * Get isDefault
     *
     * @return boolean 
     */
    public function getIsDefault()
    {
        return $this->isDefault;
    }
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
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;


    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return Theme
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
     * @return Theme
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
     * @return Theme
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
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return Theme
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
     * @return Theme
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
     * @var string
     */
    private $templateType;


    /**
     * Set templateType
     *
     * @param string $templateType
     * @return Theme
     */
    public function setTemplateType($templateType)
    {
        $this->templateType = $templateType;
    
        return $this;
    }

    /**
     * Get templateType
     *
     * @return string 
     */
    public function getTemplateType()
    {
        return $this->templateType;
    }
}
