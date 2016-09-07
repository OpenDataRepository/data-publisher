<?php

/**
 * Open Data Repository Data Publisher
 * Job Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Job controller handles displaying progress of ongoing jobs
 * (and notifying users?) to users.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class JobController extends ODRCustomController
{

    /**
     * Displays all ongoing and completed jobs
     * 
     * @param Request $request
     *
     * @return Response
     */
    public function listAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {

            $jobs = array(
//                'recache' => 'Recaching',
                'migrate' => 'DataField Migration',
                'mass_edit' => 'Mass Updates',
//                'rebuild_thumbnails'
                'csv_import_validate' => 'CSV Validation',
                'csv_import' => 'CSV Imports',
                'csv_export' => 'CSV Exports',
//                'xml_import'
//                'xml_export'
            );

            // 
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Job:list.html.twig',
                    array(
                        'jobs' => $jobs,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x32268134 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Wrapper function for self::refreshJob()
     * 
     * @param string $job_type
     * @param integer $job_id
     * @param Request $request
     *
     * @return Response
     */
    public function refreshAction($job_type, $job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user !== 'anon.')
                $return['d'] = self::refreshJob($user, $job_type, intval($job_id), $request);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x32221345 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds and returns a JSON array of job data for a specific tracked_job type
     *
     * @param User $user
     * @param string $job_type
     * @param integer $job_id   Which TrackedJob to look at, or 0 to return all TrackedJobs
     * @param Request $request
     *
     * @return array
     */
    private function refreshJob($user, $job_type, $job_id, Request $request)
    {
        // Get necessary objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
        $repo_tracked_jobs = $em->getRepository('ODRAdminBundle:TrackedJob');

        $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
        $datatype_permissions = $user_permissions['datatypes'];

        $datatree_array = parent::getDatatreeArray($em);
        $parameters = array();

        if ( $job_type !== '' )
            $parameters['job_type'] = $job_type;
        /** @var TrackedJob[] $tracked_jobs */
        $tracked_jobs = $repo_tracked_jobs->findBy( $parameters );

        $jobs = array();
        if ($tracked_jobs !== null) {
            foreach ($tracked_jobs as $num => $tracked_job) {
                $job = array();
                $job['tracked_job_id'] = $tracked_job->getId();

                if ($job_id !== 0 && $job['tracked_job_id'] !== $job_id)
                    continue;

                // ----------------------------------------
                // Determine if user has privileges to view this job
                $target_entity = $tracked_job->getTargetEntity();
                $job_type = $tracked_job->getJobType();

                if ($job_type == 'migrate')
                    $target_entity = $tracked_job->getRestrictions();
                
                $tmp = explode('_', $target_entity);
                $datatype_id = $tmp[1];

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

                $top_level_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $datatype_id);
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

                        // TODO - better way of calculating this?
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

                    // TODO - able to delete jobs at anytime
                    // For now, only permit deletion of jobs when they're finished
                    if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_admin']) )
                        $can_delete = true;
                }

                // ----------------------------------------
                // Calculate/save data specific to certain jobs
                if ($job_type == 'recache') {
                    $tmp = explode('_', $tracked_job->getTargetEntity());
                    $datatype_id = $tmp[1];

                    /** @var DataType $datatype */
                    $datatype = $repo_datatype->find($datatype_id);
                    if ($datatype == null)
                        continue;

                    $job['revision'] = $datatype->getRevision();
                    $job['description'] = 'Recache of Datatype "'.$datatype->getShortName().'"';
                }
                else if ($job_type == 'csv_export') {
                    $tmp = explode('_', $tracked_job->getTargetEntity());
                    $datatype_id = $tmp[1];

                    /** @var DataType $datatype */
                    $datatype = $repo_datatype->find($datatype_id);
                    if ($datatype == null)
                        continue;

                    $job['description'] = 'CSV Export from Datatype "'.$datatype->getShortName().'"';
//                    $job['datatype_id'] = $datatype_id;
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
        }


        // DON'T JSON_ENCODE HERE
        if ($job_id == 0) {
            // Return data for all jobs found
            return $jobs;
        }
        else {
            // User wanted specific job, don't wrap it in an array
            return $jobs[0];
        }
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
     * Deletes a single TrackedJob entity
     * 
     * @param integer $job_id
     * @param Request $request
     *
     * @return Response
     */
    public function deletejobAction($job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            // --------------------
            // Get datatype_id from tracked job data
            /** @var TrackedJob $tracked_job */
            $tracked_job = $repo_tracked_job->find($job_id);
            if ($tracked_job !== null) {

                $job_type = $tracked_job->getJobType();
                $tmp = '';
                if ($job_type == 'migrate')
                    $tmp = $tracked_job->getRestrictions();
                else
                    $tmp = $tracked_job->getTargetEntity();

                $tmp = explode('_', $tmp);
                $datatype_id = $tmp[1];

                // TODO - let child types have is_admin permission?
                // Load the top-level parent, since the is_admin permission is used
                $datatree_array = parent::getDatatreeArray($em);
                $datatype_id = parent::getGrandparentDatatypeId($datatree_array, $datatype_id);


                // If the Datatype is deleted, there's no point to this job...skip the permissions check and delete it
                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                if ($datatype == null) {
                    return parent::deletedEntityError('DataType');
                }
                else {
                    // --------------------
                    // Determine user privileges
                    /** @var User $user */
                    $user = $this->container->get('security.token_storage')->getToken()->getUser();
                    $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                    $datatype_permissions = $user_permissions['datatypes'];

                    // Ensure user has permissions to be doing this
                    if ( !(isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_admin'])) )   // TODO - change from is_admin permission?
                        return parent::permissionDeniedError("admin");
                    // --------------------
                }
            }


            // Delete any errors associated with this job...if job doesn't exist, delete them anyways
            parent::ODR_deleteTrackedErrorsByJob($em, $job_id);

            // Delete the job itself
            if ($tracked_job !== null) {
                $em->remove($tracked_job);
                $em->flush();
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x32791345 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
