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
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
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
            $source = 0x070b08bb;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var TrackedJobService $tj_service */
            $tj_service = $this->container->get('odr.tracked_job_service');

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === 'anon.') {
                throw new ODRForbiddenException();
            }
            else {
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];

                if ($job_type !== '')
                    $return['d'] = $tj_service->getJobDataByType($job_type, $datatype_permissions);
                else
                    $return['d'] = $tj_service->getJobDataById(intval($job_id), $datatype_permissions);
            }
        }
        catch (\Exception $e) {
            $source = 0xbafc9425;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
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
            // ----------------------------------------
            // Get necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var TrackedJobService $tj_service */
            $tj_service = $this->container->get('odr.tracked_job_service');


            // ----------------------------------------
            // If the tracked job exists, then the user is only allowed to delete this job if they have permissions to the datatype
            /** @var TrackedJob $tracked_job */
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($job_id);
            if ($tracked_job == null) {
                /* don't throw an error...carry on and ensure all TrackedError entries for this Job are deleted */
            }
            else {
                // Where the datatype id is stored depends on which type of job it is    TODO - fix that
                $job_type = $tracked_job->getJobType();
                $tmp = '';
                if ($job_type == 'migrate')
                    $tmp = $tracked_job->getRestrictions();
                else
                    $tmp = $tracked_job->getTargetEntity();

                $tmp = explode('_', $tmp);
                $datatype_id = $tmp[1];

                // Since child datatypes can't have the is_admin permission, and this job could be for a child datatype
                // Load this datatype's grandparent to access the is_admin permission
                // TODO - let child types have is_admin permission?
                $datatype_id = $dti_service->getGrandparentDatatypeId($datatype_id);

                // If the Datatype is deleted, there's no point to this job...skip the permissions check and delete the job
                /** @var DataType $datatype */
                $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
                if ($datatype == null) {
                    /* don't throw an error...carry on and ensure all TrackedError entries for this Job are deleted */
                }
                else {
                    // Since the Job and the Datatype still exist, check whether the user has permission to delete this Job
                    /** @var User $user */
                    $user = $this->container->get('security.token_storage')->getToken()->getUser();
                    $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                    $datatype_permissions = $user_permissions['datatypes'];

                    if ( !(isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_admin'])) )   // TODO - change from is_admin permission?
                        throw new ODRForbiddenException();
                }
            }

            // Delete the job and all of its associated entities
            $tj_service->deletejob($job_id);
        }
        catch (\Exception $e) {
            $source = 0x8501ab5c;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
