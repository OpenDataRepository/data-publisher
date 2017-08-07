<?php

/**
 * Open Data Repository Data Publisher
 * TrackedJob Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service abstracts interfacing with ODR's Tracked Job functionality.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\TrackedError;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Other
use Doctrine\ORM\EntityManager;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
use Symfony\Bridge\Monolog\Logger;


class TrackedJobService
{

    /**
     * @var EntityManager $em
     */
    private $em;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var array
     */
    private $valid_job_types = array(
//        'recache',
        'csv_export',
        'csv_import_validate',
        'csv_import',
//        'xml_import',
//        'xml_import_validate',
        'mass_edit',
        'migrate',
//        'rebuild_thumbnails',
    );


    /**
     * TrackedJobService constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatatypeInfoService $datatype_info_service
     * @param Logger $logger
     */
    public function __construct(EntityManager $entity_manager, DatatypeInfoService $datatype_info_service, Logger $logger)
    {
        $this->em = $entity_manager;
        $this->dti_service = $datatype_info_service;
        $this->logger = $logger;
    }


    /**
     * Returns a formatted array of useful data about the given tracked job
     *
     * @param integer $job_id
     * @param array $datatype_permissions  TODO - move this parameter to be dynamically loaded from a service?  but would need user id then...
     *
     * @return null|array
     */
    public function getJobDataById($job_id, $datatype_permissions)
    {
        // Ensure the tracked job exists first
        /** @var TrackedJob $tracked_job */
        $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')->find($job_id);
        if ($tracked_job == null)
            return null;

        // If it exists, convert its data into an array and return that
        $job_data = self::getJobData( array($job_id), $datatype_permissions );
        return $job_data;
    }


    /**
     * Returns a formatted array of useful data about all tracked jobs of the given job type
     *
     * @param string $job_type
     * @param array $datatype_permissions  TODO - move this parameter to be dynamically loaded from a service?  but would need user id then...
     *
     * @return null|array
     */
    public function getJobDataByType($job_type, $datatype_permissions)
    {
        // Ensure the job is of the correct type
        if ( !in_array($job_type, $this->valid_job_types) )
            return null;

        // Load all tracked jobs of this job type
        /** @var TrackedJob[] $tracked_jobs */
        $tracked_jobs = $this->em->getRepository('ODRAdminBundle:TrackedJob')->findBy( array('job_type' => $job_type) );
        if ($tracked_jobs == null)
            return null;

        // Convert their data into an array and return it
        $job_data = self::getJobData($tracked_jobs, $datatype_permissions);
        return $job_data;
    }


    /**
     * Builds and returns a JSON array of job data for a provided collection of TrackedJob entities
     *
     * @param TrackedJob[] $tracked_jobs
     * @param array $datatype_permissions
     *
     * @return array
     */
    private function getJobData($tracked_jobs, $datatype_permissions)
    {
        // Going to need these repositories
        $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
        $repo_datafield = $this->em->getRepository('ODRAdminBundle:DataFields');

        $jobs = array();
        foreach ($tracked_jobs as $tracked_job) {
            $job = array();
            $job['tracked_job_id'] = $tracked_job->getId();

            // ----------------------------------------
            // Going to need the datatype id for later...
            $target_entity = $tracked_job->getTargetEntity();
            $job_type = $tracked_job->getJobType();

            if ($job_type == 'migrate')
                $target_entity = $tracked_job->getRestrictions();

            $tmp = explode('_', $target_entity);
            $datatype_id = $tmp[1];

            // Don't show a user this job if they don't have permissions to view the datatype  TODO - feels like there could be a better way of handling this criteria
            if ( !(isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view'])) )
                continue;

            // Store whether user has permissions to delete this job
            $can_delete = false;


            // ----------------------------------------
            // Save data common to every job
            $created = $tracked_job->getCreated();
            $job['datatype_id'] = $datatype_id;
            $job['created_at'] = $created->format('Y-m-d H:i:s');
            $job['created_by'] = $tracked_job->getCreatedBy()->getUserString();
            $job['progress'] = array('total' => $tracked_job->getTotal(), 'current' => $tracked_job->getCurrent());
            $job['tracked_job_id'] = $tracked_job->getId();
            $job['eta'] = '...';

            $additional_data = json_decode( $tracked_job->getAdditionalData(), true );
            $job['description'] = $additional_data['description'];
            $job['can_delete'] = false;

            $top_level_datatype_id = $this->dti_service->getGrandparentDatatypeId($datatype_id);
            $job['top_level_datatype_id'] = $top_level_datatype_id;


            // ----------------------------------------
            if ( $tracked_job->getCompleted() == null || $tracked_job->getStarted() == null ) {
                // If job is in progress, calculate an ETA if possible
                $start = $tracked_job->getStarted();
                if ( $start == null ) {
                    $job['time_elapsed'] = '0s';
                    $job['eta'] = '...';
                }
                else {
                    $now = new \DateTime();

                    $interval = date_diff($start, $now);
                    $job['time_elapsed'] = self::formatInterval( $interval );

                    $seconds_elapsed = intval($interval->format("%a"))*86400 + intval($interval->format("%h"))*3600 + intval($interval->format("%i"))*60 + intval($interval->format("%s"));

                    // Estimate completion time the easy way
                    if ( intval($tracked_job->getCurrent()) !== 0 ) {
                        $eta = intval( $seconds_elapsed * intval($tracked_job->getTotal()) / intval($tracked_job->getCurrent()) );
                        $eta = $eta - $seconds_elapsed;

                        $curr_date = new \DateTime();
                        $new_date = new \DateTime();
                        $new_date = $new_date->add( new \DateInterval('PT'.$eta.'S') );

                        $interval = date_diff($curr_date, $new_date);
                        $job['eta'] = self::formatInterval( $interval );
                    }
                }
            }
            else {
                // If job is completed, calculate how long it took to finish
                $start = $tracked_job->getStarted();
                $end = $tracked_job->getCompleted();

                $interval = date_diff($start, $end);
                $job['time_elapsed'] = self::formatInterval( $interval );
                $job['eta'] = 'Done';

                // If the job is finished, and the user has permissions to this datatype, allow them to delete jobs
                if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_admin']) )
                    $can_delete = true;
            }

            // ----------------------------------------
            // Calculate/save data specific to certain jobs
            if ($job_type == 'csv_export') {
                $tmp = explode('_', $tracked_job->getTargetEntity());
                $datatype_id = $tmp[1];

                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                if ($datatype == null)
                    continue;

                $job['description'] = 'CSV Export from Datatype "'.$datatype->getShortName().'"';
//                $job['datatype_id'] = $datatype_id;
                $job['user_id'] = $tracked_job->getCreatedBy()->getId();

                if ($can_delete)
                    $job['can_delete'] = true;
            }
            else if ($job_type == 'csv_import_validate') {
                $tmp = explode('_', $tracked_job->getTargetEntity());
                $datatype_id = $tmp[1];

                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                if ($datatype == null)
                    continue;

                $job['description'] = 'Validating csv import data for DataType "'.$datatype->getShortName().'"';

                if ($can_delete)
                    $job['can_delete'] = true;
            }
            else if ($job_type == 'csv_import') {
                $tmp = explode('_', $tracked_job->getTargetEntity());
                $datatype_id = $tmp[1];

                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                if ($datatype == null)
                    continue;

                $job['description'] = 'Importing data into DataType "'.$datatype->getShortName().'"';

                if ($can_delete)
                    $job['can_delete'] = true;
            }
            else if ($job_type == 'mass_edit') {
                $tmp = explode('_', $tracked_job->getTargetEntity());
                $datatype_id = $tmp[1];

                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                if ($datatype == null)
                    continue;

                $job['description'] = 'Mass Edit of DataType "'.$datatype->getShortName().'"';

                if ($can_delete)
                    $job['can_delete'] = true;
            }
            else if ($job_type == 'migrate') {
                $tmp = explode('_', $tracked_job->getTargetEntity());
                $datafield_id = $tmp[1];

                $old_fieldtype = $new_fieldtype = '';
                if ( isset($additional_data['old_fieldtype']) )
                    $old_fieldtype = $additional_data['old_fieldtype'];
                if ( isset($additional_data['new_fieldtype']) )
                    $new_fieldtype = $additional_data['new_fieldtype'];

                /** @var DataFields $datafield */
                $datafield = $repo_datafield->find($datafield_id);
                if ($datafield == null)
                    continue;

                $job['description'] = 'Migration of DataField "'.$datafield->getFieldName().'" from "'.$old_fieldtype.'" to "'.$new_fieldtype.'"';

                if ($can_delete)
                    $job['can_delete'] = true;
            }

            $jobs[] = $job;
        }

        return $jobs;
    }


    /**
     * Utility function to turn PHP DateIntervals into strings more effectively
     *
     * @param \DateInterval $interval
     *
     * @return string
     */
    private function formatInterval(\DateInterval $interval)
    {
        $str = '';

        $days = intval( $interval->format("%a") );
        if ($days >= 1)
            $str .= $days.'d ';

        $hours = intval( $interval->format("%h") );
        if ($hours >= 1)
            $str .= $hours.'h ';

        $minutes = intval( $interval->format("%i") );
        if ($minutes >= 1)
            $str .= $minutes.'m ';

        $seconds = intval( $interval->format("%s") );
        if ($seconds >= 1)
            $str .= $seconds.'s ';

        if ($str == '')
            return '0s';
        else
            return substr($str, 0, strlen($str)-1);
    }


    /**
     * TODO - this needs a lot of thought...it's currently something of a hackjob
     *
     * Gets or creates a TrackedJob entity in the database for use by background processes
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user              The user to use if a new TrackedJob is to be created
     * @param string $job_type        A label used to indicate which type of job this is  e.g. 'recache', 'import', etc.
     * @param string $target_entity   Which entity this job is operating on
     * @param array $additional_data  Additional data related to the TrackedJob
     * @param string $restrictions    TODO - ...additional info/restrictions attached to the job
     * @param integer $total          ...how many pieces the job is broken up into?
     * @param boolean $reuse_existing TODO - multi-user concerns
     *
     * @return TrackedJob
     */
    public function getTrackedJob($user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing = false)
    {
        $tracked_job = null;

        // TODO - more flexible way of doing this?
        if ($reuse_existing)
            $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => $job_type, 'target_entity' => $target_entity) );
        else
            $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => $job_type, 'target_entity' => $target_entity, 'completed' => null) );

        if ($tracked_job == null) {
            $tracked_job = new TrackedJob();
            $tracked_job->setJobType($job_type);
            $tracked_job->setTargetEntity($target_entity);
            $tracked_job->setCreatedBy($user);
        }
        else {
            $tracked_job->setCreated( new \DateTime() );
        }

        $tracked_job->setStarted(null);

        $tracked_job->setAdditionalData( json_encode($additional_data) );
        $tracked_job->setRestrictions($restrictions);

        $tracked_job->setCompleted(null);
        $tracked_job->setCurrent(0);                // TODO - possible desynch, though haven't spotted one yet
        $tracked_job->setTotal($total);
        $this->em->persist($tracked_job);
        $this->em->flush();

//        $tracked_job->resetCurrent($em);          // TODO - potential fix for possible desynch mentioned earlier
        $this->em->refresh($tracked_job);
        return $tracked_job;
    }


    /**
     * Updates the progress of a specified tracked job
     *
     * @param integer $job_id
     * @param integer $progress
     *
     * @throws ODRNotFoundException
     */
    public function incrementJobProgress($job_id)
    {
        // Ensure the tracked job exists first
        /** @var TrackedJob $tracked_job */
        $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')->find($job_id);
        if ($tracked_job == null)
            throw new ODRNotFoundException('Tracked Job');

        $total = $tracked_job->getTotal();
        $count = $tracked_job->incrementCurrent($this->em);

        if ($count >= $total)
            $tracked_job->setCompleted( new \DateTime() );

        $this->em->persist($tracked_job);
        $this->em->flush();
        $this->em->refresh($tracked_job);
    }


    /**
     * Deletes a single TrackedJob entity
     *
     * @param integer $job_id
     */
    public function deleteJob($job_id)
    {
        // Delete any errors associated with this job...technically, doesn't matter whether the job exists or not
        self::deleteTrackedErrorsByJob($job_id);

        // Delete the job itself if it exists
        $repo_tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob');
        /** @var TrackedJob $tracked_job */
        $tracked_job = $repo_tracked_job->find($job_id);

        if ($tracked_job !== null) {
            $this->em->remove($tracked_job);
            $this->em->flush();
        }
    }


    /**
     * Gets an array of TrackedError entities for a specified TrackedJob
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $tracked_job_id
     *
     * @throws ODRNotFoundException
     *
     * @return array
     */
    public function getTrackedErrorsByJob($tracked_job_id)
    {
        $job_errors = array();

        $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);
        if ($tracked_job == null)
            throw new ODRNotFoundException('TrackedJob');

        /** @var TrackedError[] $tracked_errors */
        $tracked_errors = $this->em->getRepository('ODRAdminBundle:TrackedError')->findBy( array('trackedJob' => $tracked_job_id) );
        foreach ($tracked_errors as $error)
            $job_errors[ $error->getId() ] = array('error_level' => $error->getErrorLevel(), 'error_body' => json_decode( $error->getErrorBody(), true ));

        return $job_errors;
    }


    /**
     * Deletes all TrackedError entities associated with a specified TrackedJob
     *
     * @param integer $tracked_job_id
     *
     * @return integer
     */
    private function deleteTrackedErrorsByJob($tracked_job_id)
    {
        // Because there could potentially be thousands of errors for this TrackedJob, do a mass DQL deletion
        $query = $this->em->createQuery(
           'DELETE FROM ODRAdminBundle:TrackedError AS te
            WHERE te.trackedJob = :tracked_job'
        )->setParameters( array('tracked_job' => $tracked_job_id) );
        $rows = $query->execute();

        return $rows;
    }

}