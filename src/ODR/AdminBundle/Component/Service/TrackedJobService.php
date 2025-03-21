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
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\TrackedError;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class TrackedJobService
{

    /**
     * @var EntityManager $em
     */
    private $em;

    /**
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

    /**
     * @var PermissionsManagementService
     */
    private $permissions_service;

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
        'clone_theme',
//        'xml_import',
//        'xml_import_validate',
        'mass_edit',
        'migrate',
        'tag_rebuild',
        'clone_and_link',
//        'rebuild_thumbnails',
    );


    /**
     * TrackedJobService constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatatreeInfoService $datatree_info_service
     * @param PermissionsManagementService $permissions_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatatreeInfoService $datatree_info_service,
        PermissionsManagementService  $permissions_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->datatree_info_service = $datatree_info_service;
        $this->permissions_service = $permissions_service;
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

        if ( $tracked_job->getFailed() )
            throw new ODRException('Tracked Job '.$tracked_job->getId().' ('.$tracked_job->getJobType().') appears to be stalled, aborting');

        // If it exists, convert its data into an array and return that
        $job_data = self::getJobData( array($tracked_job), $datatype_permissions );
        return $job_data;
    }


    /**
     * Returns jobs for users that users may need to acknowledge.
     *
     * @param $user_id
     * @return array
     */
    public function getJobDataByUserId($user_id)
    {
        // Ensure the tracked job exists first
        /** @var TrackedJob $tracked_job */
        $qb = $this->em->createQueryBuilder();

        $qb->select('tj')
            ->from('ODRAdminBundle:TrackedJob', 'tj')
            ->where($qb->expr()->isNotNull('tj.completed'))
            ->andWhere('tj.createdBy = :user_id')
            ->andWhere($qb->expr()->isNull('tj.deletedAt'))
            ->andWhere($qb->expr()->in('tj.job_type', ':jobs_array'))
            ->orderBy('tj.created', 'DESC');

        $qb->setParameter('user_id', $user_id);
        $qb->setParameter('jobs_array', array(
            'csv_export',
            'csv_import',
            'csv_import_validate',
            'mass_edit',
            'migrate'
        ));
        $tracked_jobs = $qb->getQuery()->getArrayResult();

        if ($tracked_jobs == null)
            return array();

        return $tracked_jobs;
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

            if ($job_type == 'migrate' || $job_type == 'tag_rebuild')
                $target_entity = $tracked_job->getRestrictions();

            $tmp = explode('_', $target_entity);
            $datatype_id = $tmp[1];


            // ----------------------------------------
            /** @var ODRUser|null $user */
            $user = null;
            if ( !is_null($tracked_job->getCreatedBy()) )
                $user = $tracked_job->getCreatedBy();

            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                continue;

            if ( !$this->permissions_service->canViewDatatype($user, $datatype) )
                continue;

            // Store whether user has permissions to delete this job
            $can_delete = false;


            // ----------------------------------------
            // Save data common to every job
            $created = $tracked_job->getCreated();
            $job['datatype_id'] = $datatype_id;
            $job['created_at'] = $created->format('Y-m-d H:i:s');

            $job['created_by'] = 'anon';
            $job['user_id'] = 0;
            if ( !is_null($tracked_job->getCreatedBy()) ) {
                $job['created_by'] = $tracked_job->getCreatedBy()->getUserString();
                $job['user_id'] = $tracked_job->getCreatedBy()->getId();
            }

            $job['progress'] = array('total' => $tracked_job->getTotal(), 'current' => $tracked_job->getCurrent());
            $job['tracked_job_id'] = $tracked_job->getId();
            $job['eta'] = '...';

            $additional_data = $tracked_job->getAdditionalData();
            $job['description'] = '';
            if ( isset($additional_data['description']) )
                $job['description'] = $additional_data['description'];
            $job['can_delete'] = false;

            $top_level_datatype_id = $this->datatree_info_service->getGrandparentDatatypeId($datatype_id);
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
                if ( $this->permissions_service->isDatatypeAdmin($user, $datatype) )
                    $can_delete = true;
            }

            // ----------------------------------------
            // None of the jobs can be deleted if they're in progress...
            if ($can_delete)
                $job['can_delete'] = true;

            // Calculate/save data specific to certain jobs
            if ($job_type == 'csv_export') {
                $job['description'] = 'CSV Export from Datatype "'.$datatype->getShortName().'"';
            }
            else if ($job_type == 'csv_import_validate') {
                $job['description'] = 'Validating csv import data for DataType "'.$datatype->getShortName().'"';
            }
            else if ($job_type == 'csv_import') {
                $job['description'] = 'Importing data into DataType "'.$datatype->getShortName().'"';
            }
            else if ($job_type == 'mass_edit') {
                $job['description'] = 'Mass Edit of DataType "'.$datatype->getShortName().'"';
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
            }
            else if ($job_type == 'tag_rebuild') {
                $tmp = explode('_', $tracked_job->getTargetEntity());
                $datafield_id = $tmp[1];

                /** @var DataFields $datafield */
                $datafield = $repo_datafield->find($datafield_id);
                if ($datafield == null)
                    continue;

                $job['description'] = 'Tag Rebuild of Datafield '.$datafield_id.' "'.$datafield->getFieldName().'" for the Datatype "'.$datatype->getShortName().'"';
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
     * @param ODRUser $user           The user to use if a new TrackedJob is to be created
     * @param string $job_type        A label used to indicate which type of job this is  e.g. 'recache', 'import', etc.
     * @param string $target_entity   Which entity this job is operating on
     * @param array $additional_data  Additional data related to the TrackedJob
     * @param string $restrictions    TODO - ...additional info/restrictions attached to the job
     * @param integer $total          ...how many pieces the job is broken up into?
     * @param boolean $reuse_existing TODO - multi-user concerns
     *
     * @return TrackedJob
     */
    public function getTrackedJob(
        $user,
        $job_type,
        $target_entity,
        $additional_data,
        $restrictions,
        $total,
        $reuse_existing = false)
    {
        $tracked_job = null;

        // TODO - more flexible way of doing this?
        if ($reuse_existing)
            $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')
                ->findOneBy( array('job_type' => $job_type, 'target_entity' => $target_entity) );
        else
            $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')
                ->findOneBy(
                    array(
                        'job_type' => $job_type,
                        'target_entity' => $target_entity,
                        'completed' => null
                    )
                );

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

        $tracked_job->setAdditionalData($additional_data);
        $tracked_job->setRestrictions($restrictions);

        $tracked_job->setCompleted(null);
        $tracked_job->setCurrent(0);                // TODO - possible desynch, though haven't spotted one yet
        $tracked_job->setTotal($total);
        $tracked_job->setFailed(false);

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
     * @param integer $total
     *
     * @throws ODRNotFoundException
     */
    public function incrementJobProgress($job_id, $total = 0)
    {
        // Ensure the tracked job exists first
        /** @var TrackedJob $tracked_job */
        $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')->find($job_id);
        if ($tracked_job == null)
            throw new ODRNotFoundException('Tracked Job');

        if($total > 0) {
            // Set new total
            $tracked_job->setTotal($total);
            $this->em->persist($tracked_job);
            $this->em->flush();
            $this->em->refresh($tracked_job);
        }
        $count = $tracked_job->incrementCurrent($this->em);

        if ($count >= $tracked_job->getTotal()) {
            $tracked_job->setCompleted( new \DateTime() );

            $this->em->persist($tracked_job);
            $this->em->flush();
            $this->em->refresh($tracked_job);
        }
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


    /**
     * Returns the job_type of an in-progress background job if would conflict with a new job
     * created from the given data...returns null if there's no conflict
     *
     * @param array $job_data
     * @return string|null
     */
    public function getConflictingBackgroundJob($job_data)
    {
        // ----------------------------------------
        if ( !isset($job_data['job_type']) || !isset($job_data['target_entity']) )
            throw new ODRBadRequestException('getConflictingBackgroundJob() called with invalid array', 0x93308777);

        $new_job_type = $job_data['job_type'];
        $target_entity = $job_data['target_entity'];

        // Most jobs run on datatypes, but a few run on datafields or datarecords instead...
        $datafield_jobs = array(
            'migrate' => 1,
            'tag_rebuild' => 1,

            // The rest of these aren't "real" job, but have the potential for breaking in-progress jobs
            'delete_datafield' => 1,
            'delete_radio_option' => 1,
            'delete_tag' => 1,
            'rename_radio_option' => 1,
            'rename_tag' => 1,
        );
        $datarecord_jobs = array(
            'delete_datarecord' => 1,    // not a "real" job, but can break running jobs if it executes
        );

        $datatype = null;
        if ( $target_entity instanceof DataType
            && !isset($datafield_jobs[$new_job_type]) && !isset($datarecord_jobs[$new_job_type])
        ) {
            $datatype = $target_entity;
        }
        else if ( $target_entity instanceof DataFields && isset($datafield_jobs[$new_job_type]) ) {
            $datafield = $target_entity;
            $datatype = $datafield->getDataType();
        }
        else if ( $target_entity instanceof DataRecord && isset($datarecord_jobs[$new_job_type]) ) {
            $datarecord = $target_entity;
            $datatype = $datarecord->getDataType();
        }
        else {
            // Either a datafield job didn't have an associated datafield entity
            // or a datatype job didn't have an associated datatype entity
            throw new ODRBadRequestException('getConflictingBackgroundJob() called with invalid target_entity', 0x93308777);
        }

        // Ensure the checks are being done with the top-level datatype
        $grandparent_datatype = $datatype->getGrandparent();


        // ----------------------------------------
        // These jobs merely copy to new database entries...they can't interfere with any other
        //  background job
        $always_allowed_jobs = array(
            'clone_theme' => 1,
            'clone_and_link' => 1,
        );
        if ( isset($always_allowed_jobs[$new_job_type]) )
            return null;


        // These jobs might be allowed, but require some additional checks to be sure
        // All function names referenced in this array need to return boolean

        // The top-level key in this array indicates whether any job could be created if a job that
        //  matches the top-level key is already in progress...e.g.
        // Since $allowed_jobs['csv_import'] does not exist, that means no new job can be created
        //  when a 'csv_import' job is currently in progress for the datatype
        // Since $allowed_jobs['migrate']['migrate'] exists, this means that a new 'migrate'
        //  job could potentially be allowed even if a 'migrate' job is already in progress...but ODR
        //  should call the given function name "self::requireDifferentDatafield()" to verify

        // If $allowed_jobs['migrate']['mass_edit'] existed, then that would indicate a new
        //  'mass_edit' job could be created even if a 'migrate' job was in progress...etc
        // The above is a fictional example...most of the jobs shouldn't be started when another
        //  job is already running, because they can modify the same datarecord/datafield pairs, or
        //  otherwise rely on values not changing

        $allowed_jobs = array(
            'migrate' => array(
                // New migrations are allowed, but only when the datafield isn't already being migrated
                'migrate' => 'self::requireDifferentDatafield',
                // Shouldn't migrate a datafield that's being rebuilt
                'tag_rebuild' => 'self::requireDifferentDatafield',

                // Renaming is allowed, but only when the datafield isn't already being migrated
                'rename_radio_option' => 'self::requireDifferentDatafield',
                'rename_tag' => 'self::requireDifferentDatafield',
                // Deleting is allowed, but only when the datafield isn't already being migrated
                'delete_radio_option' => 'self::requireDifferentDatafield',
                'delete_tag' => 'self::requireDifferentDatafield',
            ),

            'tag_rebuild' => array(
                // New migrations are allowed, but only when the datafield isn't already being migrated
                'migrate' => 'self::requireDifferentDatafield',
                // Should be able to run more than one of these jobs at a time so long as they're
                //  operating on different fields
                'tag_rebuild' => 'self::requireDifferentDatafield',

                // Doing stuff with radio options shouldn't affect rebuilding a tag list
                'rename_radio_option' => 'self::alwaysAllowed',
                'delete_radio_option' => 'self::alwaysAllowed',
                // Renaming tags should also work
                'rename_tag' => 'self::alwaysAllowed',
                // Deleting a tag should only be allowed when it belongs to a different datafield
                'delete_tag' => 'self::requireDifferentDatafield',
            ),

            'csv_export' => array(
                // Since export filenames include which tracked job they're for, a user can run
                //  multiple exports at the same time
                'csv_export' => 'self::alwaysAllowed',

                // None of the other jobs are allowed, since changing/deleting stuff in the middle
                //  of exporting is bad
            ),
            'mass_edit' => array(
                // Renaming these has no effect on mass edit doing any selecting/deselecting
                'rename_radio_option' => 'self::alwaysAllowed',
                'rename_tag' => 'self::alwaysAllowed',

                // None of the other jobs are allowed, since they're changing stuff mass edit needs
            ),

            // Only allowed to have one csv import job running at a time
//            'csv_import' => array(),
//            'csv_import_validate' => array(),
        );


        // ----------------------------------------
        // Need a list of any job that's currently in progress
        $current_jobs = $this->em->getRepository('ODRAdminBundle:TrackedJob')->findBy(
            array('completed' => null)
        );
        /** @var TrackedJob[] $current_jobs */

        // If no jobs are currently running, then always able to create a new job
        if ( empty($current_jobs) )
            return null;

        foreach ($current_jobs as $tj) {
            // Everything depends on which type of job is currently running...
            $current_job_type = $tj->getJobType();

            // If this is one of the jobs which are always allowed, skip to the next job
            if ( isset($always_allowed_jobs[$current_job_type]) )
                continue;

            // ...and which datatype it's currently modifying
            $affected_datatype_id = null;
            if ( $current_job_type === 'migrate' || $current_job_type === 'tag_rebuild' ) {
                $pieces = explode('_', $tj->getRestrictions());
                $affected_datatype_id = intval($pieces[1]);
            }
            else {
                $pieces = explode('_', $tj->getTargetEntity());
                $affected_datatype_id = intval($pieces[1]);
            }

            // Determine whether this in-progress job is affecting the same datatype as the new job...
            if ( $grandparent_datatype->getId() !== $affected_datatype_id ) {
                // ...it's not, so the new job can't interfere with this in-progress job. Still need
                //  to check the rest of the in-progress jobs though...
            }
            else {
                // ...the new job will be affecting the same datatype as this in-progress job.

                if ( !isset($allowed_jobs[$current_job_type]) ) {
                    // The current job_type doesn't allow the creation of any other jobs while it's
                    //  running
                    return $current_job_type;
                }
                else if ( !isset($allowed_jobs[$current_job_type][$new_job_type]) ) {
                    // The current job_type doesn't allow a job of the given job_type to be started
                    //  while it's running...a different new job_type could be allowed though
                    return $current_job_type;
                }
                else {
                    // The current job_type might allow the creation of the new job_type.  Call the
                    //  function defined in $allowed_jobs to determine though...
                    $can_start_job = call_user_func($allowed_jobs[$current_job_type][$new_job_type], $job_data, $tj);

                    // ...if it returned a string, then the current job will interfere with the new
                    //  job...should stop looking through the list of current jobs
                    if ( !is_null($can_start_job) )
                        return $current_job_type;

                    // ...otherwise, this current job doesn't have an issue with the new job...need
                    //  to check all the other current jobs though, to be sure they agree
                }
            }
        }

        // If this point is reached, then no conflicting job was found
        return null;
    }


    /**
     * Called by self::canStartJob() to determine whether the job described by $job_data is being
     * run on the same datafield as $tracked_job
     *
     * @param array $job_data
     * @param TrackedJob $tracked_job
     * @return string|null
     */
    private function requireDifferentDatafield($job_data, $tracked_job)
    {
        // Determine which datafield the currently running TrackedJob is migrating...
        $pieces = explode('_', $tracked_job->getTargetEntity());
        $affected_datafield_id = intval($pieces[1]);

        // Determine which datafield the user wants to migrate...
        /** @var DataFields $new_migrate_df */
        $new_migrate_df = $job_data['target_entity'];

        // Prevent the user from starting another migrate job on the same datafield
        if ( $new_migrate_df->getId() === $affected_datafield_id )
            return $tracked_job->getJobType();
        else
            return null;
    }


    /**
     * Called by self::canStartJob(), but returns null so the job described by $job_data is always
     * allowed to run.
     *
     * @param array $job_data
     * @param TrackedJob $tracked_job
     * @return null
     */
    private function alwaysAllowed($job_data, $tracked_job)
    {
        // By returning null, the job described by $job_data will always be permitted
        return null;
    }
}
