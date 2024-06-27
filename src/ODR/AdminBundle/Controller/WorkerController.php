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

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
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
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRUploadService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


class WorkerController extends ODRCustomController
{

    /**
     * Called by the migration background process to transfer data from one storage entity to
     * another compatible storage entity.
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

        $conn = null;

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

            /** @var Logger $logger */
            $logger = $this->get('logger');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');


            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');


            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException('Invalid Form');

            $ret = '';

            // Grab necessary objects
            /** @var ODRUser $user */
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find( $user_id );
            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find( $datafield_id );
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');

            $top_level_datatype = $datatype->getGrandparent();
            if ( $top_level_datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Grandparent Datatype');

            $datarecord = null;
            if ( $datarecord_id != 0 ) {
                /** @var DataRecord $datarecord */
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find( $datarecord_id );
                if ( is_null($datarecord) )
                    throw new ODRNotFoundException('Datarecord');
            }

            /** @var FieldType $old_fieldtype */
            $old_fieldtype = $repo_fieldtype->find( $old_fieldtype_id );
            $old_typeclass = $old_fieldtype->getTypeClass();
            /** @var FieldType $new_fieldtype */
            $new_fieldtype = $repo_fieldtype->find( $new_fieldtype_id );
            $new_typeclass = $new_fieldtype->getTypeClass();


            // Radio options need typename to distinguish...
            $old_typename = $old_fieldtype->getTypeName();
            $new_typename = $new_fieldtype->getTypeName();
            if ($old_typename == $new_typename)
                throw new ODRBadRequestException('Not allowed to migrate between the same Fieldtype');

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

                    // ----------------------------------------
                    // NOTE: conversions from multiple radio/select to single radio/select create
                    //  one background job per datarecord

                    // Do not want to mark this datarecord as updated...nothing fundamentally changed
                    // However, still need to delete the relevant cached datarecord entries
                    $cache_service->delete('cached_datarecord_'.$datarecord->getGrandparent()->getId());
                    $cache_service->delete('cached_table_data_'.$datarecord->getGrandparent()->getId());
                    $cache_service->delete('json_record_'.$datarecord->getGrandparent()->getUniqueId());
                }
            }
            else if ( $new_typeclass !== 'Radio' ) {
                // ----------------------------------------
                // Going to perform these migrations with native SQL, since Doctrine slows it
                //  down to unacceptable levels
                $conn = $em->getConnection();
                $conn->beginTransaction();

                // Going to need to map typeclasses to actual tables, since not using Doctrine
                $table_map = array(
                    'IntegerValue' => 'odr_integer_value',
                    'DecimalValue' => 'odr_decimal_value',
                    'ShortVarchar' => 'odr_short_varchar',
                    'MediumVarchar' => 'odr_medium_varchar',
                    'LongVarchar' => 'odr_long_varchar',
                    'LongText' => 'odr_long_text',
                    'DatetimeValue' => 'odr_datetime_value',
                );


                // ----------------------------------------
                // This query should do nothing, but make sure that the destination table doesn't
                //  have any undeleted entries for this datafield
                $delete_dest_query = 'UPDATE '.$table_map[$new_typeclass].' SET deletedAt = NOW() WHERE data_field_id = '.$datafield->getId().' AND deletedAt IS NULL';
                $rows = $conn->executeUpdate($delete_dest_query);

                if ( $rows > 0 )
                    $logger->warning('WorkerController::migrateAction() tracked_job '.$tracked_job_id.': deleted '.$rows.' of data for datafield '.$datafield->getId().' from the "'.$new_typeclass.'" table...should have been 0.');


                // ----------------------------------------
                // Going to use an  "INSERT ... SELECT" construct to transfer all acceptable
                //  data from the source table to the destination table
                $insert_query = 'INSERT INTO '.$table_map[$new_typeclass].'(data_field_id, field_type_id, data_record_id, data_record_fields_id, created, updated, deletedAt, createdBy, updatedBy, value';
                // DecimalValue fieldtypes have both 'value' and 'original_value'
                if ( $new_fieldtype->getTypeClass() === 'DecimalValue' )
                    $insert_query .= ', original_value';
                $insert_query .= ')';

                // Most of the SELECT is the same for all migrations...
                $select_query = ' SELECT e.data_field_id, '.$new_fieldtype->getId().', e.data_record_id, e.data_record_fields_id, NOW(), NOW(), NULL, '.$user->getId().', '.$user->getId().', ';
                $remaining_query = ' FROM '.$table_map[$old_typeclass].' AS e WHERE e.data_field_id = '.$datafield->getId().' AND e.deletedAt IS NULL';

                // ...but the rest of it depends on the type of data being migrated, and what it's
                //  being migrated to
                $old_length = 0;
                $old_is_text = false;
                if ( $old_typeclass === 'ShortVarchar' ) {
                    $old_length = 32;
                    $old_is_text = true;
                }
                else if ( $old_typeclass === 'MediumVarchar' ) {
                    $old_length = 64;
                    $old_is_text = true;
                }
                else if ( $old_typeclass === 'LongVarchar' ) {
                    $old_length = 255;
                    $old_is_text = true;
                }
                else if ( $old_typeclass === 'LongText' ) {
                    $old_length = 9999;
                    $old_is_text = true;
                }

                $new_length = 0;
                $new_is_text = false;
                if ( $new_typeclass === 'ShortVarchar' ) {
                    $new_length = 32;
                    $new_is_text = true;
                }
                else if ( $new_typeclass === 'MediumVarchar' ) {
                    $new_length = 64;
                    $new_is_text = true;
                }
                else if ( $new_typeclass === 'LongVarchar' ) {
                    $new_length = 255;
                    $new_is_text = true;
                }
                else if ( $new_typeclass === 'LongText' ) {
                    $new_length = 9999;
                    $new_is_text = true;
                }


                // Each of the different migration types requires a slightly different query...
                if ( $old_is_text && $new_is_text && $old_length < $new_length ) {
                    // Shorter text values can be inserted into longer text values without any
                    // extra conversions
                    $select_query .= 'e.value';
                    // Only copy non-blank values
                    $remaining_query .= ' AND e.value != ""';
                }
                else if ( $old_is_text && $new_is_text && $old_length > $new_length ) {
                    // Longer text values need to be truncated to go into shorter text values
                    $select_query .= 'SUBSTRING(e.value, 1, '.$new_length.')';
                    // Only copy non-blank values
                    $remaining_query .= ' AND e.value != ""';
                }
                else if ( $old_is_text && $new_typeclass === 'IntegerValue' ) {
                    // Converting text into an integer requires a cast...
                    $select_query .= 'CAST(e.value AS SIGNED)';
                    // Only copy non-blank values
                    $remaining_query .= ' AND e.value != ""';

                    // ...but it also needs both a REGEX and BETWEEN conditions, otherwise an
                    //  error will be thrown when encountering values that aren't valid 4 byte
                    //  integers
                    $remaining_query .= ' AND REGEXP_LIKE(e.value, "'.ValidUtility::INTEGER_REGEX.'")';

                    // The regex MUST come before the BETWEEN, otherwise the BETWEEN will throw
                    //  warnings (which are upgraded to errors) when comparing non-integer values
                    $remaining_query .= ' AND CAST(e.value AS DOUBLE) BETWEEN -2147483648 AND 2147483647';
                    // NOTE - the cast here uses a DOUBLE, since that can handle absurdly large
                    //  numbers...if it was instead cast to a SIGNED here, then it would be much more
                    //  likely to encounter an "out of range" value, and crash the whole migration
                }
                else if ( $old_is_text && $new_typeclass === 'DecimalValue' ) {
                    // Converting text into a decimal requires a cast for the value...
                    $select_query .= 'CAST(SUBSTR(e.value, 1, 255) AS DOUBLE)';
                    // ...but the original_value should just match the original text being converted
                    $select_query .= ', SUBSTR(e.value, 1, 255)';    // TODO - this guarantees a fit inside a varchar(255), but it probably shouldn't even be varchar(32) due to precision
                    // Only copy non-blank values
                    $remaining_query .= ' AND e.value != ""';

                    // It also needs a REGEX, otherwise an error will be thrown when encountering
                    //  values that aren't valid doubles
                    $remaining_query .= ' AND REGEXP_LIKE(e.value, "'.ValidUtility::DECIMAL_REGEX.'")';
                }
                else if ( $old_typeclass === 'IntegerValue' && $new_is_text ) {
                    // The string representation of a 4 byte integer is always able to fit into
                    //  the text fields, since they're at least 32 bytes long
                    $select_query .= 'CAST(e.value AS CHAR('.$new_length.'))';
                    // Only copy non-blank values
                    $remaining_query .= ' AND e.value IS NOT NULL';
                }
                else if ( $old_typeclass === 'DecimalValue' && $new_is_text ) {
                    // Want to convert the 'original_value' property of the Decimal...needs to be
                    //  truncated because original_value could technically be longer than the text
                    //  field  TODO - it probably shouldn't even be varchar(32), due to precision
                    $select_query .= 'SUBSTRING(e.original_value, 1, '.$new_length.')';
                    // Only copy non-blank values
                    $remaining_query .= ' AND e.original_value IS NOT NULL';
                }
                else if ( $old_typeclass === 'IntegerValue' && $new_typeclass === 'DecimalValue' ) {
                    // Integers can get converted into Decimals without issue...need one cast
                    //  for the value, and another for the original_value
                    $select_query .= 'CAST(e.value AS DOUBLE)';
                    $select_query .= ', CAST(e.value AS CHAR(255))';

                    // Don't need a regex to verify that integers are valid for conversion to decimal

                    // Only copy non-blank values
                    $remaining_query .= ' AND e.value IS NOT NULL';
                }
                else if ( $old_typeclass === 'DecimalValue' && $new_typeclass === 'IntegerValue' ) {
                    // Want to convert the 'original_value' property of the Decimal
                    $select_query .= 'CAST(e.original_value AS SIGNED)';

                    // Still need to use a BETWEEN in case the decimal is larger than a 4 byte integer
                    $remaining_query .= ' AND CAST(e.original_value AS DOUBLE) BETWEEN -2147483648 AND 2147483647';
                    // NOTE - the cast here uses a DOUBLE, since that can handle absurdly large
                    //  numbers...if it was instead cast to a SIGNED here, then it would be much more
                    //  likely to encounter an "out of range" value, and crash the whole migration

                    // Don't need a regex to verify that decimals are valid for conversion to integer

                    // Only copy non-blank values
                    $remaining_query .= ' AND e.original_value IS NOT NULL';
                }
                else if ( $old_typeclass === 'DatetimeValue' && $new_is_text ) {
                    // Converting from a date to a text value is pretty easy
                    $select_query .= 'CAST(e.value AS CHAR('.$new_length.'))';
                }

                // Text/number fields can't be converted into dates  TODO - ...for now


                // Stitch all parts of the query together and execute it
                $final_query = $insert_query.$select_query.$remaining_query;
                $logger->debug('WorkerController::migrateAction() tracked_job '.$tracked_job_id.': '.$final_query);

                $rows = $conn->executeUpdate($final_query);
                $logger->info('WorkerController::migrateAction() tracked_job '.$tracked_job_id.': copied '.$rows.' rows of data from "'.$old_typeclass.'" to "'.$new_typeclass.'" for datafield '.$datafield->getId());


                // ----------------------------------------
                // Now that the values have been moved, soft-delete the entries in the source
                //  table
                $delete_src_query = 'UPDATE '.$table_map[$old_typeclass].' SET deletedAt = NOW() WHERE data_field_id = '.$datafield->getId().' AND deletedAt IS NULL';
                $rows = $conn->executeUpdate($delete_src_query);

                $logger->debug('WorkerController::migrateAction() tracked_job '.$tracked_job_id.': deleted '.$rows.' rows of data for datafield '.$datafield->getId().' from the "'.$old_typeclass.'" table');


                // No errors at this point, commit the changes
//                $conn->rollBack();
                $conn->commit();


                // ----------------------------------------
                // NOTE: all non-radio fieldtype migrations only have a single background job, and
                //  so the list of datarecords must be determined via other means

                // Don't want to mark the affected datarecords as updated...nothing has fundamentally
                //  changed.  However, need to delete all the cached datarecords for the datatype
                /** @var SearchService $search_service */
                $search_service = $this->container->get('odr.search_service');

                $dr_list = $search_service->getCachedSearchDatarecordList($datatype->getGrandparent()->getId());
                foreach ($dr_list as $dr_id => $parent_dr_id) {
                    $cache_service->delete('cached_datarecord_'.$dr_id);
                    $cache_service->delete('cached_table_data_'.$dr_id);
                }

                $dr_list = $search_service->getCachedDatarecordUUIDList($datatype->getGrandparent()->getId());
                foreach ($dr_list as $dr_id => $dr_uuid)
                    $cache_service->delete('json_record_'.$dr_uuid);

                $logger->debug('WorkerController::migrateAction() tracked_job '.$tracked_job_id.': deleted cache entries for '.count($dr_list).' datarecords from top-level datatype '.$top_level_datatype->getId());
            }

            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total) {
                    // Job is completed...
                    $tracked_job->setCompleted( new \DateTime() );

                    // Fire off an event notifying that the modification of the datafield is done
                    try {
                        // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                        //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                        /** @var EventDispatcherInterface $event_dispatcher */
                        $dispatcher = $this->get('event_dispatcher');
                        $event = new DatafieldModifiedEvent($datafield, $user);
                        $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }

                $em->persist($tracked_job);
                $em->flush();
$ret .= '  Set current to '.$count."\n";
            }

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            // This is only ever called from command-line...
            $request->setRequestFormat('json');

            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

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
     * Called by background processes to perform an asynchronous encryption or decryption of a File
     *  or Image.  Also asynchronously adds files/images into a zip archive.
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

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['crypto_type']) || !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $crypto_type = $post['crypto_type'];
            $object_type = strtolower( $post['object_type'] );
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            $error_prefix .= $crypto_type.' for '.$object_type.' '.$object_id.'...';

            // This is required if encrypting, optional if decrypting
            $local_filename = '';
            if ( isset($post['local_filename']) )
                $local_filename = $post['local_filename'];

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

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');


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
            else if ($object_type == 'image')
                $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            else
                throw new ODRBadRequestException('Invalid object_type');


            if ($base_obj == null)
                throw new \Exception('could not load object '.$object_id.' of type "'.$object_type.'"');
            /** @var File|Image $base_obj */


            // ----------------------------------------
            if ($crypto_type == 'encrypt') {
                if ( $local_filename === '' )
                    throw new ODRBadRequestException('Need $local_filename to encrypt');

                // Need to encrypt this file/image...
                if ($object_type === 'file')
                    $crypto_service->encryptFile($object_id, $local_filename);
                else
                    $crypto_service->encryptImage($object_id, $local_filename);    // NOTE - images are currently not encrypted through this controller action
            }
            else if ($crypto_type == 'decrypt') {
                // Need to decrypt this file/image...
                if ( $archive_filepath !== '' ) {
                    if ( $local_filename === '' )
                        throw new ODRBadRequestException('Need $local_filename to decrypt for archives');

                    // ...and store it in a zip archive
                    $crypto_service->decryptObjectForArchive($object_type, $object_id, $local_filename, $desired_filename, $archive_filepath);
                }
                else {
                    // ...and store it on the server
                    if ($object_type === 'file')
                        $crypto_service->decryptFile($object_id, $local_filename);
                    else
                        $crypto_service->decryptImage($object_id, $local_filename);
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
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Creates a pile of background jobs with the intent of locating useless storage entities in
     * the backend database, so they can get deleted.
     *
     * @param Request $request
     * @return Response
     */
    public function startcleanupAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // TODO - this works, but chewing through ~23 million useless rows takes a rather long time
            throw new ODRException('Do not continue');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $pheanstalk = $this->get('pheanstalk');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $url = $this->generateUrl('odr_storage_entity_cleanup_worker', array(), UrlGeneratorInterface::ABSOLUTE_URL);

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // --------------------

            // Want to find pointless blank values in these tables...
            $tables = array(
                'odr_short_varchar',

                // The other ones aren't as important...
                'odr_medium_varchar',
                'odr_long_varchar',
                'odr_long_text',
                'odr_integer_value',
                'odr_decimal_value',
            );

            // Need a list of all datafields...including the "deleted" ones
            $query = 'SELECT df.id AS df_id FROM odr_data_fields df';
            $conn = $em->getConnection();
            $results = $conn->executeQuery($query);

            foreach ($results as $result) {
                $df_id = intval($result['df_id']);

//                if ( $df_id > 10 )
//                    break;

                foreach ($tables as $table) {
                    // Create a job for each datafield/table combo
                    $payload = json_encode(
                        array(
                            'datafield_id' => $df_id,
                            'table' => $table,

                            'api_key' => $beanstalk_api_key,
                            'url' => $url,
                            'redis_prefix' => $redis_prefix,    // debug purposes only
                        )
                    );

                    $pheanstalk->useTube('storage_entity_cleanup')->put($payload);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0xfe66de84;
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
     * Called by a background process to determine which storage entities from a specific table for
     * the given datafield can be deleted without losing any historical data.
     *
     * @param Request $request
     * @return Response
     */
    public function storageentitycleanupAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $ret = '';

        $conn = null;

        try {

            throw new ODRException('Do not continue');

            $post = $_POST;
//print_r($post);
            if (!isset($post['datafield_id']) || !isset($post['table']) || !isset($post['api_key']))
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $datafield_id = intval($post['datafield_id']);
            $table = $post['table'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException('Invalid Form');


            /** @var Logger $logger */
            $logger = $this->get('logger');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();

            $query =
               'SELECT e.id, e.data_record_fields_id AS drf_id, e.value, e.created, e.updated
                FROM '.$table.' e
                WHERE e.data_field_id = '.$datafield_id.'
                ORDER BY e.data_record_fields_id, e.id';
            $results = $conn->executeQuery($query);

            $prev_id = $prev_drf = $prev_value = null;
            $blank_ids = array();

            foreach ($results as $result) {
                $id = $result['id'];
                $drf_id = $result['drf_id'];
                $value = $result['value'];
                $created = $result['created'];//->format('Y-m-d H:i:s');
                $updated = $result['updated'];//->format('Y-m-d H:i:s');

                // This drf is different than the previous, so it should be the first storage entity
                //  for this datarecord/datafield pair
                if ( $drf_id !== $prev_drf ) {
                    // If the value is the empty string, and the created date is equal to the
                    //  updated date...
                    if ( ($value === '' || is_null($value) ) && $created === $updated) {
                        // ...then this is most likely an unnecessary entry created by CSVImport,
                        //  and can get deleted without losing either data or history
                        $blank_ids[] = $id;
                    }
                }

                // Need to keep track of the drf id...
                $prev_drf = $drf_id;
            }

            // Be sure the check the last entry in the list
            if ( $prev_value === '' || is_null($prev_value) )
                $blank_ids[] = $prev_id;

            if ( !empty($blank_ids) ) {
                $offset = 0;
                $length = 5000;

                while (true) {
                    $slice = array_slice($blank_ids, $offset, $length);
                    if ( !empty($slice) ) {
                        $delete_query = 'DELETE FROM '.$table.' WHERE id IN ('.implode(',', $slice).');';
                        $offset += $length;

                        $rows = $conn->executeUpdate($delete_query);
                        $logger->debug('WorkerController::storageentitycleanupAction(): deleted '.$rows.' rows from "'.$table.'" for datafield '.$datafield_id);
                    }
                    else {
                        break;
                    }
                }
            }

        }
        catch (\Exception $e) {
            // This is only ever called from command-line...
            $request->setRequestFormat('json');

            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x5e17488b;
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
     * TODO
     *
     * @param Request $request
     * @return Response
     */
    public function amcsdparseAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            $dir = $this->container->getParameter('odr_tmp_directory');
            $dir .= '/user_'.$user->getId().'/amcsd/';
            if ( !file_exists($dir) )
                mkdir($dir);

            $amc_codes = self::splitFile($dir, 'amc');
            print 'duplicate database codes in amc file:<pre>';
            foreach ($amc_codes as $code => $count) {
                if ( $count > 1 )
                    print $code.': '.$count."\n";
            }
            print '</pre>';

            $cif_codes = self::splitFile($dir, 'cif');
            print 'duplicate database codes in cif file:<pre>';
            foreach ($cif_codes as $code => $count) {
                if ( $count > 1 )
                    print $code.': '.$count."\n";
            }
            print '</pre>';

            $dif_codes = self::splitFile($dir, 'dif');
            print 'duplicate database codes in dif file:<pre>';
            foreach ($dif_codes as $code => $count) {
                if ( $count > 1 )
                    print $code.': '.$count."\n";
            }
            print '</pre>';

        }
        catch (\Exception $e) {
            $source = 0x37a03aa6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * TODO
     *
     * @param string $filepath
     * @param string $key
     * @return array
     */
    private function splitFile($filepath, $key)
    {
        if ( !file_exists($filepath.$key) )
            mkdir( $filepath.$key );

        $filename = '../'.$key.'data.txt';
        $input_file = fopen($filepath.$filename, 'r');
        if ( !$input_file )
            throw new ODRException('unable to read '.$filepath.$filename);

        $start = 0;
        $curr = 0;
        $buffer = '';
        $codes = array();
        while ( true ) {
            // No point reading past the end of the file
            if ( feof($input_file) )
                break;

            $char = fgetc($input_file);
            if ( $char === "\n" ) {
                // End of line reached, reset to beginning of line
                $curr = ftell($input_file);
                $ret = fseek($input_file, $start);

                // Read the entire line from the file
                $line = fread($input_file, ($curr - $start) );
                $trimmed_line = trim($line)."\r\n";
                // Can't get rid of carriage returns
//                $line = str_replace("\r", "", $line);

                // If this is the end of the file fragment...
                if ( $trimmed_line === "END\r\n" || $trimmed_line === "_END_\r\n" ) {
                    // Files don't have the filno in them, so have to resort to database code
                    $matches = array();
                    preg_match('/_database_code_amcsd (\d{7,7})/', $buffer, $matches);
                    $database_code = $matches[1];

                    if ( !isset($codes[$database_code]) )
                        $codes[$database_code] = 1;
                    else
                        $codes[$database_code] += 1;

                    $output_file = fopen($filepath.$key.'/'.$database_code.'.txt', 'w');
                    // Ensure there are no newlines before the compound name...
                    $buffer = trim($buffer, "\r\n\t\v\x00");    // don't strip spaces, dif file needs them
                    // Write the file fragment to disk, replacing the ending newlines that were trimmed
//                    fwrite($output_file, $buffer."\r\n\r\n");
                    fwrite($output_file, $buffer."\r\n");
                    fclose($output_file);

                    // Reset for next file
                    $start = $curr;
                    $buffer = '';
                }
                else {
                    // Reset for next line
                    $buffer .= $line;
                    $start = $curr;
                }
            }
        }

        fclose($input_file);
        return $codes;
    }


    /**
     * TODO
     *
     * @param Request $request
     * @return Response
     */
    public function amcsddecryptAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------


            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');

            $dir = $this->container->getParameter('odr_tmp_directory');
            $dir .= '/user_'.$user->getId().'/amcsd_current/';
            if ( !file_exists($dir) )
                mkdir($dir);
            if ( !file_exists($dir.'amc') )
                mkdir($dir.'amc');
            if ( !file_exists($dir.'cif') )
                mkdir($dir.'cif');
            if ( !file_exists($dir.'dif') )
                mkdir($dir.'dif');


            $info = self::getAMCSDInfo($em->getConnection());
            $database_codes = $info['database_codes'];
            $amc_files = $info['amc_files'];
            $cif_files = $info['cif_files'];
            $dif_files = $info['dif_files'];


            // ----------------------------------------
            // Now that the ids of each of the files are known, decrypt them into the temp directory
            foreach ($database_codes as $dr_id => $filename) {

                $amc_file_id = null;
                if ( isset($amc_files[$dr_id]) )
                    $amc_file_id = $amc_files[$dr_id];
                $cif_file_id = null;
                if ( isset($cif_files[$dr_id]) )
                    $cif_file_id = $cif_files[$dr_id];
                $dif_file_id = null;
                if ( isset($dif_files[$dr_id]) )
                    $dif_file_id = $dif_files[$dr_id];

                if ( is_null($amc_file_id) )
                    throw new ODRException('no amc file for datarecord '.$dr_id);
                if ( is_null($cif_file_id) )
                    throw new ODRException('no cif file for datarecord '.$dr_id);
                if ( is_null($dif_file_id) )
                    throw new ODRException('no dif file for datarecord '.$dr_id);


                if ( !file_exists($dir.'amc/'.$filename) ) {
                    $amc_filepath = $crypto_service->decryptFile($amc_file_id, $filename);
                    rename($amc_filepath, $dir.'amc/'.$filename);
                }

                if ( !file_exists($dir.'cif/'.$filename) ) {
                    $cif_filepath = $crypto_service->decryptFile($cif_file_id, $filename);
                    rename($cif_filepath, $dir.'cif/'.$filename);
                }

                if ( !file_exists($dir.'dif/'.$filename) ) {
                    $dif_filepath = $crypto_service->decryptFile($dif_file_id, $filename);
                    rename($dif_filepath, $dir.'dif/'.$filename);
                }
            }

        }
        catch (\Exception $e) {
            $source = 0x2530aca3;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * @param \Doctrine\DBAL\Connection $conn
     * @return array
     */
    private function getAMCSDInfo($conn)
    {
        // ----------------------------------------
        // Need to get the dataype id...
        $query =
           'SELECT dt.id AS dt_id
            FROM odr_data_type dt
            LEFT JOIN odr_data_type_meta dtm ON dtm.data_type_id = dt.id
            WHERE dtm.short_name = "AMCSD"
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        $dt_id = 0;
        foreach ($results as $result)
            $dt_id = $result['dt_id'];


        // ----------------------------------------
        // ...so that the relevant datafield ids can be located
        $query =
           'SELECT rpm.data_field_id AS df_id, rpf.field_name
            FROM odr_render_plugin rp
            LEFT JOIN odr_render_plugin_instance rpi ON rpi.render_plugin_id = rp.id
            LEFT JOIN odr_render_plugin_map rpm ON rpm.render_plugin_instance_id = rpi.id
            LEFT JOIN odr_render_plugin_fields rpf ON rpm.render_plugin_fields_id = rpf.id
            WHERE rp.plugin_class_name = "odr_plugins.rruff.amcsd" AND rpi.data_type_id = '.$dt_id.'
            AND rp.deletedAt IS NULL AND rpf.deletedAt IS NULL
            AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        $database_code_df_id = $amc_df_id = $cif_df_id = $dif_df_id = 0;
        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $rpf_name = $result['field_name'];

            switch ($rpf_name) {
                case 'database_code_amcsd':
                    $database_code_df_id = $df_id;
                    break;
                case 'AMC File':
                    $amc_df_id = $df_id;
                    break;
                case 'CIF File':
                    $cif_df_id = $df_id;
                    break;
                case 'DIF File':
                    $dif_df_id = $df_id;
                    break;
            }
        }


        // ----------------------------------------
        // ...and now that both the datatype id and the datafield ids are known, get the values
        //  for each of the four fields for each AMCSD datarecord on ODR
        $database_codes = array();
        $amc_files = array();
        $cif_files = array();
        $dif_files = array();

        $query =
           'SELECT dr.id AS dr_id, sv.value AS val
            FROM odr_data_record dr
            LEFT JOIN odr_data_record_fields drf ON drf.data_record_id = dr.id
            LEFT JOIN odr_short_varchar sv ON sv.data_record_fields_id = drf.id
            WHERE dr.data_type_id = '.$dt_id.' AND sv.data_field_id = '.$database_code_df_id.'
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND sv.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $val = $result['val'];
            $database_codes[$dr_id] = $val.'.txt';
        }

        $query =
           'SELECT dr.id AS dr_id, amc_file.id AS val
            FROM odr_data_record dr
            LEFT JOIN odr_data_record_fields drf ON drf.data_record_id = dr.id
            LEFT JOIN odr_file amc_file ON amc_file.data_record_fields_id = drf.id
            WHERE dr.data_type_id = '.$dt_id.' AND amc_file.data_field_id = '.$amc_df_id.'
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND amc_file.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $val = $result['val'];
            $amc_files[$dr_id] = $val;
        }

        $query =
           'SELECT dr.id AS dr_id, cif_file.id AS val
            FROM odr_data_record dr
            LEFT JOIN odr_data_record_fields drf ON drf.data_record_id = dr.id
            LEFT JOIN odr_file cif_file ON cif_file.data_record_fields_id = drf.id
            WHERE dr.data_type_id = '.$dt_id.' AND cif_file.data_field_id = '.$cif_df_id.'
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND cif_file.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $val = $result['val'];
            $cif_files[$dr_id] = $val;
        }

        $query =
           'SELECT dr.id AS dr_id, dif_file.id AS val
            FROM odr_data_record dr
            LEFT JOIN odr_data_record_fields drf ON drf.data_record_id = dr.id
            LEFT JOIN odr_file dif_file ON dif_file.data_record_fields_id = drf.id
            WHERE dr.data_type_id = '.$dt_id.' AND dif_file.data_field_id = '.$dif_df_id.'
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND dif_file.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $val = $result['val'];
            $dif_files[$dr_id] = $val;
        }

        $info = array(
            'datatype_id' => $dt_id,
            'database_code_df_id' => $database_code_df_id,
            'database_codes' => $database_codes,
            'amc_file_df_id' => $amc_df_id,
            'amc_files' => $amc_files,
            'cif_file_df_id' => $cif_df_id,
            'cif_files' => $cif_files,
            'dif_file_df_id' => $dif_df_id,
            'dif_files' => $dif_files,
        );

        return $info;
    }


    /**
     * TODO
     *
     * @param Request $request
     * @return Response
     */
    public function amcsddiffAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------


            $dir = $this->container->getParameter('odr_tmp_directory').'/user_'.$user->getId().'/';
            $new_basedir = $dir.'amcsd/';
            $current_basedir = $dir.'amcsd_current/';
            $modified_basedir = $dir.'amcsd_modified/';

            if ( !file_exists($modified_basedir) )
                mkdir($modified_basedir);
            if ( !file_exists($modified_basedir.'amc') )
                mkdir($modified_basedir.'amc');
            if ( !file_exists($modified_basedir.'cif') )
                mkdir($modified_basedir.'cif');
            if ( !file_exists($modified_basedir.'dif') )
                mkdir($modified_basedir.'dif');


            $new = array('amc' => 0, 'cif' => 0, 'dif' => 0);
            $changed = array('amc' => 0, 'cif' => 0, 'dif' => 0);

            $filetypes = array('amc', 'cif', 'dif');
            foreach ($filetypes as $filetype) {
                $new_filelist = scandir($new_basedir.$filetype);
                foreach ($new_filelist as $filename) {
                    if ( $filename === '.' || $filename === '..' )
                        continue;

                    if ( !file_exists($current_basedir.$filetype.'/'.$filename) ) {
                        copy($new_basedir.$filetype.'/'.$filename, $modified_basedir.$filetype.'/'.$filename);
                        $new[$filetype] += 1;
                    }
                    else {
                        $current_hash = md5_file($current_basedir.$filetype.'/'.$filename);
                        $new_hash = md5_file($new_basedir.$filetype.'/'.$filename);

                        if ( $current_hash === $new_hash ) {
//                        unlink($new_basedir.$filetype.'/'.$filename);
//                        unlink($current_basedir.$filetype.'/'.$filename);
                        }
                        else {
                            copy($new_basedir.$filetype.'/'.$filename, $modified_basedir.$filetype.'/'.$filename);
                            $changed[$filetype] += 1;
                        }
                    }
                }
            }

            print 'new: <pre>'.print_r($new, true).'</pre>';
            print 'changed: <pre>'.print_r($changed, true).'</pre>';

        }
        catch (\Exception $e) {
            $source = 0x28e46c62;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    public function amcsdupdateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $datafield_repository = $em->getRepository('ODRAdminBundle:DataFields');
            $datarecord_repository = $em->getRepository('ODRAdminBundle:DataRecord');
            $file_repository = $em->getRepository('ODRAdminBundle:File');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var EntityCreationService $entity_creation_service */
            $entity_creation_service = $this->container->get('odr.entity_creation_service');
            /** @var ODRUploadService $odr_upload_service */
            $odr_upload_service = $this->container->get('odr.upload_service');

            /** @var Logger $logger */
            $logger = $this->container->get('logger');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            $odr_web_dir = $this->container->getParameter('odr_web_directory');
            $dir = $this->container->getParameter('odr_tmp_directory').'/user_'.$user->getId().'/';
            $new_basedir = $dir.'amcsd/';
            $current_basedir = $dir.'amcsd_current/';
            $modified_basedir = $dir.'amcsd_modified/';

            $info = self::getAMCSDInfo($em->getConnection());
            $database_codes = array_flip( $info['database_codes'] );
            $files = array(
                'amc' => $info['amc_files'],
                'cif' => $info['cif_files'],
                'dif' => $info['dif_files'],
            );

            /** @var DataType $amcsd_dt */
            $amcsd_dt = $em->getRepository('ODRAdminBundle:DataType')->find( $info['datatype_id'] );
            if ( $amcsd_dt == null )
                throw new ODRNotFoundException('Datatype');

            $amc_file_df_id = $info['amc_file_df_id'];
            $cif_file_df_id = $info['cif_file_df_id'];
            $dif_file_df_id = $info['dif_file_df_id'];
            $df_lookup = array(
                'amc' => $datafield_repository->find($amc_file_df_id),
                'cif' => $datafield_repository->find($cif_file_df_id),
                'dif' => $datafield_repository->find($dif_file_df_id),
            );
            foreach ($df_lookup as $df) {
                if ( $df == null )
                    throw new ODRNotFoundException('datafield');
            }

            $dr_lookup = array();


            $filetypes = array('amc', 'cif', 'dif');
            foreach ($filetypes as $filetype) {
                $modified_filelist = scandir($modified_basedir.$filetype);
                foreach ($modified_filelist as $filename) {
                    if ( $filename === '.' || $filename === '..' )
                        continue;

                    if ( !isset($database_codes[$filename]) ) {
                        // Need to create a new datarecord
                        $new_dr = $entity_creation_service->createDatarecord($user, $amcsd_dt);
                        $em->refresh($new_dr);
                        $logger->debug('amcsd update: created new datarecord, id '.$new_dr->getId());

                        // Fire off an event notifying that a datarecord got created
                        try {
                            $event = new DatarecordCreatedEvent($new_dr, $user);
                            $dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't want to rethrow the error since it'll interrupt everything after this
                            //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                        }

                        $dr_lookup[ $new_dr->getId() ] = $new_dr;
                        $database_codes[$filename] = $new_dr->getId();
                    }

                    // Now that the datarecord is guaranteed to exist...
                    $dr_id = $database_codes[$filename];
                    $logger->debug('amcsd update: checking '.$filetype.' file "'.$filename.'", belonging to datarecord '.$dr_id.'...');
                    if ( isset($files[$filetype][$dr_id]) ) {
                        // ...datarecord already has a file for this filetype
                        $existing_file_id = $files[$filetype][$dr_id];
                        /** @var File $existing_file */
                        $existing_file = $file_repository->find($existing_file_id);
                        $drf = $existing_file->getDataRecordFields();
                        $logger->debug('amcsd update: -- file already exists, manually deleting file '.$existing_file->getId());

                        // Delete the existing file...
                        // NOTE: doing it "manually" instead of using the EntityDeletionService
                        //  because the subsequent event dispatches just slow things down
                        $file_upload_path = $odr_web_dir.'/uploads/files/';
                        $decrypted_filename = 'File_'.$existing_file->getId().'.'.$existing_file->getExt();
                        $absolute_path = realpath($file_upload_path).'/'.$decrypted_filename;

                        if ( file_exists($absolute_path) )
                            unlink($absolute_path);

                        // Delete the file and its current metadata entry
                        $file_meta = $existing_file->getFileMeta();
                        $file_meta->setDeletedAt(new \DateTime());
                        $em->persist($file_meta);

                        $existing_file->setDeletedBy($user);
                        $existing_file->setDeletedAt(new \DateTime());
                        $em->persist($existing_file);
                        $em->flush();


                        // ...and upload the modified file...
                        $new_filename = $filename;
                        if ( $filetype !== 'dif' ) {
                            // TODO - name these with fileno?
                            $new_filename = substr($filename, 0, -3).$filetype;
                            rename($modified_basedir.$filetype.'/'.$filename, $modified_basedir.$filetype.'/'.$new_filename);
                        }
                        $logger->debug('amcsd update: -- attempting to upload new file "'.$new_filename.'"...');

                        $new_file = $odr_upload_service->uploadNewFile($modified_basedir.$filetype.'/'.$new_filename, $user, $drf);    // TODO - public status?
                        // ...and update the relevant array entry
                        $files[$filetype][$dr_id] = $new_file->getId();
                        $logger->debug('amcsd update: -- new file '.$new_file->getId().' scheduled for encryption');
                    }
                    else {
                        // ...datarecord does not have a file for this filetype
                        if ( !isset($dr_lookup[$dr_id]) ) {
                            /** @var DataRecord $dr */
                            $dr = $datarecord_repository->find($dr_id);
                            if ( $dr == null )
                                throw new ODRNotFoundException('Datarecord '.$dr_id);
                            $dr_lookup[$dr_id] = $dr;
                        }

                        $logger->debug('amcsd update: -- file does not exist...');
                        $dr = $dr_lookup[$dr_id];
                        $df = $df_lookup[$filetype];
                        $drf = $entity_creation_service->createDatarecordField($user, $dr, $df);

                        // Upload the new file...
                        $new_filename = $filename;
                        if ( $filetype !== 'dif' ) {
                            // TODO - name these with fileno?
                            $new_filename = substr($filename, 0, -3).$filetype;
                            rename($modified_basedir.$filetype.'/'.$filename, $modified_basedir.$filetype.'/'.$new_filename);
                        }
                        $logger->debug('amcsd update: -- attempting to upload new file "'.$new_filename.'"...');

                        $new_file = $odr_upload_service->uploadNewFile($modified_basedir.$filetype.'/'.$new_filename, $user, $drf);    // TODO - public status?
                        // ...and update the relevant array entry
                        $files[$filetype][$dr_id] = $new_file->getId();
                        $logger->debug('amcsd update: -- new file '.$new_file->getId().' scheduled for encryption');
                    }
                }
            }

        }
        catch (\Exception $e) {
            $source = 0x1a78e1b8;
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
