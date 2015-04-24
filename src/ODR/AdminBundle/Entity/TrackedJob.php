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
    private $description;

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
    private $completed;

    /**
     * @var \DateTime
     */
    private $created;

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
     * Set description
     *
     * @param string $description
     * @return TrackedJob
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get description
     *
     * @return string 
     */
    public function getDescription()
    {
        return $this->description;
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
     * Increment current
     * Gets mysql to directly update the 'current' field, bypassing the caching mechanisms in the persist()/flush()/refresh() call chain in an attempt to ensure synchronization
     *
     */
    public function incrementCurrent(\Doctrine\ORM\EntityManager $em)
    {
        $query =
           'UPDATE odr_tracked_job
            SET current = current + 1
            WHERE id = :id';
        $params = array('id' => $this->id);
        $conn = $em->getConnection();
        $rowsAffected = $conn->executeUpdate($query, $params);

        // Grab what the new value is, so it can be returned
        $query = $em->createQuery(
           'SELECT tj.current AS current
            FROM ODRAdminBundle:TrackedJob AS tj
            WHERE tj.id = :id'
        )->setParameters($params);
        $results = $query->getArrayResult();

        return $results[0]['current'];
    }
}
