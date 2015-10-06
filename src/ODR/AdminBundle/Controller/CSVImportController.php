<?php

/**
* Open Data Repository Data Publisher
* CSV Import Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The CSV controller handles the creation, initialization, and 
* execution of an import from a CSV file.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\TrackedError;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\RadioOption;
use ODR\AdminBundle\Entity\RadioSelection;
// Forms
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\ShortVarcharForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
// CSV Reader
use Ddeboer\DataImport\Workflow;
use Ddeboer\DataImport\Reader;
use Ddeboer\DataImport\Writer;
use Ddeboer\DataImport\Filter;
use Ddeboer\DataImport\Reader\CsvReader;


class CSVImportController extends ODRCustomController
{

    /**
     * Performs the initial setup for dealing with a CSV import request.
     *
     * @param integer $datatype_id Which DataType the CSV data is being imported into.
     * @param Request $request
     *
     * @return a Symfony JSON response containing HTML TODO
     */
    public function importAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo_datatype->find($datatype_id);

            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // --------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // Reset the user's csv import delimiter
            $session = $request->getSession();
            $session->set('csv_delimiter', '');

            // Render the basic csv import page
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:CSVImport:import.html.twig',
                    array(
                        'datatype' => $datatype,
                        'upload_type' => 'csv',

                        'presets' => null,
                        'errors' => null,
                        'allow_import' => false,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x468215567 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Easier to handle CSV delimiter through a direct HTTP request instead of lumping it into the Flow.js upload logic.
     *
     * @param string $delimiter
     * @param Request $request
     *
     * @return none
     */
    public function delimiterAction($delimiter, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Store the desired delimiter in user's session
            $session = $request->getSession();
            $csv_delimiter = '';

            switch ($delimiter) {
                case 'tab':
                    $csv_delimiter = "\t";
                    break;
                case 'space':
                    $csv_delimiter = " ";
                    break;
                case 'comma':
                    $csv_delimiter = ",";
                    break;
                case 'semicolon':
                    $csv_delimiter = ";";
                    break;
                case 'colon':
                    $csv_delimiter = ":";
                    break;
                case 'pipe':
                    $csv_delimiter = "|";
                    break;
/*
                default:
                    throw new \Exception('Select a delimiter');
                    break;
*/
            }
            $session->set('csv_delimiter', $csv_delimiter);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x821135537 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Reads the previously uploaded CSV file to extract column names, and renders a form to let the user decide what data to import and which DataFields to import it to.
     * @see CSVImportController:uploadAction()
     *
     * @param integer $datatype_id Which datatype the CSV data is being imported into.
     * @param Request $request
     *
     * @return a Symfony JSON response containing the HTML TODO 
     */
    public function layoutAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $session = $request->getSession();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // --------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // --------------------
            // Grab all datafields belonging to that datatype
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                WHERE df.dataType = :datatype AND df.deletedAt IS NULL
                ORDER BY df.fieldName'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $datafields = $query->getResult();
//print_r($results);
//exit();

            // Grab the FieldTypes that the csv importer can read data into
            // TODO - naming fieldtypes by number
            $fieldtype_array = array(
                1, // boolean
                2, // file
                3, // image
                4, // integer
                5, // paragraph text
                6, // long varchar
                7, // medium varchar
                8, // single radio
                9, // short varchar
                11, // datetime
                13, // multiple radio
                14, // single select
                15, // multiple select
                16, // decimal
//                17, // markdown
            );
            $fieldtypes = $repo_fieldtype->findBy( array('id' => $fieldtype_array) );

            // Attempt to load the previously uploaded csv file
            if ( !$session->has('csv_file') )
                throw new \Exception('No CSV file uploaded');

            // Remove any completely blank columns from the file
            self::removeBlankColumns($user->getId(), $request);

            $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user->getId().'/';
            $csv_filename = $session->get('csv_file');
            $delimiter = $session->get('csv_delimiter');

            // Apparently SplFileObject doesn't do this before opening the file...
            ini_set('auto_detect_line_endings', TRUE);

            // Symfony has already verified that the file's mimetype is valid...
            $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
            $reader = new CsvReader($csv_file, $delimiter);
            $reader->setHeaderRowNumber(0);     // want associative array for the column names

            // Get the first row of the csv file
            $file_headers = $reader->getColumnHeaders();
            $line_num = 1;
            $encoding_errors = array();

            foreach ($reader as $row) {
                // Keep track of the line number so UTF-8 errors can be accurately listed
                $line_num++;

                // TODO - this eventually needs to be done via beanstalk?  but can't json_encode the data unless it passes this check...
                if ( count($row) > 0 ) {
                    foreach ($row as $col_name => $col_data) {
                        // Check each piece of data for encoding errors
                        if ( mb_check_encoding($col_data, "utf-8") == false )       // this check needs to be performed prior to a json_encode
                            $encoding_errors[$line_num][] = $col_name;
                    }
                }
            }

//exit();
//print_r($encoding_errors);

            // Grab column names from first row
            $error_messages = array();
            $columns = array();
            foreach ($file_headers as $column_num => $value) {
                if ($value == '')
                    $error_messages[] = array( 'error_level' => 'Error', 'error_body' => array('line_num' => 1, 'message' => 'Column '.($column_num+1).' has an illegal blank header') );
            }

            // Notify of "syntax" errors in the csv file
            if ( count($encoding_errors) > 0 || count($reader->getErrors()) > 0 ) {

                // Warn about invalid encoding
                foreach ($encoding_errors as $line_num => $errors) {
                    $str = ' the column "'.$errors[0].'"';
                    if ( count($errors) > 1 )
                        $str = ' the columns '.implode('", "', $errors);

                    $error_messages[] = array( 'error_level' => 'Error', 'error_body' => array('line_num' => $line_num+1, 'message' => 'Invalid UTF-8 character in'.$str) );
                }

                // Warn about wrong number of columns
                foreach ($reader->getErrors() as $line_num => $errors) {
                    $error_messages[] = array( 'error_level' => 'Error', 'error_body' => array('line_num' => $line_num+1, 'message' => 'Found '.count($errors).' columns on this line, expected '.count($file_headers)) );
                }
            }

//print_r($error_messages);


            // Render the page
            $templating = $this->get('templating');
            if ( count($error_messages) == 0 ) {
                // If no errors, render the column/datafield/fieldtype selection page
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:CSVImport:layout.html.twig',
                        array(
                            'columns' => $file_headers,
                            'datatype' => $datatype,
                            'datafields' => $datafields,
                            'fieldtypes' => $fieldtypes,
                            'allowed_fieldtypes' => $fieldtype_array,

                            'presets' => null,
                        )
                    )
                );
            }
            else {
                // If errors found, render a table listing which errors are found on what line
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:CSVImport:errors.html.twig',
                        array(
                            'error_messages' => $error_messages,
                        )
                    )
                );
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x224681522 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     *  Because Excel on Macintosh computers apparently can't always manage to keep itself from 
     *  exporting completely blank columns in the csv files it creates, there needs to be
     *  a function to strip completely blank columns from csv files.
     *
     * @param integer $user_id
     * @param Request $request
     * 
     * @return none
     */
    private function removeBlankColumns($user_id, Request $request)
    {
        $session = $request->getSession();

        // Attempt to load the previously uploaded csv file
        if ( !$session->has('csv_file') )
            throw new \Exception('No CSV file uploaded');

        $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user_id.'/';
        $csv_filename = $session->get('csv_file');
        $delimiter = $session->get('csv_delimiter');

        // Apparently SplFileObject doesn't do this before opening the file...
        ini_set('auto_detect_line_endings', TRUE);

        $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
        $csv_file->setFlags(
            \SplFileObject::READ_CSV |
            \SplFileObject::SKIP_EMPTY |
            \SplFileObject::READ_AHEAD |
            \SplFileObject::DROP_NEW_LINE
        );
        $csv_file->setCsvControl($delimiter);    // use default settings for enclosure and escape characters

        // Read first row
        $header_row = $csv_file->fgetcsv(); // automatically increments file pointer
//print_r($header_row);

        // Determine if any of the column headers are blank...
        $blank_header = false;
        $column_use = array();
        for ($i = 0; $i < count($header_row); $i++) {
            if ( $header_row[$i] !== '' )
                $column_use[$i] = true;
            else
                $column_use[$i] = false;
        }
        foreach ($column_use as $column_id => $in_use) {
            if ($in_use == false)
                $blank_header = true;
        }

        // If none of the column headers are blank, then don't bother reading rest of file
        if (!$blank_header) {
//print 'early exit';
            $csv_file = null;    // close SplFileObject?
            return;
        }

        // Continue reading the file...
        while ( $csv_file->valid() ) {
            $row = $csv_file->fgetcsv();    // automatically increments file pointer
            if ( count($row) == 0 )
                continue;
//print_r($row);

            // If column mis-match, don't bother reading rest of file
            if ( count($row) !== count($header_row) ) {
//print 'column mismatch';
                $csv_file = null;
                return;
            }

            // If a column previously thought to be blank has a value in the row, update the array
            foreach ($row as $column_id => $value) {
                if ($value !== '' && $column_use[$column_id] == false)
                    $column_use[$column_id] = true;
            }
        }

        // Done reading file...
        $rewrite_file = false;
        foreach ($column_use as $column_id => $in_use) {
            if (!$in_use) {
//print 'column '.$column_id.' not in use'."\n";
                $rewrite_file = true;
            }
        }

        // If no completely blank columns...
        if (!$rewrite_file) {
//print "don't need to rewrite file";
            $csv_file = null;
            return;
        }

        // Need to rewrite the original csv file...create a new temporary csv file
        $tokenGenerator = $this->container->get('fos_user.util.token_generator');
        $tmp_filename = substr($tokenGenerator->generateToken(), 0, 12);
        // TODO - other illegal first characters for filename?
        if ( substr($tmp_filename, 0, 1) == '-' )
            $tmp_filename = 'a'.substr($tmp_filename, 1);
        $tmp_filename .= '.csv';

        $new_csv_file = fopen( $csv_import_path.$tmp_filename, 'w' );   // apparently SplFileObject doesn't have fputcsv()
//        $session->set('csv_file', $tmp_filename);

        $blank_columns = array();
        foreach ($column_use as $column_id => $in_use) {
            if (!$in_use)
                $blank_columns[] = $column_id;
        }

        // Rewind the file pointer to the original csv file to the **second** line, then print out the header row without the headers for the blank columns
        $csv_file->rewind();
        $new_header_row = $header_row;
        foreach ($blank_columns as $num => $column_id)
            unset($new_header_row[$column_id]);
        fputcsv($new_csv_file, $new_header_row, $delimiter);

        // Do the same for all the other rows in the file
        while ( $csv_file->valid() ) {
            $row = $csv_file->fgetcsv();    // automatically advances file pointer
            if ( count($row) == 0 )
                continue;

            foreach ($blank_columns as $num => $column_id)
                unset( $row[$column_id] );
//print_r($row);

            fputcsv($new_csv_file, $row, $delimiter);
        }

        // Move the contents of the temporary csv file back into the original csv file
        $csv_file = null;
        fclose($new_csv_file);

        rename($csv_import_path.$tmp_filename, $csv_import_path.$csv_filename);
        return;
    }


    /**
     * Builds and returns a JSON list of the files that have been uploaded to the user's csv storage directory
     * 
     * @param Request $request
     *
     * @return TODO
     */
    public function refreshfilelistAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // TODO - permissions?
            $user = $this->container->get('security.context')->getToken()->getUser();

            // Get all files in the given user's 'upload' directory
            $uploaded_files = array();
            $upload_directory = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user->getId().'/storage';
            if ( file_exists($upload_directory) )
                $uploaded_files = scandir($upload_directory);

            // Don't include the default linux directory pointers...
            $filelist = array();
            foreach ($uploaded_files as $num => $filename) {
                if ($filename !== '.' && $filename !== '..')
                    $filelist[$filename] = date( 'Y-m-d H:i:s T', filemtime($upload_directory.'/'.$filename) );
            }
            asort($filelist);

            // Return the list of files as a json array
            $return['t'] = 'json';
            $return['d'] = $filelist;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x627153467 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Delete all files that have been uploaded to the user's csv storage directory
     * 
     * @param Request $request
     *
     * @return TODO
     */
    public function deletefilelistAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // TODO - permissions?
            $user = $this->container->get('security.context')->getToken()->getUser();

            // Get all files in the given user's 'upload' directory
            $uploaded_files = array();
            $upload_directory = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user->getId().'/storage';
            if ( file_exists($upload_directory) )
                $uploaded_files = scandir($upload_directory);

            // Don't delete the default linux directory pointers...
            foreach ($uploaded_files as $num => $filename) {
                if ($filename !== '.' && $filename !== '..')
                    unlink( $upload_directory.'/'.$filename );
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x627153468 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes any csv-specific data from the user's session, and also deletes any csv file they uploaded.
     * TODO - replace this functionality with a more conventional "this is the list of CSV files have been uploaded...what do you want to do with them?"
     *
     * @param Request $request
     *
     * @return an empty Symfony JSON response, unless some sort of error occurred.
     */
    public function cancelAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Attempt to locate the csv file stored in the user's session
            $user = $this->container->get('security.context')->getToken()->getUser();
            $session = $request->getSession();
            if ( $session->has('csv_file') ) {
                // Delete the file if it exists
                $filename = $session->get('csv_file');
                $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user->getId().'/';
                if ( file_exists($csv_import_path.$filename) )
                    unlink($csv_import_path.$filename);

                // Delete csv-specific data from the user's session
                $session->remove('csv_file');
                $session->remove('csv_delimiter');
            }

            // The page will reload itself to reset the HTML
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x42627153467 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Reads a $_POST request for importing a CSV file, and creates a beanstalk job to validate each line in the file.
     *
     * @param Request $request
     *
     * @return an empty Symfony JSON response, unless an error occurred.
     */
    public function startvalidateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['datafield_mapping']) || !isset($post['datatype_id']) )
                throw new \Exception('Invalid Form');

            // --------------------
            // Pull data from the post
            $datafield_mapping = $post['datafield_mapping'];
            $datatype_id = $post['datatype_id'];

            // Get datafields where uniqueness will be checked for/enforced
            $unique_columns = array();
            if ( isset($post['unique_columns']) )
                $unique_columns = $post['unique_columns'];
            // Grab fieldtype mapping for datafields this import is going to create, if the user chose to create new datafields
            $fieldtype_mapping = null;
            if ( isset($post['fieldtype_mapping']) )
                $fieldtype_mapping = $post['fieldtype_mapping'];
            // Get secondary delimiters to use for multiple select/radio columns, if they exist
            $column_delimiters = array();
            if ( isset($post['column_delimiters']) )
                $column_delimiters = $post['column_delimiters'];


            // --------------------
            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $session = $request->getSession();

            $router = $this->get('router');
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_csv_import_validate');

            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new \Exception('Invalid Form');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // ----------------------------------------
            // Need to keep track of which columns are files/images
            $file_columns = array();

            // Ensure that the datatype/fieldtype mappings and secondary column delimiters work
            foreach ($datafield_mapping as $col_num => $datafield_id) {
                if ($datafield_id == 'new') {
                    // Since a new datafield will be created, ensure fieldtype exists
                    if ( $fieldtype_mapping == null )
                        throw new \Exception('Invalid Form...no fieldtype_mapping');
                    if ( !isset($fieldtype_mapping[$col_num]) )
                        throw new \Exception('Invalid Form...$fieldtype_mapping['.$col_num.'] not set');

                    // If new datafield is multiple select/radio, or new datafield is file/image, ensure secondary delimiters exist
                    if ($fieldtype_mapping[$col_num] == 13 || $fieldtype_mapping[$col_num] == 15 || $fieldtype_mapping[$col_num] == 2 || $fieldtype_mapping[$col_num] == 3) {   // TODO - naming fieldtypes by number
                        if ( $column_delimiters == null )
                            throw new \Exception('Invalid Form a...no column_delimiters');
                        if ( !isset($column_delimiters[$col_num]) )
                            throw new \Exception('Invalid Form a...$column_delimiters['.$col_num.'] not set');

                        // Keep track of file/image columns...
                        if ($fieldtype_mapping[$col_num] == 2 || $fieldtype_mapping[$col_num] == 3)
                            $file_columns[] = $col_num;
                    }
                }
                else {
                    // Ensure datafield exists
                    $datafield = $repo_datafield->find($datafield_id);
                    if ($datafield == null)
                        throw new \Exception('Invalid Form...deleted DataField');

                    // Ensure fieldtype mapping entry exists
                    $fieldtype_mapping[$col_num] = $datafield->getFieldType()->getId();

                    // If datafield is a multiple select/radio field, ensure secondary delimiters exist
                    $typename = $datafield->getFieldType()->getTypeName();
                    if ($typename == "Multiple Select" || $typename == "Multiple Radio" || $typename == "File" || $typename == "Image") {
                        if ( $column_delimiters == null )
                            throw new \Exception('Invalid Form b...no column_delimiters');
                        if ( !isset($column_delimiters[$col_num]) )
                            throw new \Exception('Invalid Form b...$column_delimiters['.$col_num.'] not set');

                        // Keep track of file/image columns...
                        if ($typename == "File" || $typename == "Image")
                            $file_columns[] = $col_num;
                    }
                }
            }

//return;

            // ----------------------------------------
            // Convert any secondary delimiters from words to a single character
            foreach ($column_delimiters as $df_id => $delimiter) {
                switch ($delimiter) {
/*
                    case 'tab':
                        $column_delimiters[$df_id] = "\t";
                        break;
                    case 'space':
                        $column_delimiters[$df_id] = " ";
                        break;
                    case 'comma':
                        $column_delimiters[$df_id] = ",";
                        break;
*/
                    case 'semicolon':
                        $column_delimiters[$df_id] = ";";
                        break;
                    case 'colon':
                        $column_delimiters[$df_id] = ":";
                        break;
                    case 'pipe':
                        $column_delimiters[$df_id] = "|";
                        break;
                    default:
                        throw new \Exception('Invalid Form');
                        break;
                }
            }

            // Prevent fieldtypes that can't be unique from being checked for uniqueness
            foreach ($unique_columns as $column_id => $tmp) {
                if ( isset($datafield_mapping[$column_id]) ) {
                    $df_id = $datafield_mapping[$column_id];
                    if ($df_id == 'new') {
                        // Ensure the fieldtype for the new datafield can be unique
                        $ft_id = $fieldtype_mapping[$column_id];
                        $fieldtype = $repo_fieldtype->find($ft_id);
                        if ($fieldtype->getCanBeUnique() != '1')
                            unset( $unique_columns[$column_id] );
                    }
                    else {
                        // Ensure this datafield can be unique
                        $datafield = $repo_datafield->find($df_id);
                        if ($datafield->getFieldType()->getCanBeUnique() != '1')
                            unset( $unique_columns[$column_id] );
                    }
                }
                else {
                    // Silently ignore it 
                    unset( $unique_columns[$column_id] );
                }
            }


            // ----------------------------------------
            // Attempt to load csv file
            if ( !$session->has('csv_file') )
                throw new \Exception('No CSV file uploaded');

            $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user->getId().'/';
            $csv_filename = $session->get('csv_file');
            $delimiter = $session->get('csv_delimiter');

            // Apparently SplFileObject doesn't do this before opening the file...
            ini_set('auto_detect_line_endings', TRUE);

            $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
            $reader = new CsvReader($csv_file, $delimiter);

            // Grab headers from csv file
            $reader->setHeaderRowNumber(0);
            $file_headers = $reader->getColumnHeaders();

//return;

            // ----------------------------------------
            // Compile all the data required for this csv import to store in the tracked job entity 
            $additional_data = array(
                'description' => 'Validating csv import data for DataType '.$datatype_id.'...',

                'csv_filename' => $csv_filename,
                'delimiter' => $delimiter,
                'unique_columns' => $unique_columns,
                'datafield_mapping' => $datafield_mapping,
                'fieldtype_mapping' => $fieldtype_mapping,
                'column_delimiters' => $column_delimiters,
            );

            // Get/create an entity to track the progress of this csv import
            $job_type = 'csv_import_validate';
            $target_entity = 'datatype_'.$datatype->getId();
            $restrictions = '';
            $total = $reader->count();
            $reuse_existing = false;
//$reuse_existing = true;

            $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
            $tracked_job_id = $tracked_job->getId();


            // ----------------------------------------
            // Reload the CsvReader object
            unset($reader);
            $reader = new CsvReader($csv_file, $delimiter);
            // $reader->setHeaderRowNumber(0);  // Don't want an array with the structure 'column_name' => 'column_value'...want 'column_num' => 'column_value' instead

            // All Datafields marked as unique need to have no duplicates in the import file...
            // ...unique datafields which aren't serving as the external id/name datafields for a datatype also need to ensure they're not colliding with values currently in the database
            // External id/name datafields are excluded from this second criteria because those are used as keys to update existing Datarecords

            // If a column is mapped to a unique datafield, go through and ensure that there are no duplicate values in that column
            $need_flush = false;
            $error_messages = array();
            foreach ($unique_columns as $column_num => $tmp) {

                $line_num = 0;
                $unique_values = array();

                foreach ($reader as $row) {
                    $line_num++;
                    $value = $row[$column_num];
                    if ( isset($unique_values[$value]) ) {
                        // Encountered duplicate value
                        $error = array( 'line_num' => $line_num, 'message' => 'The field "'.$file_headers[$column_num].'" is supposed to be unique, but value is a duplicate of line '.$unique_values[$value] );
//print_r($error);

                        // TODO - ...any way to make this use beanstalk?  don't really want it inline, but the checks for this error can't really be broken apart...
                        $tracked_error = new TrackedError();
                        $tracked_error->setTrackedJob($tracked_job);
                        $tracked_error->setErrorLevel('Error');
                        $tracked_error->setErrorBody( json_encode($error) );
                        $tracked_error->setCreatedBy( $user );

                        $need_flush = true;
                        $em->persist($tracked_error);
                    }
                    else {
                        // ...otherwise, not found, just store the value
                        $unique_values[$value] = $line_num;
                    }
                }
            }


            // If a column is mapped to a file/image datafield, then ensure there are no duplicate filenames
            foreach ($file_columns as $tmp => $column_num) {

                $line_num = 0;
                $unique_filenames = array();
                foreach ($reader as $row) {
                    $line_num++;
                    $value = $row[$column_num];

                    $filenames = explode( $column_delimiters[$column_num], $value );
                    foreach ($filenames as $filename) {
                        if ( isset($unique_filenames[$filename]) ) {
                            // Encountered duplicate value
                            $error = array( 'line_num' => $line_num, 'message' => 'The field "'.$file_headers[$column_num].'" wants to import the file "'.$filename.'", but that file was already listed on line '.$unique_filenames[$value] );
//print_r($error);

                            // TODO - ...any way to make this use beanstalk?  don't really want it inline, but the checks for this error can't really be broken apart...
                            $tracked_error = new TrackedError();
                            $tracked_error->setTrackedJob($tracked_job);
                            $tracked_error->setErrorLevel('Error');
                            $tracked_error->setErrorBody( json_encode($error) );
                            $tracked_error->setCreatedBy( $user );

                            $need_flush = true;
                            $em->persist($tracked_error);
                        }
                        else {
                            // ...otherwise, not found, just store the value
                            $unique_filenames[$filename] = $line_num;
                        }
                    }
                }
            }

//$need_flush = false;
            if ($need_flush)
                $em->flush();


            // ----------------------------------------
            // Create a beanstalk job for each row of the csv file
            $count = 0;
            foreach ($reader as $row) {
                // Skip first row
                $count++;
                if ($count == 1)
                    continue;

                // Queue each line for validation by a worker process...
                $payload = json_encode(
                    array(
                        'tracked_job_id' => $tracked_job_id,
                        'datatype_id' => $datatype->getId(),
                        'user_id' => $user->getId(),

                        'column_names' => $file_headers,
                        'unique_columns' => $unique_columns,
                        'datafield_mapping' => $datafield_mapping,
                        'fieldtype_mapping' => $fieldtype_mapping,
                        'column_delimiters' => $column_delimiters,
                        'line_num' => $count,
                        'line' => $row,

                        'api_key' => $beanstalk_api_key,
                        'url' => $url,
                        'memcached_prefix' => $memcached_prefix,    // debug purposes only
                    )
                );

                $pheanstalk->useTube('csv_import_validate')->put($payload);
            }

            $return['d'] = array('tracked_job_id' => $tracked_job_id);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x232815634 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called by worker processes to validate the data from each line of a CSV file
     *
     * @param Request $request
     *
     * @return TODO
     */
    public function csvvalidateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $tracked_job_id = -1;

        try {
            $post = $_POST;
//print_r($post);
//return;

            if ( !isset($post['tracked_job_id']) || !isset($post['datatype_id']) || !isset($post['user_id']) || !isset($post['column_names']) || !isset($post['datafield_mapping'])
              /*|| !isset($post['unique_columns']) || !isset($post['fieldtype_mapping'])*/ /*|| !isset($post['column_delimiters'])*/ 
                || !isset($post['line_num']) || !isset($post['line']) || !isset($post['api_key']) ) {

                throw new \Exception('Invalid job data');
            }

            // Pull data from the post
            $tracked_job_id = $post['tracked_job_id'];
            $column_names = $post['column_names'];
            $datafield_mapping = $post['datafield_mapping'];
            $line_num = $post['line_num'];
            $line = $post['line'];
            $datatype_id = $post['datatype_id'];
            $user_id = $post['user_id'];
            $api_key = $post['api_key'];

            // Have to pull these separately because they might not exist
            $unique_columns = array();
            if ( isset($post['unique_columns']) )
                $unique_columns = $post['unique_columns'];
            $fieldtype_mapping = array();
            if ( isset($post['fieldtype_mapping']) )
                $fieldtype_mapping = $post['fieldtype_mapping'];
            $column_delimiters = array();
            if ( isset($post['column_delimiters']) )
                $column_delimiters = $post['column_delimiters'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');


            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');

            $user = $repo_user->find($user_id);
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new \Exception('Datatype is deleted!');


            // ----------------------------------------
            // Locate the datarecord this row of data points to, if possible
            $datarecord_id = null;
            $external_id_field = $datatype->getExternalIdField();
            if ($external_id_field !== null) {
                $typeclass = $external_id_field->getFieldType()->getTypeClass();
                foreach ($datafield_mapping as $column_num => $datafield_id) {
                    if ($external_id_field->getId() == $datafield_id) {
                        // Attempt to locate a datarecord with this specific external id
                        $value = $line[$column_num];
                        $query = $em->createQuery(
                           'SELECT dr.id
                            FROM ODRAdminBundle:'.$typeclass.' AS e
                            JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                            WHERE e.dataField = :datafield AND e.value = :value
                            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
                        )->setParameters( array('datafield' => $datafield_id, 'value' => $value) );
                        $results = $query->getArrayResult();

                        // If one exists, save its id
                        if ( isset($results[0]) )
                            $datarecord_id = $results[0]['id'];
                    }
                }
            }


            // ----------------------------------------
            // Attempt to validate each line of data against the desired datafield/fieldtype mapping
            $need_flush = false;
            foreach ($datafield_mapping as $column_num => $datafield_id) {
                $value = trim( $line[$column_num] );
                $length = mb_strlen($value, "utf-8");   // TODO - is this the right function to use?

                // Get typeclass of what this data will be imported into
                $allow_multiple_uploads = true;
                $fieldtype = null;
                if ($datafield_id == 'new') {
                    // Datafield hasn't been created yet, grab which fieldtype it's supposed to be from the mapping
                    $fieldtype = $repo_fieldtype->find( $fieldtype_mapping[$column_num] );
                }
                else {
                    // Datafield already exists, grab fieldtype from datafield
                    $datafield = $repo_datafield->find($datafield_id);
                    $fieldtype = $datafield->getFieldType();

                    // Also store whether the datafield has been set to only allow a single file/image upload
                    if ( $datafield->getAllowMultipleUploads() == false )
                        $allow_multiple_uploads = false;
                }

                // Check for errors specifically related to this fieldtype
                $errors = array();
                $typeclass = $fieldtype->getTypeClass();
                switch ($typeclass) {
                    case "Boolean":
                        // TODO
                        break;

                    case "File":
                    case "Image":
                        if ( $value !== '' && isset($column_delimiters[$column_num]) ) {
                            // Due to validation in self::processAction(), this will exist when the datafield is a file/image
                            $upload_dir = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user_id.'/storage/';

                            // Grab a list of the files already uploaded to this datafield
                            $already_uploaded_files = array();
                            if ($datarecord_id !== null) {
                                $query_str =
                                   'SELECT e.originalFileName
                                    FROM ODRAdminBundle:'.$typeclass.' AS e
                                    WHERE e.dataRecord = :datarecord AND e.dataField = :datafield ';
                                if ($typeclass == 'Image')
                                    $query_str .= 'AND e.original = 1 ';
                                $query_str .= 'AND e.deletedAt IS NULL';

                                $query = $em->createQuery($query_str)->setParameters( array('datarecord' => $datarecord_id, 'datafield' => $datafield_id) );
                                $results = $query->getArrayResult();

                                foreach ($results as $tmp => $result)
                                    $already_uploaded_files[] = $result['originalFileName'];
                            }

                            // Grab all filenames listed in the field
                            $filenames = explode( $column_delimiters[$column_num], $value );
                            $total_file_count = count($filenames) + count($already_uploaded_files);
                            foreach ($filenames as $filename) {
                                // Determine whether the file is already uploaded to the server
                                $already_uploaded = false;
                                if ( in_array($filename, $already_uploaded_files) )
                                    $already_uploaded = true;

                                if ($already_uploaded) {
                                    // The File/Image has already been uploaded to this datafield
                                    // ...regardless of whether the upload ignores the file/image or replaces the existing file/image, there will be no net change in the number of files/images uploaded
                                    $total_file_count--;
                                }

                                if ( !file_exists($upload_dir.$filename) ) {
                                    // File/Image does not exist in the upload directory
                                    $errors[] = array(
                                        'level' => 'Error',
                                        'body' => array(
                                            'line_num' => $line_num,
                                            'message' => 'Column "'.$column_names[$column_num].'" references a '.$typeclass.' "'.$filename.'", but that '.$typeclass.' has not been uploaded to the server.',
                                        )
                                    );
                                }
                                else {
                                    // File/Image exists, ensure it has a valid mimetype
                                    $validation_params = $this->container->getParameter('file_validation');
                                    $validation_params = $validation_params[ strtolower($typeclass) ];

                                    $uploaded_file = new SymfonyFile($upload_dir.$filename);
                                    if ( !in_array($uploaded_file->getMimeType(), $validation_params['mimeTypes']) ) {
                                        $errors[] = array(
                                            'level' => 'Error',
                                            'body' => array(
                                                'line_num' => $line_num,
                                                'message' => 'The '.$typeclass.' "'.$filename.'" listed in column "'.$column_names[$column_num].'" is not a valid file for the '.$typeclass.' fieldtype.',
                                            )
                                        );
                                    }

                                    if ($already_uploaded) {
                                        // TODO - should the upload replace existing files instead of doing nothing?
                                        // Warn about file/image already being on the server
                                        $errors[] = array(
                                            'level' => 'Warning',
                                            'body' => array(
                                                'line_num' => $line_num,
                                                'message' => 'The '.$typeclass.' "'.$filename.'" listed in column "'.$column_names[$column_num].'" has already been uploaded to this datarecord.',
                                            )
                                        );
                                    }

                                }
                            }

                            // Also check to ensure that uploading a file/image won't violate the state of the "allow multiple uploads" checkbox
                            if ( !$allow_multiple_uploads && $total_file_count > 1 ) {
                                $errors[] = array(
                                    'level' => 'Error',
                                    'body' => array(
                                        'line_num' => $line_num,
                                        'message' => 'The column "'.$column_names[$column_num].'" is marked as only allowing a single '.$typeclass.' upload, but it would have '.$total_file_count.' '.$typeclass.'s uploaded after this CSV Import.',
                                    )
                                );
                            }
                        }
                        break;
                    
                    case "IntegerValue":
                        if ($value !== '') {
                            // Warn about invalid characters in an integer conversion
                            $int_value = intval( $value );
                            if ( strval($int_value) != $value ) {
                                $errors[] = array(
                                    'level' => 'Warning',
                                    'body' => array(
                                        'line_num' => $line_num,
                                        'message' => 'Column "'.$column_names[$column_num].'" has the value "'.$value.'", but will be converted to the integer value "'.strval($int_value).'"'
                                    )
                                );
                            }
                        }
                        break;
                    case "DecimalValue":
                        if ($value !== '') {
                            $float_value = floatval( $value );
                            if ( strval($float_value) != $value )  {
                                $errors[] = array(
                                    'level' => 'Warning',
                                    'body' => array(
                                        'line_num' => $line_num,
                                        'message' => 'Column "'.$column_names[$column_num].'" has the value "'.$value.'", but will be converted to the floating-point value "'.strval($float_value).'"',
                                    ),
                                );
                            }
                        }
                        break;

                    case "DatetimeValue":
                        // TODO - more strenuous date checking
                        $pattern = '/^(\d{1,4})$/'; // string consists solely of one to four digits
                        if ( preg_match($pattern, $value) == 1 ) {
                            $errors[] = array(
                                'level' => 'Error',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" has the value "'.$value.'", which is not a valid Datetime value',
                                ),
                            );
                        }
                        else {
                            try {
                                $tmp = new \DateTime($value);
                            }
                            catch (\Exception $e) {
                                $errors[] = array(
                                    'level' => 'Error',
                                    'body' => array(
                                        'line_num' => $line_num,
                                        'message' => 'Column "'.$column_names[$column_num].'" has the value "'.$value.'", which is not a valid Datetime value',
                                    ),
                                );
                            }
                        }
                        break;

                    case "ShortVarchar":
                        if ($length > 32) {
                            $errors[] = array(
                                'level' => 'Warning',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" is '.$length.' characters long, but a ShortVarchar field can only store 32 characters',
                                ),
                            );
                        }
                        break;
                    case "MediumVarchar":
                        if ($length > 64) {
                            $errors[] = array(
                                'level' => 'Warning',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" is '.$length.' characters long, but a MediumVarchar field can only store 64 characters',
                                ),
                            );
                        }
                        break;
                    case "LongVarchar":
                        if ($length > 255) {
                            $errors[] = array(
                                'level' => 'Warning',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" is '.$length.' characters long, but a LongVarchar field can only store 255 characters',
                                ),
                            );
                        }
                        break;
                    case "LongText":
                        /* do nothing? */
                        break;

                    case "Radio":
                        // Don't attempt to validate an empty string...there's just no options selected
                        if ( $value == '' )
                            break;

                        if ( isset($column_delimiters[$column_num]) ) {
                            // Due to validation in self::processAction(), this will exist when the datafield is a multiple select/radio...it won't exist if the datafield is a single select/radio

                            // Check length of each option?
                            $options = explode( $column_delimiters[$column_num], $value );
                            foreach ($options as $option) {
                                $option = trim($option);
                                $option_length = mb_strlen($option, "utf-8");
                                if ( $option_length == 0 ) {
                                    $errors[] = array(
                                        'level' => 'Warning',
                                        'body' => array(
                                            'line_num' => $line_num,
                                            'message' => 'Column "'.$column_names[$column_num].'" would create a blank radio option during import...',
                                        ),
                                    );
                                }
                                else if ( $option_length > 64 ) {
                                    $errors[] = array(
                                        'level' => 'Warning',
                                        'body' => array(
                                            'line_num' => $line_num,
                                            'message' => 'Column "'.$column_names[$column_num].'" has a Radio Option that is '.$length.' characters long, but the maximum length allowed is 64 characters',
                                        ),
                                    );
                                }
                            }
                        }
                        else {
                            // Check length of option
                            if ($length > 64) {
                                $errors[] = array(
                                    'level' => 'Warning',
                                    'body' => array(
                                        'line_num' => $line_num,
                                        'message' => 'Column "'.$column_names[$column_num].'" has a Radio Option that is '.$length.' characters long, but the maximum length allowed is 64 characters',
                                    ),
                                );
                            }
                        }
                        break;
                }

                // TODO - this is insufficient for the reasons stated in github issue #132...probably needs to be redone entirely
                // Check for duplicate values in unique columns if they're mapping to a pre-existing datafield
                if ( isset($unique_columns[$column_num]) && $datafield_id !== 'new' ) {
                    // Skip if this column is mapped to the external id/name datafield for this datatype
                    $external_id_field = $datatype->getExternalIdField();
//                    $name_field = $datatype->getNameField();

                    if ( ($external_id_field !== null && $external_id_field->getId() == $datafield_id) /*|| ($name_field !== null && $name_field->getId() == $datafield_id)*/ ) {
                        /* don't check whether the value collides with an existing value for either of these datafields...if it did, the importer would be unable to update existing datarecords */
                    }
                    else {
                        // Run a quick query to check whether the new value from the import is a duplicate of an existing value 
                        $query = $em->createQuery(
                           'SELECT dr.id AS dr_id
                            FROM ODRAdminBundle:'.$typeclass.' AS e
                            JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                            WHERE e.dataField = :datafield AND e.value = :value
                            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
                        )->setParameters( array('datafield' => $datafield_id, 'value' => $value) );
                        $results = $query->getArrayResult();

                        if ( count($results) > 0 ) {
                            $dr_id = $results[0]['dr_id'];  // TODO - notify of multiple datarecords sharing blank values?
                            $errors[] = array(
                                'level' => 'Warning',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" is mapped to a unique datafield, but its value "'.$value.'" already exists in Datarecord '.$dr_id, 
                                ),
                            );
                        }
                    }
                }

                // Save any errors found
                foreach ($errors as $error) {
//print_r($error);
                    $tracked_error = new TrackedError();
                    $tracked_error->setErrorLevel( $error['level'] );
                    $tracked_error->setErrorBody( json_encode($error['body']) );
                    $tracked_error->setTrackedJob( $repo_tracked_job->find($tracked_job_id) );
                    $tracked_error->setCreatedBy( $user );

                    $em->persist($tracked_error);
                    $need_flush = true;
                }
            }

//$need_flush = false;
            if ($need_flush)
                $em->flush();

            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                $tracked_job = $repo_tracked_job->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total)
                    $tracked_job->setCompleted( new \DateTime() );

                $em->persist($tracked_job);
                $em->flush();
            }

        }
        catch (\Exception $e) {

            // Update the job tracker even if an error occurred...right? TODO
            if ($tracked_job_id !== -1) {
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total)
                    $tracked_job->setCompleted( new \DateTime() );

                $em->persist($tracked_job);
                $em->flush();
            }

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x223285634 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders a page displaying results of csv validation
     *
     * @param integer $tracked_job_id
     * @param Request $request
     *
     * @return a Symfony JSON response containing HTML TODO
     */
    public function validateresultsAction($tracked_job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ------------------------------
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_tracked_error = $em->getRepository('ODRAdminBundle:TrackedError');
            $templating = $this->get('templating');


            // ------------------------------
            $tracked_job = $repo_tracked_job->find($tracked_job_id);
            if ($tracked_job == null)
                return parent::deletedEntityError('TrackedJob');
            if ( $tracked_job->getJobType() !== "csv_import_validate" )
                return parent::deletedEntityError('TrackedJob');

            $presets = json_decode( $tracked_job->getAdditionalData(), true );
            $target_entity = $tracked_job->getTargetEntity();
            $tmp = explode('_', $target_entity);
            $datatype_id = $tmp[1];

            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");

            // TODO - permissions check may need to be more involved than just checking whether the user accessing this can edit the datatype...
            // --------------------


            // --------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // --------------------
            // Grab all datafields belonging to that datatype
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                WHERE df.dataType = :datatype AND df.deletedAt IS NULL
                ORDER BY df.fieldName'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $datafields = $query->getResult();
//print_r($results);
//exit();

            // --------------------
            // Grab the FieldTypes that the csv importer can read data into
            // TODO - naming fieldtypes by number
            $fieldtype_array = array(
                1, // boolean
                2, // file
                3, // image
                4, // integer
                5, // paragraph text
                6, // long varchar
                7, // medium varchar
                8, // single radio
                9, // short varchar
                11, // datetime
                13, // multiple radio
                14, // single select
                15, // multiple select
                16, // decimal
//                17, // markdown
            );
            $fieldtypes = $repo_fieldtype->findBy( array('id' => $fieldtype_array) );

            // ----------------------------------------
            // Convert any secondary delimiters into word format
            foreach ($presets['column_delimiters'] as $df_id => $delimiter) {
                switch ($delimiter) {
/*
                    case "\t":
                        $presets['column_delimiters'][$df_id] = "tab";
                        break;
                    case ' ':
                        $presets['column_delimiters'][$df_id] = "space";
                        break;
                    case ',':
                        $presets['column_delimiters'][$df_id] = "comma";
                        break;
*/
                    case ';':
                        $presets['column_delimiters'][$df_id] = "semicolon";
                        break;
                    case ':':
                        $presets['column_delimiters'][$df_id] = "colon";
                        break;
                    case '|':
                        $presets['column_delimiters'][$df_id] = "pipe";
                        break;
                    default:
                        throw new \Exception('Invalid Form');
                        break;
                }
            }


            // --------------------
            // Read column names from the file
            $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user->getId().'/';
            $csv_filename = $presets['csv_filename'];
            $delimiter = $presets['delimiter'];

            // Apparently SplFileObject doesn't do this before opening the file...
            ini_set('auto_detect_line_endings', TRUE);

            $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
            $reader = new CsvReader($csv_file, $delimiter);
            $reader->setHeaderRowNumber(0);     // want associative array
            $file_headers = $reader->getColumnHeaders();


            // ------------------------------
            // Get any errors reported for this job
            $error_messages = parent::ODR_getTrackedErrorArray($em, $tracked_job_id);

            // If some sort of serious error encountered during validation, prevent importing
            $allow_import = true;
            foreach ($error_messages as $message) {
                if ( $message['error_level'] == 'Error' )
                    $allow_import = false;
            }

//print_r($error_messages);
//print_r($presets);
//return;

            // Render the page...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:CSVImport:import.html.twig',
                    array(
                        'datatype' => $datatype,
                        'upload_type' => '',

                        'presets' => $presets,
                        'errors' => $error_messages,

                        // These get passed to layout.html.twig
                        'columns' => $file_headers,
                        'datatype' => $datatype,
                        'datafields' => $datafields,
                        'fieldtypes' => $fieldtypes,
                        'allowed_fieldtypes' => $fieldtype_array,

                        'tracked_job_id' => $tracked_job_id,
                        'allow_import' => $allow_import,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x469855647 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Given the id of a completed csv_import_validate job, begins the process of a csv import by creating a beanstalk job to import each line in the csv file.
     *
     * @param integer $job_id
     * @param Request $request
     *
     * @return an empty Symfony JSON response, unless an error occurred.
     */
    public function startworkerAction($job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ------------------------------
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            // ------------------------------
            // Load the data from the finished validation job
            $tracked_job = $repo_tracked_job->find($job_id);
            if ($tracked_job->getCompleted() == null)
                throw new \Exception('Invalid job');

            $job_data = json_decode( $tracked_job->getAdditionalData(), true );
            $target_entity = $tracked_job->getTargetEntity();
            $tmp = explode('_', $target_entity);
            $datatype_id = $tmp[1];

            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");

            // TODO - permissions check may need to be more involved than just checking whether the user accessing this can edit the datatype...
            // --------------------


            // ----------------------------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // ----------------------------------------
            // Read column names from the file
            $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user->getId().'/';
            $csv_filename = $job_data['csv_filename'];
            $delimiter = $job_data['delimiter'];

            // Apparently SplFileObject doesn't do this before opening the file...
            ini_set('auto_detect_line_endings', TRUE);

            $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
            $reader = new CsvReader($csv_file, $delimiter);
            $reader->setHeaderRowNumber(0);     // want associative array
            $column_names = $reader->getColumnHeaders();

//print_r($column_names);
//return;


            // ----------------------------------------
            // NOTE - Create the tracked job here to prevent a second upload from being scheduled while the first is creating datafields...hopefully...
            // Get/create an entity to track the progress of this csv import
            $job_type = 'csv_import';
            $target_entity = 'datatype_'.$datatype->getId();
            $additional_data = array('description' => 'Importing data into DataType '.$datatype_id.'...');
            $restrictions = '';
            $total = $reader->count();
            $reuse_existing = false;
//$reuse_existing = true;

            $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
            $tracked_job_id = $tracked_job->getId();

            // Not going to need the TrackedError entries for this job anymore, get rid of them
            parent::ODR_deleteTrackedErrorsByJob($em, $job_id);


            // ----------------------------------------
            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $session = $request->getSession();

            $router = $this->get('router');
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_csv_import_worker');

            // Extract data from the tracked job
            $unique_columns = $job_data['unique_columns'];
            $datafield_mapping = $job_data['datafield_mapping'];
            $fieldtype_mapping = $job_data['fieldtype_mapping'];
            $column_delimiters = $job_data['column_delimiters'];

//print_r($job_data);
//return;

            // ----------------------------------------
            // Create any necessary datafields
            $new_datafields = array();
            $new_mapping = array();
            $created = false;
            $render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);    // default render plugin
            foreach ($datafield_mapping as $column_id => $datafield_id) {
                $datafield = null;

                if ( is_numeric($datafield_id) ) {
                    // Load datafield from repository
                    $datafield = $repo_datafield->find($datafield_id);
                    if ($datafield == null)
                        throw new \Exception('Invalid Form');

//print 'loaded existing datafield '.$datafield_id."\n";
                    $logger->notice('Using existing datafield '.$datafield->getId().' "'.$datafield->getFieldName().'" for csv import of datatype '.$datatype->getId().' by '.$user->getId());
                }
                else {  // $datafield_id == 'new'
                    // Grab desired fieldtype from post
                    if ( $fieldtype_mapping == null )
                        throw new \Exception('Invalid Form');

                    $fieldtype = $repo_fieldtype->find( $fieldtype_mapping[$column_id] );
                    if ($fieldtype == null)
                        throw new \Exception('Invalid Form');

                    // Create new datafield
                    $datafield = parent::ODR_addDataFieldsEntry($em, $user, $datatype, $fieldtype, $render_plugin);
                    $created = true;

                    // Set the datafield's name, then persist/reload it
                    $datafield->setFieldName( $column_names[$column_id] );
                    if ( isset($unique_columns[$column_id]) )
                        $datafield->setIsUnique(1);

                    $em->persist($datafield);
                    $em->flush($datafield);     // required, or can't get id
                    $em->refresh($datafield);

                    $new_datafields[] = $datafield;

                    $logger->notice('Created new datafield '.$datafield->getId().' "'.$datafield->getFieldName().'" for csv import of datatype '.$datatype->getId().' by '.$user->getId());
//print 'created new datafield of fieldtype "'.$fieldtype->getTypeName().'" with name "'.$column_names[$column_id].'"'."\n";
                }

                // Store ID of target datafield
                $new_mapping[$column_id] = $datafield->getId();
            }

            if ($created) {
                // Since datafields were created for this import, create a new theme element and attach the new datafields to it
                $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);
                $theme_element = parent::ODR_addThemeElementEntry($em, $user, $datatype, $theme);
                $em->flush($theme_element);
                $em->refresh($theme_element);

                foreach ($new_datafields as $new_datafield) {
                    // Tie each new datafield to the new theme element
                    $theme_element_field = parent::ODR_addThemeElementFieldEntry($em, $user, null, $new_datafield, $theme_element);
                }

                // Save all theme element changes
                $em->flush();
            }

/*
print 'datafield mapping: ';
print_r($new_mapping);
*/
//return;


            // ----------------------------------------
            // Re-read the csv file so a beanstalk job can be created for each line in the file
            $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user->getId().'/';
            $csv_filename = $job_data['csv_filename'];
            $delimiter = $job_data['delimiter'];

            // Apparently SplFileObject doesn't do this before opening the file...
            ini_set('auto_detect_line_endings', TRUE);

            $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
            $reader = new CsvReader($csv_file, $delimiter);
//            $reader->setHeaderRowNumber(0);   // don't want associative array

            // Grab headers from csv file incase a new datafield is created
            $headers = array();
            foreach ($reader as $row) {
                $headers = $row;
                break;
            }


            // ----------------------------------------
            // Create a beanstalk job for each row of the csv file
            $count = 0;
            foreach ($reader as $row) {
                // Skip first row
                $count++;
                if ($count == 1)
                    continue;

                // Queue each line for import by a worker process...
                $payload = json_encode(
                    array(
                        'tracked_job_id' => $tracked_job_id,
                        'user_id' => $user->getId(),
                        'datatype_id' => $datatype->getId(),

                        'api_key' => $beanstalk_api_key,
                        'url' => $url,
                        'memcached_prefix' => $memcached_prefix,    // debug purposes only

//                        'external_id_column' => $external_id_column,
                        'column_delimiters' => $column_delimiters,
                        'mapping' => $new_mapping,
                        'line' => $row,
                    )
                );

                // Randomize priority somewhat
                $priority = 1024;
                $num = rand(0, 400) - 200;
                $priority += $num;

                $delay = 5;
                $pheanstalk->useTube('csv_import_worker')->put($payload, $priority, $delay);
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x232815622 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called by worker processes to do the actual work of importing each line of a CSV file
     * TODO - remove/modify how $status being used as a return variable
     *
     * @param Request $request
     *
     * @return TODO
     */
    public function csvworkerAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $tracked_job_id = -1;

        try {
            $post = $_POST;
//print_r($post);
//return;

            if ( !isset($post['tracked_job_id']) || !isset($post['mapping']) || !isset($post['line']) || !isset($post['datatype_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid job data');

            // Pull data from the post
            $tracked_job_id = $post['tracked_job_id'];
            $user_id = $post['user_id'];
            $datatype_id = $post['datatype_id'];
            $mapping = $post['mapping'];
            $line = $post['line'];
            $api_key = $post['api_key'];

            $column_delimiters = null;
            if ( isset($post['column_delimiters']) )
                $column_delimiters = $post['column_delimiters'];


            // ----------------------------------------
            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');


            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');

            $user = $repo_user->find($user_id);
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new \Exception('Datatype is deleted!');


            // ----------------------------------------
            // Attempt to locate an existing datarecord
            $status = '';
            $datarecord = null;
            $external_id_field = $datatype->getExternalIdField();
//            $name_field = $datatype->getNameField();

            $source = '';
            $value = '';
            $typeclass = '';
            $datafield_id = '';
/*
            // Try to first locate the name field value...
            if ($name_field !== null) {
                $source = 'Name datafield';
                $datafield_id = $name_field->getId();
                $typeclass = $name_field->getFieldType()->getTypeClass();
                foreach ($mapping as $column_num => $df_id) {
                    if ($df_id == $datafield_id)
                        $value = $line[$column_num];
                }
            }
*/
            // Try to locate the external id field value...purposefully overwrite the value from the name field because...
            if ($external_id_field !== null) {
                $source = 'External ID datafield';
                $datafield_id = $external_id_field->getId();
                $typeclass = $external_id_field->getFieldType()->getTypeClass();
                foreach ($mapping as $column_num => $df_id) {
                    if ($df_id == $datafield_id)
                        $value = $line[$column_num];
                }
            }

            if ($value !== '' && $datafield_id !== '') {
                // Locate the datarecord pointed to by the external id, if possible
                $query = $em->createQuery(
                   'SELECT dr
                    FROM ODRAdminBundle:'.$typeclass.' AS e
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                    JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                    WHERE e.dataField = :datafield AND e.value = :value
                    AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
                )->setParameters( array('datafield' => $datafield_id, 'value' => $value) );
                $results = $query->getResult();

                if ( isset($results[0]) )
                    $datarecord = $results[0];
//print $datarecord->getId()."\n";
            }

//return;


            // ----------------------------------------
            if ($datarecord == null) {
                // Create a new datarecord, since one doesn't exist
                $datarecord = parent::ODR_addDataRecord($em, $user, $datatype);
                $datarecord->setParent($datarecord);
                $datarecord->setGrandparent($datarecord);

                $em->persist($datarecord);
                $status = "\n".'Created new datarecord for csv import of datatype '.$datatype_id.'...'."\n";
                $logger->notice('Created datarecord '.$datarecord->getId().' for csv import of datatype '.$datatype_id.' by '.$user->getId());
            }
            else {
                // Mark datarecord as updated
                $datarecord->setUpdated( new \DateTime() );
                $datarecord->setUpdatedBy($user);
                $em->persist($datarecord);

                $status = "\n".'Found existing datarecord ('.$datarecord->getId().') for csv import of datatype '.$datatype_id.'...'."\n";
                $logger->notice('Using existing datarecord ('.$datarecord->getId().') pointed to by '.$source.' "'.$value.'" for csv import of datatype '.$datatype_id.' by '.$user->getId());
            }
            $em->flush($datarecord);
            $em->refresh($datarecord);


            // All datarecordfield and storage entities should exist now...

            // ----------------------------------------
            // Break apart the line into constituent columns...
            foreach ($line as $column_num => $column_data) {
                // Only care about this column if it's mapped to a datafield...
                if ( isset($mapping[$column_num]) ) {
                    // ...grab which datafield is getting mapped to
                    $datafield_id = $mapping[$column_num];
                    $datafield = $repo_datafield->find($datafield_id);

                    $typename = $datafield->getFieldType()->getTypeName();
                    $typeclass = $datafield->getFieldType()->getTypeClass();
                    $classname = "ODR\\AdminBundle\\Entity\\".$typeclass;

                    $column_data = trim($column_data);

                    if ($typeclass == 'Boolean') {
                        // Grab repository for entity
                        $repo_entity = $em->getRepository($classname);
                        $entity = $repo_entity->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()) );
                        if ($entity == null) {
                            $entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                            $em->persist($entity);
                            $em->flush($entity);
                            $em->refresh($entity);
                            $status .= '    -- >> created new '.$typeclass."\n";
                        }

                        // Save value from csv file
                        $checked = '';
                        if ( $column_data !== '' ) {
                            $checked = 'checked';
                            $entity->setValue(1);   // any character in the field counts as checked
                        }
                        else {
                            $checked = 'unchecked';
                            $entity->setValue(0);
                        }

                        $em->persist($entity);

                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.' '/*.$entity->getId()*/.') to "'.$checked.'"...'."\n";
                    }
                    else if ($typeclass == 'File' || $typeclass == 'Image') {
                        // If a filename is in this column...
                        if ($column_data !== '') {
                            // Grab the associated datarecordfield entity
                            $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()) );

                            // Store the path to the user's upload area...
                            $filepath = dirname(__FILE__).'/../../../../web/uploads/csv/user_'.$user->getId().'/storage';

                            // Grab a list of the files already uploaded to this datafield
                            $already_uploaded_files = array();
                            $query_str =
                               'SELECT e.originalFileName
                                FROM ODRAdminBundle:'.$typeclass.' AS e
                                WHERE e.dataRecord = :datarecord AND e.dataField = :datafield ';
                            if ($typeclass == 'Image')
                                $query_str .= 'AND e.original = 1 ';
                            $query_str .= 'AND e.deletedAt IS NULL';

                            $query = $em->createQuery($query_str)->setParameters( array('datarecord' => $datarecord->getId(), 'datafield' => $datafield->getId()) );
                            $results = $query->getArrayResult();

                            foreach ($results as $tmp => $result)
                                $already_uploaded_files[] = $result['originalFileName'];

                            $status .= '    -- datafield '.$datafield->getId().' ('.$typeclass.') '."\n";

                            $filenames = explode( $column_delimiters[$column_num], $column_data );
                            foreach ($filenames as $filename) {
                                // TODO - ability to replace existing files?
                                // If the file doesn't already exist in the datafield, upload it
                                if ( !in_array($filename, $already_uploaded_files) ) {
                                    parent::finishUpload($em, $filepath, $column_data, $user->getId(), $drf->getId());

                                    $status .= '    ...uploaded new '.$typeclass.' ("'.$filename.'")'."\n";
                                }
                            }
                        }
                    }
                    else if ($typeclass == 'IntegerValue') {
                        // Grab repository for entity
                        $repo_entity = $em->getRepository($classname);
                        $entity = $repo_entity->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()) );
                        if ($entity == null) {
                            $entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                            $em->persist($entity);
                            $em->flush($entity);
                            $em->refresh($entity);
                            $status .= '    -- >> created new '.$typeclass."\n";
                        }

                        // Save value from csv file
                        if ($column_data !== '')
                            $entity->setValue( intval($column_data) );
                        else
                            $entity->setValue( null );
                        $em->persist($entity);

                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.' '/*.$entity->getId()*/.') to "'.$column_data.'"...'."\n";
                    }
                    else if ($typeclass == 'DecimalValue') {
                        // Grab repository for entity
                        $repo_entity = $em->getRepository($classname);
                        $entity = $repo_entity->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()) );
                        if ($entity == null) {
                            $entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                            $em->persist($entity);
                            $em->flush($entity);
                            $em->refresh($entity);
                            $status .= '    -- >> created new '.$typeclass."\n";
                        }

                        // Save value from csv file
                        if ($column_data !== '')
                            $entity->setValue( floatval($column_data) );
                        else
                            $entity->setValue( null );
                        $em->persist($entity);

                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.' '/*.$entity->getId()*/.') to "'.$column_data.'"...'."\n";
                    }
                    else if ($typeclass == 'LongText' || $typeclass == 'LongVarchar' || $typeclass == 'MediumVarchar' || $typeclass == 'ShortVarchar') {
                        // Grab repository for entity
                        $repo_entity = $em->getRepository($classname);
                        $entity = $repo_entity->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()) );
                        if ($entity == null) {
                            $entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                            $em->persist($entity);
                            $em->flush($entity);
                            $em->refresh($entity);
                            $status .= '    -- >> created new '.$typeclass."\n";
                        }

                        // Save value from csv file
                        $entity->setValue($column_data);
                        $em->persist($entity);

                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.' '/*.$entity->getId()*/.') to "'.$column_data.'"...'."\n";
                    }
                    else if ($typeclass == 'DatetimeValue') {
                        // Grab repository for entity
                        $repo_entity = $em->getRepository($classname);
                        $entity = $repo_entity->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()) );
                        if ($entity == null) {
                            $entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                            $em->persist($entity);
                            $em->flush($entity);
                            $em->refresh($entity);
                            $status .= '    -- >> created new '.$typeclass."\n";
                        }

                        // Save value from csv file...different formats are already taken care of courtesy of the bundle used for csv importing
                        if ($column_data !== '') {
                            $datetime = new \DateTime($column_data);
                            $entity->setValue($datetime);
                            $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.' '/*.$entity->getId()*/.') to "'.$datetime->format('Y-m-d H:i:s').'"...'."\n";
                        }
                        else {
                            $entity->setValue(null);
                            $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.' '/*.$entity->getId()*/.') to ""...'."\n";
                        }
                        $em->persist($entity);
                    }
                    else if ($typeclass == 'Radio') {
                        $status .= '    -- datafield '.$datafield->getId().' ('.$typeclass.' '/*.$entity->getId()*/.') ';

                        // If multiple radio/select, get an array of all the options...
                        $options = array($column_data);
                        if ($typename == "Multiple Select" || $typename == "Multiple Radio")
                            $options = explode( $column_delimiters[$column_num], $column_data );

                        foreach ($options as $num => $option_name) {
                            // Don't look for or create a blank radio option
                            $option_name = trim($option_name);
                            if ( $option_name == '' )
                                continue;

                            // See if a radio_option entity with this name already exists
                            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy( array('optionName' => $option_name, 'dataFields' => $datafield->getId()) );
                            if ($radio_option == null) {
                                // TODO - CURRENTLY WORKS, BUT MIGHT WANT TO LOOK INTO AN OFFICIAL MUTEX...

                                // define and execute a query to manually create the absolute minimum required for a RadioOption entity...
                                $query = 
                                   'INSERT INTO odr_radio_options (option_name, data_fields_id)
                                    SELECT * FROM (SELECT :name AS option_name, :df_id AS df_id) AS tmp
                                    WHERE NOT EXISTS (
                                        SELECT option_name FROM odr_radio_options WHERE option_name = :name AND data_fields_id = :df_id AND deletedAt IS NULL
                                    ) LIMIT 1;';
                                $params = array('name' => $option_name, 'df_id' => $datafield->getId());
                                $conn = $em->getConnection();
                                $rowsAffected = $conn->executeUpdate($query, $params);

                                if ($rowsAffected > 0) {
                                    $logger->notice('Created new RadioOption ("'.$option_name.'") as part of csv import for datatype '.$datatype->getId().' by '.$user->getId());
                                    $status .= '    ...created new radio_option ("'.$option_name.'")';
                                }

                                // Now that it exists, fill out the properties of a RadioOption entity that were skipped during the manual creation...
                                $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy( array('optionName' => $option_name, 'dataFields' => $datafield->getId()) );
                                $radio_option->setCreatedBy($user);
                                $radio_option->setCreated( new \DateTime() );
                                $radio_option->setUpdatedBy($user);
                                $radio_option->setUpdated( new \DateTime() );
                                $radio_option->setExternalId(0);

                                $em->persist($radio_option);
                            }

                            // Now that the radio option is guaranteed to exist...
                            $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()) );
                            $selected = 1;  // default to selected
                            $radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $drf, $selected);

                            $status .= '...selected';
                        }
                        $status .= "\n";
                    }
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
//                $em->flush();
//$ret .= '  Set current to '.$count."\n";
            }

            // Import is finished, no longer prevent other parts of the site from accessing this datarecord
            $datarecord->setProvisioned(false);
            $em->persist($datarecord);

            $em->flush();


            // ----------------------------------------
            // Rebuild the list of sorted datarecords, since the datarecord order may have changed
            $memcached->delete($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');
            // Schedule the datarecord for an update
            $options = array();
            parent::updateDatarecordCache($datarecord->getId(), $options);


            $return['d'] = $status;
        }
        catch (\Exception $e) {
            // TODO - ???
            $status = str_replace('</br>', "\n", $status);
            print $status;


            // Update the job tracker even if an error occurred...right? TODO
            if ($tracked_job_id !== -1) {
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total)
                    $tracked_job->setCompleted( new \DateTime() );

                $em->persist($tracked_job);
                $em->flush();
            }

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x232383515 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
