<?php

/**
 * Open Data Repository Data Publisher
 * FileChecksum Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The FileChecksum Entity is automatically generated from
 * ./Resources/config/doctrine/FileChecksum.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * FileChecksum
 */
class FileChecksum
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var integer
     */
    private $chunk_id;

    /**
     * @var string
     */
    private $checksum;

    /**
     * @var \ODR\AdminBundle\Entity\File
     */
    private $file;


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
     * Set chunk_id
     *
     * @param integer $chunkId
     * @return FileChecksum
     */
    public function setChunkId($chunkId)
    {
        $this->chunk_id = $chunkId;

        return $this;
    }

    /**
     * Get chunk_id
     *
     * @return integer 
     */
    public function getChunkId()
    {
        return $this->chunk_id;
    }

    /**
     * Set checksum
     *
     * @param string $checksum
     * @return FileChecksum
     */
    public function setChecksum($checksum)
    {
        $this->checksum = $checksum;

        return $this;
    }

    /**
     * Get checksum
     *
     * @return string 
     */
    public function getChecksum()
    {
        return $this->checksum;
    }

    /**
     * Set file
     *
     * @param \ODR\AdminBundle\Entity\File $file
     * @return FileChecksum
     */
    public function setFile(\ODR\AdminBundle\Entity\File $file = null)
    {
        $this->file = $file;

        return $this;
    }

    /**
     * Get file
     *
     * @return \ODR\AdminBundle\Entity\File 
     */
    public function getFile()
    {
        return $this->file;
    }
}
