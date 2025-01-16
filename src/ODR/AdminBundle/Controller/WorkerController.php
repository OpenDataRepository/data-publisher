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
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\DatatypeImportedEvent;
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
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\TagHelperService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\GraphBundle\Component\Service\AMCSDUpdateService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Pheanstalk\Pheanstalk;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Templating\EngineInterface;


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
     * Ensure all selected tags for a set of datarecords also have selected parents.
     *
     * @param Request $request
     * @return Response
     */
    public function tagrebuildworkerAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if ( !isset($post['tracked_job_id']) || !isset($post['user_id']) || !isset($post['datarecord_list']) || !isset($post['datafield_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            $tracked_job_id = $post['tracked_job_id'];
            $user_id = $post['user_id'];
            $datarecord_list = trim($post['datarecord_list']);
            $datafield_id = $post['datafield_id'];

            $api_key = $post['api_key'];
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

            /** @var TagHelperService $tag_helper_service */
            $tag_helper_service = $this->container->get('odr.tag_helper_service');
            /** @var Logger $logger */
            $logger = $this->get('logger');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var ODRUser $user */
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
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

            /** @var TrackedJob $tracked_job */
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);
            if ($tracked_job == null)
                throw new ODRNotFoundException('TrackedJob');


            // ----------------------------------------
            // Verify that the datarecord list is legitimate
            if ( $datarecord_list === '' || $datarecord_list === ',' )
                throw new ODRBadRequestException('Empty Datarecord list');

            $datarecord_list = explode(',', $datarecord_list);
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id, dr.id AS dr_id, ts.selected, t.id AS tag_id
                FROM ODRAdminBundle:DataType dt
                LEFT JOIN ODRAdminBundle:DataRecord dr WITH dr.dataType = dt
                LEFT JOIN ODRAdminBundle:DataRecordFields drf WITH drf.dataRecord = dr
                LEFT JOIN ODRAdminBundle:TagSelection ts WITH ts.dataRecordFields = drf
                LEFT JOIN ODRAdminBundle:Tags t WITH ts.tag = t
                WHERE dr.id IN (:datarecord_ids) AND drf.dataField = :datafield_id
                AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND ts.deletedAt IS NULL AND t.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datarecord_ids' => $datarecord_list,
                    'datafield_id' => $datafield->getId(),
                )
            );
            $results = $query->getArrayResult();

            $current_selections = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $dr_id = $result['dr_id'];
                $selected = $result['selected'];
                $tag_id = $result['tag_id'];

                if ( $dt_id !== $datatype->getId() )
                    throw new ODRBadRequestException('Invalid Datarecord '.$dr_id);

                if ( !isset($current_selections[$dr_id]) )
                    $current_selections[$dr_id] = array();

                // Only want selected tags...building up an array of those and then submitting it
                //  to the TagHelperService will end up ensuring all the tag parents get selected
                if ( $selected === 1 )
                    $current_selections[$dr_id][$tag_id] = 1;
            }


            // ----------------------------------------
            // Load/precompute both the tag hierarchy and its inverse to slightly reduce the amound
            //  of work the tag helper service has to do
            $tag_hierarchy = $tag_helper_service->getTagHierarchy($top_level_datatype->getId());
            // Want to cut the datatype/datafield levels out of the hierarchy if they're in there
            if ( !isset($tag_hierarchy[$datatype->getId()][$datafield->getId()]) )
                throw new ODRBadRequestException('Invalid tag hierarchy for TagHelperService::updateSelectedTags()', 0x2078e3a4);
            $tag_hierarchy = $tag_hierarchy[$datatype->getId()][$datafield->getId()];

            // Need to invert the provided hierarchies so that the code can look up the parent
            //  tag when given a child tag
            $inversed_tag_hierarchy = array();
            foreach ($tag_hierarchy as $parent_tag_id => $children) {
                foreach ($children as $child_tag_id => $tmp)
                    $inversed_tag_hierarchy[$child_tag_id] = $parent_tag_id;
            }


            // Keep track of which datarecords need events fired
            $datarecord_lookup = array();
            foreach ($current_selections as $dr_id => $selections) {
                /** @var DataRecordFields $drf */
                $drf = $repo_datarecordfields->findOneBy(
                    array(
                        'dataRecord' => $dr_id,
                        'dataField' => $datafield_id
                    )
                );
                if ( is_null($drf) ) {
                    // This shouldn't happen at this point
                    throw new ODRNotFoundException('DataRecordFields for dr '.$dr_id.', df '.$datafield_id.' not found', true);
                }

                // Resubmit the list of selections
                $change_made = $tag_helper_service->updateSelectedTags($user, $drf, $selections, true, $tag_hierarchy, $inversed_tag_hierarchy);    // delay flush...
                if ($change_made)
                    $datarecord_lookup[$dr_id] = $drf->getDataRecord();
            }

            // Update the job tracker if necessary
            $ret = '';
            if ($tracked_job_id !== -1) {
                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total && $total != -1)
                    $tracked_job->setCompleted( new \DateTime() );

                $em->persist($tracked_job);
                $ret .= '  Set current to '.$count."\n";
            }

            $em->flush();
            $return['d'] = $ret;


            // ----------------------------------------
            // In an attempt to delay flushing...fire the events off here at the end
            if ( !empty($datarecord_lookup) ) {
                try {
                    $event = new DatafieldModifiedEvent($datafield, $user);
                    $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }

                foreach ($datarecord_lookup as $dr_id => $dr) {
                    try {
                        $event = new DatarecordModifiedEvent($dr, $user);
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
            // This is only ever called from command-line...
            $request->setRequestFormat('json');

            $source = 0x33462c05;
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
     * Triggers the AMCSD Update process chain
     *
     * @param Request $request
     * @return Response
     */
    public function amcsdupdatetriggerAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

//            /** @var AMCSDUpdateService $amcsd_update_service */
//            $amcsd_update_service = $this->container->get('odr.amcsd_update_service');
//            $amcsd_update_service->amcsdupdateAction(2, null);
//            throw new ODRException('do not continue');

            /** @var Pheanstalk $pheanstalk */
            $pheanstalk = $this->get('pheanstalk');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $api_key = $this->container->getParameter('beanstalk_api_key');


            // Not sure what state the server was left in, so wipe the jobs off of it
            // TODO - probably need to test this on prod before just blindly using it
            $tubes = $pheanstalk->listTubes();
            foreach ($tubes as $tube) {
                /** @var Pheanstalk\Response\ArrayResponse $ret */
                $ret = $pheanstalk->statsTube($tube);
                $tmp = $ret->getArrayCopy();
                $job_count = $tmp['total-jobs'];
                for ($i = $job_count; $i > 0; $i--) {
                    $job = $pheanstalk->watch($tube)->ignore('default')->reserve();
                    $pheanstalk->delete($job);
                }
            }

            // Insert the new job into the queue
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "user_id" => $user->getId(),
                    "redis_prefix" => $redis_prefix,
                    "api_key" => $api_key,
                )
            );

            $delay = 1;
            $pheanstalk->useTube('amcsd_1_parse')->put($payload, $priority, $delay);
//            $pheanstalk->useTube('amcsd_2_decrypt')->put($payload, $priority, $delay);
//            $pheanstalk->useTube('amcsd_3_diff')->put($payload, $priority, $delay);
//            $pheanstalk->useTube('amcsd_4_references')->put($payload, $priority, $delay);
//            $pheanstalk->useTube('amcsd_5_update')->put($payload, $priority, $delay);
        }
        catch (\Exception $e) {
            $source = 0xe5ca8383;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    public function asdfAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();

            $lookup = 'reference';
//            $lookup = 'mineral';
//            $lookup = 'instrument';

            $props = array();
            if ( $lookup === 'reference' ) {
                $props = array(
                    'table' => 'odr_integer_value',
                    'datatype_id' => 734,
                    'datafield_id' => 7049,
                );
            }
            else if ( $lookup === 'mineral' ) {
                $props = array(
                    'table' => 'odr_integer_value',
                    'datatype_id' => 736,
                    'datafield_id' => 7050,
                );
            }
            else if ( $lookup === 'instrument' ) {
                $props = array(
                    'table' => 'odr_short_varchar',
                    'datatype_id' => 741,
                    'datafield_id' => 7080,
                );
            }
            else
                throw new ODRException('Unknown lookup type');


            // ----------------------------------------
            $query =
               'SELECT dr.unique_id, e.value AS ref_id
                FROM odr_data_record dr
                LEFT JOIN odr_data_record_fields drf ON dr.id = drf.data_record_id
                LEFT JOIN '.$props['table'].' e ON e.data_record_fields_id = drf.id
                WHERE dr.data_type_id = '.$props['datatype_id'].' AND e.data_field_id = '.$props['datafield_id'].'
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL';
            $results = $conn->executeQuery($query);

            print '<table border="1">';
            print '<tr><th>ref_id</th><th>unique_id</th></tr>';
            foreach ($results as $result) {
                $unique_id = $result['unique_id'];
                $ref_id = $result['ref_id'];

                print '<tr>';
                print '<td>'.$ref_id.'</td>';
                print '<td>'.$unique_id.'</td>';
                print '</tr>';
            }
            print '</table>';

        }
        catch (\Exception $e) {
            $source = 0xffffffff;
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
