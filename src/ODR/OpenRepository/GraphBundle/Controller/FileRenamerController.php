<?php

/**
 * Open Data Repository Data Publisher
 * FileRenamer Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All of the details and explanations of this (filthy, honestly) FileRenamer plugin can be read at
 * /src/ODR/OpenRepository/GraphBundle/Plugins/Base/FileRenamerPlugin.php
 *
 * In there, there's a mention that users need to be able to force a rebuild of the filenames from
 * Edit mode...this controller is what allows that to happen.
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
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
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\GraphBundle\Plugins\FileRenamerPluginInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class FileRenamerController extends ODRCustomController
{

    /**
     * Forces a rebuild of all filenames in this record (and its descendants) for any field that's
     * using the FileRenamer plugin.
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
        $return['d'] = array('changes_made' => false);

        try {
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
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

            /** @var FileRenamerPluginInterface $plugin_service */
            $plugin_service = $this->container->get('odr_plugins.base.file_renamer');

            // ----------------------------------------
            // Permissions are going to be a pain...easiest way is to filter cached arrays
            $dt_array = $database_info_service->getDatatypeArray($datatype->getId());  // do want links
            $dr_array = $datarecord_info_service->getDatarecordArray($datarecord->getId());  // do want links
            $permissions_service->filterByGroupPermissions($dt_array, $dr_array, $permissions_array);

            // Dig through what's left of the cached datatype array to find fields using the
            //  relevant plugins
            $relevant_plugins = array('odr_plugins.base.file_renamer' => 1);
            // NOTE: unlike FileRenamerController::rebuildAction(), this does not trigger on references

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
                $changes_made = self::renameFilesInDatafield($em, $entity_modify_service, $logger, $user, $drf, $plugin_service, 'odr_plugins.base.file_renamer');
                // If any of the files in this drf entry were renamed...
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
                $return['d']['changes_made'] = true;

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
            $source = 0xd85f36a8;
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
     * Forces a rebuild of all filenames in a specific record's field that's using the FileRenamer
     * plugin.
     *
     * The RRUFFReference plugin also uses this, because reasons.
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
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
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
            // If no files/images are uploaded, then this drf entry can legitimately be null


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


            // TODO - this mess just keeps getting worse
            if ( isset($dt['renderPluginInstances']) ) {
                foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                    if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.rruff_references' ) {
                        // Datatype is using the correct plugin...
                        $plugin_classname = 'odr_plugins.rruff.rruff_references';
                        break;
                    }
                }
            }

            if ( is_null($plugin_classname) && isset($dt['dataFields']) && isset($dt['dataFields'][$datafield->getId()]) ) {
                $df = $dt['dataFields'][$datafield->getId()];
                if ( isset($df['renderPluginInstances']) ) {
                    foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.file_renamer' ) {
                            // Datafield is using the correct plugin...
                            $plugin_classname = 'odr_plugins.base.file_renamer';
                            break;
                        }
                    }
                }
            }

            if ( is_null($plugin_classname) )
                throw new ODRBadRequestException('Datafield is not using a FileRenamer Render Plugin');


            // ----------------------------------------
            if ( $drf == null ) {
                /* No files/images uploaded here, so nothing to do */
            }
            else {
                // Loading the service makes sense now that we know the datafield is using the plugin
                /** @var FileRenamerPluginInterface $plugin_service */
                $plugin_service = $this->container->get($plugin_classname);

                // Update the filenames in this drf
                $changes_made = self::renameFilesInDatafield($em, $entity_modify_service, $logger, $user, $drf, $plugin_service, $plugin_classname);

                // Only fire events if something changed
                if ( $changes_made ) {
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
        }
        catch (\Exception $e) {
            $source = 0xce33a562;
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
     * Things are easier if each field checks/renames its files individually...
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EntityMetaModifyService $entity_modify_service
     * @param Logger $logger
     * @param ODRUser $user
     * @param DataRecordFields $drf
     * @param FileRenamerPluginInterface $plugin_service
     * @param string $plugin_classname
     * @throws \Exception
     * @return boolean true if any filename in this field was changed, false otherwise
     */
    private function renameFilesInDatafield($em, $entity_modify_service, $logger, $user, $drf, $plugin_service, $plugin_classname)
    {
        // Technically can do this without a try/catch block, but no real reason not to use one
        $datarecord = $drf->getDataRecord();
        $datafield = $drf->getDataField();
        $typeclass = $datafield->getFieldType()->getTypeClass();

        $ret = null;
        $changes_made = false;
        try {
            // Determine the new names for each of the files/images uploaded to this drf
            $ret = $plugin_service->getNewFilenames($drf);
            $logger->debug('Want to rename the '.$typeclass.'s in datafield '.$datafield->getId().' datarecord '.$datarecord->getId().'...', array(self::class, 'rebuildAction()', 'drf '.$drf->getId(), $plugin_classname));

            if ( is_array($ret) ) {
                foreach ($ret as $entity_id => $data) {
                    // ...so for each file/image uploaded to the datafield...
                    /** @var File|Image $entity */
                    $entity = $data['entity'];
                    $new_filename = $data['new_filename'];

                    // ...if the filename changed...
                    if ( $new_filename !== $entity->getOriginalFileName() ) {
                        // ...and it's not too long...
                        if ( strlen($new_filename) <= 255 ) {
                            // ...then the file/image needs to get renamed
                            $changes_made = true;
                            $logger->debug('-- renaming '.$typeclass.' '.$entity->getId().' from "'.$entity->getOriginalFileName().'" to "'.$new_filename.'"', array(self::class, 'renameFilesInDatafield()', $plugin_classname));

                            // Save the new filename in the database
                            $props = array('original_filename' => $new_filename);
                            if ($typeclass === 'File')
                                $entity_modify_service->updateFileMeta($user, $entity, $props, true);
                            else
                                $entity_modify_service->updateImageMeta($user, $entity, $props, true);

                            // If the plugin is enforcing a particular file extension
                            if ( isset($data['new_ext']) ) {
                                // ...then need to also set a value in the File/Image entity itself
                                $new_ext = $data['new_ext'];

                                $entity->setExt($new_ext);
                                $em->persist($entity);
                            }
                        }
                        else {
                            $logger->debug('-- (ERROR) unable to save new filename "'.$new_filename.'" for '.$typeclass.' '.$entity_id.' because it exceeds 255 characters', array(self::class, 'renameFilesInDatafield()', $plugin_classname));
                        }
                    }
                    else {
                        $logger->debug('...no need to rename '.$typeclass.' '.$entity_id.' in datafield '.$datafield->getId().' datarecord '.$datarecord->getId(), array(self::class, 'renameFilesInDatafield()', $plugin_classname));
                    }
                }
            }
            else {
                // ...if getNewFilename() returns null, then there's some unrecoverable problem
                //  that prevents the file from being renamed
                $logger->debug('-- (ERROR) unable to rename the '.$typeclass.'s...', array(self::class, 'rebuildAction()', 'drf '.$drf->getId(), $plugin_classname));

                // Regardless of the reason why there's a problem, this plugin can't fix it

                // Since this isn't a background job or an event, however, the suspected reason
                //  for the problem can get displayed to the user
                throw new \Exception($ret);
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'rebuildAction()', 'drf '.$drf->getId(), $plugin_classname));

            // Since this isn't a background job or an event, however, the suspected reason
            //  for the problem can get displayed to the user
            throw $e;
        }
        finally {
            // Would prefer if this happened regardless of success/failure...
            if ( is_array($ret) )
                $logger->debug('finished rename attempt for the '.$typeclass.'s in datafield '.$datafield->getId().' datarecord '.$datarecord->getId(), array(self::class, 'renameFilesInDatafield()', 'drf '.$drf->getId(), $plugin_classname));
        }

        // Return whether any files got renamed
        return $changes_made;
    }
}
