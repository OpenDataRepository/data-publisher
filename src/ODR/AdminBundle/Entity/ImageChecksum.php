<?php

/**
* Open Data Repository Data Publisher
* ImageChecksum Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The ImageChecksum Entity is automatically generated from 
* ./Resources/config/doctrine/ImageChecksum.orm.yml
*
*/


namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ImageChecksum
 */
class ImageChecksum
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
     * @var \ODR\AdminBundle\Entity\Image
     */
    private $Image;


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
     * @return ImageChecksum
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
     * @return ImageChecksum
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
     * Set Image
     *
     * @param \ODR\AdminBundle\Entity\Image $image
     * @return ImageChecksum
     */
    public function setImage(\ODR\AdminBundle\Entity\Image $image = null)
    {
        $this->Image = $image;

        return $this;
    }

    /**
     * Get Image
     *
     * @return \ODR\AdminBundle\Entity\Image 
     */
    public function getImage()
    {
        return $this->Image;
    }
}
