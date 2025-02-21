<?php

/**
 * Open Data Repository Data Publisher
 * TrackedJob Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The TrackedJob Entity is automatically generated from
 * ./Resources/config/doctrine/TrackedJob.orm.yml
 *
 */


namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * TrackedJob
 */
class TrackedJob
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $job_type;

    /**
     * @var string
     */
    private $target_entity;

    /**
     * @var string
     */
    private $additional_data;

    /**
     * @var string
     */
    private $restrictions;

    /**
     * @var integer
     */
    private $current;

    /**
     * @var integer
     */
    private $total;

    /**
     * @var \DateTime
     */
    private $started;

    /**
     * @var \DateTime
     */
    private $completed;

    /**
     * @var bool
     */
    private $failed;

    /**
     * @var \DateTime|null
     */
    private $viewed;

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
    private $trackedCSVExport;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $trackedError;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->trackedCSVExport = new \Doctrine\Common\Collections\ArrayCollection();
        $this->trackedError = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set job_type
     *
     * @param string $jobType
     * @return TrackedJob
     */
    public function setJobType($jobType)
    {
        $this->job_type = $jobType;

        return $this;
    }

    /**
     * Get job_type
     *
     * @return string 
     */
    public function getJobType()
    {
        return $this->job_type;
    }

    /**
     * Set target_entity
     *
     * @param string $targetEntity
     * @return TrackedJob
     */
    public function setTargetEntity($targetEntity)
    {
        $this->target_entity = $targetEntity;

        return $this;
    }

    /**
     * Get target_entity
     *
     * @return string 
     */
    public function getTargetEntity()
    {
        return $this->target_entity;
    }

    /**
     * Set additional_data
     *
     * @param array $additionalData
     * @return TrackedJob
     */
    public function setAdditionalData($additionalData)
    {
        $this->additional_data = json_encode( $additionalData );

        return $this;
    }

    /**
     * Get additional_data
     *
     * @return array
     */
    public function getAdditionalData()
    {
        return json_decode( $this->additional_data, true );
    }

    /**
     * Set restrictions
     *
     * @param string $restrictions
     * @return TrackedJob
     */
    public function setRestrictions($restrictions)
    {
        $this->restrictions = $restrictions;

        return $this;
    }

    /**
     * Get restrictions
     *
     * @return string 
     */
    public function getRestrictions()
    {
        return $this->restrictions;
    }

    /**
     * Set current
     *
     * @param integer $current
     * @return TrackedJob
     */
    public function setCurrent($current)
    {
        $this->current = $current;

        return $this;
    }

    /**
     * Get current
     *
     * @return integer 
     */
    public function getCurrent()
    {
        return $this->current;
    }

    /**
     * Set total
     *
     * @param integer $total
     * @return TrackedJob
     */
    public function setTotal($total)
    {
        $this->total = $total;

        return $this;
    }

    /**
     * Get total
     *
     * @return integer 
     */
    public function getTotal()
    {
        return $this->total;
    }

    /**
     * Set started
     *
     * @param \DateTime $started
     * @return TrackedJob
     */
    public function setStarted($started)
    {
        $this->started = $started;

        return $this;
    }

    /**
     * Get started
     *
     * @return \DateTime
     */
    public function getStarted()
    {
        return $this->started;
    }

    /**
     * Set completed
     *
     * @param \DateTime $completed
     * @return TrackedJob
     */
    public function setCompleted($completed)
    {
        $this->completed = $completed;

        return $this;
    }

    /**
     * Get completed
     *
     * @return \DateTime 
     */
    public function getCompleted()
    {
        return $this->completed;
    }

    /**
     * Set failed.
     *
     * @param bool $failed
     *
     * @return TrackedJob
     */
    public function setFailed($failed)
    {
        $this->failed = $failed;

        return $this;
    }

    /**
     * Get failed.
     *
     * @return bool
     */
    public function getFailed()
    {
        return $this->failed;
    }

    /**
     * Set viewed.
     *
     * @param \DateTime|null $viewed
     *
     * @return TrackedJob
     */
    public function setViewed($viewed = null)
    {
        $this->viewed = $viewed;

        return $this;
    }

    /**
     * Get viewed.
     *
     * @return \DateTime|null
     */
    public function getViewed()
    {
        return $this->viewed;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return TrackedJob
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
     * @return TrackedJob
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
     * Add trackedCSVExport
     *
     * @param \ODR\AdminBundle\Entity\TrackedCSVExport $trackedCSVExport
     * @return TrackedJob
     */
    public function addTrackedCSVExport(\ODR\AdminBundle\Entity\TrackedCSVExport $trackedCSVExport)
    {
        $this->trackedCSVExport[] = $trackedCSVExport;

        return $this;
    }

    /**
     * Remove trackedCSVExport
     *
     * @param \ODR\AdminBundle\Entity\TrackedCSVExport $trackedCSVExport
     */
    public function removeTrackedCSVExport(\ODR\AdminBundle\Entity\TrackedCSVExport $trackedCSVExport)
    {
        $this->trackedCSVExport->removeElement($trackedCSVExport);
    }

    /**
     * Get trackedCSVExport
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getTrackedCSVExport()
    {
        return $this->trackedCSVExport;
    }

    /**
     * Add trackedError
     *
     * @param \ODR\AdminBundle\Entity\TrackedError $trackedError
     * @return TrackedJob
     */
    public function addTrackedError(\ODR\AdminBundle\Entity\TrackedError $trackedError)
    {
        $this->trackedError[] = $trackedError;

        return $this;
    }

    /**
     * Remove trackedError
     *
     * @param \ODR\AdminBundle\Entity\TrackedError $trackedError
     */
    public function removeTrackedError(\ODR\AdminBundle\Entity\TrackedError $trackedError)
    {
        $this->trackedError->removeElement($trackedError);
    }

    /**
     * Get trackedError
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getTrackedError()
    {
        return $this->trackedError;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return TrackedJob
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
     * Converts object to a simple array
     *
     * @return array
     */
    public function toArray() {
        $tracked_job = array();

        $tracked_job['id'] = $this->getId();
        $tracked_job['job_type'] = $this->getJobType();
        $tracked_job['total'] = $this->getTotal();
        $tracked_job['current'] = $this->getCurrent();
        $tracked_job['completed'] = $this->getCompleted();
        $tracked_job['started'] = $this->getStarted();
        $tracked_job['viewed'] = $this->getViewed();
        $tracked_job['additional_data'] = $this->getAdditionalData();
        $tracked_job['target_entity'] = $this->getTargetEntity();

        return $tracked_job;
    }

    /**
     * Increment current
     * Gets mysql to directly update the 'current' field, bypassing the caching mechanisms in the persist()/flush()/refresh() call chain in an attempt to ensure synchronization
     *
     * @return integer The value of the 'current' field after the increment
     */
    public function incrementCurrent(\Doctrine\ORM\EntityManager $em)
    {
        // Directly update the 'current' field...
        $query =
           'UPDATE odr_tracked_job
            SET current = current + 1
            WHERE id = :id';
        $params = array('id' => $this->id);
        $conn = $em->getConnection();
        $rowsAffected = $conn->executeUpdate($query, $params);


        // Grab what the new value is, so it can be returned
        $query = $em->createQuery(
           'SELECT tj.current AS current, tj.started AS started
            FROM ODRAdminBundle:TrackedJob AS tj
            WHERE tj.id = :id'
        )->setParameters($params);
        $results = $query->getArrayResult();

        $curr_value = $results[0]['current'];
        $start_time = $results[0]['started'];

        // If job hasn't been marked as 'started' yet, do that
        if ($start_time == null) {
            $start_time = new \DateTime();
            $query =
               'UPDATE odr_tracked_job
                SET started = :datetime
                WHERE id = :id';
            $params = array('id' => $this->id, 'datetime' => $start_time->format('Y-m-d H:i:s') );
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);
        }

        return $curr_value;
    }
}
