<?php

/**
 * Open Data Repository Data Publisher
 * FileHeaderInserter Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All of the details and explanations of this (filthy, honestly) FileHeaderInserter plugin can be
 * read at /src/ODR/OpenRepository/GraphBundle/Plugins/Base/FileHeaderInserterPlugin.php
 *
 * In there, there's a mention that users need to be able to force a rebuild of the headers from
 * Edit mode...this controller is what allows that to happen.
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\GraphBundle\Plugins\Base\FileHeaderInserterPlugin;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class FileHeaderInserterController extends ODRCustomController
{

    /**
     * Forces a rebuild of all filenames in this record (and its descendants) for any field that's
     * using the FileHeaderInserter plugin.
     *
     * @param integer $dr_id
     * @param Request $request
     * @return Response
     */
    public function rebuildallAction($dr_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var Logger $logger */
            $logger = $this->container->get('logger');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($dr_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            // Silently ensure this only works on top-level datarecords
            $datarecord = $datarecord->getGrandparent();
            $datatype = $datarecord->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $permissions_array = $permissions_service->getUserPermissionsArray($user);

            // Ensure the user is allowed to edit the datafield
            if ( !$permissions_service->canEditDatarecord($user, $datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------

            /** @var FileHeaderInserterPlugin $plugin_service */
            $plugin_service = $this->container->get('odr_plugins.base.file_header_inserter');

            // ----------------------------------------
            // Permissions are going to be a pain...easiest way is to filter cached arrays
            $dt_array = $database_info_service->getDatatypeArray($datatype->getId());  // do want links
            $dr_array = $datarecord_info_service->getDatarecordArray($datarecord->getId());  // do want links
            $permissions_service->filterByGroupPermissions($dt_array, $dr_array, $permissions_array);

            // Dig through what's left of the cached datatype array to find fields using the
            //  relevant plugins
            $relevant_plugins = array('odr_plugins.base.file_header_inserter' => 1);

            $df_list = array();
            foreach ($dt_array as $dt_id => $dt) {
                if ( !empty($dt['dataFields']) ) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        if ( !empty($df['renderPluginInstances']) ) {
                            foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                                $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                                if ( isset($relevant_plugins[$plugin_classname]) )
                                    $df_list[$df_id] = 1;
                            }
                        }
                    }
                }
            }

            // Dig through the cached datarecord array to get the ids of other entities...
            $drf_ids_list = array();
            foreach ($dr_array as $dr_id => $dr) {
                if ( !empty($dr['dataRecordFields']) ) {
                    foreach ($df_list as $df_id => $num) {
                        if ( isset($dr['dataRecordFields'][$df_id]) ) {
                            $drf = $dr['dataRecordFields'][$df_id];
                            $drf_id = $drf['id'];

                            // Only hydrate the entries that have files or images
                            if ( !empty($drf['file']) || !empty($drf['image']) )
                                $drf_ids_list[$drf_id] = 1;
                        }
                    }
                }
            }

            // ...so that we can run a database query to hydrate them
            $query = $em->createQuery(
               'SELECT drf
                FROM ODRAdminBundle:DataRecordFields drf
                WHERE drf.id IN (:drf_ids)
                AND drf.deletedAt IS NULL'
            )->setParameters( array('drf_ids' => array_keys($drf_ids_list)) );
            $drf_list = $query->getResult();
            /** @var DataRecordFields[] $drf_list */


            // ----------------------------------------
            $updated_datafield_list = $updated_datarecord_list = array();
            foreach ($drf_list as $drf_id => $drf) {
                // Shouldn't happen, but just in case...
                if ( is_null($drf) )
                    continue;

                $changes_made = $plugin_service->executeOnFileDatafield($drf, $user, true);

                // If any of the files in this drf entry were modified...
                if ( $changes_made ) {
                    // ...then they need to have events fired once we're done here
                    $df = $drf->getDataField();
                    $dr = $drf->getDataRecord();

                    $updated_datafield_list[$df->getId()] = $df;
                    $updated_datarecord_list[$dr->getId()] = $dr;
                }
            }

            // Only fire events if something changed
            if ( !empty($updated_datafield_list) || !empty($updated_datarecord_list) ) {
                // Now that the files/images are named correctly, flush the changes
                $em->flush();

                // Need to fire off one of these events for each datafield that got updated...
                foreach ($updated_datafield_list as $df_id => $df) {
                    try {
                        $event = new DatafieldModifiedEvent($df, $user);
                        $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }

                // Need to fire off one of these events for each datarecord that got updated...
                foreach ($updated_datarecord_list as $dr_id => $dr) {
                    try {
                        $event = new DatarecordModifiedEvent($datarecord, $user);
                        $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x36d9d650;
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
     * Forces a rebuild of the headers for all files uploaded to this drf entry.
     *
     * @param integer $dr_id
     * @param integer $df_id
     * @param Request $request
     *
     * @return Response
     */
    public function rebuildAction($dr_id, $df_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var Logger $logger */
            $logger = $this->container->get('logger');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($dr_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecordFields $drf */
            $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                array(
                    'dataRecord' => $datarecord->getId(),
                    'dataField' => $datafield->getId(),
                )
            );
            // If no files are uploaded, then this drf entry can legitimately be null


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            // Ensure the user is allowed to edit the datafield
            if ( !$permissions_service->canEditDatafield($user, $datafield) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Ensure the datafield is using the correct render plugin
            $plugin_classname = null;

            $dt_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links

            $dt = $dt_array[$datatype->getId()];
            if ( isset($dt['dataFields'][$datafield->getId()]) ) {
                $df = $dt['dataFields'][$datafield->getId()];
                if ( isset($df['renderPluginInstances']) ) {
                    foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.file_header_inserter' ) {
                            // Datafield is using the correct plugin...
                            $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                        }
                    }
                }
            }

            if ( is_null($plugin_classname) )
                throw new ODRBadRequestException('Datafield is not using the FileHeaderInserter Render Plugin');

            // Loading the service makes sense now that we know the datafield is using the plugin
            /** @var FileHeaderInserterPlugin $plugin_service */
            $plugin_service = $this->container->get('odr_plugins.base.file_header_inserter');


            // ----------------------------------------
            $change_made = false;
            if ( $drf == null ) {
                /* No drf exists, so there can't be any files uploaded here...nothing to do */
//                $logger->debug('No files to rebuild the file headers for in datafield '.$datafield->getId().' datarecord '.$datarecord->getId(), array(self::class, 'rebuildAction()'));
            }
            else {
                // ----------------------------------------
                // Technically can do this without a try/catch block, but no real reason not to use one
                try {
                    // Since there are at least three places where file headers need to be dealt with,
                    //  it's better to have the render plugin do all the work

                    // This particular place, however, is allowed to throw exceptions to notify
                    //  the user of issues...the events are not
                    $notify_user = true;
                    $change_made = $plugin_service->executeOnFileDatafield($drf, $user, $notify_user);
                }
                catch (\Exception $e) {
                    // Can't really display the error to the user yet, but can log it...
                    $logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'rebuildAction()', 'drf '.$drf->getId()));

                    // Since this isn't a background job or an event, however, the suspected reason
                    //  for the problem can get displayed to the user
                    throw $e;
                }
            }

            // Only fire events if something changed
            if ( $change_made ) {
                // Now that the files/images are named correctly, flush the changes
                $em->flush();

                // Fire off an event notifying that the modification of the datafield is done
                try {
                    $event = new DatafieldModifiedEvent($datafield, $user);
                    $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                }

                // Since a file got renamed, need to mark the record as updated
                try {
                    $event = new DatarecordModifiedEvent($datarecord, $user);
                    $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x17e250dc;
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
