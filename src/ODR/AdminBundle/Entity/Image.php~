<?php

/**
 * Open Data Repository Data Publisher
 * Image Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Image Entity is automatically generated from
 * ./Resources/config/doctrine/Image.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Image
 */
class Image
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var boolean
     */
    private $original;

    /**
     * @var string
     */
    private $ext;

    /**
     * @var string
     */
    private $localFileName;

    /**
     * @var string
     */
    private $encrypt_key;

    /**
     * @var string
     */
    private $original_checksum;

    /**
     * @var integer
     */
    private $imageWidth;

    /**
     * @var integer
     */
    private $imageHeight;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $children;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $imageChecksum;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $imageMeta;

    /**
     * @var \ODR\AdminBundle\Entity\Image
     */
    private $parent;

    /**
     * @var \ODR\AdminBundle\Entity\FieldType
     */
    private $fieldType;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $dataRecord;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecordFields
     */
    private $dataRecordFields;

    /**
     * @var \ODR\AdminBundle\Entity\ImageSizes
     */
    private $imageSize;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $deletedBy;


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->children = new \Doctrine\Common\Collections\ArrayCollection();
        $this->imageChecksum = new \Doctrine\Common\Collections\ArrayCollection();
        $this->imageMeta = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set original
     *
     * @param boolean $original
     * @return Image
     */
    public function setOriginal($original)
    {
        $this->original = $original;

        return $this;
    }

    /**
     * Get original
     *
     * @return boolean 
     */
    public function getOriginal()
    {
        return $this->original;
    }

    /**
     * Set ext
     *
     * @param string $ext
     * @return Image
     */
    public function setExt($ext)
    {
        $this->ext = $ext;

        return $this;
    }

    /**
     * Get ext
     *
     * @return string 
     */
    public function getExt()
    {
        return $this->ext;
    }

    /**
     * Set localFileName
     *
     * @param string $localFileName
     * @return Image
     */
    public function setLocalFileName($localFileName)
    {
        $this->localFileName = $localFileName;

        return $this;
    }

    /**
     * Get localFileName
     *
     * @return string 
     */
    public function getLocalFileName()
    {
        return $this->localFileName;
    }

    /**
     * Set encrypt_key
     *
     * @param string $encryptKey
     * @return Image
     */
    public function setEncryptKey($encryptKey)
    {
        $this->encrypt_key = $encryptKey;

        return $this;
    }

    /**
     * Get encrypt_key
     *
     * @return string 
     */
    public function getEncryptKey()
    {
        return $this->encrypt_key;
    }

    /**
     * Set original_checksum
     *
     * @param string $originalChecksum
     * @return Image
     */
    public function setOriginalChecksum($originalChecksum)
    {
        $this->original_checksum = $originalChecksum;

        return $this;
    }

    /**
     * Get original_checksum
     *
     * @return string 
     */
    public function getOriginalChecksum()
    {
        return $this->original_checksum;
    }

    /**
     * Set imageWidth
     *
     * @param integer $imageWidth
     * @return Image
     */
    public function setImageWidth($imageWidth)
    {
        $this->imageWidth = $imageWidth;

        return $this;
    }

    /**
     * Get imageWidth
     *
     * @return integer 
     */
    public function getImageWidth()
    {
        return $this->imageWidth;
    }

    /**
     * Set imageHeight
     *
     * @param integer $imageHeight
     * @return Image
     */
    public function setImageHeight($imageHeight)
    {
        $this->imageHeight = $imageHeight;

        return $this;
    }

    /**
     * Get imageHeight
     *
     * @return integer 
     */
    public function getImageHeight()
    {
        return $this->imageHeight;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return Image
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
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return Image
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
     * Add children
     *
     * @param \ODR\AdminBundle\Entity\Image $children
     * @return Image
     */
    public function addChild(\ODR\AdminBundle\Entity\Image $children)
    {
        $this->children[] = $children;

        return $this;
    }

    /**
     * Remove children
     *
     * @param \ODR\AdminBundle\Entity\Image $children
     */
    public function removeChild(\ODR\AdminBundle\Entity\Image $children)
    {
        $this->children->removeElement($children);
    }

    /**
     * Get children
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * Add imageChecksum
     *
     * @param \ODR\AdminBundle\Entity\ImageChecksum $imageChecksum
     * @return Image
     */
    public function addImageChecksum(\ODR\AdminBundle\Entity\ImageChecksum $imageChecksum)
    {
        $this->imageChecksum[] = $imageChecksum;

        return $this;
    }

    /**
     * Remove imageChecksum
     *
     * @param \ODR\AdminBundle\Entity\ImageChecksum $imageChecksum
     */
    public function removeImageChecksum(\ODR\AdminBundle\Entity\ImageChecksum $imageChecksum)
    {
        $this->imageChecksum->removeElement($imageChecksum);
    }

    /**
     * Get imageChecksum
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getImageChecksum()
    {
        return $this->imageChecksum;
    }

    /**
     * Add imageMeta
     *
     * @param \ODR\AdminBundle\Entity\ImageMeta $imageMeta
     * @return Image
     */
    public function addImageMetum(\ODR\AdminBundle\Entity\ImageMeta $imageMeta)
    {
        $this->imageMeta[] = $imageMeta;

        return $this;
    }

    /**
     * Remove imageMeta
     *
     * @param \ODR\AdminBundle\Entity\ImageMeta $imageMeta
     */
    public function removeImageMetum(\ODR\AdminBundle\Entity\ImageMeta $imageMeta)
    {
        $this->imageMeta->removeElement($imageMeta);
    }

    /**
     * Get imageMeta
     *
     * @return \ODR\AdminBundle\Entity\ImageMeta
     */
    public function getImageMeta()
    {
        return $this->imageMeta->first();
    }

    /**
     * Set parent
     *
     * @param \ODR\AdminBundle\Entity\Image $parent
     * @return Image
     */
    public function setParent(\ODR\AdminBundle\Entity\Image $parent = null)
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * Get parent
     *
     * @return \ODR\AdminBundle\Entity\Image 
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Set fieldType
     *
     * @param \ODR\AdminBundle\Entity\FieldType $fieldType
     * @return Image
     */
    public function setFieldType(\ODR\AdminBundle\Entity\FieldType $fieldType = null)
    {
        $this->fieldType = $fieldType;

        return $this;
    }

    /**
     * Get fieldType
     *
     * @return \ODR\AdminBundle\Entity\FieldType 
     */
    public function getFieldType()
    {
        return $this->fieldType;
    }

    /**
     * Set dataField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataField
     * @return Image
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
     * Set dataRecord
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $dataRecord
     * @return Image
     */
    public function setDataRecord(\ODR\AdminBundle\Entity\DataRecord $dataRecord = null)
    {
        $this->dataRecord = $dataRecord;

        return $this;
    }

    /**
     * Get dataRecord
     *
     * @return \ODR\AdminBundle\Entity\DataRecord 
     */
    public function getDataRecord()
    {
        return $this->dataRecord;
    }

    /**
     * Set dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     * @return Image
     */
    public function setDataRecordFields(\ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields = null)
    {
        $this->dataRecordFields = $dataRecordFields;

        return $this;
    }

    /**
     * Get dataRecordFields
     *
     * @return \ODR\AdminBundle\Entity\DataRecordFields 
     */
    public function getDataRecordFields()
    {
        return $this->dataRecordFields;
    }

    /**
     * Set imageSize
     *
     * @param \ODR\AdminBundle\Entity\ImageSizes $imageSize
     * @return Image
     */
    public function setImageSize(\ODR\AdminBundle\Entity\ImageSizes $imageSize = null)
    {
        $this->imageSize = $imageSize;

        return $this;
    }

    /**
     * Get imageSize
     *
     * @return \ODR\AdminBundle\Entity\ImageSizes 
     */
    public function getImageSize()
    {
        return $this->imageSize;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return Image
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
     * Set deletedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $deletedBy
     * @return Image
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

    /**
     * Is public
     *
     * @return boolean
     */
    public function isPublic()
    {
        // Return whether the original image is public or not
        if ( $this->getPublicDate()->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' )
            return false;
        else
            return true;
    }

    /*
     * ----------------------------------------
     * ----------------------------------------
     */

    /**
     * @Assert\File(maxSize="6000000")
     */
    private $file;

    /**
     * @var mixed
     */
    private $temp;

    /**
     * Sets file.
     *
     * @param UploadedFile $file
     */
    public function setFile(UploadedFile $file = null)
    {
        $this->file = $file;
        // check if we have an old image path
        if (is_file($this->getAbsolutePath())) {
            // store the old name to delete after the update
            $this->temp = $this->getAbsolutePath();
        } else {
            $this->path = 'initial';
        }
    }

    /**
     * Get file.
     *
     * @return UploadedFile
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * @ORM\PrePersist
     */
    public function preUpload()
    {
        if (null !== $this->getFile()) {
            $this->path = $this->getFile()->guessExtension();

            $this->setExt($this->getFile()->guessExtension());
        }
    }

    /**
     * @ORM\PostPersist
     */
    public function upload()
    {
        if (null === $this->getFile()) {
            return;
        }

        // check if we have an old image
        if (isset($this->temp)) {
            // delete the old image
            unlink($this->temp);
            // clear the temp image path
            $this->temp = null;
        }

        $filename = "Image_" . $this->id.'.'.$this->getFile()->guessExtension();
        // you must throw an exception here if the file cannot be moved
        // so that the entity is not persisted to the database
        // which the UploadedFile move() method does
        $this->getFile()->move(
            $this->getUploadRootDir(),
            $filename
        );

        $this->setFile(null);
    }

    /**
     * @ORM\PreRemove
     */
    public function storeFilenameForRemove()
    {
        $this->temp = $this->getAbsolutePath();
    }

    /**
     * @ORM\PostRemove
     */
    public function removeUpload()
    {
/*
        // Do not remove file
        if (isset($this->temp)) {
            unlink($this->temp);
        }
*/
    }

    /**
     * @var mixed
     */
    public $path;

    /**
     * @return mixed
     */
    public function getAbsolutePath()
    {
        return null === $this->path
            ? null
            : $this->getUploadRootDir().'/Image_'.$this->id.'.'.$this->path;
    }

    /**
     * @return mixed
     */
    public function getWebPath()
    {
        return null === $this->path
            ? null
            : $this->getUploadDir().'/'.$this->path;
    }

    /**
     * @return string
     */
    public function getUploadRootDir()
    {
        // the absolute directory path where uploaded
        // documents should be saved
        return __DIR__.'/../../../../web/'.$this->getUploadDir();
    }

    /**
     * @return string
     */
    public function getUploadDir()
    {
        // get rid of the __DIR__ so it doesn't screw up
        // when displaying uploaded doc/image in the view.
        return 'uploads/images';
    }


    /*
     * ----------------------------------------
     * ----------------------------------------
     */

    /**
     * Get displayorder
     *
     * @return integer
     */
    public function getDisplayorder()
    {
        if ( $this->getOriginal() )
            return $this->getImageMeta()->getDisplayorder();
        else
            return $this->getParent()->getImageMeta()->getDisplayorder();
    }

    /**
     * Get caption
     *
     * @return string
     */
    public function getCaption()
    {
        if ( $this->getOriginal() )
            return $this->getImageMeta()->getCaption();
        else
            return $this->getParent()->getImageMeta()->getCaption();
    }

    /**
     * Get originalFileName
     *
     * @return string
     */
    public function getOriginalFileName()
    {
        if ( $this->getOriginal() )
            return $this->getImageMeta()->getOriginalFileName();
        else
            return $this->getParent()->getImageMeta()->getOriginalFileName();
    }

    /**
     * Get external_id
     *
     * @return string
     */
    public function getExternalId()
    {
        if ( $this->getOriginal() )
            return $this->getImageMeta()->getExternalId();
        else
            return $this->getParent()->getImageMeta()->getExternalId();
    }

    /**
     * Get publicDate
     *
     * @return \DateTime
     */
    public function getPublicDate()
    {
        if ( $this->getOriginal() )
            return $this->getImageMeta()->getPublicDate();
        else
            return $this->getParent()->getImageMeta()->getPublicDate();
    }
    /**
     * @var string|null
     */
    private $unique_id;


    /**
     * Set uniqueId.
     *
     * @param string|null $uniqueId
     *
     * @return Image
     */
    public function setUniqueId($uniqueId = null)
    {
        $this->unique_id = $uniqueId;

        return $this;
    }

    /**
     * Get uniqueId.
     *
     * @return string|null
     */
    public function getUniqueId()
    {
        return $this->unique_id;
    }
}
