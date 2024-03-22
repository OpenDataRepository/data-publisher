<?php

/**
 * Open Data Repository Data Publisher
 * File Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The File Entity is automatically generated from
 * ./Resources/config/doctrine/File.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * File
 */
class File
{
    /**
     * @var integer
     */
    private $id;

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
    private $filesize;

    /**
     * @var string|null
     */
    private $unique_id;

    /**
     * @deprecated
     * @var boolean
     */
    private $provisioned;

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
    private $fileChecksum;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $fileMeta;

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
        $this->fileChecksum = new \Doctrine\Common\Collections\ArrayCollection();
        $this->fileMeta = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set ext
     *
     * @param string $ext
     * @return File
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
     * @return File
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
     * @return File
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
     * @return File
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
     * Set filesize
     *
     * @param integer $filesize
     * @return File
     */
    public function setFilesize($filesize)
    {
        $this->filesize = $filesize;

        return $this;
    }

    /**
     * Get filesize
     *
     * @return integer 
     */
    public function getFilesize()
    {
        return $this->filesize;
    }

    /**
     * Set uniqueId.
     *
     * @param string|null $uniqueId
     *
     * @return File
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

    /**
     * @deprecated
     * Set provisioned
     *
     * @param boolean $provisioned
     *
     * @return File
     */
    public function setProvisioned($provisioned)
    {
        $this->provisioned = $provisioned;

        return $this;
    }

    /**
     * @deprecated
     * Get provisioned
     *
     * @return boolean
     */
    public function getProvisioned()
    {
        return $this->provisioned;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return File
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
     * @return File
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
     * Add fileChecksum
     *
     * @param \ODR\AdminBundle\Entity\FileChecksum $fileChecksum
     * @return File
     */
    public function addFileChecksum(\ODR\AdminBundle\Entity\FileChecksum $fileChecksum)
    {
        $this->fileChecksum[] = $fileChecksum;

        return $this;
    }

    /**
     * Remove fileChecksum
     *
     * @param \ODR\AdminBundle\Entity\FileChecksum $fileChecksum
     */
    public function removeFileChecksum(\ODR\AdminBundle\Entity\FileChecksum $fileChecksum)
    {
        $this->fileChecksum->removeElement($fileChecksum);
    }

    /**
     * Get fileChecksum
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getFileChecksum()
    {
        return $this->fileChecksum;
    }

    /**
     * Add fileMeta
     *
     * @param \ODR\AdminBundle\Entity\FileMeta $fileMeta
     * @return File
     */
    public function addFileMetum(\ODR\AdminBundle\Entity\FileMeta $fileMeta)
    {
        $this->fileMeta[] = $fileMeta;

        return $this;
    }

    /**
     * Remove fileMeta
     *
     * @param \ODR\AdminBundle\Entity\FileMeta $fileMeta
     */
    public function removeFileMetum(\ODR\AdminBundle\Entity\FileMeta $fileMeta)
    {
        $this->fileMeta->removeElement($fileMeta);
    }

    /**
     * Get fileMeta
     *
     * @return \ODR\AdminBundle\Entity\FileMeta
     */
    public function getFileMeta()
    {
        return $this->fileMeta->first();
    }

    /**
     * Set fieldType
     *
     * @param \ODR\AdminBundle\Entity\FieldType $fieldType
     * @return File
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
     * @return File
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
     * @return File
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
     * @return File
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
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return File
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
     * @return File
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
        if ($this->getPublicDate()->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
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
    private $uploaded_file;

    /**
     * Sets file.
     *
     * @param UploadedFile $file
     */
    public function setUploadedFile(UploadedFile $file = null)
    {
        $this->uploaded_file = $file;
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
    public function getUploadedFile()
    {
        return $this->uploaded_file;
    }

    /**
     * @ORM\PrePersist
     */
    public function preUpload()
    {
        if (null !== $this->getUploadedFile()) {
            $this->path = $this->getUploadedFile()->guessExtension();

            $this->setExt($this->getUploadedFile()->guessExtension());
        }
    }

    /**
     * @ORM\PostPersist
     */
    public function upload()
    {
        if (null === $this->getUploadedFile()) {
            return;
        }

        // check if we have an old image
        if (isset($this->temp)) {
            // delete the old image
            unlink($this->temp);
            // clear the temp image path
            $this->temp = null;
        }

        $filename = "File_" . $this->id.'.'.$this->getUploadedFile()->guessExtension();
        // you must throw an exception here if the file cannot be moved
        // so that the entity is not persisted to the database
        // which the UploadedFile move() method does
        $this->getUploadedFile()->move(
            $this->getUploadRootDir(),
            $filename
        );

        $this->setUploadedFile(null);
    }

    /**
     * @ORM\PreRemove
     */
    public function storeFilenameForRemove()
    {
        $this->temp = $this->getAbsolutePath();
    }

    /**
     * @var mixed
     */
    private $temp;

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
            : $this->getUploadRootDir().'/File_'.$this->id.'.'.$this->path;
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
        return 'uploads/files';
    }


    /*
     * ----------------------------------------
     * ----------------------------------------
     */

    /**
     * Get caption
     *
     * @return string
     */
    public function getCaption()
    {
        return $this->getFileMeta()->getDescription();
    }

    /**
     * Get originalFileName
     *
     * @return string
     */
    public function getOriginalFileName()
    {
        return $this->getFileMeta()->getOriginalFileName();
    }

    /**
     * Get quality
     *
     * @return int
     */
    public function getQuality()
    {
        return $this->getFileMeta()->getQuality();
    }

    /**
     * Get external_id
     *
     * @return string
     */
    public function getExternalId()
    {
        return $this->getFileMeta()->getExternalId();
    }

    /**
     * Get publicDate
     *
     * @return \DateTime
     */
    public function getPublicDate()
    {
        return $this->getFileMeta()->getPublicDate();
    }
}
