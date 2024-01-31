<?php

/**
 * Open Data Repository Data Publisher
 * ImageMeta Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The ImageMeta Entity is responsible for storing the properties
 * of the Image Entity that are subject to change, and is
 * automatically generated from ./Resources/config/doctrine/ImageMeta.orm.yml
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ImageMeta
 */
class ImageMeta
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var integer
     */
    private $displayorder;

    /**
     * @var string
     */
    private $caption;

    /**
     * @var string
     */
    private $originalFileName;

    /**
     * @var int
     */
    private $quality;

    /**
     * @var string
     */
    private $external_id;

    /**
     * @var \DateTime
     */
    private $publicDate;

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
     * @var \ODR\AdminBundle\Entity\Image
     */
    private $image;

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
     * Set displayorder
     *
     * @param integer $displayorder
     * @return ImageMeta
     */
    public function setDisplayorder($displayorder)
    {
        $this->displayorder = $displayorder;

        return $this;
    }

    /**
     * Get displayorder
     *
     * @return integer 
     */
    public function getDisplayorder()
    {
        return $this->displayorder;
    }

    /**
     * Set caption
     *
     * @param string $caption
     * @return ImageMeta
     */
    public function setCaption($caption)
    {
        $this->caption = $caption;

        return $this;
    }

    /**
     * Get caption
     *
     * @return string 
     */
    public function getCaption()
    {
        return $this->caption;
    }

    /**
     * Set originalFileName
     *
     * @param string $originalFileName
     * @return ImageMeta
     */
    public function setOriginalFileName($originalFileName)
    {
        $this->originalFileName = $originalFileName;

        return $this;
    }

    /**
     * Get originalFileName
     *
     * @return string 
     */
    public function getOriginalFileName()
    {
        return $this->originalFileName;
    }

    /**
     * Set quality.
     *
     * @param int $quality
     *
     * @return ImageMeta
     */
    public function setQuality($quality)
    {
        $this->quality = $quality;

        return $this;
    }

    /**
     * Get quality.
     *
     * @return int
     */
    public function getQuality()
    {
        return $this->quality;
    }

    /**
     * Set external_id
     *
     * @param string $externalId
     * @return ImageMeta
     */
    public function setExternalId($externalId)
    {
        $this->external_id = $externalId;

        return $this;
    }

    /**
     * Get external_id
     *
     * @return string 
     */
    public function getExternalId()
    {
        return $this->external_id;
    }

    /**
     * Set publicDate
     *
     * @param \DateTime $publicDate
     * @return ImageMeta
     */
    public function setPublicDate($publicDate)
    {
        $this->publicDate = $publicDate;

        return $this;
    }

    /**
     * Get publicDate
     *
     * @return \DateTime 
     */
    public function getPublicDate()
    {
        return $this->publicDate;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return ImageMeta
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
     * @return ImageMeta
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
     * @return ImageMeta
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
     * Set image
     *
     * @param \ODR\AdminBundle\Entity\Image $image
     * @return ImageMeta
     */
    public function setImage(\ODR\AdminBundle\Entity\Image $image = null)
    {
        $this->image = $image;

        return $this;
    }

    /**
     * Get image
     *
     * @return \ODR\AdminBundle\Entity\Image 
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return ImageMeta
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
     * @return ImageMeta
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
