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
use ODR\AdminBundle\Entity\Boolean as ODRBoolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\TrackedError;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
// CSV Reader
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
     * @return Response
     */
    public function importAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
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
            // Always bypass cache in dev mode
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;

            // Locate any child or linked datatypes
            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);
            $childtypes = array();
            foreach ($datatree_array['descendant_of'] as $dt_id => $parent_dt_id) {
                if ($parent_dt_id == $datatype_id) {
                    // Ensure user has permissions to modify this childtype before storing it
                    if ( isset($user_permissions[ $dt_id ]) && $user_permissions[ $dt_id ]['edit'] == 1 ) {

                        // Only store the childtype if it doesn't have children of its own...
                        if ( !in_array($dt_id, $datatree_array['descendant_of']) )
                            $childtypes[] = $repo_datatype->find($dt_id);
                    }
                }
            }
            if ( count($childtypes) == 0 )
                $childtypes = null;

            $linked_types = array();
            foreach ($datatree_array['linked_from'] as $descendant_dt_id => $ancestor_ids) {
                if ( in_array($datatype_id, $ancestor_ids) ) {
                    // Ensure user has permissions to modify this linked type before storing it
                    if ( isset($user_permissions[ $descendant_dt_id ]) && $user_permissions[ $descendant_dt_id ]['edit'] == 1 ) {
                        $linked_types[] = $repo_datatype->find($descendant_dt_id);
                    }
                }
            }
            if ( count($linked_types) == 0 )
                $linked_types = null;

            // Reset the user's csv import delimiter
            $session = $request->getSession();
            $session->set('csv_delimiter', '');


            // ----------------------------------------
            // Render the basic csv import page
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:CSVImport:import.html.twig',
                    array(
                        'datatype' => $datatype,
                        'childtypes' => $childtypes,
                        'linked_types' => $linked_types,
                        'upload_type' => 'csv',

                        'parent_datatype' => $datatype, // user hasn't selected which datatype to import into yet

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
     * @return Response
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
     *
     * @param integer $source_datatype_id  The top-level datatype that user started this CSV import from
     * @param integer $target_datatype_id  Which datatype the CSV data is being imported into.
     * @param Request $request
     *
     * @return Response
     */
    public function layoutAction($source_datatype_id, $target_datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $session = $request->getSession();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            /** @var DataType $source_datatype */
            $source_datatype = $repo_datatype->find($source_datatype_id);
            if ( $source_datatype == null )
                return parent::deletedEntityError('DataType');
            /** @var DataType $target_datatype */
            $target_datatype = $repo_datatype->find($target_datatype_id);
            if ( $target_datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $target_datatype->getId() ]) && isset($user_permissions[ $target_datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$target_datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$target_datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$target_datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // ----------------------------------------
            // Always bypass cache in dev mode
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;

            // $datatype_id is the datatype being imported into...determine whether it's the remote side of a datatype link, or a top-level datatype, or locate its parent datatype if it's a child datatype
            $linked_importing = false;
            $parent_datatype = null;


            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);
            if ( $source_datatype_id !== $target_datatype_id && isset($datatree_array['linked_from'][$target_datatype_id]) && in_array($source_datatype_id, $datatree_array['linked_from'][$target_datatype_id]) ) {
                /* "Importing into" a linked datatype */
                $linked_importing = true;
                $parent_datatype = $repo_datatype->find($datatree_array['linked_from'][$target_datatype_id]);

                if ($parent_datatype == null)
                    throw new \Exception('Invalid Target Datatype');

                // User should not have had the option to link to a datatype that lacks an external ID field...
                if ($target_datatype->getExternalIdField() == null)
                    throw new \Exception('Invalid Target Datatype');
            }
            else if ( !isset($datatree_array['descendant_of'][$target_datatype_id]) || $datatree_array['descendant_of'][$target_datatype_id] == '' ) {
                /* Importing into top-level datatype, do nothing */
            }
            else {
                /* Importing into a child datatype */
                $parent_datatype_id = $datatree_array['descendant_of'][$target_datatype_id];
                if ( isset($datatree_array['descendant_of'][$parent_datatype_id]) && $datatree_array['descendant_of'][$parent_datatype_id] == '' ) {
                    // Importing into a childtype...going to need the parent datatype to help determine where data should go
                    $parent_datatype = $repo_datatype->find($parent_datatype_id);

                    // User shouldn't have had the option to select a child datatype if the parent had no external ID field...
                    if ($parent_datatype->getExternalIdField() == null)
                        throw new \Exception('Invalid Target Datatype');
                }
                else {
                    // User should not have had the option to select a child of a child datatype...
                    throw new \Exception('Invalid Target Datatype');
                }
            }


            // ----------------------------------------
            // Grab all datafields belonging to that datatype
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                WHERE df.dataType = :datatype
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL
                ORDER BY dfm.fieldName'
            )->setParameters( array('datatype' => $target_datatype->getId()) );
            /** @var DataFields[] $datafields */
            $datafields = $query->getResult();
//print_r($results);
//exit();

            // Grab the FieldTypes that the csv importer can read data into
            /** @var FieldType[] $fieldtypes */
            $fieldtypes = $repo_fieldtype->findAll();
            $allowed_fieldtypes = array();

            foreach ($fieldtypes as $num => $fieldtype) {
                // Every field can be imported into except for the Markdown field
                if ($fieldtype->getTypeName() !== 'Markdown') {
                    $allowed_fieldtypes[ $fieldtype->getId() ] = $fieldtype->getTypeName();
                }
                else {
                    unset( $fieldtypes[$num] );
                }
            }


            // ----------------------------------------
            // Attempt to load the previously uploaded csv file
            if ( !$session->has('csv_file') )
                throw new \Exception('No CSV file uploaded');

            // Remove any completely blank columns and rows from the csv file
            self::trimCSVFile($user->getId(), $request);

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

//print_r($encoding_errors);
//exit();

            // ----------------------------------------
            // Grab column names from first row
            $error_messages = array();
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
                        $str = ' the columns "'.implode('", "', $errors).'"';

                    $error_messages[] = array( 'error_level' => 'Error', 'error_body' => array('line_num' => $line_num+1, 'message' => 'Invalid UTF-8 character in'.$str) );
                }

                // Warn about wrong number of columns
                foreach ($reader->getErrors() as $line_num => $errors) {
                    $error_messages[] = array( 'error_level' => 'Error', 'error_body' => array('line_num' => $line_num+1, 'message' => 'Found '.count($errors).' columns on this line, expected '.count($file_headers)) );
                }
            }

//print_r($error_messages);

            // ----------------------------------------
            // Render the page
            $templating = $this->get('templating');
            if ( count($error_messages) == 0 ) {
                // If no errors, render the column/datafield/fieldtype selection page
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:CSVImport:layout.html.twig',
                        array(
                            'parent_datatype' => $parent_datatype,  // as expected if importing into a child datatype, or null if importing into top-level datatype, or equivalent to the local datatype if importing links
                            'datatype' => $target_datatype,         // as expected if importing into a top-level or child datatype, or equivalent to the remote datatype if importing links
                            'linked_importing' => $linked_importing,

                            'columns' => $file_headers,
                            'datafields' => $datafields,
                            'fieldtypes' => $fieldtypes,
                            'allowed_fieldtypes' => $allowed_fieldtypes,

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
     * Because Excel apparently can't always manage to keep itself from exporting completely blank 
     * rows or columns in the csv files it creates, there needs to be a function to strip these
     * completely blank rows/columns from the csv file that the user uploads.
     *
     * @throws \Exception
     *
     * @param integer $user_id
     * @param Request $request
     *
     */
    private function trimCSVFile($user_id, Request $request)
    {
        // Attempt to load the previously uploaded csv file
        $session = $request->getSession();
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

        // Trim headers
        $headers_trimmed = false;
        foreach ($header_row as $num => $header) {
            $new_header = trim($header);
            if ( $header !== $new_header ) {
                $headers_trimmed = true;
                $header_row[$num] = $new_header;
            }
        }

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


        // Also need to determine if any of the rows in the file are completely blank...
        $blank_rows = array();

        // ----------------------------------------
        // Continue reading the rest of the file...
        $line_num = 0;
        while ( $csv_file->valid() ) {
            $row = $csv_file->fgetcsv();    // automatically increments file pointer
            if ( count($row) == 0 )
                continue;
//print_r($row);

            $line_num++;

            // If there's a mismatch in the number of columns, don't bother reading rest of file
            if ( count($row) !== count($header_row) ) {
//print 'column mismatch';
                $csv_file = null;
                return;
            }

            // Check for any values in this row/column
            $blank_row = true;
            foreach ($row as $column_id => $value) {
                if ($value !== '') {
                    // Note that this column and this row have at least one value
                    if ($column_use[$column_id] == false)
                        $column_use[$column_id] = true;
                    $blank_row = false;
                }
            }

            // If none of the columns in this row had a value, save the line number so this blank row can be removed
            if ($blank_row)
                $blank_rows[] = $line_num;
        }


        // ----------------------------------------
        // Done reading file...check to see whether it needs to be rewritten
        $rewrite_file = false;
        foreach ($column_use as $column_id => $in_use) {
            if (!$in_use) {
//print 'column '.$column_id.' not in use'."\n";
                $rewrite_file = true;
            }
        }

        if ( count($blank_rows) > 0 || $headers_trimmed || $blank_header )
            $rewrite_file = true;

        if (!$rewrite_file) {
//print "don't need to rewrite file";
            $csv_file = null;
            return;
        }


        // ----------------------------------------
        // Need to rewrite the original csv file...create a new temporary csv file
        $tokenGenerator = $this->container->get('fos_user.util.token_generator');
        $tmp_filename = substr($tokenGenerator->generateToken(), 0, 12);
        // TODO - other illegal first characters for filename?
        if ( substr($tmp_filename, 0, 1) == '-' )
            $tmp_filename = 'a'.substr($tmp_filename, 1);
        $tmp_filename .= '.csv';

        $new_csv_file = fopen( $csv_import_path.$tmp_filename, 'w' );   // apparently fputcsv() won't work with a SplFileObject

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
        $line_num = 0;
        while ( $csv_file->valid() ) {
            $row = $csv_file->fgetcsv();    // automatically advances file pointer
            if ( count($row) == 0 )
                continue;

            $line_num++;

            // Remove the completely blank columns from the original csv file so they don't get printed to the temporary csv file...
            foreach ($blank_columns as $num => $column_id)
                unset( $row[$column_id] );
//print_r($row);

            // Don't print the completely blank rows from the original csv file
            if ( !in_array($line_num, $blank_rows) )
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
     * @return Response
     */
    public function refreshfilelistAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // TODO - permissions?
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

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
     * @return Response
     */
    public function deletefilelistAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // TODO - permissions?
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

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
     * @return Response
     */
    public function cancelAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Attempt to locate the csv file stored in the user's session
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
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
     * @return Response
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

            if ( !isset($post['datatype_id']) )
                throw new \Exception('Invalid Form');
            $datatype_id = $post['datatype_id'];

            // --------------------
            // Pull data from the post
            $datafield_mapping = array();
            if ( isset($post['datafield_mapping']))
                $datafield_mapping = $post['datafield_mapping'];
            // Get datafields where uniqueness will be checked for/enforced
            $unique_columns = array();
            if ( isset($post['unique_columns']) )
                $unique_columns = $post['unique_columns'];
            // Grab fieldtype mapping for datafields this import is going to create, if the user chose to create new datafields
            $fieldtype_mapping = null;
            if ( isset($post['fieldtype_mapping']) )
                $fieldtype_mapping = $post['fieldtype_mapping'];
            // Get secondary delimiters to use for file/image/multiple select/radio columns, if they exist
            $column_delimiters = array();
            if ( isset($post['column_delimiters']) )
                $column_delimiters = $post['column_delimiters'];
            // Get the file/image columns where all files/images in the datafield but not in the csv file will be deleted
            $synch_columns = array();
            if ( isset($post['synch_columns']) )
                $synch_columns = $post['synch_columns'];

            // If the import is for a child or linked datatype, then one of the columns from the csv file has to be mapped to the parent (or local if linked import) datatype's external id datafield
            $parent_datatype_id = '';
            if ( isset($post['parent_datatype_id']) )
                $parent_datatype_id = $post['parent_datatype_id'];
            $parent_external_id_column = '';
            if ( isset($post['parent_external_id_column']) )
                $parent_external_id_column = $post['parent_external_id_column'];

            // If the import is for a linked datatype, then another column from the csv file also has to be mapped to the remote datatype's external id datafield
            $remote_external_id_column = '';
            if ( isset($post['remote_external_id_column']) )
                $remote_external_id_column = $post['remote_external_id_column'];


            // ----------------------------------------
            // Load symfony objects
            $session = $request->getSession();
            $pheanstalk = $this->get('pheanstalk');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');

            $router = $this->get('router');
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_csv_import_validate');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            // Need to store fieldtype ids and fieldtype typenames
            /** @var FieldType[] $fieldtypes */
            $fieldtypes = $repo_fieldtype->findAll();
            $allowed_fieldtypes = array();
            foreach ($fieldtypes as $fieldtype)
                $allowed_fieldtypes[ $fieldtype->getId() ] = $fieldtype->getTypeName();


            // ----------------------------------------
            // Load required datatype entities
            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new \Exception('Invalid Form');

            /** @var DataType|null $parent_datatype */
            $parent_datatype = null;
            if ($parent_datatype_id !== '') {
                $parent_datatype = $repo_datatype->find($parent_datatype_id);

                if ($parent_datatype == null)
                    throw new \Exception('Invalid Form');
            }

            // If importing into top-level dataype, $datatype is the top-level datatype and $parent_datatype is null
            // If importing into child datatype, $datatype is the child datatype and $parent_datatype is $datatype's parent
            // If importing linked datatype, $datatype is the remote datatype and $parent_datatype is the local datatype


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
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

            // Ensure that the datatype/fieldtype mappings and secondary column delimiters aren't mismatched or missing
            foreach ($datafield_mapping as $col_num => $datafield_id) {
                if ($datafield_id == 'new') {
                    // Since a new datafield will be created, ensure fieldtype exists
                    if ( $fieldtype_mapping == null )
                        throw new \Exception('Invalid Form...no fieldtype_mapping');
                    if ( !isset($fieldtype_mapping[$col_num]) )
                        throw new \Exception('Invalid Form...$fieldtype_mapping['.$col_num.'] not set');

                    // If new datafield is multiple select/radio, or new datafield is file/image, ensure secondary delimiters exist
                    $fieldtype_id = $fieldtype_mapping[$col_num];
                    $typename = $allowed_fieldtypes[$fieldtype_id];

                    if ($typename == 'Multiple Radio' || $typename == 'Multiple Select' || $typename == 'File' || $typename == 'Image') {
                        if ( $column_delimiters == null )
                            throw new \Exception('Invalid Form a...no column_delimiters');
                        if ( !isset($column_delimiters[$col_num]) )
                            throw new \Exception('Invalid Form a...$column_delimiters['.$col_num.'] not set');

                        // Keep track of file/image columns...
                        if ($typename == 'File' || $typename == 'Image')
                            $file_columns[] = $col_num;
                    }
                }
                else {
                    // Ensure datafield exists
                    /** @var DataFields $datafield */
                    $datafield = $repo_datafield->find($datafield_id);
                    if ($datafield == null)
                        throw new \Exception('Invalid Form...deleted DataField');

                    // Ensure fieldtype mapping entry exists
                    $fieldtype_mapping[$col_num] = $datafield->getFieldType()->getId();

                    // If datafield is multiple select/radio field, or datafield is file/image, ensure secondary delimiters exist
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
                        /** @var FieldType $fieldtype */
                        $fieldtype = $repo_fieldtype->find($ft_id);
                        if ($fieldtype->getCanBeUnique() != '1')
                            unset( $unique_columns[$column_id] );
                    }
                    else {
                        // Ensure this datafield can be unique
                        /** @var DataFields $datafield */
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
            $reader->setHeaderRowNumber(0);     // want associative array for the column names
            $file_headers = $reader->getColumnHeaders();

//return;

            // ----------------------------------------
            // Compile all the data required for this csv import to store in the tracked job entity 
            $additional_data = array(
                'description' => 'Validating csv import data for DataType '.$datatype_id.'...',

                'csv_filename' => $csv_filename,
                'delimiter' => $delimiter,

                // Only used when importing into a top-level or child datatype
                'unique_columns' => $unique_columns,
                'datafield_mapping' => $datafield_mapping,
                'fieldtype_mapping' => $fieldtype_mapping,
                'column_delimiters' => $column_delimiters,
                'synch_columns' => $synch_columns,

                // Only used when importing into a child or linked datatype
                'parent_external_id_column' => $parent_external_id_column,
                'parent_datatype_id' => $parent_datatype_id,

                // Only used when importing into a linked datatype
                'remote_external_id_column' => $remote_external_id_column,
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

            // 1) All datafields marked as unique need to have no duplicates in the import file
            // 2) Unique datafields which aren't serving as the external id/name datafields for a datatype also need to ensure they're not colliding with values currently in the database
            // External id/name datafields are excluded from this second criteria because those are used as keys to update existing Datarecords

            // If a column is mapped to a unique datafield, go through and ensure that there are no duplicate values in that column
            $errors = array();
            foreach ($unique_columns as $column_num => $tmp) {
                $column_errors = self::checkColumnUniqueness($file_headers, $reader, $column_num, $parent_external_id_column);
                $errors = array_merge($errors, $column_errors);
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
                            $errors[] = array(
                                'level' => 'Error',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'The field "'.$file_headers[$column_num].'" wants to import the file "'.$filename.'", but that file was already listed on line '.$unique_filenames[$value],
                                ),
                            );
                        }
                        else {
                            // ...otherwise, not found, just store the value
                            $unique_filenames[$filename] = $line_num;
                        }
                    }
                }
            }


            // ----------------------------------------
            // If a column is mapped to the parent datatype's external id field, and the link between the parent datatype and the child datatype is set to single-only...ensure that importing this csv file won't violate that
            if ($parent_external_id_column !== '') {
                $query = $em->createQuery(
                   'SELECT dtm.multiple_allowed AS multiple_allowed
                    FROM ODRAdminBundle:DataTree AS dt
                    JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                    WHERE dt.descendant = :child_datatype
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters( array('child_datatype' => $datatype_id) );
                $results = $query->getArrayResult();
//print_r($results);

                if ($results[0]['multiple_allowed'] == true) {
                    /* any number of child datarecords are allowed, no need to do anything */
                }
                else {
                    // Since only a single child/linked datarecord is allowed, and the importer will create a link or a child datarecord for each line of the csv file...
                    // ...build a list of datarecords that already have their single child/linked datarecord

                    $parent_typeclass = $parent_datatype->getExternalIdField()->getFieldType()->getTypeClass();
                    $rel_type_noun = '';
                    $rel_type_adj = '';

                    $results = array();
                    if ($remote_external_id_column == '') {
                        // Grab a list of all child datarecords grouped by parent datarecord
                        // TODO - external id for child datarecords...currently CSV Importing only can create child datarecords
                        $query = $em->createQuery(
                           'SELECT e.value AS parent_external_id, child.id AS child_external_id
                            FROM ODRAdminBundle:'.$parent_typeclass.' AS e
                            JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                            JOIN ODRAdminBundle:DataRecord AS parent WITH drf.dataRecord = parent
                            JOIN ODRAdminBundle:DataRecord AS child WITH child.parent = parent
                            WHERE drf.dataField = :parent_external_id_field AND child.dataType = :child_datatype
                            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND parent.deletedAt IS NULL AND child.deletedAt IS NULL'
                        )->setParameters( array('parent_external_id_field' => $parent_datatype->getExternalIdField()->getId(), 'child_datatype' => $datatype->getId()) );
                        $results = $query->getArrayResult();

                        $rel_type_noun = 'child';
                        $rel_type_adj = 'child';
                    }
                    else {
                        // Grab a list of all relevant linked datatrecords
                        $child_typeclass = $datatype->getExternalIdField()->getFieldType()->getTypeClass();

                        $query = $em->createQuery(
                           'SELECT e_1.value AS parent_external_id, e_2.value AS child_external_id
                            FROM ODRAdminBundle:'.$parent_typeclass.' AS e_1
                            JOIN ODRAdminBundle:DataRecordFields AS drf_1 WITH e_1.dataRecordFields = drf_1
                            JOIN ODRAdminBundle:DataRecord AS parent WITH drf_1.dataRecord = parent
                            JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH parent = ldt.ancestor
                            JOIN ODRAdminBundle:DataRecord AS child WITH ldt.descendant = child
                            JOIN ODRAdminBundle:DataRecordFields AS drf_2 WITH drf_2.dataRecord = child
                            JOIN ODRAdminBundle:'.$child_typeclass.' AS e_2 WITH e_2.dataRecordFields = drf_2
                            WHERE drf_1.dataField = :local_external_id_field AND drf_2.dataField = :remote_external_id_field
                            AND e_1.deletedAt IS NULL AND drf_1.deletedAt IS NULL AND parent.deletedAt IS NULL AND ldt.deletedAt IS NULL AND child.deletedAt IS NULL AND drf_2.deletedAt IS NULL AND e_2.deletedAt IS NULL'
                        )->setParameters( array('local_external_id_field' => $parent_datatype->getExternalIdField()->getId(), 'remote_external_id_field' => $datatype->getExternalIdField()->getId()) );
                        $results = $query->getArrayResult();

                        $rel_type_noun = 'link';
                        $rel_type_adj = 'linked';
                    }
//print_r($results);

                    // Convert the DQL result into a more managable array
                    $datatree = array();
                    foreach ($results as $num => $result) {
                        $parent_id = $result['parent_external_id'];
                        $child_id = $result['child_external_id'];

                        $datatree[$parent_id] = $child_id;  // relationship is supposed to only have a single child/link datarecord per parent datarecord, so not going to lose data by storing the results this way
                    }

                    // Since each parent datarecord is only allowed to have a single child/linked datarecord of this datatype...
                    // ...read the csv file again to locate parent datarecords that are listed multiple times (importer would create multiple child/linked datarecords)
                    // ...also check to see whether any lines of the csv file reference parent datarecords that already have children/linked datarecords (importer would create additional child/linked datarecords)
                    $line_num = 0;
                    $parent_external_ids = array();
                    foreach ($reader as $row) {
                        $line_num++;
                        $value = trim( $row[$parent_external_id_column] );

                        // Locate duplicates of the parent datarecord's external id (which would create multiple child/linked datarecords when only one is allowed)
                        if ( isset($parent_external_ids[$value]) ) {
                            $errors[] = array(
                                'level' => 'Error',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'The relationship between the Datatype "'.$parent_datatype->getShortName().'" and its '.$rel_type_adj.' Datatype "'.$datatype->getShortName().'" only permits a single '.$rel_type_noun.', but the parent Datarecord pointed to by the external ID "'.$value.'" was already listed on line '.$parent_external_ids[$value].'...which means more than one '.$rel_type_adj.' Datarecord would exist after the import',
                                ),
                            );
                        }
                        else {
                            // ...otherwise, not found, just store the value
                            $parent_external_ids[$value] = $line_num;
                        }


                        // Locate parent datarecords that already have a child/linked datarecord (which would have another child/linked datarecord after the import)
                        if ( isset($datatree[$value]) ) {
                            if ($remote_external_id_column == '' || trim( $row[$remote_external_id_column] ) != $datatree[$value] ) {
                                // If there's a duplicate, and the value doesn't point to a remote datarecord that is already linked to this local datarecord...throw an error
                                $errors[] = array(
                                    'level' => 'Error',
                                    'body' => array(
                                        'line_num' => $line_num,
                                        'message' => 'The Datarecord pointed to by the external ID "'.$value.'" already has its single permitted '.$rel_type_adj.' Datarecord of the Datatype "'.$datatype->getShortName().'", but this line would create another '.$rel_type_adj.' Datarecord as part of the import',
                                    ),
                                );
                            }
                        }

                    }
                }
            }


            // ----------------------------------------
            // If any errors found, flush them to db so they can be loaded with the rest of the errors found during validation
            $need_flush = false;
            foreach ($errors as $error) {
//print_r($error);
                $tracked_error = new TrackedError();
                $tracked_error->setErrorLevel( $error['level'] );
                $tracked_error->setErrorBody( json_encode($error['body']) );
                $tracked_error->setTrackedJob( $tracked_job );
                $tracked_error->setCreatedBy( $user );

                $em->persist($tracked_error);
                $need_flush = true;
            }

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
                        'line_num' => $count,
                        'line' => $row,

                        'api_key' => $beanstalk_api_key,
                        'url' => $url,
                        'memcached_prefix' => $memcached_prefix,    // debug purposes only

                        // Only used when importing into a top-level or child datatype
                        'unique_columns' => $unique_columns,
                        'datafield_mapping' => $datafield_mapping,
                        'fieldtype_mapping' => $fieldtype_mapping,
                        'column_delimiters' => $column_delimiters,
                        'synch_columns' => $synch_columns,

                        // Only used when importing into a child/linked datatype
                        'parent_external_id_column' => $parent_external_id_column,
                        'parent_datatype_id' => $parent_datatype_id,

                        // Only used when creating links via importing
                        'remote_external_id_column' => $remote_external_id_column,
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
     * Checks whether the given column of the csv file satisfies ODR's requirements for uniqueness
     * If $parent_external_id_column == '', then that column of the csv file must not contain any duplicates.
     * If $parent_external_id_column !== '', then that column of the csv file can contain the same value on multiple lines, so long as each line has a different value in $parent_external_id_column
     *
     * @param array $file_headers
     * @param CsvReader $reader Iterator over a csv file
     * @param integer $column_num Which column of the csv file in $reader to check
     * @param integer $parent_external_id_column Which column of the csv file holds the external_id value for the parent datatype...or empty string if there is no parent datatype
     *
     * @return array
     */
    private function checkColumnUniqueness($file_headers, $reader, $column_num, $parent_external_id_column)
    {
        $errors = array();

        // Read each row of the csv file...
        $line_num = 0;
        $unique_values = array();

        if ($parent_external_id_column == '') {
            // Unique column in a top-level datatype...this column of the csv file must not contain any duplicates
            foreach ($reader as $row) {
                $line_num++;

                $value = trim( $row[$column_num] );

                if ( isset($unique_values[$value]) ) {
                    // Encountered duplicate value
                    $errors[] = array(
                        'level' => 'Error',
                        'body' => array(
                            'line_num' => $line_num,
                            'message' => 'The field "'.$file_headers[$column_num].'" is supposed to be unique, but value is a duplicate of line '.$unique_values[$value],
                        ),
                    );
                }
                else {
                    // ...otherwise, not found, just store the value
                    $unique_values[$value] = $line_num;
                }
            }
        }
        else {
            // Unique column in a child datatype...this column of the csv file can contain duplicates, but the values pointed to by the parent external_id column must be different for each duplicate
            foreach ($reader as $row) {
                $line_num++;

                $value = trim( $row[$column_num] );
                $parent_value = trim( $row[$parent_external_id_column] );

                if ( isset($unique_values[$parent_value]) && isset($unique_values[$parent_value][$value]) ) {
                    // Encountered duplicate value
                    $errors[] = array(
                        'level' => 'Error',
                        'body' => array(
                            'line_num' => $line_num,
                            'message' => 'The field "'.$file_headers[$column_num].'" is supposed to be unique, but value is a duplicate of line '.$unique_values[$parent_value][$value],
                        ),
                    );
                }
                else {
                    // ...otherwise, not found, just store the value
                    if ( !isset($unique_values[$parent_value]) )
                        $unique_values[$parent_value] = array();

                    $unique_values[$parent_value][$value] = $line_num;
                }
            }
        }

        // Return any errors found
        return $errors;
    }


    /**
     * Called by worker processes to validate the data from each line of a CSV file
     *
     * @param Request $request
     *
     * @return Response
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

            if ( !isset($post['tracked_job_id']) || !isset($post['datatype_id']) || !isset($post['user_id']) || !isset($post['column_names'])
                || !isset($post['line_num']) || !isset($post['line']) || !isset($post['api_key']) ) {

                throw new \Exception('Invalid job data');
            }

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $column_names = $post['column_names'];
            $line_num = $post['line_num'];
            $line = $post['line'];
            $datatype_id = $post['datatype_id'];
            $user_id = $post['user_id'];
            $api_key = $post['api_key'];

            // Have to pull these separately because they might not exist
            $unique_columns = array();
            if ( isset($post['unique_columns']) )
                $unique_columns = $post['unique_columns'];
            $datafield_mapping = array();
            if ( isset($post['datafield_mapping']) )
                $datafield_mapping = $post['datafield_mapping'];
            $fieldtype_mapping = array();
            if ( isset($post['fieldtype_mapping']) )
                $fieldtype_mapping = $post['fieldtype_mapping'];
            $column_delimiters = array();
            if ( isset($post['column_delimiters']) )
                $column_delimiters = $post['column_delimiters'];
/*
            // TODO - these aren't used in validations?
            $synch_columns = array();
            if ( isset($post['synch_columns']) )
                $synch_columns = $post['synch_columns'];
*/

            // If the import is for a child or linked datatype, then one of the columns from the csv file has to be mapped to the parent (or local if linked import) datatype's external id datafield
            $parent_datatype_id = '';
            if ( isset($post['parent_datatype_id']) )
                $parent_datatype_id = $post['parent_datatype_id'];
            $parent_external_id_column = '';
            if ( isset($post['parent_external_id_column']) )
                $parent_external_id_column = $post['parent_external_id_column'];

            // If the import is for a linked datatype, then another column from the csv file also has to be mapped to the remote datatype's external id datafield
            $remote_external_id_column = '';
            if ( isset($post['remote_external_id_column']) )
                $remote_external_id_column = $post['remote_external_id_column'];



            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');

            /** @var User $user */
            $user = $repo_user->find($user_id);
            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new \Exception('Datatype is deleted!');


            // ----------------------------------------
            // If $parent_external_id_column is specified, then attempt to locate the parent datarecord (or local datarecord if linked)...the located datarecord is not used during verification

            // If importing into child datatype, this will warn if the parent datarecord does not exist
            // If "importing" linked datatype, this will warn if the local datarecord does not exist
            $errors = array();
            $parent_datatype = null;
            if ($parent_datatype_id !== '' && $parent_external_id_column !== '') {
                // Load the parent datatype
                /** @var DataType $parent_datatype */
                $parent_datatype = $repo_datatype->find($parent_datatype_id);
                if ($parent_datatype == null || $parent_datatype->getExternalIdField() == null)
                    throw new \Exception('Invalid Form');

                $parent_external_id_field = $parent_datatype->getExternalIdField();
                $parent_external_id_value = trim( $line[$parent_external_id_column] );
                $dr = parent::getDatarecordByExternalId($em, $parent_external_id_field->getId(), $parent_external_id_value);

                // If a parent with this external id does not exist, warn the user (the row will be ignored)
                if ($dr == null) {
                    $errors[] = array(
                        'level' => 'Warning',
                        'body' => array(
                            'line_num' => $line_num,
                            'message' => 'The value "'.$parent_external_id_value.'" in column "'.$column_names[$parent_external_id_column].'" is supposed to match the external ID of a parent Datarecord in "'.$parent_datatype->getShortName().'", but no such Datarecord exists...this row will be ignored',
                        ),
                    );
                }
            }


            // ----------------------------------------
            // Attempt to locate the datarecord that this row of data will import into...

            // If importing into top-level dataype, $datarecord_id will be for a top-level datarecord
            // If importing into child datatype, $datarecord_id will point to the child datarecord...its parent datarecord was located in the preceding block
            // If "importing" linked datatype, $datarecord_id will point to the remote datarecord...the local datarecord was located in the preceding block
            $datarecord_id = null;

            $external_id_field = $datatype->getExternalIdField();
            if ($external_id_field !== null) {

                //$typeclass = $external_id_field->getFieldType()->getTypeClass();
                $value = '';
                if ($remote_external_id_column == '') {
                    // Find the value for the external ID from $datafield_mapping
                    foreach ($datafield_mapping as $column_num => $datafield_id) {
                        if ($external_id_field->getId() == $datafield_id) {
                            $value = trim( $line[$column_num] );
                            break;
                        }
                    }
                }
                else {
                    // Target datarecord is on the remote side of a link...find the external ID from $remote_external_id_column
                    $value = trim( $line[$remote_external_id_column] );
                }

                // Locate the datarecord pointed to by this external ID
                if ( $parent_external_id_column !== '' && $remote_external_id_column == '' ) {
                    // Importing into child datatype...attempt to locate the child datarecord
                    $parent_external_id = trim( $line[$parent_external_id_column] );
                    $dr = parent::getChildDatarecordByExternalId($em, $external_id_field->getId(), $value, $parent_datatype->getExternalIdField()->getId(), $parent_external_id);
                    if ($dr !== null)
                        $datarecord_id = $dr->getId();
                }
                else {
                    // Importing into top-level or linked datatype...attempt to locate the expected datarecord (or remote if linked)
                    $dr = parent::getDatarecordByExternalId($em, $external_id_field->getId(), $value);
                    if ($dr !== null)
                        $datarecord_id = $dr->getId();
                }


                // If importing into top-level or child datatype, a missing datarecord is acceptable...it will be created during csvworkerAction() later
                // If importing into a linked datatype, the remote datarecord MUST exist...can't link to a datarecord that doesn't exist
                if ($remote_external_id_column !== '' && $datarecord_id == null) {
                    $errors[] = array(
                        'level' => 'Error',
                        'body' => array(
                            'line_num' => $line_num,
                            'message' => 'The value "'.$value.'" in column "'.$column_names[$remote_external_id_column].'" is supposed to match the external ID of a Datarecord in the Datatype "'.$datatype->getShortName().'", but no such Datarecord exists',
                        ),
                    );
                }
            }


            // ----------------------------------------
            // Attempt to validate each line of data against the desired datafield/fieldtype mapping
            foreach ($datafield_mapping as $column_num => $datafield_id) {
                $value = trim( $line[$column_num] );
                $length = mb_strlen($value, "utf-8");   // TODO - is this the right function to use?

                // Get typeclass of what this data will be imported into
                $allow_multiple_uploads = true;
                /** @var FieldType $fieldtype */
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
                        // Don't attempt to validate an empty string...it just means there are no options selected
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


                // ----------------------------------------
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
            }


            // ----------------------------------------
            // Save any errors found
            $need_flush = false;
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

            if ($need_flush)
                $em->flush();


            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                /** @var TrackedJob $tracked_job */
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
                $em = $this->getDoctrine()->getManager();
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
     * @return Response
     */
    public function validateresultsAction($tracked_job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Get necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $templating = $this->get('templating');


            // ----------------------------------------
            /** @var TrackedJob $tracked_job */
            $tracked_job = $repo_tracked_job->find($tracked_job_id);
            if ($tracked_job == null)
                return parent::deletedEntityError('TrackedJob');
            if ( $tracked_job->getJobType() !== "csv_import_validate" )
                return parent::deletedEntityError('TrackedJob');

            $presets = json_decode( $tracked_job->getAdditionalData(), true );
            $target_entity = $tracked_job->getTargetEntity();
            $tmp = explode('_', $target_entity);
            $datatype_id = $tmp[1];

            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
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
            // Always bypass cache in dev mode
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;

            // Load settings for import.html.twig and layout.html.twig from the data stored in the tracked job entity
            $parent_datatype_id = '';
            $parent_datatype = null;
            if ( isset($presets['parent_datatype_id']) && $presets['parent_datatype_id'] !== '' ) {
                $parent_datatype_id = $presets['parent_datatype_id'];

                /** @var DataType $parent_datatype */
                $parent_datatype = $repo_datatype->find($parent_datatype_id);
                if ($parent_datatype == null)
                    throw new \Exception('Invalid Form');
            }

            $linked_importing = false;
            if ( isset($presets['remote_external_id_column']) && $presets['remote_external_id_column'] !== '' ) {
                $linked_importing = true;
            }


            // Also locate any child or linked datatypes for this datatype
            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);
            /** @var DataType[]|null $childtypes */
            $childtypes = null;
            if ($parent_datatype_id !== '') {
                foreach ($datatree_array['descendant_of'] as $dt_id => $parent_dt_id) {
                    if ($parent_dt_id == $datatype_id) {
                        // Ensure user has permissions to modify this childtype before storing it
                        if (isset($user_permissions[$dt_id]) && $user_permissions[$dt_id]['edit'] == 1) {

                            // Only store the childtype if it doesn't have children of its own...
                            if ( !in_array($dt_id, $datatree_array['descendant_of']) )
                                $childtypes[] = $repo_datatype->find($dt_id);
                        }
                    }
                }
                if (count($childtypes) == 0)
                    $childtypes = null;
            }

            /** @var DataType[]|null $linked_types */
            $linked_types = null;
            if ($parent_datatype_id !== '') {
                foreach ($datatree_array['linked_from'] as $descendant_dt_id => $ancestor_ids) {
                    if ( in_array($datatype_id, $ancestor_ids) ) {
                        // Ensure user has permissions to modify this linked type before storing it
                        if (isset($user_permissions[$descendant_dt_id]) && $user_permissions[$descendant_dt_id]['edit'] == 1) {
                            $linked_types[] = $repo_datatype->find($descendant_dt_id);
                        }
                    }
                }
                if (count($linked_types) == 0)
                    $linked_types = null;
            }


            // ----------------------------------------
            // If importing into top-level dataype, $datatype is the top-level datatype and $parent_datatype is null
            // If importing into child datatype, $datatype is the child datatype and $parent_datatype is $datatype's parent
            // If importing linked datatype, $datatype is the remote datatype and $parent_datatype is the local datatype
            // ----------------------------------------


            // ----------------------------------------
            // Grab all datafields belonging to that datatype
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                WHERE df.dataType = :datatype AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL
                ORDER BY dfm.fieldName'
            )->setParameters( array('datatype' => $datatype->getId()) );
            /** @var DataFields[] $datafields */
            $datafields = $query->getResult();
//print_r($results);
//exit();

            // Grab the FieldTypes that the csv importer can read data into
            /** @var FieldType[] $fieldtypes */
            $fieldtypes = $repo_fieldtype->findAll();
            $allowed_fieldtypes = array();

            foreach ($fieldtypes as $num => $fieldtype) {
                // Every field can be imported into except for the Markdown field
                if ($fieldtype->getTypeName() !== 'Markdown') {
                    $allowed_fieldtypes[ $fieldtype->getId() ] = $fieldtype->getTypeName();
                }
                else {
                    unset( $fieldtypes[$num] );
                }
            }


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


            // ----------------------------------------
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


            // ----------------------------------------
            // Get any errors reported during validation of this import
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
                        'childtypes' => $childtypes,
                        'linked_types' => $linked_types,
                        'upload_type' => '',

                        'presets' => $presets,
                        'errors' => $error_messages,

                        // These get passed to layout.html.twig
                        'parent_datatype' => $parent_datatype,
                        'linked_importing' => $linked_importing,

                        'columns' => $file_headers,
                        'datafields' => $datafields,
                        'fieldtypes' => $fieldtypes,
                        'allowed_fieldtypes' => $allowed_fieldtypes,

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
     * @return Response
     */
    public function startworkerAction($job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            // ----------------------------------------
            // Load the data from the finished validation job
            /** @var TrackedJob $tracked_job */
            $tracked_job = $repo_tracked_job->find($job_id);
            if ($tracked_job->getCompleted() == null)
                throw new \Exception('Invalid job');

            $job_data = json_decode( $tracked_job->getAdditionalData(), true );
            $target_entity = $tracked_job->getTargetEntity();
            $tmp = explode('_', $target_entity);
            $datatype_id = $tmp[1];

            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
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
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');

            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
//            $session = $request->getSession();


            // Extract data from the tracked job
            $unique_columns = $job_data['unique_columns'];
            $datafield_mapping = $job_data['datafield_mapping'];
            $fieldtype_mapping = $job_data['fieldtype_mapping'];
            $column_delimiters = $job_data['column_delimiters'];
            $synch_columns = $job_data['synch_columns'];
            $parent_external_id_column = $job_data['parent_external_id_column'];
            $parent_datatype_id = $job_data['parent_datatype_id'];
            $remote_external_id_column = $job_data['remote_external_id_column'];

//print_r($job_data);
//return;

            // For readability, linking datarecords with csv importing uses a different controller action
            $router = $this->get('router');
            $url = $this->container->getParameter('site_baseurl');

            if ($remote_external_id_column == '')
                $url .= $router->generate('odr_csv_import_worker');
            else
                $url .= $router->generate('odr_csv_link_worker');


            // ----------------------------------------
            // Create any necessary datafields
            $new_datafields = array();
            $new_mapping = array();
            $created = false;
            /** @var RenderPlugin $render_plugin */
            $render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);    // default render plugin
            foreach ($datafield_mapping as $column_id => $datafield_id) {
                $datafield = null;

                if ( is_numeric($datafield_id) ) {
                    // Load datafield from repository
                    /** @var DataFields $datafield */
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

                    /** @var FieldType $fieldtype */
                    $fieldtype = $repo_fieldtype->find( $fieldtype_mapping[$column_id] );
                    if ($fieldtype == null)
                        throw new \Exception('Invalid Form');

                    // Create new datafield
                    $created = true;
                    $objects = parent::ODR_addDataField($em, $user, $datatype, $fieldtype, $render_plugin);

                    /** @var DataFields $datafield */
                    $datafield = $objects['datafield'];
                    /** @var DataFieldsMeta $datafield_meta */
                    $datafield_meta = $objects['datafield_meta'];

                    // Set the datafield's name
                    $datafield_meta->setFieldName( $column_names[$column_id] );
                    if ( isset($unique_columns[$column_id]) )
                        $datafield_meta->setIsUnique(true);
                    $em->persist($datafield_meta);  // don't need to flush just yet, nothing needs the meta entry to have the correct name

                    $new_datafields[] = $datafield;

                    $logger->notice('Created new datafield '.$datafield->getId().' "'.$column_names[$column_id].'" for csv import of datatype '.$datatype->getId().' by '.$user->getId());
//print 'created new datafield of fieldtype "'.$fieldtype->getTypeName().'" with name "'.$column_names[$column_id].'"'."\n";
                }

                // Store ID of target datafield
                $new_mapping[$column_id] = $datafield->getId();
            }
            /** @var DataFields[] $new_datafields */

            if ($created) {
                // Since datafields were created for this import, create a new theme element and attach the new datafields to it
                /** @var Theme $theme */
                $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
                $objects = parent::ODR_addThemeElement($em, $user, $datatype, $theme);
                /** @var ThemeElement $theme_element */
                $theme_element = $objects['theme_element'];
                /** @var ThemeElementMeta $theme_element_meta */
//                $theme_element_meta = $objects['theme_element_meta'];

                foreach ($new_datafields as $new_datafield) {
                    // Attach each of the previously created datafields to the new theme_element
                    parent::ODR_addThemeDataField($em, $user, $new_datafield, $theme_element);

                    // If this is a newly created image datafield, ensure it has the required ImageSizes entities
                    if ($new_datafield->getFieldType()->getTypeClass() == 'Image')
                        parent::ODR_checkImageSizes($em, $user, $new_datafield);
                }

                // Save all changes
                $em->flush();

                // Update theme and datatype becuase new datafields were added
                parent::tmp_updateThemeCache($em, $theme, $user, true);

                // Since new datafields were created, wipe the lists of datafield permissions for all users
                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var User[] $user_list */
                $user_list = $user_manager->findUsers();
                foreach ($user_list as $u) {
                    $memcached->delete($memcached_prefix.'.user_'.$u->getId().'_datafield_permissions');

                    // TODO - schedule a permissions recache via beanstalk?
                }
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

                        'column_delimiters' => $column_delimiters,
                        'synch_columns' => $synch_columns,
                        'mapping' => $new_mapping,
                        'line' => $row,

                        // Only used when importing into a child/linked datatype
                        'parent_external_id_column' => $parent_external_id_column,
                        'parent_datatype_id' => $parent_datatype_id,

                        // Only used when creating links via importing
                        'remote_external_id_column' => $remote_external_id_column,
                    )
                );

                // Randomize priority somewhat so multiple people can run imports simultaneously without waiting for the imports started before them to finish completely
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
     * @return Response
     */
    public function csvworkerAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $tracked_job_id = -1;
        $status = '';

        try {
            $post = $_POST;
//print_r($post);
//return;

            if ( !isset($post['tracked_job_id']) || !isset($post['mapping']) || !isset($post['line']) || !isset($post['datatype_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid job data');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $datatype_id = $post['datatype_id'];
            $mapping = $post['mapping'];
            $line = $post['line'];
            $api_key = $post['api_key'];

            $column_delimiters = null;
            if ( isset($post['column_delimiters']) )
                $column_delimiters = $post['column_delimiters'];
            $synch_columns = null;
            if ( isset($post['synch_columns']) )
                $synch_columns = $post['synch_columns'];
            $parent_external_id_column = '';
            if ( isset($post['parent_external_id_column']) )
                $parent_external_id_column = $post['parent_external_id_column'];


            // ----------------------------------------
            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');

            /** @var User $user */
            $user = $repo_user->find($user_id);
            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new \Exception('Datatype is deleted!');


            // ----------------------------------------
            // Attempt to locate the child datarecord's parent, if neccessary
            $parent_datarecord = null;
            $parent_external_id_field = null;
            $parent_external_id_value = '';

            if ($parent_external_id_column !== '') {
                // $datatype_id points to a child datatype
                $datatree_array = parent::getDatatreeArray($em);

                // Locate the top-level datatype
                $parent_datatype_id = null;
                if ( isset($datatree_array['descendant_of'][$datatype_id]) )
                    $parent_datatype_id = $datatree_array['descendant_of'][$datatype_id];
                else
                    throw new \Exception('Invalid Datatype ID');

                // Find the datarecord pointed to by the value in $parent_external_id_column
                $parent_external_id_value = trim( $line[$parent_external_id_column] );

                /** @var DataType $parent_datatype */
                $parent_datatype = $repo_datatype->find($parent_datatype_id);
                $parent_external_id_field = $parent_datatype->getExternalIdField();

                // Since this is importing into a child datatype, parent datarecord must exist
                // csvvalidateAction() purposely only gives a warning so the user is not prevented from importing the rest of the file
                $parent_datarecord = parent::getDatarecordByExternalId($em, $parent_external_id_field->getId(), $parent_external_id_value);
                if ($parent_datarecord == null)
                    throw new \Exception('Parent Datarecord does not exist');
            }

            // ----------------------------------------
            // Attempt to locate the datarecord that this row of data will be imported into
            /** @var DataRecord|null $datarecord */
            $datarecord = null;
            $external_id_field = $datatype->getExternalIdField();
            $external_id_value = '';

            if ( $external_id_field !== null) {
                $datafield_id = $external_id_field->getId();
//                $typeclass = $external_id_field->getFieldType()->getTypeClass();

                foreach ($mapping as $column_num => $df_id) {
                    if ($df_id == $datafield_id)
                        $external_id_value = trim( $line[$column_num] );
                }

                if ($parent_external_id_column !== '') {
                    // Need to locate a child datarecord
                    $datarecord = parent::getChildDatarecordByExternalId($em, $external_id_field->getId(), $external_id_value, $parent_external_id_field->getId(), $parent_external_id_value);
                }
                else {
                    // Need to locate a top-level datarecord
                    $datarecord = parent::getDatarecordByExternalId($em, $external_id_field->getId(), $external_id_value);
                }
            }
//return;

            // One of four possibilities at this point...
            // 1) $parent_datarecord != null, $datarecord != null   -- importing into an existing child datarecord
            // 2) $parent_datarecord != null, $datarecord == null   -- importing into a new child datarecord
            // 3) $parent_datarecord == null, $datarecord != null   -- importing into an existing top-level datarecord
            // 4) $parent_datarecord == null, $datarecord == null   -- importing into a new top-level datarecord


            // ----------------------------------------
            // Determine whether to create a new datarecord or not
            if ($datarecord == null) {
                // Create a new datarecord, since one doesn't exist
                $datarecord = parent::ODR_addDataRecord($em, $user, $datatype);
                if ($parent_datarecord == null) {
                    $datarecord->setParent($datarecord);
                    $datarecord->setGrandparent($datarecord);
                }
                else {
                    $datarecord->setParent($parent_datarecord);
                    $datarecord->setGrandparent($parent_datarecord->getGrandparent());
                }

                $em->persist($datarecord);

                if ($parent_datarecord == null) {
                    // Created new top-level datarecord
                    $status = "\n".'Created new datarecord for csv import of datatype '.$datatype_id.'...'."\n";
                    $logger->notice('Created datarecord '.$datarecord->getId().' for csv import of datatype '.$datatype_id.' by '.$user->getId());
                }
                else {
                    // Created new child datarecord
                    $status = "\n".'Created new child datarecord under parent datarecord '.$parent_datarecord->getId().' for csv import of datatype '.$datatype_id.'...'."\n";
                    $logger->notice('Created child datarecord '.$datarecord->getId().' under parent datarecord '.$parent_datarecord->getId().' for csv import of datatype '.$datatype_id.' by '.$user->getId());
                }
            }
            else {
                // Mark datarecord as updated
                $datarecord->setUpdated( new \DateTime() );
                $datarecord->setUpdatedBy($user);
                $em->persist($datarecord);

                if ($parent_datarecord == null) {
                    // Updated existing top-level datarecord
                    $status = "\n".'Found existing datarecord '.$datarecord->getId().' for csv import of datatype '.$datatype_id.'...'."\n";
                    $logger->notice('Using existing datarecord '.$datarecord->getId().' pointed to by "'.$external_id_value.'" for csv import of datatype '.$datatype_id.' by '.$user->getId());
                }
                else {
                    // Updated existing child datarecord
                    $status = "\n".'Found existing child datarecord '.$datarecord->getId().' under parent datarecord '.$parent_datarecord->getId().' for csv import of datatype '.$datatype_id.'...'."\n";
                    $logger->notice('Using existing child datarecord '.$datarecord->getId().' pointed to by "'.$external_id_value.'" under parent datarecord '.$parent_datarecord->getId().' for csv import of datatype '.$datatype_id.' by '.$user->getId());
                }
            }
            $em->flush($datarecord);
            $em->refresh($datarecord);


           // ----------------------------------------
            // Break apart the line into constituent columns...
            foreach ($line as $column_num => $column_data) {
                // Only care about this column if it's mapped to a datafield...
                if ( isset($mapping[$column_num]) ) {
                    // ...grab which datafield is getting mapped to
                    $datafield_id = $mapping[$column_num];
                    /** @var DataFields $datafield */
                    $datafield = $repo_datafield->find($datafield_id);

                    $typename = $datafield->getFieldType()->getTypeName();
                    $typeclass = $datafield->getFieldType()->getTypeClass();
                    $classname = "ODR\\AdminBundle\\Entity\\".$typeclass;

                    $column_data = trim($column_data);

                    if ($typeclass == 'Boolean') {
                        // Get the existing entity for this datarecord/datafield, or create a new one if it doesn't exist
                        /** @var ODRBoolean $entity */
                        $entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);

                        // Any character in the field counts as checked
                        $checked = false;
                        if ($column_data !== '')
                            $checked = true;

                        // Ensure the value in the datafield matches the value in the import file
                        parent::ODR_copyStorageEntity($em, $user, $entity, array('value' => $checked));
                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$checked.'"...'."\n";

                    }
                    else if ($typeclass == 'File' || $typeclass == 'Image') {

                        // ----------------------------------------
                        $csv_filenames = array();
                        $status .= '    -- datafield '.$datafield->getId().' ('.$typeclass.') '."\n";


                        // ----------------------------------------
                        // If a filename is in this column...
                        if ($column_data !== '') {
                            // Grab the associated datarecordfield entity
                            $drf = parent::ODR_addDataRecordField($em, $user, $datarecord, $datafield);

                            // Store the path to the user's upload area...
                            $path_prefix = dirname(__FILE__).'/../../../../web/';
                            $storage_filepath = 'uploads/csv/user_'.$user->getId().'/storage';

                            // Grab a list of the files/images already uploaded to this datafield
                            $existing_files = array();
                            $query_str =
                               'SELECT e
                                FROM ODRAdminBundle:'.$typeclass.' AS e
                                WHERE e.dataRecord = :datarecord AND e.dataField = :datafield ';
                            if ($typeclass == 'Image')
                                $query_str .= 'AND e.original = 1 ';
                            $query_str .= 'AND e.deletedAt IS NULL';
                            $query = $em->createQuery($query_str)->setParameters( array('datarecord' => $datarecord->getId(), 'datafield' => $datafield->getId()) );

                            /** @var File[]|Image[] $objects */
                            $objects = $query->getResult();
                            foreach ($objects as $tmp => $file)
                                $existing_files[ $file->getOriginalFileName() ] = $file;    // TODO - duplicate original filenames in datafield?
                            /** @var File[]|Image[] $existing_files */

                            // ----------------------------------------
                            // For each file/image listed in the csv file...
                            $csv_filenames = explode( $column_delimiters[$column_num], $column_data );
                            foreach ($csv_filenames as $csv_filename) {
                                // ...there are three possibilities...
                                if ( !isset($existing_files[$csv_filename]) ) {
                                    // ...need to add a new file/image
                                    parent::finishUpload($em, $storage_filepath, $csv_filename, $user->getId(), $drf->getId());

                                    $status .= '      ...uploaded new '.$typeclass.' ("'.$csv_filename.'")'."\n";
                                }
                                else if ( $existing_files[$csv_filename]->getOriginalChecksum() == md5_file($path_prefix.$storage_filepath.'/'.$csv_filename) ) {
                                    // ...the specified file/image is already in datafield
                                    $status .= '      ...'.$typeclass.' ("'.$csv_filename.'") is an exact copy of existing version, skipping.'."\n";

                                    // Delete the file/image from the csv import storage directory on the server since it already exists as an officially uploaded file
                                    unlink($path_prefix.$storage_filepath.'/'.$csv_filename);
                                }
                                else {
                                    // ...need to "update" the existing file/image
                                    $status .= '      ...'.$typeclass.' ("'.$csv_filename.'") is different than existing version, "updating"...';

                                    // Load old file/image and its associated metadata
                                    $old_obj = $existing_files[$csv_filename];
                                    $old_obj_meta = null;
                                    $properties = array();
                                    if ($typeclass == 'File') {
                                        /** @var File $obj_obj */
                                        $old_obj_meta = $old_obj->getFileMeta();
                                        $properties = array(
                                            'description' => $old_obj_meta->getDescription(),
                                            'original_filename' => $old_obj_meta->getOriginalFileName(),
                                            'external_id' => $old_obj_meta->getExternalId(),
                                            'publicDate' => $old_obj_meta->getPublicDate(),
                                        );

                                        // Ensure no decrypted version of the original file remains on the server
                                        $filepath = dirname(__FILE__).'/../../../../web/uploads/files/File_'.$old_obj->getId().'.'.$old_obj->getExt();
                                        if ( file_exists($filepath) )
                                            unlink($filepath);
                                    }
                                    else {
                                        /** @var Image $old_obj */
                                        $old_obj_meta = $old_obj->getImageMeta();
                                        $properties = array(
                                            'caption' => $old_obj_meta->getCaption(),
                                            'original_filename' => $old_obj_meta->getOriginalFileName(),
                                            'external_id' => $old_obj_meta->getExternalId(),
                                            'publicDate' => $old_obj_meta->getPublicDate(),
                                            'display_order' => $old_obj_meta->getDisplayorder()
                                        );

                                        // Ensure no decrypted version of the original image or its thumbnails remain on the server
                                        /** @var Image[] $old_images */
                                        $old_images = $em->getRepository('ODRAdminBundle:Image')->findBy( array('parent' => $old_obj->getId()) );
                                        foreach ($old_images as $img) {
                                            $filepath = dirname(__FILE__).'/../../../../web/uploads/images/Image_'.$img->getId().'.'.$img->getExt();
                                            if ( file_exists($filepath) )
                                                unlink($filepath);
                                        }
                                    }

                                    // "Upload" the new file, and copy over the existing metadata
                                    $new_obj = parent::finishUpload($em, $storage_filepath, $csv_filename, $user->getId(), $drf->getId());
                                    if ($typeclass == 'File')
                                        parent::ODR_copyFileMeta($em, $user, $new_obj, $properties);
                                    else
                                        parent::ODR_copyImageMeta($em, $user, $new_obj, $properties);

                                    // Save who replaced the file/image
                                    $old_obj->setDeletedBy($user);
                                    $em->persist($old_obj);
                                    $em->flush($old_obj);

                                    // Delete the old object and its metadata entry
                                    $em->remove($old_obj);
                                    $em->remove($old_obj_meta);
                                    $em->flush();
                                }
                            }
                        }

                        // ----------------------------------------
                        // Delete all files/images not listed in csv file if user selected that option
                        $need_flush = false;
                        if ( isset($synch_columns[$column_num]) && $synch_columns[$column_num] == 1) {

                            // Grab all files/images (including thumbnails) uploaded to this datarecord/datafield
                            $query = $em->createQuery(
                               'SELECT e
                                FROM ODRAdminBundle:'.$typeclass.' AS e
                                WHERE e.dataRecord = :datarecord AND e.dataField = :datafield
                                AND e.deletedAt IS NULL'
                            )->setParameters( array('datarecord' => $datarecord->getId(), 'datafield' => $datafield->getId()) );
                            $results = $query->getResult();

                            foreach ($results as $tmp => $file) {
                                /** @var File|Image $file */
                                $original_filename = $file->getOriginalFileName();

                                if ( !in_array($original_filename, $csv_filenames) ) {
                                    if ($typeclass == 'File') {
                                        /** @var File $file */
                                        $status .= '      ...'.$typeclass.' ("'.$original_filename.'") not listed in csv file, deleting...'."\n";

                                        // Delete the decrypted version of this file from the server, if it exists
                                        $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
                                        $filename = 'File_'.$file->getId().'.'.$file->getExt();
                                        $absolute_path = realpath($file_upload_path).'/'.$filename;

                                        if ( file_exists($absolute_path) )
                                            unlink($absolute_path);

                                        // Save who deleted the file
                                        $file->setDeletedBy($user);
                                        $em->persist($file);
                                        $em->flush($file);

                                        // Delete the file entity and its associated metadata entry
                                        $file_meta = $file->getFileMeta();
                                        $em->remove($file);
                                        $em->remove($file_meta);
                                        $need_flush = true;
                                    }
                                    else if ($typeclass == 'Image') {
                                        /** @var Image $file */
                                        if ($file->getOriginal() == 1) {
                                            $status .= '      ...'.$typeclass.' ("'.$original_filename.'") not listed in csv file, deleting...'."\n";

                                            // Delete the image's associated metadata entry
                                            $image_meta = $file->getImageMeta();
                                            $em->remove($image_meta);
                                        }

                                        // Ensure no decrypted version of the image (or thumbnails) exists on the server
                                        $local_filepath = dirname(__FILE__).'/../../../../web/uploads/images/Image_'.$file->getId().'.'.$file->getExt();
                                        if ( file_exists($local_filepath) )
                                            unlink($local_filepath);

                                        // Save who deleted the image
                                        $file->setDeletedBy($user);
                                        $em->persist($file);
                                        $em->flush($file);

                                        // Delete the image (thumbnails are deleted by this as well)
                                        $em->remove($file);
                                        $need_flush = true;
                                    }
                                }
                            }
                        }

                        if ($need_flush)
                            $em->flush();
                    }
                    else if ($typeclass == 'IntegerValue') {
                        // Get the existing entity for this datarecord/datafield, or create a new one if it doesn't exist
                        /** @var IntegerValue $entity */
                        $entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);

                        // NOTE - intentionally not using intval() here...self::csvvalidateAction() would've already warned if column data wasn't an integer
                        // In addition, parent::ODR_copyStorageEntity() has to have values passed as strings, and will convert back to integer before saving
                        $value = $column_data;

                        // Ensure the value stored in the entity matches the value in the import file
                        parent::ODR_copyStorageEntity($em, $user, $entity, array('value' => $value));
                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$value.'"...'."\n";

                    }
                    else if ($typeclass == 'DecimalValue') {
                        // Get the existing entity for this datarecord/datafield, or create a new one if it doesn't exist
                        /** @var DecimalValue $entity */
                        $entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);

                        // NOTE - intentionally not using floatval() here...self::csvvalidateAction() would've already warned if column data wasn't a float
                        // In addition, parent::ODR_copyStorageEntity() has to have values passed as strings...DecimalValue::setValue() will deal with any string received
                        $value = $column_data;

                        // Ensure the value stored in the entity matches the value in the import file
                        parent::ODR_copyStorageEntity($em, $user, $entity, array('value' => $value));
                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$value.'"...'."\n";

                    }
                    else if ($typeclass == 'LongText' || $typeclass == 'LongVarchar' || $typeclass == 'MediumVarchar' || $typeclass == 'ShortVarchar') {
                        // Get the existing entity for this datarecord/datafield, or create a new one if it doesn't exist
                        /** @var LongText|LongVarchar|MediumVarchar|ShortVarchar $entity */
                        $entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);

                        // Ensure the value stored in the entity matches the value in the import file
                        parent::ODR_copyStorageEntity($em, $user, $entity, array('value' => $column_data));
                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$column_data.'"...'."\n";

                    }
                    else if ($typeclass == 'DatetimeValue') {
                        // Get the existing entity for this datarecord/datafield, or create a new one if it doesn't exist
                        /** @var DatetimeValue $entity */
                        $entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);

                        // Turn the data into a DateTime object...csvvalidateAction() already would've warned if column data isn't actually a date
                        $value = null;
                        if ( $column_data !== '' )
                            $value = new \DateTime($column_data);

                        // Ensure the value stored in the entity matches the value in the import file
                        parent::ODR_copyStorageEntity($em, $user, $entity, array('value' => $value));
                        if ($value == null)
                            $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to ""...'."\n";
                        else
                            $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$value->format('Y-m-d H:i:s').'"...'."\n";

                    }
                    else if ($typeclass == 'Radio') {
                        $status .= '    -- datafield '.$datafield->getId().' ('.$typeclass.') ';

                        // If multiple radio/select, get an array of all the options...
                        $options = array($column_data);
                        if ($typename == "Multiple Select" || $typename == "Multiple Radio")
                            $options = explode( $column_delimiters[$column_num], $column_data );

                        foreach ($options as $num => $option_name) {
                            // Don't look for or create a blank radio option
                            $option_name = trim($option_name);
                            if ( $option_name == '' )
                                continue;

                            // Create a radio_option entity for this datafield with this name if it doesn't already exist
                            $force_create = false;
                            $radio_option = parent::ODR_addRadioOption($em, $user, $datafield, $force_create, $option_name);

                            // Now that the radio option is guaranteed to exist...grab the relevant RadioSelection entity...
                            $drf = parent::ODR_addDataRecordField($em, $user, $datarecord, $datafield);


                            // If this field only allows a single selection...
                            if ($typename == 'Single Radio' || $typename == 'Single Select') {
                                /** @var RadioSelection[] $radio_selections */
                                $radio_selections = $em->getRepository('ODRAdminBundle:RadioSelection')->findBy( array('dataRecordFields' => $drf->getId()) );

                                // ...for every radio selection entity in this datafield...
                                foreach ($radio_selections as $rs) {
                                    if ( $rs->getRadioOption()->getId() !== $radio_option->getId() && $rs->getSelected() == 1 ) {
                                        // ...if it's not the one that's supposed to be selected, deselect it
                                        $properties = array('selected' => 0);
                                        parent::ODR_copyRadioSelection($em, $user, $rs, $properties);
                                    }
                                }
                            }

                            // TODO - add Radio equivalent of "delete all unlisted files/images" for Multiple Radio/Select

                            // Now that there won't be extraneous radio options selected afterwards... ensure the radio selection entity for the desired radio option exists
                            $radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $drf);

                            // Ensure it has the correct selected status
                            $properties = array('selected' => 1);
                            parent::ODR_copyRadioSelection($em, $user, $radio_selection, $properties);

                            $status .= '    ...radio_selection for radio_option ("'.$radio_option->getOptionName().'") now selected'."\n";

                        }
                        $status .= "\n";
                    }
                }
            }

            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                /** @var TrackedJob $tracked_job */
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
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);
/*
            $options = array(
                'mark_as_updated' => true,
                'user_id' => $user->getId(),    // since this action is called via command-line, need to specify which user is doing the importing
                'force_shortresults_recache' => true,
                'force_textresults_recache' => true
            );
            parent::updateDatarecordCache($datarecord->getId(), $options);
*/

            // Delete all cached search results for this datatype
            // TODO - more precise deletion of cached search results...new datarecord created should delete all search results without datafields, update to a datafield should delete all search results with that datafield
            $cached_searches = $memcached->get($memcached_prefix.'.cached_search_results');
            if ( $cached_searches != false && isset($cached_searches[$datatype_id]) ) {
                unset( $cached_searches[$datatype_id] );

                // Save the collection of cached searches back to memcached
                $memcached->set($memcached_prefix.'.cached_search_results', $cached_searches, 0);
            }

            $return['d'] = $status;
        }
        catch (\Exception $e) {
            // TODO - ???
            $status = str_replace('</br>', "\n", $status);
            print $status;


            // Update the job tracker even if an error occurred...right? TODO
            if ($tracked_job_id !== -1) {
                $em = $this->getDoctrine()->getManager();
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


    /**
     * Called by worker processes to create/delete links between datarecords during the import of a CSV file
     * TODO - remove/modify how $status being used as a return variable
     *
     * @param Request $request
     *
     * @return Response
     */
    public function csvlinkworkerAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $tracked_job_id = -1;
        $status = '';

        try {
            $post = $_POST;
//print_r($post);
//return;

            // TODO - correct requirements
            if ( !isset($post['tracked_job_id']) || /*!isset($post['mapping']) || */ !isset($post['line']) || !isset($post['datatype_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid job data');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $datatype_id = $post['datatype_id'];
//            $mapping = $post['mapping'];
            $line = $post['line'];
            $api_key = $post['api_key'];

/*
            $column_delimiters = null;
            if ( isset($post['column_delimiters']) )
                $column_delimiters = $post['column_delimiters'];
*/

            // If the import is for a child or linked datatype, then one of the columns from the csv file has to be mapped to the parent (or local if linked import) datatype's external id datafield
            $parent_datatype_id = '';
            if ( isset($post['parent_datatype_id']) )
                $parent_datatype_id = $post['parent_datatype_id'];
            $parent_external_id_column = '';
            if ( isset($post['parent_external_id_column']) )
                $parent_external_id_column = $post['parent_external_id_column'];

            // If the import is for a linked datatype, then another column from the csv file also has to be mapped to the remote datatype's external id datafield
            $remote_external_id_column = '';
            if ( isset($post['remote_external_id_column']) )
                $remote_external_id_column = $post['remote_external_id_column'];


            // ----------------------------------------
            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
/*
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
*/
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');

            /** @var User $user */
            $user = $repo_user->find($user_id);
            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new \Exception('Datatype is deleted!');
            /** @var DataType $parent_datatype */
            $parent_datatype = $repo_datatype->find($parent_datatype_id);
            if ($parent_datatype == null)
                throw new \Exception('Datatype is deleted!');

            // ----------------------------------------
            // Locate "local" and "remote" datarecords
            $local_external_id_field = $parent_datatype->getExternalIdField();
            $local_external_id = trim( $line[$parent_external_id_column] );
            $local_datarecord = parent::getDatarecordByExternalId($em, $local_external_id_field->getId(), $local_external_id);

            $remote_external_id_field = $datatype->getExternalIdField();
            $remote_external_id = trim( $line[$remote_external_id_column] );
            $remote_datarecord = parent::getDatarecordByExternalId($em, $remote_external_id_field->getId(), $remote_external_id);


            // ----------------------------------------
            // Ensure a link exists from the local datarecord to the remote datarecord
            parent::ODR_linkDataRecords($em, $user, $local_datarecord, $remote_datarecord);
            $status .= ' -- Datarecord '.$local_datarecord->getId().' (external id: "'.$local_external_id.'") is now linked to Datarecord '.$remote_datarecord->getId().' (external id: "'.$remote_external_id.'")'."\n";

            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                /** @var TrackedJob $tracked_job */
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total)
                    $tracked_job->setCompleted( new \DateTime() );

                $em->persist($tracked_job);
                $em->flush();
//$ret .= '  Set current to '.$count."\n";
            }


            // ----------------------------------------
            // Datarecord order can't change as a result of linking/unlinking
/*
            // Schedule the local datarecord for an update
            $options = array(
                'user_id' => $user->getId(),    // since this action is called via command-line, need to specify which user is doing the importing
                'mark_as_updated' => true
            );
            parent::updateDatarecordCache($local_datarecord->getId(), $options);
*/
            parent::tmp_updateDatarecordCache($em, $local_datarecord, $user);

            $return['d'] = $status;
        }
        catch (\Exception $e) {
            // TODO - ???
            $status = str_replace('</br>', "\n", $status);
            print $status;


            // Update the job tracker even if an error occurred...right? TODO
            if ($tracked_job_id !== -1) {
                $em = $this->getDoctrine()->getManager();
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
            $return['d'] = 'Error 0x238253515 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
