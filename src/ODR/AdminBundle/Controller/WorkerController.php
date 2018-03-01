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
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;

use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneThemeService;
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
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
                            parent::ODR_copyRadioSelection($em, $user, $radio_selection, $properties);

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

                    $new_obj = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                    parent::ODR_copyStorageEntity($em, $user, $new_obj, array('value' => $value));
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
     * @deprecated
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
                $destination_path = dirname(__FILE__).'/../../../../web';
                $destination_filename = $base_obj->getUploadDir().'/File_'.$object_id.'.'.$base_obj->getExt();
                rename( $base_obj->getLocalFileName(), $destination_path.'/'.$destination_filename );

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
                    $redis = $this->container->get('snc_redis.default');;
                    // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
                    $redis_prefix = $this->container->getParameter('memcached_key_prefix');

                    $file_decryptions = parent::getRedisData(($redis->get($redis_prefix.'_file_decryptions')));

                    unset($file_decryptions[$target_filename]);
                    $redis->set($redis_prefix.'_file_decryptions', gzcompress(serialize($file_decryptions)));
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
     * Debug function...clears existing cached versions of datatypes (optionally for a specific datatype).
     * Should only be used when changes have been made to the structure of the cached array for datatypes
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function dtclearAction($datatype_id, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');


            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            $results = array();
            if ($datatype_id == 0) {
                // Locate all existing datatype ids
                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id
                    FROM ODRAdminBundle:DataType AS dt'
                );

                $results = $query->getArrayResult();
            }
            else {
                // Locate all child/linked datatypes for the specified datatype
                $datatype_ids = $dti_service->getAssociatedDatatypes( array($datatype_id) );

                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id
                    FROM ODRAdminBundle:DataType AS dt
                    WHERE dt.id IN (:datatype_ids)'
                )->setParameters( array('datatype_ids' => $datatype_ids) );

                $results = $query->getArrayResult();
            }

            $cache_service->delete('cached_datatree_array');
            $cache_service->delete('top_level_datatypes');

            $keys_to_delete = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];

                // All datatypes have these keys
                $keys_to_delete[] = 'cached_datatype_'.$dt_id;
                $keys_to_delete[] = 'datatype_'.$dt_id.'_record_order';

                // Child datatypes don't have these keys, but deleting them doesn't hurt anything
                $keys_to_delete[] = 'dashboard_'.$dt_id;
                $keys_to_delete[] = 'dashboard_'.$dt_id.'_public_only';
                $keys_to_delete[] = 'associated_datatypes_for_'.$dt_id;
            }

            print '<pre>';
            foreach ($keys_to_delete as $key) {
                if ($cache_service->exists($key) ) {
                    $cache_service->delete($key);
                    print '"'.$key.'" deleted'."\n";
                }
            }
            print '</pre>';
        }
        catch (\Exception $e) {
            $source = 0xaa016ab8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $return = array(
            'r' => 0,
            't' => '',
            'd' => '',
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...clears existing cached versions of themes (optionally for a specific datatype).
     * Should only be used when changes have been made to the structure of the cached array for themes.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function themeclearAction($datatype_id, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');


            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            $results = array();
            if ($datatype_id == 0) {
                // Locate all existing theme ids
                $query = $em->createQuery(
                   'SELECT t.id AS t_id
                    FROM ODRAdminBundle:Theme AS t'
                );

                $results = $query->getArrayResult();
            }
            else {
                // Locate all themes for this datatype, its children, and its linked datatypes (just because)
                $datatype_ids = $dti_service->getAssociatedDatatypes( array($datatype_id) );

                $query = $em->createQuery(
                   'SELECT t.id AS t_id
                    FROM ODRAdminBundle:Theme AS t
                    WHERE t.dataType IN (:datatype_ids)'
                )->setParameters( array('datatype_ids' => $datatype_ids) );

                $results = $query->getArrayResult();
            }

            $keys_to_delete = array();
            foreach ($results as $result) {
                $t_id = $result['t_id'];
                $keys_to_delete[] = 'cached_theme_'.$t_id;
            }

            print '<pre>';
            foreach ($keys_to_delete as $key) {
                if ($cache_service->exists($key) ) {
                    $cache_service->delete($key);
                    print '"'.$key.'" deleted'."\n";
                }
            }
            print '</pre>';
        }
        catch (\Exception $e) {
            $source = 0xe4e80e34;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $return = array(
            'r' => 0,
            't' => '',
            'd' => '',
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...clears existing cached versions of datarecords (optionally for a given datatype).
     * Should only be used when changes have been made to the structure of the cached array for datarecords
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function drclearAction($datatype_id, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');


            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();


            $conn = $em->getConnection();

            $query = null;
            if ($datatype_id == 0) {
                // Locate all existing datarecord ids
                $query =
                   'SELECT dr.id AS dr_id
                    FROM odr_data_record AS dr
                    WHERE dr.id = dr.grandparent_id
                    AND dr.deletedAt IS NULL';
            }
            else {
                // Only want datarecords of the specified datatype
                $query =
                   'SELECT dr.id AS dr_id
                    FROM odr_data_record AS dr
                    WHERE dr.id = dr.grandparent_id AND dr.data_type_id = '.$datatype_id.'
                    AND dr.deletedAt IS NULL';
            }
            $results = $conn->fetchAll($query);

            $key = 'associated_datarecords_for_';
            foreach ($results as $result) {
                if ($cache_service->exists($key.$result['dr_id']))
                    $cache_service->delete($key.$result['dr_id']);
            }

            $key = 'cached_datarecord_';
            foreach ($results as $result) {
                if ($cache_service->exists($key.$result['dr_id']))
                    $cache_service->delete($key.$result['dr_id']);
            }

            $key = 'cached_table_data_';
            foreach ($results as $result) {
                if ($cache_service->exists($key.$result['dr_id']))
                    $cache_service->delete($key.$result['dr_id']);
            }

        }
        catch (\Exception $e) {
            $source = 0xc178ea4b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $return = array(
            'r' => 0,
            't' => '',
            'd' => '',
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...clears all existing cached search result.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function searchclearAction(Request $request)
    {
        try {
            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            $cache_service->delete('cached_search_results');
        }
        catch (\Exception $e) {
            $source = 0xcb3e7952;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $return = array(
            'r' => 0,
            't' => '',
            'd' => '',
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...clears all existing cached search result.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function permissionsclearAction(Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            // ----------------------------------------
            // Clear all cached user permissions
            $query = $em->createQuery(
               'SELECT u.id AS user_id
                FROM ODROpenRepositoryUserBundle:User AS u'
            );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $user_id = $result['user_id'];
                $cache_service->delete('user_'.$user_id.'_permissions');
            }


            // ----------------------------------------
            // Clear all cached group permissions
            $query = $em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:Group AS g'
            );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $group_id = $result['group_id'];
                $cache_service->delete('group_'.$group_id.'_permissions');
            }
        }
        catch (\Exception $e) {
            $source = 0x2afc476b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $return = array(
            'r' => 0,
            't' => '',
            'd' => '',
        );

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
     * Debug function to force the correct datetime format in the database
     *
     * @param Request $request
     */
    public function fixdatabasedatesAction(Request $request)
    {
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
        if (!$user->hasRole('ROLE_SUPER_ADMIN'))
            throw new ODRForbiddenException();

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $em->getFilters()->disable('softdeleteable');

        $has_created = $has_updated = $has_deleted = $has_publicdate = array();

        print '<pre>';
        $filepath = dirname(__FILE__).'/../Entity/';
        $filelist = scandir($filepath);
        foreach ($filelist as $num => $filename) {

            if ( strlen($filename) > 3 && strpos($filename, '~') === false && strpos($filename, '.bck') === false ) {
                $handle = fopen($filepath.$filename, 'r');
                if (!$handle)
                    throw new ODRException('Unable to open file');

                $classname = '';
                while ( !feof($handle) ) {
                    $line = fgets($handle);

                    $matches = array();
                    if ( preg_match('/^class ([^\s]+)$/', $line, $matches) == 1 )
                        $classname = $matches[1];
                    if ($classname == 'FieldType')
                        continue;

                    if ( strpos($line, 'private $created;') !== false )
                        $has_created[] = $classname;
                    if ( strpos($line, 'private $updated;') !== false )
                        $has_updated[] = $classname;
                    if ( strpos($line, 'private $deletedAt;') !== false )
                        $has_deleted[] = $classname;
                    if ( strpos($line, 'private $publicDate;') !== false )
                        $has_publicdate[] = $classname;
                }

                fclose($handle);
            }
        }
/*
        print "has created: \n";
        print_r($has_created);
        print "has updated: \n";
        print_r($has_updated);
        print "has deleted: \n";
        print_r($has_deleted);
        print "has publicDate: \n";
        print_r($has_publicdate);
*/
        $bad_created = $bad_updated = $bad_deleted = $bad_publicdate = array();
        $parameter = array('bad_date' => "0000-00-00%");

        foreach ($has_created as $num => $classname) {
            $query = $em->createQuery(
               'SELECT COUNT(e.id)
                FROM ODRAdminBundle:'.$classname.' AS e
                WHERE e.created LIKE :bad_date'
            )->setParameters($parameter);
            $results = $query->getArrayResult();

            if ( $results[0][1] > 0 )
                $bad_created[$classname] = $results[0][1];
        }

        foreach ($has_updated as $num => $classname) {
            $query = $em->createQuery(
               'SELECT COUNT(e.id)
                FROM ODRAdminBundle:'.$classname.' AS e
                WHERE e.updated LIKE :bad_date'
            )->setParameters($parameter);
            $results = $query->getArrayResult();

            if ( $results[0][1] > 0 )
                $bad_updated[$classname] = $results[0][1];
        }

        foreach ($has_deleted as $num => $classname) {
            $query = $em->createQuery(
               'SELECT COUNT(e.id)
                FROM ODRAdminBundle:'.$classname.' AS e
                WHERE e.deletedAt LIKE :bad_date'
            )->setParameters($parameter);
            $results = $query->getArrayResult();

            if ( $results[0][1] > 0 )
                $bad_deleted[$classname] = $results[0][1];
        }

        foreach ($has_publicdate as $num => $classname) {
            $query = $em->createQuery(
               'SELECT COUNT(e.id)
                FROM ODRAdminBundle:'.$classname.' AS e
                WHERE e.publicDate LIKE :bad_date'
            )->setParameters($parameter);
            $results = $query->getArrayResult();

            if ( $results[0][1] > 0 )
                $bad_publicdate[$classname] = $results[0][1];
        }

        print "bad created: \n";
        print_r($bad_created);
        print "bad updated: \n";
        print_r($bad_updated);
        print "bad deleted: \n";
        print_r($bad_deleted);
        print "bad publicDate: \n";
        print_r($bad_publicdate);

        $save = false;
//        $save = true;

        foreach ($bad_created as $classname => $num) {
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:'.$classname.' AS e
                SET e.created = :good_date
                WHERE e.created < :bad_date'
            )->setParameters(
                array(
                    'good_date' => new \Datetime('2013-01-01 00:00:00'),
                    'bad_date' => '2000-01-01 00:00:00'
                )
            );
            if ($save)
                $first = $query->execute();
        }

        foreach ($bad_updated as $classname => $num) {
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:'.$classname.' AS e
                SET e.updated = e.deletedAt
                WHERE e.deletedAt IS NOT NULL'
            );
            if ($save)
                $first = $query->execute();

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:'.$classname.' AS e
                SET e.updated = e.created
                WHERE e.updated < :good_date'
            )->setParameters( array('good_date' => '2000-01-01 00:00:00') );
            if ($save)
                $second = $query->execute();
        }

        foreach ($bad_deleted as $classname => $num) {
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:'.$classname.' AS e
                SET e.deletedAt = NULL
                WHERE e.deletedAt < :bad_date'
            )->setParameters(
                array(
                    'bad_date' => '2000-01-01 00:00:00'
                )
            );
            if ($save)
                $first = $query->execute();
        }

        foreach ($bad_publicdate as $classname => $num) {
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:'.$classname.' AS e
                SET e.publicDate = :good_date
                WHERE e.publicDate < :bad_date'
            )->setParameters(
                array(
                    'good_date' => new \DateTime('2200-01-01 00:00:00'),
                    'bad_date' => '2000-01-01 00:00:00'
                )
            );
            if ($save)
                $first = $query->execute();
        }

        $em->getFilters()->enable('softdeleteable');
        print '</pre>';
    }


    /**
     * Looks for and creates any missing meta entries
     *
     * @param Request $request
     *
     * @return Response
     */
    public function fixmissingmetaentriesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_file = $em->getRepository('ODRAdminBundle:File');
            $repo_image = $em->getRepository('ODRAdminBundle:Image');
            $repo_radio_options = $em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            /** @var RenderPlugin $default_render_plugin */
            $default_render_plugin = $repo_render_plugin->find(1);

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            $batch_size = 100;
            $count = 0;

            // Load everything regardless of deleted status
            $em->getFilters()->disable('softdeleteable');

            // Going to run native SQL queries for this, doctrine doesn't do subqueries well
            $conn = $em->getConnection();
            print '<pre>';

            // ----------------------------------------
            // Datafields
            $query =
                'SELECT df.id AS df_id
                 FROM odr_data_fields AS df
                 WHERE df.id NOT IN (
                     SELECT DISTINCT(dfm.data_field_id)
                     FROM odr_data_fields_meta AS dfm
                 )';
            $results = $conn->fetchAll($query);

            $missing_datafields = array();
            foreach ($results as $result)
                $missing_datafields[] = $result['df_id'];

            print 'missing datafield meta entries: '."\n";
            print_r($missing_datafields);

            if ($save) {
                foreach ($missing_datafields as $num => $df_id) {
                    $df = $repo_datafield->find($df_id);

                    $dfm = new DataFieldsMeta();
                    $dfm->setDataField($df);
                    $dfm->setFieldType( $repo_fieldtype->find(9) );     // shortvarchar
                    $dfm->setRenderPlugin($default_render_plugin);

                    $dfm->setMasterRevision(0);
                    $dfm->setTrackingMasterRevision(0);
                    $dfm->setMasterPublishedRevision(0);

                    $dfm->setFieldName('New Field');
                    $dfm->setDescription('Field description');
                    $dfm->setXmlFieldName('');
                    $dfm->setRegexValidator('');
                    $dfm->setPhpValidator('');

                    $dfm->setMarkdownText('');
                    $dfm->setIsUnique(false);
                    $dfm->setRequired(false);
                    $dfm->setSearchable(0);
                    $dfm->setPublicDate( new \DateTime('2200-01-01 00:00:00') );

                    $dfm->setChildrenPerRow(1);
                    $dfm->setRadioOptionNameSort(0);
                    $dfm->setRadioOptionDisplayUnselected(0);
                    $dfm->setAllowMultipleUploads(0);
                    $dfm->setShortenFilename(0);

                    $dfm->setCreatedBy($user);
                    $dfm->setUpdatedBy($user);

                    $em->persist($dfm);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // Datarecords
            $query =
                'SELECT dr.id AS dr_id
                 FROM odr_data_record AS dr
                 WHERE dr.id NOT IN (
                     SELECT DISTINCT(drm.data_record_id)
                     FROM odr_data_record_meta AS drm
                 )';
            $results = $conn->fetchAll($query);

            $missing_datarecords = array();
            foreach ($results as $result)
                $missing_datarecords[] = $result['dr_id'];

            print 'missing datarecord meta entries: '."\n";
            print_r($missing_datarecords);

            if ($save) {
                foreach ($missing_datarecords as $num => $dr_id) {
                    $dr = $repo_datarecord->find($dr_id);

                    $drm = new DataRecordMeta();
                    $drm->setDataRecord($dr);
                    $drm->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

                    $drm->setCreatedBy($user);
                    $drm->setUpdatedBy($user);

                    $em->persist($drm);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // Datatree
            $query =
                'SELECT dt.id AS dt_id
                 FROM odr_data_tree AS dt
                 WHERE dt.id NOT IN (
                     SELECT DISTINCT(dtm.data_tree_id)
                     FROM odr_data_tree_meta AS dtm
                 )';
            $results = $conn->fetchAll($query);

            $missing_datatrees = array();
            foreach ($results as $result)
                $missing_datatrees[] = $result['dt_id'];

            print 'missing datatree meta entries:  **NO ACTION TAKEN**'."\n";
            print_r($missing_datatrees);


            // ----------------------------------------
            // Datatypes
            $query =
                'SELECT dt.id AS dt_id
                 FROM odr_data_type AS dt
                 WHERE dt.id NOT IN (
                     SELECT DISTINCT(dtm.data_type_id)
                     FROM odr_data_type_meta AS dtm
                 )';
            $results = $conn->fetchAll($query);

            $missing_datatypes = array();
            foreach ($results as $result)
                $missing_datatypes[] = $result['dt_id'];

            print 'missing datatype meta entries: '."\n";
            print_r($missing_datatypes);

            if ($save) {
                foreach ($missing_datatypes as $num => $dt_id) {
                    $dt = $repo_datatype->find($dt_id);

                    $dtm = new DataTypeMeta();
                    $dtm->setDataType($dt);
                    $dtm->setRenderPlugin($default_render_plugin);

                    $dtm->setSearchSlug($dt_id);
                    $dtm->setShortName("New Datatype");
                    $dtm->setLongName("New Datatype");
                    $dtm->setDescription("New DataType Description");
                    $dtm->setXmlShortName('');

                    $dtm->setSearchNotesUpper(null);
                    $dtm->setSearchNotesLower(null);

                    $dtm->setPublicDate( new \DateTime('1980-01-01 00:00:00') );

                    $dtm->setExternalIdField(null);
                    $dtm->setNameField(null);
                    $dtm->setSortField(null);
                    $dtm->setBackgroundImageField(null);

                    $dtm->setMasterPublishedRevision(0);
                    $dtm->setMasterRevision(0);
                    $dtm->setTrackingMasterRevision(0);

                    $dtm->setCreatedBy($user);
                    $dtm->setUpdatedBy($user);

                    $em->persist($dtm);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // Files
            $query =
                'SELECT f.id AS f_id
                 FROM odr_file AS f
                 WHERE f.id NOT IN (
                     SELECT DISTINCT(fm.file_id)
                     FROM odr_file_meta AS fm
                 )';
            $results = $conn->fetchAll($query);

            $missing_files = array();
            foreach ($results as $result)
                $missing_files[] = $result['f_id'];

            print 'missing file meta entries: '."\n";
            print_r($missing_files);

            if ($save) {
                foreach ($missing_files as $num => $f_id) {
                    $file = $repo_file->find($f_id);

                    $fm = new FileMeta();
                    $fm->setFile($file);
                    $fm->setOriginalFileName('file_name');
                    $fm->setExternalId('');
                    $fm->setPublicDate( new \DateTime('1980-01-01 00:00:00') );

                    $fm->setCreatedBy($user);
                    $fm->setUpdatedBy($user);

                    $em->persist($fm);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // Images
            $query =
                'SELECT i.id AS i_id
                 FROM odr_image AS i
                 WHERE i.id NOT IN (
                     SELECT DISTINCT(im.image_id)
                     FROM odr_image_meta AS im
                 )';
            $results = $conn->fetchAll($query);

            $missing_images = array();
            foreach ($results as $result)
                $missing_images[] = $result['i_id'];

            print 'missing image meta entries: '."\n";
            print_r($missing_images);

            if ($save) {
                foreach ($missing_images as $num => $i_id) {
                    $image = $repo_image->find($i_id);

                    $im = new ImageMeta();
                    $im->setImage($image);
                    $im->setDisplayorder(0);
                    $im->setOriginalFileName('image name');
                    $im->setCaption('image caption');
                    $im->setExternalId('');
                    $im->setPublicDate( new \DateTime('1980-01-01 00:00:00') );

                    $im->setCreatedBy($user);
                    $im->setUpdatedBy($user);

                    $em->persist($im);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // RadioOptions
            $query =
                'SELECT ro.id AS ro_id
                 FROM odr_radio_options AS ro
                 WHERE ro.id NOT IN (
                     SELECT DISTINCT(rom.radio_option_id)
                     FROM odr_radio_options_meta AS rom
                 )';
            $results = $conn->fetchAll($query);

            $missing_radio_options = array();
            foreach ($results as $result)
                $missing_radio_options[] = $result['ro_id'];

            print 'missing radio option meta entries: '."\n";
            print_r($missing_radio_options);

            if ($save) {
                foreach ($missing_radio_options as $num => $ro_id) {
                    $ro = $repo_radio_options->find($ro_id);

                    $rom = new RadioOptionsMeta();
                    $rom->setRadioOption($ro);
                    $rom->setOptionName('Option Name');
                    $rom->setXmlOptionName('');
                    $rom->setDisplayOrder(0);
                    $rom->setIsDefault(false);

                    $rom->setCreatedBy($user);
                    $rom->setUpdatedBy($user);

                    $em->persist($rom);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // Themes
            $query =
                'SELECT t.id AS t_id
                 FROM odr_theme AS t
                 WHERE t.id NOT IN (
                     SELECT DISTINCT(tm.theme_id)
                     FROM odr_theme_meta AS tm
                 )';
            $results = $conn->fetchAll($query);

            $missing_themes = array();
            foreach ($results as $result)
                $missing_themes[] = $result['t_id'];

            print 'missing theme meta entries: '."\n";
            print_r($missing_themes);

            if ($save) {
                foreach ($missing_themes as $num => $t_id) {
                    $theme = $repo_theme->find($t_id);

                    $tm = new ThemeMeta();
                    $tm->setTheme($theme);
                    $tm->setTemplateName('');
                    $tm->setTemplateDescription('');
                    $tm->setIsDefault(false);
                    $tm->setDisplayOrder(0);
                    $tm->setShared(false);
                    $tm->setIsTableTheme(false);

                    $tm->setCreatedBy($user);
                    $tm->setUpdatedBy($user);

                    $em->persist($tm);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // ThemeElements
            $query =
                'SELECT te.id AS te_id
                 FROM odr_theme_element AS te
                 WHERE te.id NOT IN (
                     SELECT DISTINCT(tem.theme_element_id)
                     FROM odr_theme_element_meta AS tem
                 )';
            $results = $conn->fetchAll($query);

            $missing_theme_elements = array();
            foreach ($results as $result)
                $missing_theme_elements[] = $result['te_id'];

            print 'missing theme element meta entries: '."\n";
            print_r($missing_theme_elements);

            if ($save) {
                foreach ($missing_theme_elements as $num => $te_id) {
                    $te = $repo_theme_element->find($te_id);

                    $tem = new ThemeElementMeta();
                    $tem->setThemeElement($te);
                    $tem->setDisplayOrder(999);
                    $tem->setCssWidthMed('1-1');
                    $tem->setCssWidthXL('1-1');
                    $tem->setHidden(0);

                    $tem->setCreatedBy($user);
                    $tem->setUpdatedBy($user);

                    $em->persist($tem);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }

            // ----------------------------------------
            if ($save)
                $em->flush();

            print '</pre>';
            // Turn the deleted filter back on
            $em->getFilters()->enable('softdeleteable');
        }
        catch (\Exception $e) {
            $em->getFilters()->enable('softdeleteable');

            $source = 0xabcdef00;
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
            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');

            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $repo_theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType');

            // Want to be able to update deleted entities as well
            $em->getFilters()->disable('softdeleteable');

            // Manually load all top-level themes
            $query = $em->createQuery(
               'SELECT partial t.{id, themeType}, partial dt.{id}
                FROM ODRAdminBundle:Theme AS t
                JOIN t.dataType AS dt
                WHERE t = t.parentTheme'
            );
            $top_level_themes = $query->getArrayResult();

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
                        partial gp_dt.{id}

                    FROM ODRAdminBundle:Theme AS t
                    JOIN t.dataType AS dt
                    JOIN t.themeElements AS te
                    JOIN te.themeDataType AS tdt
                    JOIN tdt.dataType AS c_dt
                    JOIN c_dt.grandparent AS gp_dt
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
                                        /** @var Theme $parent_theme */
                                        $parent_theme = $repo_theme->find($theme_id);
                                        /** @var Theme $source_theme */
                                        $source_theme = $repo_theme->findOneBy( array('dataType' => $c_dt_id, 'themeType' => 'master') );
                                        /** @var DataType $linked_datatype */
                                        $linked_datatype = $repo_datatype->find($c_dt_id);

                                        // Create a new theme entry for this linked datatype
                                        // Doesn't matter if one already exists, create a new one anyways
                                        $new_theme = new Theme();
                                        $new_theme->setThemeType($theme_type);
                                        $new_theme->setParentTheme($parent_theme);
                                        $new_theme->setSourceTheme($source_theme);
                                        $new_theme->setDataType($linked_datatype);

                                        $new_theme->setCreated( $parent_theme->getCreated() );
                                        $new_theme->setCreatedBy( $parent_theme->getCreatedBy() );
                                        $new_theme->setUpdated( $parent_theme->getUpdated() );
                                        $new_theme->setUpdatedBy( $parent_theme->getUpdatedBy() );
                                        $new_theme->setDeletedAt( $parent_theme->getDeletedAt() );
                                        $new_theme->setDeletedBy( $parent_theme->getDeletedBy() );

                                        if ($save) {
                                            $em->persist($new_theme);
                                            $em->flush();
                                            $em->refresh($new_theme);
                                        }

                                        $new_theme_meta = new ThemeMeta();
                                        $new_theme_meta->setTheme($new_theme);
                                        $new_theme_meta->setTemplateName( $parent_theme->getTemplateName() );
                                        $new_theme_meta->setTemplateDescription( $parent_theme->getTemplateDescription() );
                                        $new_theme_meta->setIsDefault( $parent_theme->isDefault() );
                                        $new_theme_meta->setDisplayOrder( $parent_theme->getDisplayOrder());
                                        $new_theme_meta->setShared( $parent_theme->isShared() );
//                                        $new_theme_meta->setSourceSyncCheck( $parent_theme->getSourceSyncCheck() );
                                        $new_theme_meta->setSourceSyncCheck(null);
                                        $new_theme_meta->setIsTableTheme( $parent_theme->getIsTableTheme() );

                                        $new_theme_meta->setCreated( $parent_theme->getCreated() );
                                        $new_theme_meta->setCreatedBy( $parent_theme->getCreatedBy() );
                                        $new_theme_meta->setUpdated( $parent_theme->getUpdated() );
                                        $new_theme_meta->setUpdatedBy( $parent_theme->getUpdatedBy() );
                                        $new_theme_meta->setDeletedAt( $parent_theme->getDeletedAt() );

                                        if ($save) {
                                            $new_theme->addThemeMetum($new_theme_meta);
                                            $em->persist($new_theme);

                                            $em->persist($new_theme_meta);
                                            $em->flush();
                                            $em->refresh($new_theme_meta);
                                        }
                                        print '       >> creating new theme for datatype '.$c_dt_id.', parent_theme '.$parent_theme->getId().', source_theme '.$source_theme->getId()."\n";

                                        if ($save) {
                                            /** @var ThemeDataType $theme_datatype */
                                            $theme_datatype = $repo_theme_datatype->find($tdt_id);
                                            $theme_datatype->setChildTheme($new_theme);

                                            $em->persist($theme_datatype);
                                            $em->flush();
                                            $em->refresh($theme_datatype);

                                            print '       >> setting child_theme_id to '.$new_theme->getId()."\n";
                                        }
                                        else {
                                            print '       >> setting child_theme_id to **SOMETHING**'."\n";
                                        }
                                    }
                                }
                                else if ( count($sub_results) == 1 ) {
                                    // Found the child datatype, set it
                                    if ($save) {
                                        /** @var Theme $child_theme */
                                        $child_theme = $repo_theme->find( $sub_results[0]['id'] );
                                        /** @var ThemeDataType $theme_datatype */
                                        $theme_datatype = $repo_theme_datatype->find($tdt_id);

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
     * @param $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function synchtestAction($theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneThemeService $clone_theme_service */
            $clone_theme_service = $this->container->get('odr.clone_theme_service');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $diff = $clone_theme_service->getThemeSourceDiff($theme);
            print '<pre>'.print_r($diff, true).'</pre>';
        }
        catch (\Exception $e) {
            $source = 0x0214889b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
