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
// use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * ODR\AdminBundle\Entity\File
 */
class File
{
    /**
     * @var integer $id
     */
    private $id;

    /**
     * @var string $fileData
     */
    private $fileData;

    /**
     * @var string $caption
     */
    private $caption;

    /**
     * @var string $originalFileName
     */
    private $originalFileName;

    /**
     * @var string $localFileName
     */
    private $localFileName;

    /**
     * @var ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var ODR\AdminBundle\Entity\FieldType
     */
    private $fieldType;

    /**
     * @var ODR\AdminBundle\Entity\DataRecord
     */
    private $dataRecord;


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
     * Set fileData
     *
     * @param string $fileData
     * @return File
     */
    public function setUploadedFileData($fileData)
    {
        $this->fileData = $fileData;
    
        return $this;
    }

    /**
     * Get fileData
     *
     * @return string 
     */
    public function getUploadedFileData()
    {
        return $this->fileData;
    }

    /**
     * Set caption
     *
     * @param string $caption
     * @return File
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
     * @return File
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
     * Set dataField
     *
     * @param ODR\AdminBundle\Entity\DataFields $dataField
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
     * @return ODR\AdminBundle\Entity\DataFields 
     */
    public function getDataField()
    {
        return $this->dataField;
    }

    /**
     * Set fieldType
     *
     * @param ODR\AdminBundle\Entity\FieldType $fieldType
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
     * @return ODR\AdminBundle\Entity\FieldType 
     */
    public function getFieldType()
    {
        return $this->fieldType;
    }

    /**
     * Set dataRecord
     *
     * @param ODR\AdminBundle\Entity\DataRecord $dataRecord
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
     * @return ODR\AdminBundle\Entity\DataRecord 
     */
    public function getDataRecord()
    {
        return $this->dataRecord;
    }
    /**
     * @var boolean $graphable
     */
    private $graphable;

    /**
     * @var string $graphFunction
     */
    private $graphFunction;


    /**
     * Set graphable
     *
     * @param boolean $graphable
     * @return File
     */
    public function setGraphable($graphable)
    {
        $this->graphable = $graphable;
    
        return $this;
    }

    /**
     * Get graphable
     *
     * @return boolean 
     */
    public function getGraphable()
    {
        return $this->graphable;
    }

    /**
     * Set graphFunction
     *
     * @param string $graphFunction
     * @return File
     */
    public function setGraphFunction($graphFunction)
    {
        $this->graphFunction = $graphFunction;
    
        return $this;
    }

    /**
     * Get graphFunction
     *
     * @return string 
     */
    public function getGraphFunction()
    {
        return $this->graphFunction;
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
     * Set updated
     *
     * @param \DateTime $updated
     * @return File
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
     * Set updatedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $updatedBy
     * @return File
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
     * @var \ODR\AdminBundle\Entity\DataRecordFields
     */
    private $dataRecordFields;


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
     */
    public function preUpload()
    {  
        if (null !== $this->getUploadedFile()) {
            $this->path = $this->getUploadedFile()->guessExtension();

            $this->setExt($this->getUploadedFile()->guessExtension());
        }
    }

    /**
     */
    public function storeFilenameForRemove()
    {  
        $this->temp = $this->getAbsolutePath();
    }

    /**
     */
    public function removeUpload()
    {  
        // Do not remove file
        /*
        if (isset($this->temp)) {
            unlink($this->temp);
        }
        */
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
     * TODO: description.
     * 
     * @var mixed
     */
    private $temp;


    /**
    * TODO: short description.
    * 
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
     * TODO: description.
     * 
     * @var mixed
     */
    public $path;

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getAbsolutePath()
    {
        return null === $this->path
            ? null
            : $this->getUploadRootDir().'/File_'.$this->id.'.'.$this->path;
    }

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getWebPath()
    {
        return null === $this->path
            ? null
            : $this->getUploadDir().'/'.$this->path;
    }

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getUploadRootDir()
    {
        // the absolute directory path where uploaded
        // documents should be saved
        return __DIR__.'/../../../../web/'.$this->getUploadDir();
    }

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getUploadDir()
    {
        // get rid of the __DIR__ so it doesn't screw up
        // when displaying uploaded doc/image in the view.
        return 'uploads/files';
    }


    /**
     * @var string
     */
    private $ext;


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
     * @var \DateTime
     */
    private $publicDate;


    /**
     * Set publicDate
     *
     * @param \DateTime $publicDate
     * @return File
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
     * Is public
     *
     * @return boolean
     */
    public function isPublic()
    {
        if ($this->publicDate->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
            return false;
        else
            return true;
    }

    /**
     * @var string
     */
    private $encrypt_key;


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
     * @var \Doctrine\Common\Collections\Collection
     */
    private $FileChecksum;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->FileChecksum = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Add FileChecksum
     *
     * @param \ODR\AdminBundle\Entity\FileChecksum $fileChecksum
     * @return File
     */
    public function addFileChecksum(\ODR\AdminBundle\Entity\FileChecksum $fileChecksum)
    {
        $this->FileChecksum[] = $fileChecksum;

        return $this;
    }

    /**
     * Remove FileChecksum
     *
     * @param \ODR\AdminBundle\Entity\FileChecksum $fileChecksum
     */
    public function removeFileChecksum(\ODR\AdminBundle\Entity\FileChecksum $fileChecksum)
    {
        $this->FileChecksum->removeElement($fileChecksum);
    }

    /**
     * Get FileChecksum
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getFileChecksum()
    {
        return $this->FileChecksum;
    }
    /**
     * @var string
     */
    private $external_id;

    /**
     * @var string
     */
    private $original_checksum;


    /**
     * Set external_id
     *
     * @param string $externalId
     * @return File
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
}