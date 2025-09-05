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
     * Forces a rebuild of the filenames for all files/images uploaded to this drf entry.
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

            // Loading the service makes sense now that we know the datafield is using the plugin
            /** @var FileRenamerPluginInterface $plugin_service */
            $plugin_service = $this->container->get($plugin_classname);


            // ----------------------------------------
            if ( $drf == null ) {
                /* No files/images uploaded here, so nothing to do */
            }
            else {
                // Hydrate all the files/images uploaded to this drf
                $typeclass = $datafield->getFieldType()->getTypeClass();
                $tmp = null;
                if ( $typeclass === 'File' ) {
                    $query = $em->createQuery(
                       'SELECT f
                        FROM ODRAdminBundle:File f
                        WHERE f.dataRecordFields = :drf
                        AND f.deletedAt IS NULL'
                    )->setParameters( array('drf' => $drf->getId()) );
                    $tmp = $query->getResult();
                }
                else {
                    $query = $em->createQuery(
                       'SELECT i
                        FROM ODRAdminBundle:Image i
                        WHERE i.dataRecordFields = :drf AND i.original = 1
                        AND i.deletedAt IS NULL'
                    )->setParameters( array('drf' => $drf->getId()) );
                    $tmp = $query->getResult();
                }

                // There could be nothing uploaded to the field, or there could be multiple files/images
                /** @var File[]|Image[] $tmp */
                $entities = array();
                foreach ($tmp as $num => $entity)
                    $entities[ $entity->getId() ] = $entity;
                /** @var File[]|Image[] $entities */


                // ----------------------------------------
                // Technically can do this without a try/catch block, but no real reason not to use one
                $ret = null;
                try {
                    // Determine the new names for each of the files/images uploaded to this drf
                    $ret = $plugin_service->getNewFilenames($drf);
                    $logger->debug('Want to rename the '.$typeclass.'s in datafield '.$datafield->getId().' datarecord '.$datarecord->getId().'...', array(self::class, 'rebuildAction()', 'drf '.$drf->getId(), $plugin_classname));

                    if ( is_array($ret) ) {
                        foreach ($ret as $entity_id => $data) {
                            $new_filename = $data['new_filename'];

                            if ( strlen($new_filename) <= 255 ) {
                                // ...so for each file/image uploaded to the datafield...
                                /** @var File|Image $entity */
                                $entity = $entities[$entity_id];
                                $logger->debug('...renaming '.$typeclass.' '.$entity->getId().' to "'.$new_filename.'"...', array(self::class, 'rebuildAction()', $typeclass.' '.$entity->getId(), $plugin_classname));

                                // ...save the new filename in the database...
                                $props = array('original_filename' => $new_filename);
                                if ($typeclass === 'File')
                                    $entity_modify_service->updateFileMeta($user, $entity, $props, true);
                                else
                                    $entity_modify_service->updateImageMeta($user, $entity, $props, true);

                                // If the plugin is enforcing a particular file extension...
                                if ( isset($data['new_ext']) ) {
                                    // ...then need to also set a value in the File/Image entity itself
                                    $new_ext = $data['new_ext'];

                                    $entity->setExt($new_ext);
                                    $em->persist($entity);
                                }
                            }
                            else {
                                $logger->debug('-- (ERROR) unable to save new filename "'.$new_filename.'" for '.$typeclass.' '.$entity_id.' because it exceeds 255 characters', array(self::class, 'rebuildAction()', $typeclass.' '.$entity->getId(), $plugin_classname));
                            }
                        }

                        // Now that the files/images are named correctly, flush the changes
                        $em->flush();
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
                    // Would prefer if these happened regardless of success/failure...
                    if ( is_array($ret) ) {
                        $logger->debug('finished rename attempt for the '.$typeclass.'s in datafield '.$datafield->getId().' datarecord '.$datarecord->getId(), array(self::class, 'rebuildAction()', 'drf '.$drf->getId(), $plugin_classname));


                        // ----------------------------------------
                        // Fire off an event notifying that the modification of the datafield is done
                        try {
                            $event = new DatafieldModifiedEvent($datafield, $user);
                            $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't want to rethrow the error since it'll interrupt everything after this
                            //  event
//                            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                                throw $e;
                        }

                        // Since a file got renamed, need to mark the record as updated
                        try {
                            $event = new DatarecordModifiedEvent($datarecord, $user);
                            $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't want to rethrow the error since it'll interrupt everything after this
                            //  event
//                            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                                throw $e;
                        }
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
}
