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
                        $value = new \DateTime('0000-00-00 00:00:00');
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

                                if ( $old_value == '-0001-11-30' && $new_value != '-0001-11-30' )
                                    $delete_new_drf = false;
                                else if ( $old_value != '-0001-11-30' && $new_value != '-0001-11-30' )
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
    public function entitycheckAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

print '<pre>';
        $entities = array('Boolean', /*'File', 'Image',*/ 'IntegerValue', 'LongText', 'LongVarchar', 'MediumVarchar', /*'Radio',*/ 'ShortVarchar', 'DatetimeValue', 'DecimalValue');
        foreach ($entities as $entity) {
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT e.id AS e_id, dr.id AS dr_id, df.id AS df_id, e.value AS value, drf.id AS drf_id, drf.deletedAt AS drf_deletedAt
                FROM ODRAdminBundle:'.$entity.' e
                JOIN ODRAdminBundle:DataRecord AS dr WITH e.dataRecord = dr
                JOIN ODRAdminBundle:DataFields AS df WITH e.dataField = df
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                WHERE e.deletedAt IS NULL AND dr.deletedAt IS NULL AND df.deletedAt IS NULL AND drf.deletedAt IS NOT NULL
                ORDER BY dr.id, df_id, e.id');
            $iterableResult = $query->iterate();
print $entity."\n";

            $prev_dr = $prev_df = $prev_e = $prev_value = null;
            $i = 0;
            foreach ($iterableResult as $result) {
//print_r($result);
                $row = $result[$i];
                $e_id = $row['e_id'];
                $dr_id = $row['dr_id'];
                $df_id = $row['df_id'];
                $drf_id = $row['drf_id'];

                $drf_deletedAt = $row['drf_deletedAt'];
                $drf_deletedAt = $drf_deletedAt->format('Y-m-d');

                $value = '';
                if ($entity == 'DatetimeValue') {
                    $e = $row['value'];
                    $value = $e->format('Y-m-d');
                }
                else {
                    $value = $row['value'];
                }

                print 'Datarecord '.$dr_id.' Datafield '.$df_id."\n";
                print '-- entity '.$e_id.' points to deleted drf '.$drf_id."\n";

                $i++;
            }

            $em->getFilters()->enable('softdeleteable');
print "\n\n";
        }
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
     */
    public function startbuildmetadataAction(Request $request)
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
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

            $api_key = $this->container->getParameter('beanstalk_api_key');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
//            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------


            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            // $url .= $router->generate('odr_start_build_metadata_entries');
            $url .= $router->generate('odr_start_build_theme_entries');

            // Insert the new job into the queue
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "url" => $url,
//                    "redis_previx" => $redis_prefix,
                    "api_key" => $api_key,
                )
            );

            $delay = 1;
            $pheanstalk->useTube('build_metadata_start')->put($payload, $priority, $delay);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x212672862 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Looks for entities without metadata entries in the database, and schedules them for beanstalk to create
     *
     * @param integer $datatype_id Which datatype should have all its image thumbnails rebuilt
     * @param Request $request
     *
     * @return Response
     */
    public function startbuildmetadataentriesAction(Request $request)
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
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

            $api_key = $this->container->getParameter('beanstalk_api_key');

            throw new \Exception('DISABLED DUE TO TEMPORARY HACK BELOW');

            // --------------------
            // Determine user privileges
            /** @var User $user */
//            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find(2);     // TEMPORARY HACK

//            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------


            // ----------------------------------------
            // Radio Options
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_radio_option_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT ro.id AS id
                FROM ODRAdminBundle:RadioOptions AS ro
                LEFT JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                WHERE rom.id IS NULL'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print $query->getSQL();
//print '<pre>'.print_r($results, true).'</pre>';  exit();

            $return['d'] .= 'Scheduled '.count($results).' Radio Options for metadata creation...'."\n";

            if (count($results) > 0) {
                $object_type = 'radio_option';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

            // ----------------------------------------
            // Files
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_file_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT f.id AS id
                FROM ODRAdminBundle:File AS f
                LEFT JOIN ODRAdminBundle:FileMeta AS fm WITH fm.file = f
                WHERE fm.id IS NULL'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print $query->getSQL();
//print '<pre>'.print_r($results, true).'</pre>';  exit();

            $return['d'] .= 'Scheduled '.count($results).' Files for metadata creation...'."\n";

            if (count($results) > 0) {
                $object_type = 'file';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

            // ----------------------------------------
            // Images
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_image_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT i.id AS id
                FROM ODRAdminBundle:Image AS i
                LEFT JOIN ODRAdminBundle:ImageMeta AS im WITH im.image = i
                WHERE i.original = 1 AND im.id IS NULL'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print $query->getSQL();
//print '<pre>'.print_r($results, true).'</pre>';  exit();

            $return['d'] .= 'Scheduled '.count($results).' Images for metadata creation...'."\n";

            if (count($results) > 0) {
                $object_type = 'image';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

            // ----------------------------------------
            // DataTree
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_datatree_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT dt.id AS id
                FROM ODRAdminBundle:DataTree AS dt
                LEFT JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                WHERE dtm.id IS NULL'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print $query->getSQL();
//print '<pre>'.print_r($results, true).'</pre>';  exit();

            $return['d'] .= 'Scheduled '.count($results).' DataTrees for metadata creation...'."\n";

            if (count($results) > 0) {
                $object_type = 'datatree';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

            // ----------------------------------------
            // Datafields
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_datafield_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT df.id AS id
                FROM ODRAdminBundle:DataFields AS df
                LEFT JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                WHERE dfm.id IS NULL'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print $query->getSQL();
//print '<pre>'.print_r($results, true).'</pre>';  exit();

            $return['d'] .= 'Scheduled '.count($results).' Datafields for metadata creation...'."\n";

            if (count($results) > 0) {
                $object_type = 'datafield';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

            // ----------------------------------------
            // Datatypes
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_datatype_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT dt.id AS id
                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                WHERE dtm.id IS NULL'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print $query->getSQL();
//print '<pre>'.print_r($results, true).'</pre>';  exit();

            $return['d'] .= 'Scheduled '.count($results).' Datatypes for metadata creation...'."\n";

            if (count($results) > 0) {
                $object_type = 'datatype';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

            // ----------------------------------------
            // Datarecords
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_datarecord_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT dr.id AS id
                FROM ODRAdminBundle:DataRecord AS dr
                LEFT JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                WHERE drm.id IS NULL'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print $query->getSQL();
//print '<pre>'.print_r($results, true).'</pre>';  exit();

            $return['d'] .= 'Scheduled '.count($results).' Datarecords for metadata creation...'."\n";

            if (count($results) > 0) {
                $object_type = 'datarecord';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x472127862 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified radio option if needed
     *
     * @param Request $request
     */
    public function buildradiooptionmetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

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
/*
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
*/

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted radio options too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the radio option entity exists...
            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($object_id);
            if ($radio_option == null)
                throw new \Exception('Radio Option does not exist');

            // Attempt to locate a metadata entry for the provided entity
            /** @var RadioOptionsMeta $radio_option_meta */
            $radio_option_meta = $em->getRepository('ODRAdminBundle:RadioOptionsMeta')->findOneBy( array('radioOption' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($radio_option_meta !== null) {
                $return['d'] = 'Metadata entry already exists for Radio Option '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $radio_option_meta = new RadioOptionsMeta();
                $radio_option_meta->setRadioOption( $radio_option );

                $radio_option_meta->setOptionName( $radio_option->getOptionNameOriginal() );
                $radio_option_meta->setXmlOptionName( $radio_option->getXmlOptionNameOriginal() );
                $radio_option_meta->setDisplayOrder( $radio_option->getDisplayOrderOriginal() );
                $radio_option_meta->setIsDefault( $radio_option->getIsDefaultOriginal() );

                $radio_option_meta->setCreatedBy( $radio_option->getUpdatedBy() );
                $radio_option_meta->setCreated( $radio_option->getUpdated() );
                $radio_option_meta->setUpdatedBy( $radio_option->getUpdatedBy() );
                $radio_option_meta->setUpdated( $radio_option->getUpdated() );

                $radio_option_meta->setDeletedAt( $radio_option->getDeletedAt() );

                // If radio option is deleted, transfer who deleted it from the updatedBy column to the deletedBy column
                if ( $radio_option->getDeletedAt() !== null ) {
                    $radio_option->setDeletedBy($radio_option->getUpdatedBy());
                    $em->persist($radio_option);
                }

                $em->persist($radio_option_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for Radio Option '.$object_id;
            }
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


    /**
     * Builds metadata entry for a specified file if needed
     *
     * @param Request $request
     */
    public function buildfilemetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

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
/*
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
*/

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted files too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the file entity exists...
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($object_id);
            if ($file == null)
                throw new \Exception('File does not exist');

            // Attempt to locate a metadata entry for the provided entity
            /** @var FileMeta $file_meta */
            $file_meta = $em->getRepository('ODRAdminBundle:FileMeta')->findOneBy( array('file' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($file_meta !== null) {
                $return['d'] = 'Metadata entry already exists for File '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $file_meta = new FileMeta();
                $file_meta->setFile( $file );

                $file_meta->setDescription( $file->getDescriptionOriginal() );
                $file_meta->setOriginalFileName( $file->getOriginalFileNameOriginal() );
                $file_meta->setExternalId( $file->getExternalIdOriginal() );
                $file_meta->setPublicDate( $file->getPublicDateOriginal() );

                $file_meta->setCreatedBy( $file->getCreatedBy() );
                $file_meta->setCreated( $file->getCreated() );
                $file_meta->setUpdatedBy( $file->getUpdatedBy() );
                $file_meta->setUpdated( $file->getUpdated() );

                $file_meta->setDeletedAt( $file->getDeletedAt() );

                // If file is deleted, transfer who deleted it from the updatedBy column to the deletedBy column
                if ( $file->getDeletedAt() !== null ) {
                    $file->setDeletedBy( $file->getUpdatedBy() );
                    $em->persist($file);
                }

                $em->persist($file_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for File '.$object_id;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x271386622 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified image if needed
     *
     * @param Request $request
     */
    public function buildimagemetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

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
/*
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
*/

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted images too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the file entity exists...
            /** @var Image $image */
            $image = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            if ($image == null)
                throw new \Exception('Image does not exist');

            // Attempt to locate a metadata entry for the provided entity
            /** @var ImageMeta $image_meta */
            $image_meta = $em->getRepository('ODRAdminBundle:ImageMeta')->findOneBy( array('image' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($image_meta !== null) {
                $return['d'] = 'Metadata entry already exists for Image '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $image_meta = new ImageMeta();
                $image_meta->setImage( $image );

                $image_meta->setDisplayorder( $image->getDisplayorderOriginal() );
                $image_meta->setCaption( $image->getCaptionOriginal() );
                $image_meta->setOriginalFileName( $image->getOriginalFileNameOriginal() );
                $image_meta->setExternalId( $image->getExternalIdOriginal() );
                $image_meta->setPublicDate( $image->getPublicDateOriginal() );

                $image_meta->setCreatedBy( $image->getCreatedBy() );
                $image_meta->setCreated( $image->getCreated() );
                $image_meta->setUpdatedBy( $image->getUpdatedBy() );
                $image_meta->setUpdated( $image->getUpdated() );

                $image_meta->setDeletedAt( $image->getDeletedAt() );

                // If image is deleted, transfer who deleted it from the updatedBy column to the deletedBy column
                if ( $image->getDeletedAt() !== null ) {
                    $image->setDeletedBy( $image->getUpdatedBy() );
                    $em->persist($image);
                }

                $em->persist($image_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for Image '.$object_id;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x273786222 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified datatree if needed
     *
     * @param Request $request
     */
    public function builddatatreemetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

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
/*
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
*/

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted images too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the file entity exists...
            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->find($object_id);
            if ($datatree == null)
                throw new \Exception('DataTree does not exist');

            // Attempt to locate a metadata entry for the provided entity
            /** @var DataTreeMeta $datatree_meta */
            $datatree_meta = $em->getRepository('ODRAdminBundle:DataTreeMeta')->findOneBy( array('dataTree' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($datatree_meta !== null) {
                $return['d'] = 'Metadata entry already exists for DataTree '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $datatree_meta = new DataTreeMeta();
                $datatree_meta->setDataTree( $datatree );

                $datatree_meta->setIsLink( $datatree->getIsLinkOriginal() );
                $datatree_meta->setMultipleAllowed( $datatree->getMultipleAllowedOriginal() );

                $datatree_meta->setCreatedBy( $datatree->getCreatedBy() );
                $datatree_meta->setCreated( $datatree->getCreated() );
                $datatree_meta->setUpdatedBy( $datatree->getUpdatedBy() );
                $datatree_meta->setUpdated( $datatree->getUpdated() );

                $datatree_meta->setDeletedAt( $datatree->getDeletedAt() );

                // If datatree is deleted, transfer who deleted it from the updatedBy column to the deletedBy column
                if ( $datatree->getDeletedAt() !== null ) {
                    $datatree->setDeletedBy( $datatree->getUpdatedBy() );
                    $em->persist($datatree);
                }

                $em->persist($datatree_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for DataTree '.$object_id;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x3728566222 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified datafield if needed
     *
     * @param Request $request
     */
    public function builddatafieldmetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

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
/*
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
*/

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted images too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the file entity exists...
            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($object_id);
            if ($datafield == null)
                throw new \Exception('Datafield does not exist');

            // Attempt to locate a metadata entry for the provided entity
            /** @var DataFieldsMeta $datafield_meta */
            $datafield_meta = $em->getRepository('ODRAdminBundle:DataFieldsMeta')->findOneBy( array('dataField' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($datafield_meta !== null) {
                $return['d'] = 'Metadata entry already exists for Datafield '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $datafield_meta = new DataFieldsMeta();

                $datafield_meta->setDataField( $datafield );
                $datafield_meta->setFieldType( $datafield->getFieldTypeOriginal() );
                $datafield_meta->setRenderPlugin( $datafield->getRenderPluginOriginal() );

                $datafield_meta->setFieldName( $datafield->getFieldNameOriginal() );
                $datafield_meta->setDescription( $datafield->getDescriptionOriginal() );
                $datafield_meta->setXmlFieldName( $datafield->getXmlFieldNameOriginal() );
                $datafield_meta->setPhpValidator( $datafield->getPhpValidatorOriginal() );
                $datafield_meta->setRegexValidator( $datafield->getRegexValidatorOriginal() );

                $datafield_meta->setMarkdownText( $datafield->getMarkdownTextOriginal() );
                $datafield_meta->setIsUnique( $datafield->getIsUniqueOriginal() );
                $datafield_meta->setRequired( $datafield->getRequiredOriginal() );
                $datafield_meta->setSearchable( $datafield->getSearchableOriginal() );
                $datafield_meta->setUserOnlySearch( $datafield->getUserOnlySearchOriginal() );

                $datafield_meta->setDisplayOrder( $datafield->getDisplayOrderOriginal() );
                $datafield_meta->setChildrenPerRow( $datafield->getChildrenPerRowOriginal() );
                $datafield_meta->setRadioOptionNameSort( $datafield->getRadioOptionNameSortOriginal() );
                $datafield_meta->setRadioOptionDisplayUnselected( $datafield->getRadioOptionDisplayUnselectedOriginal() );
                $datafield_meta->setAllowMultipleUploads( $datafield->getAllowMultipleUploadsOriginal() );

                $datafield_meta->setShortenFilename( $datafield->getShortenFilenameOriginal() );

                $datafield_meta->setCreated( $datafield->getCreated() );
                $datafield_meta->setCreatedBy( $datafield->getCreatedBy() );
                $datafield_meta->setUpdated( $datafield->getUpdated() );
                $datafield_meta->setUpdatedBy( $datafield->getUpdatedBy() );

                $datafield_meta->setDeletedAt( $datafield->getDeletedAt() );

                // If datafield is deleted, transfer who deleted it from the updatedBy column to the deletedBy column
                if ( $datafield->getDeletedAt() !== null ) {
                    $datafield->setDeletedBy( $datafield->getUpdatedBy() );
                    $em->persist($datafield);
                }

                $em->persist($datafield_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for Datafield '.$object_id;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2738672902 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified datatype if needed
     *
     * @param Request $request
     */
    public function builddatatypemetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

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
/*
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
*/

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted images too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the file entity exists...
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($object_id);
            if ($datatype == null)
                throw new \Exception('Datatype does not exist');

            // Attempt to locate a metadata entry for the provided entity
            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy( array('dataType' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($datatype_meta !== null) {
                $return['d'] = 'Metadata entry already exists for Datatype '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $datatype_meta = new DataTypeMeta();

                $datatype_meta->setDataType( $datatype );

                $datatype_meta->setDataType($datatype);
                $datatype_meta->setRenderPlugin( $datatype->getRenderPluginOriginal() );

                $datatype_meta->setSearchSlug( $datatype->getSearchSlugOriginal() );
                $datatype_meta->setShortName( $datatype->getShortNameOriginal() );
                $datatype_meta->setLongName( $datatype->getLongNameOriginal() );
                $datatype_meta->setDescription( $datatype->getDescriptionOriginal() );
                $datatype_meta->setXmlShortName( $datatype->getXmlShortNameOriginal() );

                $datatype_meta->setDisplayType( $datatype->getDisplayTypeOriginal() );
                $datatype_meta->setUseShortResults( $datatype->getUseShortResultsOriginal() );
                $datatype_meta->setPublicDate( $datatype->getPublicDateOriginal() );

                $datatype_meta->setExternalIdField( $datatype->getExternalIdFieldOriginal() );
                $datatype_meta->setNameField( $datatype->getNameFieldOriginal() );
                $datatype_meta->setSortField( $datatype->getSortFieldOriginal() );
                $datatype_meta->setBackgroundImageField( $datatype->getBackgroundImageFieldOriginal() );

                $datatype_meta->setCreated( $datatype->getCreated() );
                $datatype_meta->setCreatedBy( $datatype->getCreatedBy() );
                $datatype_meta->setUpdated( $datatype->getUpdated() );
                $datatype_meta->setUpdatedBy( $datatype->getUpdatedBy() );

                $datatype_meta->setDeletedAt( $datatype->getDeletedAt() );

                // If datafield is deleted, transfer who deleted it from the updatedBy column to the deletedBy column
                if ( $datatype->getDeletedAt() !== null ) {
                    $datatype->setDeletedBy( $datatype->getUpdatedBy() );
                    $em->persist($datatype);
                }

                $em->persist($datatype_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for Datatype '.$object_id;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x738863422 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified datarecord if needed
     *
     * @param Request $request
     */
    public function builddatarecordmetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

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
/*
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
*/

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted datarecords too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the datarecord entity exists...
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($object_id);
            if ($datarecord == null)
                throw new \Exception('Datarecord does not exist');

            // Attempt to locate a metadata entry for the provided entity
            /** @var DataRecordMeta $datatree_meta */
            $datarecord_meta = $em->getRepository('ODRAdminBundle:DataRecordMeta')->findOneBy( array('dataRecord' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($datarecord_meta !== null) {
                $return['d'] = 'Metadata entry already exists for Datarecord '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $datarecord_meta = new DataRecordMeta();
                $datarecord_meta->setDataRecord($datarecord);

                $datarecord_meta->setPublicDate( $datarecord->getPublicDateOriginal() );

                $datarecord_meta->setCreatedBy( $datarecord->getCreatedBy() );
                $datarecord_meta->setCreated( $datarecord->getCreated() );
                $datarecord_meta->setUpdatedBy( $datarecord->getUpdatedBy() );
                $datarecord_meta->setUpdated( $datarecord->getUpdated() );

                $datarecord_meta->setDeletedAt($datarecord->getDeletedAt());

                // If datatree is deleted, transfer who deleted it from the updatedBy column to the deletedBy column
                if ($datarecord->getDeletedAt() !== null) {
                    $datarecord->setDeletedBy($datarecord->getUpdatedBy());
                    $em->persist($datarecord);
                }

                $em->persist($datarecord_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for Datarecord '.$object_id;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x239864522 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Looks for themes without metadata entries in the database, and schedules them for beanstalk to rebuild
     *
     * @param integer $datatype_id Which datatype should have all its image thumbnails rebuilt
     * @param Request $request
     *
     * @return Response
     */
    public function startbuildthemeentriesAction(Request $request)
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
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

            $api_key = $this->container->getParameter('beanstalk_api_key');

            throw new \Exception('DISABLED DUE TO TEMPORARY HACK BELOW');

            // --------------------
            // Determine user privileges
            /** @var User $user */
//            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find(2);     // TEMPORARY HACK
//            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------


            // ----------------------------------------
            // Themes
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_theme_metadata');


            // Determine all top-level datatypes, including deleted ones
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT dt.id AS datatype_id
                FROM ODRAdminBundle:DataType AS dt'
            );
            $results = $query->getArrayResult();

            $all_datatypes = array();
            foreach ($results as $num => $result)
                $all_datatypes[] = $result['datatype_id'];

            $query = $em->createQuery(
               'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                FROM ODRAdminBundle:DataTree AS dt
                JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
                JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
                WHERE dtm.is_link = 0'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

            $parent_of = array();
            foreach ($results as $num => $result)
                $parent_of[ $result['descendant_id'] ] = $result['ancestor_id'];

            $top_level_datatypes = array();
            foreach ($all_datatypes as $datatype_id) {
                if ( !isset($parent_of[$datatype_id]) )
                    $top_level_datatypes[] = $datatype_id;
            }
            sort($top_level_datatypes);
//print_r($top_level_datatypes);
//exit();

            foreach ($top_level_datatypes as $num => $datatype_id) {
                // Figure out if this datatype has any official themes yet
                $query = $em->createQuery(
                   'SELECT t.id AS id
                    FROM ODRAdminBundle:Theme AS t
                    WHERE t.dataType = :datatype_id
                    AND t.deletedAt IS NULL'
                )->setParameters( array('datatype_id' => $datatype_id) );
                $results = $query->getArrayResult();

//                if ( count($results) == 0 ) {
                    // ...if not, then schedule this datatype to get the basic themes it needs
                    $object_type = 'themes of datatype';

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                        "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $datatype_id,
                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );
                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
//                }
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2127128652 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds the basic theme entries and their metadata for a given top-level datatype
     *
     * @param Request $request
     *
     * @return Response
     */
    public function buildthememetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if (!isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']))
                throw new \Exception('Invalid Form');

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

            // ----------------------------------------
            // Ensure the datatype entity exists...
            /** @var DataType $datatype */
            $em->getFilters()->disable('softdeleteable');
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($object_id);
            if ($datatype == null)
                throw new \Exception('Datatype does not exist');


            // ----------------------------------------
            // Determine all top-level datatypes, including deleted ones
            $query = $em->createQuery(
                'SELECT dt.id AS datatype_id
                FROM ODRAdminBundle:DataType AS dt'
            );
            $results = $query->getArrayResult();

            $all_datatypes = array();
            foreach ($results as $num => $result)
                $all_datatypes[] = $result['datatype_id'];

            $query = $em->createQuery(
                'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                FROM ODRAdminBundle:DataTree AS dt
                JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
                JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
                WHERE dtm.is_link = 0'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

            $parent_of = array();
            foreach ($results as $num => $result)
                $parent_of[ $result['descendant_id'] ] = $result['ancestor_id'];

            $top_level_datatypes = array();
            foreach ($all_datatypes as $datatype_id) {
                if ( !isset($parent_of[$datatype_id]) )
                    $top_level_datatypes[] = $datatype_id;
            }
            sort($top_level_datatypes);


            // ----------------------------------------
            $indent = 0;
            $write = true;
//            $write = false;

            $em->getFilters()->disable('softdeleteable');
            $ret = self::copyThemeData($em, $datatype, $top_level_datatypes, $indent, $write);
            $em->getFilters()->enable('softdeleteable');

            $return['d'] = $ret."\n";
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2756328622 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * @param integer $num
     *
     * @return string
     */
    private function indent($num)
    {
        $str = '';
        for ($i = 0; $i < $num; $i++)
            $str .= '    ';
        return $str;
    }


    /**
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataType $datatype
     * @param array $top_level_datatypes
     * @param integer $indent
     * @param boolean $write
     *
     * @return string
     */
    private function copyThemeData($em, $datatype, $top_level_datatypes, $indent, $write)
    {
        $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
        $repo_theme_element_field = $em->getRepository('ODRAdminBundle:ThemeElementField');
        $repo_theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField');
        $repo_theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType');

        $str = 'Datatype';
        if ($datatype->getDeletedAt() !== null)
            $str = 'deleted Datatype';

        $ret = self::indent($indent).'Creating new themes for '.$str.' '.$datatype->getId().'...'."\n";

        // Create a master theme for this datatype
        $master_theme = new Theme();
        $master_theme->setDataType($datatype);
        $master_theme->setThemeType('master');
        $master_theme->setCreatedBy( $datatype->getCreatedBy() );
        $master_theme->setCreated( $datatype->getCreated() );
        $master_theme->setUpdatedBy( $datatype->getCreatedBy() );
        $master_theme->setUpdated( $datatype->getCreated() );

        if ($datatype->getDeletedAt() !== null) {
            $master_theme->setDeletedAt( $datatype->getDeletedAt() );
            $master_theme->setDeletedBy( $datatype->getDeletedBy() );
        }

        if ($write)
            $em->persist($master_theme);

        $ret .= self::indent($indent+1).'-- master '."\n";

        // If display_in_results is false for at least one of the theme elements for this datatype, then create a view theme for this datatype as well
        $view_theme = null;
/*
        $query = $em->createQuery(
           'SELECT te.id, te.displayInResults
            FROM ODRAdminBundle:ThemeElement AS te
            WHERE te.dataType = :datatype_id AND te.theme = :theme_id
            AND te.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype->getId(), 'theme_id' => 1) );
        $results = $query->getArrayResult();

        $needs_view_theme = false;
        foreach ($results as $num => $result) {
            $display_in_results = $result['displayInResults'];
            if ($display_in_results == 0) {
                $needs_view_theme = true;
                break;
            }
        }

        if ($needs_view_theme) {
            $view_theme = new Theme();
            $view_theme->setDataType($datatype);
            $view_theme->setThemeType('view');
            $view_theme->setCreatedBy( $datatype->getCreatedBy() );
            $view_theme->setCreated( $datatype->getCreated() );
            if ($write)
                $em->persist($view_theme);

            $ret .= self::indent($indent+1).'-- view '."\n";
        }
*/

        // If the datatype has some shortresults stuff, then create a search results theme
        $search_results_theme = null;
        if ( in_array($datatype->getId(), $top_level_datatypes) ) {
            $query = $em->createQuery(
               'SELECT te.id
                FROM ODRAdminBundle:ThemeElement AS te
                WHERE te.dataType = :datatype_id AND te.theme = :theme_id
                AND te.deletedAt IS NULL'
            )->setParameters(array('datatype_id' => $datatype->getId(), 'theme_id' => 2));
            $results = $query->getArrayResult();

            $needs_search_results_theme = false;
            foreach ($results as $num => $result)
                $needs_search_results_theme = true;

            if ($needs_search_results_theme) {
                $search_results_theme = new Theme();
                $search_results_theme->setDataType($datatype);
                $search_results_theme->setThemeType('search_results');
                $search_results_theme->setCreatedBy( $datatype->getCreatedBy() );
                $search_results_theme->setCreated( $datatype->getCreated() );
                $search_results_theme->setUpdatedBy( $datatype->getCreatedBy() );
                $search_results_theme->setUpdated( $datatype->getCreated() );

                if ($datatype->getDeletedAt() !== null) {
                    $search_results_theme->setDeletedAt( $datatype->getDeletedAt() );
                    $search_results_theme->setDeletedBy( $datatype->getDeletedBy() );
                }

                if ($write)
                    $em->persist($search_results_theme);

                $ret .= self::indent($indent+1).'-- search_results '."\n";
            }
        }


        // If the datatype has a textresults layout, then create a table theme
        $table_theme = null;
        if ( in_array($datatype->getId(), $top_level_datatypes) ) {
            $query = $em->createQuery(
               'SELECT df.id, dfm.displayOrder
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                WHERE df.dataType = :datatype_id
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
            )->setParameters(array('datatype_id' => $datatype->getId()));
            $results = $query->getArrayResult();

            $needs_table_theme = false;
            foreach ($results as $num => $result) {
                $display_order = $result['displayOrder'];

                if ($display_order !== -1) {
                    $needs_table_theme = true;
                    break;
                }
            }

            if ($needs_table_theme) {
                $table_theme = new Theme();
                $table_theme->setDataType($datatype);
                $table_theme->setThemeType('table');
                $table_theme->setCreatedBy( $datatype->getCreatedBy() );
                $table_theme->setCreated( $datatype->getCreated() );
                $table_theme->setUpdatedBy( $datatype->getCreatedBy() );
                $table_theme->setUpdated( $datatype->getCreated() );

                if ($datatype->getDeletedAt() !== null) {
                    $table_theme->setDeletedAt( $datatype->getDeletedAt() );
                    $table_theme->setDeletedBy( $datatype->getDeletedBy() );
                }

                if ($write)
                    $em->persist($table_theme);

                $ret .= self::indent($indent+1).'-- table '."\n";
            }
        }

        // Save and refresh all these themes
        if ($write) {
            $em->flush();
            $em->refresh($master_theme);
            if ($view_theme !== null)
                $em->refresh($view_theme);
            if ($search_results_theme !== null)
                $em->refresh($search_results_theme);
            if ($table_theme !== null)
                $em->refresh($table_theme);
        }


        // ----------------------------------------
        // Create a new ThemeMeta entry for each of the created themes
        $master_theme_meta = new ThemeMeta();
        $master_theme_meta->setTheme($master_theme);
        $master_theme_meta->setTemplateName('');
        $master_theme_meta->setTemplateDescription('');
        $master_theme_meta->setIsDefault(true);
        $master_theme_meta->setCreatedBy( $datatype->getCreatedBy() );
        $master_theme_meta->setCreated( $datatype->getCreated() );
        $master_theme_meta->setUpdatedBy( $datatype->getCreatedBy() );
        $master_theme_meta->setUpdated( $datatype->getCreated() );

        if ($master_theme->getDeletedAt() !== null)
            $master_theme_meta->setDeletedAt( $master_theme->getDeletedAt() );

        if ($write)
            $em->persist($master_theme_meta);
/*
        // If a view theme was created, make a new ThemeMeta entry for that
        if ($view_theme !== null) {
            $view_theme_meta = new ThemeMeta();
            $view_theme_meta->setTheme($view_theme);
            $view_theme_meta->setTemplateName('');
            $view_theme_meta->setTemplateDescription('');
            $view_theme_meta->setIsDefault(true);
            $view_theme_meta->setCreatedBy( $datatype->getCreatedBy() );
            $view_theme_meta->setCreated( $datatype->getCreated() );
            $view_theme_meta->setUpdatedBY( $datatype->getCreatedBy() );
            $view_theme_meta->setUpdated( $datatype->getCreated() );

            if ($view_theme->getDeletedAt() !== null)
                $view_theme_meta->setDeletedAt( $view_theme->getDeletedAt() );

            if ($write)
                $em->persist($view_theme_meta);
        }
*/

        // If a search results theme was created, make a new ThemeMeta entry for that
        if ($search_results_theme !== null) {
            $search_results_theme_meta = new ThemeMeta();
            $search_results_theme_meta->setTheme($search_results_theme);
            $search_results_theme_meta->setTemplateName('');
            $search_results_theme_meta->setTemplateDescription('');
            $search_results_theme_meta->setIsDefault(true);
            $search_results_theme_meta->setCreatedBy( $datatype->getCreatedBy() );
            $search_results_theme_meta->setCreated( $datatype->getCreated() );
            $search_results_theme_meta->setUpdatedBy( $datatype->getCreatedBy() );
            $search_results_theme_meta->setUpdated( $datatype->getCreated() );

            if ($search_results_theme->getDeletedAt() !== null)
                $search_results_theme_meta->setDeletedAt( $search_results_theme->getDeletedAt() );

            if ($write)
                $em->persist($search_results_theme_meta);
        }

        // If a table theme was created, create a new ThemeMeta entry for that
        if ($table_theme !== null) {
            $table_theme_meta = new ThemeMeta();
            $table_theme_meta->setTheme($table_theme);
            $table_theme_meta->setTemplateName('');
            $table_theme_meta->setTemplateDescription('');
            $table_theme_meta->setIsDefault(true);
            $table_theme_meta->setCreatedBy( $datatype->getCreatedBy() );
            $table_theme_meta->setCreated( $datatype->getCreated() );
            $table_theme_meta->setUpdatedBy( $datatype->getCreatedBy() );
            $table_theme_meta->setUpdated( $datatype->getCreated() );

            if ($table_theme->getDeletedAt() !== null)
                $table_theme_meta->setDeletedAt( $table_theme->getDeletedAt() );

            if ($write)
                $em->persist($table_theme_meta);
        }

        if ($write)
            $em->flush();

        $ret .= "\n";

        // ----------------------------------------
        // Locate all theme elements that comprise this datatype's "master" theme
        $query = $em->createQuery(
           'SELECT te
            FROM ODRAdminBundle:ThemeElement AS te
            WHERE te.dataType = :datatype_id AND te.theme = :theme_id'
        )->setParameters( array('datatype_id' => $datatype->getId(), 'theme_id' => 1) );

        $results = $query->getResult();
        foreach ($results as $theme_element) {
            /** @var ThemeElement $theme_element */
            // Change this theme element's theme from the old default of "1" to whatever the new datatype's "master" theme is
            $theme_element->setTheme($master_theme);
            if ($write)
                $em->persist($theme_element);

            // Create a new theme element meta entry for this theme element
            $theme_element_meta = self::createThemeElementMetaEntry($theme_element);

            $str = 'moved theme_element';
            if ($theme_element->getDeletedAt() !== null) {
                $str = 'moved deleted theme_element';
                $theme_element->setDeletedBy( $theme_element->getUpdatedBy() );

                if ($write)
                    $em->persist($theme_element);
            }

            if ($write) {
                $em->persist($theme_element_meta);
                $em->flush();
            }

            if ($write)
                $ret .= self::indent($indent+1).'-- '.$str.' '.$theme_element->getId().' to datatype '.$datatype->getId().' master theme '.$master_theme->getId()."\n";
            else
                $ret .= self::indent($indent+1).'-- '.$str.' '.$theme_element->getId().' to datatype '.$datatype->getId().' master theme '."\n";


            // Easier to transfer theme_datafield and theme_datatype entities to the theme_element here...
            /** @var ThemeElementField[] $theme_element_fields */
            $theme_element_fields = $repo_theme_element_field->findBy( array('themeElement' => $theme_element->getId()) );
            foreach ($theme_element_fields as $tef) {
                if ($tef->getDataType() !== null) {
                    // ...is for a datatype, load the relevant theme_datatype entry
                    /** @var ThemeDataType $theme_datatype */
                    $theme_datatype = $repo_theme_datatype->findOneBy( array('dataType' => $tef->getDataType()->getId(), 'theme' => 1) );

                    if ($theme_datatype !== null) {
                        $theme_datatype->setThemeElement($theme_element);
                        $theme_datatype->setDisplayType( $tef->getDataType()->getDisplayType() );

                        if ($write)
                            $em->persist($theme_datatype);

                        $ret .= self::indent($indent+2).'-- attached theme_datatype '.$theme_datatype->getId().' (datatype '.$tef->getDataType()->getId().')'."\n";

                        // Also create the theme data for the childtype at this time
                        /** @var DataTree $datatree */
                        $datatree = $repo_datatree->findOneBy( array('descendant' => $tef->getDataType()->getId()) );
                        if ($datatree->getIsLink() == false) {
                            $ret .= self::indent($indent+2).'{'."\n";
                            $ret .= self::copyThemeData($em, $tef->getDataType(), $top_level_datatypes, $indent+3, $write);
                            $ret .= self::indent($indent+2).'}'."\n";
                        }
                    }
                }
                else {
                    // ...is for a datafield, load the relevant theme_datafield entry
                    /** @var ThemeDataField $theme_datafield */
                    $theme_datafield = $repo_theme_datafield->findOneBy( array('dataField' => $tef->getDataFields()->getId(), 'theme' => 1) );

                    if ($theme_datafield !== null) {
                        $theme_datafield->setThemeElement($theme_element);
                        $theme_datafield->setDisplayOrder( $tef->getDisplayOrder() );

                        $str = 'attached theme_datafield';
                        if ($theme_datafield->getActive() == false) {
                            $theme_datafield->setDeletedAt( new \DateTime() );
                            $str = 'attached deleted theme_datafield';
                        }

                        if ($write)
                            $em->persist($theme_datafield);

                        $ret .= self::indent($indent+2).'-- '.$str.' '.$theme_datafield->getId().' (datafield '.$tef->getDataFields()->getId().') at display_order '.$tef->getDisplayOrder()."\n";
                    }
                }
            }

            if ($write)
                $em->flush();
        }

        $ret .= "\n";

        // Locate all theme elements that comprise this datatype's "search results" theme, if it exists
        if ( $search_results_theme !== null ) {
            $query = $em->createQuery(
               'SELECT te
                FROM ODRAdminBundle:ThemeElement AS te
                WHERE te.dataType = :datatype_id AND te.theme = :theme_id'
            )->setParameters(array('datatype_id' => $datatype->getId(), 'theme_id' => 2));

            $results = $query->getResult();
            foreach ($results as $theme_element) {
                /** @var ThemeElement $theme_element */
                // Change this theme element's theme from the old default of "1" to whatever the new datatype's "master" theme is
                $theme_element->setTheme($search_results_theme);
                if ($write)
                    $em->persist($theme_element);

                // Create a new theme element meta entry for this theme element
                $theme_element_meta = self::createThemeElementMetaEntry($theme_element);

                $str = 'moved theme_element';
                if ($theme_element->getDeletedAt() !== null) {
                    $str = 'moved deleted theme_element';
                    $theme_element->setDeletedBy( $theme_element->getUpdatedBy() );
                    if ($write)
                        $em->persist($theme_element);
                }

                if ($write) {
                    $em->persist($theme_element_meta);
                    $em->flush();
                }

                if ($write)
                    $ret .= self::indent($indent+1).'-- '.$str.' '.$theme_element->getId().' to datatype '.$datatype->getId().' search_results theme '.$search_results_theme->getId()."\n";
                else
                    $ret .= self::indent($indent+1).'-- '.$str.' '.$theme_element->getId().' to datatype '.$datatype->getId().' search_results theme '."\n";


                // Easier to transfer theme_datafield and theme_datatype entities to the theme_element here...
                /** @var ThemeElementField[] $theme_element_fields */
                $theme_element_fields = $repo_theme_element_field->findBy( array('themeElement' => $theme_element->getId()) );
                foreach ($theme_element_fields as $tef) {
                    if ($tef->getDataType() !== null) {
                        // ...is for a datatype, load the relevant theme_datatype entry
                        /** @var ThemeDataType $theme_datatype */
                        $theme_datatype = $repo_theme_datatype->findOneBy( array('dataType' => $tef->getDataType()->getId(), 'theme' => 2) );

                        if ($theme_datatype !== null) {
                            $theme_datatype->setThemeElement($theme_element);
                            $theme_datatype->setDisplayType( $tef->getDataType()->getDisplayType() );

                            if ($write)
                                $em->persist($theme_datatype);

                            $ret .= self::indent($indent+2).'-- attached theme_datatype '.$theme_datatype->getId().' (datatype '.$tef->getDataType()->getId().')'."\n";

                            // Don't need to pursue child datatypes here
                        }
                    }
                    else {
                        // ...is for a datafield, load the relevant theme_datafield entry
                        /** @var ThemeDataField $theme_datafield */
                        $theme_datafield = $repo_theme_datafield->findOneBy( array('dataField' => $tef->getDataFields()->getId(), 'theme' => 2) );

                        if ($theme_datafield !== null) {
                            $theme_datafield->setThemeElement($theme_element);
                            $theme_datafield->setDisplayOrder( $tef->getDisplayOrder() );

                            $str = 'attached theme_datafield';
                            if ($theme_datafield->getActive() == false) {
                                $theme_datafield->setDeletedAt( new \DateTime() );
                                $str = 'attached deleted theme_datafield';
                            }

                            if ($write)
                                $em->persist($theme_datafield);

                            $ret .= self::indent($indent+2).'-- '.$str.' '.$theme_datafield->getId().' (datafield '.$tef->getDataFields()->getId().') at display_order '.$tef->getDisplayOrder()."\n";
                        }
                    }
                }

                if ($write)
                    $em->flush();
            }

            $ret .= "\n";
        }


        // Locate all theme elements that comprise this datatype's "table" theme
        if ( $table_theme !== null ) {
            // Since it doesn't exist, create a single theme element for the table theme
            $theme_element = new ThemeElement();
            $theme_element->setDataType($datatype);
            $theme_element->setTheme($table_theme);

            $theme_element->setDisplayOrder( 999 );
            $theme_element->setCssWidthMed( '1-1' );
            $theme_element->setCssWidthXL( '1-1' );
            $theme_element->setDisplayInResults( true );

            $theme_element->setCreatedBy( $datatype->getCreatedBy() );
            $theme_element->setCreated( $datatype->getCreated() );

            if ($write) {
                $em->persist($theme_element);
                $em->flush();
                $em->refresh($theme_element);
            }

            $theme_element_meta = new ThemeElementMeta();
            $theme_element_meta->setThemeElement($theme_element);

            $theme_element_meta->setDisplayOrder( 999 );
            $theme_element_meta->setCssWidthMed( '1-1' );
            $theme_element_meta->setCssWidthXL( '1-1' );

            $theme_element_meta->setPublicDate( new \DateTime('2200-01-01 00:00:00') );

            $theme_element_meta->setCreatedBy( $theme_element->getCreatedBy() );
            $theme_element_meta->setCreated( $theme_element->getCreated() );
            $theme_element_meta->setUpdatedBy( $theme_element->getCreatedBy() );
            $theme_element_meta->setUpdated( $theme_element->getCreated() );

            if ($write)
                $em->persist($theme_element_meta);


            if ($write)
                $ret .= self::indent($indent+1).'-- created theme_element '.$theme_element->getId().' for datatype '.$datatype->getId().' table theme '.$table_theme->getId()."\n";
            else
                $ret .= self::indent($indent+1).'-- created theme_element for datatype '.$datatype->getId().' table theme '."\n";

            // Locate the datafields being used in textresults
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                WHERE df.dataType = :datatype_id AND dfm.displayOrder > -1'
            )->setParameters( array('datatype_id' => $datatype->getId()) );
            $results = $query->getResult();

            // Create new theme_datafield entries for each of them, and attach to the new theme element
            foreach ($results as $df) {
                /** @var DataFields $df */
                $tdf = new ThemeDataField();
                $tdf->setTheme($table_theme);
                $tdf->setThemeElement($theme_element);
                $tdf->setDataField($df);

                $tdf->setActive(true);
                $tdf->setDisplayOrder( $df->getDisplayOrder() );
                $tdf->setCssWidthMed('1-1');
                $tdf->setCssWidthXL('1-1');

                $tdf->setCreatedBy( $datatype->getCreatedBy() );
                $tdf->setCreated( $datatype->getCreated() );

                $tdf->setDeletedAt( $df->getDeletedAt() );

                if ($write)
                    $em->persist($tdf);

                if ($write)
                    $ret .= self::indent($indent+2).'-- created theme_datafield '.$tdf->getId().' for datafield '.$df->getId().' at display_order '.$df->getDisplayOrder()."\n";
                else
                    $ret .= self::indent($indent+2).'-- created theme_datafield for datafield '.$df->getId().' at display_order '.$df->getDisplayOrder()."\n";
            }

            if ($write)
                $em->flush();
        }

        // ThemeDatafields linking datafields of child datatypes to a search theme still exist...delete them
        $query = $em->createQuery(
           'SELECT tdf.id
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.dataField = df
            WHERE df.dataType = :datatype_id AND tdf.themeElement IS NULL AND tdf.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        $ret .= "\n";
        $ret .= self::indent($indent+1).'-- deleting unused theme_datafield entries...'."\n";
        foreach ($results as $result) {
            $tdf = $repo_theme_datafield->find($result['id']);
            $tdf->setDeletedAt( new \DateTime() );

            if ($write)
                $em->persist($tdf);

            $ret .= self::indent($indent+2).'-- '.$result['id']."\n";
        }


        // ...also, because the ThemeDatatype table's purpose was completely changed, there are left-over entries that are no longer useful...delete those too
        $query = $em->createQuery(
            'SELECT tdt.id
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:ThemeDataType AS tdt WITH tdt.dataType = dt
            WHERE dt.id = :datatype_id AND tdt.themeElement IS NULL AND tdt.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        $ret .= "\n";
        $ret .= self::indent($indent+1).'-- deleting unused theme_datatype entries...'."\n";
        foreach ($results as $result) {
            $tdt = $repo_theme_datatype->find($result['id']);
            $tdt->setDeletedAt( new \DateTime() );

            if ($write)
                $em->persist($tdt);

            $ret .= self::indent($indent+2).'-- '.$result['id']."\n";
        }

        if ($write)
            $em->flush();

        return $ret;
    }


    /**
     * Helper function to reduce clutter involved in transferring theme/theme_element data over to the new system
     *
     * @param ThemeElement $theme_element
     *
     * @return ThemeElementMeta
     */
    private function createThemeElementMetaEntry($theme_element)
    {
        $theme_element_meta = new ThemeElementMeta();
        $theme_element_meta->setThemeElement($theme_element);

        $theme_element_meta->setDisplayOrder( $theme_element->getDisplayOrderOriginal() );
        $theme_element_meta->setCssWidthMed( $theme_element->getCssWidthMedOriginal() );
        $theme_element_meta->setCssWidthXL( $theme_element->getCssWidthXLOriginal() );

        // ...displayInResults was effectively used for multiple purposes before, so it can't really be used to determine the new "publicDate" property of this theme element...
        $theme_element_meta->setPublicDate( new \DateTime('2200-01-01 00:00:00') );

        $theme_element_meta->setCreatedBy( $theme_element->getCreatedBy() );
        $theme_element_meta->setCreated( $theme_element->getCreated() );
        $theme_element_meta->setUpdatedBy( $theme_element->getCreatedBy() );
        $theme_element_meta->setUpdated( $theme_element->getCreated() );

        $theme_element_meta->setDeletedAt( $theme_element->getDeletedAt() );

        return $theme_element_meta;
    }


    /**
     * Debug function to more easily reset datafield order in table themes
     *
     * @param Request $request
     */
    public function redoTableThemesAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ( !($user->hasRole('ROLE_SUPER_ADMIN')) )
            throw new \Exception('NOT ALLOWED');

        // Load all theme, theme_element, and theme_datafield information for all non-deleted table themes
        $query = $em->createQuery(
           'SELECT t.id AS t_id, te.id AS te_id, tdf.displayOrder AS display_order, df.id AS df_id
            FROM ODRAdminBundle:Theme AS t
            JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
            JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
            JOIN ODRAdminBundle:DataFields AS df WITH tdf.dataField = df
            WHERE t.themeType = :theme_type
            AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL AND df.deletedAt IS NULL
            ORDER BY t.id, te.displayOrder, tdf.displayOrder'
        )->setParameters( array('theme_type' => 'table') );
        $results = $query->getArrayResult();

        $data = array();
        foreach ($results as $result) {
            $theme_id = $result['t_id'];
            $theme_element_id = $result['te_id'];
            $display_order = $result['display_order'];
            $datafield_id = $result['df_id'];

            if ( !isset($data[$theme_id]) )
                $data[$theme_id] = array();
            if ( !isset($data[$theme_id][$theme_element_id]) )
                $data[$theme_id][$theme_element_id] = array();
            if ( !isset($data[$theme_id][$theme_element_id][$display_order]) )
                $data[$theme_id][$theme_element_id][$display_order] = $datafield_id;
        }

//        print '<pre>'.print_r($data, true).'</pre>';

        // Get rid of all themes that already have their theme_datafield entries with a starting index of 0
        foreach ($data as $t_id => $tmp_1) {
            foreach ($tmp_1 as $te_id => $tmp_2) {
                if ( isset($tmp_2[0]) ) {
                    unset( $data[$t_id] );
                    break;
                }
            }

        }
//        print '<pre>'.print_r($data, true).'</pre>';


        $router = $this->get('router');

        // Create some basic html forms to force a call to ThemeController::datafieldorderAction()
        foreach ($data as $t_id => $tmp_1) {
            $printed_start = false;

            foreach ($tmp_1 as $te_id => $tmp_2) {
                $url = $this->container->getParameter('site_baseurl').$router->generate('odr_design_save_datafield_order', array('initial_theme_element_id' => $te_id, 'ending_theme_element_id' => $te_id));

                if (!$printed_start) {
                    print '<form action="'.$url.'" method="POST">';
                    $printed_start = true;
                }

                foreach ($tmp_2 as $display_order => $df_id) {
                    print '<input type="hidden" name="'.(intval($display_order)-1).'" value="'.$df_id.'" />';
                }
            }
            print '<input type="submit" value="update order for theme '.$t_id.'" />';
            print '</form>';
        }

        // Don't bother to return anything...symfony will complain, but it's a debug action anyways
    }
}
