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

// Entites
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\TrackedCSVExport;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\RadioOption;
use ODR\AdminBundle\Entity\RadioSelection;
// Forms
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\ShortVarcharForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;


class JobController extends ODRCustomController
{

    /**
     * Displays all ongoing and completed jobs
     * 
     * @param Request $request
     *
     * @return TODO
     */
    public function listAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {

            $jobs = array(
                'recache' => 'Recaching',
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
     * Wrapper function to...TODO
     * 
     * @param string $job_type 
     * @param Request $request
     *
     * @return TODO
     */
    public function refreshAction($job_type, $job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $user = $this->container->get('security.context')->getToken()->getUser();
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
     * @param string $job_type 
     * @param integer $job_id Which TrackedJob to look at, or 0 to return all TrackedJobs
     *
     * @return TODO
     */
    private function refreshJob($user, $job_type, $job_id, Request $request)
    {
        // Get necessary objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_tracked_jobs = $em->getRepository('ODRAdminBundle:TrackedJob');
        $user_permissions = parent::getPermissionsArray($user->getId(), $request);


        $tracked_jobs = array();
        $parameters = array();

        if ( $job_type !== '' )
            $parameters['job_type'] = $job_type;
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

                if ( !(isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['view']) && $user_permissions[$datatype_id]['view'] == 1) )
                    continue;

                // ----------------------------------------
                // Save data common to every job
                $created = $tracked_job->getCreated();
                $job['created_at'] = $created->format('Y-m-d H:i:s');
                $job['created_by'] = $tracked_job->getCreatedBy()->getUserExtend()->getFirstName().' '.$tracked_job->getCreatedBy()->getUserExtend()->getLastName();
                $job['progress'] = array('total' => $tracked_job->getTotal(), 'current' => $tracked_job->getCurrent());
                $job['tracked_job_id'] = $tracked_job->getId();
                $job['eta'] = '...';

                $additional_data = json_decode( $tracked_job->getAdditionalData(), true );
                $job['description'] = $additional_data['description'];

                // ----------------------------------------
                if ( $tracked_job->getCompleted() == null ) {
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
                }

                // ----------------------------------------
                // Calculate/save data specific to certain jobs
                if ($job_type == 'recache') {
                    $tmp = explode('_', $tracked_job->getTargetEntity());
                    $datatype_id = $tmp[1];

                    $datatype = $repo_datatype->find($datatype_id);
                    $job['revision'] = $datatype->getRevision();
                    $job['description'] = 'Recache of Datatype "'.$datatype->getShortName().'"';
                }
                else if ($job_type == 'csv_export') {
                    $tmp = explode('_', $tracked_job->getTargetEntity());
                    $datatype_id = $tmp[1];

                    $datatype = $repo_datatype->find($datatype_id);
                    $job['description'] = 'CSV Export from Datatype "'.$datatype->getShortName().'"';
//                    $job['datatype_id'] = $datatype_id;
                    $job['user_id'] = $tracked_job->getCreatedBy()->getId();
                }
                else if ($job_type == 'csv_import_validate') {
                    $tmp = explode('_', $tracked_job->getTargetEntity());
                    $datatype_id = $tmp[1];

                    $datatype = $repo_datatype->find($datatype_id);
                    $job['description'] = 'Validating csv import data for DataType "'.$datatype->getShortName().'"';
                }
                else if ($job_type == 'csv_import') {
                    $tmp = explode('_', $tracked_job->getTargetEntity());
                    $datatype_id = $tmp[1];

                    $datatype = $repo_datatype->find($datatype_id);
                    $job['description'] = 'Importing data into DataType "'.$datatype->getShortName().'"';
                }
                else if ($job_type == 'mass_edit') {
                    $tmp = explode('_', $tracked_job->getTargetEntity());
                    $datatype_id = $tmp[1];

                    $datatype = $repo_datatype->find($datatype_id);
                    $job['description'] = 'Mass Edit of DataType "'.$datatype->getShortName().'"';
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
     * TODO - improve this
     *
     * @param DateInterval $interval
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
     * Builds and returns a JSON array of any TrackedError entities that exist for a given TrackedJob
     * 
     * @param integer $job_id Which TrackedJob to look at
     * @param Request $request
     *
     * @return TODO
     */
    public function getjoberrorsAction($job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            // --------------------
            // Get datatype from job
            $tracked_job = $repo_tracked_job->find($job_id);
            if ($tracked_job == null)
                return parent::deletedEntityError('Job');

            $job_type = $tracked_job->getJobType();
            $tmp = '';
            if ($job_type == 'migrate')
                $tmp = $tracked_job->getRestrictions();
            else
                $tmp = $tracked_job->getTargetEntity();

            $tmp = explode('_', $tmp);
            $datatype_id = $tmp[1];

            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Job');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $return['d'] = parent::ODR_getTrackedErrorArray($em, $job_id);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x34272215 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes all TrackedErrors for a given TrackedJob
     * 
     * @param integer $job_id
     * @param Request $request
     *
     * @return TODO
     */
    public function deletejoberrorsAction($job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            // --------------------
            // Get datatype from job
            $tracked_job = $repo_tracked_job->find($job_id);
            // Don't worry about a deleted TrackedJob...just delete any TrackedErrors for this job if they somehow still exist
            if ($tracked_job == null) {
//                return parent::deletedEntityError('Job');
                parent::ODR_deleteTrackedErrorsByJob($em, $job_id);
                return;
            }

            $job_type = $tracked_job->getJobType();
            $tmp = '';
            if ($job_type == 'migrate') 
                $tmp = $tracked_job->getRestrictions();
            else 
                $tmp = $tracked_job->getTargetEntity();

            $tmp = explode('_', $tmp);
            $datatype_id = $tmp[1];

            $datatype = $repo_datatype->find($datatype_id);
            // If the Datatype is deleted, there's no point to this job...delete it
            if ($datatype == null) {
//                return parent::deletedEntityError('Job');
                return self::deletejobAction($job_id, $request);
            }


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            
            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'admin' ])) )   // TODO - probably needs to be changed...
                return parent::permissionDeniedError("admin");
            // --------------------

            // Delete all errors attached to this job
            parent::ODR_deleteTrackedErrorsByJob($em, $job_id);
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


    /**
     * Deletes a single TrackedJob entity
     * 
     * @param string $job_type 
     * @param Request $request
     *
     * @return TODO
     */
    public function deletejobAction($job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            // --------------------
            // Get datatype from job
            $tracked_job = $repo_tracked_job->find($job_id);
            // Don't worry about a deleted TrackedJob...just delete any TrackedErrors for this job if they somehow still exist
            if ($tracked_job == null) {
//                return parent::deletedEntityError('Job');
                parent::ODR_deleteTrackedErrorsByJob($em, $job_id);
                return;
            }

            $job_type = $tracked_job->getJobType();
            $tmp = '';
            if ($job_type == 'migrate')
                $tmp = $tracked_job->getRestrictions();
            else
                $tmp = $tracked_job->getTargetEntity();

            $tmp = explode('_', $tmp);
            $datatype_id = $tmp[1];

            $datatype = $repo_datatype->find($datatype_id);
            // If the Datatype is deleted, there's no point to this job...skip the permissions check and delete it
            if ($datatype == null) {
//                return parent::deletedEntityError('Job');
                /* do nothing */
            }
            else {
                // --------------------
                // Determine user privileges
                $user = $this->container->get('security.context')->getToken()->getUser();
                $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            
                // Ensure user has permissions to be doing this
                if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'admin' ])) )   // TODO - probably needs to be changed...
                    return parent::permissionDeniedError("admin");
                // --------------------
            }

            // Delete any errors associated with this job
            parent::ODR_deleteTrackedErrorsByJob($em, $job_id);

            // Delete the job itself
            $tracked_job = $repo_tracked_job->find($job_id);
            if ($tracked_job !== null) {
                $em->remove($tracked_job);
                $em->flush();
            }

            // TODO 
            $return['d'] = '';
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


    /**
     * Deletes a single TrackedError entity...
     * 
     * @param string $job_type 
     * @param Request $request
     *
     * @return TODO
     */
/*
    public function deletetrackederrorAction($error_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_tracked_error = $em->getRepository('ODRAdminBundle:TrackedError');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            
            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'admin' ])) )    // TODO - probably needs to be changed
                return parent::permissionDeniedError("admin");
            // --------------------

            $tracked_error = $repo_tracked_error->find($error_id);
            if ($tracked_error !== null) {
                $em->remove($tracked_error);
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
*/

}
