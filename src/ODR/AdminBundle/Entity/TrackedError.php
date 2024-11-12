<?php

/**
 * Open Data Repository Data Publisher
 * TrackedError Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The TrackedError Entity is automatically generated from
 * ./Resources/config/doctrine/TrackedError.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * TrackedError
 */
class TrackedError
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $error_level;

    /**
     * @var string
     */
    private $error_category;

    /**
     * @var string
     */
    private $error_body;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \ODR\AdminBundle\Entity\TrackedJob
     */
    private $trackedJob;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;


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
     * Set error_level
     *
     * @param string $errorLevel
     * @return TrackedError
     */
    public function setErrorLevel($errorLevel)
    {
        $this->error_level = $errorLevel;

        return $this;
    }

    /**
     * Get error_level
     *
     * @return string
     */
    public function getErrorLevel()
    {
        return $this->error_level;
    }

    /**
     * Set errorCategory.
     *
     * @param string $errorCategory
     *
     * @return TrackedError
     */
    public function setErrorCategory($errorCategory)
    {
        $this->error_category = $errorCategory;

        return $this;
    }

    /**
     * Get errorCategory.
     *
     * @return string
     */
    public function getErrorCategory()
    {
        return $this->error_category;
    }

    /**
     * Set error_body
     *
     * @param string $errorBody
     * @return TrackedError
     */
    public function setErrorBody($errorBody)
    {
        $this->error_body = $errorBody;

        return $this;
    }

    /**
     * Get error_body
     *
     * @return string
     */
    public function getErrorBody()
    {
        return $this->error_body;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return TrackedError
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
     * @return TrackedError
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
     * Set trackedJob
     *
     * @param \ODR\AdminBundle\Entity\TrackedJob $trackedJob
     * @return TrackedError
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
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return TrackedError
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
}
