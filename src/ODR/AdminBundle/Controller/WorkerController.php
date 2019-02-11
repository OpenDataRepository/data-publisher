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
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneThemeService;
use ODR\AdminBundle\Component\Service\CloneTemplateService;
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Service\UUIDService;
use ODR\AdminBundle\Component\Utility\UniqueUtility;
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
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');


            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException('Invalid Form');

            $ret = '';

            // Grab necessary objects
            /** @var User $user */
            $user = $repo_user->find( $user_id );
            /** @var DataRecord $datarecord */
            $datarecord = $repo_datarecord->find( $datarecord_id );
            /** @var DataFields $datafield */
            $datafield = $repo_datafield->find( $datafield_id );
            $em->refresh($datafield);

            /** @var FieldType $old_fieldtype */
            $old_fieldtype = $repo_fieldtype->find( $old_fieldtype_id );
            $old_typeclass = $old_fieldtype->getTypeClass();
            /** @var FieldType $new_fieldtype */
            $new_fieldtype = $repo_fieldtype->find( $new_fieldtype_id );
            $new_typeclass = $new_fieldtype->getTypeClass();

            // Ensure datarecord/datafield pair exist
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
                // If migrating from multiple radio/select to single radio/select, and more than one radio option selected...then need to deselect all but one option

                // Grab all selected radio options
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id, rs.id AS rs_id, rs.selected AS selected, ro.id AS ro_id, rom.optionName AS option_name
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.dataRecordFields = drf
                    JOIN ODRAdminBundle:RadioOptions AS ro WITH rs.radioOption = ro
                    JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                    WHERE drf.dataRecord = :datarecord AND drf.dataField = :datafield AND rs.selected = 1
                    AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL
                    ORDER BY rom.displayOrder, ro.id'
                )->setParameters( array('datarecord' => $datarecord->getId(), 'datafield' => $datafield->getId()) );
                $results = $query->getArrayResult();

                // If more than one radio option selected...
                if ( count($results) > 1 ) {
                    // ...deselect all but the first one in the list
                    for ($i = 1; $i < count($results); $i++) {
                        $rs_id = $results[$i]['rs_id'];
                        $ro_id = $results[$i]['ro_id'];
                        $option_name = $results[$i]['option_name'];

                        /** @var RadioSelection $radio_selection */
                        $radio_selection = $repo_radio_selection->find($rs_id);

                        if ($radio_selection->getSelected() == 1) {
                            // Ensure this RadioSelection is unselected
                            $properties = array('selected' => 0);
//                            parent::ODR_copyRadioSelection($em, $user, $radio_selection, $properties);
                            $emm_service->updateRadioSelection($user, $radio_selection, $properties);    // TODO - test this

                            $ret .= '>> Deselected Radio Option '.$ro_id.' ('.$option_name.')'."\n";
                        }
                    }
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

//                    $new_obj = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                    $new_obj = $ec_service->createStorageEntity($user, $datarecord, $datafield);    // TODO - test this
//                    parent::ODR_copyStorageEntity($em, $user, $new_obj, array('value' => $value));    // TODO - why was this separate?
                    $emm_service->updateStorageEntity($user, $new_obj, array('value' => $value));    // TODO - test this
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
            // Mark this datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);

            // TODO - cached search results?


            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            // This is only ever called from command-line...
            $request->setRequestFormat('json');

            $source = 0x5e17488a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            /** @var User $user */
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            /** @var User $user */
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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


            /** @var Image $img */
            $img = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            if ($img == null)
                throw new \Exception('Image '.$object_id.' has been deleted');

            /** @var User $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find(2);    // TODO - need an actual system user...

            // Ensure the full-size image exists on the server
            $crypto_service->decryptImage($object_id);

            // Ensure an ImageSizes entity exists for this image
            /** @var ImageSizes[] $image_sizes */
            $image_sizes = $em->getRepository('ODRAdminBundle:ImageSizes')->findBy( array('dataFields' => $img->getDataField()->getId()) );
            if ( count($image_sizes) == 0 ) {
                // Create missing ImageSizes entities for this datafield
                parent::ODR_checkImageSizes($em, $user, $img->getDataField());

                // Reload the newly created ImageSizes for this datafield
                while ( count($image_sizes) == 0 ) {
                    sleep(1);   // wait a second so whichever process is creating the ImageSizes entities has time to finish
                    $image_sizes = $em->getRepository('ODRAdminBundle:ImageSizes')->findBy( array('dataFields' => $img->getDataField()->getId()) );
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
                    // Attempt to open the specified zip archive
                    $handle = fopen($archive_filepath, 'c');    // create file if it doesn't exist, otherwise do not fail and position pointer at beginning of file
                    if (!$handle)
                        throw new \Exception('unable to open "'.$archive_filepath.'" for writing');

                    // Attempt to acquire a lock on the zip archive so only one process is adding to it at a time
                    $lock = false;
                    while (!$lock) {
                        $lock = flock($handle, LOCK_EX);
                        if (!$lock)
                            usleep(200000);     // sleep for a fifth of a second to try to acquire a lock...
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

                    // Release the lock on the zip archive
                    flock($handle, LOCK_UN);
                    fclose($handle);
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $redis = $this->container->get('snc_redis.default');;
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
     * Given two datatypes...an "ancestor" datatype that links to a "remote" datatype...the theme
     * cloner will now end up creating a copy of the remote datatype's master theme and attaching
     * it to the themes of the ancestor datatype as if it's just another child datatype.
     *
     * This isn't really an issue when A isn't linked to, or A links to B...but when A -> B -> C,
     * then the theme cloner needs B to be complete before it can generate A correctly.  This
     * function reorders the list of themes used by the migration below so that A won't be done
     * before B, which won't be done before C, etc.
     *
     * @return array
     */
    private function computedependencies()
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        // Load all top-level themes
        $query = $em->createQuery(
           'SELECT partial t.{id, themeType}, partial dt.{id}
            FROM ODRAdminBundle:Theme AS t
            JOIN t.dataType AS dt
            WHERE t = t.parentTheme'
        );
        $top_level_themes = $query->getArrayResult();

        // Load all linked datatree entries
        $query = $em->createQuery(
           'SELECT dt, partial ancestor.{id}, partial descendant.{id}
            FROM ODRAdminBundle:DataTree AS dt
            JOIN dt.dataTreeMeta AS dtm
            JOIN dt.ancestor AS ancestor
            JOIN dt.descendant AS descendant
            WHERE dtm.is_link = 1
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
            AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        );
        $datatree_array = $query->getArrayResult();


        // Going to store which datatypes are linked to/from other datatypes...
        $all_datatypes = array();
        foreach ($top_level_themes as $num => $t) {
            $dt_id = $t['dataType']['id'];

            if ( !isset($all_datatypes[$dt_id]) ) {
                $all_datatypes[$dt_id] = array(
                    'ancestors' => array(),
                    'descendants' => array(),
                );
            }
        }

        foreach ($datatree_array as $num => $dt) {
            $ancestor_id = $dt['ancestor']['id'];
            $descendant_id = $dt['descendant']['id'];

            $all_datatypes[$descendant_id]['ancestors'][] = $ancestor_id;
            $all_datatypes[$ancestor_id]['descendants'][] = $descendant_id;
        }

        // Apparently the ancestors/descendants parts of the array can be empty?
        foreach ($all_datatypes as $dt_id => $tmp) {
            if ( !isset($tmp['ancestors']) )
                $all_datatypes[$dt_id]['ancestors'] = array();
            if ( !isset($tmp['descendants']) )
                $all_datatypes[$dt_id]['descendants'] = array();
        }

        // While there are still datatypes that need processing...
        $index = 0;
        $theme_list = array();
        while ( count($all_datatypes) > 0 ) {
            // ...locate the datatypes that don't depend on other linked datatypes to already be processed...
            foreach ($all_datatypes as $dt_id => $tmp) {
                if ( empty($tmp['descendants']) ) {
                    // ...then locate all of this datatype's top-level themes...
                    foreach ($top_level_themes as $num => $t) {
                        if ( $t['dataType']['id'] == $dt_id ) {
                            // ...and store them in array that's going to get returned
                            $theme_list[$index] = $t;
                            $index++;

                            unset( $top_level_themes[$num] );
                        }
                    }

                    // Also, for each datatype that links to this particular datatype...
                    foreach ($tmp['ancestors'] as $num => $ancestor_id) {
                        // ...remove the linked datatype from the list of descendants so
                        $key = array_search($dt_id, $all_datatypes[$ancestor_id]['descendants']);
                        if ( $key !== false )
                            unset( $all_datatypes[$ancestor_id]['descendants'][$key] );
                    }

                    // All themes for this datatype are scheduled for processing, don't need the entry anymore
                    unset( $all_datatypes[$dt_id] );
                }
            }
        }

        //
        return $theme_list;
    }


    /**
     * @param Request $request
     *
     * @return Response
     */
    public function childthememigrateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        try {
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var CloneThemeService $clone_theme_service */
            $clone_theme_service = $this->container->get('odr.clone_theme_service');

            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $repo_theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType');

            // Get a list of top-level themes ordered so that the theme cloner won't run into
            //  null pointer exceptions...
            $top_level_themes = self::computedependencies();
//exit( '<pre>'.print_r($top_level_themes, true).'</pre>' );

            // Want to be able to update deleted entities as well
            $em->getFilters()->disable('softdeleteable');

            print '<pre>'."\n";
            foreach ($top_level_themes as $num => $theme) {
                $theme_id = $theme['id'];
                $datatype_id = $theme['dataType']['id'];
                $theme_type = $theme['themeType'];

                print "\n".'top_level_datatype: '.$datatype_id.' ('.$theme_type.')'."\n";

                $query = $em->createQuery(
                   'SELECT
                        partial t.{id},
                        partial dt.{id},
                        partial te.{id},
                        partial tdt.{id, deletedAt},
                        partial c_dt.{id},
                        partial gp_dt.{id},
                        partial c_t.{id}

                    FROM ODRAdminBundle:Theme AS t
                    JOIN t.dataType AS dt
                    JOIN t.themeElements AS te
                    JOIN te.themeDataType AS tdt
                    JOIN tdt.dataType AS c_dt
                    JOIN c_dt.grandparent AS gp_dt
                    LEFT JOIN tdt.childTheme AS c_t
                    WHERE t.parentTheme = :theme_id'
                )->setParameters( array('theme_id' => $theme_id) );
                $results = $query->getArrayResult();

//                print '<pre>'.print_r($results, true).'</pre>';  break;

                foreach ($results as $num => $t) {
                    $dt_id = $t['dataType']['id'];
                    $t_id = $t['id'];

                    print ' -- datatype: '.$dt_id.'  theme: '.$t_id."\n";

                    foreach ($t['themeElements'] as $num => $te) {
                        foreach ($te['themeDataType'] as $num => $tdt) {

                            if ( isset($tdt['childTheme']) && isset($tdt['childTheme']['id']) )
                                continue;

                            $tdt_id = $tdt['id'];
                            $c_dt_id = $tdt['dataType']['id'];
                            $gp_dt_id = $tdt['dataType']['grandparent']['id'];

                            // Determine whether the child datatype id belongs to a linked datatype
                            $is_linked_datatype = false;
                            if ( intval($gp_dt_id) !== intval($datatype_id) )
                                $is_linked_datatype = true;

                            $is_deleted = false;
                            if ( !is_null($tdt['deletedAt']) )
                                $is_deleted = true;

                            if ($is_linked_datatype)
                                print ' -- -- linked_datatype_id: '.$c_dt_id;
                            else
                                print ' -- -- child_datatype_id: '.$c_dt_id;

                            if ($is_deleted)
                                print '  DELETED';
                            print "\n";

                            // Attempt to locate the correct theme_id to store in the theme_datatype's child_theme_id field
                            $query = $em->createQuery(
                               'SELECT t.id
                                FROM ODRAdminBundle:Theme AS t
                                WHERE t.dataType = :datatype_id AND t.parentTheme = :theme_id'
                            )->setParameters( array('datatype_id' => $c_dt_id, 'theme_id' => $theme_id) );
                            $sub_results = $query->getArrayResult();

                            if ( count($sub_results) > 1 ) {
                                // Should only ever be one result, in theory?
                                print '***** query returned '.count($sub_results).' results, should only return 0 or 1 results *****'."\n";
                            }
                            else {
                                if ( $is_linked_datatype || count($sub_results) == 0 ) {
                                    if (!$is_deleted) {

                                        // ----------------------------------------
                                        /** @var ThemeDataType $theme_datatype */
                                        $theme_datatype = $repo_theme_datatype->find($tdt_id);
                                        if ( is_null($theme_datatype) )
                                            print '***** unable to locate theme_datatype '.$tdt_id.' *****'."\n";

                                        $theme_element = $theme_datatype->getThemeElement();

                                        /** @var DataType $linked_datatype */
                                        $linked_datatype = $repo_datatype->find($c_dt_id);
                                        if ( is_null($linked_datatype) )
                                            print '***** unable to locate linked_datatype '.$c_dt_id.' *****'."\n";

                                        // Load the linked datatype's master theme
                                        $query = $em->createQuery(
                                           'SELECT t
                                            FROM ODRAdminBundle:Theme AS t
                                            WHERE t.dataType = :datatype_id AND t.themeType = :theme_type
                                            AND t.parentTheme = t.sourceTheme'
                                        )->setParameters(
                                            array(
                                                'datatype_id' => $c_dt_id,
                                                'theme_type' => 'master',
                                            )
                                        );
                                        $sub_result = $query->getResult();
                                        if (!$sub_result)
                                            print '***** unable to locate master theme for linked datatype '.$c_dt_id.' *****'."\n";

                                        /** @var Theme $linked_datatype_master_theme */
                                        $linked_datatype_master_theme = $sub_result[0];

                                        $query = $em->createQuery(
                                           'SELECT dt
                                            FROM ODRAdminBundle:DataTree AS dt
                                            WHERE dt.ancestor = :ancestor_datatype AND dt.descendant = :descendant_datatype
                                            ORDER BY dt.created DESC'
                                        )->setParameters(
                                            array(
                                                'ancestor_datatype' => $theme_element->getTheme()->getDataType()->getId(),
                                                'descendant_datatype' => $linked_datatype->getId(),
                                            )
                                        );
                                        $sub_result = $query->getResult();
                                        if (!$sub_result)
                                            print '***** unable to locate datatree entry for ancestor datatype '.$theme_element->getTheme()->getDataType()->getId().', descendant datatype '.$linked_datatype->getId().' *****'."\n";

                                        /** @var DataTree $most_recent_datatree */
                                        $most_recent_datatree = $sub_result[0];
                                        $user = $most_recent_datatree->getCreatedBy();
                                        if ( is_null($user) )
                                            $user = $most_recent_datatree->getDescendant()->getCreatedBy();

                                        print '       >> cloning source theme '.$linked_datatype_master_theme->getId().' for linked datatype '.$linked_datatype->getId().' (linked by user '.$user->getId().') into theme_element '.$theme_element->getId().' of theme '.$theme_element->getTheme()->getId()."\n";

                                        if ($save) {
                                            $em->getFilters()->enable('softdeleteable');
                                            //
                                            $clone_theme_service->cloneIntoThemeElement(
                                                $user,                                      // the user that created the link to this linked datatype
                                                $theme_element,                             // the theme element to clone into
                                                $linked_datatype_master_theme,              // the master theme of the linked datatype to make a copy of
                                                $linked_datatype,                           // the linked datatype itself
                                                $theme_element->getTheme()->getThemeType(), // the type of theme that is getting a copy of the linked datatype's master theme
                                                $theme_datatype                             // don't create a new themeDatatype entry, attach the newly created theme to this one
                                            );

                                            // cloneIntoThemeElement() will have created a new theme_datatype entry, so get rid of the old one
                                            $em->remove($theme_datatype);
                                            $em->flush();
                                            print '       >> deleted old theme_datatype entry'."\n";

                                            $em->getFilters()->disable('softdeleteable');
                                        }
                                    }
                                }
                                else if ( count($sub_results) == 1 ) {
                                    // Found the child datatype, set it
                                    if ($save) {
                                        /** @var Theme $child_theme */
                                        $child_theme = $repo_theme->find( $sub_results[0]['id'] );
                                        if ( is_null($child_theme) )
                                            print '***** unable to locate child theme '.$sub_results[0]['id'].' *****'."\n";

                                        /** @var ThemeDataType $theme_datatype */
                                        $theme_datatype = $repo_theme_datatype->find($tdt_id);
                                        if ( is_null($theme_datatype) )
                                            print '***** unable to locate child theme_datatype '.$tdt_id.' *****'."\n";

                                        $theme_datatype->setChildTheme($child_theme);

                                        $em->persist($theme_datatype);
                                        $em->flush();
                                        $em->refresh($theme_datatype);
                                    }

                                    print '       >> setting child_theme_id to '.$sub_results[0]['id']."\n";
                                }
                                else {
                                    // Should only ever be one result, in theory?
                                    print '***** SOMETHING WRONG *****'."\n";
                                }
                            }
                        }
                    }
                }

                // Wipe cache entry for this theme
                $cache_service->delete('cached_theme_'.$theme_id);
            }
            print '</pre>';

            // Re-enable softdeleteable filter
            $em->getFilters()->enable('softdeleteable');

            // Wipe a few more cache entries to be on the safe side
            $cache_service->delete('top_level_datatypes');
            $cache_service->delete('top_level_themes');
        }
        catch (\Exception $e) {
            // Don't want any changes made being saved to the database
            $em->getFilters()->enable('softdeleteable');
            $em->clear();

            $source = 0x0a4b8452;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO -
     *
     * @param Request $request
     *
     * @return Response
     */
    public function migratepluginsAction(Request $request)
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

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            /** @var RenderPlugin[] $render_plugins */
            $render_plugins = $em->getRepository('ODRAdminBundle:RenderPlugin')->findAll();

            foreach ($render_plugins as $render_plugin) {

                switch ($render_plugin->getPluginName()) {
                    // Base
                    case 'Default Render':
                        $render_plugin->setPluginClassName('odr_plugins.base.default');
                        break;
                    case 'GCMassSpec Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.base.gcms');
                        break;
                    case 'CSV Table Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.base.csvtable');
                        break;
                    case 'Graph Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.base.graph');
                        break;
                    case 'Chemistry Field':
                        $render_plugin->setPluginClassName('odr_plugins.base.chemistry');
                        break;
                    case 'References Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.base.references');
                        break;
                    case 'Comment Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.base.comment');
                        break;
                    case 'Link Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.base.link');
                        break;
                    case 'URL Field':
                        $render_plugin->setPluginClassName('odr_plugins.base.url');
                        break;
                    case 'Currency Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.base.currency');
                        break;

                    // Chemin
                    case 'Chemin ED1 Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.chemin.chemined1');
                        break;
                    case 'Chemin EDA Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.chemin.chemineda');
                        break;
                    case 'Chemin EDS Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.chemin.chemineds');
                        break;
                    case 'Chemin EE1 Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.chemin.cheminee1');
                        break;
                    case 'Chemin EEA Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.chemin.chemineea');
                        break;
                    case 'Chemin EES Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.chemin.cheminees');
                        break;
                    case 'Chemin EFM Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.chemin.cheminefm');
                        break;
                    case 'Chemin ETR Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.chemin.cheminetr');
                        break;
                    case 'Qanalyze XRD Analysis':
                        $render_plugin->setPluginClassName('odr_plugins.chemin.qanalyze');
                        break;

                    // AHED
                    case 'Organization Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.ahed.organization');
                        break;
                    case 'Person Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.ahed.person');
                        break;
                    case 'Sample Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.ahed.sample');
                        break;
                    case 'Site Identifier Plugin':
                        $render_plugin->setPluginClassName('odr_plugins.ahed.site');
                        break;

                    default:
                        print '<pre>Ecountered unrecognized plugin name "'.$render_plugin->getPluginName().'", marked as deleted</pre>';
                        if ($save)
                            $render_plugin->setDeletedAt(new \DateTime());
                        break;
                }

                if ($save)
                    $em->persist($render_plugin);
            }

            if ($save)
                $em->flush();
        }
        catch (\Exception $e) {
            $source = 0x0214889b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * @param Request $request
     *
     * @return Response
     */
    public function themeversioninitAction(Request $request)
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
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();


            /** @var CloneThemeService $clone_theme_service */
            $clone_theme_service = $this->container->get('odr.clone_theme_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            print '<pre>';
            $top_level_themes = $theme_info_service->getTopLevelThemes();
            foreach ($top_level_themes as $theme_id) {
                /** @var Theme $theme */
                $theme = $repo_theme->find($theme_id);

                $diff = $clone_theme_service->getThemeSourceDiff($theme);
                print 'theme '.$theme_id.' "'.$theme->getThemeType().'" (datatype '.$theme->getDataType()->getId().'):';

                $theme_meta = $theme->getThemeMeta();

                if ( count($diff) > 0 ) {
                    print ' HAS DIFFERENCES'."\n";

                    $theme_meta->setSourceSyncVersion(0);
                }
                else {
                    print ' has no differences'."\n";

                    $theme_meta->setSourceSyncVersion(1);
                }

                if ($save)
                    $em->persist($theme_meta);
            }
            print '</pre>';

            if ($save)
                $em->flush();
        }
        catch (\Exception $e) {
            $source = 0x675970ad;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * TODO -
     *
     * @param string $uuid_type
     * @param Request $request
     *
     * @return Response
     */
    public function setuniqueidsAction($uuid_type, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        // NOTE - go into the orm files for each of these entities and disable the gedmo timestampable on update first
        $save = false;
//        $save = true;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var UUIDService $uuid_service */
            $uuid_service = $this->container->get('odr.uuid_service');

            if ($uuid_type === 'datatype') {
                // Need all datatypes, as well as a list of which ones are top-level...
                $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();

                /** @var DataType[] $all_datatypes */
                $all_datatypes = $em->getRepository('ODRAdminBundle:DataType')->findAll();

                // Go through all the top-level datatypes first...
                print '<pre>';
                foreach ($all_datatypes as $dt) {
                    if (in_array($dt->getId(), $top_level_datatype_ids)) {
                        // If the top-level datatype has a unique_id but no template_group, fix that
                        if ($dt->getUniqueId() !== '' && (is_null($dt->getTemplateGroup() || $dt->getTemplateGroup() === ''))) {
                            $dt->setTemplateGroup($dt->getUniqueId());

                            print 'set top-level datatype '.$dt->getId().' "'.$dt->getShortName().'" was missing a template_group, set to "'.$dt->getUniqueId().'"'."\n";

                            if ($save) {
                                $em->persist($dt);
                                $em->flush();
                                $em->refresh($dt);
                            }
                        }

                        // If the top-level datatype does not have a unique_id, create one
                        if (is_null($dt->getUniqueId()) || $dt->getUniqueId() === '') {
                            $unique_id = $uuid_service->generateDatatypeUniqueId();

                            $dt->setUniqueId($unique_id);
                            $dt->setTemplateGroup($unique_id);

                            print 'set top-level datatype '.$dt->getId().' "'.$dt->getShortName().'" to have unique_id and template_group "'.$unique_id.'"'."\n";

                            if ($save) {
                                $em->persist($dt);
                                $em->flush();
                                $em->refresh($dt);
                            }
                        }
                    }
                }

                // ...now that the grandparent datatypes have unique_ids and template_groups, the
                //  child datatypes can be set to use their grandparent's template_group...
                foreach ($all_datatypes as $dt) {
                    if (!in_array($dt->getId(), $top_level_datatype_ids)) {
                        // Child datatypes should always match their grandparent's template_group...
                        if ( $dt->getTemplateGroup() !== $dt->getGrandparent()->getTemplateGroup() ) {
                            $dt->setTemplateGroup($dt->getGrandparent()->getTemplateGroup());

                            print 'set child datatype '.$dt->getId().' "'.$dt->getShortName().'" to have template_group "'.$dt->getGrandparent()->getTemplateGroup().'"'."\n";
                        }

                        // If the child datatype does not have a unique_id, create one
                        if (is_null($dt->getUniqueId()) || $dt->getUniqueId() === '') {
                            $unique_id = $uuid_service->generateDatatypeUniqueId();
                            $dt->setUniqueId($unique_id);

                            print 'set child datatype '.$dt->getId().' "'.$dt->getShortName().'" to have unique_id "'.$unique_id.'"'."\n";
                        }

                        if ($save) {
                            $em->persist($dt);
                            $em->flush();
                            $em->refresh($dt);
                        }
                    }
                }
                print '</pre>';
            }
            else if ($uuid_type === 'field') {
                print '<pre>';
                // Need to get all current ids in use in order to determine uniqueness of a new id...
                $query = $em->createQuery(
                   'SELECT df.fieldUuid
                    FROM ODRAdminBundle:DataFields AS df
                    WHERE df.deletedAt IS NULL and df.fieldUuid IS NOT NULL'
                );
                $results = $query->getArrayResult();

                $existing_ids = array();
                foreach ($results as $num => $result)
                    $existing_ids[ $result['fieldUuid'] ] = 1;

                // Now that we have a list of the existing unique ids...
                /** @var DataFields[] $datafields */
                $datafields = $em->getRepository('ODRAdminBundle:DataFields')->findBy(
                    array('fieldUuid' => null)
                );
                // Only ~7k datafields, easily done with a single query

                foreach ($datafields as $df) {
                    // Keep generating ids until we come across one that's not in use
                    $unique_id = UniqueUtility::uniqueIdReal();
                    while ( isset($existing_ids[$unique_id]) )
                        $unique_id = UniqueUtility::uniqueIdReal();

                    // Now that we found one that's not in use, save it...
                    $df->setFieldUuid($unique_id);

                    print 'set datafield '.$df->getId().' to have unique id '.$unique_id."\n";

                    // ...update the existing list of unique_ids so this one doesn't get used again
                    $existing_ids[ $unique_id ] = 1;
                    // ...and persist the datarecord
                    if ($save)
                        $em->persist($df);
                }
                print '</pre>';
            }
            else if ($uuid_type === 'radio') {
                print '<pre>';
                // Need to get all current ids in use in order to determine uniqueness of a new id...
                $query = $em->createQuery(
                   'SELECT ro.radioOptionUuid
                    FROM ODRAdminBundle:RadioOptions AS ro
                    WHERE ro.deletedAt IS NULL and ro.radioOptionUuid IS NOT NULL'
                );
                $results = $query->getArrayResult();

                $existing_ids = array();
                foreach ($results as $num => $result)
                    $existing_ids[ $result['radioOptionUuid'] ] = 1;


                // Now that we have a list of the existing unique ids...
                /** @var RadioOptions[] $radio_options */
                $radio_options = $em->getRepository('ODRAdminBundle:RadioOptions')->findBy(
                    array('radioOptionUuid' => null)
                );
                // Appears to be able to work in a single call

                foreach ($radio_options as $ro) {
                    // Keep generating ids until we come across one that's not in use
                    $unique_id = UniqueUtility::uniqueIdReal();
                    while ( isset($existing_ids[$unique_id]) )
                        $unique_id = UniqueUtility::uniqueIdReal();

                    // Now that we found one that's not in use, save it...
                    $ro->setRadioOptionUuid($unique_id);

                    print 'set radio option '.$ro->getId().' to have unique id '.$unique_id."\n";

                    // ...update the existing list of unique_ids so this one doesn't get used again
                    $existing_ids[ $unique_id ] = 1;
                    // ...and persist the datarecord
                    if ($save)
                        $em->persist($ro);
                }
                print '</pre>';
            }
            else if ($uuid_type === 'record') {
                print '<pre>';
                // Need to get all current ids in use in order to determine uniqueness of a new id...
                $query = $em->createQuery(
                   'SELECT dr.unique_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.deletedAt IS NULL and dr.unique_id IS NOT NULL'
                );
                $results = $query->getArrayResult();

                $existing_ids = array();
                foreach ($results as $num => $result)
                    $existing_ids[ $result['unique_id'] ] = 1;


                // Now that we have a list of the existing unique ids, load a pile of datarecords
                //  that don't have a unique id yet
                $query = $em->createQuery(
                   'SELECT dr
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.unique_id IS NULL AND dr.deletedAt IS NULL'
                );
                // Can't go much above this, or the query will timeout apparently
                $query->setMaxResults(15000);

                /** @var DataRecord[] $datarecords */
                $datarecords = $query->getResult();

                $count = 0;
                foreach ($datarecords as $dr) {
                    // Keep generating ids until we come across one that's not in use
                    $unique_id = UniqueUtility::uniqueIdReal();
                    while ( isset($existing_ids[$unique_id]) )
                        $unique_id = UniqueUtility::uniqueIdReal();

                    // Now that we found one that's not in use, save it...
                    $dr->setUniqueId($unique_id);

                    print 'set datarecord '.$dr->getId().' to have unique id '.$unique_id."\n";
                    $count++;

                    // ...update the existing list of unique_ids so this one doesn't get used again
                    $existing_ids[ $unique_id ] = 1;
                    // ...and persist the datarecord
                    if ($save)
                        $em->persist($dr);

                    if ($save && ($count % 5000) === 0 )
                        $em->flush();
                }
                print '</pre>';
            }

            if ($save)
                $em->flush();
        }
        catch (\Exception $e) {
            $source = 0x74a51771;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * @param Request $request
     *
     * @return Response
     */
    public function fixsetupstepsAction(Request $request)
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

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();


            /** @var DataType[] $all_datatypes */
            $all_datatypes = $em->getRepository('ODRAdminBundle:DataType')->findAll();

            print '<pre>';
            foreach ($all_datatypes as $dt) {
                $current_setup_step = $dt->getSetupStep();

                if ($current_setup_step !== DataType::STATE_INITIAL && $current_setup_step !== DataType::STATE_OPERATIONAL) {
                    $dt->setSetupStep(DataType::STATE_OPERATIONAL);
                    $em->persist($dt);

                    print 'set datatype '.$dt->getId().' "'.$dt->getShortName().'" to be "operational" instead of "incomplete"'."\n";
                }
            }
            print '</pre>';

            if ($save)
                $em->flush();
        }
        catch (\Exception $e) {
            $source = 0xd895a5e6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    public function asdfAction(Request $request)
    {
        try {
/*
            $array = array(
                "fields" => array(
//                    0 => array(
//                        "field_name" => "Astrobiology Disciplines",
//                        "selected_options" => array(
//                            0 => array(
//                                "name" => "geochemistry",
//                                "template_radio_option_uuid" => "0730d71",
//                            )
//                        ),
//                        "template_field_uuid" => "cfc0199",
//                    )
                    0 => array(
//                        "field_name" => "Dataset Name",
                        "value" => "c",
                        "template_field_uuid" => "08088a9"
//                        "template_field_uuid" => "a4b7180"
                    )
                ),
                "general" => "",
                "sort_by" => array(
//                    0 => array(
//                        "dir" => "asc",
//                        "template_field_uuid" => "08088a9",
//                    )
                ),
//                "template_name" => "AHED Core 1.0 Properties",
                "template_uuid" => "2ea627b",
            );

            $json = json_encode($array);
//            exit( '<pre>'.print_r($json, true).'</pre>' );
            $base64 = base64_encode($json);
            exit( '<pre>'.print_r($base64, true).'</pre>' );
*/
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();


            $query = $em->createQuery(
               'SELECT df.fieldUuid, ro.radioOptionUuid, rom.optionName
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                JOIN ODRAdminBundle:RadioOptions AS ro WITH ro.dataField = df
                JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                WHERE dt.unique_id = :dt_uuid AND df.fieldUuid IN (:df_uuids)
                AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
                AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL'
//                ORDER BY df.fieldUuid'
            )->setParameters(
                array(
                    'dt_uuid' => '2ea627b',
                    'df_uuids' => array(
                        '3efc620',
                        '3653d7f',
                        '979523a',
                        'cfc0199',
                        '72d4cf2',
                        '2c5f861',
                    )
                )
            );
            $results = $query->getArrayResult();

            $original_fullnames = array();
            foreach ($results as $result) {
                $df_uuid = $result['fieldUuid'];
                $full_option_name = $result['optionName'];

                if ( !isset($original_fullnames[$df_uuid]) )
                    $original_fullnames[$df_uuid] = array();

                $original_fullnames[$df_uuid][] = $full_option_name;
            }

            foreach ($original_fullnames as $df_uuid => $options) {
                $tmp = $options;
                asort($tmp);
                $original_fullnames[$df_uuid] = $tmp;
            }

//            asort($original_fullnames);
//            $original_fullnames = array_values($original_fullnames);
            print '<pre>'.print_r($original_fullnames, true).'</pre>';


            $options = array();
            $children = array();
            $parents = array();
            foreach ($results as $result) {
                $df_uuid = $result['fieldUuid'];
                $full_option_name = $result['optionName'];
                $option_pieces = explode(' > ', $full_option_name);

//                exit( '<pre>'.print_r($option_pieces, true).'</pre>' );

                if ( !isset($options[$df_uuid]) )
                    $options[$df_uuid] = array(0 => '');

                $parent_piece = null;
                foreach ($option_pieces as $piece) {
                    if ( !in_array($piece, $options[$df_uuid]) ) {
                        $options[$df_uuid][] = $piece;
                    }

                    if ( is_null($parent_piece) ) {
                        // top-level piece
                        $parent_piece = $piece;

                        $parent_key = array_search($parent_piece, $options[$df_uuid], true);

                        if ( !isset($children[$df_uuid]) )
                            $children[$df_uuid] = array(0 => array());
                        $children[$df_uuid][0][$parent_key] = 1;
                    }
                    else {
                        $parent_key = array_search($parent_piece, $options[$df_uuid], true);
                        $current_key = array_search($piece, $options[$df_uuid], true);

                        if ( !isset($children[$df_uuid]) )
                            $children[$df_uuid] = array(0 => array());
                        if ( !isset($children[$df_uuid][$parent_key]) )
                            $children[$df_uuid][$parent_key] = array();

                        $children[$df_uuid][$parent_key][$current_key] = 1;

                        if ( !isset($parents[$df_uuid]) )
                            $parents[$df_uuid] = array();
                        if ( !isset($parents[$df_uuid][$current_key]) )
                            $parents[$df_uuid][$current_key] = array();

                        $parents[$df_uuid][$current_key][$parent_key] = 1;

                        $parent_piece = $piece;
                    }
                }
            }

//            print '<pre>'.print_r($options, true).'</pre>';
//            print '<pre>'.print_r($children, true).'</pre>';
//            print '<pre>'.print_r($parents, true).'</pre>';    //exit();

            $duplicates = array();
            foreach ($parents as $df_uuid => $key_list) {
                foreach ($key_list as $child_key => $parent_keys) {
                    if ( count($parent_keys) > 1 ) {
                        if ( !isset($duplicates[$df_uuid]) )
                            $duplicates[$df_uuid] = array();

                        $child_option_name = $options[$df_uuid][$child_key];
                        $duplicates[$df_uuid][$child_option_name] = array();
                        foreach ($parent_keys as $parent_key => $num) {
                            $parent_option_name = $options[$df_uuid][$parent_key];
                            $duplicates[$df_uuid][$child_option_name][] = $parent_option_name;
                        }
                    }
                }
            }

            print '<pre>'.print_r($duplicates, true).'</pre>';

            print '<pre>';
            foreach ($duplicates as $df_uuid => $data) {
                print 'datafield "'.$df_uuid.'" has '.count($data).' entries with more than one parent'."\n";
            }
            print '</pre>';
            exit();


            print '<pre>';
            $reconstructed_fullnames = self::findcycles($options, $children);
            print '</pre>';

//            asort($reconstructed_fullnames);
//            $reconstructed_fullnames = array_values($reconstructed_fullnames);
            print '<pre>'.print_r($reconstructed_fullnames, true).'</pre>';

            exit('no cycles detected');
        }
        catch (\Exception $e) {
            $source = 0xd895a5e6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

/*
    private function findduplicates($options, $children)
    {
        foreach ($children as $df_uuid => $ro_list) {
            self::findduplicates_worker($options[$df_uuid], $children[$df_uuid], 0, );
        }
    }


    private function findduplicates_worker($options, $children, $index, )
    {

    }
*/

    private function findcycles($options, $children)
    {
        $reconstructed_fullnames = array();

        foreach ($children as $df_uuid => $ro_list) {
//            print 'looking into df "'.$df_uuid.'"...'."\n";

            $tmp = array();
            self::findcycles_worker($options[$df_uuid], $ro_list, 0, array(), $tmp);
            $reconstructed_fullnames[$df_uuid] = $tmp;

//            print "\n\n";
        }

        return $reconstructed_fullnames;
    }


    private function findcycles_worker($options, $children, $index, $visited, &$reconstructed_fullnames)
    {
        // If this number is already in the visited array, then there's a cycle in the tag hierarchy
        if ( isset($visited[$index]) )
            exit ( ' >> already seen index "'.$visited.'" inside '.print_r($visited, true).'</pre>' );

        // Otherwise, mark this as visited
        $visited[$index] = 1;

        if ( isset($children[$index]) ) {
            foreach ($children[$index] as $child_index => $num) {
                self::findcycles_worker($options, $children, $child_index, $visited, $reconstructed_fullnames);
            }
        }
        else {
            $str = '';
            foreach ($visited as $id => $num)
//                $str .= $options[$id].' > ';
                $str .= $id.' ';
//            $str = substr($str, 3, -3);

            $reconstructed_fullnames[] = $str;
        }
    }
}
