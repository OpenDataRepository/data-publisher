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
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
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
use ODR\OpenRepository\GraphBundle\Plugins\SearchOverrideInterface;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
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
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Doctrine\DBAL\Connection as DBALConnection;
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
                // ShortVarchar fieldtypes have both 'value' and 'converted_value'
                else if ( $new_fieldtype->getTypeClass() === 'ShortVarchar' )
                    $insert_query .= ', converted_value';
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
                $select_query_fragments = $remaining_query_fragments = array();

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
                    // IMPORTANT: changes made here must also be transferred to ReportsController::convert_to_integer()

                    // Converting text into an integer requires a cast...
                    $select_query .= 'CAST(e.value AS SIGNED)';
                    // Only copy non-blank values
                    $remaining_query .= ' AND e.value != ""';

                    // ...but it also needs both a REGEX and BETWEEN conditions, otherwise an
                    //  error will be thrown when encountering values that aren't valid 4 byte
                    //  integers
                    $remaining_query .= ' AND REGEXP_LIKE(e.value, "'.ValidUtility::INTEGER_MIGRATE_REGEX.'")';

                    // The regex MUST come before the BETWEEN, otherwise the BETWEEN will throw
                    //  warnings (which are upgraded to errors) when comparing non-integer values
                    $remaining_query .= ' AND CAST(e.value AS DOUBLE) BETWEEN -2147483648 AND 2147483647';
                    // NOTE - the cast here uses a DOUBLE, since that can handle absurdly large
                    //  numbers...if it was instead cast to a SIGNED here, then it would be much more
                    //  likely to encounter an "out of range" value, and crash the whole migration
                }
                else if ( $old_is_text && $new_typeclass === 'DecimalValue' ) {
                    // There are two queries that need to be run...

                    // IMPORTANT: changes made here must also be transferred to ReportsController::convert_to_decimal()

                    // ----------------------------------------
                    // The first is going to convert numbers with an optional exponent
                    //  e.g. 12.34 OR 12.34e-3

                    // Need to use mysql's CAST() to generate a double...
                    $select_query_fragment = 'CAST(SUBSTR(e.value, 1, 32) AS DOUBLE)';
                    // ...but the original_value should just match the original text being converted
                    $select_query_fragment .= ', SUBSTR(e.value, 1, 32)';    // TODO - this guarantees a fit inside a varchar(255), but it probably shouldn't even be varchar(32) due to precision

                    // Only cast non-blank values...
                    $remaining_query_fragment = ' AND e.value != ""';
                    // ...and only cast values that match a regex to prevent warnings/errors...for
                    //  whatever reason, every warning gets "upgraded" to an error
                    $remaining_query_fragment .= ' AND REGEXP_LIKE(e.value, "'.ValidUtility::DECIMAL_MIGRATE_REGEX_A.'")';

                    // This is the first of two queries
                    $select_query_fragments = array(0 => $select_query_fragment);
                    $remaining_query_fragments = array(0 => $remaining_query_fragment);

                    // ----------------------------------------
                    // The second is going to convert numbers with a spectrographic tolerance
                    //  e.g. 12.34(56)

                    // Only call CAST() on the stuff before the parenthesis...
                    $select_query_fragment = 'CAST(SUBSTR(e.value, 1, LOCATE("(",e.value)-1) AS DOUBLE)';
                    // ...but leave the original_value as the original text
                    $select_query_fragment .= ', SUBSTR(e.value, 1, 32)';    // TODO - this guarantees a fit inside a varchar(255), but it probably shouldn't even be varchar(32) due to precision

                    // Only cast non-blank values...
                    $remaining_query_fragment = ' AND e.value != ""';
                    // ...and only cast values that match a regex to prevent warnings/errors...for
                    //  whatever reason, every warning gets "upgraded" to an error
                    $remaining_query_fragment .= ' AND REGEXP_LIKE(e.value, "'.ValidUtility::DECIMAL_MIGRATE_REGEX_B.'")';

                    $select_query_fragments[1] = $select_query_fragment;
                    $remaining_query_fragments[1] = $remaining_query_fragment;
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

                // Need to provide a value for this column...but don't bother attempting to fill it
                //  out properly
                if ( $new_typeclass === 'ShortVarchar' )
                    $select_query.= ', ""';


                // Stitch all parts of the query together and execute it
                if ( empty($select_query_fragments) ) {
                    $final_query = $insert_query.$select_query.$remaining_query;
                    $logger->debug('WorkerController::migrateAction() tracked_job '.$tracked_job_id.': '.$final_query);

                    $rows = $conn->executeUpdate($final_query);
                    $logger->info('WorkerController::migrateAction() tracked_job '.$tracked_job_id.': copied '.$rows.' rows of data from "'.$old_typeclass.'" to "'.$new_typeclass.'" for datafield '.$datafield->getId());
                }
                else {
                    for ($i = 0; $i < count($select_query_fragments); $i++) {
                        $select_query_fragment = $select_query_fragments[$i];
                        $remaining_query_fragment = $remaining_query_fragments[$i];

                        $final_query = $insert_query.$select_query.$select_query_fragment.$remaining_query.$remaining_query_fragment;
                        // DecimalValue queries involve regexes, which need to have escaped backslashes
                        //  for mysql...
                        if ( $new_typeclass === 'DecimalValue' )
                            $final_query = str_replace("\\", "\\\\", $final_query);
                        $logger->debug('WorkerController::migrateAction() tracked_job '.$tracked_job_id.': '.$final_query);

                        $rows = $conn->executeUpdate($final_query);
                        $logger->info('WorkerController::migrateAction() tracked_job '.$tracked_job_id.': copied '.$rows.' rows of data from "'.$old_typeclass.'" to "'.$new_typeclass.'" for datafield '.$datafield->getId());
                    }
                }


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

                $dr_list = $search_service->getCachedDatarecordList($datatype->getGrandparent()->getId());
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


    public function asdfAction($search_key, $complete, Request $request)
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

            /** @var DatatreeInfoService $datatree_info_service */
            $datatree_info_service = $this->container->get('odr.datatree_info_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchService $search_service */
            $search_service = $this->container->get('odr.search_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');

            $user_permissions = array();
            $search_as_super_admin = true;
            $ignore_searchable = true;

            // TODO - some of these search keys effectively aren't covered in the existing tests...
            // unrigged criteria

            // ----------------------------------------
            // rruff references
//            $params = array('dt_id' => 734, 'inverse' => '-1');  // should total 24712
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsIjcwMzUiOiJkb3ducyIsICJpbnZlcnNlIjoiLTEifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsIjcwMzUiOiJkb3ducyIsICJpbnZlcnNlIjoiLTEifQ
            // ima list
//            $params = array('dt_id' => 736, '7062' => "-1094,-1104");  // should total 6215, due to including tags
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsIjcwNjIiOiItMTA5NCwtMTEwNCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwNjIiOiItMTA5NCwtMTEwNCJ9
            // ima list (no tags)
//            $params = array('dt_id' => 736, '7062' => "*1094,*1104");  // should total 6534
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCI3MDYyIjoiKjEwOTQsKjExMDQifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCI3MDYyIjoiKjEwOTQsKjExMDQifQ
            // rruff samples
//            $params = array('dt_id' => 738);  // should total 6339
//            https://theta.odr.io/app_dev.php/rruff_sample#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczOCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczOCJ9


            // rruff references courtesy of Linsay Podjasek
//            $params = array('dt_id' => 734, 'inverse' => 736, 'dt_734_c_by' => '276', '7062' => "-1094,-1104");  // 0, because none of Lindsay's references are linked to by IMA
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF83MzRfY19ieSI6IjI3NiIsImR0X2lkIjoiNzM2IiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF83MzRfY19ieSI6IjI3NiIsImR0X2lkIjoiNzM2IiwiNzA2MiI6IioxMDk0LCoxMTA0In0

//            $params = array('dt_id' => 734, 'inverse' => '-1', 'dt_734_c_by' => '276');  // 64 total
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF83MzRfY19ieSI6IjI3NiIsImR0X2lkIjoiNzM0IiwiaW52ZXJzZSI6Ii0xIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF83MzRfY19ieSI6IjI3NiIsImR0X2lkIjoiNzM0IiwiaW52ZXJzZSI6Ii0xIn0

//            $params = array('dt_id' => 734, 'dt_734_c_by' => '276');  // should also be 64 total
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF83MzRfY19ieSI6IjI3NiIsImR0X2lkIjo3MzR9


            // ----------------------------------------
            // ima records where status notes have 'antiquity'
//            $params = array('dt_id' => 736, '7614' => 'antiquity');  // should total 36
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsIjcwNjIiOiItMTA5NCwtMTEwNCIsIjc2MTQiOiJhbnRpcXVpdHkifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwNjIiOiItMTA5NCwtMTEwNCIsIjc2MTQiOiJhbnRpcXVpdHkifQ

//             ima records where status notes have 'antiquity' in a note that's not first
//            $params = array('dt_id' => 736, '7614' => 'antiquity', '7613' => '>0');  // should total 1...35 of the 36 have 'antiquity' in status note #0
//            $params = array('dt_id' => 736, '7614' => 'antiquity', '7613' => '>0', 'set' => 1);  // should total 2...Hematite ~directly~ matches, while Melanterite has 'antiquity' AND 'status note >0'...just not in the same status note record
            // no actual search for it
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwNjIiOiItMTA5NCwtMTEwNCIsIjc2MTQiOiJhbnRpcXVpdHkiLCI3NjEzIjoiPjAifQ

            // references of status notes with 'antiquity'
//            $params = array('dt_id' => 734, 'inverse' => 736, '7062' => '*1094,*1104', '7614' => 'antiquity');  // should total 9
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDYyIjoiKjEwOTQsKjExMDQiLCI3NjE0IjoiYW50aXF1aXR5In0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDYyIjoiKjEwOTQsKjExMDQiLCI3NjE0IjoiYW50aXF1aXR5In0

            // ima where status notes have 'antiquity' and author has 'downs'
//            $params = array('dt_id' => 736, '7062' => '*1094,*1104', '7614' => 'antiquity', '7035' => 'downs');  // 15 total...36 minerals have a status note with 'antiquity', and 15 of those have a reference from 'downs'
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCI3MDM1IjoiZG93bnMiLCI3MDYyIjoiKjEwOTQsKjExMDQiLCI3NjE0IjoiYW50aXF1aXR5In0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCI3MDM1IjoiZG93bnMiLCI3MDYyIjoiKjEwOTQsKjExMDQiLCI3NjE0IjoiYW50aXF1aXR5In0

            // references where status notes have 'antiquity' and author has 'downs'
//            $params = array('dt_id' => 734, 'inverse' => 736, '7062' => '*1094,*1104', '7614' => 'antiquity', '7035' => 'downs');  // should total 0...'downs' hasn't been around since antiquity
            // NOTE: existing search system returns 165, because it takes antiquity/downs "up" to a list of minerals before "going back down" to references
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDYyIjoiKjEwOTQsKjExMDQiLCI3NjE0IjoiYW50aXF1aXR5IiwiNzAzNSI6ImRvd25zIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDYyIjoiKjEwOTQsKjExMDQiLCI3NjE0IjoiYW50aXF1aXR5IiwiNzAzNSI6ImRvd25zIn0


            // ----------------------------------------
            // ima records where mineral name
//            $params = array('dt_id' => 736, '7052' => 'ab', '7062' => "-1094,-1104");  // should have 117 entries
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsIjcwNTIiOiJhYiIsIjcwNjIiOiItMTA5NCwtMTEwNCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwNTIiOiJhYiIsIjcwNjIiOiItMTA5NCwtMTEwNCJ9

//            $params = array('dt_id' => 736, '7052' => 'abe', '7062' => "-1094,-1104");  // should have 16 entries
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsIjcwNTIiOiJhYmUiLCI3MDYyIjoiLTEwOTQsLTExMDQifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwNTIiOiJhYmUiLCI3MDYyIjoiLTEwOTQsLTExMDQifQ

//            $params = array('dt_id' => 736, '7052' => 'abelsonite', '7062' => "-1094,-1104");  // should have 1 entries
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsIjcwNTIiOiJhYmVsc29uaXRlIiwiNzA2MiI6Ii0xMDk0LC0xMTA0In0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwNTIiOiJhYmVsc29uaXRlIiwiNzA2MiI6Ii0xMDk0LC0xMTA0In0

//            $params = array('dt_id' => 736, '7052' => 'abelsoniteasdf', '7062' => "-1094,-1104");  // should have 0 entries
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsIjcwNTIiOiJhYmVsc29uaXRlYXNkZiIsIjcwNjIiOiItMTA5NCwtMTEwNCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwNTIiOiJhYmVsc29uaXRlYXNkZiIsIjcwNjIiOiItMTA5NCwtMTEwNCJ9


            // ----------------------------------------
            // rruff references, reference author has 'downs'
//            $params = array('dt_id' => 734, '7035' => 'downs');  // should total 206
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsIjcwMzUiOiJkb3ducyIsICJpbnZlcnNlIjoiLTEifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsIjcwMzUiOiJkb3ducyIsICJpbnZlcnNlIjoiLTEifQ

            // ima list, reference author has 'downs'
//            $params = array('dt_id' => 736, '7035' => 'downs', '7062' => "-1094,-1104");  // should total 368
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsIjcwMzUiOiJkb3ducyIsIjcwNjIiOiItMTA5NCwtMTEwNCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwMzUiOiJkb3ducyIsIjcwNjIiOiItMTA5NCwtMTEwNCJ9

            // rruff samples, reference author has 'downs'
//            $params = array('dt_id' => 738, '7035' => 'downs', '7062' => "-1094,-1104");  // should total 869
//            https://theta.odr.io/app_dev.php/rruff_sample#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczOCIsIjcwMzUiOiJkb3ducyJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczOCIsIjcwMzUiOiJkb3ducyJ9


            // varieties of inverse
//            $params = array('dt_id' => 734, 'inverse' => '-1', '7035' => 'downs');  // should total 206.  existing search system should match
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiItMSIsIjcwMzUiOiJkb3ducyJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiItMSIsIjcwMzUiOiJkb3ducyJ9

//            $params = array('dt_id' => 734, 'inverse' => '736', '7035' => 'downs');  // should also total 206, inverse should have zero effect.  existing search system thinks it's 165
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDM1IjoiZG93bnMifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDM1IjoiZG93bnMifQ

//            $params = array('dt_id' => 734, 'inverse' => '736', '7052' => 'abelsonite');  // should total 5
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDUyIjoiYWJlbHNvbml0ZSJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDUyIjoiYWJlbHNvbml0ZSJ9

//            $params = array('dt_id' => 734, 'inverse' => '736', '7052' => 'abelsonite', '7035' => 'downs');  // should total 1
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDUyIjoiYWJlbHNvbml0ZSIsIjcwMzUiOiJkb3ducyJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDUyIjoiYWJlbHNvbml0ZSIsIjcwMzUiOiJkb3ducyJ9


//            $params = array('dt_id' => 734, 'inverse' => '738', '7052' => 'abelsonite', '7112' => '785');  // should still total 5
//            $params = array('dt_id' => 734, 'inverse' => '738', '7052' => 'abelsonite', '7112' => '785', 'set' => '1');  // should still total 5
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzgiLCI3MDUyIjoiYWJlbHNvbml0ZSIsIjcxMTIiOiI3ODUifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzgiLCI3MDUyIjoiYWJlbHNvbml0ZSIsIjcxMTIiOiI3ODUifQ

//            $params = array('dt_id' => 734, 'inverse' => '738', '7052' => 'abelsonite', '7035' => 'downs', '7112' => '785');  // should still total 1
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzgiLCI3MDUyIjoiYWJlbHNvbml0ZSIsIjcxMTIiOiI3ODUiLCI3MDM1IjoiZG93bnMifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzgiLCI3MDUyIjoiYWJlbHNvbml0ZSIsIjcxMTIiOiI3ODUiLCI3MDM1IjoiZG93bnMifQ

//            $params = array('dt_id' => 734, 'inverse' => '738', '7052' => 'abelsonite', '7112' => '785', '7081' => 'Cameca SX50');  // should still total 5
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzgiLCI3MDUyIjoiYWJlbHNvbml0ZSIsIjcxMTIiOiI3ODUsIjcwODEiOiJDYW1lY2EgU1g1MCcifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzgiLCI3MDUyIjoiYWJlbHNvbml0ZSIsIjcxMTIiOiI3ODUsIjcwODEiOiJDYW1lY2EgU1g1MCcifQ
            // TODO - would be nice if i could somehow figure out how to prevent this one from creating 30+ paths for the instruments datatype to reach the reference datatype
            // TODO - technically, all "paths" for the instrument datatype "go through" the sample datatype, so they could theoretically get "cut off" there
            // TODO - ...but i suspect that doing that would also require the paths involving the instrument list to "finish up" BEFORE rruff samples does anything else

            // ----------------------------------------
            // instruments with 'rossman'
//            $params = array('dt_id' => 741, '7081' => 'Rossman');  // should total 2
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6Ijc0MSIsIjcwODEiOiJyb3NzbWFuIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6Ijc0MSIsIjcwODEiOiJyb3NzbWFuIn0

            // rruff_infrared records that have instruments with 'rossman'
//            $params = array('dt_id' => 758, '7081' => 'Rossman');  // should total 112
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6Ijc1OCIsIjcwODEiOiJyb3NzbWFuIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6Ijc1OCIsIjcwODEiOiJyb3NzbWFuIn0

            // rruff sample records that have instruments with 'rossman'
//            $params = array('dt_id' => 738, '7081' => 'Rossman');  // should also total 112, because rossman is only used for infrared
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczOCIsIjcwODEiOiJyb3NzbWFuIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczOCIsIjcwODEiOiJyb3NzbWFuIn0

            // instruments used by rruff infrared
//            $params = array('dt_id' => 741, 'inverse' => 758, '7141' => 'R');  // should total 20
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6Ijc0MSIsImludmVyc2UiOiI3NTgiLCI3MTQxIjoiUiJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6Ijc0MSIsImludmVyc2UiOiI3NTgiLCI3MTQxIjoiUiJ9


            // ----------------------------------------
            // rruff references, general search of 'downs'
//            $params = array('dt_id' => 734, 'gen' => 'downs');  // should total 213
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImdlbiI6ImRvd25zIiwiaW52ZXJzZSI6Ii0xIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImdlbiI6ImRvd25zIiwiaW52ZXJzZSI6Ii0xIn0

            // ima list, general search of 'downs'
//            $params = array('dt_id' => 736, 'gen' => 'downs', '7062' => "-1094,-1104");  // should total 378
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsImdlbiI6ImRvd25zIiwiNzA2MiI6Ii0xMDk0LC0xMTA0In0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsImdlbiI6ImRvd25zIiwiNzA2MiI6Ii0xMDk0LC0xMTA0In0

            // rruff samples, general search of 'downs'
//            $params = array('dt_id' => 738, 'gen' => 'downs');  // should total 6002
//            https://theta.odr.io/app_dev.php/rruff_sample#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczOCIsImdlbiI6ImRvd25zIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczOCIsImdlbiI6ImRvd25zIn0


            // ----------------------------------------
            // ima minerals (ignoring tags)
//            $params = array('dt_id' => 736, '7062' => "*1094,*1104");  // should total 6534
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9

            // ima minerals (ignoring tags) with a references
//            $params = array('dt_id' => 736, '7062' => "*1094,*1104", '7035' => '!""');  // should total 6490
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsIjcwMzUiOiIhXCJcIiIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwMzUiOiIhXCJcIiIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9

            // ima minerals (ignoring tags) without a references
//            $params = array('dt_id' => 736, '7062' => "*1094,*1104", '7035' => '""');  // should total 44 (6534-6490).  existing search system won't work
//            https://theta.odr.io/app_dev.php/ima#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNiIsIjcwMzUiOiIhXCJcIiIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNiIsIjcwMzUiOiIhXCJcIiIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9


            // ----------------------------------------
//            $params = array('dt_id' => 734, 'inverse' => '-1');  // should total 24712
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsIjcwMzUiOiJkb3ducyIsICJpbnZlcnNlIjoiLTEifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsIjcwMzUiOiJkb3ducyIsICJpbnZlcnNlIjoiLTEifQ

            // references linked to by ima minerals
//            $params = array('dt_id' => 734, 'inverse' => 736, '7052' => '!""');  // should total 18655
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDUyIjoiIVwiXCIifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDUyIjoiIVwiXCIifQ

            // references not linked to by ima minerals
//            $params = array('dt_id' => 734, 'inverse' => 736, '7052' => '""');  // should total 6057 (24712-18655)  existing search system won't work
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDUyIjoiXCJcIiJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczNCIsImludmVyc2UiOiI3MzYiLCI3MDUyIjoiXCJcIiJ9




            // ----------------------------------------
            // ----------------------------------------
            // ----------------------------------------
//            $params = array('dt_id' => 738, 'dt_738_pub' => '0', '7069' => 'R06');  // will return 17 results when logged in
//            $search_as_super_admin = false;  $ignore_searchable = false;  // will return 1057 (1074-17) results when not logged in
//            https://theta.odr.io/app_dev.php/rruff_sample#/app_dev.php/search/display/2010/eyJkdF83MzhfcHViIjoiMCIsImR0X2lkIjoiNzM4IiwiNzA2OSI6IlIwNiJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF83MzhfcHViIjoiMCIsImR0X2lkIjoiNzM4IiwiNzA2OSI6IlIwNiJ9

//            $params = array('dt_id' => 738, '7112' => '532', '7069' => 'R06');  // will return 1064 results when logged in
//            $search_as_super_admin = false;  $ignore_searchable = false;  // will return 1048 (1064-16) results when not logged in
//            https://theta.odr.io/app_dev.php/rruff_sample#/app_dev.php/search/display/2010/eyJkdF9pZCI6IjczOCIsIjcwNjkiOiJSMDYiLCI3MTEyIjoiNTMyIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6IjczOCIsIjcwNjkiOiJSMDYiLCI3MTEyIjoiNTMyIn0


            // ----------------------------------------
            // ----------------------------------------
            // ----------------------------------------
//
//            $params = array('dt_id' => 734, 7035 => 'downs', 'inverse' => -1);  // should return 206 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6ImRvd25zIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6ImRvd25zIn0
//            $params = array('dt_id' => 734, 7036 => 'pyroxene', 'inverse' => -1);  // should return 216 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNiI6InB5cm94ZW5lIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNiI6InB5cm94ZW5lIn0
//
//            $params = array('dt_id' => 734, 7035 => 'downs', 7036 => 'pyroxene', 'inverse' => -1);  // should return 10 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6ImRvd25zIiwiNzAzNiI6InB5cm94ZW5lIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6ImRvd25zIiwiNzAzNiI6InB5cm94ZW5lIn0
//            $params = array('dt_id' => 734, 7035 => 'downs', 7036 => 'pyroxene', 'inverse' => -1, 'merge' => 'OR');  // should return (206-10)+(216-10)+10=412 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwibWVyZ2UiOiJPUiIsIjcwMzUiOiJkb3ducyIsIjcwMzYiOiJweXJveGVuZSJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwibWVyZ2UiOiJPUiIsIjcwMzUiOiJkb3ducyIsIjcwMzYiOiJweXJveGVuZSJ9
//
//            $params = array('dt_id' => 734, 7035 => '!downs', 'inverse' => -1);  // should return (24712-206)=24506 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6IiFkb3ducyJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6IiFkb3ducyJ9
//            $params = array('dt_id' => 734, 7036 => '!pyroxene', 'inverse' => -1);  // should return (24712-216)=24496 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNiI6IiFweXJveGVuZSJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNiI6IiFweXJveGVuZSJ9
//
//            $params = array('dt_id' => 734, 7035 => '!downs', 7036 => 'pyroxene', 'inverse' => -1);  // should return (216-10)=206 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6IiFkb3ducyIsIjcwMzYiOiJweXJveGVuZSJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6IiFkb3ducyIsIjcwMzYiOiJweXJveGVuZSJ9
//            $params = array('dt_id' => 734, 7035 => '!downs', 7036 => 'pyroxene', 'inverse' => -1, 'merge' => 'OR');  // should return (24506+10)=24516
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwibWVyZ2UiOiJPUiIsIjcwMzUiOiIhZG93bnMiLCI3MDM2IjoicHlyb3hlbmUifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwibWVyZ2UiOiJPUiIsIjcwMzUiOiIhZG93bnMiLCI3MDM2IjoicHlyb3hlbmUifQ
//
//            $params = array('dt_id' => 734, 7035 => 'downs', 7036 => '!pyroxene', 'inverse' => -1);  // should return (206-10)=196 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6ImRvd25zIiwiNzAzNiI6IiFweXJveGVuZSJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6ImRvd25zIiwiNzAzNiI6IiFweXJveGVuZSJ9
//            $params = array('dt_id' => 734, 7035 => 'downs', 7036 => '!pyroxene', 'inverse' => -1, 'merge' => 'OR');  // should return (24496+10)=24506
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwibWVyZ2UiOiJPUiIsIjcwMzUiOiJkb3ducyIsIjcwMzYiOiIhcHlyb3hlbmUifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwibWVyZ2UiOiJPUiIsIjcwMzUiOiJkb3ducyIsIjcwMzYiOiIhcHlyb3hlbmUifQ
//
//            $params = array('dt_id' => 734, 7035 => '!downs', 7036 => '!pyroxene', 'inverse' => -1);  // should return 24712-206-216+10=24300 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6IiFkb3ducyIsIjcwMzYiOiIhcHlyb3hlbmUifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwiNzAzNSI6IiFkb3ducyIsIjcwMzYiOiIhcHlyb3hlbmUifQ
//            $params = array('dt_id' => 734, 7035 => '!downs', 7036 => '!pyroxene', 'inverse' => -1, 'merge' => 'OR');  // should return 24712-10=24702 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwibWVyZ2UiOiJPUiIsIjcwMzUiOiIhZG93bnMiLCI3MDM2IjoiIXB5cm94ZW5lIn0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM0LCJpbnZlcnNlIjotMSwibWVyZ2UiOiJPUiIsIjcwMzUiOiIhZG93bnMiLCI3MDM2IjoiIXB5cm94ZW5lIn0
//
//            // ----------------------------------------
//            // ----------------------------------------
//            // ----------------------------------------
//
//            $params = array('dt_id' => 736, 7035 => 'downs', '7062' => "*1094,*1104");  // should return 371 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCI3MDM1IjoiZG93bnMiLCI3MDYyIjoiKjEwOTQsKjExMDQifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCI3MDM1IjoiZG93bnMiLCI3MDYyIjoiKjEwOTQsKjExMDQifQ
//            $array_downs = array('Abelsonite','Adanite','Aegirine','Aeschynite-(Ce)','Agardite-(Y)','Ahrensite','Akaganeite','Akimotoite','Albite','Alloclasite','Allophane','Alterite','Alunite','Analcime','Andradite','Anglesite','Anhydrite','Ankerite','Annite','Anorpiment','Anorthite','Anorthoroselite','Antarcticite','Antlerite','Aplowite','Aragonite','Aravaipaite','Argentopyrite','Armalcolite','Arsenolamprite','Arsenolite','Asbolane','Augite','Baddeleyite','Bartelkeite','Barylite','Baryte','Bassanite','Beidellite','Bernardevansite','Bertrandite','Beryllonite','Bieberite','Billingsleyite','Bischofite','Bjarebyite','Bornhardtite','Bouazzerite','Brackebuschite','Breyite','Bridgmanite','Brucite','Brushite','Burgessite','Cahnite','Calcioaravaipaite','Calcioferrite','Calcite','Carrollite','Cattierite','Celestine','Chabazite-Ca','Chabazite-K','Chabazite-Mg','Chabazite-Na','Chalcocite','Chalcopyrite','Chamosite','Chenmingite','Chlorapatite','Chromite','Claudetite','Clinobarylite','Clinochlore','Clinoptilolite-Ca','Clinoptilolite-K','Clinoptilolite-Na','Clinosafflorite','Cobaltarthurite','Cobaltaustinite','Cobaltite','Cobaltkieserite','Cobaltkoritnigite','Cobaltlotharmeyerite','Cobaltneustädtelite','Cobaltoblödite','Cobaltomenite','Cobaltpentlandite','Cobalttsumcorite','Cobaltzippeite','Cochromite','Coesite','Comblainite','Conichalcite','Copiapite','Corrensite','Costibite','Cristobalite','Cubanite','Deloryite','Despujolsite','Dimorphite','Diopside','Dolomite','Dondoellite','Durangite','Eakerite','Eddavidite','Edwindavisite','Enstatite','Epsomite','Erythrite','Esperite','Evanichite','Eveite','Fayalite','Feiite','Ferri-kaersutite','Ferrihydrite','Ferripyrophyllite','Ferrobobfergusonite','Ferrofettelite','Ferromerrillite','Ferroselite','Ferrosilite','Ferroskutterudite','Fettelite','Fizélyite','Flinkite','Fluorapatite','Fluorite','Fluorlamprophyllite','Forsterite','Franksousaite','Freboldite','Giniite','Glaucodot','Goethite','Goldmanite','Greenalite','Greigite','Guangyuanite','Gustavite','Gypsum','Hafnon','Halite','Hastingsite','Hazenite','Heazlewoodite','Hedenbergite','Hematite','Hemihedrite','Hemleyite','Hercynite','Heterogenite','Hexahydrite','Hisingerite','Hloušekite','Hydrohalite','Hydroxycalciomicrolite','Hydroxylapatite','Hydroxylbastnäsite-(Ce)','Ice','Ikaite','Ilmenite','Imogolite','Iranite','Isokite','Jadeite','Jaipurite','Jamborite','Jarosite','Jinshajiangite','Julienite','Junitoite','Kaersutite','Kainite','Kaolinite','Karpenkoite','Katayamalite','Katoite','Keplerite','Kieftite','Kieserite','Kolbeckite','Kolwezite','Kosmochlor','Kovdorskite','Kyanite','Laihunite','Langisite','Lanthanite-(Nd)','Laverovite','Lavinskyite','Lazaraskeite','Leverettite','Lianbinite','Liebenbergite','Liebermannite','Lingunite','Linnaeite','Lipuite','Lithiomarsturite','Lithiotantite','Liudongshengite','Liuite','Loomisite','Lotharmeyerite','Maghemite','Magnesioalterite','Magnesite','Magnetite','Majorite','Malhmoodite','Mangano-ferri-eckermannite','Marcasite','Markascherite','Mattagamite','Meieranite','Merrillite','Metakirchheimerite','Metarossite','Mikenewite','Millerite','Minnesotaite','Mirabilite','Modderite','Monazite-(Ce)','Montmorillonite','Moorhouseite','Murdochite','Murphyite','Muscovite','Natropalermoite','Nepheline','Neustädtelite','Nickel','Nickelskutterudite','Nioboaeschynite-(Ce)','Nontronite','Odinite','Oenite','Olivenite','Opal','Orpiment','Orthoclase','Ottensite','Oursinite','Pakhomovskyite','Palermoite','Paracostibite','Paradimorphite','Parisite-(La)','Pauloabibite','Penikisite','Pentlandite','Periclase','Pertoldite','Petermegawite','Petersite-(Ce)','Petewilliamsite','Phenakite','Phlogopite','Phosphophyllite','Pigeonite','Pirquitasite','Plancheite','Pradetite','Prehnite','Pseudobrookite','Pyrite','Pyrosmalite-(Fe)','Pyroxene','Pyroxferroite','Pyrrhotite','Quartz','Ramdohrite','Rappoldite','Rasmussenite','Raspite','Rasvumite','Raydemarkite','Raygrantite','Reedmergnerite','Retzian-(Ce)','Retzian-(La)','Retzian-(Nd)','Rhomboclase','Ringwoodite','Robertsite','Rongibbsite','Roselite','Rruffite','Ruizite','Rutile','Safflorite','Sanderite','Sanidine','Saponite','Schaurteite','Schizolite','Schneebergite','Scottyite','Segerstromite','Seifertite','Siderite','Siegenite','Sillimanite','Skutterudite','Smolyaninovite','Smythite','Spherocobaltite','Spinel','Spodumene','Stanevansite','Starkeyite','Stibioclaudetite','Stilpnomelane','Stishovite','Strengite','Strontioruizite','Struvite','Stöfflerite','Sulphur','Szenicsite','Szomolnokite','Talc','Taniajacoite','Terrywallaceite','Tetrawickmanite','Thénardite','Thérèsemagnanite','Tissintite','Tranquillityite','Tridymite','Trogtalite','Troilite','Tschaunerite','Tuite','Tvalchrelidzeite','Tychite','Tyrrellite','Uchucchacuaite','Ulvöspinel','Uvarovite','Vaesite','Variscite','Vermiculite','Virgilluethite','Vivianite','Vladimirite','Wadsleyite','Wairauite','Walstromite','Wangdaodeite','Weeksite','Whitlockite','Wilancookite','Wilkinsonite','Willyamite','Witherite','Wollastonite','Wupatkiite','Wüstite','Xenotime-(Y)','Xieite','Yangite','Zagamiite','Zhanghuifenite','Zircon','Åkermanite');
//            $params = array('dt_id' => 736, 7036 => 'pyroxene', '7062' => "*1094,*1104");  // should return 60 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCI3MDM2IjoicHlyb3hlbmUiLCI3MDYyIjoiKjEwOTQsKjExMDQifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCI3MDM2IjoicHlyb3hlbmUiLCI3MDYyIjoiKjEwOTQsKjExMDQifQ
//            $array_pyroxene = array('Actinolite','Aegirine','Aegirine-augite','Amphibole','Anorthite','Antigorite','Augite','Bustamite','Calcite','Carpholite','Chromomphacite','Clinoenstatite','Clinoferrosilite','Davisite','Diopside','Donpeacorite','Enstatite','Esseneite','Fayalite','Ferrosilite','Foggite','Garnet','Gehlenite','Grossmanite','Halagurite','Hedenbergite','Hornblende','Ilmenite','Iron','Jadeite','Jervisite','Johannsenite','Kaersutite','Kanoite','Kosmochlor','Kushiroite','Magnesio-arfvedsonite','Magnetite','Majorite','Namansilite','Natalyite','Nchwaningite','Omphacite','Pargasite','Petedunnite','Pigeonite','Pyroxene','Pyroxmangite','Rhodonite','Rhönite','Richterite','Ryabchikovite','Spodumene','Surinamite','Tissintite','Tourmaline','Tremolite','Winchite','Wollastonite','Yangite');
//
//            $array_downs_and_pyroxene = array_intersect($array_downs, $array_pyroxene);
//            print '<pre>'.count($array_downs_and_pyroxene)."\n".print_r($array_downs_and_pyroxene, true).'</pre>';
//            $params = array('dt_id' => 736, 7035 => 'downs', 7036 => 'pyroxene', '7062' => "*1094,*1104");  // will return 7 results
//            $params = array('dt_id' => 736, 7035 => 'downs', 7036 => 'pyroxene', '7062' => "*1094,*1104", 'set' => '1');  // should return 21 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCI3MDM1IjoiZG93bnMiLCI3MDM2IjoicHlyb3hlbmUiLCI3MDYyIjoiKjEwOTQsKjExMDQifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCI3MDM1IjoiZG93bnMiLCI3MDM2IjoicHlyb3hlbmUiLCI3MDYyIjoiKjEwOTQsKjExMDQifQ
//
//            $array_downs_or_pyroxene = array_unique( array_merge($array_downs, $array_pyroxene) );
//            sort($array_downs_or_pyroxene);
//            print '<pre>'.count($array_downs_or_pyroxene)."\n".print_r($array_downs_or_pyroxene, true).'</pre>';
//            $params = array('dt_id' => 736, 7035 => 'downs', 7036 => 'pyroxene', '7062' => "*1094,*1104", 'merge' => 'OR');  // should return 410 results
//            // NOTE: 410 is correct, can no longer do direct math on the sets because of the required transformations
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCJtZXJnZSI6Ik9SIiwiNzAzNSI6ImRvd25zIiwiNzAzNiI6InB5cm94ZW5lIiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCJtZXJnZSI6Ik9SIiwiNzAzNSI6ImRvd25zIiwiNzAzNiI6InB5cm94ZW5lIiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//
//            $params = array('dt_id' => 736, 7035 => '!downs', '7062' => "*1094,*1104", 'set' => '1');  // should return (6534-371)=6163 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCI3MDM1IjoiIWRvd25zIiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCI3MDM1IjoiIWRvd25zIiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//            $array_not_downs = array("Abellaite","Abelloemringerite","Abenakiite-(Ce)","Abernathyite","Abhurite","Abramovite","Abswurmbachite","Abuite","Acanthite","Acetamide","Acetaminophen","Achalaite","Achyrophanite","Achávalite","Acmonidesite","Actinolite","Acuminite","Adachiite","Adamite","Adamsite-(Y)","Addibischoffite","Adelite","Admontite","Adolfpateraite","Adranosite","Adranosite-(Fe)","Adrianite","Aegirine-augite","Aenigmatite","Aerinite","Aerugite","Aeschynite-(Nd)","Aeschynite-(Y)","Afghanite","Afmite","Afwillite","Agaite","Agakhanovite-(Y)","Agardite-(Ce)","Agardite-(La)","Agardite-(Nd)","Agmantinite","Agrellite","Agricolaite","Agrinierite","Aguilarite","Aheylite","Ahlfeldite","Aikinite","Aiolosite","Airdite","Ajoite","Akaogiite","Akasakaite-(Ce)","Akasakaite-(La)","Akatoreite","Akdalaite","Akhtenskite","Aklimaite","Akopovaite","Akrochordite","Aksaite","Aktashite","AlSO4(OH)","Alabandite","Alacránite","Alamosite","Alarsite","Albertiniite","Albrechtschraufite","Albrittonite","Alburnite","Alcantarillaite","Alcaparrosaite","Aldermanite","Aldomarinoite","Aldridgeite","Aleksandrovite","Aleksite","Aleutite","Alexearlite","Alexkhomyakovite","Alexkuznetsovite-(Ce)","Alexkuznetsovite-(La)","Alflarsenite","Alforsite","Alfredcasparite","Alfredopetrovite","Alfredstelznerite","Algodonite","Alicewilsonite-(YCe)","Alicewilsonite-(YLa)","Aliettite","Allabogdanite","Allactite","Allanite-(Ce)","Allanite-(La)","Allanite-(Nd)","Allanite-(Sm)","Allanite-(Y)","Allanpringite","Allantoin","Allargentum","Alleghanyite","Allendeite","Allochalcoselite","Alloriite","Alluaivite","Alluaudite","Almagreraite","Almandine","Almarudite","Almeidaite","Alnaperbøeite-(Ce)","Alpeite","Alpersite","Alpha-D lactose monohydrate","Alsakharovite-Zn","Alstonite","Altaite","Althausite","Althupite","Altisite","Alum-(K)","Alum-(Na)","Aluminite","Aluminium","Alumino-ferrobarroisite","Alumino-ferrohornblende","Alumino-ferrotschermakite","Alumino-ferrowinchite","Alumino-magnesiohornblende","Alumino-magnesiotaramite","Alumino-ottoliniite","Alumino-oxy-rossmanite","Aluminobarroisite","Aluminoceladonite","Aluminocerite-(CeCa)","Aluminocopiapite","Aluminocoquimbite","Aluminokatophorite","Aluminomagnesiohulsite","Aluminopyracmonite","Aluminosugilite","Aluminotaipingite-(CeCa)","Aluminotschermakite","Aluminowinchite","Alumoedtollite","Alumohydrocalcite","Alumoklyuchevskite","Alumolukrahnite","Alumotantite","Alumotungstite","Alumovesuvianite","Alumoåkermanite","Alunogen","Alvanite","Alvesite","Alwilkinsite-(Y)","Amableite-(Ce)","Amakinite","Amamoorite","Amarantite","Amarillite","Amaterasuite","Amber","Amblygonite","Amblygonite-series","Ambrinoite","Ameghinite","Amesite","Amgaite","Amicite","Aminoffite","Ammineite","Ammonioalunite","Ammonioborite","Ammoniojarosite","Ammoniolasalite","Ammonioleucite","Ammoniomagnesiovoltaite","Ammoniomathesiusite","Ammoniotinsleyite","Ammoniovoltaite","Ammoniozippeite","Ammonium hazenite","Amoraite","Amphibole","Amstallite","Amurselite","Anandite","Anapaite","Anastasenkoite","Anatacamite","Anatase","Anatolygurbanovite","Anatolyite","Ancylite-(Ce)","Ancylite-(La)","Andalusite","Andersonite","Andesine","Andreadiniite","Andreybulakhite","Andreyivanovite","Andrianovite","Andrieslombaardite","Andrémeyerite","Anduoite","Andychristyite","Andymcdonaldite","Andyrobertsite","Angarfite","Angastonite","Angelellite","Anhydrite-Mg-beta","Anhydrokainite","Anilite","Aniyunwiyaite","Ankangite","Ankinovichite","Annabergite","Anningite-(Ce)","Annivite","Annivite-(Zn)","Anorthoclase","Anorthominasragrite","Anorthoyttrialite-(Y)","Ansermetite","Anthoinite","Anthonyite","Anthophyllite","Anthraxolite","Antigorite","Antimonpearceite","Antimonselite","Antimony","Antipinite","Antipovite","Antitaenite","Antofagastaite","Anyuiite","Anzaite-(Ce)","Apachite","Apatite-series","Apexite","Aphthitalite","Apjohnite","Apophyllite-series","Apuanite","Aqualite","Aradite","Arakiite","Aramayoite","Arangasite","Arapovite","Aravaite","Arcanite","Archerite","Arctite","Arcubisite","Ardaite","Ardealite","Ardennite-(As)","Ardennite-(V)","Arfvedsonite","Argandite","Argentobaumhauerite","Argentodufrénoysite","Argentojarosite","Argentoliveingite","Argentopearceite","Argentopentlandite","Argentopolybasite","Argentotennantite-(Fe)","Argentotennantite-(Zn)","Argentotetrahedrite-(Cd)","Argentotetrahedrite-(Fe)","Argentotetrahedrite-(Hg)","Argentotetrahedrite-(Zn)","Argesite","Argutite","Argyrodite","Arhbarite","Ariegilatite","Arisite-(Ce)","Arisite-(La)","Aristarainite","Armangite","Armbrusterite","Armellinoite-(Ce)","Armenite","Armstrongite","Arnhemite","Arrheniusite-(Ce)","Arrojadite-(BaNa)","Arrojadite-(KFe)","Arrojadite-(KNa)","Arrojadite-(NaFe)","Arrojadite-(PbFe)","Arrojadite-(SrFe)","Arsenatrotitanite","Arsenbrackebuschite","Arsendescloizite","Arsenic","Arseniopleite","Arseniosiderite","Arsenmarcobaldiite","Arsenmedaite","Arsenobenauite","Arsenoclasite","Arsenocrandallite","Arsenoflorencite-(Ce)","Arsenoflorencite-(La)","Arsenoflorencite-(Nd)","Arsenogoldfieldite","Arsenogorceixite","Arsenogoyazite","Arsenohauchecornite","Arsenohopeite","Arsenopalladinite","Arsenopyrite","Arsenosabugalite","Arsenotučekite","Arsenovanmeersscheite","Arsenoveszelyite","Arsenowagnerite","Arsenowaylandite","Arsenoústalečite","Arsenpolybasite","Arsenquatrandorite","Arsentsumebite","Arsenudinaite","Arsenuranospathite","Arsenuranylite","Arsiccioite","Arsmirandite","Artemether","Artemisinin","Artesunate","Arthurite","Artinite","Artroeite","Artsmithite","Arupite","Arzakite","Arzamastsevite","Arzrunite","Asagiite","Asbecasite","Aschamalmite","Ashburtonite","Ashcroftine-(Y)","Ashoverite","Asimowite","Asisite","Aspedamite","Aspidolite","Aspirin","Asselbornite","Astrocyanite-(Ce)","Astrophyllite","Atacamite","Atelestite","Atelisite-(Y)","Atencioite","Atenolol","Athabascaite","Atheneite","Atlasovite","Atokite","Atorvastatin","Attakolite","Attikaite","Aubertite","Auerbakhite","Augelite","Auriacusite","Aurichalcite","Auricupride","Aurihydrargyrumite","Aurivilliusite","Auroantimonate","Auropearceite","Auropolybasite","Aurorite","Auroselenide","Aurostibite","Austinite","Autunite","Avdeevite","Avdoninite","Averievite","Avicennite","Avogadrite","Awaruite","Axelite","Axinite","Axinite-(Fe)","Axinite-(Mg)","Axinite-(Mn)","Axinite-series","Azoproite","Azurite","Babefphite","Babingtonite","Babkinite","Babunaite-(Nd)","Babánekite","Bacaferrite","Backite","Badakhshanite-(Y)","Badalovite","Badengzhuite","Bafertisite","Baghdadite","Bahariyaite","Bahianite","Baiamareite","Baileychlore","Bainbridgeite-(NdCe)","Bainbridgeite-(YCe)","Bairdite","Bakakinite","Bakerite","Bakhchisaraitsevite","Baksanite","Balangeroite","Balestraite","Balipholite","Balićžunićite","Balkanite","Balliranoite","Balyakinite","Bambollaite","Bamfordite","Banalsite","Bandylite","Bannermanite","Bannisterite","Baotite","Barahonaite-(Al)","Barahonaite-(Fe)","Bararite","Baratovite","Barberiite","Barbertonite","Barbosalite","Barentsite","Bariandite","Barikaite","Bario-olgite","Bario-orthojoaquinite","Barioferrite","Bariolakargiite","Bariomicrolite","Barioperovskite","Bariopharmacoalumite","Bariopharmacosiderite","Bariopyrochlore","Bariosincosite","Barium-zinc alumopharmocosiderite","Barićite","Barkovite","Barlowite","Barnesite","Barquillite","Barrerite","Barringerite","Barringtonite","Barroisite","Barronite","Barrotite","Barrydawsonite-(Y)","Barstowite","Bartonite","Barwoodite","Barysilite","Barytocalcite","Barytolamprophyllite","Bassetite","Bassoite","Bastnasite-series","Bastnäsite-(Ce)","Bastnäsite-(La)","Bastnäsite-(Nd)","Bastnäsite-(Y)","Batagayite","Batievaite-(Y)","Batiferrite","Batisite","Batisivite","Batoniite","Baumhauerite","Baumhauerite II","Baumoite","Baumstarkite","Bauranoite","Bavenite","Bavsiite","Bayanoboite-(Y)","Bayerite","Bayldonite","Bayleyite","Baylissite","Bazhenovite","Bazirite","Bazzite","Bearsite","Bearthite","Beaverite-(Cu)","Beaverite-(Zn)","Bechererite","Beckettite","Becquerelite","Bederite","Beershevaite","Behoite","Belakovskiite","Belendorffite","Belkovite","Bellbergite","Bellidoite","Bellingerite","Belloite","Belmonteite","Belogubite","Belomarinaite","Belousovite","Belovite-(Ce)","Belovite-(La)","Belyankinite","Bementite","Benauite","Benavidesite","Bendadaite","Benitoite","Benjaminite","Benleonardite","Bennesherite","Benstonite","Bentorite","Benyacarite","Beraunite","Berborite","Berdesinskiite","Berezanskite","Bergbauerite","Bergenite","Bergslagite","Berlinite","Bermanite","Bernalite","Bernardite","Bernarlottiite","Berndlehmannite","Berndtite","Berndtite-4H","Berryite","Berthierine","Berthierite","Bertossaite","Beryl","Beryllite","Beryllocordierite-Na","Berzelianite","Berzeliite","Beshtauite","Beta - iridisite","Betekhtinite","Betpakdalite-CaCa","Betpakdalite-CaMg","Betpakdalite-FeFe","Betpakdalite-NaCa","Betpakdalite-NaNa","Bettertonite","Betzite","Beudantite","Beusite","Beusite-(Ca)","Beyerite","Bezsmertnovite","Biachellaite","Biagioniite","Bianchiniite","Bianchite","Bicapite","Bicchulite","Bideauxite","Biehlite","Bigcreekite","Bijvoetite-(Y)","Bikitaite","Bilibinskite","Billietite","Billwiseite","Bimbowrieite","Bindheimite","Biphosphammite","Biraite-(Ce)","Biraite-(La)","Birchite","Biringuccite","Birnessite","Birunite","Bisbeeite","Bismite","Bismoclite","Bismuth","Bismuthinite","Bismutite","Bismutocolumbite","Bismutoferrite","Bismutohauchecornite","Bismutomicrolite","Bismutopyrochlore","Bismutostibiconite","Bismutotantalite","Bitikleite","Bityite","Bixbyite-(Fe)","Bixbyite-(Mn)","Blakeite","Blatonite","Blatterite","Bleasdaleite","Blixite","Blodite-series","Blossite","Bluebellite","Bluelizardite","Blueridgeite","Bluestreakite","Blödite","Bobcookite","Bobdownsite","Bobfergusonite","Bobfinchite","Bobierrite","Bobjonesite","Bobkingite","Bobmeyerite","Bobshannonite","Bobtraillite","Bodieite","Boevskite","Bogdanovite","Boggsite","Bohdanowiczite","Bohseite","Bohuslavite","Bojarite","Bokite","Boleite","Bolivarite","Bolotinaite","Boltwoodite","Bonaccordite","Bonaccorsiite","Bonacinaite","Bonattite","Bonazziite","Bonshtedtite","Boojumite","Boothite","Boracite","Boralsilite","Borax","Borcarite","Borisenkoite","Borishanskiite","Bornemanite","Bornite","Borocookeite","Borodaevite","Boromullite","Boromuscovite","Borovskite","Bortnikovite","Bortolanite","Borzęckiite","Boscardinite","Bosiite","Bosoite","Bostwickite","Botallackite","Botryogen","Bottinoite","Botuobinskite","Boulangerite","Bounahasite","Bournonite","Boussingaultite","Bouškaite","Bowieite","Bowlesite","Boyleite","Brabantite","Braccoite","Bracewellite","Bradaczekite","Bradleyite","Braggite","Braithwaiteite","Braitschite-(Ce)","Branchite","Brandholzite","Brandtite","Brandãoite","Brannerite","Brannockite","Brass","Brassite","Brattforsite","Braunerite","Braunite","Brazilianite","Brearleyite","Bredigite","Breithauptite","Brendelite","Brenkite","Brewsterite-Ba","Brewsterite-Sr","Brezinaite","Brianite","Brianroulstonite","Brianyoungite","Briartite","Bridgesite-(Ce)","Brindleyite","Brinrobertsite","Britholite-(Ce)","Britholite-(Y)","Britvinite","Brizziite","Brochantite","Brockite","Brodtkorbite","Brokenhillite","Bromargyrite","Bromellite","Brontesite","Brookite","Browneite","Brownleeite","Brownmillerite","Brugnatellite","Brumadoite","Brunogeierite","Brunovskyite","Brusnitsynite","Brüggenite","Bubnovaite","Buchwaldite","Buckhornite","Buddingtonite","Bukovite","Bukovskýite","Bulachite","Bulgakite","Bultfonteinite","Bunnoite","Bunsenite","Burangaite","Burbankite","Burckhardtite","Burkeite","Burnettite","Burnsite","Burovaite-Ca","Burpalite","Burroite","Burtite","Buryatite","Buseckite","Buserite","Bushmakinite","Bussenite","Bussyite-(Ce)","Bussyite-(Y)","Bustamite","Bustamite-series","Butianite","Butlerite","Buttgenbachite","Buynite","Byelorussite-(Ce)","Bykovaite","Byrudite","Bystrite","Byströmite","Bytownite","Bytízite","Byzantievite","Béhierite","Bílinite","Böhmite","Bøggildite","Bøgvadite","Bütschliite","Běhounekite","CO3-SO4 - hydrotalcite - 18.5Å","CaLiBO3","Cabalzarite","Cabrerite","Cabriite","Cabvinite","Cacoxenite","Cadmium","Cadmoindite","Cadmoselite","Cadmoxite","Cadsulfohite","Cadvanite","Cadwaladerite","Caesiumpharmacosiderite","Cafarsite","Cafeosite","Cafetite","Caffeine","Caichengyunite","Cairncrossite","Calamaite","Calaverite","Calciborite","Calcinaksite","Calcio-olivine","Calcioancylite-(Ce)","Calcioancylite-(La)","Calcioancylite-(Nd)","Calcioandyrobertsite","Calciobetafite","Calcioburbankite","Calciocatapleiite","Calciocopiapite","Calciodelrioite","Calciohatertite","Calciohilairite","Calciojohillerite","Calciolangbeinite","Calciomurmanite","Calciopetersite","Calciopharmacoalumite","Calciosamarskite","Calciotantite","Calciouranoite","Calcioursilite","Calcioveatchite","Calcjarlite","Calclacite","Calcurmolite","Calcybeborosilite-(Y)","Calderite","Calderónite","Caledonite","Calkinsite-(Ce)","Callaghanite","Calomel","Calumetite","Calvertite","Calzirtite","Camanchacaite","Camaronesite","Cameronite","Camgasite","Caminite","Campigliaite","Campostriniite","Camérolaite","Canaphite","Canasite","Canavesite","Cancrinite","Cancrisilite","Canfieldite","Cannilloite","Cannizzarite","Cannonite","Canosioite","Canutite","Caoxite","Capgaronnite","Cappelenite-(Y)","Capranicaite","Caracolite","Carboborite","Carbobystrite","Carbocalumite","Carbocernaite","Carboferriphoxite","Carboirite","Carbokentbrooksite","Carbonate-fluorapatite","Carbonate-hydroxylapatite","Carbonatecyanotrichite","Cardite","Carducciite","Caresite","Carletonite","Carletonmooreite","Carlfrancisite","Carlfriesite","Carlgieseckeite-(Nd)","Carlhintzeite","Carlinite","Carlosbarbosaite","Carlosruizite","Carlosturanite","Carlsbergite","Carlsonite","Carmeltazite","Carmichaelite","Carminite","Carnallite","Carnotite","Carobbiite","Carpathite","Carpholite","Carraraite","Carrboydite","Caryinite","Caryochroite","Caryopilite","Cascandite","Caseyite","Cassagnaite","Cassedanneite","Cassidyite","Cassiterite","Castellaroite","Caswellsilverite","Catalanoite","Catamarcaite","Catapleiite","Cattiite","Cavansite","Cavoite","Cayalsite-(Y)","Caysichite-(Y)","Cebaite-(Ce)","Cebaite-(Nd)","Cebollite","Celadonite","Celleriite","Celsian","Centennialite","Cerchiaraite-(Al)","Cerchiaraite-(Fe)","Cerchiaraite-(Mn)","Cerianite-(Ce)","Ceriopyrochlore-(Ce)","Cerite-(CeCa)","Cerium","Cerromojonite","Ceruleite","Cerussite","Cervandonite-(Ce)","Cervantite","Cervelleite","Cesanite","Cesbronite","Cesiodymite","Cesiokenopyrochlore","Cesplumtantite","Cesàrolite","Cetineite","Chabazite-Sr","Chabournéite","Chadwickite","Chaidamuite","Chalcanthite","Chalcoalumite","Chalcocyanite","Chalcomenite","Chalconatronite","Chalcophanite","Chalcophyllite","Chalcosiderite","Chalcostibite","Chalcothallite","Challacolloite","Chambersite","Chaméanite","Chanabayaite","Changbaiite","Changchengite","Changesite-(Y)","Changoite","Chantalite","Chaoite","Chapmanite","Charleshatchettite","Charlesite","Charmarite","Charmarite-3T","Charoite","Chatkalite","Chayesite","Chegemite","Chekhovichite","Chelkarite","Chenevixite","Chengdeite","Chenguodaite","Chenite","Chenowethite","Chenxianite","Chenzhangruite","Cheralite","Cheremnykhite","Cherepanovite","Chernikovite","Chernovite-(Y)","Chernykhite","Cherokeeite","Chervetite","Chesnokovite","Chessexite","Chesterite","Chestermanite","Chevkinite-(Ce)","Chiappinoite-(Y)","Chiavennite","Chibaite","Chihmingite","Chihuahuaite","Childrenite","Chiluite","Chinchorroite","Chinleite-(Ce)","Chinleite-(Nd)","Chinleite-(Y)","Chinnerite","Chiolite","Chirvinskyite","Chistyakovaite","Chivruaiite","Chiyokoite","Chkalovite","Chladniite","Chloraluminite","Chlorargyrite","Chlorartinite","Chlorbartonite","Chlorellestadite","Chloritoid","Chlorkyuygenite","Chlormagaluminite","Chlormanganokalite","Chlormayenite","Chloro-potassic-ferro-edenite","Chlorocalcite","Chloromagnesite","Chloromenite","Chlorophoenicite","Chloroquine diphosphate","Chlorothionite","Chloroxiphite","Choloalite","Chondrodite","Chongite","Chopinite","Chorążewiczite","Chovanite","Chrisstanleyite","Christelite","Christite","Christofschäferite-(Ce)","Chromatite","Chrombismite","Chromceladonite","Chromferide","Chromio-pargasite","Chromium","Chromium-dravite","Chromo-alumino-povondraite","Chromomphacite","Chromphyllite","Chromschieffelinite","Chromviskontite","Chrysoberyl","Chrysocolla","Chrysothallite","Chrysotile","Chubarovite","Chudobaite","Chukanovite","Chukhrovite-(Ca)","Chukhrovite-(Ce)","Chukhrovite-(Nd)","Chukhrovite-(Y)","Chukochenite","Chukotkaite","Churchite-(Nd)","Churchite-(Y)","Chursinite","Chvaleticeite","Chvilevaite","Cianciulliite","Cinnabar","Ciprianiite","Ciriottiite","Cirrolite","Clairite","Claraite","Claringbullite","Clarkeite","Clausthalite","Clearcreekite","Clerite","Cleusonite","Cliffordite","Clino-ferri-holmquistite","Clino-ferro-ferri-holmquistite","Clino-ferro-suenoite","Clino-oscarkempffite","Clino-suenoite","Clinoatacamite","Clinobehoite","Clinobisvanite","Clinocervantite","Clinochalcomenite","Clinoclase","Clinoenstatite","Clinofergusonite-(Ce)","Clinofergusonite-(Nd)","Clinofergusonite-(Y)","Clinoferroholmquistite","Clinoferrosilite","Clinohedrite","Clinohumite","Clinojimthompsonite","Clinokurchatovite","Clinometaborite","Clinophosinaite","Clinosulphur","Clinotobermorite","Clinotyrolite","Clinoungemachite","Clinozoisite","Clintonite","Clogauite","Cloncurryite","Cloudite","Coalingite","Cobaltogordaite","Coccinite","Coconinoite","Coffinite","Cohenite","Coiraite","Colchesterite","Colchicine","Coldwellite","Colemanite","Colimaite","Colinowensite","Collinsite","Colomeraite","Coloradoite","Colquiriite","Columbite-(Fe)","Columbite-(Mg)","Columbite-(Mn)","Colusite","Comancheite","Combeite","Compreignacite","Congolite","Connellite","Cookeite","Coombsite","Cooperite","Coparsite","Copper","Copper(II) tetraammine nitrate","Coquandite","Coquimbite","Coralloite","Corderoite","Cordierite","Cordylite-(Ce)","Cordylite-(La)","Corkite","Cornetite","Cornubite","Cornwallite","Coronadite","Correianevesite","Cortesognoite","Corundum","Corvusite","Cosalite","Coskrenite-(Ce)","Cossaite","Cotunnite","Coulsonite","Cousinite","Coutinhoite","Covellite","Cowlesite","Coyoteite","Crandallite","Cranswickite","Crawfordite","Creaseyite","Crednerite","Creedite","Crerarite","Crichtonite","Criddleite","Crimsonite","Crocobelonite","Crocoite","Cronstedtite","Cronusite","Crookesite","Crowningshieldite","Cryobostryxite","Cryolite","Cryolithionite","Cryptochalcite","Cryptohalite","Cryptomelane","Cryptophyllite","CuZnCl(OH)_3_","Cualstibite","Cuatrocapaite-(K)","Cuatrocapaite-(NH_4_)","Cubic zirconia","Cubo-ice","Cuboargyrite","Cubothioplumbite","Cumengeite","Cummingtonite","Cupalite","Cuprite","Cuproauride","Cuprobismutite","Cuprocherokeeite","Cuprocopiapite","Cuprodobrovolskyite","Cuprodongchuanite","Cuproiridsite","Cuprokalininite","Cupromakopavonite","Cupromakovickyite","Cupromolybdite","Cuproneyite","Cupropavonite","Cupropearceite","Cupropolybasite","Cuprorhodsite","Cuprorivaite","Cuprosenandorite","Cuprosklodowskite","Cuprospinel","Cuprostibite","Cuprotungstite","Cuprozheshengite","Curetonite","Curienite","Curite","Currierite","Cuspidine","Cuyaite","Cuzticite","Cyanochroite","Cyanophyllite","Cyanotrichite","Cylindrite","Cymrite","Cyprine","Cyrilovite","Czochralskiite","Cámaraite","Césarferreiraite","D'ansite","D'ansite-(Fe)","D'ansite-(Mn)","Dachiardite-Ca","Dachiardite-K","Dachiardite-Na","Dacostaite","Dadsonite","Dagenaisite","Daliranite","Dalnegorskite","Dalnegroite","Dalyite","Damaraite","Damiaoite","Danalite","Danbaite","Danburite","Danielsite","Dantopaite","Daomanite","Daqingshanite-(Ce)","Darapiosite","Darapskite","Dargaite","Darrellhenryite","Dashkovaite","Datolite","Daubréeite","Daubréelite","Davanite","Davemaoite","Davidbrownite-(NH_4_)","Davidite-(Ce)","Davidite-(La)","Davidite-(Y)","Davidlloydite","Davidsmithite","Davinciite","Davisite","Davreuxite","Davyne","Dawsonite","Deanesmithite","Debattistiite","Decagonite","Decrespignyite-(Y)","Deerite","Defernite","Dekatriasartorite","Delafossite","Delchiaroite","Delhayelite","Delhuyarite-(Ce)","Deliensite","Delindeite","Dellagiustaite","Dellaite","Deloneite","Delrioite","Deltalumite","Deltanitrogen","Delvauxite","Demagistrisite","Demartinite","Demesmaekerite","Demicheleite-(Br)","Demicheleite-(Cl)","Demicheleite-(I)","Dendoraite-(NH_4_)","Denisovite","Denningite","Depmeierite","Derbylite","Derriksite","Dervillite","Desautelsite","Descloizite","Dessauite-(Y)","Destinezite","Deveroite-(Ce)","Devilliersite","Devilline","Devitoite","Deweylite","Dewindtite","Dewitite","Deynekoite","Diaboleite","Diadochite","Diamond","Diaoyudaoite","Diaphorite","Diaspore","Dickinsonite-(KMnNa)","Dickite","Dickthomssenite","Diegogattaite","Dienerite","Dietrichite","Dietzeite","Digenite","Dingdaohengite-(Ce)","Dinilawiite","Dinite","Diomignite","Dioptase","Dioskouriite","Direnzoite","Dissakisite-(Ce)","Dissakisite-(La)","Disulfodadsonite","Dittmarite","Diversilite-(Ce)","Dixenite","Djerfisherite","Djurleite","Dmisokolovite","Dmisteinbergite","Dmitryivanovite","Dmitryvarlamovite","Dobrovolskyite","Dobšináite","Dokuchaevite","Dolerophanite","Dollaseite-(Ce)","Doloresite","Domerockite","Domeykite","Domitrovicite","Donbassite","Dongchuanite","Donharrisite","Donnayite-(Y)","Donowensite","Donpeacorite","Donwilhelmsite","Dorallcharite","Dorfmanite","Dorrite","Douglasite","Dovyrenite","Downeyite","Downsite","Doyleite","Dozyite","Dravertite","Dravite","Drechslerite","Dresserite","Dreyerite","Driekopite","Dritsite","Drobecite","Droninoite","Drugmanite","Drysdallite","Dualite","Dubińskaite","Dufrénite","Dufrénoysite","Duftite","Dugganite","Dukeite","Dulanggouite","Dumontite","Dumortierite","Dundasite","Duranusite","Dusmatovite","Dussertite","Dutkevichite-(Ce)","Dutrowite","Duttonite","Dwornikite","Dymkovite","Dypingite","Dyrnaesite-(La)","Dyscrasite","Dzhalindite","Dzharkenite","Dzhuluite","Dzierżanowskite","Désorite","Earlandite","Earlshannonite","Eastonite","Ebnerite","Ecandrewsite","Ecdemite","Eckerite","Eckermannite","Eckhardite","Eclarite","Edenharterite","Edenite","Edgarbaileyite","Edgarite","Edgrewite","Edingtonite","Edoylerite","Edscottite","Edtollite","Edwardsite","Effenbergerite","Efremovite","Eggletonite","Eglestonite","Ehrigite","Ehrleite","Eifelite","Eirikite","Eitelite","Ekanite","Ekaterinite","Ekatite","Ekebergite","Ekplexite","Elaliite","Elasmochloite","Elbaite","Elbrusite","Eldfellite","Eldragónite","Electrum","Eleomelanite","Eleonorite","Elgoresyite","Eliopoulosite","Eliseevite","Elkinstantonite","Ellenbergerite","Ellestadite-(Cl)","Ellinaite","Ellingsenite","Elliottite","Ellisite","Elpasolite","Elpidite","Elramlyite-(Ce)","Eltyubyuite","Elyite","Embreyite","Emeleusite","Emilite","Emmerichite","Emmonsite","Emplectite","Empressite","Enargite","Engelhauptite","Englishite","Enneasartorite","Enricofrancoite","Eosphorite","Ephesite","Epididymite","Epidote","Epidote-(Sr)","Epiebnerite","Epifanovite","Epistilbite","Epistolite","Erazoite","Ercitite","Erdite","Ericaite","Ericlaxmanite","Ericssonite","Erikapohlite","Erikjonssonite","Eringaite","Eriochalcite","Erionite-Ca","Erionite-K","Erionite-Na","Erlianite","Erlichmanite","Ermakovite","Ermeloite","Ernienickelite","Erniggliite","Ernstburkeite","Ernstite","Ershovite","Erssonite","Ertixiite","Ertlite","Erythrosiderite","Erzwiesite","Escheite","Esdanaite-(Ce)","Eskebornite","Eskimoite","Eskolaite","Espadaite","Esperanzaite","Esquireite","Esseneite","Eta - bronze","Ettringite","Eucairite","Euchlorine","Euchroite","Euclase","Eucryptite","Eudialyte","Eudidymite","Eugenite","Eugsterite","Eulytine","Eurekadumpite","Euxenite-(Y)","Evansite","Evdokimovite","Evenkite","Eveslogite","Evseevite","Ewaldite","Ewingite","Eylettersite","Eyselite","Ezcurrite","Ezochiite","Eztlite","Fabianite","Fabritzite","Fabrièsite","Faheyite","Fahleite","Fairbankite","Fairchildite","Fairfieldite","Faizievite","Falcondoite","Falgarite","Falkmanite","Falottaite","Falsterite","Famatinite","Fanfaniite","Fangite","Fanguangite","Fantappièite","Farneseite","Farringtonite","Fassinaite","Faujasite-Ca","Faujasite-Mg","Faujasite-Na","Faustite","Favreauite","Fe_2_SiO_4_spinel","Fedorite","Fedorovskite","Fedotovite","Fehrite","Feinglosite","Feitknechtite","Fejerite","Feklichevite","Felbertalite","Felsőbányaite","Fenaksite","Fencooperite","Fengchengite","Fengruiite","Feodosiyite","Ferberite","Ferchromide","Ferdisilicite","Ferdowsiite","Fergusonite-(Ce)","Fergusonite-(Nd)","Fergusonite-(Y)","Ferhodsite","Fermiite","Fernandinite","Feroxyhyte","Ferraioloite","Ferrarisite","Ferri-barroisite","Ferri-ferrobarroisite","Ferri-ferrotschermakite","Ferri-ferrowinchite","Ferri-fluoro-katophorite","Ferri-fluoro-leakeite","Ferri-ghoseite","Ferri-hellandite-(Ce)","Ferri-katophorite","Ferri-leakeite","Ferri-magnesiokatophorite","Ferri-magnesiotaramite","Ferri-mottanaite-(Ce)","Ferri-obertiite","Ferri-ottoliniite","Ferri-pedrizite","Ferri-taramite","Ferri-tschermakite","Ferri-winchite","Ferriakasakaite-(Ce)","Ferriakasakaite-(La)","Ferriallanite-(Ce)","Ferriallanite-(La)","Ferriandrosite-(Ce)","Ferriandrosite-(La)","Ferribushmakinite","Ferric-nybøite","Ferricerite-(LaCa)","Ferricopiapite","Ferricoronadite","Ferrierite-K","Ferrierite-Mg","Ferrierite-NH_4_","Ferrierite-Na","Ferrihollandite","Ferrilotharmeyerite","Ferrimolybdite","Ferrimuirite","Ferrinatrite","Ferriperbøeite-(Ce)","Ferriperbøeite-(La)","Ferriphoxite","Ferriprehnite","Ferrirockbridgeite","Ferrisanidine","Ferrisepiolite","Ferrisicklerite","Ferristrunzite","Ferrisurite","Ferrisymplesite","Ferritaramite","Ferritungstite","Ferrivauxite","Ferriwhittakerite","Ferro-actinolite","Ferro-anthophyllite","Ferro-bosiite","Ferro-eckermannite","Ferro-edenite","Ferro-ferri-fluoro-leakeite","Ferro-ferri-holmquistite","Ferro-ferri-hornblende","Ferro-ferri-katophorite","Ferro-ferri-nybøite","Ferro-ferri-obertiite","Ferro-ferri-pedrizite","Ferro-fluoro-edenite","Ferro-fluoro-pedrizite","Ferro-gedrite","Ferro-glaucophane","Ferro-holmquistite","Ferro-hornblende","Ferro-katophorite","Ferro-papikeite","Ferro-pargasite","Ferro-pedrizite","Ferro-richterite","Ferro-taramite","Ferro-tschermakite","Ferroalluaudite","Ferroaluminoceladonite","Ferrobarroisite","Ferroberaunite","Ferrobustamite","Ferrocarpholite","Ferroceladonite","Ferrochiavennite","Ferrodimolybdenite","Ferroefremovite","Ferroericssonite","Ferrohexahydrite","Ferrohögbomite-2N2S","Ferroindialite","Ferroinnelite","Ferrokaersutite","Ferrokentbrooksite","Ferrokinoshitalite","Ferrokësterite","Ferrolaueite","Ferroleakeite","Ferronickelplatinum","Ferronigerite-2N1S","Ferronigerite-6N6S","Ferronordite-(Ce)","Ferronordite-(La)","Ferronybøite","Ferropedrizite","Ferroqingheiite","Ferrorhodonite","Ferrorhodsite","Ferrorockbridgeite","Ferrorosemaryite","Ferrosaponite","Ferrostalderite","Ferrostrunzite","Ferrotaaffeite-2N'2S","Ferrotaaffeite-6N'3S","Ferrotellurite","Ferrotitanowodginite","Ferrotochilinite","Ferrotorryweiserite","Ferrotschermakite","Ferrotychite","Ferrovalleriite","Ferrovorontsovite","Ferrowinchite","Ferrowodginite","Ferrowyllieite","Ferroåkermanite","Ferruccite","Fersilicite","Fersmanite","Fersmite","Feruvite","Fervanite","Fetiasite","Feynmanite","Fianelite","Fibroferrite","Fichtelite","Fiedlerite","Fiemmeite","Filatovite","Filipstadite","Fillowite","Finchite","Finescreekite","Fingerite","Finnemanite","Fischesserite","Fivegite","Flaggite","Flagstaffite","Flamite","Fleetite","Fleischerite","Fleisstalite","Fletcherite","Flinteite","Florencite-(Ce)","Florencite-(La)","Florencite-(Nd)","Florencite-(Sm)","Florenskyite","Florensovite","Fluckite","Fluellite","Fluoborite","Fluocerite-(Ce)","Fluocerite-(La)","Fluor-arfvedsonite","Fluor-buergerite","Fluor-dravite","Fluor-elbaite","Fluor-liddicoatite","Fluor-rewitzerite","Fluor-rossmanite","Fluor-schorl","Fluor-tsilaisite","Fluor-uvite","Fluoralforsite","Fluorannite","Fluorapophyllite-(Cs)","Fluorapophyllite-(K)","Fluorapophyllite-(NH_4_)","Fluorapophyllite-(Na)","Fluorarrojadite-(BaFe)","Fluorarrojadite-(BaNa)","Fluorbarytolamprophyllite","Fluorbritholite-(Ce)","Fluorbritholite-(La)","Fluorbritholite-(Nd)","Fluorbritholite-(Y)","Fluorcalciobritholite","Fluorcalciomicrolite","Fluorcalciopyrochlore","Fluorcalcioroméite","Fluorcanasite","Fluorcaphite","Fluorcarletonite","Fluorcarmoite-(BaNa)","Fluorchegemite","Fluorellestadite","Fluorine","Fluorkyuygenite","Fluorluanshiweiite","Fluormacraeite","Fluormayenite","Fluornatrocoulsellite","Fluornatromicrolite","Fluornatropyrochlore","Fluoro-cannilloite","Fluoro-edenite","Fluoro-ferri-magnesiokatophorite","Fluoro-leakeite","Fluoro-magnesiokatophorite","Fluoro-nybøite","Fluoro-oxy-ferri-magnesiokatophorite","Fluoro-pargasite","Fluoro-pedrizite","Fluoro-richterite","Fluoro-riebeckite","Fluoro-taramite","Fluoro-tremolite","Fluorocronite","Fluorokinoshitalite","Fluorophlogopite","Fluorotaramite","Fluorotetraferriphlogopite","Fluorotremolite","Fluorowardite","Fluorphosphohedyphane","Fluorpyromorphite","Fluorsigaiite","Fluorstrophite","Fluorthalénite-(Y)","Fluorvesuvianite","Fluorwavellite","Flurlite","Flörkeite","Foggite","Fogoite-(Y)","Foitite","Folvikite","Fontanite","Fontarnauite","Foordite","Footemineite","Formanite-(Y)","Formicaite","Fornacite","Forêtite","Foshagite","Fougèrite","Fourmarierite","Fowlerite","Fraipontite","Francevillite","Franciscanite","Francisite","Franckeite","Francoanellite","Franconite","Frankamenite","Frankdicksonite","Frankhawthorneite","Franklinfurnaceite","Franklinite","Franklinphilite","Fransoletite","Franzinite","Françoisite-(Ce)","Françoisite-(Nd)","Fredrikssonite","Freedite","Freibergite","Freieslebenite","Freitalite","Fresnoite","Freudenbergite","Friedelite","Friedrichbeckeite","Friedrichite","Friisite","Fritzscheite","Frohbergite","Frolovite","Frondelite","Froodite","Fuchunite","Fuenzalidaite","Fuettererite","Fukalite","Fukuchilite","Fulbrightite","Fullerite","Fupingqiuite","Furongite","Furutobeite","Fuyuanite","Fülöppite","Gabrielite","Gabrielsonite","Gachingite","Gadolinite-(Ce)","Gadolinite-(Nd)","Gadolinite-(Y)","Gadolinium gallium garnet","Gagarinite-(Ce)","Gagarinite-(Y)","Gageite","Gahnite","Gaidonnayite","Gaildunningite","Gainesite","Gaitite","Gajardoite","Gajardoite-(NH_4_)","Galaxite","Galeaclolusite","Galeite","Galena","Galenobismutite","Galgenbergite-(Ce)","Galileiite","Galkhaite","Galliskiite","Gallite","Gallobeudantite","Galloplumbogummite","Galuskinite","Gamagarite","Gananite","Ganomalite","Ganophyllite","Ganterite","Gaotaiite","Garavellite","Garmite","Garnet","Garpenbergite","Garrelsite","Garronite-Ca","Garronite-Na","Gartrellite","Garutiite","Garyansellite","Gasparite-(Ce)","Gasparite-(La)","Gaspéite","Gatedalite","Gatehouseite","Gatelite-(Ce)","Gatewayite","Gatumbaite","Gaudefroyite","Gaultite","Gauthierite","Gayite","Gaylussite","Gazeevite","Gearksutite","Gebhardite","Gedrite","Geerite","Geffroyite","Gehlenite","Geigerite","Geikielite","Gelosaite","Geminite","Gengenbachite","Genkinite","Genplesite","Genthelvite","Geocronite","Georgbarsanovite","Georgbokiite","George-ericksenite","Georgechaoite","Georgeite","Georgeliuite","Georgerobinsonite","Georgiadesite","Gerasimovskite","Gerdtremmelite","Gerenite-(Y)","Gerhardtite","Germanite","Germanocolusite","Gersdorffite","Gerstleyite","Gerstmannite","Geschieberite","Getchellite","Geuerite","Geversite","Ghiaraite","Giacovazzoite","Gianellaite","Gibbsite","Giessenite","Giftgrubeite","Gilalite","Gillardite","Gillespite","Gillulyite","Gilmarite","Ginelfite","Ginorite","Giorgiosite","Giraudite-(Zn)","Girdite","Girvasite","Gismondine-Ca","Gismondine-Sr","Gittinsite","Giuseppettite","Giuşcăite","Gjerdingenite-Ca","Gjerdingenite-Fe","Gjerdingenite-Mn","Gjerdingenite-Na","Gladite","Gladiusite","Gladkovskyite","Glagolevite","Glass","Glass-(Ce)","Glass-(Dy)","Glass-(Er)","Glass-(Eu)","Glass-(Gd)","Glass-(Ho)","Glass-(La)","Glass-(Lu)","Glass-(Nd)","Glass-(Pr)","Glass-(Sm)","Glass-(Tb)","Glass-(Tm)","Glass-(Y)","Glass-(Yb)","Glauberite","Glaucocerinite","Glaucochroite","Glauconite","Glaucophane","Glaukosphaerite","Glecklerite","Glikinite","Glucine","Glushinskite","Gmalimite","Gmelinite-Ca","Gmelinite-K","Gmelinite-Na","Gobbinsite","Gobelinite","Godlevskite","Godovikovite","Goedkenite","Gold","Goldamalgam","Goldfieldite","Goldhillite","Goldichite","Goldquarryite","Goldschmidtite","Golyshevite","Gonnardite","Gonyerite","Goosecreekite","Gorbunovite","Gorceixite","Gordaite","Gordonite","Gorerite","Gormanite","Gortdrumite","Goryainovite","Goslarite","Gottardiite","Gottlobite","Goudeyite","Gowerite","Goyazite","Graemite","Graeserite","Graftonite","Graftonite-(Ca)","Graftonite-(Mn)","Grahampearsonite","Gramaccioliite-(Y)","Grammatikopoulosite","Grandaite","Grandidierite","Grandreefite","Grandviewite","Grantsite","Graphite","Gratonite","Grattarolaite","Graulichite-(Ce)","Graulichite-(La)","Gravegliaite","Grayite","Graţianite","Grechishchevite","Greenlizardite","Greenockite","Greenwoodite","Gregoryite","Greifensteinite","Grenmarite","Grguricite","Griceite","Griffinite","Grigorievite","Grimaldiite","Grimmite","Grimselite","Griphite","Grischunite","Gritsenkoite","Groatite","Grokhovskyite","Grootfonteinite","Grossite","Grossmanite","Grossular","Groutite","Grumantite","Grumiplucite","Grundmannite","Grunerite","Gruzdevite","Guanacoite","Guanajuatite","Guanine","Guarinoite","Guastoniite-(Y)","Gudmundite","Guettardite","Gugiaite","Guidottiite","Guildite","Guilleminite","Guimarãesite","Guite","Guixiangite","Gungerite","Gunmaite","Gunningite","Gunterite","Gupeiite","Gurimite","Gurzhiite","Gutkovaite-Mn","Guyanaite","Guérinite","Gwihabaite","Gyrolite","Gysinite-(Ce)","Gysinite-(La)","Gysinite-(Nd)","Görgeyite","Götzenite","Günterblassite","Haapalaite","Hagendorfite","Haggertyite","Hagstromite","Haidingerite","Haigerachite","Haineaultite","Hainite-(Y)","Haitaite-(La)","Haiweeite","Hakite-(Cd)","Hakite-(Fe)","Hakite-(Hg)","Hakite-(Zn)","Halagurite","Halamishite","Halilsarpite","Hallimondite","Halloysite","Halotrichite","Halurgite","Hambergite","Hammarite","Hanahanite","Hanauerite","Hanawaltite","Hancockite","Hanjiangite","Hanksite","Hannayite","Hannebachite","Hansblockite","Hansesmarkite","Hanswilkeite","Hapkeite","Haradaite","Hardystonite","Harkerite","Harmotome","Harmunite","Harrisonite","Harstigite","Hartkoppeite","Hasanovite","Hashemite","Hastite","Hatchite","Hatertite","Hatrurite","Hauchecornite","Hauckite","Hauerite","Hausmannite","Hawleyite","Hawthorneite","Haxonite","Haycockite","Haydeeite","Hayelasdiite","Haynesite","Haywoodite","Hayyanite","Haüyne","Heamanite-(Ce)","Hechtsbergite","Hectorfloresite","Hectorite","Hedegaardite","Hedleyite","Hedyphane","Heflikite","Heftetjernite","Heideite","Heidornite","Heimaeyite","Heimite","Heinrichite","Heisenbergite","Hejtmanite","Heklaite","Heliophyllite","Hellandite-(Ce)","Hellandite-(Y)","Hellyerite","Helmutwinklerite","Helvine","Hematolite","Hematophanite","Hemimorphite","Hemloite","Hemusite","Hendekasartorite","Hendersonite","Hendricksite","Heneuite","Henmilite","Hennomartinite","Henritermierite","Henryite","Henrymeyerite","Henrysunite","Hentschelite","Hephaistosite","Heptasartorite","Herbertsmithite","Herderite","Hereroite","Hermannjahnite","Hermannroseite","Herzenbergite","Hessite","Hetaerolite","Heteromorphite","Heterosite","Heulandite-Ba","Heulandite-Ca","Heulandite-K","Heulandite-Na","Heulandite-Sr","Hewettite","Hexacelsian","Hexaferrum","Hexahydroborite","Hexamolybdenum","Hexatestibiopanickelite","Hexathioplumbite","Heyerdahlite","Heyite","Heyrovskýite","Hezuolinite","Hibbingite","Hibonite","Hibschite","Hidalgoite","Hielscherite","Hieratite","Hilairite","Hilarionite","Hilgardite","Hilgardite-3A","Hilgardite-4M","Hillebrandite","Hillesheimite","Hillite","Hingganite","Hingganite-(Ce)","Hingganite-(Nd)","Hingganite-(Y)","Hingganite-(Yb)","Hinokageite","Hinsdalite","Hiortdahlite","Hiroseite","Hitachiite","Hizenite-(Y)","Hiärneite","Hjalmarite","Hocartite","Hochelagaite","Hochleitnerite","Hodgesmithite","Hodgkinsonite","Hodrušite","Hoelite","Hoganite","Hogarthite","Hohmannite","Hokkaidoite","Holdawayite","Holdenite","Holfertite","Hollandite","Hollingworthite","Hollisterite","Holmquistite","Holtedahlite","Holtite","Holtstamite","Holubite","Homilite","Honeaite","Honessite","Hongheite","Hongshiite","Honzaite","Hopeite","Hoperanchite","Hopmannite","Horiite","Hornblende","Horomanite","Horváthite-(Y)","Horákite","Hotsonite","Housleyite","Howardevansite","Howieite","Howlite","Hrabákite","Hsianghualite","Huanghoite-(Ce)","Huanghoite-(Nd)","Huangite","Huangshanite","Huanzalaite","Hubbardite","Hubeite","Huemulite","Huenite","Hughesite","Huizingite-(Al)","Hulsite","Humberstonite","Humboldtine","Humite","Hummerite","Hunchunite","Hundholmenite-(Y)","Hungchaoite","Huntingdonite","Huntite","Hureaulite","Hurlbutite","Hutcheonite","Hutchinsonite","Huttonite","Hyalophane","Hyalotekite","Hyblerite","Hydroandradite","Hydroastrophyllite","Hydrobasaluminite","Hydrobiotite","Hydroboracite","Hydrocalumite","Hydrocerussite","Hydrochlorborite","Hydrodelhayelite","Hydrodresserite","Hydroglauberite","Hydrohalloysite","Hydrohetaerolite","Hydrohonessite","Hydrokenoelsmoreite","Hydrokenomicrolite","Hydrokenopyrochlore","Hydrokenoralstonite","Hydromagnesite","Hydrombobomkulite","Hydroniumjarosite","Hydroniumpharmacoalumite","Hydroniumpharmacosiderite","Hydronováčekite","Hydropascoite","Hydroplumboelsmoreite","Hydropyrochlore","Hydroredmondite","Hydroromarchite","Hydroroméite","Hydroscarbroite","Hydrotalcite","Hydroterskite","Hydrotungstite","Hydrowoodwardite","Hydroxyapophyllite-(K)","Hydroxycalciopyrochlore","Hydroxycalcioroméite","Hydroxycancrinite","Hydroxyferroroméite","Hydroxykenoelsmoreite","Hydroxykenomicrolite","Hydroxykenopyrochlore","Hydroxylapatite-M","Hydroxylbastnäsite-(La)","Hydroxylbastnäsite-(Nd)","Hydroxylbenyacarite","Hydroxylborite","Hydroxylchondrodite","Hydroxylclinohumite","Hydroxyledgrewite","Hydroxylellestadite","Hydroxylgugiaite","Hydroxylhedyphane","Hydroxylherderite","Hydroxylmattheddleite","Hydroxylphosphohedyphane","Hydroxylpyromorphite","Hydroxylwagnerite","Hydroxymanganopyrochlore","Hydroxymcglassonite-(K)","Hydroxynatropyrochlore","Hydroxyplumbopyrochlore","Hydrozincite","Hylbrownite","Hypercinnabar","Hyršlite","Hyttsjöite","Häggite","Håleniusite-(Ce)","Håleniusite-(La)","Hörnesite","Höslite","Høgtuvaite","Hübnerite","Hügelite","IMA2009-079","Ianbruceite","Iangreyite","Ianthinite","Ichnusaite","Icosahedrite","Idaite","Idrialite","Igelströmite","Iimoriite-(Y)","Ikorskyite","Ikranite","Ikunolite","Ilesite","Ilinskite","Ilirneyite","Illite","Illoqite-(Ce)","Ilmajokite-(Ce)","Ilsemannite","Iltisite","Ilvaite","Ilyukhinite","Ilímaussite-(Ce)","Imandrite","Imayoshiite","Imhofite","Imiterite","Inaglyite","Incaite","Incomsartorite","Inderborite","Inderite","Indialite","Indigirite","Indite","Indium","Inesite","Ingersonite","Ingodite","Innelite","Innsbruckite","Insizwaite","Interliveingite","Intersilite","Inyoite","Iodargyrite","Iodine","Iowaite","Iquiqueite","Iraqite-(La)","Irarsite","Irhtemite","Iridarsenite","Iridium","Iriginite","Irinarassite","Iron","Irtyshite","Iseite","Ishiharaite","Ishikawaite","Iskandarovite","Isoclasite","Isocubanite","Isoferroplatinum","Isolueshite","Isomertieite","Isovite","Isselite","Itelmenite","Itoigawaite","Itoite","Itsiite","Ivanyukite-Cu","Ivanyukite-K","Ivanyukite-Na","Ivanyukite-Na-T","Ivsite","Iwakiite","Iwashiroite-(Y)","Iwateite","Ixiolite-(Fe^2+^)","Ixiolite-(Mn^2+^)","Ixiolite-(Sc)","Iyoite","Izoklakeite","Jacobsite","Jacquesdietrichite","Jacutingaite","Jadarite","Jaffeite","Jagoite","Jagowerite","Jagüéite","Jahnsite-(CaFeFe)","Jahnsite-(CaFeMg)","Jahnsite-(CaMnFe)","Jahnsite-(CaMnMg)","Jahnsite-(CaMnMn)","Jahnsite-(CaMnZn)","Jahnsite-(MnMnFe)","Jahnsite-(MnMnMg)","Jahnsite-(MnMnMn)","Jahnsite-(MnMnZn)","Jahnsite-(NaFeMg)","Jahnsite-(NaMnMg)","Jahnsite-(NaMnMn)","Jakobssonite","Jalpaite","Jamesite","Jamesonite","Janchevite","Janggunite","Janhaugite","Jankovićite","Jarandolite","Jarlite","Jarosewichite","Jaskólskiite","Jasmundite","Jasonsmithite","Jasrouxite","Jaszczakite","Javorieite","Jeanbandyite","Jeankempite","Jedwabite","Jeffbenite","Jeffreyite","Jennite","Jensenite","Jentschite","Jeppeite","Jeremejevite","Jerrygibbsite","Jervisite","Ježekite","Jianmuite","Jianshuiite","Jimboite","Jimkrieghite","Jimthompsonite","Jingsuiite","Jingwenite-(Y)","Jinxiuite","Joanneumite","Joaquinite-(Ce)","Joegoldsteinite","Joesmithite","Johachidolite","Johanngeorgenstadtite","Johannite","Johannsenite","Johillerite","Johnbaumite","Johnbaumite-M","Johninnesite","Johnjamborite","Johnkoivulaite-(Cs)","Johnsenite-(Ce)","Johnsomervilleite","Johntomaite","Johnwalkite","Joliotite","Jolliffeite","Jonassonite","Jonesite","Jonlarsenite","Joosteite","Jordanite","Jordisite","Joséite-A","Joséite-B","Joséite-C","Joteite","Jouravskite","Joëlbruggerite","Juabite","Juangodoyite","Juanitaite","Juanite","Juansilvaite","Julgoldite-(Fe^2+^)","Julgoldite-(Fe^3+^)","Julgoldite-(Mg)","Jungite","Junoite","Juonniite","Jurbanite","Jusite","Juxingite","Jáchymovite","Jôkokuite","Jörgkellerite","Jørgensenite","K_3_Fe(CN)_5_NO","Kaatialaite","Kabalovite","Kadyrelite","Kafehydrocyanite","Kahlenbergite","Kahlerite","Kainosite-(Y)","Kainotropite","Kaitianite","Kalborsite","Kalgoorlieite","Kaliborite","Kalicinite","Kalifersite","Kalininite","Kalinite","Kaliochalcite","Kaliophilite","Kalistrontite","Kalithallite","Kalsilite","Kaluginite","Kalungaite","Kalyuzhnyite-(Ce)","Kamaishilite","Kamarizaite","Kambaldaite","Kamchatkite","Kamenevite","Kamiokite","Kamitugaite","Kamotoite-(Y)","Kampelite","Kampfite","Kamphaugite-(Y)","Kanatzidisite","Kanemite","Kangite","Kangjinlaite","Kannanite","Kanoite","Kanonaite","Kanonerovite","Kantorite","Kapellasite","Kapitsaite-(Y)","Kapundaite","Kapustinite","Karasugite","Karchevskyite","Karelianite","Karenwebberite","Karibibite","Karlditmarite","Karlite","Karlleuite","Karlseifertite","Karnasurtite-(Ce)","Karpinskite","Karpovite","Karupmøllerite-Ca","Karwowskiite","Kasatkinite","Kashinite","Kaskasite","Kasolite","Kassite","Kastningite","Katanite","Katerinopoulosite","Katiarsite","Katophorite","Katoptrite","Katsarosite","Kawazulite","Kayrobertsonite","Kayupovaite","Kazakhstanite","Kazakovite","Kazanskyite","Kaznakhtite","Kaňkite","Keckite","Kegelite","Kegginite","Keilite","Keithconnite","Keiviite-(Y)","Keiviite-(Yb)","Keldyshite","Kellyite","Kelyanite","Kemmlitzite","Kempite","Kenhsuite","Kenngottite","Kennygayite","Kenoargentotennantite-(Fe)","Kenoargentotetrahedrite-(Fe)","Kenoargentotetrahedrite-(Zn)","Kenomicrolite","Kenoplumbomicrolite","Kenorozhdestvenskayaite-(Fe)","Kenotobermorite","Kentbrooksite","Kentrolite","Kenyaite","Kerimasite","Kermesite","Kernite","Kernowite","Kesebolite-(Ce)","Kettnerite","Keutschite","Keyite","Keystoneite","Khademite","Khaidarkanite","Khamrabaevite","Khanneshite","Kharaelakhite","Khatyrkite","Khesinite","Khibinskite","Khinite","Khmaralite","Khomyakovite","Khorixasite","Khrenovite","Khristovite-(Ce)","Khurayyimite","Khvorovite","Kiddcreekite","Kidodite","Kidwellite","Kihlmanite-(Ce)","Kilchoanite","Killalaite","Kimrobinsonite","Kimuraite-(Y)","Kimzeyite","Kingite","Kingsgateite","Kingsmountite","Kingstonite","Kinichilite","Kinoite","Kinoshitalite","Kintoreite","Kipushite","Kircherite","Kirchhoffite","Kirkiite","Kirschsteinite","Kiryuite","Kishonite","Kitagohaite","Kitkaite","Kittatinnyite","Kladnoite","Klajite","Klaprothite","Klebelsbergite","Kleberite","Kleemanite","Kleinite","Klockmannite","Klyuchevskite","Klöchite","Knasibfite","Knorringite","Koashvite","Kobeite-(Y)","Kobellite","Kobokoboite","Kobyashevite","Kochite","Kochkarite","Kochsándorite","Kodamaite","Koechlinite","Koenenite","Kogarkoite","Kojonenite","Kokchetavite","Kokinosite","Koksharovite","Koktaite","Kolarite","Kolfanite","Kolicite","Kolitschite","Kollerite","Kolovratite","Kolskyite","Kolymite","Komarovite","Kombatite","Komkovite","Konderite","Koninckite","Kononovite","Konyaite","Konzettite","Kopernikite","Kopylovite","Koragoite","Koritnigite","Kornelite","Kornerupine","Korobitsynite","Korshunovskite","Koryakite","Korzhinskite","Kosnarite","Kostovite","Kostylevite","Kotoite","Kottenheimite","Kotulskite","Koutekite","Kozoite-(La)","Kozoite-(Nd)","Kozyrevskite","Kozłowskiite","Kraisslite","Krasheninnikovite","Krasnoshteinite","Krasnovite","Kratochvílite","Krausite","Krauskopfite","Krautite","Kravtsovite","Kreiterite","Kremersite","Krennerite","Krettnichite","Kribergite","Krieselite","Krinovite","Kristiansenite","Kristjánite","Krivovichevite","Krotite","Kroupaite","Kruijenite","Krupičkaite","Krupkaite","Krut'aite","Krutovite","Kryachkoite","Kryzaite","Kryzhanovskite","Králíkite","Krásnoite","Kröhnkite","Krügerite","Ktenasite","Kuannersuite-(Ce)","Kudriavite","Kudryavtsevaite","Kufahrite","Kukharenkoite-(Ce)","Kukharenkoite-(La)","Kukisvumite","Kuksite","Kulanite","Kuliginite","Kuliokite-(Y)","Kulkeite","Kullerudite","Kumdykolite","Kummerite","Kumtyubeite","Kunatite","Kupletskite","Kupletskite-(Cs)","Kupčíkite","Kuramite","Kuranakhite","Kuratite","Kurchatovite","Kurgantaite","Kurilite","Kurnakovite","Kurumsakite","Kusachiite","Kushiroite","Kutinaite","Kutnohorite","Kutyukhinite","Kuvaevite","Kuzelite","Kuzmenkoite-Mn","Kuzmenkoite-Zn","Kuzminite","Kuznetsovite","Kvanefjeldite","Kvačekite","Kyanoxalite","Kyawthuite","Kyrgyzstanite","Kyzylkumite","Kësterite","Köttigite","Laachite","Labradorite","Labuntsovite-Fe","Labuntsovite-Mg","Labuntsovite-Mn","Labyrinthite","Lacroixite","Laffittite","Laflammeite","Laforêtite","Lafossaite","Lagalyite","Lahnsteinite","Laitakarite","Lakargiite","Lakebogaite","Lalondeite","Lammerite","Lamprophyllite","Lanarkite","Landauite","Landesite","Langbeinite","Langhofite","Langite","Lanmuchangite","Lannonite","Lansfordite","Lanthanite-(Ce)","Lanthanite-(La)","Lapeyreite","Laphamite","Lapieite","Laplandite-(Ce)","Laptevite-(Ce)","Larderellite","Larisaite","Larnite","Larosite","Larsenite","Lasalite","Lasmanisite","Lasnierite","Latiumite","Latrappite","Laueite","Laumontite","Launayite","Lauraniite","Laurelite","Laurentianite","Laurentthomasite","Laurionite","Laurite","Lausenite","Lautarite","Lautenthalite","Lautite","Lavendulan","Lavoisierite","Lavrentievite","Lawrencite","Lawsonbauerite","Lawsonite","Lazarenkoite","Lazaridisite","Lazerckerite","Lazulite","Lazurite","Lead","Leadamalgam","Leadhillite","Lebedevite","Lechatelierite","Lechnerite","Lecontite","Lecoqite-(Y)","Lednevite","Leesite","Lefontite","Legrandite","Leguernite","Lehmannite","Lehnerite","Leifite","Leightonite","Leisingite","Leiteite","Lemanskiite","Lemmleinite-Ba","Lemmleinite-K","Lemoynite","Lenaite","Lengenbachite","Leningradite","Lennilenapeite","Lenoblite","Leogangite","Leonardsenite","Leonite","Lepageite","Lepersonnite-(Gd)","Lepersonnite-(Nd)","Lepidocrocite","Lepidolite","Lepkhenelmite-Zn","Lermontovite","Lesukite","Letnikovite-(Ce)","Letovicite","Leucite","Leucophanite","Leucophoenicite","Leucophosphite","Leucosphenite","Leucostaurite","Levantite","Levinsonite-(Y)","Leybovite-K","Leydetite","Leószilárdite","Liandratite","Liangjunite","Libbyite","Liberite","Libethenite","Libyan Desert glass","Liddicoatite","Liebauite","Liebigite","Liguowuite","Liguriaite","Likasite","Lileyite","Lillianite","Lime","Limousinite","Linarite","Lindackerite","Lindbergite","Lindgrenite","Lindqvistite","Lindsleyite","Lindströmite","Lingbaoite","Lintisite","Linzhiite","Liottite","Lipscombite","Liraite","Liroconite","Lisanite","Lisetite","Lishiite","Lishizhenite","Lisiguangite","Lisitsynite","Liskeardite","Lislkirchnerite","Litharge","Lithiophilite","Lithiophorite","Lithiophosphate","Lithiowodginite","Lithosite","Litidionite","Litochlebite","Litvinskite","Liveingite","Liversidgeite","Livingstonite","Lizardite","Llantenesite","Lobanovite","Lokkaite-(Y)","Lombardoite","Lomonosovite","Londonite","Lonecreekite","Longshoushanite-(Ce)","Lonsdaleite","Loparite","Lopatkaite","Loranskite-(Y)","Lorenzenite","Lorándite","Loseyite","Loudounite","Loughlinite","Louisfuchsite","Lourenswalsite","Lovdarite","Loveringite","Lovozerite","Luanheite","Luanshiweiite","Luberoite","Luboržákite","Lucabindiite","Lucasite-(Ce)","Lucasite-(La)","Lucchesiite","Luddenite","Ludjibaite","Ludlamite","Ludlockite","Ludwigite","Lueshite","Luetheite","Luinaite-(OH)","Lukechangite-(Ce)","Lukkulaisvaaraite","Lukrahnite","Lulzacite","Lumsdenite","Lun'okite","Lunijianlaite","Luobusaite","Luogufengite","Lusernaite-(Y)","Lussierite","Luxembourgite","Luzonite","Lyonsite","Långbanite","Långbanshyttanite","Låvenite","Lévyclaudite","Lévyne-Ca","Lévyne-Na","Línekite","Lópezite","Löllingite","Löweite","Lüneburgite","Macaulayite","Macdonaldite","Macedonite","Macfallite","Machatschkiite","Machiite","Macivorite","Mackayite","Mackinawite","Macphersonite","Macquartite","Macraeite","Madeiraite","Madocite","Magadiite","Magbasite","Magganasite","Maghagendorfite","Maghrebite","Magnanelliite","Magnesio-arfvedsonite","Magnesio-dutrowite","Magnesio-ferri-fluoro-hornblende","Magnesio-ferri-hornblende","Magnesio-fluoro-arfvedsonite","Magnesio-fluoro-hastingsite","Magnesio-foitite","Magnesio-hastingsite","Magnesio-hornblende","Magnesio-lucchesiite","Magnesio-riebeckite","Magnesioaubertite","Magnesiobeltrandoite-2N3S","Magnesiobermanite","Magnesiocanutite","Magnesiocarpholite","Magnesiochloritoid","Magnesiochlorophoenicite","Magnesiochromite","Magnesiocopiapite","Magnesiocoulsonite","Magnesiodumortierite","Magnesioferrite","Magnesiofluckite","Magnesiohatertite","Magnesiohongruiite-(Fe^3+^)","Magnesiohulsite","Magnesiohögbomite-2N2S","Magnesiohögbomite-2N3S","Magnesiohögbomite-2N4S","Magnesiohögbomite-6N12S","Magnesiohögbomite-6N6S","Magnesiokoritnigite","Magnesioleydetite","Magnesioneptunite","Magnesionigerite-2N1S","Magnesionigerite-6N6S","Magnesiopascoite","Magnesioqingheiite","Magnesiorowlandite-(Y)","Magnesiosadanagaite","Magnesiostaurolite","Magnesiotaaffeite-2N'2S","Magnesiotaaffeite-6N'3S","Magnesiotaramite","Magnesiovesuvianite","Magnesiovoltaite","Magnesiozippeite","Magnesium stearate","Magnetoplumbite","Magnioursilite","Magnolite","Magnussonite","Magnéliite","Magselite","Mahnertite","Maikainite","Majakite","Majindeite","Majzlanite","Makarochkinite","Makatite","Makotoite","Makovickyite","Malachite","Malanite","Malayaite","Maldonite","Maleevite","Maletoyvayamite","Malinkoite","Malladrite","Mallardite","Mallestigite","Malyshevite","Mambertiite","Mammothite","Mampsisite","Manaevite-(Ce)","Manaksite","Manandonite","Manasseite","Mandarinoite","Maneckiite","Manganarsite","Manganbabingtonite","Manganbelyankinite","Manganberzeliite","Manganese","Manganflurlite","Mangangordonite","Manganhumite","Mangani-dellaventuraite","Mangani-eckermannite","Mangani-obertiite","Mangani-pargasite","Manganiakasakaite-(La)","Manganiandrosite-(Ce)","Manganiandrosite-(La)","Manganiceladonite","Manganilvaite","Manganite","Manganlotharmeyerite","Mangano-mangani-ungarettiite","Manganoarrojadite-(KNa)","Manganobadalovite","Manganoblödite","Manganochromite","Manganocummingtonite","Manganoeudialyte","Manganogrunerite","Manganohatertite","Manganohörnesite","Manganokaskasite","Manganokhomyakovite","Manganokukisvumite","Manganolangbeinite","Manganonaujakasite","Manganoneptunite","Manganonewberyite","Manganonordite-(Ce)","Manganoquadratite","Manganoschafarzikite","Manganosegelerite","Manganoshadlunite","Manganosite","Manganostibite","Manganotychite","Manganrockbridgeite","Manganvesuvianite","Mangazeite","Manitobaite","Manjiroite","Mannardite","Mansfieldite","Mantienneite","Manuelarossiite","Maohokite","Maoniupingite-(Ce)","Mapimite","Mapiquiroite","Marathonite","Marchettiite","Marcobaldiite","Margaritasite","Margarite","Margarosanite","Mariakrite","Marialite","Marianoite","Maricopaite","Mariinskite","Marinaite","Marinellite","Marioantofilliite","Marićite","Markcooperite","Markeyite","Markhininite","Marklite","Markwelchite","Marokite","Marrite","Marrucciite","Marsaalamite-(Y)","Marshite","Marsturite","Marthozite","Martinandresite","Martinite","Martyite","Marumoite","Maruyamaite","Marécottite","Masaitisite","Mascagnite","Maslovite","Massicot","Masutomilite","Masuyite","Mathesiusite","Mathewrogersite","Mathiasite","Matildite","Matioliite","Matlockite","Matsubaraite","Matteuccite","Mattheddleite","Matthiasweilite","Matulaite","Matyhite","Maucherite","Mauriziodiniite","Maurogemmiite","Mavlyanovite","Mawbyite","Mawsonite","Maxwellite","Mayingite","Mazorite","Mazzettiite","Mazzite-Mg","Mazzite-Na","Mbobomkulite","Mcallisterite","Mcalpineite","Mcauslanite","Mcbirneyite","Mcconnellite","Mccrillisite","Mcgillite","Mcgovernite","Mcguinnessite","Mckelveyite-(Nd)","Mckelveyite-(Y)","Mckinstryite","Mcnearite","Medaite","Medenbachite","Medvedevite","Meerschautite","Megacyclite","Megakalsilite","Megawite","Meierite","Meifuite","Meionite","Meisserite","Meitnerite","Meixnerite","Meizhouite","Mejillonesite","Melanarsite","Melanocerite-(Ce)","Melanophlogite","Melanostibite","Melanotekite","Melanothallite","Melanovanadite","Melansonite","Melanterite","Melcherite","Meliphanite","Melkovite","Melliniite","Mellite","Mellizinkalite","Melonite","Menchettiite","Mendeleevite-(Ce)","Mendeleevite-(Nd)","Mendigite","Mendipite","Mendozavilite-KCa","Mendozavilite-NaCu","Mendozavilite-NaFe","Mendozite","Meneghinite","Menezesite","Mengeite","Mengxianminite","Meniaylovite","Menshikovite","Menzerite-(Y)","Mercallite","Mercury","Mereheadite","Mereiterite","Merelaniite","Merenskyite","Meridianiite","Merlinoite","Merrihueite","Mertieite","Merwinite","Mesaite","Mesolite","Messelite","Meta-aluminite","Meta-alunogen","Meta-ankoleite","Meta-autunite","Metaborite","Metacalciouranoite","Metacinnabar","Metadelrioite","Metahaiweeite","Metaheimite","Metaheinrichite","Metahewettite","Metahohmannite","Metakahlerite","Metaköttigite","Metalodèvite","Metamunirite","Metanatroautunite","Metanováčekite","Metarauchite","Metasaléeite","Metaschoderite","Metaschoepite","Metasideronatrite","Metastibnite","Metastudtite","Metaswitzerite","Metatamboite","Metathénardite","Metatorbernite","Metatyuyamunite","Metauramphite","Metauranocircite","Metauranocircite II","Metauranopilite","Metauranospinite","Metauroxite","Metavandendriesscheite","Metavanmeersscheite","Metavanuralite","Metavariscite","Metavauxite","Metavivianite","Metavoltine","Metazellerite","Metazeunerite","Meurigite-K","Meurigite-Na","Meyerhofferite","Meymacite","Meyrowitzite","Mgriite","Mianningite","Miargyrite","Miassite","Michalskiite","Micheelsenite","Michenerite","Michitoshiite-(Cu)","Microcline","Microlite","Microsommite","Midbarite","Middendorfite","Middlebackite","Mieite-(Y)","Miersite","Miessiite","Miguelromeroite","Miharaite","Mikasaite","Mikecoxite","Mikehowardite","Milanriederite","Milarite","Milkovoite","Millisite","Millosevichite","Millsite","Milotaite","Mimetite","Mimetite-M","Minakawaite","Minasgeraisite-(Y)","Minasragrite","Mineevite-(Y)","Minehillite","Minguzzite","Minium","Minjiangite","Minohlite","Minrecordite","Minyulite","Mirnyite","Misakiite","Misenite","Miserite","Mitridatite","Mitrofanovite","Mitryaevaite","Mitscherlichite","Mixite","Miyahisaite","Miyawakiite-(Y)","Mizraite-(Ce)","Moabite","Moctezumite","Modraite","Mogovidite","Mogánite","Mohite","Mohrite","Moiraite","Moissanite","Mojaveite","Molinelloite","Moluranite","Molybdenite","Molybdenum","Molybdite","Molybdofornacite","Molybdomenite","Molybdophyllite","Molysite","Momoiite","Monalbite","Monazite-(Gd)","Monazite-(La)","Monazite-(Nd)","Monazite-(Sm)","Moncheite","Monchetundraite","Monetite","Mongolite","Mongshanite","Monimolite","Monipite","Monohydrocalcite","Montanite","Montbrayite","Montdorite","Montebrasite","Monteneroite","Monteneveite","Monteponite","Monteregianite-(Y)","Montesommaite","Montetrisaite","Montgomeryite","Monticellite","Montpelvouxite","Montroseite","Montroyalite","Montroydite","Mooihoekite","Moolooite","Mooreite","Mopungite","Moraesite","Moragite","Moraskoite","Mordenite","Moreauite","Morelandite","Morenosite","Morimotoite","Morinite","Morleyite","Morningstarite","Morozeviczite","Morrisonite","Mosandrite-(Ce)","Moschelite","Moschellandsbergite","Mosesite","Moskvinite-(Y)","Mottanaite-(Ce)","Mottramite","Motukoreaite","Mounanaite","Mountainite","Mountkeithite","Mourite","Moxuanxueite","Moydite-(Y)","Mozartite","Mozgovaite","Moëloite","Mpororoite","Mroseite","Mrázekite","Muirite","Mukhinite","Mullite","Mummeite","Munakataite","Mundite","Mundrabillaite","Munirite","Muonionalustaite","Murakamiite","Murashkoite","Murataite-(Y)","Murchisite","Murmanite","Murunskite","Museumite","Mushistonite","Muskoxite","Muthmannite","Mutinaite","Mutnovskite","Mäkinenite","Mélonjosephite","Möhnite","Mössbauerite","Mückeite","Müllerite","Naalasite","Nabalamprophyllite","Nabaphite","Nabateaite","Nabesite","Nabiasite","Nabimusaite","Nabokoite","Nacaphite","Nacareniobsite-(Ce)","Nacareniobsite-(Nd)","Nacareniobsite-(Y)","Nacrite","Nadorite","Nafeasite","Nafertisite","Nagashimalite","Nagelschmidtite","Nagyágite","Nahcolite","Nahpoite","Nakauriite","Nakkaalaaqite","Naldrettite","Nalipoite","Nalivkinite","Namansilite","Nambulite","Namibite","Namuwite","Nancyrossite","Nanlingite","Nannoniite","Nanpingite","Nantokite","Napoliite","Naquite","Narsarsukite","Nashite","Nasinite","Nasledovite","Nasonite","Nastrophite","Nataliakulikite","Nataliyamalikite","Natalyite","Natanite","Natisite","Natrite","Natroalunite","Natroaphthitalite","Natrobistantite","Natroboltwoodite","Natrochalcite","Natrodufrénite","Natroglaucocerinite","Natrojarosite","Natrokomarovite","Natrolemoynite","Natrolite","Natromarkeyite","Natromelansonite","Natromolybdite","Natromontebrasite","Natron","Natronambulite","Natroniobite","Natropharmacoalumite","Natropharmacosiderite","Natrophilite","Natrophosphate","Natrosilite","Natrosulfatourea","Natrotantite","Natrotitanite","Natrouranospinite","Natrowalentaite","Natroxalate","Natrozippeite","Naujakasite","Naumannite","Navajoite","Navrotskyite","Nazarchukite","Nazarovite","Nchwaningite","Nealite","Nechelyustovite","Nefedovite","Negevite","Neighborite","Nekoite","Nekrasovite","Nelenite","Neltnerite","Nenadkevichite","Neotocite","Nepskoeite","Neptunite","Neskevaaraite-Fe","Nesquehonite","Nestolaite","Nevadaite","Nevskite","Newberyite","Neyite","Nežilovite","Niahite","Niasite","Nichromite","Nickelalumite","Nickelaustinite","Nickelbischofite","Nickelblödite","Nickelboussingaultite","Nickelhexahydrite","Nickeline","Nickellotharmeyerite","Nickelphosphide","Nickelpicromerite","Nickelschneebergite","Nickeltalmessite","Nickeltsumcorite","Nickeltyrrellite","Nickelzippeite","Nickenichite","Nickolayite","Nicksobolevite","Niedermayrite","Nielsbohrite","Nielsenite","Nierite","Nifontovite","Nigelcookite","Niggliite","Niigataite","Nikischerite","Nikmelnikovite","Niksergievite","Nimite","Ningyoite","Niningerite","Nioboaeschynite-(Nd)","Nioboaeschynite-(Y)","Niobobaotite","Niobocarbide","Nioboheftetjernite","Nioboholtite","Nioboixiolite-(Fe^2+^)","Nioboixiolite-(Fe^3+^)","Nioboixiolite-(Mn^2+^)","Nioboixiolite-(◻)","Niobokupletskite","Niobophyllite","Niocalite","Nipalarsite","Nipeiite-(Ce)","Nisbite","Nishanbaevite","Nisnite","Nissonite","Niter","Nitratine","Nitrobarite","Nitrocalcite","Nitromagnesite","Nitroplumbite","Nitscheite","Niveolanite","Nixonite","Nizamoffite","Nobleite","Noelbensonite","Nolanite","Nollmotzite","Nolzeite","Noonkanbahite","Norbergite","Nordenskiöldine","Nordgauite","Nordite-(Ce)","Nordite-(La)","Nordstrandite","Nordströmite","Norilskite","Normandite","Norrishite","Norsethite","Northstarite","Northupite","Nosean","Novgorodovaite","Novikovite","Novodneprite","Novograblenovite","Novákite","Nováčekite","Nowackiite","Nsutite","Nuffieldite","Nukundamite","Nullaginite","Numanoite","Nuragheite","Nuwaite","Nybergite","Nybøite","Nyerereite","Nyholmite","Népouite","Nöggerathite-(Ce)","O'danielite","Oberthürite","Oberwolfachite","Oboniobite","Oboyerite","Obradovicite-KCu","Obradovicite-NaCu","Obradovicite-NaNa","Odigitriaite","Odikhinchaite","Odintsovite","Offretite","Oftedalite","Ogdensburgite","Ognitite","Ohmilite","Ohtaniite","Ojuelaite","Okanoganite-(Y)","Okayamalite","Okenite","Okhotskite","Okieite","Okruginite","Okruschite","Oldhamite","Oldsite-(K)","Olekminskite","Olenite","Olgafrankite","Olgite","Oligoclase","Olkhonskite","Olmiite","Olmsteadite","Olsacherite","Olsenite","Olshanskyite","Olympite","Omariniite","Omeiite","Ominelite","Omongwaite","Omphacite","Omsite","Ondrušite","Oneillite","Onoratoite","Oosterboschite","Ootannite","Ophirite","Oppenheimerite","Orcelite","Ordoñezite","Oregonite","Oreillyite","Organovaite-Mn","Organovaite-Zn","Orickite","Orientite","Orishchinite","Orlandiite","Orlovite","Orlymanite","Orpheite","Orschallite","Orthobrannerite","Orthochamosite","Orthocuproplatinum","Orthoericssonite","Orthogersdorffite","Orthojoaquinite-(Ce)","Orthojoaquinite-(La)","Orthominasragrite","Orthopinakiolite","Orthoserpierite","Orthowalpurgite","Osakaite","Osarizawaite","Osarsite","Osbornite","Oscarkempffite","Oskarssonite","Osmium","Osumilite","Osumilite-(Mg)","Oswaldpeetersite","Otavite","Otjisumeite","Ottemannite","Ottohahnite","Ottoite","Ottoliniite","Ottrélite","Otwayite","Oulankaite","Ourayite","Ovamboite","Overite","Owensite","Owyheeite","Oxammite","Oxo-magnesio-hastingsite","Oxo-mangani-leakeite","Oxy-chromium-dravite","Oxy-dravite","Oxy-foitite","Oxy-schorl","Oxy-vanadium-dravite","Oxybismutomicrolite","Oxycalciobetafite","Oxycalciomicrolite","Oxycalciopyrochlore","Oxycalcioroméite","Oxykinoshitalite","Oxynatromicrolite","Oxyphlogopite","Oxyplumbopyrochlore","Oxyplumboroméite","Oxystannomicrolite","Oxystibiomicrolite","Oxyuranobetafite","Oxyvanite","Oxyyttrobetafite-(Y)","Oyelite","Oyonite","Ozernovskite","Ozerovaite","Paarite","Pabellóndepicaite","Pabstite","Paceite","Pachnolite","Packratite","Paddlewheelite","Padmaite","Padĕraite","Paganoite","Pahasapaite","Painite","Palarstanide","Palenzonaite","Palladinite","Palladium","Palladoarsenide","Palladobismutharsenide","Palladodymite","Palladogermanide","Palladosilicide","Palladothallite","Palladseite","Palmierite","Palygorskite","Pampaloite","Panasqueiraite","Pandoraite-Ba","Pandoraite-Ca","Panethite","Panguite","Panichiite","Panskyite","Pansnerite","Panunzite","Paolovite","Papagoite","Papikeite","Paqueite","Para-alumohydrocalcite","Parabariomicrolite","Paraberzeliite","Parabrandtite","Parabutlerite","Paracelsian","Paracetamol","Paracoquimbite","Paradamite","Paradocrasite","Paraershovite","Parafiniukite","Parafransoletite","Parageorgbokiite","Paragersdorffite","Paragonite","Paraguanajuatite","Parahibbingite","Parahopeite","Parakeldyshite","Parakuzmenkoite-Fe","Paralabuntsovite-Mg","Paralammerite","Paralaurionite","Paralomonosovite","Paralstonite","Paramarkeyite","Paramelaconite","Paramendozavilite","Paramolybdomenite","Paramontroseite","Paranatisite","Paranatrolite","Paraniite-(Y)","Paraotwayite","Parapierrotite","Pararaisaite","Pararammelsbergite","Pararealgar","Pararobertsite","Pararsenolamprite","Parascandolaite","Paraschachnerite","Paraschoepite","Parascholzite","Parascorodite","Parasibirskite","Paraspurrite","Parasterryite","Parasymplesite","Paratacamite","Paratacamite-(Mg)","Paratacamite-(Ni)","Paratellurite","Paratimroseite","Paratobermorite","Paratooite-(La)","Paratsepinite-Ba","Paratsepinite-Na","Paraumbite","Parauranophane","Paravauxite","Paravinogradovite","Parawulffite","Pargasite","Parisite-(Ce)","Parisite-(Nd)","Parkerite","Parkinsonite","Parnauite","Parsettensite","Parsonsite","Parthéite","Partzite","Parvo-mangano-edenite","Parvo-manganotremolite","Parwanite","Parwelite","Parádsasvárite","Pascoite","Paseroite","Patrónite","Pattersonite","Patynite","Pauflerite","Pauladamsite","Paulgrothite","Paulhlavaite","Paulingite-Ca","Paulingite-K","Paulingite-Na","Paulišite","Paulkellerite","Paulkerrite","Paulmooreite","Paulrobinsonite","Paulscherrerite","Pautovite","Pavlovskyite","Pavonite","Paxite","Pašavaite","Pearceite","Peatite-(Y)","Pecoraite","Pectolite","Pectolite-M2abc","Pedrizite","Peisleyite","Pekoite","Pekovite","Pellouxite","Pellyite","Penberthycroftite","Pendevilleite-(Y)","Penfieldite","Pengite","Penkvilksite","Pennantite","Penobsquisite","Penriceite","Penroseite","Pentagonite","Pentahydrite","Pentahydroborite","Penzhinite","Peprossiite-(Ce)","Peprossiite-(Y)","Perbøeite-(Ce)","Perbøeite-(La)","Perchiazziite","Perchukite-(Y)","Percleveite-(Ce)","Percleveite-(La)","Peretaite","Perettiite-(Y)","Perhamite","Perite","Perkovaite","Perlialite","Perloffite","Permanganogrunerite","Permingeatite","Perovskite","Perraultite","Perrierite-(Ce)","Perrierite-(La)","Perroudite","Perryite","Pertlikite","Pertsevite-(F)","Pertsevite-(OH)","Petalite","Petarasite","Petedunnite","Peterandresenite","Peterbaylissite","Peterchinite","Petersenite-(Ce)","Petersite-(La)","Petersite-(Y)","Petitjeanite","Petrovicite","Petrovite","Petrovskaite","Petrukite","Petscheckite","Petterdite","Petzite","Petříčekite","Pezzottaite-(Cs)","Pfaffenbergite","Pharmacoalumite","Pharmacolite","Pharmacosiderite","Pharmazincite","Phaunouxite","Philipsbornite","Philipsburgite","Phillipsite-Ca","Phillipsite-K","Phillipsite-Na","Philolithite","Philoxenite","Philrothite","Phoenicochroite","Phosgenite","Phosinaite-(Ce)","Phosphammite","Phosphocyclite-(Fe)","Phosphocyclite-(Ni)","Phosphoellenbergerite","Phosphoferrite","Phosphofibrite","Phosphogartrellite","Phosphohedyphane","Phosphoinnelite","Phosphorrösslerite","Phosphosiderite","Phosphovanadylite-Ba","Phosphovanadylite-Ca","Phosphowalpurgite","Phosphuranylite","Phoxite","Phuralumite","Phurcalite","Phylloretine","Phyllotungstite","Picaite","Piccoliite","Pickeringite","Picotpaulite","Picromerite","Picropharmacolite","Pieczkaite","Piemontite","Piemontite-(Pb)","Piemontite-(Sr)","Piergorite-(Ce)","Pierrotite","Pigotite","Pilanesbergite","Pilawite-(Y)","Pilipenkoite","Pillaite","Pilsenite","Pinakiolite","Pinalite","Pinchite","Pingguite","Pinnoite","Pintadoite","Piretite","Pirssonite","Pitiglianoite","Pitticite","Pittongite","Piypite","Pizgrischite","Plagionite","Planerite","Platarsite","Platinum","Plattnerite","Plavnoite","Playfairite","Pleysteinite","Plimerite","Pliniusite","Plombièrite","Plumboagardite","Plumbobetafite","Plumboferrite","Plumbogaidonnayite","Plumbogottlobite","Plumbogummite","Plumbojarosite","Plumbojohntomaite","Plumbomicrolite","Plumbonacrite","Plumbopalladinite","Plumboperloffite","Plumbopharmacosiderite","Plumbophyllite","Plumbopyrochlore","Plumboselite","Plumbotellurite","Plumbotsumite","Plumosite","Plášilite","Podlesnoite","Poellmannite","Pohlite","Poirierite","Poitevinite","Pokhodyashinite","Pokrovskite","Polarite","Poldervaartite","Polekhovskyite","Polezhaevaite-(Ce)","Polhemusite","Polkanovite","Polkovicite","Polloneite","Pollucite","Polyakovite-(Ce)","Polyarsite","Polybasite","Polycrase-(Y)","Polydymite","Polyhalite","Polylithionite","Polyphite","Polystyrene","Pomite","Ponomarevite","Popovite","Poppiite","Popugaevaite","Portlandite","Posnjakite","Postite","Potarite","Potassic magnesio-arfvedsonite","Potassic-arfvedsonite","Potassic-chloro-hastingsite","Potassic-chloro-pargasite","Potassic-ferri-leakeite","Potassic-ferro-ferri-sadanagaite","Potassic-ferro-ferri-taramite","Potassic-ferro-pargasite","Potassic-ferro-sadanagaite","Potassic-ferro-taramite","Potassic-fluoro-hastingsite","Potassic-fluoro-pargasite","Potassic-fluoro-richterite","Potassic-hastingsite","Potassic-jeanlouisite","Potassic-magnesio-arfvedsonite","Potassic-magnesio-fluoro-arfvedsonite","Potassic-magnesio-hastingsite","Potassic-mangani-leakeite","Potassic-pargasite","Potassic-richterite","Potassic-sadanagaite","Potassiccarpholite","Potassichastingsite","Potassicmendeleevite-(Ce)","Potassicrichterite","Potassium Dihydrogen Phosphate","Potosíite","Pottsite","Poubaite","Poudretteite","Poughite","Povondraite","Powellite","Poyarkovite","Pošepnýite","Prachařite","Pratesiite","Preisingerite","Preiswerkite","Preobrazhenskite","Pretulite","Prewittite","Priceite","Priderite","Princivalleite","Pringleite","Priscillagrewite-(Y)","Prismatine","Probertite","Proshchenkoite-(Y)","Prosopite","Prosperite","Protasite","Proto-anthophyllite","Proto-ferro-anthophyllite","Proto-ferro-suenoite","Proto-owyheeite","Protocaseyite","Protochabournéite","Protoenstatite","Protojoséite","Proudite","Proustite","Proxidecagonite","Proxitwelvefoldite","Przhevalskite","Pseudoboleite","Pseudocotunnite","Pseudodickthomssenite","Pseudograndreefite","Pseudojohannite","Pseudolaueite","Pseudolyonsite","Pseudomalachite","Pseudomarkeyite","Pseudomeisserite-(NH_4_)","Pseudomertieite","Pseudopomite","Pseudorutile","Pseudosinhalite","Pseudowollastonite","Pucherite","Pumpellyite-(Al)","Pumpellyite-(Fe^2+^)","Pumpellyite-(Fe^3+^)","Pumpellyite-(Mg)","Pumpellyite-(Mn^2+^)","Puninite","Punkaruaivite","Purpurite","Puschridgeite","Pushcharovskite","Putnisite","Putoranite","Puttapaite","Putzite","Pyatenkoite-(Y)","Pyracmonite","Pyradoketosite","Pyrargyrite","Pyrimethamine","Pyroaurite","Pyrobelonite","Pyrochlore","Pyrochroite","Pyrocoproite","Pyrolusite","Pyromorphite","Pyrope","Pyrophanite","Pyrophosphite","Pyrophyllite","Pyrosmalite-(Mn)","Pyrostilpnite","Pyroxmangite","Pääkkönenite","Péligotite","Písekite-(Y)","Příbramite","Qandilite","Qaqarssukite-(Ce)","Qatranaite","Qeltite","Qilianshanite","Qingheiite","Qingsongite","Qitianlingite","Qiumingite","Quadratite","Quadridavyne","Quadruphite","Quatrandorite","Queitite","Quenselite","Quenstedtite","Quetzalcoatlite","Quijarroite","Quintinite","Quintinite-3T","Qusongite","Raadeite","Rabbittite","Rabejacite","Raberite","Radekškodaite-(Ce)","Radekškodaite-(La)","Radhakrishnaite","Radovanite","Radtkeite","Radvaniceite","Raguinite","Raisaite","Raite","Rajite","Rakovanite","Ralphcannonite","Ramaccioniite","Ramanite-(Cs)","Ramanite-(Rb)","Ramazzoite","Rambergite","Rameauite","Ramikite-(Y)","Rammelsbergite","Ramosite","Ramsbeckite","Ramsdellite","Ranciéite","Rankachite","Rankamaite","Rankinite","Ransomite","Ranunculite","Rapidcreekite","Raslakite","Rastsvetaevite","Rathite","Rathite-IV","Rauchite","Raudseppite","Rauenthalite","Rauvite","Ravatite","Rayite","Realgar","Reaphookhillite","Rebulite","Reckibachite","Rectorite","Redcanyonite","Reddingite","Redgillite","Redingtonite","Redledgeite","Redmondite","Redondite","Reederite-(Y)","Reevesite","Refikite","Regerite","Reichenbachite","Reidite","Reinerite","Reinhardbraunsite","Relianceite-(K)","Renardite","Rengeite","Renierite","Reppiaite","Retgersite","Revdite","Rewitzerite","Reyerite","Reynoldsite","Reznitskyite","Rhabdoborite-(Mo)","Rhabdoborite-(V)","Rhabdoborite-(W)","Rhabdophane-(Ce)","Rhabdophane-(La)","Rhabdophane-(Nd)","Rhabdophane-(Y)","Rheniite","Rhodarsenide","Rhodesite","Rhodium","Rhodizite","Rhodochrosite","Rhodonite","Rhodostannite","Rhodplumsite","Rhönite","Ribbeite","Richardsite","Richardsollyite","Richellite","Richelsdorfite","Richetite","Richterite","Rickardite","Rickturnerite","Riebeckite","Riesite","Rietveldite","Rigrahamite","Rilandite","Rimkorolgite","Rinkite-(Ce)","Rinkite-(Y)","Rinmanite","Rinneite","Riomarinaite","Riotintoite","Rippite","Rittmannite","Rivadavite","Riversideite","Roaldite","Robinsonite","Rockbridgeite","Rodalquilarite","Rodolicoite","Roeblingite","Roedderite","Rogermitchellite","Roggianite","Rohaite","Rokühnite","Rollandite","Romanite","Romanorlovite","Romanèchite","Romarchite","Roméite","Rondorfite","Ronneburgite","Ronpetersonite","Rooseveltite","Roquesite","Rorisite","Rosasite","Roscherite","Roscoelite","Rosemaryite","Rosenbergite","Rosenbuschite","Rosenhahnite","Roshchinite","Rosiaite","Rosickýite","Rosièresite","Rossiantonite","Rossite","Rossmanite","Rossovskyite","Rostite","Rotemite","Roterbärite","Rotherkopfite","Rouaite","Roubaultite","Roumaite","Rouseite","Routhierite","Rouvilleite","Rouxelite","Roweite","Rowlandite-(Y)","Rowleyite","Roxbyite","Roymillerite","Rozenite","Rozhdestvenskayaite-(Zn)","Ruarsite","Rubicline","Rubinite","Rucklidgeite","Rudabányaite","Rudashevskyite","Rudenkoite","Rudolfhermannite","Ruifrancoite","Ruitenbergite","Ruizhongite","Rumoiite","Rumseyite","Rundqvistite-(Ce)","Rusakovite","Rusinovite","Russellite","Russoite","Rustenburgite","Rustumite","Ruthenarsenite","Rutheniridosmine","Ruthenium","Rutherfordine","Ryabchikovite","Rynersonite","Rémondite-(Ce)","Rémondite-(La)","Ríosecoite","Römerite","Röntgenite-(Ce)","Rösslerite","Rüdlingerite","SO4 - hydrotalcite - 11Å","SO4 - hydrotalcite - 8.8Å","Saamite","Sabatierite","Sabelliite","Sabieite","Sabinaite","Sabugalite","Saccoite","Sachanbińskiite","Sacrofanite","Sadanagaite","Saddlebackite","Sahamalite-(Ce)","Sahlinite","Sailaufite","Sainfeldite","Sakhaite","Sakuraiite","Salammoniac","Salesite","Saliotite","Saltonseaite","Salzburgite","Saléeite","Samaniite","Samarium","Samarskite-(Y)","Samarskite-(Yb)","Samfowlerite","Sampleite","Samraite","Samsonite","Samuelsonite","Sanbornite","Saneroite","Sangenaroite","Sanguite","Sanjuanite","Sanmartinite","Sanrománite","Santabarbaraite","Santaclaraite","Santafeite","Santanaite","Santarosaite","Santite","Sapozhnikovite","Sapphirine","Sarabauite","Saranchinaite","Saranovskite","Sarcolite","Sarcopside","Sardashtite","Sardignaite","Sarkinite","Sarmientite","Sarrabusite","Sarrochite","Sartorite","Sarvodaite","Saryarkite-(Y)","Sasaite","Sassite","Sassolite","Satimolite","Satpaevite","Satterlyite","Sauconite","Savelievaite","Sayrite","Sazhinite-(Ce)","Sazhinite-(La)","Sazykinaite-(Y)","Sbacchiite","Sborgite","Scacchite","Scainiite","Scandio-fluoro-eckermannite","Scandio-winchite","Scandiobabingtonite","Scarbroite","Scawtite","Scenicite","Schachnerite","Schafarzikite","Schairerite","Schallerite","Schapbachite","Scheelite","Schertelite","Scheuchzerite","Schiavinatoite","Schieffelinite","Schindlerite","Schirmerite","Schlegelite","Schlemaite","Schlossmacherite","Schlüterite-(Y)","Schmidite","Schmiederite","Schmitterite","Schneiderhöhnite","Schoderite","Schoenfliesite","Schoepite","Scholzite","Schoonerite","Schorl","Schorlomite","Schreibersite","Schreyerite","Schröckingerite","Schubnelite","Schuetteite","Schuilingite-(Nd)","Schulenbergite","Schultenite","Schumacherite","Schwartzembergite","Schwertmannite","Schäferite","Schöllhornite","Schüllerite","Sclarite","Scolecite","Scordariite","Scorodite","Scorticoite","Scorzalite","Scotlandite","Scrutinyite","Seaborgite","Seamanite","Searlesite","Sederholmite","Sedovite","Seeligerite","Seelite","Segelerite","Segnitite","Seidite-(Ce)","Seidozerite","Seinäjokite","Sejkoraite-(Y)","Sekaninaite","Selenium","Selenodantopaite","Selenojalpaite","Selenojunoite","Selenolaurite","Selenopolybasite","Selenostephanite","Seligmannite","Selivanovaite","Sellaite","Selsurtite","Selwynite","Semenovite-(Ce)","Semseyite","Senaite","Senandorite","Senarmontite","Senegalite","Sengierite","Senkevichite","Sepiolite","Serandite","Serendibite","Sergeevite","Sergevanite","Sergeysmirnovite","Serpierite","Serrabrancaite","Sewardite","Shabaite-(Nd)","Shabynite","Shadlunite","Shafranovskite","Shagamite","Shakhdaraite-(Y)","Shakhovite","Shandite","Shannonite","Sharpite","Sharyginite","Shasuite","Shattuckite","Shcherbakovite","Shcherbinaite","Shchurovskyite","Sheldrickite","Shenganfuite","Shenzhuangite","Sherwoodite","Shibkovite","Shigaite","Shijiangshanite","Shilovite","Shimazakiite","Shimenite","Shinarumpite","Shinichengite","Shinkolobweite","Shiranuiite","Shirokshinite","Shirozulite","Shkatulkalite","Shlykovite","Shojiite","Shomiokite-(Y)","Shortite","Shosanbetsuite","Shuangfengite","Shubnikovite","Shuiskite-(Cr)","Shuiskite-(Mg)","Shulamitite","Shumwayite","Shuvalovite","Si_3_N_4_-beta","Sibirskite","Sicherite","Sicklerite","Siderazot","Sideronatrite","Siderophyllite","Siderotil","Sidorenkite","Sidorovite","Sidpietersite","Sidwillite","Sieleckiite","Sigismundite","Sigloite","Sigogglinite","Siidraite","Silesiaite","Silhydrite","Silicocarnotite","Silicon","Siligiite","Silinaite","Sillénite","Silver","Silvialite","Simferite","Simmonsite","Simonellite","Simonite","Simonkolleite","Simplotite","Simpsonite","Sincosite","Sinhalite","Sinjarite","Sinkankasite","Sinnerite","Sinoite","Sitinakite","Siudaite","Siwaqaite","Sjögrenite","Skaergaardite","Skinnerite","Skippenite","Sklodowskite","Skogbyite","Skorpionite","Slavkovite","Slavíkite","Slawsonite","Slottaite","Sluzhenikinite","Slyudyankaite","Smamite","Smirnite","Smirnovskite","Smithite","Smithsonite","Smrkovecite","Sobolevite","Sobolevskite","Sodalite","Soddyite","Sodic-ferri-clinoferroholmquistite","Sodic-ferripedrizite","Sodic-ferro-anthophyllite","Sodic-ferrogedrite","Sodic-ferropedrizite","Sodicanthophyllite","Sodicgedrite","Sodicpedrizite","Sofiite","Sogdianite","Sokolovaite","Solongoite","Somersetite","Sonolite","Sonoraite","Sopcheite","Sorbyite","Sorosite","Sosedkoite","Souzalite","Součekite","Spadaite","Spaltiite","Spangolite","Spanoite","Spencerite","Sperlingite","Sperrylite","Spertiniite","Spessartine","Sphaerobertrandite","Sphaerobismoite","Sphalerite","Spheniscidite","Spionkopite","Spiridonovite","Spiroffite","Spriggite","Springcreekite","Spryite","Spurrite","Srebrodolskite","Srilankite","Stalderite","Stanfieldite","Stangersite","Stankeithite","Stanleyite","Stannite","Stannoidite","Stannopalladinite","Staněkite","Starovaite","Staročeskéite","Staurolite","Stavelotite-(La)","Steacyite","Steedeite","Steenstrupine-(Ce)","Stefanweissite","Steigerite","Steinhardtite","Steiningerite","Steinmetzite","Steklite","Stellerite","Stenhuggarite","Stenonite","Stepanovite","Stephanite","Stercorite","Stergiouite","Sterlinghillite","Sternbergite","Steropesite","Sterryite","Stetefeldtite","Stetindite-(Ce)","Steudelite","Stevensite","Steverustite","Stewartite","Stibarsen","Stibiconite","Stibiocolumbite","Stibiocolusite","Stibiogoldfieldite","Stibiopalladinite","Stibiosegnitite","Stibiotantalite","Stibioústalečite","Stibivanite","Stibnite","Stichtite","Stilbite-Ca","Stilbite-Na","Stilleite","Stillwaterite","Stillwellite-(Ce)","Stillwellite-(La)","Stistaite","Stoiberite","Stokesite","Stolperite","Stolzite","Stoppaniite","Stornesite-(Y)","Stottite","Stracherite","Straczekite","Strakhovite","Strandite","Stranskiite","Strashimirite","Strassmannite","Strelkinite","Stringhamite","Stromeyerite","Stronadelphite","Stronalsite","Strontianite","Strontio-orthojoaquinite","Strontioborite","Strontiochevkinite","Strontiodresserite","Strontiofluorite","Strontioginorite","Strontiohurlbutite","Strontiojoaquinite","Strontiomelane","Strontioperloffite","Strontiopharmacosiderite","Strontiopyrochlore","Strontiowhitlockite","Strunzite","Struvite-(K)","Strätlingite","Studenitsite","Studtite","Stumpflite","Stunorthropite","Sturmanite","Stützite","Suanite","Sudburyite","Sudoite","Sudovikovite","Suenoite","Suessite","Sugakiite","Sugarwhiteite","Sugilite","Suhailite","Sulfadoxine","Sulfatoredmondite","Sulfhydrylbystrite","Sulfoborite","Sulfopadmaite","Sulphohalite","Sulphotsumoite","Sulvanite","Sundiusite","Sunshuite","Suolunite","Suredaite","Surinamite","Surite","Surkhobite","Sursassite","Susannite","Suseinargiuite","Sussexite","Suzukiite","Svabite","Svanbergite","Sveinbergeite","Sveite","Sverigeite","Svetlanaite","Svornostite-(K)","Svornostite-(NH_4_)","Svyatoslavite","Svyazhinite","Swaknoite","Swamboite-(Nd)","Swartzite","Swedenborgite","Sweetite","Swinefordite","Switzerite","Sylvanite","Sylvite","Symesite","Symplesite","Synadelphite","Synchysite-(Ce)","Synchysite-(Nd)","Synchysite-(Y)","Syngenite","Szaibélyite","Szilagyiite","Szklaryite","Szmikite","Sztrókayite","Szymańskiite","Söhngeite","Sørensenite","Tacharanite","Tachyhydrite","Tadzhikite-(Ce)","Tadzhikite-(Y)","Taenite","Taikanite","Taimyrite","Taimyrite II","Tainiolite","Taipingite-(CeCa)","Takanawaite-(Y)","Takanelite","Takedaite","Takovite","Takéuchiite","Talmessite","Talnakhite","Tamaite","Tamarugite","Tamboite","Tamuraite","Tancaite-(Ce)","Tancoite","Taneyamalite","Tangdanite","Tangeite","Tanohataite","Tantalaeschynite-(Ce)","Tantalaeschynite-(Y)","Tantalcarbide","Tantalite-(Fe)","Tantalite-(Mg)","Tantalite-(Mn)","Tantalowodginite","Tanteuxenite-(Y)","Tantite","Tapiaite","Tapiolite-(Fe)","Tapiolite-(Mn)","Taramellite","Taramite","Taranakite","Tarapacáite","Tarbagataite","Tarbuttite","Tarkianite","Tartarosite","Tarutinoite","Taseqite","Tashelgite","Tassieite","Tatarinovite","Tatarskite","Tatyanaite","Tausonite","Tavagnascoite","Tavorite","Tazheranite","Tazieffite","Tazzoliite","Teallite","Tedhadleyite","Teepleite","Tegengrenite","Teineite","Telargpalite","Tellurantimony","Tellurite","Tellurium","Tellurobismuthite","Tellurocanfieldite","Tellurohauchecornite","Telluromandarinoite","Telluronevskite","Telluropalladinite","Telluroperite","Telyushenkoite","Temagamite","Tengchongite","Tengerite-(Y)","Tennantite-(Cd)","Tennantite-(Cu)","Tennantite-(Fe)","Tennantite-(Hg)","Tennantite-(In)","Tennantite-(Mn)","Tennantite-(Ni)","Tennantite-(Zn)","Tenorite","Tephroite","Terlinguacreekite","Terlinguaite","Ternesite","Ternovite","Terranovaite","Terskite","Tertschite","Teruggite","Teschemacherite","Testibiopalladite","Tetra-auricupride","Tetradymite","Tetraferriannite","Tetraferriphlogopite","Tetraferroplatinum","Tetrahedrite-(Cd)","Tetrahedrite-(Cu)","Tetrahedrite-(Fe)","Tetrahedrite-(Hg)","Tetrahedrite-(Mn)","Tetrahedrite-(Ni)","Tetrahedrite-(Zn)","Tetrarooseveltite","Tetrataenite","Tewite","Thadeuite","Thalcusite","Thalfenisite","Thalhammerite","Thalliomelane","Thalliumpharmacosiderite","Thalénite-(Y)","Thaumasite","Thebaite-(NH_4_)","Theisite","Theoparacelsite","Theophrastite","Therasiaite","Thermaerogenite","Thermessaite","Thermessaite-(NH_4_)","Thermonatrite","Theuerdankite","Thionasilite","Thomasclarkite-(Y)","Thometzekite","Thomsenolite","Thomsonite-Ca","Thomsonite-Sr","Thorasphite","Thorbastnäsite","Thoreaulite","Thorianite","Thorikosite","Thoriopyrochlore","Thorite","Thornasite","Thorneite","Thorogummite","Thorosteenstrupine","Thorsite","Thortveitite","Thorutite","Threadgoldite","Thunderbayite","Tianhongqiite","Tianhuixinite","Tiberiobardiite","Tibiscumite","Tiemannite","Tienshanite","Tietaiyangite","Tiettaite","Tikhonenkovite","Tilasite","Tilkerodeite","Tilleyite","Tillmannsite","Timroseite","Tin","Tinaksite","Tincalconite","Tinnunculite","Tinsleyite","Tinticite","Tintinaite","Tinzenite","Tiptopite","Tiragalloite","Tischendorfite","Tisinalite","Tistarite","Titanite","Titanium","Titanoholtite","Titanomaghemite","Titanowodginite","Titantaramellite","Tivanite","Tlalocite","Tlapallite","Tobelite","Tobermorite","Tochilinite","Tocornalite","Todorokite","Tokkoite","Tokyoite","Tolbachite","Toledoite","Tolovkite","Tolstykhite","Tomamaeite","Tombarthite-(Y)","Tombstoneite","Tomcampbellite","Tomichite","Tomiolloite","Tomsquarryite","Tondiite","Tongbaite","Tongxinite","Tooeleite","Topaz","Topsøeite","Torbernite","Torrecillasite","Torreyite","Torryweiserite","Tosudite","Toturite","Tounkite","Touretite","Tourmaline","Townendite","Toyohaite","Trabzonite","Transjordanite","Traskite","Trattnerite","Treasurite","Trebiskyite","Trechmannite","Tredouxite","Trembathite","Tremolite","Trevorite","Triangulite","Triazolite","Trigodomeykite","Trigonite","Trikalsilite","Trilithionite","Trimerite","Trimounsite-(Y)","Trinepheline","Trinitite","Triphylite","Triplite","Triploidite","Trippkeite","Tripuhyite","Tristramite","Tritomite-(Ce)","Tritomite-(Y)","Trolleite","Trona","Truscottite","Trébeurdenite","Trögerite","Trüstedtite","Tsangpoite","Tsaregorodtsevite","Tschermakite","Tschermigite","Tschernichite","Tschörtnerite","Tsepinite-Ca","Tsepinite-K","Tsepinite-Na","Tsepinite-Sr","Tsikourasite","Tsilaisite","Tsnigriite","Tsugaruite","Tsumcorite","Tsumebite","Tsumgallite","Tsumoite","Tsygankoite","Tubulite","Tugarinovite","Tugtupite","Tuhualite","Tulameenite","Tuliokite","Tululite","Tumchaite","Tundrite-(Ce)","Tundrite-(Nd)","Tunellite","Tungsten","Tungstenite","Tungstibite","Tungstite","Tungusite","Tunisite","Tuperssuatsiaite","Turanite","Turkestanite","Turneaureite","Turquoise","Turtmannite","Tuscanite","Tusionite","Tuzlaite","Tučekite","Tvedalite","Tveitite-(Y)","Tvrdýite","Tweddillite","Twinnite","Tyretskite","Tyrolite","Tyuyamunite","Tzeferisite","Törnebohmite-(Ce)","Törnebohmite-(La)","Törnroosite","Uakitite","Udinaite","Uduminelite","Uedaite-(Ce)","Uklonskovite","Ulexite","Ulfanderssonite-(Ce)","Ullmannite","Ulrichite","Umangite","Umbite","Umbozerite","Umbrianite","Umohoite","Ungavaite","Ungemachite","Upalite","Uralborite","Uralolite","Uramarsite","Uramphite","Urancalcarite","Uraninite","Uranmicrolite","Uranocircite","Uranocircite I","Uranoclite","Uranophane","Uranopilite","Uranopolycrase","Uranosilite","Uranospathite","Uranosphaerite","Uranospinite","Uranotungstite","Uranpyrochlore","Urea","Uricite","Uroxite","Urphoite","Ursilite","Urusovite","Urvantsevite","Ushkovite","Usovite","Ussingite","Ustarasite","Usturite","Utahite","Uvanite","Uvite","Uytenbogaardtite","Uzonite","Vadlazarenkovite","Vajdakite","Vakhrushevaite","Valentinite","Valleriite","Valleyite","Vallouiseite","Vanackerite","Vanadinite","Vanadio-oxy-chromium-dravite","Vanadio-oxy-dravite","Vanadio-pargasite","Vanadiocarpholite","Vanadium","Vanadoakasakaite-(Ce)","Vanadoakasakaite-(La)","Vanadoallanite-(La)","Vanadoandrosite-(Ce)","Vanadomalayaite","Vanalite","Vanarsite","Vandenbrandeite","Vandendriesscheite","Vanderheydenite","Vandermeerscheite","Vaniniite","Vanmeersscheite","Vanoxite","Vanpeltite","Vantasselite","Vanthoffite","Vanuralite","Vapnikite","Varennesite","Vargite","Varlamoffite","Varulite","Vashegyite","Vasilite","Vasilseverginite","Vasilyevite","Vaterite","Vaughanite","Vauquelinite","Vauxite","Vavřínite","Veatchite","Veatchite-A","Veatchite-p","Veblenite","Veenite","Vegrandisite","Velikite","Vendidaite","Verbeekite","Verbierite","Vergasovaite","Vernadite","Verneite","Verplanckite","Versiliaite","Vertumnite","Veselovskýite","Vestaite","Vesuvianite","Veszelyite","Viaeneite","Vicanite-(Ce)","Vielleaureite-(Ce)","Vigezzite","Vigrishinite","Vihorlatite","Viitaniemiite","Vikingite","Villamanínite","Villiaumite","Villyaellenite","Vimsite","Vincentite","Vinciennite","Vinogradovite","Violarite","Virgilite","Vishnevite","Viskontite","Vismirnovite","Vistepite","Viséite","Viteite","Vitimite","Vittinkiite","Vitusite-(Ce)","Vladimirivanovite","Vladkrivovichevite","Vladkuzminite","Vladykinite","Vlasovite","Vlodavetsite","Vochtenite","Voggite","Voglite","Volaschioite","Volborthite","Volkonskoite","Volkovskite","Voloshinite","Voltaite","Volynskite","Vonbezingite","Vondechenite","Vonsenite","Vorlanite","Voronkovite","Vorontsovite","Voudourisite","Vozhminite","Vrančiceite","Vrbaite","Vránaite","Vuagnatite","Vulcanite","Vuonnemite","Vuorelainenite","Vuoriyarvite-K","Vurroite","Vyacheslavite","Vyalsovite","Vymazalováite","Vysokýite","Vysotskite","Vyuntspakhkite-(Y)","Västmanlandite-(Ce)","Väyrynenite","Vésigniéite","Wadalite","Wadeite","Wagnerite","Waimirite-(Y)","Waipouaite","Wairakite","Wakabayashilite","Wakefieldite-(Ce)","Wakefieldite-(La)","Wakefieldite-(Nd)","Wakefieldite-(Y)","Walentaite","Walfordite","Walkerite","Wallisite","Wallkilldellite","Wallkilldellite-(Fe)","Walpurgite","Walthierite","Wampenite","Wangkuirenite","Wangpuite","Wangxibinite","Wangyanite","Wardite","Wardsmithite","Warikahnite","Warkite","Warwickite","Wassonite","Watanabeite","Watatsumiite","Waterhouseite","Watkinsonite","Wattersite","Wattevilleite","Wavellite","Wawayandaite","Waylandite","Wayneburnhamite","Weberite","Weddellite","Wegscheiderite","Weibullite","Weilerite","Weilite","Weinebeneite","Weishanite","Weissbergite","Weissite","Welinite","Weloganite","Welshite","Wendwilsonite","Wenjiite","Wenkite","Wenlanzhangite-(Y)","Wenqingite","Werdingite","Wermlandite","Wernerbaurite","Wernerkrauseite","Wesselsite","Westerveldite","Wetherillite","Wheat starch","Wheatleyite","Whelanite","Wherryite","Whewellite","Whitecapsite","Whiteite-(CaFeMg)","Whiteite-(CaMgMg)","Whiteite-(CaMnFe)","Whiteite-(CaMnMg)","Whiteite-(CaMnMn)","Whiteite-(MnFeMg)","Whiteite-(MnMnMg)","Whiteite-(MnMnMn)","Whiterockite","Whitmoreite","Whittakerite","Wickenburgite","Wickmanite","Wicksite","Widenmannite","Widgiemoolthalite","Wightmanite","Wiklundite","Wilcoxite","Wildcatite","Wildenauerite","Wilhelmgümbelite","Wilhelmkleinite","Wilhelmramsayite","Wilhelmvierlingite","Wilkmanite","Willemite","Willemseite","Willhendersonite","Wiluite","Winchite","Windhoekite","Windmountainite","Winstanleyite","Wiperamingaite","Wiserite","Wittichenite","Wittite","Wittite B","Witzkeite","Wodegongjieite","Wodginite","Wolfeite","Wolfsriedite","Wollastonite-2M","Wonesite","Woodallite","Woodhouseite","Woodruffite","Woodwardite","Wooldridgeite","Wopmayite","Wortupaite","Wrightite","Wroewolfeite","Wulfenite","Wulffite","Wumuite","Wurtzite","Wuyanzhiite","Wyartite","Wyartite II","Wycheproofite","Wyllieite","Wöhlerite","Wölsendorfite","Wülfingite","Xanthiosite","Xanthoconite","Xanthoxenite","Xenophyllite","Xenotime-(Gd)","Xenotime-(Yb)","Xiangjiangite","Xianhuaite-(Ce)","Xiexiandeite","Xifengite","Xilingolite","Ximengite","Xingzhongite","Xitieshanite","Xocolatlite","Xocomecatlite","Xonotlite","Xuite","Xuwenyuanite","Yafsoanite","Yagiite","Yakhontovite","Yakovenchukite-(Y)","Yakubovichite","Yamhamelachite","Yancowinnaite","Yangzhumingite","Yanomamite","Yarlongite","Yaroshevskite","Yaroslavite","Yarrowite","Yarzhemskiite","Yavapaiite","Yazganite","Ye'elimite","Yeatmanite","Yecoraite","Yedlinite","Yegorovite","Yeite","Yellowcatite","Yeomanite","Yimengite","Yingjiangite","Yixunite","Yoderite","Yofortierite","Yoshimuraite","Yoshiokaite","Yttriaite-(Y)","Yttrialite-(Y)","Yttrium aluminum garnet","Yttrobetafite-(Y)","Yttrocolumbite-(Y)","Yttrocrasite-(Y)","Yttropyrochlore-(Y)","Yttrotantalite-(Y)","Yttrotungstite-(Ce)","Yttrotungstite-(Nd)","Yttrotungstite-(Y)","Yuanfuliite","Yuanjiangite","Yuchuanite-(Y)","Yugawaralite","Yukonite","Yuksporite","Yunhaoite","Yurgensonite","Yurmarinite","Yushkinite","Yusupovite","Yuzuxiangite","Yvonite","Zabuyelite","Zaccagnaite","Zaccariniite","Zadovite","Zaherite","Zakharovite","Zanazziite","Zanelliite","Zangboite","Zapatalite","Zaratite","Zavalíaite","Zavaritskite","Zavyalovite","Zaykovite","Zaïrite","Zdenĕkite","Zektzerite","Zellerite","Zemannite","Zemkorite","Zenzénite","Zeophyllite","Zeravshanite","Zeunerite","Zhanghengite","Zhangpeishanite","Zharchikhite","Zhemchuzhnikovite","Zhengminghuaite","Zhenruite","Zheshengite","Zhiqinite","Zhonghongite","Zhonghuacerite-(Ce)","Ziesite","Zigrasite","Zilbermintsite-(La)","Zimbabweite","Ziminaite","Zinc","Zincalstibite","Zincaluminite","Zinccopperite","Zincgartrellite","Zincite","Zinclipscombite","Zincmelanterite","Zincoberaunite","Zincobotryogen","Zincobradaczekite","Zincobriartite","Zincochenite","Zincochromite","Zincocopiapite","Zincohögbomite-2N2S","Zincohögbomite-2N6S","Zincolibethenite","Zincolivenite","Zincomenite","Zinconigerite-2N1S","Zinconigerite-6N6S","Zincorietveldite","Zincorinmanite-(Zn)","Zincospiroffite","Zincostaurolite","Zincostottite","Zincostrunzite","Zincovelesite-6N6S","Zincovoltaite","Zincowoodwardite","Zincrosasite","Zincroselite","Zincsilite","Zinczippeite","Zinkenite","Zinkgruvanite","Zinkosite","Zinnwaldite","Zippeite","Zipserite","Zircarsite","Zirconolite","Zirconolite-3O","Zirconolite-3T","Zircophyllite","Zircosulfate","Zirkelite","Zirklerite","Ziroite","Zirsilite-(Ce)","Zirsinalite","Zlatogorite","Znamenskyite","Znucalite","Zodacite","Zoharite","Zoisite","Zoisite-(Pb)","Zolenskyite","Zolotarevite","Zoltaiite","Zorite","Zoubekite","Zoyashlyukovaite","Zubkovaite","Zugshunstite-(Ce)","Zuktamrurite","Zunyite","Zuolinite","Zussmanite","Zvyaginite","Zvyagintsevite","Zvĕstovite-(Fe)","Zvĕstovite-(Zn)","Zwieselite","Zálesíite","Zýkaite","ferropseudobrookite","unknown","Ángelaite","Åsgruvanite-(Ce)","Åskagenite-(Nd)","Écrinsite","Örebroite","Čechite","Čejkaite","Černýite","Škáchaite","Šlikite","Šreinite","Štěpite","Švenekite","Żabińskiite");
//
//            $params = array('dt_id' => 736, 7036 => '!pyroxene', '7062' => "*1094,*1104", 'set' => '1');  // should return (6534-60)=6474 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCI3MDM2IjoiIXB5cm94ZW5lIiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCI3MDM2IjoiIXB5cm94ZW5lIiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//            $array_not_pyroxene = array("Abellaite","Abelloemringerite","Abelsonite","Abenakiite-(Ce)","Abernathyite","Abhurite","Abramovite","Abswurmbachite","Abuite","Acanthite","Acetamide","Acetaminophen","Achalaite","Achyrophanite","Achávalite","Acmonidesite","Acuminite","Adachiite","Adamite","Adamsite-(Y)","Adanite","Addibischoffite","Adelite","Admontite","Adolfpateraite","Adranosite","Adranosite-(Fe)","Adrianite","Aenigmatite","Aerinite","Aerugite","Aeschynite-(Ce)","Aeschynite-(Nd)","Aeschynite-(Y)","Afghanite","Afmite","Afwillite","Agaite","Agakhanovite-(Y)","Agardite-(Ce)","Agardite-(La)","Agardite-(Nd)","Agardite-(Y)","Agmantinite","Agrellite","Agricolaite","Agrinierite","Aguilarite","Aheylite","Ahlfeldite","Ahrensite","Aikinite","Aiolosite","Airdite","Ajoite","Akaganeite","Akaogiite","Akasakaite-(Ce)","Akasakaite-(La)","Akatoreite","Akdalaite","Akhtenskite","Akimotoite","Aklimaite","Akopovaite","Akrochordite","Aksaite","Aktashite","AlSO4(OH)","Alabandite","Alacránite","Alamosite","Alarsite","Albertiniite","Albite","Albrechtschraufite","Albrittonite","Alburnite","Alcantarillaite","Alcaparrosaite","Aldermanite","Aldomarinoite","Aldridgeite","Aleksandrovite","Aleksite","Aleutite","Alexearlite","Alexkhomyakovite","Alexkuznetsovite-(Ce)","Alexkuznetsovite-(La)","Alflarsenite","Alforsite","Alfredcasparite","Alfredopetrovite","Alfredstelznerite","Algodonite","Alicewilsonite-(YCe)","Alicewilsonite-(YLa)","Aliettite","Allabogdanite","Allactite","Allanite-(Ce)","Allanite-(La)","Allanite-(Nd)","Allanite-(Sm)","Allanite-(Y)","Allanpringite","Allantoin","Allargentum","Alleghanyite","Allendeite","Allochalcoselite","Alloclasite","Allophane","Alloriite","Alluaivite","Alluaudite","Almagreraite","Almandine","Almarudite","Almeidaite","Alnaperbøeite-(Ce)","Alpeite","Alpersite","Alpha-D lactose monohydrate","Alsakharovite-Zn","Alstonite","Altaite","Alterite","Althausite","Althupite","Altisite","Alum-(K)","Alum-(Na)","Aluminite","Aluminium","Alumino-ferrobarroisite","Alumino-ferrohornblende","Alumino-ferrotschermakite","Alumino-ferrowinchite","Alumino-magnesiohornblende","Alumino-magnesiotaramite","Alumino-ottoliniite","Alumino-oxy-rossmanite","Aluminobarroisite","Aluminoceladonite","Aluminocerite-(CeCa)","Aluminocopiapite","Aluminocoquimbite","Aluminokatophorite","Aluminomagnesiohulsite","Aluminopyracmonite","Aluminosugilite","Aluminotaipingite-(CeCa)","Aluminotschermakite","Aluminowinchite","Alumoedtollite","Alumohydrocalcite","Alumoklyuchevskite","Alumolukrahnite","Alumotantite","Alumotungstite","Alumovesuvianite","Alumoåkermanite","Alunite","Alunogen","Alvanite","Alvesite","Alwilkinsite-(Y)","Amableite-(Ce)","Amakinite","Amamoorite","Amarantite","Amarillite","Amaterasuite","Amber","Amblygonite","Amblygonite-series","Ambrinoite","Ameghinite","Amesite","Amgaite","Amicite","Aminoffite","Ammineite","Ammonioalunite","Ammonioborite","Ammoniojarosite","Ammoniolasalite","Ammonioleucite","Ammoniomagnesiovoltaite","Ammoniomathesiusite","Ammoniotinsleyite","Ammoniovoltaite","Ammoniozippeite","Ammonium hazenite","Amoraite","Amstallite","Amurselite","Analcime","Anandite","Anapaite","Anastasenkoite","Anatacamite","Anatase","Anatolygurbanovite","Anatolyite","Ancylite-(Ce)","Ancylite-(La)","Andalusite","Andersonite","Andesine","Andradite","Andreadiniite","Andreybulakhite","Andreyivanovite","Andrianovite","Andrieslombaardite","Andrémeyerite","Anduoite","Andychristyite","Andymcdonaldite","Andyrobertsite","Angarfite","Angastonite","Angelellite","Anglesite","Anhydrite","Anhydrite-Mg-beta","Anhydrokainite","Anilite","Aniyunwiyaite","Ankangite","Ankerite","Ankinovichite","Annabergite","Anningite-(Ce)","Annite","Annivite","Annivite-(Zn)","Anorpiment","Anorthoclase","Anorthominasragrite","Anorthoroselite","Anorthoyttrialite-(Y)","Ansermetite","Antarcticite","Anthoinite","Anthonyite","Anthophyllite","Anthraxolite","Antimonpearceite","Antimonselite","Antimony","Antipinite","Antipovite","Antitaenite","Antlerite","Antofagastaite","Anyuiite","Anzaite-(Ce)","Apachite","Apatite-series","Apexite","Aphthitalite","Apjohnite","Aplowite","Apophyllite-series","Apuanite","Aqualite","Aradite","Aragonite","Arakiite","Aramayoite","Arangasite","Arapovite","Aravaipaite","Aravaite","Arcanite","Archerite","Arctite","Arcubisite","Ardaite","Ardealite","Ardennite-(As)","Ardennite-(V)","Arfvedsonite","Argandite","Argentobaumhauerite","Argentodufrénoysite","Argentojarosite","Argentoliveingite","Argentopearceite","Argentopentlandite","Argentopolybasite","Argentopyrite","Argentotennantite-(Fe)","Argentotennantite-(Zn)","Argentotetrahedrite-(Cd)","Argentotetrahedrite-(Fe)","Argentotetrahedrite-(Hg)","Argentotetrahedrite-(Zn)","Argesite","Argutite","Argyrodite","Arhbarite","Ariegilatite","Arisite-(Ce)","Arisite-(La)","Aristarainite","Armalcolite","Armangite","Armbrusterite","Armellinoite-(Ce)","Armenite","Armstrongite","Arnhemite","Arrheniusite-(Ce)","Arrojadite-(BaNa)","Arrojadite-(KFe)","Arrojadite-(KNa)","Arrojadite-(NaFe)","Arrojadite-(PbFe)","Arrojadite-(SrFe)","Arsenatrotitanite","Arsenbrackebuschite","Arsendescloizite","Arsenic","Arseniopleite","Arseniosiderite","Arsenmarcobaldiite","Arsenmedaite","Arsenobenauite","Arsenoclasite","Arsenocrandallite","Arsenoflorencite-(Ce)","Arsenoflorencite-(La)","Arsenoflorencite-(Nd)","Arsenogoldfieldite","Arsenogorceixite","Arsenogoyazite","Arsenohauchecornite","Arsenohopeite","Arsenolamprite","Arsenolite","Arsenopalladinite","Arsenopyrite","Arsenosabugalite","Arsenotučekite","Arsenovanmeersscheite","Arsenoveszelyite","Arsenowagnerite","Arsenowaylandite","Arsenoústalečite","Arsenpolybasite","Arsenquatrandorite","Arsentsumebite","Arsenudinaite","Arsenuranospathite","Arsenuranylite","Arsiccioite","Arsmirandite","Artemether","Artemisinin","Artesunate","Arthurite","Artinite","Artroeite","Artsmithite","Arupite","Arzakite","Arzamastsevite","Arzrunite","Asagiite","Asbecasite","Asbolane","Aschamalmite","Ashburtonite","Ashcroftine-(Y)","Ashoverite","Asimowite","Asisite","Aspedamite","Aspidolite","Aspirin","Asselbornite","Astrocyanite-(Ce)","Astrophyllite","Atacamite","Atelestite","Atelisite-(Y)","Atencioite","Atenolol","Athabascaite","Atheneite","Atlasovite","Atokite","Atorvastatin","Attakolite","Attikaite","Aubertite","Auerbakhite","Augelite","Auriacusite","Aurichalcite","Auricupride","Aurihydrargyrumite","Aurivilliusite","Auroantimonate","Auropearceite","Auropolybasite","Aurorite","Auroselenide","Aurostibite","Austinite","Autunite","Avdeevite","Avdoninite","Averievite","Avicennite","Avogadrite","Awaruite","Axelite","Axinite","Axinite-(Fe)","Axinite-(Mg)","Axinite-(Mn)","Axinite-series","Azoproite","Azurite","Babefphite","Babingtonite","Babkinite","Babunaite-(Nd)","Babánekite","Bacaferrite","Backite","Badakhshanite-(Y)","Badalovite","Baddeleyite","Badengzhuite","Bafertisite","Baghdadite","Bahariyaite","Bahianite","Baiamareite","Baileychlore","Bainbridgeite-(NdCe)","Bainbridgeite-(YCe)","Bairdite","Bakakinite","Bakerite","Bakhchisaraitsevite","Baksanite","Balangeroite","Balestraite","Balipholite","Balićžunićite","Balkanite","Balliranoite","Balyakinite","Bambollaite","Bamfordite","Banalsite","Bandylite","Bannermanite","Bannisterite","Baotite","Barahonaite-(Al)","Barahonaite-(Fe)","Bararite","Baratovite","Barberiite","Barbertonite","Barbosalite","Barentsite","Bariandite","Barikaite","Bario-olgite","Bario-orthojoaquinite","Barioferrite","Bariolakargiite","Bariomicrolite","Barioperovskite","Bariopharmacoalumite","Bariopharmacosiderite","Bariopyrochlore","Bariosincosite","Barium-zinc alumopharmocosiderite","Barićite","Barkovite","Barlowite","Barnesite","Barquillite","Barrerite","Barringerite","Barringtonite","Barroisite","Barronite","Barrotite","Barrydawsonite-(Y)","Barstowite","Bartelkeite","Bartonite","Barwoodite","Barylite","Barysilite","Baryte","Barytocalcite","Barytolamprophyllite","Bassanite","Bassetite","Bassoite","Bastnasite-series","Bastnäsite-(Ce)","Bastnäsite-(La)","Bastnäsite-(Nd)","Bastnäsite-(Y)","Batagayite","Batievaite-(Y)","Batiferrite","Batisite","Batisivite","Batoniite","Baumhauerite","Baumhauerite II","Baumoite","Baumstarkite","Bauranoite","Bavenite","Bavsiite","Bayanoboite-(Y)","Bayerite","Bayldonite","Bayleyite","Baylissite","Bazhenovite","Bazirite","Bazzite","Bearsite","Bearthite","Beaverite-(Cu)","Beaverite-(Zn)","Bechererite","Beckettite","Becquerelite","Bederite","Beershevaite","Behoite","Beidellite","Belakovskiite","Belendorffite","Belkovite","Bellbergite","Bellidoite","Bellingerite","Belloite","Belmonteite","Belogubite","Belomarinaite","Belousovite","Belovite-(Ce)","Belovite-(La)","Belyankinite","Bementite","Benauite","Benavidesite","Bendadaite","Benitoite","Benjaminite","Benleonardite","Bennesherite","Benstonite","Bentorite","Benyacarite","Beraunite","Berborite","Berdesinskiite","Berezanskite","Bergbauerite","Bergenite","Bergslagite","Berlinite","Bermanite","Bernalite","Bernardevansite","Bernardite","Bernarlottiite","Berndlehmannite","Berndtite","Berndtite-4H","Berryite","Berthierine","Berthierite","Bertossaite","Bertrandite","Beryl","Beryllite","Beryllocordierite-Na","Beryllonite","Berzelianite","Berzeliite","Beshtauite","Beta - iridisite","Betekhtinite","Betpakdalite-CaCa","Betpakdalite-CaMg","Betpakdalite-FeFe","Betpakdalite-NaCa","Betpakdalite-NaNa","Bettertonite","Betzite","Beudantite","Beusite","Beusite-(Ca)","Beyerite","Bezsmertnovite","Biachellaite","Biagioniite","Bianchiniite","Bianchite","Bicapite","Bicchulite","Bideauxite","Bieberite","Biehlite","Bigcreekite","Bijvoetite-(Y)","Bikitaite","Bilibinskite","Billietite","Billingsleyite","Billwiseite","Bimbowrieite","Bindheimite","Biphosphammite","Biraite-(Ce)","Biraite-(La)","Birchite","Biringuccite","Birnessite","Birunite","Bisbeeite","Bischofite","Bismite","Bismoclite","Bismuth","Bismuthinite","Bismutite","Bismutocolumbite","Bismutoferrite","Bismutohauchecornite","Bismutomicrolite","Bismutopyrochlore","Bismutostibiconite","Bismutotantalite","Bitikleite","Bityite","Bixbyite-(Fe)","Bixbyite-(Mn)","Bjarebyite","Blakeite","Blatonite","Blatterite","Bleasdaleite","Blixite","Blodite-series","Blossite","Bluebellite","Bluelizardite","Blueridgeite","Bluestreakite","Blödite","Bobcookite","Bobdownsite","Bobfergusonite","Bobfinchite","Bobierrite","Bobjonesite","Bobkingite","Bobmeyerite","Bobshannonite","Bobtraillite","Bodieite","Boevskite","Bogdanovite","Boggsite","Bohdanowiczite","Bohseite","Bohuslavite","Bojarite","Bokite","Boleite","Bolivarite","Bolotinaite","Boltwoodite","Bonaccordite","Bonaccorsiite","Bonacinaite","Bonattite","Bonazziite","Bonshtedtite","Boojumite","Boothite","Boracite","Boralsilite","Borax","Borcarite","Borisenkoite","Borishanskiite","Bornemanite","Bornhardtite","Bornite","Borocookeite","Borodaevite","Boromullite","Boromuscovite","Borovskite","Bortnikovite","Bortolanite","Borzęckiite","Boscardinite","Bosiite","Bosoite","Bostwickite","Botallackite","Botryogen","Bottinoite","Botuobinskite","Bouazzerite","Boulangerite","Bounahasite","Bournonite","Boussingaultite","Bouškaite","Bowieite","Bowlesite","Boyleite","Brabantite","Braccoite","Bracewellite","Brackebuschite","Bradaczekite","Bradleyite","Braggite","Braithwaiteite","Braitschite-(Ce)","Branchite","Brandholzite","Brandtite","Brandãoite","Brannerite","Brannockite","Brass","Brassite","Brattforsite","Braunerite","Braunite","Brazilianite","Brearleyite","Bredigite","Breithauptite","Brendelite","Brenkite","Brewsterite-Ba","Brewsterite-Sr","Breyite","Brezinaite","Brianite","Brianroulstonite","Brianyoungite","Briartite","Bridgesite-(Ce)","Bridgmanite","Brindleyite","Brinrobertsite","Britholite-(Ce)","Britholite-(Y)","Britvinite","Brizziite","Brochantite","Brockite","Brodtkorbite","Brokenhillite","Bromargyrite","Bromellite","Brontesite","Brookite","Browneite","Brownleeite","Brownmillerite","Brucite","Brugnatellite","Brumadoite","Brunogeierite","Brunovskyite","Brushite","Brusnitsynite","Brüggenite","Bubnovaite","Buchwaldite","Buckhornite","Buddingtonite","Bukovite","Bukovskýite","Bulachite","Bulgakite","Bultfonteinite","Bunnoite","Bunsenite","Burangaite","Burbankite","Burckhardtite","Burgessite","Burkeite","Burnettite","Burnsite","Burovaite-Ca","Burpalite","Burroite","Burtite","Buryatite","Buseckite","Buserite","Bushmakinite","Bussenite","Bussyite-(Ce)","Bussyite-(Y)","Bustamite-series","Butianite","Butlerite","Buttgenbachite","Buynite","Byelorussite-(Ce)","Bykovaite","Byrudite","Bystrite","Byströmite","Bytownite","Bytízite","Byzantievite","Béhierite","Bílinite","Böhmite","Bøggildite","Bøgvadite","Bütschliite","Běhounekite","CO3-SO4 - hydrotalcite - 18.5Å","CaLiBO3","Cabalzarite","Cabrerite","Cabriite","Cabvinite","Cacoxenite","Cadmium","Cadmoindite","Cadmoselite","Cadmoxite","Cadsulfohite","Cadvanite","Cadwaladerite","Caesiumpharmacosiderite","Cafarsite","Cafeosite","Cafetite","Caffeine","Cahnite","Caichengyunite","Cairncrossite","Calamaite","Calaverite","Calciborite","Calcinaksite","Calcio-olivine","Calcioancylite-(Ce)","Calcioancylite-(La)","Calcioancylite-(Nd)","Calcioandyrobertsite","Calcioaravaipaite","Calciobetafite","Calcioburbankite","Calciocatapleiite","Calciocopiapite","Calciodelrioite","Calcioferrite","Calciohatertite","Calciohilairite","Calciojohillerite","Calciolangbeinite","Calciomurmanite","Calciopetersite","Calciopharmacoalumite","Calciosamarskite","Calciotantite","Calciouranoite","Calcioursilite","Calcioveatchite","Calcjarlite","Calclacite","Calcurmolite","Calcybeborosilite-(Y)","Calderite","Calderónite","Caledonite","Calkinsite-(Ce)","Callaghanite","Calomel","Calumetite","Calvertite","Calzirtite","Camanchacaite","Camaronesite","Cameronite","Camgasite","Caminite","Campigliaite","Campostriniite","Camérolaite","Canaphite","Canasite","Canavesite","Cancrinite","Cancrisilite","Canfieldite","Cannilloite","Cannizzarite","Cannonite","Canosioite","Canutite","Caoxite","Capgaronnite","Cappelenite-(Y)","Capranicaite","Caracolite","Carboborite","Carbobystrite","Carbocalumite","Carbocernaite","Carboferriphoxite","Carboirite","Carbokentbrooksite","Carbonate-fluorapatite","Carbonate-hydroxylapatite","Carbonatecyanotrichite","Cardite","Carducciite","Caresite","Carletonite","Carletonmooreite","Carlfrancisite","Carlfriesite","Carlgieseckeite-(Nd)","Carlhintzeite","Carlinite","Carlosbarbosaite","Carlosruizite","Carlosturanite","Carlsbergite","Carlsonite","Carmeltazite","Carmichaelite","Carminite","Carnallite","Carnotite","Carobbiite","Carpathite","Carraraite","Carrboydite","Carrollite","Caryinite","Caryochroite","Caryopilite","Cascandite","Caseyite","Cassagnaite","Cassedanneite","Cassidyite","Cassiterite","Castellaroite","Caswellsilverite","Catalanoite","Catamarcaite","Catapleiite","Cattierite","Cattiite","Cavansite","Cavoite","Cayalsite-(Y)","Caysichite-(Y)","Cebaite-(Ce)","Cebaite-(Nd)","Cebollite","Celadonite","Celestine","Celleriite","Celsian","Centennialite","Cerchiaraite-(Al)","Cerchiaraite-(Fe)","Cerchiaraite-(Mn)","Cerianite-(Ce)","Ceriopyrochlore-(Ce)","Cerite-(CeCa)","Cerium","Cerromojonite","Ceruleite","Cerussite","Cervandonite-(Ce)","Cervantite","Cervelleite","Cesanite","Cesbronite","Cesiodymite","Cesiokenopyrochlore","Cesplumtantite","Cesàrolite","Cetineite","Chabazite-Ca","Chabazite-K","Chabazite-Mg","Chabazite-Na","Chabazite-Sr","Chabournéite","Chadwickite","Chaidamuite","Chalcanthite","Chalcoalumite","Chalcocite","Chalcocyanite","Chalcomenite","Chalconatronite","Chalcophanite","Chalcophyllite","Chalcopyrite","Chalcosiderite","Chalcostibite","Chalcothallite","Challacolloite","Chambersite","Chamosite","Chaméanite","Chanabayaite","Changbaiite","Changchengite","Changesite-(Y)","Changoite","Chantalite","Chaoite","Chapmanite","Charleshatchettite","Charlesite","Charmarite","Charmarite-3T","Charoite","Chatkalite","Chayesite","Chegemite","Chekhovichite","Chelkarite","Chenevixite","Chengdeite","Chenguodaite","Chenite","Chenmingite","Chenowethite","Chenxianite","Chenzhangruite","Cheralite","Cheremnykhite","Cherepanovite","Chernikovite","Chernovite-(Y)","Chernykhite","Cherokeeite","Chervetite","Chesnokovite","Chessexite","Chesterite","Chestermanite","Chevkinite-(Ce)","Chiappinoite-(Y)","Chiavennite","Chibaite","Chihmingite","Chihuahuaite","Childrenite","Chiluite","Chinchorroite","Chinleite-(Ce)","Chinleite-(Nd)","Chinleite-(Y)","Chinnerite","Chiolite","Chirvinskyite","Chistyakovaite","Chivruaiite","Chiyokoite","Chkalovite","Chladniite","Chloraluminite","Chlorapatite","Chlorargyrite","Chlorartinite","Chlorbartonite","Chlorellestadite","Chloritoid","Chlorkyuygenite","Chlormagaluminite","Chlormanganokalite","Chlormayenite","Chloro-potassic-ferro-edenite","Chlorocalcite","Chloromagnesite","Chloromenite","Chlorophoenicite","Chloroquine diphosphate","Chlorothionite","Chloroxiphite","Choloalite","Chondrodite","Chongite","Chopinite","Chorążewiczite","Chovanite","Chrisstanleyite","Christelite","Christite","Christofschäferite-(Ce)","Chromatite","Chrombismite","Chromceladonite","Chromferide","Chromio-pargasite","Chromite","Chromium","Chromium-dravite","Chromo-alumino-povondraite","Chromphyllite","Chromschieffelinite","Chromviskontite","Chrysoberyl","Chrysocolla","Chrysothallite","Chrysotile","Chubarovite","Chudobaite","Chukanovite","Chukhrovite-(Ca)","Chukhrovite-(Ce)","Chukhrovite-(Nd)","Chukhrovite-(Y)","Chukochenite","Chukotkaite","Churchite-(Nd)","Churchite-(Y)","Chursinite","Chvaleticeite","Chvilevaite","Cianciulliite","Cinnabar","Ciprianiite","Ciriottiite","Cirrolite","Clairite","Claraite","Claringbullite","Clarkeite","Claudetite","Clausthalite","Clearcreekite","Clerite","Cleusonite","Cliffordite","Clino-ferri-holmquistite","Clino-ferro-ferri-holmquistite","Clino-ferro-suenoite","Clino-oscarkempffite","Clino-suenoite","Clinoatacamite","Clinobarylite","Clinobehoite","Clinobisvanite","Clinocervantite","Clinochalcomenite","Clinochlore","Clinoclase","Clinofergusonite-(Ce)","Clinofergusonite-(Nd)","Clinofergusonite-(Y)","Clinoferroholmquistite","Clinohedrite","Clinohumite","Clinojimthompsonite","Clinokurchatovite","Clinometaborite","Clinophosinaite","Clinoptilolite-Ca","Clinoptilolite-K","Clinoptilolite-Na","Clinosafflorite","Clinosulphur","Clinotobermorite","Clinotyrolite","Clinoungemachite","Clinozoisite","Clintonite","Clogauite","Cloncurryite","Cloudite","Coalingite","Cobaltarthurite","Cobaltaustinite","Cobaltite","Cobaltkieserite","Cobaltkoritnigite","Cobaltlotharmeyerite","Cobaltneustädtelite","Cobaltoblödite","Cobaltogordaite","Cobaltomenite","Cobaltpentlandite","Cobalttsumcorite","Cobaltzippeite","Coccinite","Cochromite","Coconinoite","Coesite","Coffinite","Cohenite","Coiraite","Colchesterite","Colchicine","Coldwellite","Colemanite","Colimaite","Colinowensite","Collinsite","Colomeraite","Coloradoite","Colquiriite","Columbite-(Fe)","Columbite-(Mg)","Columbite-(Mn)","Colusite","Comancheite","Combeite","Comblainite","Compreignacite","Congolite","Conichalcite","Connellite","Cookeite","Coombsite","Cooperite","Coparsite","Copiapite","Copper","Copper(II) tetraammine nitrate","Coquandite","Coquimbite","Coralloite","Corderoite","Cordierite","Cordylite-(Ce)","Cordylite-(La)","Corkite","Cornetite","Cornubite","Cornwallite","Coronadite","Correianevesite","Corrensite","Cortesognoite","Corundum","Corvusite","Cosalite","Coskrenite-(Ce)","Cossaite","Costibite","Cotunnite","Coulsonite","Cousinite","Coutinhoite","Covellite","Cowlesite","Coyoteite","Crandallite","Cranswickite","Crawfordite","Creaseyite","Crednerite","Creedite","Crerarite","Crichtonite","Criddleite","Crimsonite","Cristobalite","Crocobelonite","Crocoite","Cronstedtite","Cronusite","Crookesite","Crowningshieldite","Cryobostryxite","Cryolite","Cryolithionite","Cryptochalcite","Cryptohalite","Cryptomelane","Cryptophyllite","CuZnCl(OH)_3_","Cualstibite","Cuatrocapaite-(K)","Cuatrocapaite-(NH_4_)","Cubanite","Cubic zirconia","Cubo-ice","Cuboargyrite","Cubothioplumbite","Cumengeite","Cummingtonite","Cupalite","Cuprite","Cuproauride","Cuprobismutite","Cuprocherokeeite","Cuprocopiapite","Cuprodobrovolskyite","Cuprodongchuanite","Cuproiridsite","Cuprokalininite","Cupromakopavonite","Cupromakovickyite","Cupromolybdite","Cuproneyite","Cupropavonite","Cupropearceite","Cupropolybasite","Cuprorhodsite","Cuprorivaite","Cuprosenandorite","Cuprosklodowskite","Cuprospinel","Cuprostibite","Cuprotungstite","Cuprozheshengite","Curetonite","Curienite","Curite","Currierite","Cuspidine","Cuyaite","Cuzticite","Cyanochroite","Cyanophyllite","Cyanotrichite","Cylindrite","Cymrite","Cyprine","Cyrilovite","Czochralskiite","Cámaraite","Césarferreiraite","D'ansite","D'ansite-(Fe)","D'ansite-(Mn)","Dachiardite-Ca","Dachiardite-K","Dachiardite-Na","Dacostaite","Dadsonite","Dagenaisite","Daliranite","Dalnegorskite","Dalnegroite","Dalyite","Damaraite","Damiaoite","Danalite","Danbaite","Danburite","Danielsite","Dantopaite","Daomanite","Daqingshanite-(Ce)","Darapiosite","Darapskite","Dargaite","Darrellhenryite","Dashkovaite","Datolite","Daubréeite","Daubréelite","Davanite","Davemaoite","Davidbrownite-(NH_4_)","Davidite-(Ce)","Davidite-(La)","Davidite-(Y)","Davidlloydite","Davidsmithite","Davinciite","Davreuxite","Davyne","Dawsonite","Deanesmithite","Debattistiite","Decagonite","Decrespignyite-(Y)","Deerite","Defernite","Dekatriasartorite","Delafossite","Delchiaroite","Delhayelite","Delhuyarite-(Ce)","Deliensite","Delindeite","Dellagiustaite","Dellaite","Deloneite","Deloryite","Delrioite","Deltalumite","Deltanitrogen","Delvauxite","Demagistrisite","Demartinite","Demesmaekerite","Demicheleite-(Br)","Demicheleite-(Cl)","Demicheleite-(I)","Dendoraite-(NH_4_)","Denisovite","Denningite","Depmeierite","Derbylite","Derriksite","Dervillite","Desautelsite","Descloizite","Despujolsite","Dessauite-(Y)","Destinezite","Deveroite-(Ce)","Devilliersite","Devilline","Devitoite","Deweylite","Dewindtite","Dewitite","Deynekoite","Diaboleite","Diadochite","Diamond","Diaoyudaoite","Diaphorite","Diaspore","Dickinsonite-(KMnNa)","Dickite","Dickthomssenite","Diegogattaite","Dienerite","Dietrichite","Dietzeite","Digenite","Dimorphite","Dingdaohengite-(Ce)","Dinilawiite","Dinite","Diomignite","Dioptase","Dioskouriite","Direnzoite","Dissakisite-(Ce)","Dissakisite-(La)","Disulfodadsonite","Dittmarite","Diversilite-(Ce)","Dixenite","Djerfisherite","Djurleite","Dmisokolovite","Dmisteinbergite","Dmitryivanovite","Dmitryvarlamovite","Dobrovolskyite","Dobšináite","Dokuchaevite","Dolerophanite","Dollaseite-(Ce)","Dolomite","Doloresite","Domerockite","Domeykite","Domitrovicite","Donbassite","Dondoellite","Dongchuanite","Donharrisite","Donnayite-(Y)","Donowensite","Donwilhelmsite","Dorallcharite","Dorfmanite","Dorrite","Douglasite","Dovyrenite","Downeyite","Downsite","Doyleite","Dozyite","Dravertite","Dravite","Drechslerite","Dresserite","Dreyerite","Driekopite","Dritsite","Drobecite","Droninoite","Drugmanite","Drysdallite","Dualite","Dubińskaite","Dufrénite","Dufrénoysite","Duftite","Dugganite","Dukeite","Dulanggouite","Dumontite","Dumortierite","Dundasite","Durangite","Duranusite","Dusmatovite","Dussertite","Dutkevichite-(Ce)","Dutrowite","Duttonite","Dwornikite","Dymkovite","Dypingite","Dyrnaesite-(La)","Dyscrasite","Dzhalindite","Dzharkenite","Dzhuluite","Dzierżanowskite","Désorite","Eakerite","Earlandite","Earlshannonite","Eastonite","Ebnerite","Ecandrewsite","Ecdemite","Eckerite","Eckermannite","Eckhardite","Eclarite","Eddavidite","Edenharterite","Edenite","Edgarbaileyite","Edgarite","Edgrewite","Edingtonite","Edoylerite","Edscottite","Edtollite","Edwardsite","Edwindavisite","Effenbergerite","Efremovite","Eggletonite","Eglestonite","Ehrigite","Ehrleite","Eifelite","Eirikite","Eitelite","Ekanite","Ekaterinite","Ekatite","Ekebergite","Ekplexite","Elaliite","Elasmochloite","Elbaite","Elbrusite","Eldfellite","Eldragónite","Electrum","Eleomelanite","Eleonorite","Elgoresyite","Eliopoulosite","Eliseevite","Elkinstantonite","Ellenbergerite","Ellestadite-(Cl)","Ellinaite","Ellingsenite","Elliottite","Ellisite","Elpasolite","Elpidite","Elramlyite-(Ce)","Eltyubyuite","Elyite","Embreyite","Emeleusite","Emilite","Emmerichite","Emmonsite","Emplectite","Empressite","Enargite","Engelhauptite","Englishite","Enneasartorite","Enricofrancoite","Eosphorite","Ephesite","Epididymite","Epidote","Epidote-(Sr)","Epiebnerite","Epifanovite","Epistilbite","Epistolite","Epsomite","Erazoite","Ercitite","Erdite","Ericaite","Ericlaxmanite","Ericssonite","Erikapohlite","Erikjonssonite","Eringaite","Eriochalcite","Erionite-Ca","Erionite-K","Erionite-Na","Erlianite","Erlichmanite","Ermakovite","Ermeloite","Ernienickelite","Erniggliite","Ernstburkeite","Ernstite","Ershovite","Erssonite","Ertixiite","Ertlite","Erythrite","Erythrosiderite","Erzwiesite","Escheite","Esdanaite-(Ce)","Eskebornite","Eskimoite","Eskolaite","Espadaite","Esperanzaite","Esperite","Esquireite","Eta - bronze","Ettringite","Eucairite","Euchlorine","Euchroite","Euclase","Eucryptite","Eudialyte","Eudidymite","Eugenite","Eugsterite","Eulytine","Eurekadumpite","Euxenite-(Y)","Evanichite","Evansite","Evdokimovite","Eveite","Evenkite","Eveslogite","Evseevite","Ewaldite","Ewingite","Eylettersite","Eyselite","Ezcurrite","Ezochiite","Eztlite","Fabianite","Fabritzite","Fabrièsite","Faheyite","Fahleite","Fairbankite","Fairchildite","Fairfieldite","Faizievite","Falcondoite","Falgarite","Falkmanite","Falottaite","Falsterite","Famatinite","Fanfaniite","Fangite","Fanguangite","Fantappièite","Farneseite","Farringtonite","Fassinaite","Faujasite-Ca","Faujasite-Mg","Faujasite-Na","Faustite","Favreauite","Fe_2_SiO_4_spinel","Fedorite","Fedorovskite","Fedotovite","Fehrite","Feiite","Feinglosite","Feitknechtite","Fejerite","Feklichevite","Felbertalite","Felsőbányaite","Fenaksite","Fencooperite","Fengchengite","Fengruiite","Feodosiyite","Ferberite","Ferchromide","Ferdisilicite","Ferdowsiite","Fergusonite-(Ce)","Fergusonite-(Nd)","Fergusonite-(Y)","Ferhodsite","Fermiite","Fernandinite","Feroxyhyte","Ferraioloite","Ferrarisite","Ferri-barroisite","Ferri-ferrobarroisite","Ferri-ferrotschermakite","Ferri-ferrowinchite","Ferri-fluoro-katophorite","Ferri-fluoro-leakeite","Ferri-ghoseite","Ferri-hellandite-(Ce)","Ferri-kaersutite","Ferri-katophorite","Ferri-leakeite","Ferri-magnesiokatophorite","Ferri-magnesiotaramite","Ferri-mottanaite-(Ce)","Ferri-obertiite","Ferri-ottoliniite","Ferri-pedrizite","Ferri-taramite","Ferri-tschermakite","Ferri-winchite","Ferriakasakaite-(Ce)","Ferriakasakaite-(La)","Ferriallanite-(Ce)","Ferriallanite-(La)","Ferriandrosite-(Ce)","Ferriandrosite-(La)","Ferribushmakinite","Ferric-nybøite","Ferricerite-(LaCa)","Ferricopiapite","Ferricoronadite","Ferrierite-K","Ferrierite-Mg","Ferrierite-NH_4_","Ferrierite-Na","Ferrihollandite","Ferrihydrite","Ferrilotharmeyerite","Ferrimolybdite","Ferrimuirite","Ferrinatrite","Ferriperbøeite-(Ce)","Ferriperbøeite-(La)","Ferriphoxite","Ferriprehnite","Ferripyrophyllite","Ferrirockbridgeite","Ferrisanidine","Ferrisepiolite","Ferrisicklerite","Ferristrunzite","Ferrisurite","Ferrisymplesite","Ferritaramite","Ferritungstite","Ferrivauxite","Ferriwhittakerite","Ferro-actinolite","Ferro-anthophyllite","Ferro-bosiite","Ferro-eckermannite","Ferro-edenite","Ferro-ferri-fluoro-leakeite","Ferro-ferri-holmquistite","Ferro-ferri-hornblende","Ferro-ferri-katophorite","Ferro-ferri-nybøite","Ferro-ferri-obertiite","Ferro-ferri-pedrizite","Ferro-fluoro-edenite","Ferro-fluoro-pedrizite","Ferro-gedrite","Ferro-glaucophane","Ferro-holmquistite","Ferro-hornblende","Ferro-katophorite","Ferro-papikeite","Ferro-pargasite","Ferro-pedrizite","Ferro-richterite","Ferro-taramite","Ferro-tschermakite","Ferroalluaudite","Ferroaluminoceladonite","Ferrobarroisite","Ferroberaunite","Ferrobobfergusonite","Ferrobustamite","Ferrocarpholite","Ferroceladonite","Ferrochiavennite","Ferrodimolybdenite","Ferroefremovite","Ferroericssonite","Ferrofettelite","Ferrohexahydrite","Ferrohögbomite-2N2S","Ferroindialite","Ferroinnelite","Ferrokaersutite","Ferrokentbrooksite","Ferrokinoshitalite","Ferrokësterite","Ferrolaueite","Ferroleakeite","Ferromerrillite","Ferronickelplatinum","Ferronigerite-2N1S","Ferronigerite-6N6S","Ferronordite-(Ce)","Ferronordite-(La)","Ferronybøite","Ferropedrizite","Ferroqingheiite","Ferrorhodonite","Ferrorhodsite","Ferrorockbridgeite","Ferrorosemaryite","Ferrosaponite","Ferroselite","Ferroskutterudite","Ferrostalderite","Ferrostrunzite","Ferrotaaffeite-2N'2S","Ferrotaaffeite-6N'3S","Ferrotellurite","Ferrotitanowodginite","Ferrotochilinite","Ferrotorryweiserite","Ferrotschermakite","Ferrotychite","Ferrovalleriite","Ferrovorontsovite","Ferrowinchite","Ferrowodginite","Ferrowyllieite","Ferroåkermanite","Ferruccite","Fersilicite","Fersmanite","Fersmite","Feruvite","Fervanite","Fetiasite","Fettelite","Feynmanite","Fianelite","Fibroferrite","Fichtelite","Fiedlerite","Fiemmeite","Filatovite","Filipstadite","Fillowite","Finchite","Finescreekite","Fingerite","Finnemanite","Fischesserite","Fivegite","Fizélyite","Flaggite","Flagstaffite","Flamite","Fleetite","Fleischerite","Fleisstalite","Fletcherite","Flinkite","Flinteite","Florencite-(Ce)","Florencite-(La)","Florencite-(Nd)","Florencite-(Sm)","Florenskyite","Florensovite","Fluckite","Fluellite","Fluoborite","Fluocerite-(Ce)","Fluocerite-(La)","Fluor-arfvedsonite","Fluor-buergerite","Fluor-dravite","Fluor-elbaite","Fluor-liddicoatite","Fluor-rewitzerite","Fluor-rossmanite","Fluor-schorl","Fluor-tsilaisite","Fluor-uvite","Fluoralforsite","Fluorannite","Fluorapatite","Fluorapophyllite-(Cs)","Fluorapophyllite-(K)","Fluorapophyllite-(NH_4_)","Fluorapophyllite-(Na)","Fluorarrojadite-(BaFe)","Fluorarrojadite-(BaNa)","Fluorbarytolamprophyllite","Fluorbritholite-(Ce)","Fluorbritholite-(La)","Fluorbritholite-(Nd)","Fluorbritholite-(Y)","Fluorcalciobritholite","Fluorcalciomicrolite","Fluorcalciopyrochlore","Fluorcalcioroméite","Fluorcanasite","Fluorcaphite","Fluorcarletonite","Fluorcarmoite-(BaNa)","Fluorchegemite","Fluorellestadite","Fluorine","Fluorite","Fluorkyuygenite","Fluorlamprophyllite","Fluorluanshiweiite","Fluormacraeite","Fluormayenite","Fluornatrocoulsellite","Fluornatromicrolite","Fluornatropyrochlore","Fluoro-cannilloite","Fluoro-edenite","Fluoro-ferri-magnesiokatophorite","Fluoro-leakeite","Fluoro-magnesiokatophorite","Fluoro-nybøite","Fluoro-oxy-ferri-magnesiokatophorite","Fluoro-pargasite","Fluoro-pedrizite","Fluoro-richterite","Fluoro-riebeckite","Fluoro-taramite","Fluoro-tremolite","Fluorocronite","Fluorokinoshitalite","Fluorophlogopite","Fluorotaramite","Fluorotetraferriphlogopite","Fluorotremolite","Fluorowardite","Fluorphosphohedyphane","Fluorpyromorphite","Fluorsigaiite","Fluorstrophite","Fluorthalénite-(Y)","Fluorvesuvianite","Fluorwavellite","Flurlite","Flörkeite","Fogoite-(Y)","Foitite","Folvikite","Fontanite","Fontarnauite","Foordite","Footemineite","Formanite-(Y)","Formicaite","Fornacite","Forsterite","Forêtite","Foshagite","Fougèrite","Fourmarierite","Fowlerite","Fraipontite","Francevillite","Franciscanite","Francisite","Franckeite","Francoanellite","Franconite","Frankamenite","Frankdicksonite","Frankhawthorneite","Franklinfurnaceite","Franklinite","Franklinphilite","Franksousaite","Fransoletite","Franzinite","Françoisite-(Ce)","Françoisite-(Nd)","Freboldite","Fredrikssonite","Freedite","Freibergite","Freieslebenite","Freitalite","Fresnoite","Freudenbergite","Friedelite","Friedrichbeckeite","Friedrichite","Friisite","Fritzscheite","Frohbergite","Frolovite","Frondelite","Froodite","Fuchunite","Fuenzalidaite","Fuettererite","Fukalite","Fukuchilite","Fulbrightite","Fullerite","Fupingqiuite","Furongite","Furutobeite","Fuyuanite","Fülöppite","Gabrielite","Gabrielsonite","Gachingite","Gadolinite-(Ce)","Gadolinite-(Nd)","Gadolinite-(Y)","Gadolinium gallium garnet","Gagarinite-(Ce)","Gagarinite-(Y)","Gageite","Gahnite","Gaidonnayite","Gaildunningite","Gainesite","Gaitite","Gajardoite","Gajardoite-(NH_4_)","Galaxite","Galeaclolusite","Galeite","Galena","Galenobismutite","Galgenbergite-(Ce)","Galileiite","Galkhaite","Galliskiite","Gallite","Gallobeudantite","Galloplumbogummite","Galuskinite","Gamagarite","Gananite","Ganomalite","Ganophyllite","Ganterite","Gaotaiite","Garavellite","Garmite","Garpenbergite","Garrelsite","Garronite-Ca","Garronite-Na","Gartrellite","Garutiite","Garyansellite","Gasparite-(Ce)","Gasparite-(La)","Gaspéite","Gatedalite","Gatehouseite","Gatelite-(Ce)","Gatewayite","Gatumbaite","Gaudefroyite","Gaultite","Gauthierite","Gayite","Gaylussite","Gazeevite","Gearksutite","Gebhardite","Gedrite","Geerite","Geffroyite","Geigerite","Geikielite","Gelosaite","Geminite","Gengenbachite","Genkinite","Genplesite","Genthelvite","Geocronite","Georgbarsanovite","Georgbokiite","George-ericksenite","Georgechaoite","Georgeite","Georgeliuite","Georgerobinsonite","Georgiadesite","Gerasimovskite","Gerdtremmelite","Gerenite-(Y)","Gerhardtite","Germanite","Germanocolusite","Gersdorffite","Gerstleyite","Gerstmannite","Geschieberite","Getchellite","Geuerite","Geversite","Ghiaraite","Giacovazzoite","Gianellaite","Gibbsite","Giessenite","Giftgrubeite","Gilalite","Gillardite","Gillespite","Gillulyite","Gilmarite","Ginelfite","Giniite","Ginorite","Giorgiosite","Giraudite-(Zn)","Girdite","Girvasite","Gismondine-Ca","Gismondine-Sr","Gittinsite","Giuseppettite","Giuşcăite","Gjerdingenite-Ca","Gjerdingenite-Fe","Gjerdingenite-Mn","Gjerdingenite-Na","Gladite","Gladiusite","Gladkovskyite","Glagolevite","Glass","Glass-(Ce)","Glass-(Dy)","Glass-(Er)","Glass-(Eu)","Glass-(Gd)","Glass-(Ho)","Glass-(La)","Glass-(Lu)","Glass-(Nd)","Glass-(Pr)","Glass-(Sm)","Glass-(Tb)","Glass-(Tm)","Glass-(Y)","Glass-(Yb)","Glauberite","Glaucocerinite","Glaucochroite","Glaucodot","Glauconite","Glaucophane","Glaukosphaerite","Glecklerite","Glikinite","Glucine","Glushinskite","Gmalimite","Gmelinite-Ca","Gmelinite-K","Gmelinite-Na","Gobbinsite","Gobelinite","Godlevskite","Godovikovite","Goedkenite","Goethite","Gold","Goldamalgam","Goldfieldite","Goldhillite","Goldichite","Goldmanite","Goldquarryite","Goldschmidtite","Golyshevite","Gonnardite","Gonyerite","Goosecreekite","Gorbunovite","Gorceixite","Gordaite","Gordonite","Gorerite","Gormanite","Gortdrumite","Goryainovite","Goslarite","Gottardiite","Gottlobite","Goudeyite","Gowerite","Goyazite","Graemite","Graeserite","Graftonite","Graftonite-(Ca)","Graftonite-(Mn)","Grahampearsonite","Gramaccioliite-(Y)","Grammatikopoulosite","Grandaite","Grandidierite","Grandreefite","Grandviewite","Grantsite","Graphite","Gratonite","Grattarolaite","Graulichite-(Ce)","Graulichite-(La)","Gravegliaite","Grayite","Graţianite","Grechishchevite","Greenalite","Greenlizardite","Greenockite","Greenwoodite","Gregoryite","Greifensteinite","Greigite","Grenmarite","Grguricite","Griceite","Griffinite","Grigorievite","Grimaldiite","Grimmite","Grimselite","Griphite","Grischunite","Gritsenkoite","Groatite","Grokhovskyite","Grootfonteinite","Grossite","Grossular","Groutite","Grumantite","Grumiplucite","Grundmannite","Grunerite","Gruzdevite","Guanacoite","Guanajuatite","Guangyuanite","Guanine","Guarinoite","Guastoniite-(Y)","Gudmundite","Guettardite","Gugiaite","Guidottiite","Guildite","Guilleminite","Guimarãesite","Guite","Guixiangite","Gungerite","Gunmaite","Gunningite","Gunterite","Gupeiite","Gurimite","Gurzhiite","Gustavite","Gutkovaite-Mn","Guyanaite","Guérinite","Gwihabaite","Gypsum","Gyrolite","Gysinite-(Ce)","Gysinite-(La)","Gysinite-(Nd)","Görgeyite","Götzenite","Günterblassite","Haapalaite","Hafnon","Hagendorfite","Haggertyite","Hagstromite","Haidingerite","Haigerachite","Haineaultite","Hainite-(Y)","Haitaite-(La)","Haiweeite","Hakite-(Cd)","Hakite-(Fe)","Hakite-(Hg)","Hakite-(Zn)","Halamishite","Halilsarpite","Halite","Hallimondite","Halloysite","Halotrichite","Halurgite","Hambergite","Hammarite","Hanahanite","Hanauerite","Hanawaltite","Hancockite","Hanjiangite","Hanksite","Hannayite","Hannebachite","Hansblockite","Hansesmarkite","Hanswilkeite","Hapkeite","Haradaite","Hardystonite","Harkerite","Harmotome","Harmunite","Harrisonite","Harstigite","Hartkoppeite","Hasanovite","Hashemite","Hastingsite","Hastite","Hatchite","Hatertite","Hatrurite","Hauchecornite","Hauckite","Hauerite","Hausmannite","Hawleyite","Hawthorneite","Haxonite","Haycockite","Haydeeite","Hayelasdiite","Haynesite","Haywoodite","Hayyanite","Hazenite","Haüyne","Heamanite-(Ce)","Heazlewoodite","Hechtsbergite","Hectorfloresite","Hectorite","Hedegaardite","Hedleyite","Hedyphane","Heflikite","Heftetjernite","Heideite","Heidornite","Heimaeyite","Heimite","Heinrichite","Heisenbergite","Hejtmanite","Heklaite","Heliophyllite","Hellandite-(Ce)","Hellandite-(Y)","Hellyerite","Helmutwinklerite","Helvine","Hematite","Hematolite","Hematophanite","Hemihedrite","Hemimorphite","Hemleyite","Hemloite","Hemusite","Hendekasartorite","Hendersonite","Hendricksite","Heneuite","Henmilite","Hennomartinite","Henritermierite","Henryite","Henrymeyerite","Henrysunite","Hentschelite","Hephaistosite","Heptasartorite","Herbertsmithite","Hercynite","Herderite","Hereroite","Hermannjahnite","Hermannroseite","Herzenbergite","Hessite","Hetaerolite","Heterogenite","Heteromorphite","Heterosite","Heulandite-Ba","Heulandite-Ca","Heulandite-K","Heulandite-Na","Heulandite-Sr","Hewettite","Hexacelsian","Hexaferrum","Hexahydrite","Hexahydroborite","Hexamolybdenum","Hexatestibiopanickelite","Hexathioplumbite","Heyerdahlite","Heyite","Heyrovskýite","Hezuolinite","Hibbingite","Hibonite","Hibschite","Hidalgoite","Hielscherite","Hieratite","Hilairite","Hilarionite","Hilgardite","Hilgardite-3A","Hilgardite-4M","Hillebrandite","Hillesheimite","Hillite","Hingganite","Hingganite-(Ce)","Hingganite-(Nd)","Hingganite-(Y)","Hingganite-(Yb)","Hinokageite","Hinsdalite","Hiortdahlite","Hiroseite","Hisingerite","Hitachiite","Hizenite-(Y)","Hiärneite","Hjalmarite","Hloušekite","Hocartite","Hochelagaite","Hochleitnerite","Hodgesmithite","Hodgkinsonite","Hodrušite","Hoelite","Hoganite","Hogarthite","Hohmannite","Hokkaidoite","Holdawayite","Holdenite","Holfertite","Hollandite","Hollingworthite","Hollisterite","Holmquistite","Holtedahlite","Holtite","Holtstamite","Holubite","Homilite","Honeaite","Honessite","Hongheite","Hongshiite","Honzaite","Hopeite","Hoperanchite","Hopmannite","Horiite","Horomanite","Horváthite-(Y)","Horákite","Hotsonite","Housleyite","Howardevansite","Howieite","Howlite","Hrabákite","Hsianghualite","Huanghoite-(Ce)","Huanghoite-(Nd)","Huangite","Huangshanite","Huanzalaite","Hubbardite","Hubeite","Huemulite","Huenite","Hughesite","Huizingite-(Al)","Hulsite","Humberstonite","Humboldtine","Humite","Hummerite","Hunchunite","Hundholmenite-(Y)","Hungchaoite","Huntingdonite","Huntite","Hureaulite","Hurlbutite","Hutcheonite","Hutchinsonite","Huttonite","Hyalophane","Hyalotekite","Hyblerite","Hydroandradite","Hydroastrophyllite","Hydrobasaluminite","Hydrobiotite","Hydroboracite","Hydrocalumite","Hydrocerussite","Hydrochlorborite","Hydrodelhayelite","Hydrodresserite","Hydroglauberite","Hydrohalite","Hydrohalloysite","Hydrohetaerolite","Hydrohonessite","Hydrokenoelsmoreite","Hydrokenomicrolite","Hydrokenopyrochlore","Hydrokenoralstonite","Hydromagnesite","Hydrombobomkulite","Hydroniumjarosite","Hydroniumpharmacoalumite","Hydroniumpharmacosiderite","Hydronováčekite","Hydropascoite","Hydroplumboelsmoreite","Hydropyrochlore","Hydroredmondite","Hydroromarchite","Hydroroméite","Hydroscarbroite","Hydrotalcite","Hydroterskite","Hydrotungstite","Hydrowoodwardite","Hydroxyapophyllite-(K)","Hydroxycalciomicrolite","Hydroxycalciopyrochlore","Hydroxycalcioroméite","Hydroxycancrinite","Hydroxyferroroméite","Hydroxykenoelsmoreite","Hydroxykenomicrolite","Hydroxykenopyrochlore","Hydroxylapatite","Hydroxylapatite-M","Hydroxylbastnäsite-(Ce)","Hydroxylbastnäsite-(La)","Hydroxylbastnäsite-(Nd)","Hydroxylbenyacarite","Hydroxylborite","Hydroxylchondrodite","Hydroxylclinohumite","Hydroxyledgrewite","Hydroxylellestadite","Hydroxylgugiaite","Hydroxylhedyphane","Hydroxylherderite","Hydroxylmattheddleite","Hydroxylphosphohedyphane","Hydroxylpyromorphite","Hydroxylwagnerite","Hydroxymanganopyrochlore","Hydroxymcglassonite-(K)","Hydroxynatropyrochlore","Hydroxyplumbopyrochlore","Hydrozincite","Hylbrownite","Hypercinnabar","Hyršlite","Hyttsjöite","Häggite","Håleniusite-(Ce)","Håleniusite-(La)","Hörnesite","Höslite","Høgtuvaite","Hübnerite","Hügelite","IMA2009-079","Ianbruceite","Iangreyite","Ianthinite","Ice","Ichnusaite","Icosahedrite","Idaite","Idrialite","Igelströmite","Iimoriite-(Y)","Ikaite","Ikorskyite","Ikranite","Ikunolite","Ilesite","Ilinskite","Ilirneyite","Illite","Illoqite-(Ce)","Ilmajokite-(Ce)","Ilsemannite","Iltisite","Ilvaite","Ilyukhinite","Ilímaussite-(Ce)","Imandrite","Imayoshiite","Imhofite","Imiterite","Imogolite","Inaglyite","Incaite","Incomsartorite","Inderborite","Inderite","Indialite","Indigirite","Indite","Indium","Inesite","Ingersonite","Ingodite","Innelite","Innsbruckite","Insizwaite","Interliveingite","Intersilite","Inyoite","Iodargyrite","Iodine","Iowaite","Iquiqueite","Iranite","Iraqite-(La)","Irarsite","Irhtemite","Iridarsenite","Iridium","Iriginite","Irinarassite","Irtyshite","Iseite","Ishiharaite","Ishikawaite","Iskandarovite","Isoclasite","Isocubanite","Isoferroplatinum","Isokite","Isolueshite","Isomertieite","Isovite","Isselite","Itelmenite","Itoigawaite","Itoite","Itsiite","Ivanyukite-Cu","Ivanyukite-K","Ivanyukite-Na","Ivanyukite-Na-T","Ivsite","Iwakiite","Iwashiroite-(Y)","Iwateite","Ixiolite-(Fe^2+^)","Ixiolite-(Mn^2+^)","Ixiolite-(Sc)","Iyoite","Izoklakeite","Jacobsite","Jacquesdietrichite","Jacutingaite","Jadarite","Jaffeite","Jagoite","Jagowerite","Jagüéite","Jahnsite-(CaFeFe)","Jahnsite-(CaFeMg)","Jahnsite-(CaMnFe)","Jahnsite-(CaMnMg)","Jahnsite-(CaMnMn)","Jahnsite-(CaMnZn)","Jahnsite-(MnMnFe)","Jahnsite-(MnMnMg)","Jahnsite-(MnMnMn)","Jahnsite-(MnMnZn)","Jahnsite-(NaFeMg)","Jahnsite-(NaMnMg)","Jahnsite-(NaMnMn)","Jaipurite","Jakobssonite","Jalpaite","Jamborite","Jamesite","Jamesonite","Janchevite","Janggunite","Janhaugite","Jankovićite","Jarandolite","Jarlite","Jarosewichite","Jarosite","Jaskólskiite","Jasmundite","Jasonsmithite","Jasrouxite","Jaszczakite","Javorieite","Jeanbandyite","Jeankempite","Jedwabite","Jeffbenite","Jeffreyite","Jennite","Jensenite","Jentschite","Jeppeite","Jeremejevite","Jerrygibbsite","Ježekite","Jianmuite","Jianshuiite","Jimboite","Jimkrieghite","Jimthompsonite","Jingsuiite","Jingwenite-(Y)","Jinshajiangite","Jinxiuite","Joanneumite","Joaquinite-(Ce)","Joegoldsteinite","Joesmithite","Johachidolite","Johanngeorgenstadtite","Johannite","Johillerite","Johnbaumite","Johnbaumite-M","Johninnesite","Johnjamborite","Johnkoivulaite-(Cs)","Johnsenite-(Ce)","Johnsomervilleite","Johntomaite","Johnwalkite","Joliotite","Jolliffeite","Jonassonite","Jonesite","Jonlarsenite","Joosteite","Jordanite","Jordisite","Joséite-A","Joséite-B","Joséite-C","Joteite","Jouravskite","Joëlbruggerite","Juabite","Juangodoyite","Juanitaite","Juanite","Juansilvaite","Julgoldite-(Fe^2+^)","Julgoldite-(Fe^3+^)","Julgoldite-(Mg)","Julienite","Jungite","Junitoite","Junoite","Juonniite","Jurbanite","Jusite","Juxingite","Jáchymovite","Jôkokuite","Jörgkellerite","Jørgensenite","K_3_Fe(CN)_5_NO","Kaatialaite","Kabalovite","Kadyrelite","Kafehydrocyanite","Kahlenbergite","Kahlerite","Kainite","Kainosite-(Y)","Kainotropite","Kaitianite","Kalborsite","Kalgoorlieite","Kaliborite","Kalicinite","Kalifersite","Kalininite","Kalinite","Kaliochalcite","Kaliophilite","Kalistrontite","Kalithallite","Kalsilite","Kaluginite","Kalungaite","Kalyuzhnyite-(Ce)","Kamaishilite","Kamarizaite","Kambaldaite","Kamchatkite","Kamenevite","Kamiokite","Kamitugaite","Kamotoite-(Y)","Kampelite","Kampfite","Kamphaugite-(Y)","Kanatzidisite","Kanemite","Kangite","Kangjinlaite","Kannanite","Kanonaite","Kanonerovite","Kantorite","Kaolinite","Kapellasite","Kapitsaite-(Y)","Kapundaite","Kapustinite","Karasugite","Karchevskyite","Karelianite","Karenwebberite","Karibibite","Karlditmarite","Karlite","Karlleuite","Karlseifertite","Karnasurtite-(Ce)","Karpenkoite","Karpinskite","Karpovite","Karupmøllerite-Ca","Karwowskiite","Kasatkinite","Kashinite","Kaskasite","Kasolite","Kassite","Kastningite","Katanite","Katayamalite","Katerinopoulosite","Katiarsite","Katoite","Katophorite","Katoptrite","Katsarosite","Kawazulite","Kayrobertsonite","Kayupovaite","Kazakhstanite","Kazakovite","Kazanskyite","Kaznakhtite","Kaňkite","Keckite","Kegelite","Kegginite","Keilite","Keithconnite","Keiviite-(Y)","Keiviite-(Yb)","Keldyshite","Kellyite","Kelyanite","Kemmlitzite","Kempite","Kenhsuite","Kenngottite","Kennygayite","Kenoargentotennantite-(Fe)","Kenoargentotetrahedrite-(Fe)","Kenoargentotetrahedrite-(Zn)","Kenomicrolite","Kenoplumbomicrolite","Kenorozhdestvenskayaite-(Fe)","Kenotobermorite","Kentbrooksite","Kentrolite","Kenyaite","Keplerite","Kerimasite","Kermesite","Kernite","Kernowite","Kesebolite-(Ce)","Kettnerite","Keutschite","Keyite","Keystoneite","Khademite","Khaidarkanite","Khamrabaevite","Khanneshite","Kharaelakhite","Khatyrkite","Khesinite","Khibinskite","Khinite","Khmaralite","Khomyakovite","Khorixasite","Khrenovite","Khristovite-(Ce)","Khurayyimite","Khvorovite","Kiddcreekite","Kidodite","Kidwellite","Kieftite","Kieserite","Kihlmanite-(Ce)","Kilchoanite","Killalaite","Kimrobinsonite","Kimuraite-(Y)","Kimzeyite","Kingite","Kingsgateite","Kingsmountite","Kingstonite","Kinichilite","Kinoite","Kinoshitalite","Kintoreite","Kipushite","Kircherite","Kirchhoffite","Kirkiite","Kirschsteinite","Kiryuite","Kishonite","Kitagohaite","Kitkaite","Kittatinnyite","Kladnoite","Klajite","Klaprothite","Klebelsbergite","Kleberite","Kleemanite","Kleinite","Klockmannite","Klyuchevskite","Klöchite","Knasibfite","Knorringite","Koashvite","Kobeite-(Y)","Kobellite","Kobokoboite","Kobyashevite","Kochite","Kochkarite","Kochsándorite","Kodamaite","Koechlinite","Koenenite","Kogarkoite","Kojonenite","Kokchetavite","Kokinosite","Koksharovite","Koktaite","Kolarite","Kolbeckite","Kolfanite","Kolicite","Kolitschite","Kollerite","Kolovratite","Kolskyite","Kolwezite","Kolymite","Komarovite","Kombatite","Komkovite","Konderite","Koninckite","Kononovite","Konyaite","Konzettite","Kopernikite","Kopylovite","Koragoite","Koritnigite","Kornelite","Kornerupine","Korobitsynite","Korshunovskite","Koryakite","Korzhinskite","Kosnarite","Kostovite","Kostylevite","Kotoite","Kottenheimite","Kotulskite","Koutekite","Kovdorskite","Kozoite-(La)","Kozoite-(Nd)","Kozyrevskite","Kozłowskiite","Kraisslite","Krasheninnikovite","Krasnoshteinite","Krasnovite","Kratochvílite","Krausite","Krauskopfite","Krautite","Kravtsovite","Kreiterite","Kremersite","Krennerite","Krettnichite","Kribergite","Krieselite","Krinovite","Kristiansenite","Kristjánite","Krivovichevite","Krotite","Kroupaite","Kruijenite","Krupičkaite","Krupkaite","Krut'aite","Krutovite","Kryachkoite","Kryzaite","Kryzhanovskite","Králíkite","Krásnoite","Kröhnkite","Krügerite","Ktenasite","Kuannersuite-(Ce)","Kudriavite","Kudryavtsevaite","Kufahrite","Kukharenkoite-(Ce)","Kukharenkoite-(La)","Kukisvumite","Kuksite","Kulanite","Kuliginite","Kuliokite-(Y)","Kulkeite","Kullerudite","Kumdykolite","Kummerite","Kumtyubeite","Kunatite","Kupletskite","Kupletskite-(Cs)","Kupčíkite","Kuramite","Kuranakhite","Kuratite","Kurchatovite","Kurgantaite","Kurilite","Kurnakovite","Kurumsakite","Kusachiite","Kutinaite","Kutnohorite","Kutyukhinite","Kuvaevite","Kuzelite","Kuzmenkoite-Mn","Kuzmenkoite-Zn","Kuzminite","Kuznetsovite","Kvanefjeldite","Kvačekite","Kyanite","Kyanoxalite","Kyawthuite","Kyrgyzstanite","Kyzylkumite","Kësterite","Köttigite","Laachite","Labradorite","Labuntsovite-Fe","Labuntsovite-Mg","Labuntsovite-Mn","Labyrinthite","Lacroixite","Laffittite","Laflammeite","Laforêtite","Lafossaite","Lagalyite","Lahnsteinite","Laihunite","Laitakarite","Lakargiite","Lakebogaite","Lalondeite","Lammerite","Lamprophyllite","Lanarkite","Landauite","Landesite","Langbeinite","Langhofite","Langisite","Langite","Lanmuchangite","Lannonite","Lansfordite","Lanthanite-(Ce)","Lanthanite-(La)","Lanthanite-(Nd)","Lapeyreite","Laphamite","Lapieite","Laplandite-(Ce)","Laptevite-(Ce)","Larderellite","Larisaite","Larnite","Larosite","Larsenite","Lasalite","Lasmanisite","Lasnierite","Latiumite","Latrappite","Laueite","Laumontite","Launayite","Lauraniite","Laurelite","Laurentianite","Laurentthomasite","Laurionite","Laurite","Lausenite","Lautarite","Lautenthalite","Lautite","Lavendulan","Laverovite","Lavinskyite","Lavoisierite","Lavrentievite","Lawrencite","Lawsonbauerite","Lawsonite","Lazaraskeite","Lazarenkoite","Lazaridisite","Lazerckerite","Lazulite","Lazurite","Lead","Leadamalgam","Leadhillite","Lebedevite","Lechatelierite","Lechnerite","Lecontite","Lecoqite-(Y)","Lednevite","Leesite","Lefontite","Legrandite","Leguernite","Lehmannite","Lehnerite","Leifite","Leightonite","Leisingite","Leiteite","Lemanskiite","Lemmleinite-Ba","Lemmleinite-K","Lemoynite","Lenaite","Lengenbachite","Leningradite","Lennilenapeite","Lenoblite","Leogangite","Leonardsenite","Leonite","Lepageite","Lepersonnite-(Gd)","Lepersonnite-(Nd)","Lepidocrocite","Lepidolite","Lepkhenelmite-Zn","Lermontovite","Lesukite","Letnikovite-(Ce)","Letovicite","Leucite","Leucophanite","Leucophoenicite","Leucophosphite","Leucosphenite","Leucostaurite","Levantite","Leverettite","Levinsonite-(Y)","Leybovite-K","Leydetite","Leószilárdite","Lianbinite","Liandratite","Liangjunite","Libbyite","Liberite","Libethenite","Libyan Desert glass","Liddicoatite","Liebauite","Liebenbergite","Liebermannite","Liebigite","Liguowuite","Liguriaite","Likasite","Lileyite","Lillianite","Lime","Limousinite","Linarite","Lindackerite","Lindbergite","Lindgrenite","Lindqvistite","Lindsleyite","Lindströmite","Lingbaoite","Lingunite","Linnaeite","Lintisite","Linzhiite","Liottite","Lipscombite","Lipuite","Liraite","Liroconite","Lisanite","Lisetite","Lishiite","Lishizhenite","Lisiguangite","Lisitsynite","Liskeardite","Lislkirchnerite","Litharge","Lithiomarsturite","Lithiophilite","Lithiophorite","Lithiophosphate","Lithiotantite","Lithiowodginite","Lithosite","Litidionite","Litochlebite","Litvinskite","Liudongshengite","Liuite","Liveingite","Liversidgeite","Livingstonite","Lizardite","Llantenesite","Lobanovite","Lokkaite-(Y)","Lombardoite","Lomonosovite","Londonite","Lonecreekite","Longshoushanite-(Ce)","Lonsdaleite","Loomisite","Loparite","Lopatkaite","Loranskite-(Y)","Lorenzenite","Lorándite","Loseyite","Lotharmeyerite","Loudounite","Loughlinite","Louisfuchsite","Lourenswalsite","Lovdarite","Loveringite","Lovozerite","Luanheite","Luanshiweiite","Luberoite","Luboržákite","Lucabindiite","Lucasite-(Ce)","Lucasite-(La)","Lucchesiite","Luddenite","Ludjibaite","Ludlamite","Ludlockite","Ludwigite","Lueshite","Luetheite","Luinaite-(OH)","Lukechangite-(Ce)","Lukkulaisvaaraite","Lukrahnite","Lulzacite","Lumsdenite","Lun'okite","Lunijianlaite","Luobusaite","Luogufengite","Lusernaite-(Y)","Lussierite","Luxembourgite","Luzonite","Lyonsite","Långbanite","Långbanshyttanite","Låvenite","Lévyclaudite","Lévyne-Ca","Lévyne-Na","Línekite","Lópezite","Löllingite","Löweite","Lüneburgite","Macaulayite","Macdonaldite","Macedonite","Macfallite","Machatschkiite","Machiite","Macivorite","Mackayite","Mackinawite","Macphersonite","Macquartite","Macraeite","Madeiraite","Madocite","Magadiite","Magbasite","Magganasite","Maghagendorfite","Maghemite","Maghrebite","Magnanelliite","Magnesio-dutrowite","Magnesio-ferri-fluoro-hornblende","Magnesio-ferri-hornblende","Magnesio-fluoro-arfvedsonite","Magnesio-fluoro-hastingsite","Magnesio-foitite","Magnesio-hastingsite","Magnesio-hornblende","Magnesio-lucchesiite","Magnesio-riebeckite","Magnesioalterite","Magnesioaubertite","Magnesiobeltrandoite-2N3S","Magnesiobermanite","Magnesiocanutite","Magnesiocarpholite","Magnesiochloritoid","Magnesiochlorophoenicite","Magnesiochromite","Magnesiocopiapite","Magnesiocoulsonite","Magnesiodumortierite","Magnesioferrite","Magnesiofluckite","Magnesiohatertite","Magnesiohongruiite-(Fe^3+^)","Magnesiohulsite","Magnesiohögbomite-2N2S","Magnesiohögbomite-2N3S","Magnesiohögbomite-2N4S","Magnesiohögbomite-6N12S","Magnesiohögbomite-6N6S","Magnesiokoritnigite","Magnesioleydetite","Magnesioneptunite","Magnesionigerite-2N1S","Magnesionigerite-6N6S","Magnesiopascoite","Magnesioqingheiite","Magnesiorowlandite-(Y)","Magnesiosadanagaite","Magnesiostaurolite","Magnesiotaaffeite-2N'2S","Magnesiotaaffeite-6N'3S","Magnesiotaramite","Magnesiovesuvianite","Magnesiovoltaite","Magnesiozippeite","Magnesite","Magnesium stearate","Magnetoplumbite","Magnioursilite","Magnolite","Magnussonite","Magnéliite","Magselite","Mahnertite","Maikainite","Majakite","Majindeite","Majzlanite","Makarochkinite","Makatite","Makotoite","Makovickyite","Malachite","Malanite","Malayaite","Maldonite","Maleevite","Maletoyvayamite","Malhmoodite","Malinkoite","Malladrite","Mallardite","Mallestigite","Malyshevite","Mambertiite","Mammothite","Mampsisite","Manaevite-(Ce)","Manaksite","Manandonite","Manasseite","Mandarinoite","Maneckiite","Manganarsite","Manganbabingtonite","Manganbelyankinite","Manganberzeliite","Manganese","Manganflurlite","Mangangordonite","Manganhumite","Mangani-dellaventuraite","Mangani-eckermannite","Mangani-obertiite","Mangani-pargasite","Manganiakasakaite-(La)","Manganiandrosite-(Ce)","Manganiandrosite-(La)","Manganiceladonite","Manganilvaite","Manganite","Manganlotharmeyerite","Mangano-ferri-eckermannite","Mangano-mangani-ungarettiite","Manganoarrojadite-(KNa)","Manganobadalovite","Manganoblödite","Manganochromite","Manganocummingtonite","Manganoeudialyte","Manganogrunerite","Manganohatertite","Manganohörnesite","Manganokaskasite","Manganokhomyakovite","Manganokukisvumite","Manganolangbeinite","Manganonaujakasite","Manganoneptunite","Manganonewberyite","Manganonordite-(Ce)","Manganoquadratite","Manganoschafarzikite","Manganosegelerite","Manganoshadlunite","Manganosite","Manganostibite","Manganotychite","Manganrockbridgeite","Manganvesuvianite","Mangazeite","Manitobaite","Manjiroite","Mannardite","Mansfieldite","Mantienneite","Manuelarossiite","Maohokite","Maoniupingite-(Ce)","Mapimite","Mapiquiroite","Marathonite","Marcasite","Marchettiite","Marcobaldiite","Margaritasite","Margarite","Margarosanite","Mariakrite","Marialite","Marianoite","Maricopaite","Mariinskite","Marinaite","Marinellite","Marioantofilliite","Marićite","Markascherite","Markcooperite","Markeyite","Markhininite","Marklite","Markwelchite","Marokite","Marrite","Marrucciite","Marsaalamite-(Y)","Marshite","Marsturite","Marthozite","Martinandresite","Martinite","Martyite","Marumoite","Maruyamaite","Marécottite","Masaitisite","Mascagnite","Maslovite","Massicot","Masutomilite","Masuyite","Mathesiusite","Mathewrogersite","Mathiasite","Matildite","Matioliite","Matlockite","Matsubaraite","Mattagamite","Matteuccite","Mattheddleite","Matthiasweilite","Matulaite","Matyhite","Maucherite","Mauriziodiniite","Maurogemmiite","Mavlyanovite","Mawbyite","Mawsonite","Maxwellite","Mayingite","Mazorite","Mazzettiite","Mazzite-Mg","Mazzite-Na","Mbobomkulite","Mcallisterite","Mcalpineite","Mcauslanite","Mcbirneyite","Mcconnellite","Mccrillisite","Mcgillite","Mcgovernite","Mcguinnessite","Mckelveyite-(Nd)","Mckelveyite-(Y)","Mckinstryite","Mcnearite","Medaite","Medenbachite","Medvedevite","Meerschautite","Megacyclite","Megakalsilite","Megawite","Meieranite","Meierite","Meifuite","Meionite","Meisserite","Meitnerite","Meixnerite","Meizhouite","Mejillonesite","Melanarsite","Melanocerite-(Ce)","Melanophlogite","Melanostibite","Melanotekite","Melanothallite","Melanovanadite","Melansonite","Melanterite","Melcherite","Meliphanite","Melkovite","Melliniite","Mellite","Mellizinkalite","Melonite","Menchettiite","Mendeleevite-(Ce)","Mendeleevite-(Nd)","Mendigite","Mendipite","Mendozavilite-KCa","Mendozavilite-NaCu","Mendozavilite-NaFe","Mendozite","Meneghinite","Menezesite","Mengeite","Mengxianminite","Meniaylovite","Menshikovite","Menzerite-(Y)","Mercallite","Mercury","Mereheadite","Mereiterite","Merelaniite","Merenskyite","Meridianiite","Merlinoite","Merrihueite","Merrillite","Mertieite","Merwinite","Mesaite","Mesolite","Messelite","Meta-aluminite","Meta-alunogen","Meta-ankoleite","Meta-autunite","Metaborite","Metacalciouranoite","Metacinnabar","Metadelrioite","Metahaiweeite","Metaheimite","Metaheinrichite","Metahewettite","Metahohmannite","Metakahlerite","Metakirchheimerite","Metaköttigite","Metalodèvite","Metamunirite","Metanatroautunite","Metanováčekite","Metarauchite","Metarossite","Metasaléeite","Metaschoderite","Metaschoepite","Metasideronatrite","Metastibnite","Metastudtite","Metaswitzerite","Metatamboite","Metathénardite","Metatorbernite","Metatyuyamunite","Metauramphite","Metauranocircite","Metauranocircite II","Metauranopilite","Metauranospinite","Metauroxite","Metavandendriesscheite","Metavanmeersscheite","Metavanuralite","Metavariscite","Metavauxite","Metavivianite","Metavoltine","Metazellerite","Metazeunerite","Meurigite-K","Meurigite-Na","Meyerhofferite","Meymacite","Meyrowitzite","Mgriite","Mianningite","Miargyrite","Miassite","Michalskiite","Micheelsenite","Michenerite","Michitoshiite-(Cu)","Microcline","Microlite","Microsommite","Midbarite","Middendorfite","Middlebackite","Mieite-(Y)","Miersite","Miessiite","Miguelromeroite","Miharaite","Mikasaite","Mikecoxite","Mikehowardite","Mikenewite","Milanriederite","Milarite","Milkovoite","Millerite","Millisite","Millosevichite","Millsite","Milotaite","Mimetite","Mimetite-M","Minakawaite","Minasgeraisite-(Y)","Minasragrite","Mineevite-(Y)","Minehillite","Minguzzite","Minium","Minjiangite","Minnesotaite","Minohlite","Minrecordite","Minyulite","Mirabilite","Mirnyite","Misakiite","Misenite","Miserite","Mitridatite","Mitrofanovite","Mitryaevaite","Mitscherlichite","Mixite","Miyahisaite","Miyawakiite-(Y)","Mizraite-(Ce)","Moabite","Moctezumite","Modderite","Modraite","Mogovidite","Mogánite","Mohite","Mohrite","Moiraite","Moissanite","Mojaveite","Molinelloite","Moluranite","Molybdenite","Molybdenum","Molybdite","Molybdofornacite","Molybdomenite","Molybdophyllite","Molysite","Momoiite","Monalbite","Monazite-(Ce)","Monazite-(Gd)","Monazite-(La)","Monazite-(Nd)","Monazite-(Sm)","Moncheite","Monchetundraite","Monetite","Mongolite","Mongshanite","Monimolite","Monipite","Monohydrocalcite","Montanite","Montbrayite","Montdorite","Montebrasite","Monteneroite","Monteneveite","Monteponite","Monteregianite-(Y)","Montesommaite","Montetrisaite","Montgomeryite","Monticellite","Montmorillonite","Montpelvouxite","Montroseite","Montroyalite","Montroydite","Mooihoekite","Moolooite","Mooreite","Moorhouseite","Mopungite","Moraesite","Moragite","Moraskoite","Mordenite","Moreauite","Morelandite","Morenosite","Morimotoite","Morinite","Morleyite","Morningstarite","Morozeviczite","Morrisonite","Mosandrite-(Ce)","Moschelite","Moschellandsbergite","Mosesite","Moskvinite-(Y)","Mottanaite-(Ce)","Mottramite","Motukoreaite","Mounanaite","Mountainite","Mountkeithite","Mourite","Moxuanxueite","Moydite-(Y)","Mozartite","Mozgovaite","Moëloite","Mpororoite","Mroseite","Mrázekite","Muirite","Mukhinite","Mullite","Mummeite","Munakataite","Mundite","Mundrabillaite","Munirite","Muonionalustaite","Murakamiite","Murashkoite","Murataite-(Y)","Murchisite","Murdochite","Murmanite","Murphyite","Murunskite","Muscovite","Museumite","Mushistonite","Muskoxite","Muthmannite","Mutinaite","Mutnovskite","Mäkinenite","Mélonjosephite","Möhnite","Mössbauerite","Mückeite","Müllerite","Naalasite","Nabalamprophyllite","Nabaphite","Nabateaite","Nabesite","Nabiasite","Nabimusaite","Nabokoite","Nacaphite","Nacareniobsite-(Ce)","Nacareniobsite-(Nd)","Nacareniobsite-(Y)","Nacrite","Nadorite","Nafeasite","Nafertisite","Nagashimalite","Nagelschmidtite","Nagyágite","Nahcolite","Nahpoite","Nakauriite","Nakkaalaaqite","Naldrettite","Nalipoite","Nalivkinite","Nambulite","Namibite","Namuwite","Nancyrossite","Nanlingite","Nannoniite","Nanpingite","Nantokite","Napoliite","Naquite","Narsarsukite","Nashite","Nasinite","Nasledovite","Nasonite","Nastrophite","Nataliakulikite","Nataliyamalikite","Natanite","Natisite","Natrite","Natroalunite","Natroaphthitalite","Natrobistantite","Natroboltwoodite","Natrochalcite","Natrodufrénite","Natroglaucocerinite","Natrojarosite","Natrokomarovite","Natrolemoynite","Natrolite","Natromarkeyite","Natromelansonite","Natromolybdite","Natromontebrasite","Natron","Natronambulite","Natroniobite","Natropalermoite","Natropharmacoalumite","Natropharmacosiderite","Natrophilite","Natrophosphate","Natrosilite","Natrosulfatourea","Natrotantite","Natrotitanite","Natrouranospinite","Natrowalentaite","Natroxalate","Natrozippeite","Naujakasite","Naumannite","Navajoite","Navrotskyite","Nazarchukite","Nazarovite","Nealite","Nechelyustovite","Nefedovite","Negevite","Neighborite","Nekoite","Nekrasovite","Nelenite","Neltnerite","Nenadkevichite","Neotocite","Nepheline","Nepskoeite","Neptunite","Neskevaaraite-Fe","Nesquehonite","Nestolaite","Neustädtelite","Nevadaite","Nevskite","Newberyite","Neyite","Nežilovite","Niahite","Niasite","Nichromite","Nickel","Nickelalumite","Nickelaustinite","Nickelbischofite","Nickelblödite","Nickelboussingaultite","Nickelhexahydrite","Nickeline","Nickellotharmeyerite","Nickelphosphide","Nickelpicromerite","Nickelschneebergite","Nickelskutterudite","Nickeltalmessite","Nickeltsumcorite","Nickeltyrrellite","Nickelzippeite","Nickenichite","Nickolayite","Nicksobolevite","Niedermayrite","Nielsbohrite","Nielsenite","Nierite","Nifontovite","Nigelcookite","Niggliite","Niigataite","Nikischerite","Nikmelnikovite","Niksergievite","Nimite","Ningyoite","Niningerite","Nioboaeschynite-(Ce)","Nioboaeschynite-(Nd)","Nioboaeschynite-(Y)","Niobobaotite","Niobocarbide","Nioboheftetjernite","Nioboholtite","Nioboixiolite-(Fe^2+^)","Nioboixiolite-(Fe^3+^)","Nioboixiolite-(Mn^2+^)","Nioboixiolite-(◻)","Niobokupletskite","Niobophyllite","Niocalite","Nipalarsite","Nipeiite-(Ce)","Nisbite","Nishanbaevite","Nisnite","Nissonite","Niter","Nitratine","Nitrobarite","Nitrocalcite","Nitromagnesite","Nitroplumbite","Nitscheite","Niveolanite","Nixonite","Nizamoffite","Nobleite","Noelbensonite","Nolanite","Nollmotzite","Nolzeite","Nontronite","Noonkanbahite","Norbergite","Nordenskiöldine","Nordgauite","Nordite-(Ce)","Nordite-(La)","Nordstrandite","Nordströmite","Norilskite","Normandite","Norrishite","Norsethite","Northstarite","Northupite","Nosean","Novgorodovaite","Novikovite","Novodneprite","Novograblenovite","Novákite","Nováčekite","Nowackiite","Nsutite","Nuffieldite","Nukundamite","Nullaginite","Numanoite","Nuragheite","Nuwaite","Nybergite","Nybøite","Nyerereite","Nyholmite","Népouite","Nöggerathite-(Ce)","O'danielite","Oberthürite","Oberwolfachite","Oboniobite","Oboyerite","Obradovicite-KCu","Obradovicite-NaCu","Obradovicite-NaNa","Odigitriaite","Odikhinchaite","Odinite","Odintsovite","Oenite","Offretite","Oftedalite","Ogdensburgite","Ognitite","Ohmilite","Ohtaniite","Ojuelaite","Okanoganite-(Y)","Okayamalite","Okenite","Okhotskite","Okieite","Okruginite","Okruschite","Oldhamite","Oldsite-(K)","Olekminskite","Olenite","Olgafrankite","Olgite","Oligoclase","Olivenite","Olkhonskite","Olmiite","Olmsteadite","Olsacherite","Olsenite","Olshanskyite","Olympite","Omariniite","Omeiite","Ominelite","Omongwaite","Omsite","Ondrušite","Oneillite","Onoratoite","Oosterboschite","Ootannite","Opal","Ophirite","Oppenheimerite","Orcelite","Ordoñezite","Oregonite","Oreillyite","Organovaite-Mn","Organovaite-Zn","Orickite","Orientite","Orishchinite","Orlandiite","Orlovite","Orlymanite","Orpheite","Orpiment","Orschallite","Orthobrannerite","Orthochamosite","Orthoclase","Orthocuproplatinum","Orthoericssonite","Orthogersdorffite","Orthojoaquinite-(Ce)","Orthojoaquinite-(La)","Orthominasragrite","Orthopinakiolite","Orthoserpierite","Orthowalpurgite","Osakaite","Osarizawaite","Osarsite","Osbornite","Oscarkempffite","Oskarssonite","Osmium","Osumilite","Osumilite-(Mg)","Oswaldpeetersite","Otavite","Otjisumeite","Ottemannite","Ottensite","Ottohahnite","Ottoite","Ottoliniite","Ottrélite","Otwayite","Oulankaite","Ourayite","Oursinite","Ovamboite","Overite","Owensite","Owyheeite","Oxammite","Oxo-magnesio-hastingsite","Oxo-mangani-leakeite","Oxy-chromium-dravite","Oxy-dravite","Oxy-foitite","Oxy-schorl","Oxy-vanadium-dravite","Oxybismutomicrolite","Oxycalciobetafite","Oxycalciomicrolite","Oxycalciopyrochlore","Oxycalcioroméite","Oxykinoshitalite","Oxynatromicrolite","Oxyphlogopite","Oxyplumbopyrochlore","Oxyplumboroméite","Oxystannomicrolite","Oxystibiomicrolite","Oxyuranobetafite","Oxyvanite","Oxyyttrobetafite-(Y)","Oyelite","Oyonite","Ozernovskite","Ozerovaite","Paarite","Pabellóndepicaite","Pabstite","Paceite","Pachnolite","Packratite","Paddlewheelite","Padmaite","Padĕraite","Paganoite","Pahasapaite","Painite","Pakhomovskyite","Palarstanide","Palenzonaite","Palermoite","Palladinite","Palladium","Palladoarsenide","Palladobismutharsenide","Palladodymite","Palladogermanide","Palladosilicide","Palladothallite","Palladseite","Palmierite","Palygorskite","Pampaloite","Panasqueiraite","Pandoraite-Ba","Pandoraite-Ca","Panethite","Panguite","Panichiite","Panskyite","Pansnerite","Panunzite","Paolovite","Papagoite","Papikeite","Paqueite","Para-alumohydrocalcite","Parabariomicrolite","Paraberzeliite","Parabrandtite","Parabutlerite","Paracelsian","Paracetamol","Paracoquimbite","Paracostibite","Paradamite","Paradimorphite","Paradocrasite","Paraershovite","Parafiniukite","Parafransoletite","Parageorgbokiite","Paragersdorffite","Paragonite","Paraguanajuatite","Parahibbingite","Parahopeite","Parakeldyshite","Parakuzmenkoite-Fe","Paralabuntsovite-Mg","Paralammerite","Paralaurionite","Paralomonosovite","Paralstonite","Paramarkeyite","Paramelaconite","Paramendozavilite","Paramolybdomenite","Paramontroseite","Paranatisite","Paranatrolite","Paraniite-(Y)","Paraotwayite","Parapierrotite","Pararaisaite","Pararammelsbergite","Pararealgar","Pararobertsite","Pararsenolamprite","Parascandolaite","Paraschachnerite","Paraschoepite","Parascholzite","Parascorodite","Parasibirskite","Paraspurrite","Parasterryite","Parasymplesite","Paratacamite","Paratacamite-(Mg)","Paratacamite-(Ni)","Paratellurite","Paratimroseite","Paratobermorite","Paratooite-(La)","Paratsepinite-Ba","Paratsepinite-Na","Paraumbite","Parauranophane","Paravauxite","Paravinogradovite","Parawulffite","Parisite-(Ce)","Parisite-(La)","Parisite-(Nd)","Parkerite","Parkinsonite","Parnauite","Parsettensite","Parsonsite","Parthéite","Partzite","Parvo-mangano-edenite","Parvo-manganotremolite","Parwanite","Parwelite","Parádsasvárite","Pascoite","Paseroite","Patrónite","Pattersonite","Patynite","Pauflerite","Pauladamsite","Paulgrothite","Paulhlavaite","Paulingite-Ca","Paulingite-K","Paulingite-Na","Paulišite","Paulkellerite","Paulkerrite","Paulmooreite","Pauloabibite","Paulrobinsonite","Paulscherrerite","Pautovite","Pavlovskyite","Pavonite","Paxite","Pašavaite","Pearceite","Peatite-(Y)","Pecoraite","Pectolite","Pectolite-M2abc","Pedrizite","Peisleyite","Pekoite","Pekovite","Pellouxite","Pellyite","Penberthycroftite","Pendevilleite-(Y)","Penfieldite","Pengite","Penikisite","Penkvilksite","Pennantite","Penobsquisite","Penriceite","Penroseite","Pentagonite","Pentahydrite","Pentahydroborite","Pentlandite","Penzhinite","Peprossiite-(Ce)","Peprossiite-(Y)","Perbøeite-(Ce)","Perbøeite-(La)","Perchiazziite","Perchukite-(Y)","Percleveite-(Ce)","Percleveite-(La)","Peretaite","Perettiite-(Y)","Perhamite","Periclase","Perite","Perkovaite","Perlialite","Perloffite","Permanganogrunerite","Permingeatite","Perovskite","Perraultite","Perrierite-(Ce)","Perrierite-(La)","Perroudite","Perryite","Pertlikite","Pertoldite","Pertsevite-(F)","Pertsevite-(OH)","Petalite","Petarasite","Peterandresenite","Peterbaylissite","Peterchinite","Petermegawite","Petersenite-(Ce)","Petersite-(Ce)","Petersite-(La)","Petersite-(Y)","Petewilliamsite","Petitjeanite","Petrovicite","Petrovite","Petrovskaite","Petrukite","Petscheckite","Petterdite","Petzite","Petříčekite","Pezzottaite-(Cs)","Pfaffenbergite","Pharmacoalumite","Pharmacolite","Pharmacosiderite","Pharmazincite","Phaunouxite","Phenakite","Philipsbornite","Philipsburgite","Phillipsite-Ca","Phillipsite-K","Phillipsite-Na","Philolithite","Philoxenite","Philrothite","Phlogopite","Phoenicochroite","Phosgenite","Phosinaite-(Ce)","Phosphammite","Phosphocyclite-(Fe)","Phosphocyclite-(Ni)","Phosphoellenbergerite","Phosphoferrite","Phosphofibrite","Phosphogartrellite","Phosphohedyphane","Phosphoinnelite","Phosphophyllite","Phosphorrösslerite","Phosphosiderite","Phosphovanadylite-Ba","Phosphovanadylite-Ca","Phosphowalpurgite","Phosphuranylite","Phoxite","Phuralumite","Phurcalite","Phylloretine","Phyllotungstite","Picaite","Piccoliite","Pickeringite","Picotpaulite","Picromerite","Picropharmacolite","Pieczkaite","Piemontite","Piemontite-(Pb)","Piemontite-(Sr)","Piergorite-(Ce)","Pierrotite","Pigotite","Pilanesbergite","Pilawite-(Y)","Pilipenkoite","Pillaite","Pilsenite","Pinakiolite","Pinalite","Pinchite","Pingguite","Pinnoite","Pintadoite","Piretite","Pirquitasite","Pirssonite","Pitiglianoite","Pitticite","Pittongite","Piypite","Pizgrischite","Plagionite","Plancheite","Planerite","Platarsite","Platinum","Plattnerite","Plavnoite","Playfairite","Pleysteinite","Plimerite","Pliniusite","Plombièrite","Plumboagardite","Plumbobetafite","Plumboferrite","Plumbogaidonnayite","Plumbogottlobite","Plumbogummite","Plumbojarosite","Plumbojohntomaite","Plumbomicrolite","Plumbonacrite","Plumbopalladinite","Plumboperloffite","Plumbopharmacosiderite","Plumbophyllite","Plumbopyrochlore","Plumboselite","Plumbotellurite","Plumbotsumite","Plumosite","Plášilite","Podlesnoite","Poellmannite","Pohlite","Poirierite","Poitevinite","Pokhodyashinite","Pokrovskite","Polarite","Poldervaartite","Polekhovskyite","Polezhaevaite-(Ce)","Polhemusite","Polkanovite","Polkovicite","Polloneite","Pollucite","Polyakovite-(Ce)","Polyarsite","Polybasite","Polycrase-(Y)","Polydymite","Polyhalite","Polylithionite","Polyphite","Polystyrene","Pomite","Ponomarevite","Popovite","Poppiite","Popugaevaite","Portlandite","Posnjakite","Postite","Potarite","Potassic magnesio-arfvedsonite","Potassic-arfvedsonite","Potassic-chloro-hastingsite","Potassic-chloro-pargasite","Potassic-ferri-leakeite","Potassic-ferro-ferri-sadanagaite","Potassic-ferro-ferri-taramite","Potassic-ferro-pargasite","Potassic-ferro-sadanagaite","Potassic-ferro-taramite","Potassic-fluoro-hastingsite","Potassic-fluoro-pargasite","Potassic-fluoro-richterite","Potassic-hastingsite","Potassic-jeanlouisite","Potassic-magnesio-arfvedsonite","Potassic-magnesio-fluoro-arfvedsonite","Potassic-magnesio-hastingsite","Potassic-mangani-leakeite","Potassic-pargasite","Potassic-richterite","Potassic-sadanagaite","Potassiccarpholite","Potassichastingsite","Potassicmendeleevite-(Ce)","Potassicrichterite","Potassium Dihydrogen Phosphate","Potosíite","Pottsite","Poubaite","Poudretteite","Poughite","Povondraite","Powellite","Poyarkovite","Pošepnýite","Prachařite","Pradetite","Pratesiite","Prehnite","Preisingerite","Preiswerkite","Preobrazhenskite","Pretulite","Prewittite","Priceite","Priderite","Princivalleite","Pringleite","Priscillagrewite-(Y)","Prismatine","Probertite","Proshchenkoite-(Y)","Prosopite","Prosperite","Protasite","Proto-anthophyllite","Proto-ferro-anthophyllite","Proto-ferro-suenoite","Proto-owyheeite","Protocaseyite","Protochabournéite","Protoenstatite","Protojoséite","Proudite","Proustite","Proxidecagonite","Proxitwelvefoldite","Przhevalskite","Pseudoboleite","Pseudobrookite","Pseudocotunnite","Pseudodickthomssenite","Pseudograndreefite","Pseudojohannite","Pseudolaueite","Pseudolyonsite","Pseudomalachite","Pseudomarkeyite","Pseudomeisserite-(NH_4_)","Pseudomertieite","Pseudopomite","Pseudorutile","Pseudosinhalite","Pseudowollastonite","Pucherite","Pumpellyite-(Al)","Pumpellyite-(Fe^2+^)","Pumpellyite-(Fe^3+^)","Pumpellyite-(Mg)","Pumpellyite-(Mn^2+^)","Puninite","Punkaruaivite","Purpurite","Puschridgeite","Pushcharovskite","Putnisite","Putoranite","Puttapaite","Putzite","Pyatenkoite-(Y)","Pyracmonite","Pyradoketosite","Pyrargyrite","Pyrimethamine","Pyrite","Pyroaurite","Pyrobelonite","Pyrochlore","Pyrochroite","Pyrocoproite","Pyrolusite","Pyromorphite","Pyrope","Pyrophanite","Pyrophosphite","Pyrophyllite","Pyrosmalite-(Fe)","Pyrosmalite-(Mn)","Pyrostilpnite","Pyroxferroite","Pyrrhotite","Pääkkönenite","Péligotite","Písekite-(Y)","Příbramite","Qandilite","Qaqarssukite-(Ce)","Qatranaite","Qeltite","Qilianshanite","Qingheiite","Qingsongite","Qitianlingite","Qiumingite","Quadratite","Quadridavyne","Quadruphite","Quartz","Quatrandorite","Queitite","Quenselite","Quenstedtite","Quetzalcoatlite","Quijarroite","Quintinite","Quintinite-3T","Qusongite","Raadeite","Rabbittite","Rabejacite","Raberite","Radekškodaite-(Ce)","Radekškodaite-(La)","Radhakrishnaite","Radovanite","Radtkeite","Radvaniceite","Raguinite","Raisaite","Raite","Rajite","Rakovanite","Ralphcannonite","Ramaccioniite","Ramanite-(Cs)","Ramanite-(Rb)","Ramazzoite","Rambergite","Ramdohrite","Rameauite","Ramikite-(Y)","Rammelsbergite","Ramosite","Ramsbeckite","Ramsdellite","Ranciéite","Rankachite","Rankamaite","Rankinite","Ransomite","Ranunculite","Rapidcreekite","Rappoldite","Raslakite","Rasmussenite","Raspite","Rastsvetaevite","Rasvumite","Rathite","Rathite-IV","Rauchite","Raudseppite","Rauenthalite","Rauvite","Ravatite","Raydemarkite","Raygrantite","Rayite","Realgar","Reaphookhillite","Rebulite","Reckibachite","Rectorite","Redcanyonite","Reddingite","Redgillite","Redingtonite","Redledgeite","Redmondite","Redondite","Reederite-(Y)","Reedmergnerite","Reevesite","Refikite","Regerite","Reichenbachite","Reidite","Reinerite","Reinhardbraunsite","Relianceite-(K)","Renardite","Rengeite","Renierite","Reppiaite","Retgersite","Retzian-(Ce)","Retzian-(La)","Retzian-(Nd)","Revdite","Rewitzerite","Reyerite","Reynoldsite","Reznitskyite","Rhabdoborite-(Mo)","Rhabdoborite-(V)","Rhabdoborite-(W)","Rhabdophane-(Ce)","Rhabdophane-(La)","Rhabdophane-(Nd)","Rhabdophane-(Y)","Rheniite","Rhodarsenide","Rhodesite","Rhodium","Rhodizite","Rhodochrosite","Rhodostannite","Rhodplumsite","Rhomboclase","Ribbeite","Richardsite","Richardsollyite","Richellite","Richelsdorfite","Richetite","Rickardite","Rickturnerite","Riebeckite","Riesite","Rietveldite","Rigrahamite","Rilandite","Rimkorolgite","Ringwoodite","Rinkite-(Ce)","Rinkite-(Y)","Rinmanite","Rinneite","Riomarinaite","Riotintoite","Rippite","Rittmannite","Rivadavite","Riversideite","Roaldite","Robertsite","Robinsonite","Rockbridgeite","Rodalquilarite","Rodolicoite","Roeblingite","Roedderite","Rogermitchellite","Roggianite","Rohaite","Rokühnite","Rollandite","Romanite","Romanorlovite","Romanèchite","Romarchite","Roméite","Rondorfite","Rongibbsite","Ronneburgite","Ronpetersonite","Rooseveltite","Roquesite","Rorisite","Rosasite","Roscherite","Roscoelite","Roselite","Rosemaryite","Rosenbergite","Rosenbuschite","Rosenhahnite","Roshchinite","Rosiaite","Rosickýite","Rosièresite","Rossiantonite","Rossite","Rossmanite","Rossovskyite","Rostite","Rotemite","Roterbärite","Rotherkopfite","Rouaite","Roubaultite","Roumaite","Rouseite","Routhierite","Rouvilleite","Rouxelite","Roweite","Rowlandite-(Y)","Rowleyite","Roxbyite","Roymillerite","Rozenite","Rozhdestvenskayaite-(Zn)","Rruffite","Ruarsite","Rubicline","Rubinite","Rucklidgeite","Rudabányaite","Rudashevskyite","Rudenkoite","Rudolfhermannite","Ruifrancoite","Ruitenbergite","Ruizhongite","Ruizite","Rumoiite","Rumseyite","Rundqvistite-(Ce)","Rusakovite","Rusinovite","Russellite","Russoite","Rustenburgite","Rustumite","Ruthenarsenite","Rutheniridosmine","Ruthenium","Rutherfordine","Rutile","Rynersonite","Rémondite-(Ce)","Rémondite-(La)","Ríosecoite","Römerite","Röntgenite-(Ce)","Rösslerite","Rüdlingerite","SO4 - hydrotalcite - 11Å","SO4 - hydrotalcite - 8.8Å","Saamite","Sabatierite","Sabelliite","Sabieite","Sabinaite","Sabugalite","Saccoite","Sachanbińskiite","Sacrofanite","Sadanagaite","Saddlebackite","Safflorite","Sahamalite-(Ce)","Sahlinite","Sailaufite","Sainfeldite","Sakhaite","Sakuraiite","Salammoniac","Salesite","Saliotite","Saltonseaite","Salzburgite","Saléeite","Samaniite","Samarium","Samarskite-(Y)","Samarskite-(Yb)","Samfowlerite","Sampleite","Samraite","Samsonite","Samuelsonite","Sanbornite","Sanderite","Saneroite","Sangenaroite","Sanguite","Sanidine","Sanjuanite","Sanmartinite","Sanrománite","Santabarbaraite","Santaclaraite","Santafeite","Santanaite","Santarosaite","Santite","Saponite","Sapozhnikovite","Sapphirine","Sarabauite","Saranchinaite","Saranovskite","Sarcolite","Sarcopside","Sardashtite","Sardignaite","Sarkinite","Sarmientite","Sarrabusite","Sarrochite","Sartorite","Sarvodaite","Saryarkite-(Y)","Sasaite","Sassite","Sassolite","Satimolite","Satpaevite","Satterlyite","Sauconite","Savelievaite","Sayrite","Sazhinite-(Ce)","Sazhinite-(La)","Sazykinaite-(Y)","Sbacchiite","Sborgite","Scacchite","Scainiite","Scandio-fluoro-eckermannite","Scandio-winchite","Scandiobabingtonite","Scarbroite","Scawtite","Scenicite","Schachnerite","Schafarzikite","Schairerite","Schallerite","Schapbachite","Schaurteite","Scheelite","Schertelite","Scheuchzerite","Schiavinatoite","Schieffelinite","Schindlerite","Schirmerite","Schizolite","Schlegelite","Schlemaite","Schlossmacherite","Schlüterite-(Y)","Schmidite","Schmiederite","Schmitterite","Schneebergite","Schneiderhöhnite","Schoderite","Schoenfliesite","Schoepite","Scholzite","Schoonerite","Schorl","Schorlomite","Schreibersite","Schreyerite","Schröckingerite","Schubnelite","Schuetteite","Schuilingite-(Nd)","Schulenbergite","Schultenite","Schumacherite","Schwartzembergite","Schwertmannite","Schäferite","Schöllhornite","Schüllerite","Sclarite","Scolecite","Scordariite","Scorodite","Scorticoite","Scorzalite","Scotlandite","Scottyite","Scrutinyite","Seaborgite","Seamanite","Searlesite","Sederholmite","Sedovite","Seeligerite","Seelite","Segelerite","Segerstromite","Segnitite","Seidite-(Ce)","Seidozerite","Seifertite","Seinäjokite","Sejkoraite-(Y)","Sekaninaite","Selenium","Selenodantopaite","Selenojalpaite","Selenojunoite","Selenolaurite","Selenopolybasite","Selenostephanite","Seligmannite","Selivanovaite","Sellaite","Selsurtite","Selwynite","Semenovite-(Ce)","Semseyite","Senaite","Senandorite","Senarmontite","Senegalite","Sengierite","Senkevichite","Sepiolite","Serandite","Serendibite","Sergeevite","Sergevanite","Sergeysmirnovite","Serpierite","Serrabrancaite","Sewardite","Shabaite-(Nd)","Shabynite","Shadlunite","Shafranovskite","Shagamite","Shakhdaraite-(Y)","Shakhovite","Shandite","Shannonite","Sharpite","Sharyginite","Shasuite","Shattuckite","Shcherbakovite","Shcherbinaite","Shchurovskyite","Sheldrickite","Shenganfuite","Shenzhuangite","Sherwoodite","Shibkovite","Shigaite","Shijiangshanite","Shilovite","Shimazakiite","Shimenite","Shinarumpite","Shinichengite","Shinkolobweite","Shiranuiite","Shirokshinite","Shirozulite","Shkatulkalite","Shlykovite","Shojiite","Shomiokite-(Y)","Shortite","Shosanbetsuite","Shuangfengite","Shubnikovite","Shuiskite-(Cr)","Shuiskite-(Mg)","Shulamitite","Shumwayite","Shuvalovite","Si_3_N_4_-beta","Sibirskite","Sicherite","Sicklerite","Siderazot","Siderite","Sideronatrite","Siderophyllite","Siderotil","Sidorenkite","Sidorovite","Sidpietersite","Sidwillite","Siegenite","Sieleckiite","Sigismundite","Sigloite","Sigogglinite","Siidraite","Silesiaite","Silhydrite","Silicocarnotite","Silicon","Siligiite","Silinaite","Sillimanite","Sillénite","Silver","Silvialite","Simferite","Simmonsite","Simonellite","Simonite","Simonkolleite","Simplotite","Simpsonite","Sincosite","Sinhalite","Sinjarite","Sinkankasite","Sinnerite","Sinoite","Sitinakite","Siudaite","Siwaqaite","Sjögrenite","Skaergaardite","Skinnerite","Skippenite","Sklodowskite","Skogbyite","Skorpionite","Skutterudite","Slavkovite","Slavíkite","Slawsonite","Slottaite","Sluzhenikinite","Slyudyankaite","Smamite","Smirnite","Smirnovskite","Smithite","Smithsonite","Smolyaninovite","Smrkovecite","Smythite","Sobolevite","Sobolevskite","Sodalite","Soddyite","Sodic-ferri-clinoferroholmquistite","Sodic-ferripedrizite","Sodic-ferro-anthophyllite","Sodic-ferrogedrite","Sodic-ferropedrizite","Sodicanthophyllite","Sodicgedrite","Sodicpedrizite","Sofiite","Sogdianite","Sokolovaite","Solongoite","Somersetite","Sonolite","Sonoraite","Sopcheite","Sorbyite","Sorosite","Sosedkoite","Souzalite","Součekite","Spadaite","Spaltiite","Spangolite","Spanoite","Spencerite","Sperlingite","Sperrylite","Spertiniite","Spessartine","Sphaerobertrandite","Sphaerobismoite","Sphalerite","Spheniscidite","Spherocobaltite","Spinel","Spionkopite","Spiridonovite","Spiroffite","Spriggite","Springcreekite","Spryite","Spurrite","Srebrodolskite","Srilankite","Stalderite","Stanevansite","Stanfieldite","Stangersite","Stankeithite","Stanleyite","Stannite","Stannoidite","Stannopalladinite","Staněkite","Starkeyite","Starovaite","Staročeskéite","Staurolite","Stavelotite-(La)","Steacyite","Steedeite","Steenstrupine-(Ce)","Stefanweissite","Steigerite","Steinhardtite","Steiningerite","Steinmetzite","Steklite","Stellerite","Stenhuggarite","Stenonite","Stepanovite","Stephanite","Stercorite","Stergiouite","Sterlinghillite","Sternbergite","Steropesite","Sterryite","Stetefeldtite","Stetindite-(Ce)","Steudelite","Stevensite","Steverustite","Stewartite","Stibarsen","Stibiconite","Stibioclaudetite","Stibiocolumbite","Stibiocolusite","Stibiogoldfieldite","Stibiopalladinite","Stibiosegnitite","Stibiotantalite","Stibioústalečite","Stibivanite","Stibnite","Stichtite","Stilbite-Ca","Stilbite-Na","Stilleite","Stillwaterite","Stillwellite-(Ce)","Stillwellite-(La)","Stilpnomelane","Stishovite","Stistaite","Stoiberite","Stokesite","Stolperite","Stolzite","Stoppaniite","Stornesite-(Y)","Stottite","Stracherite","Straczekite","Strakhovite","Strandite","Stranskiite","Strashimirite","Strassmannite","Strelkinite","Strengite","Stringhamite","Stromeyerite","Stronadelphite","Stronalsite","Strontianite","Strontio-orthojoaquinite","Strontioborite","Strontiochevkinite","Strontiodresserite","Strontiofluorite","Strontioginorite","Strontiohurlbutite","Strontiojoaquinite","Strontiomelane","Strontioperloffite","Strontiopharmacosiderite","Strontiopyrochlore","Strontioruizite","Strontiowhitlockite","Strunzite","Struvite","Struvite-(K)","Strätlingite","Studenitsite","Studtite","Stumpflite","Stunorthropite","Sturmanite","Stöfflerite","Stützite","Suanite","Sudburyite","Sudoite","Sudovikovite","Suenoite","Suessite","Sugakiite","Sugarwhiteite","Sugilite","Suhailite","Sulfadoxine","Sulfatoredmondite","Sulfhydrylbystrite","Sulfoborite","Sulfopadmaite","Sulphohalite","Sulphotsumoite","Sulphur","Sulvanite","Sundiusite","Sunshuite","Suolunite","Suredaite","Surite","Surkhobite","Sursassite","Susannite","Suseinargiuite","Sussexite","Suzukiite","Svabite","Svanbergite","Sveinbergeite","Sveite","Sverigeite","Svetlanaite","Svornostite-(K)","Svornostite-(NH_4_)","Svyatoslavite","Svyazhinite","Swaknoite","Swamboite-(Nd)","Swartzite","Swedenborgite","Sweetite","Swinefordite","Switzerite","Sylvanite","Sylvite","Symesite","Symplesite","Synadelphite","Synchysite-(Ce)","Synchysite-(Nd)","Synchysite-(Y)","Syngenite","Szaibélyite","Szenicsite","Szilagyiite","Szklaryite","Szmikite","Szomolnokite","Sztrókayite","Szymańskiite","Söhngeite","Sørensenite","Tacharanite","Tachyhydrite","Tadzhikite-(Ce)","Tadzhikite-(Y)","Taenite","Taikanite","Taimyrite","Taimyrite II","Tainiolite","Taipingite-(CeCa)","Takanawaite-(Y)","Takanelite","Takedaite","Takovite","Takéuchiite","Talc","Talmessite","Talnakhite","Tamaite","Tamarugite","Tamboite","Tamuraite","Tancaite-(Ce)","Tancoite","Taneyamalite","Tangdanite","Tangeite","Taniajacoite","Tanohataite","Tantalaeschynite-(Ce)","Tantalaeschynite-(Y)","Tantalcarbide","Tantalite-(Fe)","Tantalite-(Mg)","Tantalite-(Mn)","Tantalowodginite","Tanteuxenite-(Y)","Tantite","Tapiaite","Tapiolite-(Fe)","Tapiolite-(Mn)","Taramellite","Taramite","Taranakite","Tarapacáite","Tarbagataite","Tarbuttite","Tarkianite","Tartarosite","Tarutinoite","Taseqite","Tashelgite","Tassieite","Tatarinovite","Tatarskite","Tatyanaite","Tausonite","Tavagnascoite","Tavorite","Tazheranite","Tazieffite","Tazzoliite","Teallite","Tedhadleyite","Teepleite","Tegengrenite","Teineite","Telargpalite","Tellurantimony","Tellurite","Tellurium","Tellurobismuthite","Tellurocanfieldite","Tellurohauchecornite","Telluromandarinoite","Telluronevskite","Telluropalladinite","Telluroperite","Telyushenkoite","Temagamite","Tengchongite","Tengerite-(Y)","Tennantite-(Cd)","Tennantite-(Cu)","Tennantite-(Fe)","Tennantite-(Hg)","Tennantite-(In)","Tennantite-(Mn)","Tennantite-(Ni)","Tennantite-(Zn)","Tenorite","Tephroite","Terlinguacreekite","Terlinguaite","Ternesite","Ternovite","Terranovaite","Terrywallaceite","Terskite","Tertschite","Teruggite","Teschemacherite","Testibiopalladite","Tetra-auricupride","Tetradymite","Tetraferriannite","Tetraferriphlogopite","Tetraferroplatinum","Tetrahedrite-(Cd)","Tetrahedrite-(Cu)","Tetrahedrite-(Fe)","Tetrahedrite-(Hg)","Tetrahedrite-(Mn)","Tetrahedrite-(Ni)","Tetrahedrite-(Zn)","Tetrarooseveltite","Tetrataenite","Tetrawickmanite","Tewite","Thadeuite","Thalcusite","Thalfenisite","Thalhammerite","Thalliomelane","Thalliumpharmacosiderite","Thalénite-(Y)","Thaumasite","Thebaite-(NH_4_)","Theisite","Theoparacelsite","Theophrastite","Therasiaite","Thermaerogenite","Thermessaite","Thermessaite-(NH_4_)","Thermonatrite","Theuerdankite","Thionasilite","Thomasclarkite-(Y)","Thometzekite","Thomsenolite","Thomsonite-Ca","Thomsonite-Sr","Thorasphite","Thorbastnäsite","Thoreaulite","Thorianite","Thorikosite","Thoriopyrochlore","Thorite","Thornasite","Thorneite","Thorogummite","Thorosteenstrupine","Thorsite","Thortveitite","Thorutite","Threadgoldite","Thunderbayite","Thénardite","Thérèsemagnanite","Tianhongqiite","Tianhuixinite","Tiberiobardiite","Tibiscumite","Tiemannite","Tienshanite","Tietaiyangite","Tiettaite","Tikhonenkovite","Tilasite","Tilkerodeite","Tilleyite","Tillmannsite","Timroseite","Tin","Tinaksite","Tincalconite","Tinnunculite","Tinsleyite","Tinticite","Tintinaite","Tinzenite","Tiptopite","Tiragalloite","Tischendorfite","Tisinalite","Tistarite","Titanite","Titanium","Titanoholtite","Titanomaghemite","Titanowodginite","Titantaramellite","Tivanite","Tlalocite","Tlapallite","Tobelite","Tobermorite","Tochilinite","Tocornalite","Todorokite","Tokkoite","Tokyoite","Tolbachite","Toledoite","Tolovkite","Tolstykhite","Tomamaeite","Tombarthite-(Y)","Tombstoneite","Tomcampbellite","Tomichite","Tomiolloite","Tomsquarryite","Tondiite","Tongbaite","Tongxinite","Tooeleite","Topaz","Topsøeite","Torbernite","Torrecillasite","Torreyite","Torryweiserite","Tosudite","Toturite","Tounkite","Touretite","Townendite","Toyohaite","Trabzonite","Tranquillityite","Transjordanite","Traskite","Trattnerite","Treasurite","Trebiskyite","Trechmannite","Tredouxite","Trembathite","Trevorite","Triangulite","Triazolite","Tridymite","Trigodomeykite","Trigonite","Trikalsilite","Trilithionite","Trimerite","Trimounsite-(Y)","Trinepheline","Trinitite","Triphylite","Triplite","Triploidite","Trippkeite","Tripuhyite","Tristramite","Tritomite-(Ce)","Tritomite-(Y)","Trogtalite","Troilite","Trolleite","Trona","Truscottite","Trébeurdenite","Trögerite","Trüstedtite","Tsangpoite","Tsaregorodtsevite","Tschaunerite","Tschermakite","Tschermigite","Tschernichite","Tschörtnerite","Tsepinite-Ca","Tsepinite-K","Tsepinite-Na","Tsepinite-Sr","Tsikourasite","Tsilaisite","Tsnigriite","Tsugaruite","Tsumcorite","Tsumebite","Tsumgallite","Tsumoite","Tsygankoite","Tubulite","Tugarinovite","Tugtupite","Tuhualite","Tuite","Tulameenite","Tuliokite","Tululite","Tumchaite","Tundrite-(Ce)","Tundrite-(Nd)","Tunellite","Tungsten","Tungstenite","Tungstibite","Tungstite","Tungusite","Tunisite","Tuperssuatsiaite","Turanite","Turkestanite","Turneaureite","Turquoise","Turtmannite","Tuscanite","Tusionite","Tuzlaite","Tučekite","Tvalchrelidzeite","Tvedalite","Tveitite-(Y)","Tvrdýite","Tweddillite","Twinnite","Tychite","Tyretskite","Tyrolite","Tyrrellite","Tyuyamunite","Tzeferisite","Törnebohmite-(Ce)","Törnebohmite-(La)","Törnroosite","Uakitite","Uchucchacuaite","Udinaite","Uduminelite","Uedaite-(Ce)","Uklonskovite","Ulexite","Ulfanderssonite-(Ce)","Ullmannite","Ulrichite","Ulvöspinel","Umangite","Umbite","Umbozerite","Umbrianite","Umohoite","Ungavaite","Ungemachite","Upalite","Uralborite","Uralolite","Uramarsite","Uramphite","Urancalcarite","Uraninite","Uranmicrolite","Uranocircite","Uranocircite I","Uranoclite","Uranophane","Uranopilite","Uranopolycrase","Uranosilite","Uranospathite","Uranosphaerite","Uranospinite","Uranotungstite","Uranpyrochlore","Urea","Uricite","Uroxite","Urphoite","Ursilite","Urusovite","Urvantsevite","Ushkovite","Usovite","Ussingite","Ustarasite","Usturite","Utahite","Uvanite","Uvarovite","Uvite","Uytenbogaardtite","Uzonite","Vadlazarenkovite","Vaesite","Vajdakite","Vakhrushevaite","Valentinite","Valleriite","Valleyite","Vallouiseite","Vanackerite","Vanadinite","Vanadio-oxy-chromium-dravite","Vanadio-oxy-dravite","Vanadio-pargasite","Vanadiocarpholite","Vanadium","Vanadoakasakaite-(Ce)","Vanadoakasakaite-(La)","Vanadoallanite-(La)","Vanadoandrosite-(Ce)","Vanadomalayaite","Vanalite","Vanarsite","Vandenbrandeite","Vandendriesscheite","Vanderheydenite","Vandermeerscheite","Vaniniite","Vanmeersscheite","Vanoxite","Vanpeltite","Vantasselite","Vanthoffite","Vanuralite","Vapnikite","Varennesite","Vargite","Variscite","Varlamoffite","Varulite","Vashegyite","Vasilite","Vasilseverginite","Vasilyevite","Vaterite","Vaughanite","Vauquelinite","Vauxite","Vavřínite","Veatchite","Veatchite-A","Veatchite-p","Veblenite","Veenite","Vegrandisite","Velikite","Vendidaite","Verbeekite","Verbierite","Vergasovaite","Vermiculite","Vernadite","Verneite","Verplanckite","Versiliaite","Vertumnite","Veselovskýite","Vestaite","Vesuvianite","Veszelyite","Viaeneite","Vicanite-(Ce)","Vielleaureite-(Ce)","Vigezzite","Vigrishinite","Vihorlatite","Viitaniemiite","Vikingite","Villamanínite","Villiaumite","Villyaellenite","Vimsite","Vincentite","Vinciennite","Vinogradovite","Violarite","Virgilite","Virgilluethite","Vishnevite","Viskontite","Vismirnovite","Vistepite","Viséite","Viteite","Vitimite","Vittinkiite","Vitusite-(Ce)","Vivianite","Vladimirite","Vladimirivanovite","Vladkrivovichevite","Vladkuzminite","Vladykinite","Vlasovite","Vlodavetsite","Vochtenite","Voggite","Voglite","Volaschioite","Volborthite","Volkonskoite","Volkovskite","Voloshinite","Voltaite","Volynskite","Vonbezingite","Vondechenite","Vonsenite","Vorlanite","Voronkovite","Vorontsovite","Voudourisite","Vozhminite","Vrančiceite","Vrbaite","Vránaite","Vuagnatite","Vulcanite","Vuonnemite","Vuorelainenite","Vuoriyarvite-K","Vurroite","Vyacheslavite","Vyalsovite","Vymazalováite","Vysokýite","Vysotskite","Vyuntspakhkite-(Y)","Västmanlandite-(Ce)","Väyrynenite","Vésigniéite","Wadalite","Wadeite","Wadsleyite","Wagnerite","Waimirite-(Y)","Waipouaite","Wairakite","Wairauite","Wakabayashilite","Wakefieldite-(Ce)","Wakefieldite-(La)","Wakefieldite-(Nd)","Wakefieldite-(Y)","Walentaite","Walfordite","Walkerite","Wallisite","Wallkilldellite","Wallkilldellite-(Fe)","Walpurgite","Walstromite","Walthierite","Wampenite","Wangdaodeite","Wangkuirenite","Wangpuite","Wangxibinite","Wangyanite","Wardite","Wardsmithite","Warikahnite","Warkite","Warwickite","Wassonite","Watanabeite","Watatsumiite","Waterhouseite","Watkinsonite","Wattersite","Wattevilleite","Wavellite","Wawayandaite","Waylandite","Wayneburnhamite","Weberite","Weddellite","Weeksite","Wegscheiderite","Weibullite","Weilerite","Weilite","Weinebeneite","Weishanite","Weissbergite","Weissite","Welinite","Weloganite","Welshite","Wendwilsonite","Wenjiite","Wenkite","Wenlanzhangite-(Y)","Wenqingite","Werdingite","Wermlandite","Wernerbaurite","Wernerkrauseite","Wesselsite","Westerveldite","Wetherillite","Wheat starch","Wheatleyite","Whelanite","Wherryite","Whewellite","Whitecapsite","Whiteite-(CaFeMg)","Whiteite-(CaMgMg)","Whiteite-(CaMnFe)","Whiteite-(CaMnMg)","Whiteite-(CaMnMn)","Whiteite-(MnFeMg)","Whiteite-(MnMnMg)","Whiteite-(MnMnMn)","Whiterockite","Whitlockite","Whitmoreite","Whittakerite","Wickenburgite","Wickmanite","Wicksite","Widenmannite","Widgiemoolthalite","Wightmanite","Wiklundite","Wilancookite","Wilcoxite","Wildcatite","Wildenauerite","Wilhelmgümbelite","Wilhelmkleinite","Wilhelmramsayite","Wilhelmvierlingite","Wilkinsonite","Wilkmanite","Willemite","Willemseite","Willhendersonite","Willyamite","Wiluite","Windhoekite","Windmountainite","Winstanleyite","Wiperamingaite","Wiserite","Witherite","Wittichenite","Wittite","Wittite B","Witzkeite","Wodegongjieite","Wodginite","Wolfeite","Wolfsriedite","Wollastonite-2M","Wonesite","Woodallite","Woodhouseite","Woodruffite","Woodwardite","Wooldridgeite","Wopmayite","Wortupaite","Wrightite","Wroewolfeite","Wulfenite","Wulffite","Wumuite","Wupatkiite","Wurtzite","Wuyanzhiite","Wyartite","Wyartite II","Wycheproofite","Wyllieite","Wöhlerite","Wölsendorfite","Wülfingite","Wüstite","Xanthiosite","Xanthoconite","Xanthoxenite","Xenophyllite","Xenotime-(Gd)","Xenotime-(Y)","Xenotime-(Yb)","Xiangjiangite","Xianhuaite-(Ce)","Xieite","Xiexiandeite","Xifengite","Xilingolite","Ximengite","Xingzhongite","Xitieshanite","Xocolatlite","Xocomecatlite","Xonotlite","Xuite","Xuwenyuanite","Yafsoanite","Yagiite","Yakhontovite","Yakovenchukite-(Y)","Yakubovichite","Yamhamelachite","Yancowinnaite","Yangzhumingite","Yanomamite","Yarlongite","Yaroshevskite","Yaroslavite","Yarrowite","Yarzhemskiite","Yavapaiite","Yazganite","Ye'elimite","Yeatmanite","Yecoraite","Yedlinite","Yegorovite","Yeite","Yellowcatite","Yeomanite","Yimengite","Yingjiangite","Yixunite","Yoderite","Yofortierite","Yoshimuraite","Yoshiokaite","Yttriaite-(Y)","Yttrialite-(Y)","Yttrium aluminum garnet","Yttrobetafite-(Y)","Yttrocolumbite-(Y)","Yttrocrasite-(Y)","Yttropyrochlore-(Y)","Yttrotantalite-(Y)","Yttrotungstite-(Ce)","Yttrotungstite-(Nd)","Yttrotungstite-(Y)","Yuanfuliite","Yuanjiangite","Yuchuanite-(Y)","Yugawaralite","Yukonite","Yuksporite","Yunhaoite","Yurgensonite","Yurmarinite","Yushkinite","Yusupovite","Yuzuxiangite","Yvonite","Zabuyelite","Zaccagnaite","Zaccariniite","Zadovite","Zagamiite","Zaherite","Zakharovite","Zanazziite","Zanelliite","Zangboite","Zapatalite","Zaratite","Zavalíaite","Zavaritskite","Zavyalovite","Zaykovite","Zaïrite","Zdenĕkite","Zektzerite","Zellerite","Zemannite","Zemkorite","Zenzénite","Zeophyllite","Zeravshanite","Zeunerite","Zhanghengite","Zhanghuifenite","Zhangpeishanite","Zharchikhite","Zhemchuzhnikovite","Zhengminghuaite","Zhenruite","Zheshengite","Zhiqinite","Zhonghongite","Zhonghuacerite-(Ce)","Ziesite","Zigrasite","Zilbermintsite-(La)","Zimbabweite","Ziminaite","Zinc","Zincalstibite","Zincaluminite","Zinccopperite","Zincgartrellite","Zincite","Zinclipscombite","Zincmelanterite","Zincoberaunite","Zincobotryogen","Zincobradaczekite","Zincobriartite","Zincochenite","Zincochromite","Zincocopiapite","Zincohögbomite-2N2S","Zincohögbomite-2N6S","Zincolibethenite","Zincolivenite","Zincomenite","Zinconigerite-2N1S","Zinconigerite-6N6S","Zincorietveldite","Zincorinmanite-(Zn)","Zincospiroffite","Zincostaurolite","Zincostottite","Zincostrunzite","Zincovelesite-6N6S","Zincovoltaite","Zincowoodwardite","Zincrosasite","Zincroselite","Zincsilite","Zinczippeite","Zinkenite","Zinkgruvanite","Zinkosite","Zinnwaldite","Zippeite","Zipserite","Zircarsite","Zircon","Zirconolite","Zirconolite-3O","Zirconolite-3T","Zircophyllite","Zircosulfate","Zirkelite","Zirklerite","Ziroite","Zirsilite-(Ce)","Zirsinalite","Zlatogorite","Znamenskyite","Znucalite","Zodacite","Zoharite","Zoisite","Zoisite-(Pb)","Zolenskyite","Zolotarevite","Zoltaiite","Zorite","Zoubekite","Zoyashlyukovaite","Zubkovaite","Zugshunstite-(Ce)","Zuktamrurite","Zunyite","Zuolinite","Zussmanite","Zvyaginite","Zvyagintsevite","Zvĕstovite-(Fe)","Zvĕstovite-(Zn)","Zwieselite","Zálesíite","Zýkaite","ferropseudobrookite","unknown","Ángelaite","Åkermanite","Åsgruvanite-(Ce)","Åskagenite-(Nd)","Écrinsite","Örebroite","Čechite","Čejkaite","Černýite","Škáchaite","Šlikite","Šreinite","Štěpite","Švenekite","Żabińskiite");
//
//
//            $array_not_downs_and_pyroxene = array_intersect($array_pyroxene, $array_not_downs);
//            sort($array_not_downs_and_pyroxene);
//            print '<pre>'.count($array_not_downs_and_pyroxene)."\n".print_r($array_not_downs_and_pyroxene, true).'</pre>';
//            $params = array('dt_id' => 736, 7035 => '!downs', 7036 => 'pyroxene', '7062' => "*1094,*1104", 'set' => '1');  // should return 39 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCI3MDM1IjoiIWRvd25zIiwiNzAzNiI6InB5cm94ZW5lIiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCI3MDM1IjoiIWRvd25zIiwiNzAzNiI6InB5cm94ZW5lIiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//
//            $array_downs_and_not_pyroxene = array_intersect($array_downs, $array_not_pyroxene);
//            sort($array_downs_and_not_pyroxene);
//            print '<pre>'.count($array_downs_and_not_pyroxene)."\n".print_r($array_downs_and_not_pyroxene, true).'</pre>';
//            $params = array('dt_id' => 736, 7035 => 'downs', 7036 => '!pyroxene', '7062' => "*1094,*1104", 'set' => '1');  // should return 350 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCI3MDM1IjoiIWRvd25zIiwiNzAzNiI6InB5cm94ZW5lIiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCI3MDM1IjoiIWRvd25zIiwiNzAzNiI6InB5cm94ZW5lIiwiNzA2MiI6IioxMDk0LCoxMTA0In0
//
//            $array_not_downs_or_pyroxene = array_unique( array_merge($array_not_downs, $array_pyroxene) );
//            sort($array_not_downs_or_pyroxene);
//            print '<pre>'.count($array_not_downs_or_pyroxene)."\n".print_r($array_not_downs_or_pyroxene, true).'</pre>';
//            $params = array('dt_id' => 736, 7035 => '!downs', 7036 => 'pyroxene', '7062' => "*1094,*1104", 'merge' => 'OR', 'set' => '1');  // should return 6184 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCJtZXJnZSI6Ik9SIiwiNzAzNSI6IiFkb3ducyIsIjcwMzYiOiJweXJveGVuZSIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCJtZXJnZSI6Ik9SIiwiNzAzNSI6IiFkb3ducyIsIjcwMzYiOiJweXJveGVuZSIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9
//
//            $array_downs_or_not_pyroxene = array_unique( array_merge($array_downs, $array_not_pyroxene) );
//            sort($array_downs_or_not_pyroxene);
//            print '<pre>'.count($array_downs_or_not_pyroxene)."\n".print_r($array_downs_or_not_pyroxene, true).'</pre>';
//            $params = array('dt_id' => 736, 7035 => 'downs', 7036 => '!pyroxene', '7062' => "*1094,*1104", 'merge' => 'OR', 'set' => '1');  // should return 6495 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCJtZXJnZSI6Ik9SIiwiNzAzNSI6ImRvd25zIiwiNzAzNiI6IiFweXJveGVuZSIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCJtZXJnZSI6Ik9SIiwiNzAzNSI6ImRvd25zIiwiNzAzNiI6IiFweXJveGVuZSIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9
//
//            $array_not_downs_and_not_pyroxene = array_intersect($array_not_downs, $array_not_pyroxene);
//            print '<pre>'.count($array_not_downs_and_not_pyroxene)."\n".print_r($array_not_downs_and_not_pyroxene, true).'</pre>';
//            $params = array('dt_id' => 736, 7035 => '!downs', 7036 => '!pyroxene', '7062' => "*1094,*1104", 'set' => '1');  // should return 6124 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCI3MDM1IjoiIWRvd25zIiwiNzAzNiI6IiFweXJveGVuZSIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCI3MDM1IjoiIWRvd25zIiwiNzAzNiI6IiFweXJveGVuZSIsIjcwNjIiOiIqMTA5NCwqMTEwNCJ9
//
//            $array_not_downs_or_not_pyroxene = array_unique( array_merge($array_not_downs, $array_not_pyroxene) );
//            sort($array_not_downs_or_not_pyroxene);
//            print '<pre>'.count($array_not_downs_or_not_pyroxene)."\n".print_r($array_not_downs_or_not_pyroxene, true).'</pre>';
//            $params = array('dt_id' => 736, 7035 => '!downs', 7036 => '!pyroxene', '7062' => "*1094,*1104", 'merge' => 'OR', 'set' => '1');  // should return 6513 results
//            https://theta.odr.io/app_dev.php/rruff_reference#/app_dev.php/search/display/0/eyJkdF9pZCI6NzM2LCJtZXJnZSI6Ik9SIiwiNzAzNSI6IiFkb3ducyIsIjcwMzYiOiIhcHlyb3hlbmUiLCI3MDYyIjoiKjEwOTQsKjExMDQifQ
//            https://theta.odr.io/app_dev.php/admin/asdf/eyJkdF9pZCI6NzM2LCJtZXJnZSI6Ik9SIiwiNzAzNSI6IiFkb3ducyIsIjcwMzYiOiIhcHlyb3hlbmUiLCI3MDYyIjoiKjEwOTQsKjExMDQifQ


            // ----------------------------------------
            // ----------------------------------------
            // ----------------------------------------
//
//            $params = array(
//                'dt_id' => 3,
//                '1' => '""',
////                'ignore' => '3_2'
////                'set' => '1'
//            );
//            $search_as_super_admin = false;

            // TODO - need additional "complete datarecord list" testing, probably

            // ----------------------------------------
            if ($search_key === '')
                $search_key = $search_key_service->encodeSearchKey($params);
            else
                $params = $search_key_service->decodeSearchKey($search_key);
            print '<pre>'.$search_key.'</pre>';
            print '<pre>'.print_r($params, true).'</pre>';


            // ----------------------------------------
            $desired_datatype_id = intval($params['dt_id']);
            $family_datatype_id = $desired_datatype_id;
            if ( isset($params['inverse']) ) {
                $inverse_dt_id = intval($params['inverse']);
                if ($inverse_dt_id > 0)
                    $family_datatype_id = intval($params['inverse']);
            }

            /** @var DataType $family_datatype */
            $family_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($family_datatype_id);
            if ($family_datatype == null)
                throw new ODRNotFoundException('Datatype');
            /** @var DataType $desired_datatype */
            $desired_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($desired_datatype_id);
            if ($desired_datatype == null)
                throw new ODRNotFoundException('Datatype');


            $datatype_ids = $datatree_info_service->getAssociatedDatatypes($family_datatype_id, true);
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id, dtm.shortName AS dt_name
                FROM ODRAdminBundle:DataType dt
                JOIN ODRAdminBundle:DataTypeMeta dtm WITH dtm.dataType = dt
                WHERE dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatype_ids) );
            $results = $query->getArrayResult();

            $dt_names = array();
            foreach ($results as $result)
                $dt_names[ $result['dt_id'] ] = $result['dt_name'];

//            unset($dt_names[818]);
            print '<pre>'.print_r($dt_names, true).'</pre>';

            $dr_names = array();
            foreach ($dt_names as $dt_id => $dt_name)
                $dr_names[$dt_id] = $sort_service->getNamedDatarecordList($dt_id);
//            print '<pre>'.print_r($dr_names, true).'</pre>';  exit();

            if ($complete === '')
                $complete = false;
            else
                $complete = true;
//            $complete = true;

            $grandparent_ids = $search_api_service->performSearch(
                $desired_datatype,
                $search_key,
                $user_permissions,
                $complete,
                array(),
                array(),
                $search_as_super_admin,
                $ignore_searchable,
                false
            );

            $results = array();
            foreach ($grandparent_ids as $num => $dr_id)
                $results[$dr_id] = $dr_names[$desired_datatype_id][$dr_id];
            asort($results);

            print '<pre># of $results: '.count($results).'</pre>';
            print '<pre>'.print_r($results, true).'</pre>';

            exit();

            $set = $array_not_downs_or_not_pyroxene;

            foreach ($set as $num => $val)
                $set[$num] = str_replace(array('<i>', '</i>'), '', $val);
            foreach ($results as $num => $val)
                $results[$num] = str_replace(array('<i>', '</i>'), '', $val);

            $diff_1 = array_diff($set, $results);
            print '<pre>'.count($diff_1)."\n".print_r($diff_1, true).'</pre>';
            $diff_2 = array_diff($results, $set);
            print '<pre>'.count($diff_2)."\n".print_r($diff_2, true).'</pre>';

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
//        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    const HAS_GENERAL_SEARCH_CRITERIA = 0b0001;
    const HAS_ADV_SEARCH_CRITERIA = 0b0010;

    /**
     * @param array $graph
     * @param array $counts
     * @param array $dt_names
     * @param int $current_datatype_id
     * @param int $indent
     */
    private function printgraph($graph, $counts, $dt_names, $current_datatype_id, $indent = 0)
    {
        for ($i = 0; $i < $indent; $i++)
            print "    ";

        print '('.$current_datatype_id.') '.$dt_names[$current_datatype_id].': '.$counts[$current_datatype_id]."\n";
        if ( isset($graph[$current_datatype_id]) ) {
            foreach ($graph[$current_datatype_id] as $child_dt_id => $tmp)
                self::printgraph($graph, $counts, $dt_names, $child_dt_id, $indent + 1);
        }
    }
}
