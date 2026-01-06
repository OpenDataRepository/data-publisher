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
use Ddeboer\DataImport\Writer\CsvWriter;
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\ORM\EntityManager;
use FOS\UserBundle\Util\TokenGenerator;
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
        $ignored_prefixes = array();    // TODO
        $search_arrays = $this->search_api_service->getSearchArrays($datatype->getId(), $search_permissions, $ignored_prefixes);
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
     * Does the work of a CSVExport worker job.
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
        ) {
            $this->logger->debug('invalid list of parameters passed to CSVExportHelperService::execute()');
            $this->logger->debug( print_r($parameters, true) );
            throw new ODRBadRequestException();
        }

        // Pull data from the parameters
        $api_key = $parameters['api_key'];
        if ( $this->container->getParameter('beanstalk_api_key') !== $api_key )
            throw new ODRBadRequestException('Invalid API key');

        $tracked_job_id = intval($parameters['tracked_job_id']);
        $user_id = intval($parameters['user_id']);

        $datatype_id = $parameters['datatype_id'];
        $datarecord_ids = $parameters['datarecord_id'];
        $complete_datarecord_list_array = $parameters['complete_datarecord_list'];

        // The datafield list needs to be decoded from json...it seems as if passing it through the
        //  symfony command turns it into an object otherwise
        $datafields = $parameters['datafields'];
        if ( $tracked_job_id !== -1 )
            $datafields = json_decode($datafields, true);

        $job_order = $parameters['job_order'];

        // Each execution of this function needs its own random id
        $random_id = substr($this->token_generator->generateToken(), 0, 12);
        $filename_fragment = $random_id.'_'.$datatype_id.'_'.$tracked_job_id;

        /** @var TrackedJob $tracked_job */
        $tracked_job = null;
        if ( $tracked_job_id !== -1 ) {
            $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);
            if ($tracked_job == null)
                throw new ODRNotFoundException('Tracked Job');

            if ( $tracked_job->getCurrent() >= $tracked_job->getTotal() )
                throw new ODRException('Tracked Job has current >= total');
            if ( $tracked_job->getCompleted() != null)
                throw new ODRException('Tracked Job already marked as completed');
            if ( $tracked_job->getFailed() )
                throw new ODRException('Tracked Job marked as failed');
        }


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
        // Need to know which data to filter out
        /** @var ODRUser|null $user */
        $user = null;
        if ( $user_id !== 0 )
            $user = $this->em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);

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
        $inversed_tag_hierarchy = array();


        // ----------------------------------------
        // Need to locate information about the datafields that are going to be exported...particularly
        //  whether the plugin system needs to override the values for CSVExport
        $export_datafields = array();
        $export_datatypes = array();

        // Modify the given array of datafields to also have typeclass info for later
        $new_datafields = array();

        // Ensure this datatype's external id field is exported, if one exists
        $external_id_field = $dt_array[$datatype_id]['dataTypeMeta']['externalIdField'];
        if ( !is_null($external_id_field) ) {
            $external_id_field_id = $external_id_field['id'];
            $export_datafields[$external_id_field_id] = 0;
            $new_datafields[ $datatype_id.'_'.$external_id_field_id ] = array('df_id' => $external_id_field_id, 'typeclass' => '');
        }

        foreach ($datafields as $id_string => $df_id) {
            $export_datafields[$df_id] = 0;
            $new_datafields[$id_string] = array('df_id' => intval($df_id), 'typeclass' => '');
        }
        $datafields = $new_datafields;

        // Dig through the entire relevant cached datatype array...
        foreach ($dt_array as $dt_id => $dt) {
            foreach ($dt['dataFields'] as $df_id => $df) {
                // If this datafield is supposed to be exported...
                if ( isset($export_datafields[$df_id]) ) {
                    $fieldtype = $df['dataFieldMeta']['fieldType'];
                    $typeclass = $fieldtype['typeClass'];
                    $typename = $fieldtype['typeName'];

                    // ...ensure it's not a Markdown field
                    if ($typename !== 'Markdown') {
                        $export_datafields[$df_id] = 1;
                        $export_datatypes[$dt_id] = 1;

                        // ...and splice the typeclass back into the list of datafields
                        foreach ($new_datafields as $id_string => $df_data) {
                            if ( $df_data['df_id'] === $df_id ) {
                                $datafields[$id_string]['typeclass'] = $typeclass;

                                // DO NOT break here...the user could've selected the same datafield
                                //  in multiple linked descendants, and they all need to have their
                                //  typeclass info
//                                break;
                            }
                        }
                    }

                    // If exporting a tag datafield...
                    if ($typename === 'Tags' && isset($df['tags'])) {
                        // The export process also needs to be able to locate the name of a
                        //  parent tag from a child tag
                        $inversed_tag_hierarchy = self::getTagTree($df['tagTree']);
                    }
                }
            }
        }

        // If any entries remain in $df_id_list...they're either datafields the user can't view, or
        //  they belong to unrelated datatypes.  Neither should be allowed.
        $invalid_df_ids = array();
        foreach ($export_datafields as $df_id => $num) {
            if ( $num === 0 )
                $invalid_df_ids[] = $df_id;
        }
        if ( !empty($invalid_df_ids) ) {
            $df_ids = implode(',', array_keys($invalid_df_ids));
            throw new ODRBadRequestException('Unable to locate Datafields "'.$df_ids.'" for User '.$user_id.', Datatype '.$datatype_id);
        }


        // ----------------------------------------
        // Now that the datafield list is valid, check whether any of the datafields being exported
        //  (or their datatypes) want to override a csv export
        $plugin_data = array();
        foreach ($dt_array as $dt_id => $dt) {
            if ( isset($export_datatypes[$dt_id]) ) {
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
                if ( isset($export_datafields[$df_id]) ) {
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

        // TODO - need to handle the LinkedDescendantMerger plugin
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
                if ( !isset($export_datafields[$df_id]) )
                    unset( $df_list[$df_num] );
            }

            if ( !empty($df_list) ) {
                // Can't actually execute the plugins here, since this loop doesn't involve the
                //  datarecord data

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
        //  that was getting exported, then used one background process that called cURL to process
        //  one record at a time.
        // This was "safe", but slow.  People complained.

        // In late 2023, it was modified so that a worker process handled more than one grandparent,
        //  and ran the code directly.  To reduce the amount of hydration needed, verify the
        //  datarecord list outside the primary for loop
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
            $datarecords = array_flip($complete_datarecord_list_array[$i]);
            $current_prefix = $datatype_id;
            $filtered_dr_array = self::filterDatarecordArray($current_prefix, $user_permissions, $stacked_dt_array, $datafields, $stacked_dr_array, $datarecords, $inversed_tag_hierarchy, $delimiters, $plugin_executions);

            // Unfortunately, this CSV exporter needs to be able to deal with the possibility of
            //  exporting more than one child/linked datatype that allows multiple child/linked
            // records.

            // For visualization purposes...
            // Sample (top-level)
            //   |- Mineral (only one allowed per Sample)
            //   |   |- Reference (multiple allowed per Mineral)
            //   |- Raman (multiple allowed per Sample)
            //   |- Infrared (multiple allowed per Sample)
            //   |- Reference (multiple allowed per Sample)
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
                foreach ($datafields as $id_string => $df_data) {
                    // Due to the possibility of child/linked datatypes allowing multiple child/linked
                    //  records, the filtered/merged data arrays may not have entries for all of
                    //  the fields selected for export
                    if ( isset($data[$id_string]) )
                        $line[] = $data[$id_string];
                    else
                        $line[] = '';
                }

                // Store the line so it can be written to a csv file
                $lines[] = $line;
            }
        }


        // ----------------------------------------
        // Now that a chunk of the CSV data has been gathered...it needs to be stored so it can be
        //  combined into a "finalized" file that the user will eventually download.

        // The export process was originally written to try to have multiple background processes,
        //  but the implementation used a complicated INSERT INTO (SELECT FROM WHERE NOT EXISTS)
        //  query...as a result, it only ever worked with a single background process.
        // In late 2023 the export was modified to run faster, but it reused the same flawed query
        //  ...it suffered from the same deadlock as a result, although the massive speedup elsewhere
        //  apparently made the deadlocks less common.

        // Since conventional mutexes weren't allowed, the other way to fix these deadlock issues is
        //  for each background process to only insert the random key it computed earlier in this
        //  function.  This is the first of a three-part solution to the deadlocks...
        if ( $tracked_job_id !== -1 ) {
            $query =
               'INSERT INTO odr_tracked_csv_export
                (random_key, tracked_job_id, job_order, line_count, created)
                VALUES (:random_key, :tj_id, :job_order, :line_count, :created)';

            $now = new \DateTime();
            $params = array(
                'random_key' => $filename_fragment,
                'tj_id' => $tracked_job_id,
                'job_order' => $job_order,
                'line_count' => count($datarecord_ids),
                'created' => $now->format('Y-m-d H:i:s'),
            );

            $conn = $this->em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            if ( $rowsAffected !== 1 )
                throw new ODRException('CSVExportHelperService: failure when inserting into odr_tracked_csv_export table');
        }


        // Ensure directories exists
        $csv_export_path = $this->container->getParameter('odr_tmp_directory').'/user_'.$user_id.'/';
        if ( !file_exists($csv_export_path) )
            mkdir($csv_export_path);
        $csv_export_path .= 'csv_export/';
        if ( !file_exists($csv_export_path) )
            mkdir($csv_export_path);

        // Open the indicated file
        $filename = 'f_'.$filename_fragment.'.csv';
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
        // Originally, the "worker" background processes were also responsible for updating the
        //  progress of the job as a whole...this was the other part that caused deadlocks.
        // The modification in late 2023 also reused this flawed code...but the combination of the
        //  "worker" processes handling more than one record at a time and the massive speedup again
        //  masked the deadlock issue for a bit.

        // Since, again, conventional mutexes weren't allowed, the second part of this three-part
        //  solution is to simply not have the "worker" processes update job progress.


        // ----------------------------------------
        // The entire reason why the original setup was prone to deadlocks was that the "worker"
        //  background process was also eventually responsible for triggering the "finalize" process
        //  ...and they used the job progress to determine whether they should be the one to start
        //  the "finalize" process.  Starting multiple "finalize" processes broke the export.

        // The modification in late 2023 also reused this logic, though it never seemed to trigger
        //  a deadlock in and of itself.
        // The third of the three-part solution to these deadlocks is to convert the "finalize"
        //  process from something that gets triggered into something that actively queries the
        //  database to determine whether it needs to step up and do something.


        // This technically means that the job of the "worker" process completed once it finished
        //  writing to the temporary file.

        // Close the connection to prevent stale handles?  I think every time this gets loaded the connection is "fresh"...
        $this->em->getConnection()->close();
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
     * @param string $current_prefix
     * @param array $user_permissions
     * @param array $datatype_data
     * @param array $datafields
     * @param array $datarecord_data
     * @param array $datarecords
     * @param array $inversed_tag_hierarchy
     * @param array $delimiters
     * @param array $plugin_executions
     *
     * @return array
     */
    private function filterDatarecordArray($current_prefix, $user_permissions, $datatype_data, $datafields, $datarecord_data, $datarecords, $inversed_tag_hierarchy, $delimiters, $plugin_executions)
    {
        // Due to recursion, creating/returning a new array is easier than modifying the original
        $filtered_data = array();

        // Ignore all datafields that aren't supposed to be exported
        foreach ($datarecord_data as $dr_id => $dr_data) {
            // Ignore all datarecords that aren't supposed to be exported
            if ( !isset($datarecords[$dr_id]) )
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
                    $df_prefix = $current_prefix.'_'.$df_id;
                    if ( isset($datafields[$df_prefix]) ) {
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
                            $typeclass = $datafields[$df_prefix]['typeclass'];
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
                                    $tmp = self::getTagData($df_data, $inversed_tag_hierarchy, $delimiters);
                                    break;
                                case 'XYZData':
                                    $xyz_column_names = $datatype_data[$dt_id]['dataFields'][$df_id]['dataFieldMeta']['xyz_data_column_names'];
                                    $tmp = self::getXYZData($df_data, $xyz_column_names, $delimiters);
                                    break;
                                default:
                                    $tmp = self::getOtherData($df_data, $typeclass);
                                    break;
                            }
                        }

                        // ...and save it
                        $filtered_data[$dr_id]['values'][$df_prefix] = $tmp;
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

                    $new_prefix = $current_prefix.'-'.$child_dt_id;
                    $tmp = self::filterDatarecordArray($new_prefix, $user_permissions, $child_dt, $datafields, $child_dr_list, $datarecords, $inversed_tag_hierarchy, $delimiters, $plugin_executions);
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
     * Extracts xyz data for exporting.
     *
     * @param array $df_data
     * @param string $xyz_column_names
     * @param array $delimiters TODO - define a new delimiter or two for this?
     *
     * @return string
     */
    private function getXYZData($df_data, $xyz_column_names, $delimiters)
    {
        $num_columns = count( explode(',', $xyz_column_names) );
        $xyz_data = array();
        if ( isset($df_data['xyzData']) ) {
            $points = array();

            // Pull the values from the cached datarecord...
            foreach ($df_data['xyzData'] as $num => $data) {
                $tmp = array($data['x_value']);
                if ( $num_columns > 1 )
                    $tmp[] = $data['y_value'];
                if ( $num_columns > 2 )
                    $tmp[] = $data['z_value'];

                $points[] = $tmp;
            }

            // ...then sort by x_value...
            usort($points, function($a, $b) {
                return $a[0] <=> $b[0];
            });

            // ...before finally converting back into a string for export
            foreach ($points as $point)
                $xyz_data[] = '('.implode(',', $point).')';
        }

        return implode('|', $xyz_data);
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
     * @param array $inversed_tag_hierarchy
     * @param array $delimiters
     *
     * @return string
     */
    private function getTagData($df_data, $inversed_tag_hierarchy, $delimiters)
    {
        $tags_to_export = array();
        $tag_chains = array();
        if ( isset($df_data['tagSelection']) ) {
            // Each selected tag should also have its parents selected...this means determining the
            //  strings to export is straightforward...
            $tag_lookup = array();
            foreach ($df_data['tagSelection'] as $t_id => $ts)
                $tag_lookup[$t_id] = $ts['tag']['tagName'];

            // ...but this also means the parent selections need to be filtered out of the array to
            //  prevent duplicates in the export
            foreach ($df_data['tagSelection'] as $t_id => $ts) {
                $tmp = array();
                if ( $ts['selected'] === 1 ) {
                    // Build a "chain" of tag ids, starting with the tag in question...
                    $tmp[] = $t_id;
                    $tag_id = $t_id;
                    while ( isset($inversed_tag_hierarchy[$tag_id]) ) {
                        // ...then repeatedly appending the tag's parent id to the end of the chain
                        $parent_tag_id = $inversed_tag_hierarchy[$tag_id];
                        $tmp[] = $parent_tag_id;
                        $tag_id = $parent_tag_id;
                    }

                    // Store each chain of tag by the id of the tag that started the chain
                    $tag_chains[$t_id] = array_reverse($tmp);
                }
            }

            // Check each chain of tag ids...
            foreach ($tag_chains as $tag_id => $chain) {
                foreach ($chain as $num => $t_id) {
                    // ...if the tag being checked isn't the one that started the chain...
                    if ( $t_id !== $tag_id ) {
                        // ...then ensure that tag doesn't start a chain of its own
                        unset( $tag_chains[$t_id] );
                    }
                }
            }

            // Now that there won't be any duplicates, convert the tag ids into tag names
            foreach ($tag_chains as $tag_id => $chain) {
                $tag_names = array();
                foreach ($chain as $num => $t_id)
                    $tag_names[] = $tag_lookup[$t_id];

                // Implode the chain of tags with the hierarchy delimiter
                $tags_to_export[] = implode(' '.$delimiters['tag_hierarchy'].' ', $tag_names);
            }
            natcasesort($tags_to_export);
        }

        // Implode the list of tags with their delimiters to make a single string
        return implode($delimiters['tag'], $tags_to_export);
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
                if ( isset($child_dr['children']) )
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

                // Not using $child_dr_list, because we want the result of the previous recursion
                foreach ($dr['children'][$child_dt_id] as $child_dr_id => $child_dr) {
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


    /**
     * Does the work of a CSVExport finalize job.
     *
     * @param array $parameters
     * @return string 'success', 'ignore', or 'retry'
     */
    public function finalize($parameters)
    {
        if (!isset($parameters['tracked_job_id'])
            || !isset($parameters['user_id'])

            || !isset($parameters['delimiter'])
            || !isset($parameters['datatype_id'])
            || !isset($parameters['datafields'])

            || !isset($parameters['api_key'])
        ) {
            $this->logger->debug('CSVExportHelperService::finalize(): invalid parameter list');
            $this->logger->debug( print_r($parameters, true) );
            throw new ODRBadRequestException();
        }

        $tracked_job_id = intval($parameters['tracked_job_id']);
        $user_id = intval($parameters['user_id']);
        $datatype_id = $parameters['datatype_id'];

        // The datafield list needs to be decoded from json...it seems as if passing it through the
        //  symfony command turns it into an object otherwise
        $datafields = $parameters['datafields'];
        $datafields = json_decode($datafields, true);

        $file_delimiter = $parameters['delimiter'];
        if ( $file_delimiter === 'tab' )
            $file_delimiter = "\t";

        $api_key = $parameters['api_key'];
        if ( $this->container->getParameter('beanstalk_api_key') !== $api_key )
            throw new ODRBadRequestException('Invalid API key');

        // ----------------------------------------
        // If no tracked job given, then this is probably a debug attempt...ignore the request
        if ( $tracked_job_id === -1 )
            return 'ignore';

        // Need to load the tracked job to determine the progress...
        /** @var TrackedJob $tracked_job */
        $tracked_job = $this->em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);
        if ($tracked_job == null)
            throw new ODRNotFoundException('Tracked Job');

        // Need to also load all the TrackedCSVExport entries of this job...
        /** @var TrackedCSVExport[] $tracked_csv_exports */
        $tracked_csv_exports = $this->em->getRepository('ODRAdminBundle:TrackedCSVExport')->findBy(
            array('trackedJob' => $tracked_job_id)
        );
        if ( count($tracked_csv_exports) == 0 ) {
            // Nothing has happened yet
            return 'retry';
        }

        $most_recent_completion = null;
        $ordered_filenames = array();
        $count = 0;
        foreach ($tracked_csv_exports as $te) {
            $count += $te->getLineCount();
            $ordered_filenames[ $te->getJobOrder() ] = $te;

            if ( is_null($most_recent_completion) )
                $most_recent_completion = $te->getCreated();
            else if ( $te->getCreated() > $most_recent_completion )
                $most_recent_completion = $te->getCreated();
        }
        ksort($ordered_filenames);


        // ----------------------------------------
        // Check for a stalled job
        $interval = $most_recent_completion->diff(new \DateTime());
        // If the most recent progress on the job was over 10 minutes ago, consider it to be stalled
        if ( $interval->y > 0 || $interval->m > 0 || $interval->d > 0 || $interval->h > 0 || $interval->i > 10 ) {
            // Mark stalled jobs as failed
            $tracked_job->setFailed(true);
            $this->em->persist($tracked_job);
            $this->em->flush();
            $this->em->refresh($tracked_job);

            // Throw an exception to get the background process to delete the finalize job
            throw new ODRException('CSVExportHelperService::finalize(): tracked job '.$tracked_job_id.' appears to be stalled, aborting');
        }


        // ----------------------------------------
        if ( $count > $tracked_job->getTotal() ) {
            // TODO
            $this->logger->debug('CSVExportHelperService::finalize(): tracked job '.$tracked_job_id.', count of '.$count.' exceeds total of '.$tracked_job->getTotal());
            throw new ODRException('count for tracked_job '.$tracked_job_id.' is '.$count.', which exceeds the expected count of '.$tracked_job->getTotal());
        }
        else if ( $count < $tracked_job->getTotal() ) {
            // If the count changed from what's currently in the tracked job, then store it
            if ( $count !== $tracked_job->getCurrent() ) {
                $this->logger->debug('CSVExportHelperService::finalize(): setting count to '.$count.' for tracked job '.$tracked_job_id);

                $tracked_job->setCurrent($count);
                $this->em->persist($tracked_job);
                $this->em->flush();

                // NOTE: have to refresh the entity afterwards...
                $this->em->refresh($tracked_job);
                // TODO - ...why use doctrine here if I have to ensure it's updated?
            }

            // Job is not complete yet, continue checking
            return 'retry';
        }
        else if ( $count == $tracked_job->getTotal() ) {
            // Each of the "worker" processes has finished, so the job is completed
            $tracked_job->setCurrent($count);
            $tracked_job->setCompleted(new \DateTime());
            $this->em->persist($tracked_job);
            $this->logger->debug('CSVExportHelperService::finalize(): tracked job '.$tracked_job_id.' complete, beginning finalize...');

            // Originally, there was some more INSERT INTO SELECT FROM WHERE NOT EXISTS crap at
            //  this point in an attempt to ensure only one "finalize" job was created, but it was
            //  dumb and technically another deadlock risk.

            // The "final" file needs to have a header line...the best way to get it is to read the
            //  cached datatype array
            $dt_array = $this->database_info_service->getDatatypeArray($datatype_id, true);    // do need links

            // Modify the given array of datafields to also have typeclass info for later
            $export_datafields = array();
            $new_datafields = array();

            // Ensure this datatype's external id field is exported, if one exists
            $external_id_field = $dt_array[$datatype_id]['dataTypeMeta']['externalIdField'];
            if ( !is_null($external_id_field) ) {
                $external_id_field_id = $external_id_field['id'];
                $export_datafields[$external_id_field_id] = 0;
                $new_datafields[ $datatype_id.'_'.$external_id_field_id ] = array('df_id' => $external_id_field_id, 'typeclass' => '');
            }

            foreach ($datafields as $id_string => $df_id) {
                $export_datafields[$df_id] = 0;
                $new_datafields[$id_string] = array('df_id' => intval($df_id), 'fieldName' => '');
            }
            $datafields = $new_datafields;

            // Dig through the cached datatype array and save the names of all the datatypes...
            $dt_names = array();
            foreach ($dt_array as $dt_id => $dt) {
                if ( isset($dt['dataTypeMeta']['shortName']) )
                    $dt_names[$dt_id] = $dt['dataTypeMeta']['shortName'];
            }
            // ...so prefixes for the datafields can be created if required for clarification
            $field_prefixes = array();
            foreach ($new_datafields as $id_string => $df_data) {
                // The id string has two parts...a list of datatypes to "get to" the field, and the
                //  datafield id
                $pieces = explode('_', $id_string);
                $dt_prefix = $pieces[0];
                $df_id = $pieces[1];

                if ( !isset($field_prefixes[$df_id]) )
                    $field_prefixes[$df_id] = array();
                // Determine the datatypes to "get to" the field
                $pieces = explode('-', $dt_prefix);
                // No point using the top-level datatype's name though...it's implied
                array_shift($pieces);

                // If there are any remaining datatypes...
                if ( !empty($pieces) ) {
                    // ...then create a name for this "path" to reach the field
                    $name_prefix = '';
                    foreach ($pieces as $dt_id)
                        $name_prefix .= $dt_names[$dt_id].' >> ';
                    $field_prefixes[$df_id][$id_string] = $name_prefix;
                }
            }
            // $field_prefixes will still have prefixes for fields which technically don't need them
            foreach ($field_prefixes as $df_id => $prefix_data) {
                // ...so only save the prefixes if there's more than one "path" to "get to" the field
                if ( count($prefix_data) === 1 )
                    unset( $field_prefixes[$df_id] );
            }

            // Dig through the cached datatype array and save the names of all fields being exported
            foreach ($dt_array as $dt_id => $dt) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    if ( isset($export_datafields[$df_id]) ) {
                        $typename = $df['dataFieldMeta']['fieldType']['typeName'];
                        $fieldname = $df['dataFieldMeta']['fieldName'];

                        foreach ($new_datafields as $id_string => $df_data) {
                            if ( $df_data['df_id'] === $df_id ) {

                                // Prepend the disambiguation string to the field if needed
                                $disambiguation_prefix = '';
                                if ( isset($field_prefixes[$df_id][$id_string]) )
                                    $disambiguation_prefix = $field_prefixes[$df_id][$id_string];

                                if ($typename === 'Markdown') {
                                    // Markdown fields can't be exported, so do nothing here
                                }
                                else if ($typename === 'XYZ Data') {
                                    // XYZData fields should have the column names as part of the fieldname
                                    $xyz_column_names = $df['dataFieldMeta']['xyz_data_column_names'];
                                    $datafields[$id_string]['fieldName'] = $disambiguation_prefix.$fieldname.' ('.$xyz_column_names.')';
                                }
                                else {
                                    // All other fieldtypes just use the fieldname
                                    $datafields[$id_string]['fieldName'] = $disambiguation_prefix.$fieldname;
                                }

                                // DO NOT break here...the user could've selected the same datafield
                                //  in multiple linked descendants, and they all need to have their
                                //  names
//                                break;
                            }
                        }
                    }
                }
            }

            // Compress the list of datafields into a header line
            $header_line = array();
            foreach ($datafields as $id_string => $df_data)
                $header_line[] = $df_data['fieldName'];


            // ----------------------------------------
            // Ensure directories exists
            $csv_export_path = $this->container->getParameter('odr_tmp_directory').'/user_'.$user_id.'/';
            if ( !file_exists($csv_export_path) )
                mkdir($csv_export_path);
            $csv_export_path .= 'csv_export/';
            if ( !file_exists($csv_export_path) )
                mkdir($csv_export_path);

            // Make a "final" file for the export, and insert the header line
            $final_filename = 'export_'.$user_id.'_'.$tracked_job_id.'.csv';
            $final_file = fopen($csv_export_path.$final_filename, 'w');

            if ($final_file !== false) {
                $enclosure = "\"";
                $writer = new CsvWriter($file_delimiter, $enclosure);

                $writer->setStream($final_file);
                $writer->writeItem($header_line);
            }
            else {
                throw new ODRException('Unable to open CSVExport final file: "'.$final_filename.'"');
            }


            // Now that the header line is in there, copy each of the "temporary" files into the
            //  "final" file
            foreach ($ordered_filenames as $job_order_num => $te) {
                $random_key = $te->getRandomKey();
                $tmp_filename = 'f_'.$random_key.'.csv';
                $this->logger->debug('CSVExportHelperService::finalize(): -- tracked job '.$tracked_job_id.', appending file "' . $tmp_filename.'"');

                // Copy the contents of this "temporary" file...
                $str = file_get_contents($csv_export_path.$tmp_filename);
                if ( fwrite($final_file, $str) === false ) {
                    $this->logger->debug('CSVExportHelperService::finalize(): !! tracked job '.$tracked_job_id.', could not write to "'.$csv_export_path.$final_filename.'"'."\n");
                    throw new ODRException('Unable to write to CSVExport final file: "'.$final_filename.'"');
                }

                // ...then delete it...
                if ( unlink($csv_export_path.$tmp_filename) === false ) {
                    $this->logger->debug('CSVExportHelperService::finalize(): !! tracked job '.$tracked_job_id.', could not unlink "'.$csv_export_path.$tmp_filename.'"'."\n");
                    throw new ODRException('Unable to delete CSVExport temporary file: "'.$tmp_filename.'"');
                }

                // ...and also delete the TrackedCSVExport entry
                $this->em->remove($te);
            }

            // Save any changes to the database
            $this->em->flush();

            // NOTE: have to refresh the entity afterwards...
            $this->em->refresh($tracked_job);
            // TODO - ...why use doctrine here if I have to ensure it's updated?

            // Done with the file...close it and return success
            fclose($final_file);
            $this->logger->debug('CSVExportHelperService::finalize(): -- tracked job '.$tracked_job_id.' finished, returning success');

            // Close the connection to prevent stale handles?  I think every time this gets loaded the connection is "fresh"...
            $this->em->getConnection()->close();

            return 'success';
        }
    }
}
