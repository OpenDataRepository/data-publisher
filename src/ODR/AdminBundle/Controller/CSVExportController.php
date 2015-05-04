<?php

/**
* Open Data Repository Data Publisher
* CSVExport Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The csvexport controller handles rendering and processing a
* form that allows the user to select which datafields to export
* into a csv file, and also handles the work of exporting the data.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\TrackedExport;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\Boolean;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\FileChecksum;
use ODR\AdminBundle\Entity\ImageChecksum;

// Forms
use ODR\AdminBundle\Form\DecimalValueForm;
use ODR\AdminBundle\Form\IntegerValueForm;
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

// CSV Reader
use Ddeboer\DataImport\Workflow;
use Ddeboer\DataImport\Reader;
use Ddeboer\DataImport\Writer;
use Ddeboer\DataImport\Filter;
use Ddeboer\DataImport\Writer\CsvWriter;


class CSVExportController extends ODRCustomController
{

    /**
     * Sets up a csv export request made from the shortresults list.
     * 
     * @param integer $datatype_id The database id of the DataType to export
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function csvExportListAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $templating = $this->get('templating');
            $session = $request->getSession();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab list of datarecords and associate to search key
            $em = $this->getDoctrine()->getManager();
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.deletedAt IS NULL AND dr.dataType = :datatype'
            )->setParameters( array('datatype' => $datatype_id) );
            $results = $query->getResult();

            $str = '';
            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $str .= $dr_id.',';
            }
            $datarecords = substr($str, 0, strlen($str)-1);


            // Store the list of datarecord ids for later use
            $search_key = 'datatype_id='.$datatype_id;
            $saved_searches = array();
            if ( $session->has('saved_searches') )
                $saved_searches = $session->get('saved_searches');
            $search_checksum = md5($search_key);


            $saved_searches[$search_checksum] = array('logged_in' => true, 'datatype' => $datatype_id, 'datarecords' => $datarecords, 'encoded_search_key' => $search_key);
            $session->set('saved_searches', $saved_searches);


            // Get the mass edit page rendered
            $html = self::csvExportRender($datatype_id, $search_checksum, $request);    // Using $search_checksum so Symfony doesn't screw up $search_key as it is passed around
            $return['d'] = array( 'html' => $html );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x12736280 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Sets up a csv export request made from a search results page.
     * 
     * @param integer $datatype_id The database id of the DataType the search was performed on.
     * @param string $search_key   The search key identifying which datarecords to potentially export
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function csvExportAction($datatype_id, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $templating = $this->get('templating');
            $session = $request->getSession();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $logged_in = true;

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // TODO - this block of code is effectively duplicated in multiple places...
            $encoded_search_key = '';
            $datarecords = '';
            if ($search_key !== '') {
                $search_controller = $this->get('odr_search_controller', $request);
                $search_controller->setContainer($this->container);

                if ( !$session->has('saved_searches') ) {
                    // no saved searches at all for some reason, redo the search with the given search key...
                    $search_controller->performSearch($search_key, $request);
                }

                // Grab the list of saved searches and attempt to locate the desired search
                $saved_searches = $session->get('saved_searches');
                $search_checksum = md5($search_key);

                if ( !isset($saved_searches[$search_checksum]) ) {
                    // no saved search for this query, redo the search...
                    $search_controller->performSearch($search_key, $request);

                    // Grab the list of saved searches again
                    $saved_searches = $session->get('saved_searches');
                }

                $search_params = $saved_searches[$search_checksum];
                $was_logged_in = $search_params['logged_in'];

                // If user's login status changed between now and when the search was run...
                if ($was_logged_in !== $logged_in) {
                    // ...run the search again
                    $search_controller->performSearch($search_key, $request);
                    $saved_searches = $session->get('saved_searches');
                    $search_params = $saved_searches[$search_checksum];
                }

                // Now that the search is guaranteed to exist and be correct...get all pieces of info about the search
                $datarecords = $search_params['datarecords'];
                $encoded_search_key = $search_params['encoded_search_key'];
            }

            // If the user is attempting to view a datarecord from a search that returned no results...
            if ($encoded_search_key !== '' && $datarecords === '') {
                // ...redirect to "no results found" page
                return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
            }


            // Get the mass edit page rendered
            $html = self::csvExportRender($datatype_id, $search_checksum, $request);    // Using $search_checksum so Symfony doesn't screw up $search_key as it is passed around
            $return['d'] = array( 'html' => $html );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x12736279 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

    /**
     * Renders and returns the html used for performing csv exporting
     * 
     * @param integer $datatype_id    The database id that the search was performed on.
     * @param string $search_checksum The md5 checksum created from a $search_key
     * @param Request $request
     * 
     * @return string
     */
    private function csvExportRender($datatype_id, $search_checksum, Request $request)
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);
        $templating = $this->get('templating');

        // --------------------
        // Determine user privileges
        $user = $this->container->get('security.context')->getToken()->getUser();
        $datatype_permissions = parent::getPermissionsArray($user->getId(), $request);
        $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);
        // --------------------

        $datatype = null;
        $theme_element = null;
        if ($datatype_id !== null) 
            $datatype = $repo_datatype->find($datatype_id);

        $indent = 0;
        $is_link = 0;
        $top_level = 1;
        $short_form = true;     // ?

$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

        $tree = parent::buildDatatypeTree($user, $theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);

if ($debug)
    print '</pre>';


        $html = $templating->render(
            'ODRAdminBundle:CSVExport:csvexport_ajax.html.twig',
            array(
//                'datafield_permissions' => $datafield_permissions,
                'search_checksum' => $search_checksum,
                'datatype_tree' => $tree,
                'theme' => $theme,
            )
        );

        return $html;
    }


    /**
     * Begins the process of mass exporting to a csv file, by creating a beanstalk job containing which datafields to export for each datarecord being exported 
     * 
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred.
     */
    public function csvExportStartAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure post is valid
            $post = $_POST;
//print_r($post);
//return;

            if ( !(isset($post['search_checksum']) && isset($post['datafields']) && isset($post['datatype_id'])) )
                throw new \Exception('bad post request');
            $search_checksum = $post['search_checksum'];
            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];
            $datatype_id = $post['datatype_id'];

            // TODO - ensure datafields belong to datatype?

            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

//            $memcached = $this->get('memcached');
//            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');

            $url = $this->container->getParameter('site_baseurl');
            $url .= $this->container->get('router')->generate('odr_csv_export_construct');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $logged_in = true;

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Ensure datafield ids are numeric? 
            foreach ($datafields as $num => $datafield_id) {
                if ( !is_numeric($datafield_id) )
                    throw new \Exception('bad post request');
            }


            // TODO - assumes search exists
            $search_controller = $this->get('odr_search_controller', $request);
            $search_controller->setContainer($this->container);
            // Grab the list of saved searches and attempt to locate the desired search
            $saved_searches = $session->get('saved_searches');
            $search_params = $saved_searches[$search_checksum];
            // Now that the search is guaranteed to exist and be correct...get all pieces of info about the search
            $datarecords = $search_params['datarecords'];
            $encoded_search_key = $search_params['encoded_search_key'];


            // If the user is attempting to view a datarecord from a search that returned no results...
            if ($encoded_search_key !== '' && $datarecords === '') {
                // ...redirect to "no results found" page
                return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
            }

            $datarecords = explode(',', $datarecords);
//print_r($datarecords);
//print_r($datafields);
//return;

            // ----------------------------------------
            // Get/create an entity to track the progress of this datatype recache
            $job_type = 'csv_export';
            $target_entity = 'datatype_'.$datatype_id;
            $description = 'Exporting data from DataType '.$datatype_id;
            $restrictions = '';
            $total = count($datarecords);
            $reuse_existing = false;

            // Determine if this user already has an export job going for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => $job_type, 'target_entity' => $target_entity, 'createdBy' => $user->getId(), 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('You already have an export job going for this datatype...wait until that one finishes before starting a new one');
            else
                $tracked_job = self::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $description, $restrictions, $total, $reuse_existing);

            $tracked_job_id = $tracked_job->getId();


            // ----------------------------------------
            // Create a beanstalk job for each of these datarecords
            foreach ($datarecords as $num => $datarecord_id) {

                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        'tracked_job_id' => $tracked_job_id,
                        'user_id' => $user->getId(),
                        'datarecord_id' => $datarecord_id,
                        'datafields' => $datafields,
                        'memcached_prefix' => $memcached_prefix,    // debug purposes only
                        'datatype_id' => $datatype_id,              // debug purposes only?
                        'url' => $url,
                        'api_key' => $api_key,
                    )
                );

//print_r($payload);

                $delay = 1; // one second
                $pheanstalk->useTube('csv_export_start')->put($payload, $priority, $delay);
            }

            // TODO - Notify user that job has begun?
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x24397429 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Given a datarecord id and a list of datafield ids, builds a line of csv data used by Ddeboer\DataImport\Writer\CsvWriter later
     * 
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred.
     */
    public function csvExportConstructAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
//return;

            if ( !isset($post['tracked_job_id']) || !isset($post['user_id']) || !isset($post['datarecord_id']) || !isset($post['datatype_id']) || !isset($post['datafields']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $datarecord_id = $post['datarecord_id'];
            $datatype_id = $post['datatype_id'];
            $datafields = $post['datafields'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->get('doctrine')->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');

            // TODO - permissions?

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            $url = $this->container->getParameter('site_baseurl');
            $url .= $this->container->get('router')->generate('odr_csv_export_worker');


            // ----------------------------------
            // Load FieldTypes of the datafields
            $query = $em->createQuery(
               'SELECT df.id AS df_id, df.fieldName AS fieldname, ft.typeClass AS typeclass, ft.typeName AS typename
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:FieldType AS ft WITH df.fieldType = ft
                WHERE df IN (:datafields)'
            )->setParameters( array('datafields' => $datafields) );
            $results = $query->getArrayResult();
//print_r($results);

            $typeclasses = array();
            foreach ($results as $num => $result) {
                $typeclass = $result['typeclass'];
                $typename = $result['typename'];

                if ($typeclass !== 'Radio' && $typeclass !== 'File' && $typeclass !== 'Image' && $typename !== 'Markdown') {
                    if ( !isset($typeclasses[ $result['typeclass'] ]) )
                        $typeclasses[ $result['typeclass'] ] = array();

                    $typeclasses[ $result['typeclass'] ][] = $result['df_id'];
                }
            }

//print_r($typeclasses);
//return;

            // ----------------------------------
            // Need to grab external id for this datarecord
            $query = $em->createQuery(
               'SELECT dr.external_id AS external_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.id = :datarecord AND dr.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $datarecord_id) );
            $result = $query->getArrayResult();
            $external_id = $result[0]['external_id'];


            // ----------------------------------
            // Grab data for each of the datafields selected for export
            $datarecord_data = array();
            foreach ($typeclasses as $typeclass => $df_list) {
                $query = $em->createQuery(
                   'SELECT df.id AS df_id, e.value AS value
                    FROM ODRAdminBundle:'.$typeclass.' AS e
                    JOIN ODRAdminBundle:DataRecord AS dr WITH e.dataRecord = dr
                    JOIN ODRAdminBundle:DataFields AS df WITH e.dataField = df
                    JOIN ODRAdminBundle:FieldType AS ft WITH df.fieldType = ft
                    WHERE dr.id = :datarecord AND df.id IN (:datafields) AND ft.typeClass = :typeclass
                    AND e.deletedAt IS NULL AND dr.deletedAt IS NULL AND df.deletedAt IS NULL'
                )->setParameters( array('datarecord' => $datarecord_id, 'datafields' => $df_list, 'typeclass' => $typeclass) );
                $results = $query->getArrayResult();

                foreach ($results as $num => $result) {
                    $df_id = $result['df_id'];
                    $value = $result['value'];

                    if ($typeclass == 'DatetimeValue') {
                        $date = $value->format('Y-m-d');
                        if ( strpos($date, '-0001-11-30') !== false )
                            $date = '0000-00-00';

                        $datarecord_data[$df_id] = $date;
                    }
                    else {
                        $datarecord_data[$df_id] = $value;
                    }
                }
            }

            // Sort by datafield id to ensure columns are always in same order in csv file
            ksort($datarecord_data);
//print_r($datarecord_data);
//return;

            $line = array();
            $line[] = $external_id;

            foreach ($datarecord_data as $df_id => $data)
                $line[] = $data;

//print_r($line);
//return;

            // ----------------------------------------
            // Create a beanstalk job for this datarecord
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    'tracked_job_id' => $tracked_job_id,
                    'user_id' => $user_id,
                    'datarecord_id' => $datarecord_id,
                    'datatype_id' => $datatype_id,
                    'datafields' => $datafields,
                    'line' => $line,
                    'memcached_prefix' => $memcached_prefix,    // debug purposes only
                    'url' => $url,
                    'api_key' => $api_key,
                )
            );

//print_r($payload);

            $delay = 1; // one second
            $pheanstalk->useTube('csv_export_worker')->put($payload, $priority, $delay);

            $return['d'] = '';
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x24463979 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Writes a line of csv data to a file
     * 
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred.
     */
    public function csvExportWorkerAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
//return;

            if ( !isset($post['tracked_job_id']) || !isset($post['user_id']) || !isset($post['line']) || !isset($post['datafields']) || !isset($post['random_key']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $line = $post['line'];
            $datafields = $post['datafields'];
            $random_key = $post['random_key'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->get('doctrine')->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');

            // TODO - permissions?

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Ensure the random key is stored in the database for later retrieval by the finalization process
            $tracked_export = $em->getRepository('ODRAdminBundle:TrackedExport')->findOneBy( array('random_key' => $random_key) );
            if ($tracked_export == null) {
                // ...TECHNICALLY, THIS IS OVERKILL BECAUSE $random_key IS UNIQUE...
                // TODO - CURRENTLY WORKS, BUT MIGHT WANT TO LOOK INTO AN OFFICIAL MUTEX...

                $query =
                   'INSERT INTO odr_tracked_export (random_key, tracked_job_id)
                    SELECT * FROM (SELECT :random_key, :tj_id) AS tmp
                    WHERE NOT EXISTS (
                        SELECT random_key FROM odr_tracked_export WHERE random_key = :random_key AND tracked_job_id = :tj_id
                    ) LIMIT 1;';
                $params = array('random_key' => $random_key, 'tj_id' => $tracked_job_id);
                $conn = $em->getConnection();
                $rowsAffected = $conn->executeUpdate($query, $params);

//print 'rows affected: '.$rowsAffected."\n";
            }

            // Open the indicated file
            $csv_export_path = dirname(__FILE__).'/../../../../web/uploads/csv_export/';
            $filename = 'f_'.$random_key.'.csv';
            $handle = fopen($csv_export_path.'tmp/'.$filename, 'a');
            if ($handle !== false) {
                // Write the line given to the file
                // https://github.com/ddeboer/data-import/blob/master/src/Ddeboer/DataImport/Writer/CsvWriter.php
                $delimiter = "\t";
                $enclosure = "\"";
                $writer = new CsvWriter($delimiter, $enclosure);

                $writer->setStream($handle);
                $writer->writeItem($line);

                // Close the file
                fclose($handle);
            }
            else {
                // Unable to open file
                throw new \Exception('could not open csv export file...');
            }


            // ----------------------------------------
            // Update the job tracker if necessary
            $completed = false;
            if ($tracked_job_id !== -1) {
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total) {
                    $tracked_job->setCompleted( new \DateTime() );
                    $completed = true;
                }

                $em->persist($tracked_job);
                $em->flush();
//print '  Set current to '.$count."\n";
            }


            // ----------------------------------------
            // If this was the last line to write to be written to a file for this particular export...
            // NOTE - incrementCurrent()'s current implementation can't guarantee that only a single process will enter this block...so have to ensure that only one process starts the finalize step
            $random_keys = array();
            if ($completed) {
                // Make a hash from all the random keys used
                $query = $em->createQuery(
                   'SELECT te.id AS id, te.random_key AS random_key
                    FROM ODRAdminBundle:TrackedExport AS te
                    WHERE te.trackedJob = :tracked_job AND te.finalize = 0
                    ORDER BY te.id'
                )->setParameters( array('tracked_job' => $tracked_job_id) );
                $results = $query->getArrayResult();

                // Due to ORDER BY, every process entering this section should compute the same $random_key_hash
                $random_key_hash = '';
                foreach ($results as $num => $result) {
                    $random_keys[ $result['id'] ] = $result['random_key'];
                    $random_key_hash .= $result['random_key'];
                }
                $random_key_hash = md5($random_key_hash);
//print $random_key_hash."\n";


                // Attempt to insert this hash back into the database...
                // TODO - CURRENTLY WORKS, BUT MIGHT WANT TO LOOK INTO AN OFFICIAL MUTEX...
                $query =
                   'INSERT INTO odr_tracked_export (random_key, tracked_job_id, finalize)
                    SELECT * FROM (SELECT :random_key_hash, :tj_id, :finalize) AS tmp
                    WHERE NOT EXISTS (
                        SELECT random_key FROM odr_tracked_export WHERE random_key = :random_key_hash AND tracked_job_id = :tj_id AND finalize = :finalize
                    ) LIMIT 1;';
                $params = array('random_key_hash' => $random_key_hash, 'tj_id' => $tracked_job_id, 'finalize' => true);
                $conn = $em->getConnection();
                $rowsAffected = $conn->executeUpdate($query, $params);

//print 'rows affected: '.$rowsAffected."\n";

                if ($rowsAffected == 1) {
                    // This is the first process to attempt to insert this key...it will be in charge of creating the information used to concatenate the temporary files together
                    $completed = true;
                }
                else {
                    // This is not the first process to attempt to insert this key, do nothing so multiple finalize jobs aren't created
                    $completed = false;
                }
            }


            // ----------------------------------------
            if ($completed) {
                // Determine the contents of the header line
                $header_line = array(0 => '_external_id');
                $query = $em->createQuery(
                   'SELECT df.id AS id, df.fieldName AS fieldName
                    FROM ODRAdminBundle:DataFields AS df
                    WHERE df.id IN (:datafields) AND df.deletedAt IS NULL'
                )->setParameters( array('datafields' => $datafields) );
                $results = $query->getArrayResult();
                foreach ($results as $num => $result) {
                    $df_id = $result['id'];
                    $df_name = $result['fieldName'];

                    $header_line[$df_id] = $df_name;
                }

                // Sort by datafield id so order of header columns matches order of data
                ksort($header_line);

//print_r($header_line);

                // Make a "final" file for the export, and insert the header line
                $final_filename = 'export_'.$user_id.'_'.$tracked_job_id.'.csv';
                $final_file = fopen($csv_export_path.$final_filename, 'w');

                if ($final_file !== false) {
                    $delimiter = "\t";
                    $enclosure = "\"";
                    $writer = new CsvWriter($delimiter, $enclosure);

                    $writer->setStream($final_file);
                    $writer->writeItem($header_line);
                }
                else {
                    throw new \Exception('could not open csv export file...b');
                }

                fclose($final_file);

                // ----------------------------------------
                // Now that the "final" file exists, need to splice the temporary files together into it
                $url = $this->container->getParameter('site_baseurl');
                $url .= $this->container->get('router')->generate('odr_csv_export_finalize');

                // 
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        'tracked_job_id' => $tracked_job_id,
                        'final_filename' => $final_filename,
                        'random_keys' => $random_keys,

                        'user_id' => $user_id,
                        'memcached_prefix' => $memcached_prefix,    // debug purposes only
                        'url' => $url,
                        'api_key' => $api_key,
                    )
                );

//print_r($payload);

                $delay = 1; // one second
                $pheanstalk->useTube('csv_export_finalize')->put($payload, $priority, $delay);
            }

            $return['d'] = '';
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x302421399 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Takes a list of temporary files used for csv exporting, and appends each of their contents to a "final" export file
     * 
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred.
     */
    public function csvExportFinalizeAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
//return;

            if ( !isset($post['tracked_job_id']) || !isset($post['final_filename']) || !isset($post['random_keys']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $final_filename = $post['final_filename'];
            $random_keys = $post['random_keys'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->get('doctrine')->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');

            // TODO - permissions?

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');



            // -----------------------------------------
            // Append the contents of one of the temporary files to the final file
            $csv_export_path = dirname(__FILE__).'/../../../../web/uploads/csv_export/';
            $final_file = fopen($csv_export_path.$final_filename, 'a');

            // Go through and append the contents of each of the temporary files to the "final" file
            $tracked_export_id = null;
            foreach ($random_keys as $tracked_export_id => $random_key) {
                $tmp_filename = 'f_'.$random_key.'.csv';
                $str = file_get_contents($csv_export_path.'tmp/'.$tmp_filename);
//print $str."\n\n";

                if ( fwrite($final_file, $str) === false )
                    print 'could not write to "'.$csv_export_path.$final_filename.'"'."\n";

                // Done with this intermediate file, get rid of it
                if ( unlink($csv_export_path.'tmp/'.$tmp_filename) === false )
                    print 'could not unlink "'.$csv_export_path.'tmp/'.$tmp_filename.'"'."\n";

                $tracked_export = $em->getRepository('ODRAdminBundle:TrackedExport')->find($tracked_export_id);
                $em->remove($tracked_export);
                $em->flush();

                fclose($final_file);

                // Only want to append the contents of a single temporary file to the final file at a time
                break;
            }


            // -----------------------------------------
            // Done with this temporary file
            unset($random_keys[$tracked_export_id]);

            if ( count($random_keys) >= 1 ) {
                // Create another beanstalk job to get another file fragment appended to the final file
                $url = $this->container->getParameter('site_baseurl');
                $url .= $this->container->get('router')->generate('odr_csv_export_finalize');

                // 
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        'tracked_job_id' => $tracked_job_id,
                        'final_filename' => $final_filename,
                        'random_keys' => $random_keys,

                        'user_id' => $user_id,
                        'memcached_prefix' => $memcached_prefix,    // debug purposes only
                        'url' => $url,
                        'api_key' => $api_key,
                    )
                );

//print_r($payload);

                $delay = 1; // one second
                $pheanstalk->useTube('csv_export_finalize')->put($payload, $priority, $delay);
            }
            else {
                // Remove finalize marker from ODRAdminBundle:TrackedExport
                $tracked_export = $em->getRepository('ODRAdminBundle:TrackedExport')->findOneBy( array('trackedJob' => $tracked_job_id) );  // should only be one left
                $em->remove($tracked_export);
                $em->flush();

                // TODO - Notify user that export is ready
            }

            $return['d'] = '';
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x32439779 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }



    /**
     * Renders a page to download a CSV file from TODO
     * 
     * @param integer $datatype_id TODO
     * @param Request $request
     * 
     * @return TODO
     */
    public function exportPageAction($datatype_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
//            $em = $this->getDoctrine()->getManager();
//            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
//            $datarecord = $repo_datarecord->find($datarecord_id);
//            $datatype = $datarecord->getDataType();
            $templating = $this->get('templating');

//            $xml_export_path = dirname(__FILE__).'/../../../../web/uploads/xml_export/';
//            $filename = 'DataRecord_'.$datarecord_id.'.xml';
/*
            $handle = fopen($xml_export_path.$filename, 'w');
            if ($handle !== false) {
                $content = parent::XML_GetDisplayData($request, $datarecord_id);
                fwrite($handle, $content);
                fclose($handle);
*/
                $return['d'] = array(
                    'html' => $templating->render('ODRAdminBundle:CSVExport:csv_download.html.twig', array('datatype_id' => $datatype_id))
                );
/*
            }
            else {
                // Shouldn't be an issue?
                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = 'Error 0x848128635 Could not open file at "'.$xml_export_path.$filename.'"';
            }
*/
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x848128635 ' . $e->getMessage();
        }

        // If error encountered, do a json return
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**         
     * Sidesteps symfony to set up an CSV file download...TODO 
     * 
     * @param integer $user_id TODO
     * @param integer $tracked_job_id TODO
     * @param Request $request
     *          
     * @return TODO
     */
    public function downloadCSVAction($user_id, $tracked_job_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = ''; 
            
        $response = new Response();
        
        try {
            // TODO - permissions

            $csv_export_path = dirname(__FILE__).'/../../../../web/uploads/csv_export/';
            $filename = 'export_'.$user_id.'_'.$tracked_job_id.'.csv';

            $handle = fopen($csv_export_path.$filename, 'r');
            if ($handle !== false) {
        
                // Set up a response to send the file back
                $response->setPrivate();
                $response->headers->set('Content-Type', mime_content_type($csv_export_path.$filename));
                $response->headers->set('Content-Length', filesize($csv_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'";');
    
                $response->sendHeaders();

                $content = file_get_contents($csv_export_path.$filename);   // using file_get_contents() because apparently readfile() tacks on # of bytes read at end of file for firefox
                $response->setContent($content);

                fclose($handle);
            }
            else {
                throw new \Exception('Could not open requested file');
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x848128635 ' . $e->getMessage();
        }

        if ($return['r'] !== 0) {
            // If error encountered, do a json return
            $response = new Response(json_encode($return));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        }
        else {
            // Return the previously created response
            return $response;
        }

    }

}

?>
