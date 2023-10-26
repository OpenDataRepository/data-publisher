<?php

/**
 * Open Data Repository Data Publisher
 * CSVExportWorker Command
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This Symfony console command takes beanstalk jobs from the
 * csv_export_worker tube and passes the parameters to CSVExportController.
 *
 */

namespace ODR\AdminBundle\Command;

use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ddeboer\DataImport\Writer\CsvWriter;



class CSVExportExpressWorkerCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_csv_export:worker_express')
            ->setDescription('Does the work of writing lines of CSV data to file');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln( 'CSV Express Export Start' );
        // Only need to load these once...
        $container = $this->getContainer();
        $logger = $container->get('logger');
        $pheanstalk = $container->get('pheanstalk');

        // TODO - generate a random number to use for identifying a file
        $tokenGenerator = $container->get('fos_user.util.token_generator');

        // Run command until manually stopped
        while (true) {
            $job = null;
            try {
                // Wait for a job?
                $job = $pheanstalk->watch('csv_export_worker_express')->ignore('default')->reserve();

                // Get Job Data
                $data = json_decode($job->getData());

                // Display info about job
                $str = 'CSVExportWorker request for DataRecords';
                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln($str);
                $output->writeln($data->url);

                // TODO - determine filename
                $random_id = substr($tokenGenerator->generateToken(), 0, 8);
                $random_key = $random_id.'_'.$data->datatype_id.'_'.$data->tracked_job_id;

                // Create the required url and the parameters to send
                $parameters = array(
                    'tracked_job_id' => $data->tracked_job_id,
                    'user_id' => $data->user_id,

                    'delimiter' => $data->delimiter,
                    'file_image_delimiter' => $data->file_image_delimiter,
                    'radio_delimiter' => $data->radio_delimiter,
                    'tag_delimiter' => $data->tag_delimiter,
                    'tag_hierarchy_delimiter' => $data->tag_hierarchy_delimiter,

                    'datatype_id' => $data->datatype_id,
                    'datarecord_id' => $data->datarecord_id,
                    'complete_datarecord_list' => $data->complete_datarecord_list,
                    'datafields' => $data->datafields,

                    'api_key' => $data->api_key,
                    'random_key' => $random_key,
                );

                if ( !isset($parameters['tracked_job_id'])
                    || !isset($parameters['user_id'])
                    || !isset($parameters['delimiter'])

                    || !isset($parameters['datatype_id'])
                    || !isset($parameters['datarecord_id'])
                    || !isset($parameters['complete_datarecord_list'])
                    || !isset($parameters['datafields'])

                    || !isset($parameters['api_key'])
                    || !isset($parameters['random_key'])
                ) {
                    throw new ODRBadRequestException();
                }

                // Pull data from the parameters
                $tracked_job_id = intval($parameters['tracked_job_id']);
                $user_id = $parameters['user_id'];

                $datatype_id = $parameters['datatype_id'];
                $datarecord_ids = $parameters['datarecord_id'];
                $complete_datarecord_list_array = $parameters['complete_datarecord_list'];
                $datafields = $parameters['datafields'];

                $api_key = $parameters['api_key'];
                $random_key = $parameters['random_key'];

                // Don't need to do any additional verification on these...that was handled back in
                //  csvExportStartAction()
                $delimiters = array(
                    'base' => $parameters['delimiter'],
                    'file' => null,
                    'radio' => null,
                    'tag' => null,
                    'tag_hierarchy' => null,
                );

                if ( isset($parameters['file_image_delimiter']) )
                    $delimiters['file'] = $parameters['file_image_delimiter'];

                if ( isset($parameters['radio_delimiter']) )
                    $delimiters['radio'] = $parameters['radio_delimiter'];
                if ( $delimiters['radio'] === 'space' )
                    $delimiters['radio'] = ' ';

                if ( isset($parameters['tag_delimiter']) )
                    $delimiters['tag'] = $parameters['tag_delimiter'];

                if ( isset($parameters['tag_hierarchy_delimiter']) )
                    $delimiters['tag_hierarchy'] = $parameters['tag_hierarchy_delimiter'];



                // // Load symfony objects
                // $beanstalk_api_key = $container->getParameter('beanstalk_api_key');
                // /** @var Pheanstalk $pheanstalk */
                // $pheanstalk = $this->get('pheanstalk');
//
                // if ($api_key !== $beanstalk_api_key)
                    // throw new ODRBadRequestException();


                /** @var \Doctrine\ORM\EntityManager $em */
                $em = $container->get('doctrine')->getEntityManager();

                /** @var DatabaseInfoService $dbi_service */
                $dbi_service = $container->get('odr.database_info_service');
                /** @var DatarecordInfoService $dri_service */
                $dri_service = $container->get('odr.datarecord_info_service');
                /** @var DatatreeInfoService $dti_service */
                $dti_service = $container->get('odr.datatree_info_service');
                /** @var PermissionsManagementService $pm_service */
                $pm_service = $container->get('odr.permissions_management_service');


                /** @var DataType $datatype */
                $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
                if ($datatype == null)
                    throw new ODRNotFoundException('Datatype');
                if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                    throw new ODRBadRequestException('Unable to run CSVExport from a child datatype');

                // This doesn't make sense on a master datatype
                if ( $datatype->getIsMasterType() )
                    throw new ODRBadRequestException('Unable to export from a master template');


                // ----------------------------------------
                // Need the user to be able to filter data
                /** @var ODRUser $user */
                $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
                if ($user == null || !$user->isEnabled())
                    throw new ODRNotFoundException('User');

                // Ensure user has permissions to be doing this
                if ( !$pm_service->canViewDatatype($user, $datatype) )
                    throw new ODRForbiddenException();

                // Perform filtering before attempting to find anything else
                $user_permissions = $pm_service->getUserPermissionsArray($user);
                $dt_array = $dbi_service->getDatatypeArray($datatype_id, true);    // may need linked datatypes


                // ----------------------------------------
                // Gather basic info about all datafields prior to actually loading data
                // If tags are being exported, then additional information will be needed
                $tag_data = array(
                    'names' => array(),
                    'tree' => array(),
                );

                // Ensure this datatype's external id field is going to be exported, if one exists
                $external_id_field = $dt_array[$datatype_id]['dataTypeMeta']['externalIdField'];
                if ( !is_null($external_id_field) )
                    $datafields[] = $external_id_field['id'];

                // Need to locate fieldtypes of all datafields that are going to be exported
                $flipped_datafields = array_flip($datafields);
                $datafields_to_export = array();
                foreach ($dt_array as $dt_id => $dt) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        if ( isset($flipped_datafields[$df_id]) ) {
                            $fieldtype = $df['dataFieldMeta']['fieldType'];
                            $typeclass = $fieldtype['typeClass'];
                            $typename = $fieldtype['typeName'];

                            // All fieldtypes except for Markdown can be exported
                            if ($typename !== 'Markdown')
                                $datafields_to_export[$df_id] = $typeclass;

                            // If exporting a tag datafield...
                            if ( $typename === 'Tag' && isset($df['tags']) ) {
                                // The tags are stored in a tree structure to make displaying them
                                //  easier...but for export, it's easier if they're flattened
                                $tag_data['names'] = self::getTagNames($df['tags']);
                                // The export process also needs to be able to locate the name of a
                                //  parent tag from a child tag
                                $tag_data['tree'] = self::getTagTree($df['tagTree']);
                            }

                            // "Mark" this datafield as seen
                            unset( $flipped_datafields[$df_id] );
                        }
                    }
                }


                // If any entries remain in $flipped_datafields...they're either datafields the user can't
                //  view, or they belong to unrelated datatypes.  Neither should happen, at this point.
                if ( !empty($flipped_datafields) ) {
                    $df_ids = implode(',', array_keys($flipped_datafields));
                    throw new ODRBadRequestException('Unable to locate Datafields "'.$df_ids.'" for User '.$user_id.', Datatype '.$datatype_id);
                }


                $lines = array();
                for($i = 0; $i < count($datarecord_ids); $i++) {
                    $datarecord_id = $datarecord_ids[$i];

                    /** @var DataRecord $datarecord */
                    $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
                    if ($datarecord == null)
                        throw new ODRNotFoundException('Datarecord');

                    if ($datarecord->getDataType()->getId() !== $datatype->getId())
                        throw new ODRBadRequestException('Datarecord does not match Datatype');

                    $dr_array = $dri_service->getDatarecordArray($datarecord->getId(), true);    // may need links
                    $pm_service->filterByGroupPermissions($dt_array, $dr_array, $user_permissions);

                    // ----------------------------------------
                    // Stack the cached version of the datarecord array to make recursion work
                    $dr_array = array(
                        $datarecord->getId() => $dri_service->stackDatarecordArray($dr_array, $datarecord->getId())
                    );

                    // Remove all datarecords and datafields from the stacked datarecord array that the
                    //  user doesn't want to export
                    $datarecords_to_export = array_flip($complete_datarecord_list_array[$i]);
                    $filtered_dr_array = self::filterDatarecordArray($dr_array, $datafields_to_export, $datarecords_to_export, $tag_data, $delimiters);


                    // ----------------------------------------
                    // In order to deal with child/linked datatypes correctly, the CSV exporter needs to know
                    //  which child/linked datatypes allow multiple child/linked records
                    $datatree_array = $dti_service->getDatatreeArray();

                    // Unfortunately, this CSV exporter needs to be able to deal with the possibility of
                    //  exporting more than one child/linked datatype that allows multiple child/linked
                    // records.

                    // For visualization purposes...  TODO
                    // Sample (top-level)
                    //   |- Mineral (only one allowed per Sample)
                    //   |   |- Reference (multiple allowed per Mineral)
                    //   |- Raman (multiple allowed per Sample)
                    //   |- Infrared (multiple allowed per Sample)
                    //   |- etc

                    // Child/linked datatypes that only allow a single child/linked datarecord should get
                    //  combined with their parent
                    $combined_dr_array = array();
                    foreach ($filtered_dr_array as $dr_id => $dr_array)
                        $combined_dr_array[$dr_id] = self::mergeSingleChildtypes($datatree_array, $datatype_id, $dr_array);

                    // Any remaining child/linked datatypes that permit multiple child/linked datarecords
                    //  need to get recursively merged together
                    $datarecord_data = self::mergeMultipleChildtypes($combined_dr_array);

                    // Need to ensure all fields are always in the output and that the output is always in
                    //  the same order
                    foreach ($datarecord_data as $num => $data) {
                        $line = array();
                        foreach ($datafields_to_export as $df_id => $typeclass) {
                            // Due to the possibility of child/linked datatypes allowing multiple child/linked
                            //  records, the filtered/merged data arrays may not have entries for all of
                            //  the fields selected for export
                            if ( isset($data[$df_id]) )
                                $line[$df_id] = $data[$df_id];
                            else
                                $line[$df_id] = '';
                        }

                        // Store the line so it can be written to a csv file
                        $lines[] = $line;
                    }
                }

                // Echo Lines for Debugging
                // foreach ($lines as $line) {
                    // $output->writeln($line);
                // }

                $output->writeln('Random Key: ' . $random_key . ' ' . $tracked_job_id);
                // ----------------------------------------
                // Ensure the random key is stored in the database for later retrieval
                // by the finalization process
                $tracked_csv_export = $em->getRepository('ODRAdminBundle:TrackedCSVExport')
                    ->findOneBy( array('random_key' => $random_key) );
                if ($tracked_csv_export == null) {
                    $query =
                        'INSERT INTO odr_tracked_csv_export 
                            (random_key, tracked_job_id, finalize)
                            SELECT * FROM (SELECT :random_key AS random_key, 
                                :tj_id AS tracked_job_id, :finalize AS finalize) AS tmp
                            WHERE NOT EXISTS (
                                SELECT random_key FROM odr_tracked_csv_export 
                                WHERE random_key = :random_key AND tracked_job_id = :tj_id
                            ) LIMIT 1;';
                    $params = array(
                        'random_key' => $random_key,
                        'tj_id' => $tracked_job_id,
                        'finalize' => 0
                    );
                    $conn = $em->getConnection();
                    $rowsAffected = $conn->executeUpdate($query, $params);
                    $output->writeln('Rows Affected (Random Key): ' . $rowsAffected);

                    //print 'rows affected: '.$rowsAffected."\n";
                }

                // Ensure directories exists
                $csv_export_path = $container->getParameter('odr_tmp_directory').'/user_'.$user_id.'/';
                if ( !file_exists($csv_export_path) )
                    mkdir( $csv_export_path );
                $csv_export_path .= 'csv_export/';
                if ( !file_exists($csv_export_path) )
                    mkdir( $csv_export_path );

                // Open the indicated file
                $filename = 'f_'.$random_key.'.csv';
                $output->writeln('Export File: ' . $csv_export_path.$filename);
                $handle = fopen($csv_export_path.$filename, 'a');
                if ($handle !== false) {
                    // Write the line given to the file
                    // https://github.com/ddeboer/data-import/blob/master/src/Ddeboer/DataImport/Writer/CsvWriter.php
                    // $delimiter = "\t";
                    $enclosure = "\"";
                    $writer = new CsvWriter($delimiters['base'], $enclosure);

                    $writer->setStream($handle);

                    foreach ($lines as $line) {
                        $writer->writeItem($line);
                    }

                    // Close the file
                    fclose($handle);
                }
                else {
                    // Unable to open file
                    throw new ODRException('Could not open csv worker export file.');
                }


                // ----------------------------------------
                // Update the job tracker if necessary
                $completed = false;
                if ($tracked_job_id !== -1) {
                    /** @var TrackedJob $tracked_job */
                    $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')
                        ->find($tracked_job_id);

                    $total = $tracked_job->getTotal();
                    for($i = 0; $i < count($datarecord_ids); $i++) {
                        $count = $tracked_job->incrementCurrent($em);
                    }

                    if ($count >= $total) {
                        $tracked_job->setCompleted( new \DateTime() );
                        $completed = true;
                    }

                    $em->persist($tracked_job);
                    $em->flush();
                    //print '  Set current to '.$count."\n";
                }


                // ----------------------------------------
                // If this was the last line to write to be written to a file for
                // this particular export...
                // NOTE - incrementCurrent()'s current implementation can't
                // guarantee that only a single process will enter this block...
                // so have to ensure that only one process starts the finalize step
                //
                $random_keys = array();
                if ($completed) {
                    // Make a hash from all the random keys used
                    $query = $em->createQuery(
                        'SELECT tce.id AS id, tce.random_key AS random_key
                            FROM ODRAdminBundle:TrackedCSVExport AS tce
                            WHERE tce.trackedJob = :tracked_job AND tce.finalize = 0
                            ORDER BY tce.id'
                    )->setParameters( array('tracked_job' => $tracked_job_id) );
                    $results = $query->getArrayResult();

                    // Due to ORDER BY, every process entering this section
                    // should compute the same $random_key_hash
                    $random_key_hash = '';
                    foreach ($results as $num => $result) {
                        $random_keys[ $result['id'] ] = $result['random_key'];
                        $random_key_hash .= $result['random_key'];
                    }
                    $random_key_hash = md5($random_key_hash);

                    // Attempt to insert this hash back into the database...
                    // NOTE: this uses the same random_key field as the previous
                    // INSERT WHERE NOT EXISTS query...the first time it had an 8
                    // character string inserted into it, this time it's taking a
                    // 32 character string
                    $query =
                        'INSERT INTO odr_tracked_csv_export 
                            (random_key, tracked_job_id, finalize)
                            SELECT * FROM 
                                (SELECT :random_key_hash AS random_key, 
                                        :tj_id AS tracked_job_id, 
                                        :finalize AS finalize) AS tmp
                                WHERE NOT EXISTS (
                                    SELECT random_key FROM odr_tracked_csv_export 
                                    WHERE random_key = :random_key_hash 
                                    AND tracked_job_id = :tj_id AND finalize = :finalize
                            ) LIMIT 1;';
                    $params = array('random_key_hash' => $random_key_hash, 'tj_id' => $tracked_job_id, 'finalize' => 1);
                    $conn = $em->getConnection();
                    $rowsAffected = $conn->executeUpdate($query, $params);

                    if ($rowsAffected == 1) {
                        // This is the first process to attempt to insert this key...
                        // it will be in charge of creating the information used to i
                        // concatenate the temporary files together
                        $completed = true;
                    }
                    else {
                        // This is not the first process to attempt to insert this key,
                        // do nothing so multiple finalize jobs aren't created
                        $completed = false;
                    }
                }


                // ----------------------------------------
                if ($completed) {
                    // Determine the contents of the header line
                    $dt_array = $dbi_service->getDatatypeArray($datatype_id, true);    // may need linked datatypes

                    // Need to locate fieldnames of all datafields that were exported...
                    //  recreate the $flipped_datafields array
                    $flipped_datafields = array_flip($datafields);

                    $header_line = array();
                    foreach ($dt_array as $dt_id => $dt) {
                        foreach ($dt['dataFields'] as $df_id => $df) {
                            if ( isset($flipped_datafields[$df_id]) ) {
                                $typename = $df['dataFieldMeta']['fieldType']['typeName'];
                                $fieldname = $df['dataFieldMeta']['fieldName'];

                                // All fieldtypes except for Markdown can be exported
                                if ($typename !== 'Markdown')
                                    $header_line[$df_id] = $fieldname;
                            }
                        }
                    }

                    // Make a "final" file for the export, and insert the header line
                    $final_filename = 'export_'.$user_id.'_'.$tracked_job_id.'.csv';
                    $final_file = fopen($csv_export_path.$final_filename, 'w');

                    if ($final_file !== false) {
                        $enclosure = "\"";
                        $writer = new CsvWriter($delimiters['base'], $enclosure);

                        $writer->setStream($final_file);
                        $writer->writeItem($header_line);
                    }
                    else {
                        throw new ODRException('Could not open csv finalize export file.');
                    }

                    fclose($final_file);

                    // ----------------------------------------
                    // Now that the "final" file exists, need to splice the temporary files
                    // together into it

                    $redis_prefix = $container->getParameter('memcached_key_prefix');     // debug purposes only
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            'tracked_job_id' => $tracked_job_id,
                            'final_filename' => $final_filename,
                            'random_keys' => $random_keys,

                            'user_id' => $user_id,
                            'redis_prefix' => $redis_prefix,    // debug purposes only
                            'api_key' => $api_key,
                        )
                    );

                    $delay = 0.500; // 500ms delay
                    $pheanstalk->useTube('csv_export_express_finalize')->put($payload, $priority, $delay);
                }


                // Close the connection to prevent stale handles
                $em->getConnection()->close();

                // Dealt with (or ignored) the job
                $pheanstalk->delete($job);
            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->err('CSVExportWorkerCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln('ERROR: ' . $e->getMessage());

                    $logger->err('CSVExportWorkerCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }



    /**
     * Extracts values of all datafields that have been selected for export from the cached
     * datarecord array.
     *
     * @param array $datarecord_data
     * @param array $datafields_to_export
     * @param array $datarecords_to_export
     * @param array $tag_hierarchy
     * @param array $delimiters
     *
     * @return array
     */
    private function filterDatarecordArray($datarecord_data, $datafields_to_export, $datarecords_to_export, $tag_hierarchy, $delimiters)
    {
        // Due to recursion, creating/returning a new array is easier than modifying the original
        $filtered_data = array();

        // Ignore all datafields that aren't supposed to be exported
        foreach ($datarecord_data as $dr_id => $dr_data) {
            // Ignore all datarecords that aren't supposed to be exported
            if ( !isset($datarecords_to_export[$dr_id]) )
                continue;

            $filtered_data[$dr_id] = array();

            // For any actual data in the datarecord...
            if ( isset($dr_data['dataRecordFields']) ) {
                $filtered_data[$dr_id]['values'] = array();

                foreach ($dr_data['dataRecordFields'] as $df_id => $df_data) {
                    // ...if it's supposed to be exported...
                    if ( isset($datafields_to_export[$df_id]) ) {
                        $tmp = array();

                        // ...then extract the value from the datarecord array...
                        $typeclass = $datafields_to_export[$df_id];
                        switch ( $typeclass ) {
                            case 'File':
                                $tmp = self::getFileData($df_data, $delimiters);
                                break;
                            case 'Image':
                                $tmp = self::getImageData($df_data, $delimiters);
                                break;
                            case 'Radio':
                                $tmp = self::getRadioData($df_data, $delimiters);
                                break;
                            case 'Tag':
                                $tmp = self::getTagData($df_data, $tag_hierarchy, $delimiters);
                                break;
                            default:
                                $tmp = self::getOtherData($df_data, $typeclass);
                                break;
                        }

                        // ...and save it
                        $filtered_data[$dr_id]['values'][$df_id] = $tmp;
                    }
                }

                // No sense having empty arrays
                if ( empty($filtered_data[$dr_id]['values']) )
                    unset( $filtered_data[$dr_id]['values'] );
            }

            // If the datarecord has any children...
            if ( isset($dr_data['children']) ) {
                foreach ($dr_data['children'] as $child_dt_id => $child_dr_list) {
                    // ...then repeat the process for each of the child datarecords
                    $tmp = self::filterDatarecordArray($child_dr_list, $datafields_to_export, $datarecords_to_export, $tag_hierarchy, $delimiters);
                    if ( !empty($tmp) )
                        $filtered_data[$dr_id]['children'][$child_dt_id] = $tmp;
                }
            }

            // No sense returning anything for this datarecord if it doesn't have values or children
            if ( !isset($filtered_data[$dr_id]['values']) && !isset($filtered_data[$dr_id]['children']) )
                unset( $filtered_data[$dr_id] );
        }

        return $filtered_data;
    }



    /**
     * Extracts text/number/boolean data for exporting.
     *
     * @param array $df_data
     * @param string $typeclass
     *
     * @return string
     */
    private function getOtherData($df_data, $typeclass)
    {
        $value = $df_data[ lcfirst($typeclass) ][0]['value'];
        if ( $typeclass === 'DatetimeValue' )
            $value = $value->format('Y-m-d');

        return $value;
    }



    /**
     * The tag data stored in the cached datatype array is organized for display...parent tags
     * contain their child tags.  Having to recursively dig through this array repeatedly is bad
     * though, so the tag data should get flattened for easier lookup of tag names.
     *
     * @param array $df_data
     *
     * @return array
     */
    private function getTagNames($tags)
    {
        $tag_names = array();

        foreach ($tags as $tag_id => $tag_data) {
            $tag_names[$tag_id] = $tag_data['tagName'];

            if ( isset($tag_data['children']) ) {
                $tmp = self::getTagNames($tag_data['children']);
                foreach ($tmp as $t_id => $t_name)
                    $tag_names[$t_id] = $t_name;
            }
        }

        return $tag_names;
    }


    /**
     * The tag data stored in the cached datatype array is organized for display...parent tags
     * contain their child tags.  However, since the cached datarecord array only mentions which
     * bottom-level tags are selected, this tag hierarchy array needs to be flipped so CSV Export
     * can bulid up the "full" tag name.
     *
     * @param array $tag_tree
     *
     * @return array
     */
    private function getTagTree($tag_tree)
    {
        $inversed_tree = array();
        foreach ($tag_tree as $parent_tag_id => $child_tags) {
            foreach ($child_tags as $child_tag_id => $tmp)
                $inversed_tree[$child_tag_id] = $parent_tag_id;
        }

        return $inversed_tree;
    }



    /**
     * Extracts file data for exporting.
     *
     * @param array $df_data
     * @param array $delimiters
     *
     * @return string
     */
    private function getFileData($df_data, $delimiters)
    {
        $files = array();
        if ( isset($df_data['file']) ) {
            foreach ($df_data['file'] as $num => $file) {
                // If there's already a file in the list, then insert a delimiter after the
                //  previous file
                if ( !empty($files) )
                    $files[] = $delimiters['file'];

                // Save the original filename for each file uploaded into this datafield
                $files[] = $file['fileMeta']['originalFileName'];
            }
        }

        // Implode the list of files with their delimiters to make a single string
        return implode("", $files);
    }


    /**
     * Extracts image data for exporting.
     *
     * @param array $df_data
     * @param array $delimiters
     *
     * @return string
     */
    private function getImageData($df_data, $delimiters)
    {
        $images = array();
        if ( isset($df_data['image']) ) {
            foreach ($df_data['image'] as $num => $thumbnail_image) {
                // If there's already an image in the list, then insert a delimiter after the
                //  previous image
                if ( !empty($images) )
                    $images[] = $delimiters['file'];

                // Don't want the thumbnails...want the filename of the corresponding full-size image
                $parent_image = $thumbnail_image['parent'];
                $images[] = $parent_image['imageMeta']['originalFileName'];
            }
        }

        // Implode the list of images with their delimiters to make a single string
        return implode("", $images);
    }


    /**
     * Extracts radio selection data for exporting.
     *
     * @param array $df_data
     * @param array $delimiters
     *
     * @return string
     */
    private function getRadioData($df_data, $delimiters)
    {
        $selections = array();
        if ( isset($df_data['radioSelection']) ) {
            foreach ($df_data['radioSelection'] as $ro_id => $rs) {
                // Only save radio option names when the radio option is selected
                if ( $rs['selected'] === 1 ) {
                    // If there's already a selected radio option in the list, then insert a delimiter
                    //  after the previous radio option
                    if ( !empty($selections) )
                        $selections[] = $delimiters['radio'];

                    $selections[] = $rs['radioOption']['optionName'];
                }
            }
        }

        // Implode the list of radio options with their delimiters to make a single string
        return implode("", $selections);
    }




    /**
     * Child/linked datatypes that only allow a single child/linked datarecord should get combined
     * with their parent
     *
     * @param array $datatree_array
     * @param int $current_datatype_id
     * @param array $dr_array
     *
     * @return array
     */
    private function mergeSingleChildtypes($datatree_array, $current_datatype_id, $dr_array)
    {
        // Don't continue when this datarecord has no children
        if ( !isset($dr_array['children']) )
            return $dr_array;

        // Make a copy of the given datarecord
        $dr = $dr_array;

        foreach ($dr['children'] as $child_dt_id => $child_dr_list) {
            // Regardless of whether this relation allows a single child/linked datarecord or
            //  not, need to recursively check any children of this child/linked record
            foreach ($child_dr_list as $child_dr_id => $child_dr) {
                // Only continue recursion if the child datarecord has children
                if ( isset($child_dr['children']) )
                    $dr['children'][$child_dt_id][$child_dr_id] = self::mergeSingleChildtypes($datatree_array, $child_dt_id, $child_dr);
            }

            // Determine whether the current datatype allows multiple records of this specific
            //  child/linked datatype
            $multiple_allowed = false;
            if ( isset($datatree_array['multiple_allowed'][$child_dt_id]) ) {
                $parent_list = $datatree_array['multiple_allowed'][$child_dt_id];
                if ( in_array($current_datatype_id, $parent_list) )
                    $multiple_allowed = true;
            }

            // If this relation only allows a single child/linked datarecord...
            if (!$multiple_allowed) {
                // ...then ensure this datarecord has a list of values, because...
                if ( !isset($dr['values']) )
                    $dr['values'] = array();

                foreach ($child_dr_list as $child_dr_id => $child_dr) {
                    if ( isset($child_dr['values']) ) {
                        foreach ($child_dr['values'] as $df_id => $value) {
                            // ...all values from that child datarecord need to get spliced into
                            //  this datarecord
                            $dr['values'][$df_id] = $value;
                        }
                    }

                    // Now that the values have been copied over, move any children of that child
                    //  datarecord so that they're children of the current datarecord
                    if ( isset($child_dr['children']) ) {
                        foreach ($child_dr['children'] as $grandchild_dt_id => $grandchild_dr_list)
                            $dr['children'][$grandchild_dt_id] = $grandchild_dr_list;
                    }

                    // All relevant parts of the child datarecord have been copied over, get rid
                    //  of the original
                    unset( $dr['children'][$child_dt_id] );
                    if ( empty($dr['children']) )
                        unset( $dr['children'] );
                }
            }
        }

        // Return the possibly modified values/children array for this datarecord
        return $dr;
    }


    /**
     * Any remaining child/linked datatypes that permit multiple child/linked datarecords need to
     * get recursively merged together
     *
     * @param array $dr_list
     *
     * @return array
     */
    private function mergeMultipleChildtypes($dr_list)
    {
        // Each datarecord can turn into multiple lines when it has multiple child/linked records
        $lines = array();

        foreach ($dr_list as $dr_id => $data) {
            // Any values for this datarecord are going to form the "start" of the block of data
            //  for this datarecord
            $line = array();
            if ( isset($data['values']) )
                $line = $data['values'];

            // If this datarecord has child/linked datarecords of its own...
            if ( isset($data['children']) ) {
                // ...then those child/linked datarecords need to be merged first...
                $child_lines = array();
                foreach ($data['children'] as $child_dt_id => $child_dr_list) {
                    $child_lines = self::mergeMultipleChildtypes($child_dr_list);

                    // ...and then this datarecord's data needs to be prepended before each
                    //  child/linked record's line of data
                    foreach ($child_lines as $child_line) {
                        // Make a copy of this datarecord's data first...
                        $new_line = array();
                        foreach ($line as $df_id => $value)
                            $new_line[$df_id] = $value;

                        // ...then append the child/linked datarecord's data afterwards
                        foreach ($child_line as $df_id => $value)
                            $new_line[$df_id] = $value;
                        $lines[] = $new_line;
                    }
                }
            }
            else {
                // No children to consider, just save the data from this datarecord
                $lines[] = $line;
            }
        }

        // Return all the lines created from this datarecord and its children
        return $lines;
    }



}
