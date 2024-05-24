<?php

/**
 * Open Data Repository Data Publisher
 * CSVExport Helper Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * It's easier to debug CSVExport if the code is accessible from a controller action, instead of
 * being buried in the symfony command handlers...
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\TrackedCSVExport;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\OpenRepository\GraphBundle\Plugins\ExportOverrideInterface;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Other
use Ddeboer\DataImport\Reader\CsvReader;
use Ddeboer\DataImport\Writer\CsvWriter;
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\ORM\EntityManager;
use FOS\UserBundle\Util\TokenGenerator;
use Pheanstalk\Pheanstalk;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;


class CSVExportHelperService
{

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var DatarecordInfoService
     */
    private $datarecord_info_service;

    /**
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

    /**
     * @var PermissionsManagementService
     */
    private $permissions_service;

    /**
     * @var SearchAPIService
     */
    private $search_api_service;

    /**
     * @var SearchKeyService
     */
    private $search_key_service;

    /**
     * @var TokenGenerator
     */
    private $token_generator;

    /**
     * @var Pheanstalk
     */
    private $pheanstalk;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * CSVExportHelperService constructor
     *
     * @param ContainerInterface $container
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param DatatreeInfoService $datatree_info_service
     * @param PermissionsManagementService $permissions_service
     * @param SearchAPIService $search_api_service
     * @param SearchKeyService $search_key_service
     * @param TokenGenerator $token_generator
     * @param Pheanstalk $pheanstalk
     * @param Logger $logger
     */
    public function __construct(
        ContainerInterface $container,
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        DatatreeInfoService $datatree_info_service,
        PermissionsManagementService $permissions_service,
        SearchAPIService $search_api_service,
        SearchKeyService $search_key_service,
        TokenGenerator $token_generator,
        Pheanstalk $pheanstalk,
        Logger $logger
    ) {
        $this->container = $container;
        $this->em = $entity_manager;
        $this->database_info_service = $database_info_service;
        $this->datarecord_info_service = $datarecord_info_service;
        $this->datatree_info_service = $datatree_info_service;
        $this->permissions_service = $permissions_service;
        $this->search_api_service = $search_api_service;
        $this->search_key_service = $search_key_service;
        $this->token_generator = $token_generator;
        $this->pheanstalk = $pheanstalk;
        $this->logger = $logger;
    }


    /**
     * Recursively digs through a single top-level datarecord from $inflated list to find all of its
     * child/linked datarecords that exist in $complete_datarecord_list.
     *
     * @param array $inflated_list @see SearchAPIService::buildDatarecordTree()
     * @param array $complete_datarecord_list The list of all datarecords matching the original search
     *                                        that this CSVExport is being run on...datarecord ids
     *                                        are the array keys
     *
     * @return array
     */
    public function getFilteredDatarecordList($inflated_list, $complete_datarecord_list)
    {
        $filtered_list = array();

        foreach ($inflated_list as $dr_id => $child_dt_list) {
            if ( isset($complete_datarecord_list[$dr_id]) ) {
                $filtered_list[] = $dr_id;
                if ( is_array($child_dt_list) ) {
                    // This datarecord has child/linked records, so those should get checked too
                    foreach ($child_dt_list as $child_dt_id => $dr_list) {
                        $tmp = self::getFilteredDatarecordList($dr_list, $complete_datarecord_list);
                        // Any matching child/linked records found should get added to the full list
                        foreach ($tmp as $num => $dr)
                            $filtered_list[] = $dr;
                    }
                }
            }

            // Otherwise, this datarecord is not in the search results list...it and any children
            //  should get ignored
        }

        return $filtered_list;
    }


    /**
     * CSVExport needs to have a fresh set of datarecords to be able to find the data to export...
     *
     * @param DataType $datatype
     * @param string $search_key
     * @param array $user_permissions
     * @return array
     */
    public function getExportSearchResults($datatype, $search_key, $user_permissions)
    {
        // CSVExport needs both versions of the lists of datarecords from a search result...

        // ...the grandparent datarecord list so that the export knows how many beanstalk jobs
        //  to create in the csv_export_worker queue...
        $grandparent_datarecord_list = $this->search_api_service->performSearch(
            $datatype,
            $search_key,
            $user_permissions
        );    // this only returns grandparent datarecord ids

        // ...and the complete datarecord list so that the csv_export_worker process can export
        //  the correct child/linked records
        $complete_datarecord_list = $this->search_api_service->performSearch(
            $datatype,
            $search_key,
            $user_permissions,
            true
        );    // this also returns child/linked descendant datarecord ids

        // However, the complete datarecord list can't be passed directly to the csv_export_worker
        //  queue because the list can easily exceed the maximum allowed job length...
        // Therefore, the list needs to be filtered for each csv_export_worker job so it only
        //  contains the child/linked records that are relevant to the grandparent datarecord
        $complete_datarecord_list = array_flip($complete_datarecord_list);


        // The most...reusable...method of performing this filtering is to copy the initial logic
        //  from SearchAPIService::performSearch().  This is duplication of work, but it should
        //  be fast enough to not make a noticable difference...


        // Convert the search key into a format suitable for searching
        $searchable_datafields = $this->search_api_service->getSearchableDatafieldsForUser(array($datatype->getId()), $user_permissions);
        $criteria = $this->search_key_service->convertSearchKeyToCriteria($search_key, $searchable_datafields, $user_permissions);

        // Need to grab hydrated versions of the datafields/datatypes being searched on
        $hydrated_entities = $this->search_api_service->hydrateCriteria($criteria);

        // Each datatype being searched on (or the datatype of a datafield being search on) needs
        //  to be initialized to "-1" (does not match) before the results of each facet search
        //  are merged together into the final array
        $affected_datatypes = $criteria['affected_datatypes'];
        unset( $criteria['affected_datatypes'] );
        // Also don't want the list of all datatypes anymore either
        unset( $criteria['all_datatypes'] );
        // ...or what type of search this is
        unset( $criteria['search_type'] );

        // Get the base information needed so getSearchArrays() can properly setup the search arrays
        $search_permissions = $this->search_api_service->getSearchPermissionsArray($hydrated_entities['datatype'], $affected_datatypes, $user_permissions);

        // Going to need these two arrays to be able to accurately determine which datarecords
        //  end up matching the query
        $search_arrays = $this->search_api_service->getSearchArrays(array($datatype->getId()), $search_permissions);
//        $flattened_list = $search_arrays['flattened'];
        $inflated_list = $search_arrays['inflated'];
        // The top-level of $inflated_list is wrapped in the top-level datatype id...get rid of it
        $inflated_list = $inflated_list[ $datatype->getId() ];

        return array(
            'grandparent_datarecord_list' => $grandparent_datarecord_list,
            'complete_datarecord_list' => $complete_datarecord_list,
            'inflated_list' => $inflated_list
        );
    }


    /**
     * Does the work of executing a CSVExport worker job.
     *
     * @param array $parameters
     */
    public function execute($parameters)
    {
        if (!isset($parameters['tracked_job_id'])
            || !isset($parameters['user_id'])
            || !isset($parameters['delimiter'])

            || !isset($parameters['datatype_id'])
            || !isset($parameters['datarecord_id'])
            || !isset($parameters['complete_datarecord_list'])
            || !isset($parameters['datafields'])

            || !isset($parameters['job_order'])
            || !isset($parameters['api_key'])
            || !isset($parameters['redis_prefix'])
        ) {
            $this->logger->debug('invalid list of parameters passed to function');
            $this->logger->debug( print_r($parameters, true) );
            throw new ODRBadRequestException();
        }

        // Pull data from the parameters
        $tracked_job_id = intval($parameters['tracked_job_id']);
        $user_id = $parameters['user_id'];

        $datatype_id = $parameters['datatype_id'];
        $datarecord_ids = $parameters['datarecord_id'];
        $complete_datarecord_list_array = $parameters['complete_datarecord_list'];
        $datafields = $parameters['datafields'];

        $job_order = $parameters['job_order'];
        $api_key = $parameters['api_key'];
        $redis_prefix = $parameters['redis_prefix'];

        // Each execution of this function needs its own random id
        $random_id = substr($this->token_generator->generateToken(), 0, 8);
        $random_key = $random_id.'_'.$datatype_id.'_'.$tracked_job_id;


        // ----------------------------------------
        // Don't need to do any verification on these...CSVExportController handled that
        $delimiters = array(
            'base' => $parameters['delimiter'],
            'file' => null,
            'radio' => null,
            'tag' => null,
            'tag_hierarchy' => null,
        );

        if ( $delimiters['base'] === 'tab' )
            $delimiters['base'] = "\t";

        if ( isset($parameters['file_image_delimiter']) )
            $delimiters['file'] = $parameters['file_image_delimiter'];

        if ( isset($parameters['radio_delimiter']) )
            $delimiters['radio'] = $parameters['radio_delimiter'];
        if ($delimiters['radio'] === 'space')
            $delimiters['radio'] = ' ';

        if ( isset($parameters['tag_delimiter']) )
            $delimiters['tag'] = $parameters['tag_delimiter'];

        if ( isset($parameters['tag_hierarchy_delimiter']) )
            $delimiters['tag_hierarchy'] = $parameters['tag_hierarchy_delimiter'];



        /** @var DataType $datatype */
        $datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        if ($datatype == null)
            throw new ODRNotFoundException('Datatype');
        if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
            throw new ODRBadRequestException('Unable to run CSVExport from a child datatype');

        // This doesn't make sense on a master datatype
        if ($datatype->getIsMasterType())
            throw new ODRBadRequestException('Unable to export from a master template');


        // ----------------------------------------
        // Need the user to be able to filter data
        /** @var ODRUser $user */
        $user = $this->em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
        if ($user == null || !$user->isEnabled())
            throw new ODRNotFoundException('User');
        // TODO - need to modify so this can work without an active user, somehow

        // Ensure user has permissions to be doing this
        if ( !$this->permissions_service->canViewDatatype($user, $datatype) )
            throw new ODRForbiddenException();

        $user_permissions = $this->permissions_service->getUserPermissionsArray($user);


        // ----------------------------------------
        // In order to deal with child/linked datatypes correctly, the CSV exporter needs to know
        //  which child/linked datatypes allow multiple child/linked records
        $datatree_array = $this->datatree_info_service->getDatatreeArray();

        // Gather basic info about all datafields prior to actually loading data
        $dt_array = $this->database_info_service->getDatatypeArray($datatype_id, true);    // may need linked datatypes
        // Going to need a stacked version of the datatype array if plugins are involved...
        $tmp = array();
        $this->permissions_service->filterByGroupPermissions($dt_array, $tmp, $user_permissions);
        $stacked_dt_array = array(
            $datatype_id => $this->database_info_service->stackDatatypeArray($dt_array, $datatype_id)
        );

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
        $datatypes_to_export = array();
        foreach ($dt_array as $dt_id => $dt) {
            foreach ($dt['dataFields'] as $df_id => $df) {
                if ( isset($flipped_datafields[$df_id]) ) {
                    $fieldtype = $df['dataFieldMeta']['fieldType'];
                    $typeclass = $fieldtype['typeClass'];
                    $typename = $fieldtype['typeName'];

                    // All fieldtypes except for Markdown can be exported
                    if ($typename !== 'Markdown') {
                        $datafields_to_export[$df_id] = $typeclass;
                        $datatypes_to_export[$dt_id] = 1;
                    }

                    // If exporting a tag datafield...
                    if ($typename === 'Tags' && isset($df['tags'])) {
                        // The tags are stored in a tree structure to make rendering them easier
                        //  ...but for export, it's easier if they're flattened
                        $tag_data['names'] = self::getTagNames($df['tags']);
                        // The export process also needs to be able to locate the name of a
                        //  parent tag from a child tag
                        $tag_data['tree'] = self::getTagTree($df['tagTree']);
                    }

                    // "Mark" this datafield as seen
                    unset($flipped_datafields[$df_id]);
                }
            }
        }

        // If any entries remain in $flipped_datafields...they're either datafields the user can't
        //  view, or they belong to unrelated datatypes.  Neither should be allowed.
        if ( !empty($flipped_datafields) ) {
            $df_ids = implode(',', array_keys($flipped_datafields));
            throw new ODRBadRequestException('Unable to locate Datafields "'.$df_ids.'" for User '.$user_id.', Datatype '.$datatype_id);
        }


        // ----------------------------------------
        // Now that the datafield list is valid, check whether any of the datafields being exported
        //  (or their datatypes) want to override a csv export
        $plugin_data = array();
        foreach ($dt_array as $dt_id => $dt) {
            if (isset($datatypes_to_export[$dt_id])) {
                foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                    if ( $rpi['renderPlugin']['overrideExport'] == true ) {
                        $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                        $plugin_data[] = array(
                            'rpi' => $rpi,
                            'plugin' => $this->container->get($plugin_classname),
                            'dt_id' => $dt_id,
                            'df_id' => null,
                        );
                    }
                }
            }

            foreach ($dt['dataFields'] as $df_id => $df) {
                if (isset($datafields_to_export[$df_id])) {
                    foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['overrideExport'] == true ) {
                            $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                            $plugin_data[] = array(
                                'rpi' => $rpi,
                                'plugin' => $this->container->get($plugin_classname),
                                'dt_id' => $dt_id,
                                'df_id' => $df_id,
                            );
                        }
                    }
                }
            }
        }

        // For each datatype/datafield that might want to execute a plugin to override the output...
        $plugin_executions = array('datatype' => array(), 'datafield' => array());
        foreach ($plugin_data as $num => $data) {
            // ...check whether it actually does want to override something
            /** @var ExportOverrideInterface $plugin */
            $plugin = $data['plugin'];

            $rpi = $data['rpi'];
            $df_list = $plugin->getExportOverrideFields($rpi);

            // The plugin might not want to override any datafields, or Datatype plugins may want to
            //  override multiple fields...for the latter case, ensure that the datatype plugin
            //  can know which fields are being exported, so it doesn't have to do the work to
            //  determine values for every single possible field it overrides
            foreach ($df_list as $df_num => $df_id) {
                if ( !isset($datafields_to_export[$df_id]) )
                    unset( $df_list[$df_num] );
            }

            if ( !empty($df_list) ) {
                // Can't actually execute the plugins to determine the values for the datafields here
                //  ...if the datafield belongs to a multiple-allowed child/linked descendant, then
                //  it'll have multiple values in the export

                // Have to instead store enough info so the plugin can be executed later...
                if ( is_null($data['df_id']) ) {
                    // Datatype plugins may want to override multiple fields, so store the list
                    $dt_id = $data['dt_id'];
                    $plugin_executions['datatype'][$dt_id] = $data;
                    $plugin_executions['datatype'][$dt_id]['df_list'] = array_values($df_list);
                }
                else {
                    // Datafield plugins will only execute on their own datafield
                    foreach ($df_list as $df_num => $df_id)
                        $plugin_executions['datafield'][$df_id] = $data;
                }
            }
        }


        // ----------------------------------------
        // Originally, this export process created a beanstalk job for each grandparent record
        //  that was getting exported...this was "safe", but slow.  People complained.

        // The easiest way to speed it up is to have one worker process do it all...and to reduce
        // the amount of hydration needed, verify the datarecord list outside of the for loop
        $query =
           'SELECT dr.id AS dr_id, dr.data_type_id AS dt_id
            FROM odr_data_record AS dr
            WHERE dr.id IN (?)';
        $parameters = array(1 => $datarecord_ids);
        $types = array(1 => DBALConnection::PARAM_INT_ARRAY);

        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $parameters, $types);

        foreach ($results as $result) {
//            $dr_id = $result['dr_id'];
            $dt_id = $result['dt_id'];

            if ( $dt_id !== $datatype_id )
                throw new ODRBadRequestException('Datarecord does not match Datatype');
        }


        // ----------------------------------------
        // Now that the datafields and the datarecords are valid, convert each record into a row
        //  of data
        $lines = array();
        for ($i = 0; $i < count($datarecord_ids); $i++) {
            $datarecord_id = $datarecord_ids[$i];

            // Going to need the cached datarecord array...
            $dr_array = $this->datarecord_info_service->getDatarecordArray($datarecord_id, true);    // may need links
            $this->permissions_service->filterByGroupPermissions($dt_array, $dr_array, $user_permissions);

            // Stack the cached version of the datarecord array to make recursion work
            $stacked_dr_array = array(
                $datarecord_id => $this->datarecord_info_service->stackDatarecordArray($dr_array, $datarecord_id)
            );


            // ----------------------------------------
            // Remove all datarecords and datafields from the stacked datarecord array that the
            //  user doesn't want to export
            $datarecords_to_export = array_flip($complete_datarecord_list_array[$i]);
            $filtered_dr_array = self::filterDatarecordArray($user_permissions, $stacked_dt_array, $datafields_to_export, $stacked_dr_array, $datarecords_to_export, $tag_data, $delimiters, $plugin_executions);

            // Unfortunately, this CSV exporter needs to be able to deal with the possibility of
            //  exporting more than one child/linked datatype that allows multiple child/linked
            // records.

            // For visualization purposes...
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


        // ----------------------------------------
        // Ensure the random key is stored in the database for later retrieval by the finalization
        //  process
        if ( $tracked_job_id !== -1 ) {
            /** @var TrackedCSVExport $tracked_csv_export */
            $tracked_csv_export = $this->em->getRepository('ODRAdminBundle:TrackedCSVExport')
                ->findOneBy( array('random_key' => $random_key) );

            if ($tracked_csv_export == null) {
                $query =
                   'INSERT INTO odr_tracked_csv_export
                    (random_key, tracked_job_id, finalize, job_order)
                    SELECT * FROM (SELECT :random_key AS random_key,
                        :tj_id AS tracked_job_id, :finalize AS finalize,
                        :job_order as job_order) AS tmp
                    WHERE NOT EXISTS (
                        SELECT random_key FROM odr_tracked_csv_export
                        WHERE random_key = :random_key AND tracked_job_id = :tj_id
                        AND job_order = :job_order
                    )
                    LIMIT 1;';
                $params = array(
                    'random_key' => $random_key,
                    'tj_id' => $tracked_job_id,
                    'finalize' => 0,
                    'job_order' => $job_order,
                );
                $conn = $this->em->getConnection();
                $rowsAffected = $conn->executeUpdate($query, $params);
            }
        }


        // Ensure directories exists
        $csv_export_path = $this->container->getParameter('odr_tmp_directory').'/user_'.$user_id.'/';
        if ( !file_exists($csv_export_path) )
            mkdir($csv_export_path);
        $csv_export_path .= 'csv_export/';
        if ( !file_exists($csv_export_path) )
            mkdir($csv_export_path);

        // Open the indicated file
        $filename = 'f_'.$random_key.'.csv';
//        $output->writeln('Export File: '.$csv_export_path.$filename);
        $handle = fopen($csv_export_path.$filename, 'a');
        if ($handle !== false) {
            // Write the line given to the file
            // https://github.com/ddeboer/data-import/blob/master/src/Ddeboer/DataImport/Writer/CsvWriter.php
            // $delimiter = "\t";
            $enclosure = "\"";
            $writer = new CsvWriter($delimiters['base'], $enclosure);

            $writer->setStream($handle);

            foreach ($lines as $line)
                $writer->writeItem($line);

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
            $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

            $count = 0;
            $total = $tracked_job->getTotal();
            for ($i = 0; $i < count($datarecord_ids); $i++) {
                // ...while the loop to increment seems dumb...due to having both an unknown number
                //  of datarecords and background processes working on this job...need to safely
                //  increment this value instead of just setting it to something
                $count = $tracked_job->incrementCurrent($this->em);
            }

            if ($count >= $total) {
                $tracked_job->setCompleted(new \DateTime());
                $completed = true;
            }

            $this->em->persist($tracked_job);
            $this->em->flush();
            //print '  Set current to '.$count."\n";
        }


        // ----------------------------------------
        // NOTE - incrementCurrent()'s current implementation can't guarantee that only a single
        //  process will enter this block...so have to ensure that only one process starts the
        //  finalize step

        // If this was the last line to write to be written to a file for this particular export...
        $random_keys = array();
        if ( $completed && $tracked_job_id !== -1 ) {
            // Make a hash from all the random keys used
            $query = $this->em->createQuery(
               'SELECT tce.id AS id, tce.random_key AS random_key
                FROM ODRAdminBundle:TrackedCSVExport AS tce
                WHERE tce.trackedJob = :tracked_job AND tce.finalize = 0
                ORDER BY tce.job_order asc'
            )->setParameters(array('tracked_job' => $tracked_job_id));
            $results = $query->getArrayResult();

            // Due to ORDER BY, every process entering this section should compute the same hash
            $random_key_hash = '';
            foreach ($results as $num => $result) {
                $random_keys[$result['id']] = $result['random_key'];
                $random_key_hash .= $result['random_key'];
            }
            $random_key_hash = md5($random_key_hash);

            // Attempt to insert this hash back into the database...
            // NOTE: this uses the same random_key field as the previous INSERT WHERE NOT EXISTS query
            //  ...the first time it had an 8 character string inserted into it, this time it's
            //  taking a 32 character string
            $query =
               'INSERT INTO odr_tracked_csv_export
                    (random_key, tracked_job_id, finalize)
                    SELECT * FROM (SELECT :random_key_hash AS random_key,
                        :tj_id AS tracked_job_id,
                        :finalize AS finalize) AS tmp
                    WHERE NOT EXISTS (
                        SELECT random_key FROM odr_tracked_csv_export
                        WHERE random_key = :random_key_hash
                        AND tracked_job_id = :tj_id AND finalize = :finalize
                    )
                    LIMIT 1; ';
            $params = array('random_key_hash' => $random_key_hash, 'tj_id' => $tracked_job_id, 'finalize' => 1);
            $conn = $this->em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            if ($rowsAffected == 1) {
                // This is the first process to attempt to insert this key...it will be in charge of
                //  creating the information used to concatenate the temporary files together
                $completed = true;
            }
            else {
                // This is not the first process to attempt to insert this key...do nothing to
                //  prevent creation of multiple finalize jobs
                $completed = false;
            }
        }


        // ----------------------------------------
        // If the export is ready to be combined into the final export file...
        if ( $completed && $tracked_job_id !== -1 ) {
            // ...then it needs a header line.  Recreate the $flipped_datafields array...
            $flipped_datafields = array_flip($datafields);

            // ...so another loop can get the fieldnames of all fields that got exported
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
            // Now that the "final" file exists, need to use a different background worker to splice
            //  the temporary files together into it
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
            $this->pheanstalk->useTube('csv_export_express_finalize')->put($payload, $priority, $delay);
        }


        // TODO - Close the connection to prevent stale handles?  I think every time this gets loaded the connection is "fresh"...
//        $this->em->getConnection()->close();
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

            if (isset($tag_data['children'])) {
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
     * Extracts values of all datafields that have been selected for export from the cached
     * datarecord array.
     *
     * @param array $user_permissions
     * @param array $datatype_data
     * @param array $datafields_to_export
     * @param array $datarecord_data
     * @param array $datarecords_to_export
     * @param array $tag_hierarchy
     * @param array $delimiters
     * @param array $plugin_executions
     *
     * @return array
     */
    private function filterDatarecordArray($user_permissions, $datatype_data, $datafields_to_export, $datarecord_data, $datarecords_to_export, $tag_hierarchy, $delimiters, $plugin_executions)
    {
        // Due to recursion, creating/returning a new array is easier than modifying the original
        $filtered_data = array();

        // Ignore all datafields that aren't supposed to be exported
        foreach ($datarecord_data as $dr_id => $dr_data) {
            // Ignore all datarecords that aren't supposed to be exported
            if ( !isset($datarecords_to_export[$dr_id]) )
                continue;

            $filtered_data[$dr_id] = array();

            $dt_id = $dr_data['dataType']['id'];
            $dt_data = $datatype_data[$dt_id];

            // If this export requires a field that a datatype plugin wants to override...
            $plugin_overridden_values = array();
            if ( isset($plugin_executions['datatype'][$dt_id]) ) {
                // ...then execute the datatype plugin to get those values
                $plugin_data = $plugin_executions['datatype'][$dt_id];

                /** @var ExportOverrideInterface $plugin */
                $plugin = $plugin_data['plugin'];
                $rpi = $plugin_data['rpi'];
                $df_list = $plugin_data['df_list'];

                $plugin_overridden_values = $plugin->getExportOverrideValues($df_list, $rpi, $dt_data, $dr_data, $user_permissions);
            }

            // For any actual data in the datarecord...
            if ( isset($dr_data['dataRecordFields']) ) {
                $filtered_data[$dr_id]['values'] = array();

                foreach ($dr_data['dataRecordFields'] as $df_id => $df_data) {
                    // ...if it's supposed to be exported...
                    if ( isset($datafields_to_export[$df_id]) ) {
                        // ...then acquire the datafield's value
                        $tmp = array();

                        if ( isset($plugin_overridden_values[$df_id]) ) {
                            // This datafield's value is overridden by a datatype plugin
                            $tmp = $plugin_overridden_values[$df_id];
                        }
                        else if ( isset($plugin_executions['datafield'][$df_id]) ) {
                            // This datafield's value needs to be overridden by a datafield plugin
                            $plugin_data = $plugin_executions['datafield'][$df_id];

                            /** @var ExportOverrideInterface $plugin */
                            $plugin = $plugin_data['plugin'];
                            $rpi = $plugin_data['rpi'];

                            $ret = $plugin->getExportOverrideValues(array($df_id), $rpi, $dt_data, $dr_data, $user_permissions);
                            $tmp = $ret[$df_id];
                        }
                        else {
                            // This datafield's value should be pulled from the datarecord array
                            $typeclass = $datafields_to_export[$df_id];
                            switch ($typeclass) {
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
                        }

                        // ...and save it
                        $filtered_data[$dr_id]['values'][$df_id] = $tmp;
                    }
                }

                // No sense having empty arrays
                if ( empty($filtered_data[$dr_id]['values']) )
                    unset($filtered_data[$dr_id]['values']);
            }

            // If the datarecord has any children...
            if ( isset($dr_data['children']) ) {
                foreach ($dr_data['children'] as $child_dt_id => $child_dr_list) {
                    // ...then repeat the process for each of the child datarecords
                    $child_dt = $dt_data['descendants'][$child_dt_id]['datatype'];

                    $tmp = self::filterDatarecordArray($user_permissions, $child_dt, $datafields_to_export, $child_dr_list, $datarecords_to_export, $tag_hierarchy, $delimiters, $plugin_executions);
                    if ( !empty($tmp) )
                        $filtered_data[$dr_id]['children'][$child_dt_id] = $tmp;
                }
            }

            // No sense returning anything for this datarecord if it doesn't have values or children
            if ( !isset($filtered_data[$dr_id]['values']) && !isset($filtered_data[$dr_id]['children']) )
                unset($filtered_data[$dr_id]);
        }

        return $filtered_data;
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
        if (isset($df_data['file'])) {
            foreach ($df_data['file'] as $num => $file) {
                // If there's already a file in the list, then insert a delimiter after the
                //  previous file
                if (!empty($files))
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
        if (isset($df_data['image'])) {
            foreach ($df_data['image'] as $num => $thumbnail_image) {
                // If there's already an image in the list, then insert a delimiter after the
                //  previous image
                if (!empty($images))
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
        if (isset($df_data['radioSelection'])) {
            foreach ($df_data['radioSelection'] as $ro_id => $rs) {
                // Only save radio option names when the radio option is selected
                if ($rs['selected'] === 1) {
                    // If there's already a selected radio option in the list, then insert a delimiter
                    //  after the previous radio option
                    if (!empty($selections))
                        $selections[] = $delimiters['radio'];

                    $selections[] = $rs['radioOption']['optionName'];
                }
            }
        }

        // Implode the list of radio options with their delimiters to make a single string
        return implode("", $selections);
    }


    /**
     * Extracts tag selection data for exporting from the given top-level $dr_array.
     *
     * @param array $df_data
     * @param array $tag_data
     * @param array $delimiters
     *
     * @return string
     */
    private function getTagData($df_data, $tag_data, $delimiters)
    {
        $tags = array();
        if ( isset($df_data['tagSelection']) ) {
            foreach ($df_data['tagSelection'] as $tag_id => $tag_selection) {
                // If this tag is selected...
                if ( $tag_selection['selected'] === 1 ) {
                    // If there's already a selected tag in the list, then insert a delimiter
                    //  after the previous tag
                    if (!empty($tags))
                        $tags[] = $delimiters['tag'];

                    // Since tags can be arranged in a hierarchy, the export process may need to
                    //  locate all parents of this tag
                    $current_tag_id = $tag_id;
                    $full_tag_name = array();
                    $full_tag_name[] = $tag_data['names'][$current_tag_id];

                    // The name of each tag in the hierarchy needs to be added to an array...
                    while ( isset($tag_data['tree'][$current_tag_id]) ) {
                        $full_tag_name[] = $delimiters['tag_hierarchy'];
                        $current_tag_id = $tag_data['tree'][$current_tag_id];
                        $full_tag_name[] = $tag_data['names'][$current_tag_id];
                    }

                    // ...in order to reverse the array so the tag is described from the "top-down"
                    //  instead of from the "bottom-up"
                    $full_tag_name = array_reverse($full_tag_name);
                    $full_tag_name = implode(" ", $full_tag_name);

                    // Save the full name of this tag for the export
                    $tags[] = $full_tag_name;
                }
            }
        }

        // Implode the list of tags with their delimiters to make a single string
        return implode("", $tags);
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
        $value = $df_data[lcfirst($typeclass)][0]['value'];
        if ($typeclass === 'DatetimeValue')
            $value = $value->format('Y-m-d');

        return $value;
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
                if (isset($child_dr['children']))
                    $dr['children'][$child_dt_id][$child_dr_id] = self::mergeSingleChildtypes($datatree_array, $child_dt_id, $child_dr);
            }

            // Determine whether the current datatype allows multiple records of this specific
            //  child/linked datatype
            $multiple_allowed = false;
            if ( isset($datatree_array['multiple_allowed'][$child_dt_id]) ) {
                $parent_list = $datatree_array['multiple_allowed'][$child_dt_id];
                if (in_array($current_datatype_id, $parent_list))
                    $multiple_allowed = true;
            }

            // If this relation only allows a single child/linked datarecord...
            if ( !$multiple_allowed ) {
                // ...then ensure this datarecord has a list of values, because...
                if ( !isset($dr['values']) )
                    $dr['values'] = array();

                foreach ($child_dr_list as $child_dr_id => $child_dr) {
                    if (isset($child_dr['values'])) {
                        foreach ($child_dr['values'] as $df_id => $value) {
                            // ...all values from that child datarecord need to get spliced into
                            //  this datarecord
                            $dr['values'][$df_id] = $value;
                        }
                    }

                    // Now that the values have been copied over, move any children of that child
                    //  datarecord so that they're children of the current datarecord
                    if (isset($child_dr['children'])) {
                        foreach ($child_dr['children'] as $grandchild_dt_id => $grandchild_dr_list)
                            $dr['children'][$grandchild_dt_id] = $grandchild_dr_list;
                    }

                    // All relevant parts of the child datarecord have been copied over, get rid
                    //  of the original
                    unset($dr['children'][$child_dt_id]);
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
