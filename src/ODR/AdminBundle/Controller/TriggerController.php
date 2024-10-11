<?php

/**
 * Open Data Repository Data Publisher
 * Trigger Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * There are certain pieces of ODR functionality which are convenient to trigger at times without
 * having to actually make a change to trigger them...this controller is a reasonable place to
 * store them.
 *
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatatypeImportedEvent;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
// Symfony
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Templating\EngineInterface;


class TriggerController extends ODRCustomController
{

    /**
     * Renders a page to select trigger actions for a given datatype.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function homeAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ($grandparent_datatype->getDeletedAt() !== null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            // TODO - probably could relax this to the datatype admin
            // ----------------------------------------


            $dt_array = $database_info_service->getDatatypeArray($grandparent_datatype->getId());  // don't want links
            $stacked_dt_array = $database_info_service->stackDatatypeArray($dt_array, $grandparent_datatype->getId());

            // ----------------------------------------
            // Render the required version of the page
            $html = $templating->render(
                'ODRAdminBundle:Trigger:home.html.twig',
                array(
                    'stacked_datatype_array' => $stacked_dt_array,
                )
            );

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $html,
            );

        }
        catch (\Exception $e) {
            $source = 0x347ebf12;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a complete wipe of the cache entries for a specific datatype.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function triggerdatatypecachewipeAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            // TODO - probably could relax this to the datatype admin
            // ----------------------------------------


            // Easiest way to trigger a complete cache wipe for a given datatype is to fire off
            //  a datatype imported event
            try {
                $event = new DatatypeImportedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeImportedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

        }
        catch (\Exception $e) {
            $source = 0x2e51f88a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a complete wipe of the cache entries for a specific datafield.
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function triggerdatafieldcachewipeAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataType')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');
            if ( $datafield->getDataType()->getDeletedAt() !== null )
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            // TODO - probably could relax this to the datatype admin
            // ----------------------------------------


            // Easiest way to trigger a cache wipe for everything related to a datafield is to
            //  fire off a datafield modified event
            try {
                $event = new DatafieldModifiedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

        }
        catch (\Exception $e) {
            $source = 0xd9bfea5b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a rebuild of a specific tag field...need to ensure that the parents of selected tags
     * are themselves selected.
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function triggertagtreerebuildAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');
            if ( $datafield->getFieldType()->getTypeClass() !== 'Tag' )
                throw new ODRBadRequestException('Invalid Datafield');
            if ( !$datafield->getTagsAllowMultipleLevels() )
                throw new ODRBadRequestException('Tag Field does not need rebuilding');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');

            $top_level_datatype = $datatype->getGrandparent();
            if ( $top_level_datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Grandparent Datatype');


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            // TODO - probably could relax this to the datatype admin
            // ----------------------------------------

            throw new ODRException('do not continue');

            // ----------------------------------------
            // Check whether any jobs that are currently running would interfere with a newly
            //  created 'tag_rebuild' job for this datatype
            $new_job_data = array(
                'job_type' => 'tag_rebuild',
                'target_entity' => $datafield,
            );

            $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to start a new TagRebuild job, as it would interfere with an already running '.$conflicting_job.' job');


            // ----------------------------------------
            // Get a list of all datarecords with this datafield
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord dr
                WHERE dr.dataType = :datatype_id
                AND dr.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId()) );
            $results = $query->getArrayResult();

            if ( !empty($results) ) {
                // Not entirely sure how many datarecords can be handled at once
                $records_per_job = 100;

                // Create a tracked job for this...
                $job_type = 'tag_rebuild';
                $target_entity = 'datafield_'.$datafield_id;
                $additional_data = array('description' => 'Tag Rebuild of Datafield '.$datafield_id.', DataType '.$datatype->getId());
                $restrictions = 'datatype_'.$top_level_datatype->getId();

                // TODO - test restrictions
                // TODO - create commands

                $total = intval( count($results) / $records_per_job );
                if ( $total * $records_per_job < count($results) )
                    $total += 1;

                $reuse_existing = false;
//$reuse_existing = true;

                $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
                $tracked_job_id = $tracked_job->getId();


                // Going to also need these values
                $api_key = $this->container->getParameter('beanstalk_api_key');
                $pheanstalk = $this->get('pheanstalk');
                $redis_prefix = $this->container->getParameter('memcached_key_prefix');    // debug purposes only
                $url = $this->generateUrl('odr_tag_tree_rebuild_worker', array(), UrlGeneratorInterface::ABSOLUTE_URL);

                $priority = 1024;   // should be roughly default priority
                $delay = 1;

                $datarecord_list = array();
                $count = 0;
                foreach ($results as $result) {
                    $dr_id = $result["dr_id"];
                    $datarecord_list[] = $dr_id;

                    if ( ($count % $records_per_job) === 0) {

                        $priority = 1024;   // should be roughly default priority
                        $payload = array(
                            "job_type" => 'tag_rebuild',
                            "tracked_job_id" => $tracked_job_id,

                            "user_id" => $user->getId(),
                            "datarecord_list" => implode(',', $datarecord_list),
                            "datafield_id" => $datafield_id,

                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        );
                        $payload = json_encode($payload);

                        $pheanstalk->useTube('tag_rebuild')->put($payload, $priority, $delay);

                        // Reset for next pile of datarecords
                        $datarecord_list = array();
                    }
                }

                // Update any remaining datarecords
                if ( !empty($datarecord_list) ) {
                    $payload = array(
                        "job_type" => 'tag_rebuild',
                        "tracked_job_id" => $tracked_job_id,

                        "user_id" => $user->getId(),
                        "datarecord_list" => implode(',', $datarecord_list),
                        "datafield_id" => $datafield_id,

                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "url" => $url,
                        "api_key" => $api_key,
                    );
                    $payload = json_encode($payload);

                    $pheanstalk->useTube('tag_rebuild')->put($payload, $priority, $delay);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x0633677b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
