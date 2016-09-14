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
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class WorkerController extends ODRCustomController
{

    /**
     * Called by the recaching background process to rebuild all the different versions of a DataRecord and store them in memcached.
     * @deprecated
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function recacherecordAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $ret = '';

        try {

            throw new \Exception('DO NOT CONTINUE');

            $post = $_POST;
            if ( !isset($post['tracked_job_id']) || !isset($post['datarecord_id']) || !isset($post['api_key']) || !isset($post['scheduled_at']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $datarecord_id = $post['datarecord_id'];
            $api_key = $post['api_key'];
//            $scheduled_at = \DateTime::createFromFormat('Y-m-d H:i:s', $post['scheduled_at']);
//            $delay = new \DateInterval( 'PT1S' );    // one second delay

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
//            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');


            // ----------------------------------------
            // Grab necessary objects
            $block = false;
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new \Exception('RecacheRecordCommand.php: Recache request for deleted DataRecord '.$datarecord_id.', skipping');

            if ($datarecord->getProvisioned() == true)
                throw new \Exception('RecacheRecordCommand.php: Recache request for provisioned Datarecord '.$datarecord_id.', skipping');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() !== null)
                throw new \Exception('RecacheRecordCommand.php: Recache request involving DataRecord '.$datarecord_id.' requires deleted DataType, skipping');
            $datatype_id = $datatype->getId();


            // ----------------------------------------
            // See if there are migration jobs in progress for this datatype
            $tracked_job = $repo_tracked_job->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null) {
                $target_entity = $tracked_job->getTargetEntity();
                $tmp = explode('_', $target_entity);
                $datafield_id = $tmp[1];

                $ret = 'RecacheRecordCommand.php: Datafield '.$datafield_id.' is currently being migrated to a different fieldtype...'."\n";
                $return['r'] = 2;
                $block = true;
            }

            $tracked_job = null;
            $tracked_job_target = null;
            if (!$block) {
                // TODO - can this get moved to later, or does it have to stay here...STAYS HERE, UNTIL SYSTEM NEEDS TO GET TORN APART
                // Stores tracked job target incase ODRCustomController:updateDatatypeCache() starts another update while this action is running
                if ($tracked_job_id !== -1) {
                    $tracked_job = $repo_tracked_job->find($tracked_job_id);
                    if ($tracked_job !== null)
                        $tracked_job_target = $tracked_job->getRestrictions();
                }
            }
            /** @var TrackedJob $tracked_job */


            // ----------------------------------------
            if (!$block) {

                // ----------------------------------------
                // Ensure the memcached version of the datarecord's datatype exists
                $datatype_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$datatype_id)));
                if ($datatype_data == false)
                    self::getDatatypeData($em, self::getDatatreeArray($em), $datatype_id);

                // Ensure the memcached version of the datarecord exists
                $datarecord_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datarecord_'.$datarecord_id)));
                if ($datarecord_data == false)
                    self::getDatarecordData($em, $datarecord_id);

                // Ensure the memcached version of the datarecord's
                $datarecord_table_data = parent::getRedisData(($redis->get($redis_prefix.'.datarecord_table_data_'.$datarecord_id)));
                if ($datarecord_table_data == false)
                    self::Text_GetDisplayData($em, $datarecord_id, $request);

                // Also recreate the XML version of the datarecord
                $xml_export_path = dirname(__FILE__).'/../../../../web/uploads/xml_export/';

                // Ensure directory exists
                if ( !file_exists($xml_export_path) )
                    mkdir( $xml_export_path );

                $filename = 'DataRecord_'.$datarecord_id.'.xml';
                $handle = fopen($xml_export_path.$filename, 'w');
                if ($handle !== false) {
                    $content = parent::XML_GetDisplayData($em, $datarecord->getId(), $request);
                    fwrite($handle, $content);
                    fclose($handle);
                }

$ret .= '>> Recached DataRecord '.$datarecord->getId()."\n";
$logger->info('WorkerController::recacherecordAction() >> Recached DataRecord '.$datarecord->getId());

                // Update the job tracker if necessary
                if ($tracked_job_id !== -1 && $tracked_job !== null) {

                    $em->refresh($tracked_job);

$ret .= '  original tracked_job_target: '.$tracked_job_target.'  current tracked_job_target: '.$tracked_job->getRestrictions()."\n";

                    if ( $tracked_job !== null && intval($tracked_job_target) == intval($tracked_job->getRestrictions()) ) {
                        $total = $tracked_job->getTotal();
                        $count = $tracked_job->incrementCurrent($em);

                        if ($count >= $total)
                            $tracked_job->setCompleted( new \DateTime() );
                        
                        $em->persist($tracked_job);
                        $em->flush();
$ret .= '  Set current to '.$count."\n";
                    }
                }

            }
            else {
$ret = '>> Ignored update request for DataRecord '.$datarecord->getId()."\n";
$logger->info('WorkerController::recacherecordAction() >> Ignored update request for DataRecord '.$datarecord->getId());
            }

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            // TODO - increment tracked job counter on error?

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x6642397853 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


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
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

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
                throw new \Exception('Datarecord '.$datarecord_id.' is deleted');
            if ($datafield == null)
                throw new \Exception('Datafield '.$datafield_id.' is deleted');


            // Create a new datarecord field entity if it doesn't exist
            $em->refresh($datafield);
            /** @var DataRecordFields $drf */
            $drf = $repo_datarecordfields->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()) );
            if ($drf == null) {
                $drf = parent::ODR_addDataRecordField($em, $user, $datarecord, $datafield);
                $em->flush();
                $em->refresh($drf);
            }

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
                $src_entity = $src_repository->findOneBy(array('dataField' => $datafield->getId(), 'dataRecordFields' => $drf->getId()));

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

                        $value = intval($new_value);
                    }
                    else if ( ($old_typeclass == 'ShortVarchar' || $old_typeclass == 'MediumVarchar' || $old_typeclass == 'LongVarchar' || $old_typeclass == 'LongText') && ($new_typeclass == 'DecimalValue') ) {
                        // text -> decimal
                        $pattern = '/[^0-9\.\-]+/i';
                        $replacement = '';
                        $new_value = preg_replace($pattern, $replacement, $src_entity->getValue());

                        $value = floatval($new_value);
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


                    // TODO - code to directly update the cached version of the datarecord
                    // Locate and clear the cache entry for this datarecord
                    $grandparent_datarecord_id = $datarecord->getGrandparent()->getId();
                    $redis->del($redis_prefix.'.cached_datarecord_'.$grandparent_datarecord_id);
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

/*
            // ----------------------------------------
            // Schedule the datarecord for an update
            $options = array();
            parent::updateDatarecordCache($datarecord_id, $options);

            $logger->info('WorkerController:migrateAction()  >> scheduled DataRecord '.$datarecord_id.' for update');
            $ret .= '>> scheduled DataRecord '.$datarecord_id.' for update'."\n";
*/
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            // TODO - increment tracked job counter on error?

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x6642397856: '.$e->getMessage()."\n".$ret;
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $api_key = $this->container->getParameter('beanstalk_api_key');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
//            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            // TODO - check for permissions?  restrict rebuild of thumbnails to certain datatypes?

            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
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
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38472782 ' . $e->getMessage();
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
            // Ensure the full-size image exists
            parent::decryptObject($object_id, $object_type);

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var Image $img */
            $img = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            if ($img == null)
                throw new \Exception('Image '.$object_id.' has been deleted');

            /** @var User $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find(2);    // TODO - need an actual system user...


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
     * TODO
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

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['crypto_type']) || !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['target_filepath']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $crypto_type = $post['crypto_type'];
            $object_type = strtolower( $post['object_type'] );
            $object_id = $post['object_id'];
            $target_filepath = $post['target_filepath'];
            $api_key = $post['api_key'];

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $crypto = $this->get("dterranova_crypto.crypto_adapter");
            $crypto_dir = dirname(__FILE__).'/../../../../app/crypto_dir/';     // TODO - load from config file somehow?

            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            if ( !is_numeric($post['object_id']) )
                throw new \Exception('Invalid Form');
            else
                $object_id = intval($object_id);


            // ----------------------------------------
            if ($crypto_type == 'encrypt') {

                // ----------------------------------------
                // Locate the directory with the encrypted file chunks
                $base_obj = null;
                if ($object_type == 'file') {
                    $crypto_dir .= 'File_'.$object_id;
                    $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
                }
                /** @var File $base_obj */
/*
                else if ($object_type == 'image') {
                    $crypto_dir .= 'Image_'.$object_id;
                    $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
                }
*/
                if ($base_obj == null)
                    throw new \Exception('Invalid Form');

                // Move file from completed directory to decrypted directory in preparation for encryption...
                $destination_path = dirname(__FILE__).'/../../../../web';
                $destination_filename = $base_obj->getUploadDir().'/File_'.$object_id.'.'.$base_obj->getExt();
                rename( $base_obj->getLocalFileName(), $destination_path.'/'.$destination_filename );

                // Update local filename in database...
                $base_obj->setLocalFileName($destination_filename);

                // Encryption of a given file/image is simple...
                parent::encryptObject($object_id, $object_type);

                // Calculate/store the checksum of the file to indicate the encryption process is complete
                $filepath = parent::decryptObject($object_id, $object_type);
                $original_checksum = md5_file($filepath);
                $base_obj->setOriginalChecksum($original_checksum);

                $em->persist($base_obj);
                $em->flush();
                $em->refresh($base_obj);

                // Delete the decrypted version of the file/image off the server after it's completed
                unlink($filepath);


                // Update the datarecord cache so whichever controller is handling the "are you done encrypting yet?" javascript requests can return the correct HTML
                $datarecord = $base_obj->getDataRecord();
                parent::tmp_updateDatarecordCache($em, $datarecord, $base_obj->getCreatedBy());

                // TODO - run graph plugin on new file
            }
            else {
/*
                // ...otherwise, need to manually decrypt all file chunks and write them to the specified file

                // ----------------------------------------
                // Grab the hex string representation that the file was encrypted with
                $key = $base_obj->getEncryptKey();
                // Convert the hex string representation to binary...php had a function to go bin->hex, but didn't have a function for hex->bin for at least 7 years?!?
                $key = pack("H*" , $key);   // don't have hex2bin() in current version of php...this appears to work based on the "if it decrypts to something intelligible, you did it right" theory


                // ----------------------------------------
                // Open the target file
                $handle = fopen($target_filepath, "wb");
                if (!$handle)
                    throw new \Exception('Unable to open "'.$target_filepath.'" for writing');

                // Decrypt each chunk and write to target file
                $chunk_id = 0;
                while( file_exists($crypto_dir.'/'.'enc.'.$chunk_id) ) {
                    if ( !file_exists($crypto_dir.'/'.'enc.'.$chunk_id) )
                        throw new \Exception('Encrypted chunk not found: '.$crypto_dir.'/'.'enc.'.$chunk_id);

                    $data = file_get_contents($crypto_dir.'/'.'enc.'.$chunk_id);
                    fwrite($handle, $crypto->decrypt($data, $key));
                    $chunk_id++;
                }
                fclose($handle);

                // Don't need to do anything else
*/
/*
                $obj = null;
                if ( strtolower($object_type) == 'file')
                    $obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
                else if ( strtolower($object_type) == 'image')
                    $obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
                else
                    throw new \Exception('Invalid Form, got "'.strtolower($object_type).'"');

                if ($obj == null)
                    throw new \Exception('Deleted '.$object_type);


                $absolute_path = parent::decryptObject($object_id, $object_type);

                if ( strtolower($object_type) == 'file') {
                    clearstatcache(true, $absolute_path);
                    $filesize = filesize($absolute_path);
                    $checksum = md5_file($absolute_path);

                    $obj->setFilesize($filesize);
                    $obj->setOriginalChecksum($checksum);
                    $em->persist($obj);
                    $em->flush();
                }
                else if ( strtolower($object_type) == 'image') {
                    $checksum = md5_file($absolute_path);

                    $obj->setOriginalChecksum($checksum);
                    $em->persist($obj);
                    $em->flush();
                }

                unlink($absolute_path);
*/
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x65384782 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...checks for non-deleted datarecord entities belonging to deleted datatypes
     *
     * @param Request $request
     *
     * @return string
     */
    public function drcheckAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT dt.id AS dt_id, dr.id AS dr_id
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
            WHERE dt.deletedAt IS NOT NULL AND dr.deletedAt IS NULL
            ORDER BY dt.id, dr.id');
        $results = $query->getArrayResult();
        $em->getFilters()->enable('softdeleteable');

print '<pre>';
        foreach ($results as $result) {
            $datatype_id = $result['dt_id'];
            $datarecord_id = $result['dr_id'];

            print 'DataRecord '.$datarecord_id.' (DataType '.$datatype_id.') was not deleted'."\n";

            // Delete DataRecordField entries for this datarecord
            // TODO - do this with a DQL update query?
            $query = $em->createQuery(
               'SELECT drf.id AS drf_id
                FROM ODRAdminBundle:DataRecordFields AS drf
                WHERE drf.dataRecord = :datarecord'
            )->setParameters( array('datarecord' => $datarecord_id) );
            $results = $query->getResult();
            foreach ($results as $num => $data) {
                $drf_id = $data['drf_id'];
                print '-- deleting drf '.$drf_id."\n";

                /** @var DataRecordFields $drf */
                $drf = $repo_datarecordfields->find($drf_id);
                $em->remove($drf);
            }

            /** @var DataRecord $datarecord */
            $datarecord = $repo_datarecord->find($datarecord_id);
            $em->remove($datarecord);
        }
print '</pre>';

        $em->flush();
    }


    /**
     * Debug function...checks for orphaned datarecordfield entities that somehow didn't get deleted after a datafield got deleted
     *
     * @param Request $request
     *
     * @return string
     */
    public function dfcheckAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT df.id AS df_id, ft.typeName AS type_name
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
            JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
            JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
            WHERE df.deletedAt IS NOT NULL AND dt.deletedAt IS NULL AND dfm.deletedAt IS NULL
            ORDER BY df.id'
        );
        $datafields = $query->getResult();
        $em->getFilters()->enable('softdeleteable');

print '<pre>';
        foreach ($datafields as $tmp) {
            $datafield_id = $tmp['df_id'];
            $type_name = $tmp['type_name'];
            print "\n".'DataField '.$datafield_id.' ('.$type_name.')'."\n";

            $query = $em->createQuery(
               'SELECT drf.id AS drf_id, dr.id AS dr_id
                FROM ODRAdminBundle:DataRecordFields AS drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE drf.dataField = :datafield
                AND drf.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            $results = $query->getResult();

            $entries = array();
            foreach ($results as $result) {
                $datarecord_id = $result['dr_id'];
                $datarecordfield_id = $result['drf_id'];

                print '-- drf '.$datarecordfield_id.' (dr '.$datarecord_id.', df '.$datafield_id.') was not deleted'."\n";

                /** @var DataRecordFields $drf */
                $drf = $repo_datarecordfields->find($datarecordfield_id);
                $em->remove($drf);
            }
            print "\n";
        }

        $em->flush();

print '</pre>';
    }


    /**
     * Debuf function...checks for duplicate datarecordfield entities (those that share the same datarecord/datafield key pair)
     * TODO - check for non-deleted drf entities that point to deleted datarecords? 
     *
     * @param integer $datatype_id
     * @param Request $request
     * 
     * @return string
     */
    public function drfcheckAction($datatype_id, Request $request)
    {
        $delete_entities = true;
        $delete_entities = false;

        $deleted_entities = array();
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

        $query = $em->createQuery(
           'SELECT df.id AS df_id, ft.typeName AS type_name
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
            JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
            JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
            WHERE dt.id = :datatype
            AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL AND df.deletedAt IS NULL AND dt.deletedAt IS NULL
            ORDER BY df.id'
        )->setParameters( array('datatype' => $datatype_id) );
        $datafields = $query->getArrayResult();
print '<pre>';
        foreach ($datafields as $tmp) {
            $datafield_id = $tmp['df_id'];
            $type_name = $tmp['type_name'];
            print "\n".'DataField '.$datafield_id.' ('.$type_name.')'."\n";

            $query = $em->createQuery(
               'SELECT drf.id AS drf_id, dr.id AS dr_id
                FROM ODRAdminBundle:DataRecordFields AS drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE drf.dataField = :datafield
                AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            $results = $query->getResult();

//print_r($results);

            $entries = array();
            foreach ($results as $result) {
                $datarecord_id = $result['dr_id'];
                $datarecordfield_id = $result['drf_id'];

                if ( isset($entries[$datarecord_id]) ) {
                    print '-- DataRecord '.$datarecord_id.': duplicate datarecordfield entry '.$datarecordfield_id.' (had '.$entries[$datarecord_id].')'."\n";

                    /** @var DataRecordFields $old_drf */
                    $old_drf = $repo_datarecordfields->find($entries[$datarecord_id]);
                    /** @var DataRecordFields $new_drf */
                    $new_drf = $repo_datarecordfields->find($datarecordfield_id);
                    $fieldtype = $old_drf->getDataField()->getFieldType()->getTypeClass();
                    $skip = false;
                    $delete_new_drf = true;
                    switch ($fieldtype) {
                        case 'Radio':
                            $query = $em->createQuery(
                               'SELECT drf.id AS drf_id, rom.optionName AS option_name
                                 FROM ODRAdminBundle:RadioOptions AS ro
                                 JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                                 JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.radioOption = ro
                                 JOIN ODRAdminBundle:DataRecordFields AS drf WITH rs.dataRecordFields = drf
                                 WHERE ro.dataField = :datafield AND (drf.id = :old_drf OR drf.id = :new_drf) AND rs.selected = 1
                                 AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL AND rs.deletedAt IS NULL AND drf.deletedAt IS NULL'
                            )->setParameters( array('datafield' => $datafield_id, 'old_drf' => $old_drf->getId(), 'new_drf' => $new_drf->getId()) );
                            $sub_results = $query->getResult();
//print_r($sub_results);
                            $old = array();  $new = array();
                            foreach ($sub_results as $sub_result) {
                                $drf_id = $sub_result['drf_id'];
                                $option_name = $sub_result['option_name'];
                                if ($drf_id == $old_drf->getId())
                                    $old[] = $option_name;
                                else
                                    $new[] = $option_name;
                            }
                            $old_str = implode(', ', $old);
                            $new_str = implode(', ', $new);
                            if ( strcmp($old_str, $new_str) !== 0 )
                                print '-- -- old values: "'.implode(', ', $old).'" new values: "'.implode(', ', $new).'"'."\n";
                            else
                                print '-- -- values are identical'."\n";

                            if ( $old_str == '' && $new_str != '' )
                                $delete_new_drf = false;
                            else if ( $old_str != '' && $new_str != '' )
                                $skip = true;

                            break;

                        case 'DatetimeValue':
                            if ($old_drf->getAssociatedEntity() == null) {
                                // old drf doesn't point to a storage entity, get rid of it
                                $delete_new_drf = false;
                                print '-- -- no old value'."\n";
                            }
                            else if ($new_drf->getAssociatedEntity() == null) {
                                // new drf doesn't point to a storage entity, get rid of it
                                $delete_new_drf = true;
                                print '-- -- no new value'."\n";
                            }
                            else {
                                $old_value = $old_drf->getAssociatedEntity()->getValue();
                                $old_value = $old_value->format('Y-m-d');
                                $new_value = $new_drf->getAssociatedEntity()->getValue();
                                $new_value = $new_value->format('Y-m-d');

                                if ( strcmp($old_value, $new_value) !== 0 )
                                    print '-- -- old value: "'.$old_value.'" new value: "'.$new_value.'"'."\n";
                                else
                                    print '-- -- values are identical'."\n";

                                if ( $old_value == '9999-12-31' && $new_value != '9999-12-31' )
                                    $delete_new_drf = false;
                                else if ( $old_value != '9999-12-31' && $new_value != '9999-12-31' )
                                    $skip = true;
                            }

                            break;

                        case 'Boolean':
                        case 'ShortVarchar':
                        case 'MediumVarchar':
                        case 'LongVarchar':
                        case 'LongText':
                        case 'IntegerValue':
                            if ($old_drf->getAssociatedEntity() == null) {
                                // old drf doesn't point to a storage entity, get rid of it
                                $delete_new_drf = false;
                                print '-- -- no old value'."\n";
                            }
                            else if ($new_drf->getAssociatedEntity() == null) {
                                // new drf doesn't point to a storage entity, get rid of it
                                $delete_new_drf = true;
                                print '-- -- no new value'."\n";
                            }
                            else {
                                $old_value = $old_drf->getAssociatedEntity()->getValue();
                                $new_value = $new_drf->getAssociatedEntity()->getValue();
                                if ( strcmp($old_value, $new_value) !== 0 )
                                    print '-- -- old value: "'.$old_value.'" new value: "'.$new_value.'"'."\n";
                                else
                                    print '-- -- values are identical'."\n";

                                if ( $old_value == '' && $new_value != '' )
                                    $delete_new_drf = false;
                                else if ( $old_value != '' && $new_value != '' )
                                    $skip = true;
                            }

                            break;

                        case 'File':
                        case 'Image':
                            $skip = true;
                            break;
                    }

                    if (!$skip) {
                        if ($delete_new_drf) {
                            $deleted_entities[] = $new_drf->getId();
                            if ($delete_entities) {
                                $em->remove($new_drf);
                                print '-- >> new drf deleted'."\n";
                            }
                            else {
                                print '-- >> new drf would be deleted'."\n";
                            }
                        }
                        else {
                            $deleted_entities[] = $old_drf->getId();
                            if ($delete_entities) {
                                $em->remove($old_drf);
                                print '-- >> old drf deleted'."\n";
                            }
                            else {
                                print '-- >> old drf would be deleted'."\n";
                            }
                        }

                        if ($delete_entities)
                            $em->flush();
                    }

                }
                else {
                    $entries[$datarecord_id] = $datarecordfield_id;
                }
            }
        }

if ($delete_entities)
   print "\n".'Deleted these drf entities...'."\n";
else
   print "\n".'Would deleted these drf entities...'."\n";

print_r($deleted_entities);
print '</pre>';
    }


    /**
     * Debug function...check for duplicate storage entities (those with the same datarecord/datafield key pair)
     * TODO ...
     * 
     * @param Request $request
     * 
     * @return string
     */
    public function entitycheckAction($datatype_id, Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

print '<pre>';

        $all_entities = array('boolean', 'file', 'image', 'integerValue', 'longText', 'longVarchar', 'mediumVarchar', 'radioSelection', 'shortVarchar', 'datetimeValue', 'decimalValue');
        $single_entities = array('boolean', /*'file', 'image',*/ 'integerValue', 'longText', 'longVarchar', 'mediumVarchar', /*'radioSelection',*/ 'shortVarchar', 'datetimeValue', 'decimalValue');

        $table_names = array(
            'shortVarchar' => 'odr_short_varchar',
            'mediumVarchar' => 'odr_medium_varchar',
            'longVarchar' => 'odr_long_varchar',
            'longText' => 'odr_long_text',

            'integerValue' => 'odr_integer_value',
            'decimalValue' => 'odr_decimal_value',
            'datetimeValue' => 'odr_datetime_value',

            'boolean' => 'odr_boolean',

            'file' => 'odr_file',
            'image' => 'odr_image',

            'radioSelection' => 'DO NOT RUN',
        );

        $query = $em->createQuery(
           'SELECT dr.id AS dr_id
            FROM ODRAdminBundle:DataRecord AS dr
            WHERE dr.dataType = :datatype_id
            AND dr.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype_id) );
        $results = $query->getArrayResult();

        $datarecord_ids = array();
        foreach ($results as $result)
            $datarecord_ids[] = $result['dr_id'];

        $queries = array();

        $step = 50;
        $count = 0;
        while (true) {
            $dr_ids = array();
            for ($i = $count; $i < ($count + $step); $i++) {
                if ( !isset($datarecord_ids[$i]) )
                    break;

                $dr_ids[] = $datarecord_ids[$i];
            }
print '-- '.$count."\n";
            if ( count($dr_ids) == 0 )
                break;

            $count += $step;

            $query = $em->createQuery(
               'SELECT dr, drf, e_f, e_i, e_b, e_iv, e_dv, e_lt, e_lvc, e_mvc, e_svc, e_dtv, rs, df, dfm, ft
                FROM ODRAdminBundle:DataRecord AS dr
                LEFT JOIN dr.dataRecordFields AS drf
                LEFT JOIN drf.file AS e_f
                LEFT JOIN drf.image AS e_i
                LEFT JOIN drf.boolean AS e_b
                LEFT JOIN drf.integerValue AS e_iv
                LEFT JOIN drf.decimalValue AS e_dv
                LEFT JOIN drf.longText AS e_lt
                LEFT JOIN drf.longVarchar AS e_lvc
                LEFT JOIN drf.mediumVarchar AS e_mvc
                LEFT JOIN drf.shortVarchar AS e_svc
                LEFT JOIN drf.datetimeValue AS e_dtv
                LEFT JOIN drf.radioSelection AS rs
                    
                LEFT JOIN drf.dataField AS df
                LEFT JOIN df.dataFieldMeta AS dfm
                LEFT JOIN dfm.fieldType AS ft
                
                WHERE dr.id IN (:datarecord_ids)
                    AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL'
            )->setParameters(array('datarecord_ids' => $dr_ids));
            $results = $query->getArrayResult();

            foreach ($results as $num => $dr) {
                foreach ($dr['dataRecordFields'] as $drf_num => $drf) {
                    //
                    $entity = '';
                    foreach ($all_entities as $typeclass) {
                        if ( count($drf[$typeclass]) > 0 ) {
                            if ($entity == '')
                                $entity = $typeclass;
                            else {
                                $dr_id = $dr['id'];
                                $df_id = $drf['dataField']['id'];
                                $fieldtype = lcfirst( $drf['dataField']['dataFieldMeta'][0]['fieldType']['typeClass'] );

                                print 'datarecord '.$dr_id.' df '.$df_id.' has both a "'.$entity.'" and a "'.$typeclass.'" entry...current fieldtype is "'.$fieldtype.'"'."\n";

                                $old_fieldtype = '';
                                if ($fieldtype == 'radio') {
                                    if ($entity == 'radioSelection')
                                        $old_fieldtype = $typeclass;
                                    else
                                        $old_fieldtype = $entity;
                                }
                                else if ($fieldtype == $entity) {
                                    $old_fieldtype = $typeclass;
                                }
                                else {
                                    $old_fieldtype = $entity;
                                }

                                if ( !isset($queries[$df_id]) )
                                    $queries[$df_id] = 'UPDATE '.$table_names[$old_fieldtype].' SET deletedAt = NOW() WHERE deletedAt IS NULL AND data_field_id = '.$df_id.';';
                            }
                        }
                    }

                    foreach ($single_entities as $typeclass) {
                        if ( count($drf[$typeclass]) > 1 )
                            print 'datarecord '.$dr['id'].' drf '.$drf['dataField']['id'].' has multiples entries for the "'.$typeclass.'" typeclass'."\n";
                    }
                }
            }

//break;
        }

print "\n\n\n";
foreach ($queries as $df_id => $query)
    print $query."\n";
print '</pre>';
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
            else if ($object_type == 'image' || $object_type == 'Image')
                $object_type = 'Image';
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
            else if ($object_type == 'Image') {
                $query = $em->createQuery(
                    'SELECT e.id
                    FROM ODRAdminBundle:'.$object_type.' AS e
                    WHERE e.deletedAt IS NULL AND e.original_checksum IS NULL'
                );
            }
            $results = $query->getResult();

            $object_type = strtolower($object_type);
            foreach ($results as $num => $file) {
                // Insert the new job into the queue
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "object_type" => $object_type,
                        "object_id" => $file['id'],
                        "target_filepath" => '',
                        "crypto_type" => 'decrypt',
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
     * Called by the mass_encrypt worker background process to decrypt a single file/image.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function decryptAction(Request $request)
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
            $em = $this->getDoctrine()->getManager();

            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            $obj = null;
            if ($object_type == 'file')
                $obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
//            else if ($object_type == 'image')
//                $obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);

            $absolute_path = parent::decryptObject($object_id, $object_type);
            $return['d'] = '>> Decrypted '.$object_type.' '.$object_id."\n";

            $obj->setFilesize( filesize($absolute_path) );

            $em->persist($obj);
            $em->flush();

            unlink($absolute_path);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38373431 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
*/
    }


    /**
     * Debug function...deletes radio selection entities belonging to deleted radio options
     *
     * @param Request $request
     *
     */
    public function deletedradiocheckAction(Request $request)
    {
//return;
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT ro.id AS ro_id
            FROM ODRAdminBundle:RadioOptions AS ro
            WHERE ro.deletedAt IS NOT NULL
            ORDER BY ro.id');
        $results = $query->getArrayResult();
        $em->getFilters()->enable('softdeleteable');

        foreach ($results as $num => $tmp) {
            $radio_option_id = $tmp['ro_id'];

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:RadioSelection AS rs
                SET rs.deletedAt = :now
                WHERE rs.radioOption = :radio_option_id AND rs.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'radio_option_id' => $radio_option_id) );
//            $updated = $query->execute();
        }
    }


    /**
     * displays/deletes duplicate radio selection options
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return string
     */
    public function duplicateradiocheckAction($datatype_id, Request $request)
    {
        $delete = true;
        $delete = false;

        // Get necessary objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

        // Figure out how many radio selection entities exist for this datatype
        $query =
            'SELECT COUNT(rs.id) AS num
            FROM odr_radio_selection AS rs
            LEFT JOIN odr_data_record_fields AS drf ON rs.data_record_fields_id = drf.id
            LEFT JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
            WHERE dr.data_type_id = :datatype_id
            AND rs.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL
            ORDER BY drf.id, rs.radio_option_id';
        $parameters = array('datatype_id' => $datatype_id);

        // Execute and return the native SQL query
        $conn = $em->getConnection();
        $results = $conn->fetchAll($query, $parameters);

        $total = $results[0]['num'];
        $step = 50000;  // probably a bit low, but only took ~2 minutes to find duplicates in a little more than 1 mil entries

        $duplicates = array();

        $previous_drf_id = 0;
        $previous_ro_id = 0;
        $previous_rs_id = 0;

        for ( $i = 0; $i <= $total; $i += $step ) {

            $query =
               'SELECT drf.id AS drf_id, rs.radio_option_id AS ro_id, rs.id AS rs_id, rs.selected
                FROM odr_radio_selection AS rs
                LEFT JOIN odr_data_record_fields AS drf ON rs.data_record_fields_id = drf.id
                LEFT JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
                WHERE dr.data_type_id = :datatype_id
                AND rs.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL
                ORDER BY drf.id, rs.radio_option_id';
            $query .= ' LIMIT '.$i.', '.$step;

            $parameters = array('datatype_id' => $datatype_id);

            // Execute and return the native SQL query
            $conn = $em->getConnection();
            $results = $conn->fetchAll($query, $parameters);

            foreach ($results as $result) {
                $drf_id = $result['drf_id'];
                $ro_id = $result['ro_id'];
                $rs_id = $result['rs_id'];

                // datarecordfield id and radio option id should be unique...
                if ($drf_id == $previous_drf_id && $ro_id == $previous_ro_id) {
                    $duplicates[] = $previous_rs_id;

                    $previous_rs_id = $rs_id;
                }
                else {
                    $previous_drf_id = $drf_id;
                    $previous_ro_id = $ro_id;
                    $previous_rs_id = $rs_id;
                }
            }
        }

        if (!$delete)
            print_r($duplicates);

        if ($delete) {
            $deleted_count = 0;
            foreach ($duplicates as $num => $rs_id) {
                /** @var RadioSelection $rs */
                $rs = $repo_radio_selection->find($rs_id);
                $em->remove($rs);

                $deleted_count++;
                if ( ($deleted_count % 50) == 0 )   // no idea what a good number is
                    $em->flush();
            }

            $em->flush();
        }
    }


    /**
     * @param Request $request
     *
     * @return Response
     * @throws \Exception
     */
    public function startbuildgroupdataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');

            $redis = $this->container->get('snc_redis.default');;
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

            $api_key = $this->container->getParameter('beanstalk_api_key');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------


            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_group_metadata');


            $top_level_datatypes = parent::getTopLevelDatatypes();
            foreach ($top_level_datatypes as $num => $dt_id) {
                // Insert the new job into the queue
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "object_type" => 'datatype',
                        "object_id" => $dt_id,
                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "url" => $url,
                        "api_key" => $api_key,
                    )
                );
                $delay = 1;
                $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = '0x1878321483: '.$e->getMessage();
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
    public function buildgroupdataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            throw new \Exception('DO NOT CONTINUE');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($object_id);
//            if ($datatype == null)
//                throw new \Exception('Deleted Datatype');

            // Determine whether this top-level datatype has the default groups already...
            $repo_group = $em->getRepository('ODRAdminBundle:Group');
            $repo_datatype_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
            $repo_datafield_permissions = $em->getRepository('ODRAdminBundle:UserFieldPermissions');

            $group_data = null;
            $admin_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'admin') );
            if ($admin_group == false) {
                $group_data = parent::ODR_createGroup($em, $datatype->getCreatedBy(), $datatype, 'admin');
                $admin_group = $group_data['group'];
            }

            $group_data = null;
            $edit_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'edit_all') );
            if ($edit_group == false) {
                $group_data = parent::ODR_createGroup($em, $datatype->getCreatedBy(), $datatype, 'edit_all');
                $edit_group = $group_data['group'];
            }

            $group_data = null;
            $view_all_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'view_all') );
            if ($view_all_group == false) {
                $group_data = parent::ODR_createGroup($em, $datatype->getCreatedBy(), $datatype, 'view_all');
                $view_all_group = $group_data['group'];
            }

            $group_data = null;
            $view_only_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'view_only') );
            if ($view_only_group == false) {
                $group_data = parent::ODR_createGroup($em, $datatype->getCreatedBy(), $datatype, 'view_only');
                $view_only_group = $group_data['group'];
            }


            // Need to add users to these new groups...
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var User[] $user_list */
            $user_list = $user_manager->findUsers();

$ret = 'Created default groups for datatype '.$datatype->getId()."...\n";

            $users_with_groups = array();

            // Locate all users without the super-admin role that have the current 'is_datatype_admin' permission...
            foreach ($user_list as $user) {
                if ( !$user->hasRole('ROLE_SUPER_ADMIN') ) {
                    $datatype_permission = $repo_datatype_permissions->findOneBy( array('is_type_admin' => 1, 'user' => $user->getId(), 'dataType' => $datatype->getId()) );
                    if ($datatype_permission != false) {
                        // ...add this user to the new default admin group
                        parent::ODR_createUserGroup($em, $user, $admin_group, $datatype->getCreatedBy());
$ret .= ' -- added '.$user->getUserString().' to the default "admin" group'."\n";

                        // Note that this user is in the admin group, and therefore don't add them to any other default group
                        $users_with_groups[ $user->getId() ] = 1;
                    }
                }
            }

            // Locate all users without the super-admin role that have the current 'can_edit_datarecord' permission...
            foreach ($user_list as $user) {
                if ( !$user->hasRole('ROLE_SUPER_ADMIN') && !isset($users_with_groups[$user->getId()]) ) {
                    $datatype_permission = $repo_datatype_permissions->findOneBy( array('can_edit_record' => 1, 'user' => $user->getId(), 'dataType' => $datatype->getId()) );
                    if ($datatype_permission != false) {
                        // ...add this user to the new default edit_all group
                        parent::ODR_createUserGroup($em, $user, $edit_group, $datatype->getCreatedBy());
$ret .= ' -- added '.$user->getUserString().' to the default "edit_all" group'."\n";

                        // Note that this user is in the edit_all group, and therefore don't add them to any other default group
                        $users_with_groups[ $user->getId() ] = 1;
                    }
                }
            }

            // Locate all users without the super-admin role that have the current 'can_view_datatype' permission...
            foreach ($user_list as $user) {
                if ( !$user->hasRole('ROLE_SUPER_ADMIN') && !isset($users_with_groups[$user->getId()]) ) {
                    $datatype_permission = $repo_datatype_permissions->findOneBy( array('can_view_type' => 1, 'user' => $user->getId(), 'dataType' => $datatype->getId()) );
                    if ($datatype_permission != false) {
                        // ...add this user to the new default view_all group
                        parent::ODR_createUserGroup($em, $user, $view_all_group, $datatype->getCreatedBy());
$ret .= ' -- added '.$user->getUserString().' to the default "view_all" group'."\n";

                        // Note that this user is in the view_all group, and therefore don't add them to any other default group
                        $users_with_groups[ $user->getId() ] = 1;
                    }
                }
            }

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x212738622 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
