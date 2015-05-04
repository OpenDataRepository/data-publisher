<?php

/**
* Open Data Repository Data Publisher
* TrackedExport Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The TrackedExport Entity is automatically generated from 
* ./Resources/config/doctrine/TrackedExport.orm.yml
*
*/

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * TrackedExport
 */
class TrackedExport
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $random_key;

    /**
     * @var \ODR\AdminBundle\Entity\TrackedJob
     */
    private $trackedJob;


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
     * Set random_key
     *
     * @param string $randomKey
     * @return TrackedExport
     */
    public function setRandomKey($randomKey)
    {
        $this->random_key = $randomKey;

        return $this;
    }

    /**
     * Get random_key
     *
     * @return string 
     */
    public function getRandomKey()
    {
        return $this->random_key;
    }

    /**
     * Set trackedJob
     *
     * @param \ODR\AdminBundle\Entity\TrackedJob $trackedJob
     * @return TrackedExport
     */
    public function setTrackedJob(\ODR\AdminBundle\Entity\TrackedJob $trackedJob = null)
    {
        $this->trackedJob = $trackedJob;

        return $this;
    }

    /**
     * Get trackedJob
     *
     * @return \ODR\AdminBundle\Entity\TrackedJob 
     */
    public function getTrackedJob()
    {
        return $this->trackedJob;
    }

    /**
     * @var boolean
     */
    private $finalize;


    /**
     * Set finalize
     *
     * @param boolean $finalize
     * @return TrackedExport
     */
    public function setFinalize($finalize)
    {
        $this->finalize = $finalize;

        return $this;
    }

    /**
     * Get finalize
     *
     * @return boolean 
     */
    public function getFinalize()
    {
        return $this->finalize;
    }
}
