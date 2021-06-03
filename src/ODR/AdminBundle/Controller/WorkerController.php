<?php

/**
 * Open Data Repository Data Publisher
 * Worker Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The worker controller holds all of the functions that are called
 * by the worker processes, excluding those in the XML, CSV, and
 * MassEdit controllers.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneTemplateService;
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class WorkerController extends ODRCustomController
{

    /**
     * Called by the migration background process to transfer data from one storage entity to another compatible storage entity.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function migrateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $ret = '';

        try {
            $post = $_POST;
//print_r($post);
            if ( !isset($post['tracked_job_id']) || !isset($post['datarecord_id']) || !isset($post['datafield_id']) || !isset($post['user_id']) || !isset($post['old_fieldtype_id']) || !isset($post['new_fieldtype_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $datarecord_id = $post['datarecord_id'];
            $datafield_id = $post['datafield_id'];
            $user_id = $post['user_id'];
            $old_fieldtype_id = $post['old_fieldtype_id'];
            $new_fieldtype_id = $post['new_fieldtype_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
//            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');


            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException('Invalid Form');

            $ret = '';

            // Grab necessary objects
            /** @var ODRUser $user */
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find( $user_id );
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find( $datarecord_id );
            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find( $datafield_id );
            $em->refresh($datafield);

            /** @var FieldType $old_fieldtype */
            $old_fieldtype = $repo_fieldtype->find( $old_fieldtype_id );
            $old_typeclass = $old_fieldtype->getTypeClass();
            /** @var FieldType $new_fieldtype */
            $new_fieldtype = $repo_fieldtype->find( $new_fieldtype_id );
            $new_typeclass = $new_fieldtype->getTypeClass();

            // Ensure datarecord/datafield pair exist...they should, but have to make sure...
            if ($datarecord == null)
                throw new ODRException('Datarecord '.$datarecord_id.' is deleted');
            if ($datafield == null)
                throw new ODRException('Datafield '.$datafield_id.' is deleted');


            // Radio options need typename to distinguish...
            $old_typename = $old_fieldtype->getTypeName();
            $new_typename = $new_fieldtype->getTypeName();
            if ($old_typename == $new_typename)
                throw new \Exception('Not allowed to migrate between the same Fieldtype');

            // Need to handle radio options separately...
            if ( ($old_typename == 'Multiple Radio' || $old_typename == 'Multiple Select') && ($new_typename == 'Single Radio' || $new_typename == 'Single Select') ) {
                // If migrating from multiple radio/select to single radio/select, and more than one
                // radio option is selected...then need to deselect all but one option

                // Migrating from a single radio/select to a multiple radio/select requires no work

                // Load all selected radio options for this datarecord/datafield pair
                $query = $em->createQuery(
                   'SELECT drf, rs, ro, rom

                    FROM ODRAdminBundle:DataRecordFields AS drf
                    JOIN drf.radioSelection AS rs
                    JOIN rs.radioOption AS ro
                    JOIN ro.radioOptionMeta AS rom

                    WHERE drf.dataRecord = :datarecord_id AND drf.dataField = :datafield_id AND rs.selected = 1
                    AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL
                    AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL

                    ORDER BY rom.displayOrder, ro.id'
                )->setParameters(
                    array(
                        'datarecord_id' => $datarecord->getId(),
                        'datafield_id' => $datafield->getId(),
                    )
                );
                $results = $query->getResult();

                if ( !empty($results) ) {
                    /** @var DataRecordFields $drf */
                    $drf = $results[0];

                    $changes_made = false;
                    $count = 0;
                    foreach ($drf->getRadioSelection() as $rs) {
                        /** @var RadioSelection $rs */
                        // Leave the first one selected
                        $count++;
                        if ($count == 1) {
//                            $ret .= '>> Skipping RadioOption '.$rs->getRadioOption()->getId().' ('.$rs->getRadioOption()->getOptionName().')'."\n";
                            continue;
                        }

                        // Otherwise, ensure this RadioSelection is unselected
                        $properties = array('selected' => 0);
                        $emm_service->updateRadioSelection($user, $rs, $properties, true);    // don't flush immediately...
                        $changes_made = true;

                        $ret .= '>> Deselected RadioOption '.$rs->getRadioOption()->getId().' ('.$rs->getRadioOption()->getOptionName().')'."\n";
                    }

                    if ($changes_made)
                        $em->flush();
                }
            }
            else if ( $new_typeclass !== 'Radio' ) {
                // Grab the source entity repository
                $src_repository = $em->getRepository('ODRAdminBundle:'.$old_typeclass);

                // Grab the entity that needs to be migrated
                $src_entity = $src_repository->findOneBy(array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()));

                // No point migrating anything if the src entity doesn't exist in the first place...would be no data in it
                if ($src_entity !== null) {
                    $logger->info('WorkerController::migrateAction() >> Attempting to migrate data from "'.$old_typeclass.'" '.$src_entity->getId().' to "'.$new_typeclass.'"');
                    $ret .= '>> Attempting to migrate data from "'.$old_typeclass.'" '.$src_entity->getId().' to "'.$new_typeclass.'"'."\n";

                    $value = null;
                    if ( ($old_typeclass == 'ShortVarchar' || $old_typeclass == 'MediumVarchar' || $old_typeclass == 'LongVarchar' || $old_typeclass == 'LongText') && ($new_typeclass == 'ShortVarchar' || $new_typeclass == 'MediumVarchar' || $new_typeclass == 'LongVarchar' || $new_typeclass == 'LongText') ) {
                        // text -> text requires nothing special
                        $value = $src_entity->getValue();
                    }
                    else if ( ($old_typeclass == 'IntegerValue' || $old_typeclass == 'DecimalValue') && ($new_typeclass == 'ShortVarchar' || $new_typeclass == 'MediumVarchar' || $new_typeclass == 'LongVarchar' || $new_typeclass == 'LongText') ) {
                        // number -> text is easy
                        $value = strval($src_entity->getValue());
                    }
                    else if ($old_typeclass == 'IntegerValue' && $new_typeclass == 'DecimalValue') {
                        // integer -> decimal
                        $value = floatval($src_entity->getValue());
                    }
                    else if ($old_typeclass == 'DecimalValue' && $new_typeclass == 'IntegerValue') {
                        // decimal -> integer
                        $value = intval($src_entity->getValue());
                    }
                    else if ( ($old_typeclass == 'ShortVarchar' || $old_typeclass == 'MediumVarchar' || $old_typeclass == 'LongVarchar' || $old_typeclass == 'LongText') && ($new_typeclass == 'IntegerValue') ) {
                        // text -> integer
                        $pattern = '/[^0-9\.\-]+/i';
                        $replacement = '';
                        $new_value = preg_replace($pattern, $replacement, $src_entity->getValue());

                        if ( is_numeric($new_value) )
                            $value = intval($new_value);
                        else
                            $value = '';        // will get turned into NULL
                    }
                    else if ( ($old_typeclass == 'ShortVarchar' || $old_typeclass == 'MediumVarchar' || $old_typeclass == 'LongVarchar' || $old_typeclass == 'LongText') && ($new_typeclass == 'DecimalValue') ) {
                        // text -> decimal
                        $pattern = '/[^0-9\.\-]+/i';
                        $replacement = '';
                        $new_value = preg_replace($pattern, $replacement, $src_entity->getValue());

                        if ( is_numeric($new_value) )
                            $value = floatval($new_value);
                        else
                            $value = '';        // will get turned into NULL
                    }
                    else if ( $old_typeclass == 'DatetimeValue' ) {
                        // date -> anything
                        $value = null;
                    }
                    else if ( $new_typeclass == 'DatetimeValue' ) {
                        // anything -> date
                        $value = new \DateTime('9999-12-31 00:00:00');
                    }

                    // Save changes
                    if ( $new_typeclass == 'DatetimeValue' )
                        $ret .= 'set dest_entity to "'.$value->format('Y-m-d H:i:s').'"'."\n";
                    else
                        $ret .= 'set dest_entity to "'.$value.'"'."\n";
                    $em->remove($src_entity);


                    // Create a new storage entity with the correct fieldtype...
                    $new_obj = $ec_service->createStorageEntity($user, $datarecord, $datafield);
                    // ...then update it to have the desired value
                    $emm_service->updateStorageEntity($user, $new_obj, array('value' => $value));
                }
                else {
                    $ret .= '>> No '.$old_typeclass.' source entity for datarecord "'.$datarecord->getId().'" datafield "'.$datafield->getId().'", skipping'."\n";
                }
            }

            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total)
                    $tracked_job->setCompleted( new \DateTime() );

                $em->persist($tracked_job);
                $em->flush();
$ret .= '  Set current to '.$count."\n";
            }


            // ----------------------------------------
            // Do not mark this datarecord as updated
            // Delete the relevant cached datarecord entries
            $cache_service->delete('cached_datarecord_'.$datarecord->getGrandparent()->getId());
            $cache_service->delete('cached_table_data_'.$datarecord->getGrandparent()->getId());

            // Delete all relevant search cache entries
            $search_cache_service->onDatafieldModify($datafield);

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            // This is only ever called from command-line...
            $request->setRequestFormat('json');

            $source = 0x5e17488a;
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
     * Called by background processes to synchronize a datatype with its master template
     *
     * @param Request $request
     *
     * @return Response
     */
    public function syncwithtemplateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
            if ( !isset($post['datatype_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $datatype_id = $post['datatype_id'];
            $user_id = $post['user_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneTemplateService $clone_template_service */
            $clone_template_service = $this->container->get('odr.clone_template_service');

            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException('Invalid Form');

            // Grab necessary objects
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);

            if ($user == null)
                throw new ODRException('User '.$user_id.' does not exist');
            if ($datatype == null)
                throw new ODRException('Datatype '.$datatype_id.' does not exist');


            // Perform the synchronization
            $clone_template_service->syncWithTemplate($user, $datatype);

            $return['d'] = "Synchronization completed\n";
        }
        catch (\Exception $e) {
            // This is only ever called from command-line...
            $request->setRequestFormat('json');

            $source = 0x7057656e;
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
     * Begins the process of rebuilding the image thumbnails for a specific datatype.
     *
     * @param integer $datatype_id Which datatype should have all its image thumbnails rebuilt
     * @param Request $request
     *
     * @return Response
     */
    public function startrebuildthumbnailsAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new ODRNotImplementedException();

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $api_key = $this->container->getParameter('beanstalk_api_key');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
//            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            // TODO - check for permissions?  restrict rebuild of thumbnails to certain datatypes?

            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_rebuild_thumbnails');

            // Grab a list of all full-size images on the site
            $query = $em->createQuery(
               'SELECT e.id
                FROM ODRAdminBundle:Image AS e
                JOIN ODRAdminBundle:DataRecord AS dr WITH e.dataRecord = dr
                WHERE dr.dataType = :datatype AND e.parent IS NULL
                AND e.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters(array('datatype' => $datatype_id));
            $results = $query->getArrayResult();

//print_r($results);
//return;

            if (count($results) > 0) {
                // ----------------------------------------
                // Get/create an entity to track the progress of this thumbnail rebuild
                $job_type = 'rebuild_thumbnails';
                $target_entity = 'datatype_'.$datatype_id;
                $additional_data = array('description' => 'Rebuild of all image thumbnails for DataType '.$datatype_id);
                $restrictions = '';
                $total = count($results);
                $reuse_existing = false;

                $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
                $tracked_job_id = $tracked_job->getId();

                // ----------------------------------------
                $object_type = 'image';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('rebuild_thumbnails')->put($payload, $priority, $delay);
                }
            }

        }
        catch (\Exception $e) {
            $source = 0xb115dc04;
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
     * Called by the rebuild_thumbnails worker process to rebuild the thumbnails of one of the uploaded images on the site.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function rebuildthumbnailsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        $tracked_job_id = -1;

        try {

            throw new ODRNotImplementedException();

            $post = $_POST;
            if ( !isset($post['tracked_job_id']) || !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');


            /** @var Image $img */
            $img = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            if ($img == null)
                throw new \Exception('Image '.$object_id.' has been deleted');

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find(2);    // TODO - need an actual system user...

            // Ensure the full-size image exists on the server
            $crypto_service->decryptImage($object_id);

            // Ensure an ImageSizes entity exists for this image
            /** @var ImageSizes[] $image_sizes */
            $image_sizes = $em->getRepository('ODRAdminBundle:ImageSizes')->findBy( array('dataField' => $img->getDataField()->getId()) );
            if ( count($image_sizes) == 0 ) {
                // Create missing ImageSizes entities for this datafield
                $ec_service->createImageSizes($user, $img->getDataField());

                // Reload the newly created ImageSizes for this datafield
                while ( count($image_sizes) == 0 ) {
                    sleep(1);   // wait a second so whichever process is creating the ImageSizes entities has time to finish
                    $image_sizes = $em->getRepository('ODRAdminBundle:ImageSizes')->findBy( array('dataField' => $img->getDataField()->getId()) );
                }

                // Set this image to point to the correct ImageSizes entity, since it didn't exist before
                foreach ($image_sizes as $size) {
                    if ($size->getOriginal() == true) {
                        $img->setImageSize($size);
                        $em->persist($img);
                    }
                }

                $em->flush($img);
                $em->refresh($img);
            }

            // Recreate the thumbnail from the full-sized image
            parent::resizeImages($img, $user);


            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                /** @var TrackedJob $tracked_job */
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                if ($tracked_job !== null) {
                    $total = $tracked_job->getTotal();
                    $count = $tracked_job->incrementCurrent($em);

                    if ($count >= $total)
                        $tracked_job->setCompleted(new \DateTime());

                    $em->persist($tracked_job);
                    $em->flush();
//$ret .= '  Set current to '.$count."\n";
                }
            }

            $return['d'] = '>> Rebuilt thumbnails for '.$object_type.' '.$object_id."\n";
        }
        catch (\Exception $e) {
            // Update the job tracker even if an error occurred...right? TODO
            if ($tracked_job_id !== -1) {
                $em = $this->getDoctrine()->getManager();
                /** @var TrackedJob $tracked_job */
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                if ($tracked_job !== null) {
                    $total = $tracked_job->getTotal();
                    $count = $tracked_job->incrementCurrent($em);

                    if ($count >= $total)
                        $tracked_job->setCompleted(new \DateTime());

                    $em->persist($tracked_job);
                    $em->flush();
                }
            }

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38472782 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Performs an asynchronous encrypt or decrypt on a specified file.  Also has the option
     *
     * Ideally this will eventually replaced by the crypto service...but for now file encryption
     * after uploading still goes through here...
     *
     * @param Request $request
     *
     * @return Response
     */
    public function cryptorequestAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        $error_prefix = 'Error 0x65384782: ';
        $handle = null;

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['crypto_type']) || !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['target_filename']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $crypto_type = $post['crypto_type'];
            $object_type = strtolower( $post['object_type'] );
            $object_id = $post['object_id'];
            $target_filename = $post['target_filename'];
            $api_key = $post['api_key'];

            $error_prefix .= $crypto_type.' for '.$object_type.' '.$object_id.'...';

            // These two are only used if the files are being decrypted into a zip archive
            $archive_filepath = '';
            if ( isset($post['archive_filepath']) )
                $archive_filepath = $post['archive_filepath'];

            $desired_filename = '';
            if ( isset($post['desired_filename']) )
                $desired_filename = $post['desired_filename'];


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var LockService $lock_service */
            $lock_service = $this->container->get('odr.lock_service');


            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            if ( !is_numeric($post['object_id']) )
                throw new \Exception('$object_id is not numeric');
            else
                $object_id = intval($object_id);

            $base_obj = null;
            if ($object_type == 'file')
                $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
//            else if ($object_type == 'image')
//                $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);

            // NOTE - encryption after image upload is currently done inline in ODRCustomController::finishUploadAction()
            // Also, they're decrypted inline when needed...if they were done asynch, the browser couldn't display non-public versions in <img> tags


            if ($base_obj == null)
                throw new \Exception('could not load object '.$object_id.' of type "'.$object_type.'"');
            /** @var File|Image $base_obj */


            // ----------------------------------------
            if ($crypto_type == 'encrypt') {

                // ----------------------------------------
                // Move file from completed directory to decrypted directory in preparation for encryption...
                $current_path = $base_obj->getLocalFileName();
                $current_filename = $current_path.'/'.$base_obj->getOriginalFileName();

                $destination_path = $this->container->getParameter('odr_web_directory');
                $destination_filename = $base_obj->getUploadDir().'/File_'.$object_id.'.'.$base_obj->getExt();
                rename( $current_filename, $destination_path.'/'.$destination_filename );

                // Update local filename and checksum in database...
                $base_obj->setLocalFileName($destination_filename);

                $original_checksum = md5_file($destination_path.'/'.$destination_filename);
                $base_obj->setOriginalChecksum($original_checksum);

                // Encryption of a given file/image is simple...
                parent::encryptObject($object_id, $object_type);

                if ($object_type == 'file') {
                    $base_obj->setProvisioned(false);

                    $em->persist($base_obj);
                    $em->flush();
                    $em->refresh($base_obj);
                }

                // Update the datarecord cache so whichever controller is handling the "are you done encrypting yet?" javascript requests can return the correct HTML
                $datarecord = $base_obj->getDataRecord();
                $dri_service->updateDatarecordCacheEntry($datarecord, $base_obj->getCreatedBy());

                // TODO - this would be the place to fire some sort of FilePostEncrypt event...but is that even useful?
            }
            else if ($crypto_type == 'decrypt') {
                // This is (currently) the only request the user has made for this file...begin manually decrypting it because the crypto bundle offers limited control over filenames
                $crypto = $this->get("dterranova_crypto.crypto_adapter");
                $crypto_dir = dirname(__FILE__).'/../../../../app/crypto_dir/';     // TODO - load from config file somehow?
                $crypto_dir .= 'File_'.$object_id;

                $base_filepath = dirname(__FILE__).'/../../../../web/'.$base_obj->getUploadDir();
                $local_filepath = $base_filepath.'/'.$target_filename;

                // Don't decrypt the file if it already exists on the server
                if ( !file_exists($local_filepath) ) {
                    // Grab the hex string representation that the file was encrypted with
                    $key = $base_obj->getEncryptKey();
                    // Convert the hex string representation to binary...php had a function to go bin->hex, but didn't have a function for hex->bin for at least 7 years?!?
                    $key = pack("H*", $key);   // don't have hex2bin() in current version of php...this appears to work based on the "if it decrypts to something intelligible, you did it right" theory

                    // Open the target file
                    $handle = fopen($local_filepath, "wb");
                    if (!$handle)
                        throw new \Exception('Unable to open "'.$local_filepath.'" for writing');

                    // Decrypt each chunk and write to target file
                    $chunk_id = 0;
                    while (file_exists($crypto_dir.'/'.'enc.'.$chunk_id)) {
                        if (!file_exists($crypto_dir.'/'.'enc.'.$chunk_id))
                            throw new \Exception('Encrypted chunk not found: '.$crypto_dir.'/'.'enc.'.$chunk_id);

                        $data = file_get_contents($crypto_dir.'/'.'enc.'.$chunk_id);
                        fwrite($handle, $crypto->decrypt($data, $key));
                        $chunk_id++;
                    }
                }

                if ( $archive_filepath == '' ) {

                    $file_decryptions = $cache_service->get('file_decryptions');

                    if ( isset($file_decryptions[$target_filename]) )
                        unset($file_decryptions[$target_filename]);

                    $cache_service->set('file_decryptions', $file_decryptions);
                }
                else {
                    // Acquire a lock on this zip archive so that multiple processes don't clobber
                    //  each other
                    $offset = strrpos($archive_filepath, '/') + 1;
                    $lockpath = substr($archive_filepath, $offset);

                    $lockHandler = $lock_service->createLock($lockpath.'.lock', 15);    // 15 second ttl
                    if ( !$lockHandler->acquire() ) {
                        // Another process is in the mix...block until it finishes
                        $lockHandler->acquire(true);
                    }

                    // Open the archive for appending, or create if it doesn't exist
                    $zip_archive = new \ZipArchive();
                    $zip_archive->open($archive_filepath, \ZipArchive::CREATE);

                    // Add the specified file to the zip archive
                    $zip_archive->addFile($local_filepath, $desired_filename);
                    $zip_archive->close();

                    // Delete decrypted version of non-public files off the server
                    if (!$base_obj->isPublic())
                        unlink($local_filepath);

                    // Release the previously acquired lock
                    $lockHandler->release();
                }
            }
            else {
                throw new \Exception('bad value for $crypto_type, got "'.$crypto_type.'"');
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = $error_prefix.$e->getMessage();

            // TODO - delete the partial non-public file if some sort of error during decryption?

            if ($handle != null) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Begins the process of forcibly (re)encrypting every uploaded file/image on the site
     *
     * @param string $object_type "File" or "Image"...which type of entity to encrypt
     * @param Request $request
     *
     */
    public function startencryptAction($object_type, Request $request)
    {
        throw new ODRNotImplementedException();
/*
        $em = $this->getDoctrine()->getManager();
        $pheanstalk = $this->get('pheanstalk');
        $router = $this->container->get('router');
        $redis = $this->container->get('snc_redis.default');;
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        $api_key = $this->container->getParameter('beanstalk_api_key');

        // Generate the url for cURL to use
        $url = $this->container->getParameter('site_baseurl');
        $url .= $router->generate('odr_force_encrypt');

        if ($object_type == 'file' || $object_type == 'File')
            $object_type = 'File';
        else if ($object_type == 'image' || $object_type == 'Image')
            $object_type = 'Image';
        else
            return null;

        $query = $em->createQuery(
           'SELECT e.id
            FROM ODRAdminBundle:'.$object_type.' AS e
            WHERE e.deletedAt IS NULL'
        );
        $results = $query->getResult();

//print_r($results);
//return;

        $object_type = strtolower($object_type);
        foreach ($results as $num => $result) {
            $object_id = $result['id'];

            // Insert the new job into the queue
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "object_type" => $object_type,
                    "object_id" => $object_id,
                    "redis_prefix" => $redis_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                )
            );

            $delay = 1;
            $pheanstalk->useTube('mass_encrypt')->put($payload, $priority, $delay);

//return;
        }
*/
    }


    /**
     * Called by the mass_encrypt worker background process to (re)encrypt a single file or image.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function encryptAction(Request $request)
    {
/*
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            parent::decryptObject($object_id, $object_type);    // ensure a decrypted object exists prior to attempting to encrypt
            parent::encryptObject($object_id, $object_type);

            $return['d'] = '>> Encrypted '.$object_type.' '.$object_id."\n";
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38378231 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
*/
    }


    /**
     * Begins the process of forcibly decrypting every uploaded file/image on the site.
     *
     * @param string $object_type "File" or "Image"...which type of entity to encrypt
     * @param Request $request
     *
     * @return Response
     */
    public function startdecryptAction($object_type, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new ODRNotImplementedException();

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $redis = $this->container->get('snc_redis.default');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $api_key = $this->container->getParameter('beanstalk_api_key');

            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_crypto_request');

            if ($object_type == 'file' || $object_type == 'File')
                $object_type = 'File';
//            else if ($object_type == 'image' || $object_type == 'Image')
//                $object_type = 'Image';
            else
                return null;

            $query = null;
            if ($object_type == 'File') {
                $query = $em->createQuery(
                    'SELECT e.id
                    FROM ODRAdminBundle:'.$object_type.' AS e
                    WHERE e.deletedAt IS NULL AND (e.original_checksum IS NULL OR e.filesize = 0)'
                );
            }
/*
            else if ($object_type == 'Image') {
                $query = $em->createQuery(
                    'SELECT e.id
                    FROM ODRAdminBundle:'.$object_type.' AS e
                    WHERE e.deletedAt IS NULL AND e.original_checksum IS NULL'
                );
            }
*/
            $results = $query->getResult();

            $object_type = strtolower($object_type);
            foreach ($results as $num => $file) {
                // Insert the new job into the queue
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "object_type" => $object_type,
                        "object_id" => $file['id'],
                        "target_filename" => '',
                        "crypto_type" => 'decrypt',

                        "archive_filepath" => '',
                        "desired_filename" => '',

                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "url" => $url,
                        "api_key" => $api_key,
                    )
                );

                $delay = 1;
                $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x45387831 '.$e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO
     *
     * @param Request $request
     *
     * @return Response
     */
    public function updatedatatypenamesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();


            /** @var DataType[] $all_datatypes */
            $all_datatypes = $em->getRepository('ODRAdminBundle:DataType')->findAll();

            $use_shortname = array(
//                29, 108, 220, 225, 253,
//                308, 342, 384, 391, 392,
//                397
            );

            print '<table border="1">';
            print '<tr><th>ID</th><th>Grandparent ID</th><th>Short Name</th><th>Long Name</th><th>Fix</th>';
            foreach ($all_datatypes as $dt) {
                print '<tr>';
                print '<td>'.$dt->getId().'</td>';
                print '<td>'.$dt->getGrandparent()->getId().'</td>';
                print '<td>'.$dt->getShortName().'</td>';
                print '<td>'.$dt->getLongName().'</td>';

                print '<td>';
                if ( $dt->getShortName() === $dt->getLongName() ) {
                    print '';
                }
                else if ( $dt->getId() !== $dt->getGrandparent()->getId() && $dt->getLongName() === "New Child" ) {
                    if ( $save ) {
                        $dt->getDataTypeMeta()->setLongName( $dt->getShortName() );
                        $em->persist( $dt->getDataTypeMeta() );
                    }

                    print '<b>set long_name to "'.$dt->getShortName().'"</b>';
                }
                else if ( in_array($dt->getId(), $use_shortname) ) {
                    if ( $save ) {
                        $dt->getDataTypeMeta()->setLongName( $dt->getShortName() );
                        $em->persist( $dt->getDataTypeMeta() );
                    }

                    print '<b>set long_name to "'.$dt->getShortName().'"</b>';
                }
                else {
                    if ( $save ) {
                        $dt->getDataTypeMeta()->setShortName( $dt->getLongName() );
                        $em->persist( $dt->getDataTypeMeta() );
                    }

                    print '<b>set short_name to "'.$dt->getLongName().'"</b>';
                }
                print '</td>';
            }
            print '</table>';

            if ($save)
                $em->flush();
        }
        catch (\Exception $e) {
            $source = 0x2e4e2e42;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }
}
