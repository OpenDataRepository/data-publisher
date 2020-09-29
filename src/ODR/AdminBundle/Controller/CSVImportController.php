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
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataTree;
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
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\TagTree;
use ODR\AdminBundle\Entity\TrackedError;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\TagHelperService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Service\UUIDService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
// Symfony
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
// CSV Reader
use Ddeboer\DataImport\Reader\CsvReader;
// ForceUTF8
use \ForceUTF8\Encoding;


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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )    // TODO - less restrictive permissions?
                throw new ODRForbiddenException();
            // --------------------

            // This doesn't make sense on a master template...
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to import into a master template');
            // ...or a metadata datatype
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to import into a metadata datatype');


            // ----------------------------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // ----------------------------------------
            // Locate any child or linked datatypes
            $datatree_array = $dti_service->getDatatreeArray();
            $childtypes = array();
            foreach ($datatree_array['descendant_of'] as $dt_id => $parent_dt_id) {
                if ($parent_dt_id == $datatype_id) {
                    // Ensure user has permissions to modify this childtype before storing it
                    if ( isset($datatype_permissions[ $dt_id ])
                        && isset($datatype_permissions[ $dt_id ]['dr_edit'])
                    ) {
                        // Only store the childtype if it doesn't have children of its own
                        // CSVImport currently can't handle importing into grandchild datatypes...
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
                    if ( isset($datatype_permissions[ $descendant_dt_id ])
                        && isset($datatype_permissions[ $descendant_dt_id ]['dr_edit'])
                    ) {
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

                        'datatree_array' => $datatree_array,
                        'parent_datatype' => $datatype, // user hasn't selected which datatype to import into yet

                        'presets' => null,
                        'errors' => null,
                        'allow_import' => false,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0xc37afbaf;
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
     * It's simpler to handle setting/changing the CSV delimiter through HTTP requests and the user's
     * session...otherwise it would have to be spliced into the Flow.js upload logic...
     *
     * @param Request $request
     *
     * @return Response
     */
    public function delimiterAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            $post = $request->request->all();
            if ( !isset($post['csv_delimiter']) )
                throw new ODRBadRequestException('Invalid Form');

            $delimiter = $post['csv_delimiter'];

            // Translate the primary delimiter if needed
            if ($delimiter === 'tab')
                $delimiter = "\t";
            // Can't throw an error on empty delimiter here
            if ( strlen($delimiter) > 1 )
                throw new ODRBadRequestException('Invalid column delimiter');

            // Store the desired delimiter in user's session
            $session = $request->getSession();
            $session->set('csv_delimiter', $delimiter);
        }
        catch (\Exception $e) {
            $source = 0x99d8cc4b;
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
     * Reads the previously uploaded CSV file to extract column names, and renders a form to let
     * the user decide what data to import and which DataFields to import it to.
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataType $source_datatype */
            $source_datatype = $repo_datatype->find($source_datatype_id);
            if ($source_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataType $target_datatype */
            $target_datatype = $repo_datatype->find($target_datatype_id);
            if ($target_datatype == null)
                throw new ODRNotFoundException('Datatype');
            $grandparent_target_datatype = $target_datatype->getGrandparent();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $source_datatype) )    // TODO - less restrictive permissions?
                throw new ODRForbiddenException();
            // --------------------

            // This doesn't make sense on a master template...
            if ( $source_datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to import into a master template');
            if ( $target_datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to import into a master template');
            // ...or a metadata datatype
            if ( !is_null($source_datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to import into a metadata datatype');
            if ( !is_null($target_datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to import into a metadata datatype');


            // ----------------------------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$target_datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$target_datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$target_datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // ----------------------------------------
            // $datatype_id is the datatype being imported into...determine whether it's the remote
            //  side of a datatype link, or a top-level datatype, or locate its parent datatype if
            //  it's a child datatype
            $linked_importing = false;
            $parent_datatype = null;


            $datatree_array = $dti_service->getDatatreeArray();
            if ( $source_datatype_id !== $target_datatype_id
                && isset($datatree_array['linked_from'][$target_datatype_id])
                && in_array($source_datatype_id, $datatree_array['linked_from'][$target_datatype_id])
            ) {
                /* "Importing into" a linked datatype */
                $linked_importing = true;
                $parent_datatype = $source_datatype;

                // User should not have had the option to link to a datatype that lacks an external ID field...
                if ($target_datatype->getExternalIdField() == null)
                    throw new ODRBadRequestException('Invalid Target Datatype');
            }
            else if ( !isset($datatree_array['descendant_of'][$target_datatype_id])
                || $datatree_array['descendant_of'][$target_datatype_id] == ''
            ) {
                /* Importing into top-level datatype, do nothing */
            }
            else {
                /* Importing into a child datatype */
                $parent_datatype_id = $datatree_array['descendant_of'][$target_datatype_id];

                if ( isset($datatree_array['descendant_of'][$parent_datatype_id])
                    && $datatree_array['descendant_of'][$parent_datatype_id] == ''
                ) {
                    // Importing into a childtype...going to need the parent datatype to help
                    //  determine where data should go
                    $parent_datatype = $repo_datatype->find($parent_datatype_id);

                    // User shouldn't have had the option to select a child datatype if the parent
                    //  had no external ID field...
                    if ($parent_datatype->getExternalIdField() == null)
                        throw new ODRBadRequestException('Invalid Target Datatype');
                }
                else {
                    // User should not have had the option to select a child of a child datatype...
                    throw new ODRBadRequestException('Invalid Target Datatype');
                }
            }


            // ----------------------------------------
            // Grab all datafields belonging to that datatype
            $datatype_array = $dti_service->getDatatypeArray($grandparent_target_datatype->getId(), false);
            $datafields = $datatype_array[$target_datatype_id]['dataFields'];
            uasort($datafields, "self::name_sort");

            // Grab the FieldTypes that the csv importer can read data into
            $query = $em->createQuery('SELECT ft FROM ODRAdminBundle:FieldType ft ORDER BY ft.typeName');
            /** @var FieldType[] $fieldtypes */
            $fieldtypes = $query->getResult();
            $allowed_fieldtypes = array();

            foreach ($fieldtypes as $num => $fieldtype) {
                // Every field can be imported into except for Markdown fields
                $typename = $fieldtype->getTypeName();
                if ($typename === 'Markdown') {
                    unset( $fieldtypes[$num] );
                }
                else {
                    $allowed_fieldtypes[ $fieldtype->getId() ] = $fieldtype->getTypeName();
                }
            }

            // Also need to keep track of any tag datafields that allow parent/child tags...
            $multilevel_tag_datafields = array();
            foreach ($datafields as $df_id => $df) {
                if ( $df['dataFieldMeta']['tags_allow_multiple_levels'] == true )
                    $multilevel_tag_datafields[$df_id] = 1;
            }


            // ----------------------------------------
            // Attempt to load the previously uploaded csv file
            if ( !$session->has('csv_file') )
                throw new ODRBadRequestException('No CSV file uploaded');
            if ( !$session->has('csv_delimiter') )
                throw new ODRBadRequestException('No delimiter set');


            // Ensure the file exists before attempting to read it...
            $csv_import_path = $this->getParameter('odr_web_directory').'/uploads/csv/user_'.$user->getId().'/';
            $csv_filename = $session->get('csv_file');

            if ( !file_exists($csv_import_path.$csv_filename) )
                throw new ODRException('Target CSV File does not exist');


            // Ensure the delimiter is valid before attempting to read the file...
            $delimiter = $session->get('csv_delimiter');
            if ($delimiter === '')
                throw new ODRBadRequestException("CSV delimiter can't be blank");
            if ( strlen($delimiter) > 1 )
                throw new ODRBadRequestException('Invalid column delimiter');


            // Remove any completely blank columns and rows from the csv file
            $column_lengths = array();
            $file_encoding_converted = self::trimCSVFile($csv_import_path, $csv_filename, $delimiter, $column_lengths);

            // Apparently SplFileObject doesn't do this before opening the file...
            ini_set('auto_detect_line_endings', TRUE);

            // Symfony has already verified that the file's mimetype is valid...
            $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
            $reader = new CsvReader($csv_file, $delimiter);
            $reader->setHeaderRowNumber(0);     // want associative array for the column names


            // ----------------------------------------
            // Grab column names from first row
            $file_headers = $reader->getColumnHeaders();
            $error_messages = array();
            foreach ($file_headers as $column_num => $value) {
                if ($value == '') {
                    $error_messages[] = array(
                        'error_level' => 'Error',
                        'error_body' => array(
                            'line_num' => 1,
                            'message' => 'Column '.($column_num + 1).' has an illegal blank header'
                        )
                    );
                }
            }

            // Notify of "syntax" errors in the csv file
            if ( count($reader->getErrors()) > 0 ) {
                // Warn about wrong number of columns
                foreach ($reader->getErrors() as $line_num => $errors) {
                    $error_messages[] = array(
                        'error_level' => 'Error',
                        'error_body' => array(
                            'line_num' => $line_num+1,
                            'message' => 'Found '.count($errors).' columns on this line, expected '.count($file_headers)
                        )
                    );
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

                            'datatree_array' => $datatree_array,

                            'csv_delimiter' => $delimiter,
                            'column_lengths' => $column_lengths,
                            'columns' => $file_headers,
                            'datafields' => $datafields,
                            'fieldtypes' => $fieldtypes,

                            'allowed_fieldtypes' => $allowed_fieldtypes,
                            'multilevel_tag_datafields' => $multilevel_tag_datafields,

                            'presets' => null,
                            'file_encoding_converted' => $file_encoding_converted,
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
            $source = 0x9afc6f73;

            // json_encode() will return a boolean when it attempts to encode non-UTF8 characters
            //  ...which is a possibility here because of how the CsvReader class works
            $safe_message = Encoding::toUTF8($e->getMessage());

            if ($e instanceof ODRException)
                throw new ODRException($safe_message, $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($safe_message, 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Sorts the cached array version of datafields by fieldname
     *
     * @param array $a
     * @param array $b
     *
     * @return int
     */
    private function name_sort($a, $b)
    {
        $df_a_name = $a['dataFieldMeta']['fieldName'];
        $df_b_name = $b['dataFieldMeta']['fieldName'];

        return strnatcasecmp($df_a_name, $df_b_name);
    }


    /**
     * Because Excel apparently can't always manage to keep itself from exporting completely blank
     * rows or columns in the csv files it creates, there needs to be a function to strip these
     * completely blank rows/columns from the csv file that the user uploads.
     *
     * @throws ODRException
     *
     * @param string $csv_import_path
     * @param string $csv_filename
     * @param string $delimiter
     * @param array $column_lengths Stores the length of the longest value in each column
     *
     * @return boolean true if the function attempted to force UTF-8 encoding in the file, false otherwise
     */
    private function trimCSVFile($csv_import_path, $csv_filename, $delimiter, &$column_lengths)
    {
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
            $trimmed_header = trim($header, " \t\n\r\0\x0B\xA0");    // want to also get rid of html non-breaking space, trim() doesn't by default
            $converted_header = Encoding::toUTF8($trimmed_header);

            if ( $header !== $converted_header ) {
                $headers_trimmed = true;
                $header_row[$num] = $converted_header;
            }
        }

        // Determine if any of the column headers are blank...
        $blank_header = false;
        $column_use = array();
        for ($i = 0; $i < count($header_row); $i++) {
            if ( $header_row[$i] !== '' ) {
                // This column has a non-empty header
                $column_use[$i] = true;
            }
            else {
                // This column has an empty header...mark as a possibility of removing later
                $column_use[$i] = false;
                $blank_header = true;
            }
        }


        // Store whether there's any invalid UTF-8 characters in a specific row...
        $encoding_errors = array();
        // Also need to determine if any of the rows in the file are completely blank...
        $blank_rows = array();

        // ----------------------------------------
        // Continue reading the rest of the file...
        $line_num = 0;
        while ( $csv_file->valid() ) {
            $row = $csv_file->fgetcsv();    // automatically increments file pointer
            if ( $row === null || count($row) == 0 )
                continue;
//print_r($row);

            $line_num++;

            // If there's a mismatch in the number of columns, don't bother reading rest of file
            // The user needs to fix the file before it can be understood
            if ( count($row) !== count($header_row) ) {
//print 'column mismatch';
                $csv_file = null;
                return false;
            }

            // Check for any values in this row/column
            $blank_row = true;
            foreach ($row as $column_id => $value) {
                if ($value !== '') {
                    // Store that this column and this row have at least one value
                    $column_use[$column_id] = true;
                    $blank_row = false;

                    // Check whether this string is valid UTF-8
                    if ( !mb_check_encoding($value, 'UTF-8') ) {
                        if ( !isset($encoding_errors[$line_num]) )
                            $encoding_errors[$line_num] = array();

                        $encoding_errors[$line_num][$column_id] = 1;
                    }
                }

                // Keep track of maximum column length...this is outside the previous if statement
                //  so that lengths get created for columns that are otherwise entirely blank
                if ( !isset($column_lengths[$column_id]) )
                    $column_lengths[$column_id] = 0;
                // TODO - need to use mb_strlen() instead?
                if ( strlen($value) > $column_lengths[$column_id] )
                    $column_lengths[$column_id] = strlen($value);
            }

            // If none of the columns in this row had a value, save the line number so this blank
            //  row can be removed
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

        if ( count($blank_rows) > 0 || count($encoding_errors) > 0 || $headers_trimmed || $blank_header )
            $rewrite_file = true;

        if (!$rewrite_file) {
            // File doesn't have errors, encoding or otherwise...no need to rewrite
//print "don't need to rewrite file";
            $csv_file = null;
            return false;
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

        // Rewind the file pointer to the original csv file to the **second** line, then print out
        //  the header row without the headers for the blank columns
        $csv_file->rewind();
        $new_header_row = $header_row;
        foreach ($blank_columns as $num => $column_id)
            unset($new_header_row[$column_id]);
        fputcsv($new_csv_file, $new_header_row, $delimiter);

        // Do the same for all the other rows in the file
        $line_num = 0;
        while ( $csv_file->valid() ) {
            $row = $csv_file->fgetcsv();    // automatically advances file pointer
            if ( $row === null || count($row) == 0 )
                continue;

            $line_num++;

            // Remove the completely blank columns from the original csv file so they don't get
            //  printed to the temporary csv file...
            foreach ($blank_columns as $num => $column_id)
                unset( $row[$column_id] );
//print_r($row);

            // If any column in this row had an invalid UTF-8 character...
            if ( isset($encoding_errors[$line_num]) ) {
                // ...assume that the original file was encoded with either the windows-1252 or the
                //  ISO-8859-1 encodings, and attempt to convert every column with an invalid UTF-8
                //  character to valid UTF-8
                // TODO - this "works" for right now because ODR is targeted towards English users...may need a more comprehensive system if that changes
                foreach ($encoding_errors[$line_num] as $column_id => $num)
                    $row[$column_id] = Encoding::toUTF8($row[$column_id]);
            }

            // Don't print the completely blank rows from the original csv file
            if ( !in_array($line_num, $blank_rows) )
                fputcsv($new_csv_file, $row, $delimiter);
        }

        // Move the contents of the temporary csv file back into the original csv file
        $csv_file = null;
        fclose($new_csv_file);

        rename($csv_import_path.$tmp_filename, $csv_import_path.$csv_filename);

        if ( count($encoding_errors) > 0 )
            return true;    // file had encoding errors
        else
            return false;   // file did not have encoding errors
    }


    /**
     * Creates a response to redownload the most recently uploaded CSV file from this user.
     *
     * @param Request $request
     *
     * @return Response|StreamedResponse
     */
    public function redownloadfileAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $response = new StreamedResponse();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_id = $user->getId();

            // Attempt to load the previously uploaded csv file
            $session = $request->getSession();
            if ( !$session->has('csv_file') )
                throw new ODRBadRequestException('No CSV file uploaded');
            if ( !$session->has('csv_delimiter') )
                throw new ODRBadRequestException('No delimiter set');

            $csv_import_path = $this->getParameter('odr_web_directory').'/uploads/csv/user_'.$user_id.'/';
            $csv_filename = $session->get('csv_file');
            $absolute_filepath = $csv_import_path.$csv_filename;

            $handle = fopen($absolute_filepath, 'r');
            if ($handle === false)
                throw new ODRException('Unable to open existing file at "'.$absolute_filepath.'"');

            // Attach the original filename to the download
            $display_filename = substr($csv_filename, 0, strrpos($csv_filename, '.'));

            // Set up a response to send the file back
            $response->setPrivate();
            $response->headers->set('Content-Type', mime_content_type($absolute_filepath));
            $response->headers->set('Content-Length', filesize($absolute_filepath));
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$display_filename.'";');

            // Have to specify all these properties just so that the last one can be false...otherwise
            //  Flow.js can't keep track of the progress
            $response->headers->setCookie(
                new Cookie(
                    'fileDownload', // name
                    'true',         // value
                    0,              // duration set to 'session'
                    '/',            // default path
                    null,           // default domain
                    false,          // don't require HTTPS
                    false           // allow cookie to be accessed outside HTTP protocol
                )
            );

            //$response->sendHeaders();

            // Use symfony's StreamedResponse to send the decrypted file back in chunks to the user
            $response->setCallback(function () use ($handle) {
                while (!feof($handle)) {
                    $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                    echo $buffer;
                    flush();
                }
                fclose($handle);
            });

            return $response;
        }
        catch (\Exception $e) {
            $source = 0x81eae304;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Builds and returns a JSON list of the files that have been uploaded to the user's csv
     * storage directory
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
            // Don't need to check permissions
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Get all files in the given user's 'upload' directory
            $uploaded_files = array();
            $upload_directory = $this->getParameter('odr_web_directory').'/uploads/csv/user_'.$user->getId().'/storage';
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
            $source = 0x9b61078d;
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
            // Don't need to check permissions
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Get all files in the given user's 'upload' directory
            $uploaded_files = array();
            $upload_directory = $this->getParameter('odr_web_directory').'/uploads/csv/user_'.$user->getId().'/storage';
            if ( file_exists($upload_directory) )
                $uploaded_files = scandir($upload_directory);

            // Don't delete the default linux directory pointers...
            foreach ($uploaded_files as $num => $filename) {
                if ($filename !== '.' && $filename !== '..')
                    unlink( $upload_directory.'/'.$filename );
            }
        }
        catch (\Exception $e) {
            $source = 0x15cde5cc;
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
     * Deletes any csv-specific data from the user's session, and also deletes any csv file they
     * uploaded.
     *
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $session = $request->getSession();
            if ( $session->has('csv_file') ) {
                // Delete the file if it exists
                $filename = $session->get('csv_file');
                $csv_import_path = $this->getParameter('odr_web_directory').'/uploads/csv/user_'.$user->getId().'/';
                if ( file_exists($csv_import_path.$filename) )
                    unlink($csv_import_path.$filename);

                // Delete csv-specific data from the user's session
                $session->remove('csv_file');
                $session->remove('csv_delimiter');
            }

            // The page will reload itself to reset the HTML
        }
        catch (\Exception $e) {
            $source = 0x336d4581;
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
     * Reads a $_POST request for importing a CSV file, and creates a beanstalk job to validate
     * each line in the file.
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
//exit( '<pre>'.print_r($post, true).'</pre>' );

            if ( !isset($post['datatype_id']) && !is_numeric($post['datatype_id']) )
                throw new ODRException('Invalid Form');
            $datatype_id = intval($post['datatype_id']);

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
            // Get secondary delimiters to use for tag/file/image/multiple select/radio columns, if they exist
            $column_delimiters = array();
            if ( isset($post['column_delimiters']) )
                $column_delimiters = $post['column_delimiters'];
            // Tag columns may also need hierarchy delimiters...
            $hierarchy_delimiters = array();
            if ( isset($post['hierarchy_delimiters']) )
                $hierarchy_delimiters = $post['hierarchy_delimiters'];

            // Get the file/image columns where all files/images in the datafield but not in the csv file will be deleted
            $synch_columns = array();
            if ( isset($post['synch_columns']) )
                $synch_columns = $post['synch_columns'];

            // If the import is for a child or linked datatype, then one of the columns from the
            //  csv file has to be mapped to the parent (or local if linked import) datatype's
            //  external id datafield
            $parent_datatype_id = '';
            if ( isset($post['parent_datatype_id']) ) {
                if ( !is_numeric($post['parent_datatype_id']) )
                    throw new ODRException('Invalid Form');
                else
                    $parent_datatype_id = intval($post['parent_datatype_id']);
            }
            $parent_external_id_column = '';    // Needs to be empty string instead of null because it's passed into the database and over cURL
            if ( isset($post['parent_external_id_column']) ) {
                if ( !is_numeric($post['parent_external_id_column']) )
                    throw new ODRException('Invalid Form');
                else
                    $parent_external_id_column = intval($post['parent_external_id_column']);
            }

            // If the import is for a linked datatype, then another column from the csv file also
            //  has to be mapped to the remote datatype's external id datafield
            $remote_external_id_column = '';
            if ( isset($post['remote_external_id_column']) ) {
                $remote_external_id_column = $post['remote_external_id_column'];

                if ( $remote_external_id_column !== '' && !is_numeric($remote_external_id_column) )
                    throw new ODRException('Invalid Form');
                else
                    $remote_external_id_column = intval($remote_external_id_column);
            }


            // ----------------------------------------
            // Load symfony objects
            $session = $request->getSession();
            $pheanstalk = $this->get('pheanstalk');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');

            $url = $this->generateUrl('odr_csv_import_validate', array(), UrlGeneratorInterface::ABSOLUTE_URL);

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');


            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Need to store fieldtype ids and fieldtype typenames
            /** @var FieldType[] $fieldtypes */
            $fieldtypes = $repo_fieldtype->findAll();
            $allowed_fieldtypes = array();
            foreach ($fieldtypes as $ft) {
                $typename = $ft->getTypeName();

                if ($typename === 'Markdown') {
                    // Can't import into a Markdown fieldtype, do nothing
                }
                else {
                    $allowed_fieldtypes[ $ft->getId() ] = $typename;
                }
            }


            // ----------------------------------------
            // Load required datatype entities
            // None of these should ever fail, since this is only called via beanstalk
            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new ODRException('Invalid Form...Datatype is deleted');

            /** @var DataType|null $parent_datatype */
            $parent_datatype = null;
            if ($parent_datatype_id !== '') {
                $parent_datatype = $repo_datatype->find($parent_datatype_id);

                if ($parent_datatype == null)
                    throw new ODRException('Invalid Form...Parent Datatype is deleted');
            }

            // If importing into top-level dataype, $datatype is the top-level datatype and $parent_datatype is null
            $import_into_top_level = false;
            if ($parent_datatype == null)
                $import_into_top_level = true;

            // If importing into child datatype, $datatype is the child datatype and $parent_datatype is $datatype's parent
            $import_into_child_datatype = false;
            if (!$import_into_top_level && $datatype->getParent()->getId() == $parent_datatype_id)
                $import_into_child_datatype = true;

            // If importing linked datatype, $datatype is the remote datatype and $parent_datatype is the local datatype
            $import_as_linked_datatype = false;
            if (!$import_into_top_level && $datatype->getParent()->getId() !== $parent_datatype_id)
                $import_as_linked_datatype = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )    // TODO - less restrictive permissions?
                throw new ODRForbiddenException();
            // --------------------

            // This doesn't make sense on a master template...
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to import into a master template');
            // ...or a metadata datatype
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to import into a metadata datatype');


            // ----------------------------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // ----------------------------------------
            // These need to be set, obviously...
            if ( !$session->has('csv_file') )
                throw new ODRBadRequestException('No CSV file uploaded');
            if ( !$session->has('csv_delimiter') )
                throw new ODRBadRequestException('No delimiter set');

            $csv_delimiter = $session->get('csv_delimiter');

            // Need to keep track of which columns are files/images
            $file_columns = array();
            // Also need to keep track of any tags that will be created...
            $new_tags = array();

            // Ensure that the datatype/fieldtype mappings and secondary column delimiters aren't
            //  mismatched or missing
            foreach ($datafield_mapping as $col_num => $datafield_id) {
                if ($datafield_id == 'new') {
                    // Since a new datafield will be created, ensure fieldtype exists
                    if ( $fieldtype_mapping == null )
                        throw new ODRException('Invalid Form...no fieldtype_mapping');
                    if ( !isset($fieldtype_mapping[$col_num]) )
                        throw new ODRException('Invalid Form...$fieldtype_mapping['.$col_num.'] not set');

                    // If new datafield is multiple select/radio, or file/image, or tags...ensure
                    //  secondary delimiters exist
                    $fieldtype_id = $fieldtype_mapping[$col_num];
                    if ( !isset($allowed_fieldtypes[$fieldtype_id]) )
                        throw new ODRBadRequestException('Invalid Form...attempt to import into fieldtype '.$fieldtype_id);

                    $typename = $allowed_fieldtypes[$fieldtype_id];
                    if ( $typename == "Multiple Radio"
                        || $typename == "Multiple Select"
                        || $typename == "File"
                        || $typename == "Image"
                        || $typename == "Tags"
                    ) {
                        if ( $column_delimiters == null )
                            throw new ODRException('Invalid Form a...no column_delimiters');
                        if ( !isset($column_delimiters[$col_num]) )
                            throw new ODRException('Invalid Form a...$column_delimiters['.$col_num.'] not set');

                        // Keep track of file/image columns...
                        if ($typename == "File" || $typename == "Image")
                            $file_columns[] = $col_num;

                        // Assume that a newly created tag field is going to need a hierarchy delimiter...
                        if ( $typename == "Tags" && !isset($hierarchy_delimiters[$col_num]) ) {
                            throw new ODRException('Invalid Form a...$hierarchy_delimiters['.$col_num.'] not set');
                        }

                        // If this is an existing tag field...
                        if ( $typename == "Tags" ) {
                            // ...then the validation process will need to track which tags are being
                            //  selected so the start_worker process can create any new tags required
                            //  before the worker process starts selecting them
                            $new_tags[$col_num] = array();
                        }
                    }
                }
                else {
                    // Ensure datafield exists
                    /** @var DataFields $datafield */
                    $datafield = $repo_datafield->find($datafield_id);
                    if ($datafield == null)
                        throw new ODRException('Invalid Form...deleted DataField');

                    // Ensure fieldtype mapping entry exists
                    $fieldtype_id = $datafield->getFieldType()->getId();
                    if ( !isset($allowed_fieldtypes[$fieldtype_id]) )
                        throw new ODRBadRequestException('Invalid Form...attempt to import into fieldtype '.$fieldtype_id);

                    $fieldtype_mapping[$col_num] = $fieldtype_id;

                    // If datafield is multiple select/radio field, or file/image, or tags...ensure
                    //  secondary delimiters exist
                    $typename = $datafield->getFieldType()->getTypeName();
                    if ( $typename == "Multiple Select"
                        || $typename == "Multiple Radio"
                        || $typename == "File"
                        || $typename == "Image"
                        || $typename == "Tags"
                    ) {
                        if ( $column_delimiters == null )
                            throw new ODRException('Invalid Form b...no column_delimiters');
                        if ( !isset($column_delimiters[$col_num]) )
                            throw new ODRException('Invalid Form b...$column_delimiters['.$col_num.'] not set');

                        // Keep track of file/image columns...
                        if ($typename == "File" || $typename == "Image")
                            $file_columns[] = $col_num;

                        // Complain if no hierarchy delimiters are specified for a tag field that
                        //  needs them
                        if ( $typename == "Tags"
                            && $datafield->getTagsAllowMultipleLevels()
                            && !isset($hierarchy_delimiters[$col_num])
                        ) {
                            throw new ODRException('Invalid Form b...$hierarchy_delimiters['.$col_num.'] not set');
                        }

                        // If this is an existing tag field...
                        if ( $typename == "Tags" ) {
                            // ...then the validation process will need to track which tags are being
                            //  selected so the start_worker process can create any new tags required
                            //  before the worker process starts selecting them
                            $new_tags[$col_num] = array();
                        }
                    }
                }
            }

            // If importing into a child datatype, then $remote_external_column_id needs to be set
            //  to the child datatype's external id field if it has one
            if ($import_into_child_datatype && $datatype->getExternalIdField() != null) {
                // ...check the datafield mapping to see if the user mapped the child datatype's
                //  external id field to a column in the CSV file
                $child_datatype_external_id = $datatype->getExternalIdField()->getId();

                $remote_external_id_column = array_search($child_datatype_external_id, $datafield_mapping);
                if ($remote_external_id_column == false)    // array_search failed to find a mapping
                    $remote_external_id_column = '';
            }

//return;

            // ----------------------------------------
            // Verify any secondary delimiters
            foreach ($column_delimiters as $col_num => $delimiter) {
                $delimiter = trim($delimiter);
                if ($delimiter === 'space')
                    $delimiter = ' ';

                $column_delimiters[$col_num] = $delimiter;

                if ( $delimiter === '' || strlen($delimiter) > 3 )
                    throw new ODRBadRequestException('Invalid delimiter "'.$delimiter.'" for column '.$col_num);
                if ( strpos($delimiter, $csv_delimiter) !== false )
                    throw new ODRBadRequestException('Secondary delimiter "'.$delimiter.'" for column '.$col_num.' contains csv delimiter');
            }
            // Verify any tag hierarchy delimiters
            foreach ($hierarchy_delimiters as $col_num => $delimiter) {
                $delimiter = trim($delimiter);
                $hierarchy_delimiters[$col_num] = $delimiter;

                if ( $delimiter === '' || strlen($delimiter) > 3 )
                    throw new ODRBadRequestException('Invalid hierarchy delimiter "'.$delimiter.'" for column '.$col_num);
                if ( strpos($delimiter, $csv_delimiter) !== false )
                    throw new ODRBadRequestException('Hierarchy delimiter "'.$delimiter.'" for column '.$col_num.' contains csv delimiter');

                if ( !isset($column_delimiters[$col_num]) )
                    throw new ODRBadRequestException('column '.$col_num.' has hierarchy delimiter, but not secondary delimiter');
                if ( strpos($hierarchy_delimiters[$col_num], $column_delimiters[$col_num]) !== false)
                    throw new ODRBadRequestException('Hierarchy delimiter contains secondary delimiter for column '.$col_num);
                if ( strpos($column_delimiters[$col_num], $hierarchy_delimiters[$col_num]) !== false)
                    throw new ODRBadRequestException('Secondary delimiter contains hierarchy delimiter for column '.$col_num);
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
            $csv_import_path = $this->getParameter('odr_web_directory').'/uploads/csv/user_'.$user->getId().'/';
            $csv_filename = $session->get('csv_file');
            $delimiter = $session->get('csv_delimiter');

            if ( !file_exists($csv_import_path.$csv_filename) )
                throw new ODRException('Target CSV File does not exist');

            // Don't need to check whether the file needs to be rewritten

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
                'hierarchy_delimiters' => $hierarchy_delimiters,
                'synch_columns' => $synch_columns,

                // Only used when importing into a child or linked datatype
                'parent_external_id_column' => $parent_external_id_column,
                'parent_datatype_id' => $parent_datatype_id,

                // Will have a value if importing into a linked datatype, or a child datatype that has its external column mapped
                'remote_external_id_column' => $remote_external_id_column,

                // Store any existing tags from datafields being imported into
                'new_tags' => $new_tags,
            );

//exit( '<pre>'.print_r($additional_data, true).'</pre>' );

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
                        $filename = trim($filename);

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
                        else if ($filename !== '') {
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
                    /* any number of child/linked datarecords are allowed, no need to do validate here */
                }
                else {
                    // Since only a single child/linked datarecord is allowed, and the importer will create a link or a child datarecord for each line of the csv file...
                    // ...build a list of datarecords that already have their single child/linked datarecord

                    $parent_typeclass = $parent_datatype->getExternalIdField()->getFieldType()->getTypeClass();
                    $rel_type_noun = '';
                    $rel_type_adj = '';

                    $results = array();
                    if ($import_into_child_datatype) {
                        if ($remote_external_id_column == '') {
                            // Grab a list of all child datarecords grouped by parent datarecord
                            $query = $em->createQuery(
                               'SELECT e.value AS parent_external_id, child.id AS child_external_id
                                FROM ODRAdminBundle:'.$parent_typeclass.' AS e
                                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                                JOIN ODRAdminBundle:DataRecord AS parent WITH drf.dataRecord = parent
                                JOIN ODRAdminBundle:DataRecord AS child WITH child.parent = parent
                                WHERE drf.dataField = :parent_external_id_field AND child.dataType = :child_datatype
                                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND parent.deletedAt IS NULL AND child.deletedAt IS NULL'
                            )->setParameters(array('parent_external_id_field' => $parent_datatype->getExternalIdField()->getId(), 'child_datatype' => $datatype->getId()));
                            $results = $query->getArrayResult();
                        }
                        else {
                            // Grab a list of the external id value for the child datarecords, grouped by parent datarecord
                            $child_typeclass = $datatype->getExternalIdField()->getFieldType()->getTypeClass();

                            $query = $em->createQuery(
                               'SELECT e_1.value AS parent_external_id, e_2.value AS child_external_id
                                FROM ODRAdminBundle:'.$parent_typeclass.' AS e_1
                                JOIN ODRAdminBundle:DataRecordFields AS drf_1 WITH e_1.dataRecordFields = drf_1
                                JOIN ODRAdminBundle:DataRecord AS parent WITH drf_1.dataRecord = parent
                                JOIN ODRAdminBundle:DataRecord AS child WITH child.parent = parent
                                JOIN ODRAdminBundle:DataRecordFields AS drf_2 WITH drf_2.dataRecord = child
                                JOIN ODRAdminBundle:'.$child_typeclass.' AS e_2 WITH e_2.dataRecordFields = drf_2
                                WHERE drf_1.dataField = :parent_external_id_field AND drf_2.dataField = :remote_external_id_field
                                AND e_1.deletedAt IS NULL AND drf_1.deletedAt IS NULL AND parent.deletedAt IS NULL
                                AND child.deletedAt IS NULL AND drf_2.deletedAt IS NULL AND e_2.deletedAt IS NULL'
                            )->setParameters(array('parent_external_id_field' => $parent_datatype->getExternalIdField()->getId(), 'remote_external_id_field' => $datatype->getExternalIdField()->getId()));
                            $results = $query->getArrayResult();
                        }

                        $rel_type_noun = 'child';
                        $rel_type_adj = 'child';
                    }
                    else if ($import_as_linked_datatype) {
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
                    else {
                        throw new ODRException('Parameter mismatching going on...aborting import');
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
                        // Skip header row
                        $line_num++;
                        if ($line_num == 1)
                            continue;

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
                            if ($remote_external_id_column == '') {
                                // The child datatype does not have an external ID field assigned
                                // Since the child datatype only allows a single child datarecord, the importer can assume that the user wants to overwrite its contents
                                $errors[] = array(
                                    'level' => 'Warning',
                                    'body' => array(
                                        'line_num' => $line_num,
                                        'message' => 'The Datarecord pointed to by the external ID "'.$value.'" already has its single permitted '.$rel_type_adj.' Datarecord of the Datatype "'.$datatype->getShortName().'"...running the import will overwrite the contents of the existing '.$rel_type_adj.' Datarecord',
                                    ),
                                );
                            }
                            else if ( trim( $row[$remote_external_id_column] ) != $datatree[$value] ) {
                                // The child/linked datatype does have an external ID field assigned
                                // However, the value for the child/linked datarecord's external ID in the CSV file doesn't match the value in the database...so it makes sense to refuse to continue here
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
                        'redis_prefix' => $redis_prefix,    // debug purposes only

                        // Only used when importing into a top-level or child datatype
                        'unique_columns' => $unique_columns,
                        'datafield_mapping' => $datafield_mapping,
                        'fieldtype_mapping' => $fieldtype_mapping,
                        'column_delimiters' => $column_delimiters,
                        'hierarchy_delimiters' => $hierarchy_delimiters,
                        'synch_columns' => $synch_columns,

                        // Only used when importing into a child/linked datatype
                        'parent_external_id_column' => $parent_external_id_column,
                        'parent_datatype_id' => $parent_datatype_id,

                        // Will have a value if importing into a linked datatype, or a child datatype that has its external column mapped
                        'remote_external_id_column' => $remote_external_id_column,
                    )
                );

                $pheanstalk->useTube('csv_import_validate')->put($payload);
            }

            $return['d'] = array('tracked_job_id' => $tracked_job_id);
        }
        catch (\Exception $e) {
            $source = 0xfa64ef3c;
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

        if ($parent_external_id_column === '') {
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

            if ( !isset($post['tracked_job_id'])
                || !isset($post['datatype_id'])
                || !isset($post['user_id'])
                || !isset($post['column_names'])
                || !isset($post['line_num'])
                || !isset($post['line'])
                || !isset($post['api_key'])
            ) {
                throw new ODRException('Invalid job data');
            }

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            if ( $tracked_job_id === -1 )
                throw new ODRException('Invalid tracked job id');

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
            $hierarchy_delimiters = array();
            if ( isset($post['hierarchy_delimiters']) )
                $hierarchy_delimiters = $post['hierarchy_delimiters'];
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

            // If the import is for a child or linked datatype, then another column from the csv
            //  file also has to be mapped to the child/remote datatype's external id datafield
            $remote_external_id_column = '';
            if ( isset($post['remote_external_id_column']) )
                $remote_external_id_column = $post['remote_external_id_column'];


            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');

            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

            if ($api_key !== $beanstalk_api_key)
                throw new ODRException('Invalid job data');


            /** @var ODRUser $user */
            $user = $repo_user->find($user_id);
            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new ODRException('Datatype is deleted!');
            // This doesn't make sense on a master datatype
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to import into a master template');


            /** @var TrackedJob $tracked_job */
            $tracked_job = $repo_tracked_job->find($tracked_job_id);
            if ( $tracked_job == null )
                throw new ODRBadRequestException('Invalid tracked job');

            // Only (currently) care about whether the import action will create new tags or not...
            $additional_data = $tracked_job->getAdditionalData();
            $new_tags = $additional_data['new_tags'];


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
                    throw new ODRException('Invalid Form');

                $parent_external_id_field = $parent_datatype->getExternalIdField();
                $parent_external_id_value = trim( $line[$parent_external_id_column] );
                $dr = $dri_service->getDatarecordByExternalId($parent_external_id_field, $parent_external_id_value);

                // If a parent with this external id does not exist, warn the user (the row will be ignored)
                // Don't want to throw an error here (which will prevent the import from happening)
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
            // If importing into top-level dataype, $datatype is the top-level datatype and $parent_datatype is null
            $import_into_top_level = false;
            if ($parent_datatype == null)
                $import_into_top_level = true;

            // If importing into child datatype, $datatype is the child datatype and $parent_datatype is $datatype's parent
            $import_into_child_datatype = false;
            if (!$import_into_top_level && $datatype->getParent()->getId() == $parent_datatype->getId())
                $import_into_child_datatype = true;

            // If importing linked datatype, $datatype is the remote datatype and $parent_datatype is the local datatype
            $import_as_linked_datatype = false;
            if (!$import_into_top_level && $datatype->getParent()->getId() !== $parent_datatype->getId())
                $import_as_linked_datatype = true;
            // ----------------------------------------


            // ----------------------------------------
            // Attempt to locate the datarecord that this row of data will import into...

            // If importing into top-level dataype, $datarecord_id will be for a top-level datarecord
            // If importing into child datatype, $datarecord_id will point to the child datarecord...its parent datarecord was located earlier
            // If "importing" linked datatype, $datarecord_id will point to the remote datarecord...the local datarecord was located earlier
            $datarecord_id = null;
            $external_id_field = $datatype->getExternalIdField();

            // Don't need to validate anything related to external ID fields if not using them...
            if ($external_id_field != null) {
                // Locate the expected external ID value
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
                    // Target datarecord is on the child/remote side of the relationship...find the external ID from $remote_external_id_column
                    $value = trim( $line[$remote_external_id_column] );
                }


                if ($import_into_top_level) {
                    // Importing into top-level datatype...attempt to locate the top-level datarecord
                    $dr = $dri_service->getDatarecordByExternalId($external_id_field, $value);
                    if ($dr !== null)
                        $datarecord_id = $dr->getId();

                    // Doesn't matter if the top-level datarecord is missing, a new one can be created
                }
                else if ($import_as_linked_datatype) {
                    // Importing into linked datatype...attempt to locate the remote datarecord
                    $dr = $dri_service->getDatarecordByExternalId($external_id_field, $value);
                    if ($dr !== null)
                        $datarecord_id = $dr->getId();

                    // The remote datarecord MUST exist...can't link to a non-existant datarecord
                    if ($datarecord_id == null) {
                        $errors[] = array(
                            'level' => 'Error',
                            'body' => array(
                                'line_num' => $line_num,
                                'message' => 'The value "'.$value.'" in column "'.$column_names[$remote_external_id_column].'" is supposed to match the external ID of a Datarecord in the Datatype "'.$datatype->getShortName().'", but no such Datarecord exists',
                            ),
                        );
                    }
                }
                else if ($import_into_child_datatype) {
                    // Importing into child datatype...attempt to locate the child datarecord
                    $parent_external_id = trim( $line[$parent_external_id_column] );
                    $dr = $dri_service->getChildDatarecordByExternalId($external_id_field, $value, $parent_datatype->getExternalIdField(), $parent_external_id);
                    if ($dr !== null)
                        $datarecord_id = $dr->getId();

                    // Doesn't matter if the child datarecord is missing, a new one can be created

                    // This function already checked whether the parent datarecord existed, and has
                    //  already generated a warning if it doesn't
                }
                else {
                    // Don't think this can even happen, but don't continue if it does
                    $errors[] = array(
                        'level' => 'Error',
                        'body' => array(
                            'line_num' => $line_num,
                            'message' => 'Parameter mismatch...somehow not importing into anything (top-level/child/linked datatype)??',
                        ),
                    );
                }
            }


            // For the purposes of checking files/images in a child datarecord...
            if ($external_id_field == null && $import_into_child_datatype) {
                /** @var DataTree $datatree */
                $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy( array('ancestor' => $parent_datatype->getId(), 'descendant' => $datatype->getId()) );
                if ($datatree == null) {
                    $errors[] = array(
                        'level' => 'Error',
                        'body' => array(
                            'line_num' => $line_num,
                            'message' => 'Parameter mismatch...datatree entry does not exist??',
                        ),
                    );
                }

                // Doesn't make sense to locate something in a multiple-allowed child datatype that doesn't have an external ID...
                if ($datatree->getMultipleAllowed() == false) {
                    $dr = $dri_service->getSingleChildDatarecordByParent($datatype, $parent_external_id_field, $parent_external_id_value);
                    if ($dr !== null)
                        $datarecord_id = $dr->getId();
                }
            }


            // ----------------------------------------
            // Attempt to validate each line of data against the desired datafield/fieldtype mapping
            foreach ($datafield_mapping as $column_num => $datafield_id) {
                $value = trim( $line[$column_num] );
                $length = mb_strlen($value, "utf-8");

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
                            $upload_dir = $this->getParameter('odr_web_directory').'/uploads/csv/user_'.$user_id.'/storage/';

                            // Grab a list of the files already uploaded to this datafield
                            $already_uploaded_files = array();
                            if ($datarecord_id !== null) {
                                $query_str =
                                   'SELECT em.originalFileName
                                    FROM ODRAdminBundle:'.$typeclass.' AS e
                                    JOIN ODRAdminBundle:'.$typeclass.'Meta AS em WITH em.'.strtolower($typeclass).' = e
                                    WHERE e.dataRecord = :datarecord AND e.dataField = :datafield ';
                                if ($typeclass == 'Image')
                                    $query_str .= 'AND e.original = 1 ';
                                $query_str .= 'AND e.deletedAt IS NULL AND em.deletedAt IS NULL';

                                $query = $em->createQuery($query_str)->setParameters( array('datarecord' => $datarecord_id, 'datafield' => $datafield_id) );
                                $results = $query->getArrayResult();

                                foreach ($results as $tmp => $result)
                                    $already_uploaded_files[] = $result['originalFileName'];
                            }

                            // Grab all filenames listed in the field
                            $filenames = explode( $column_delimiters[$column_num], $value );
                            $total_file_count = count($filenames) + count($already_uploaded_files);
                            foreach ($filenames as $filename) {
                                // Don't attempt to upload files with no name
                                $filename = trim($filename);
                                if ($filename === '')
                                    continue;

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
                                    if ( count($validation_params['mimeTypes']) > 0 && !in_array($uploaded_file->getMimeType(), $validation_params['mimeTypes']) ) {
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
                        if ( !ValidUtility::isValidInteger($value) ) {
                            // Warn about invalid characters in an integer conversion
                            $errors[] = array(
                                'level' => 'Warning',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'": the value "'.$value.'" is not a proper integer value, and will be converted to "'.intval($value).'"'
                                )
                            );
                        }
                        break;
                    case "DecimalValue":
                        if ( !ValidUtility::isValidDecimal($value) ) {
                            $errors[] = array(
                                'level' => 'Warning',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'": the value "'.$value.'" is not a proper decimal value, and will be converted to "'.floatval($value).'"'
                                ),
                            );
                        }
                        break;

                    case "DatetimeValue":
                        // TODO - make this use ValidUtility?  it also doesn't exactly match the actual import logic...
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
                        if ( !ValidUtility::isValidShortVarchar($value) ) {
                            $errors[] = array(
                                'level' => 'Warning',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" is '.$length.' characters long, but a ShortVarchar field can only store up to 32 characters',
                                ),
                            );
                        }
                        break;
                    case "MediumVarchar":
                        if ( !ValidUtility::isValidMediumVarchar($value) ) {
                            $errors[] = array(
                                'level' => 'Warning',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" is '.$length.' characters long, but a MediumVarchar field can only store up to 64 characters',
                                ),
                            );
                        }
                        break;
                    case "LongVarchar":
                        if ( !ValidUtility::isValidLongVarchar($value) ) {
                            $errors[] = array(
                                'level' => 'Warning',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" is '.$length.' characters long, but a LongVarchar field can only store up to 255 characters',
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
                            // Due to validation in self::processAction(), this will exist when the
                            //  datafield is a multiple select/radio...it won't exist for a single
                            //  select/radio

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
                                else if ( $option_length > 255 ) {
                                    $errors[] = array(
                                        'level' => 'Warning',
                                        'body' => array(
                                            'line_num' => $line_num,
                                            'message' => 'Column "'.$column_names[$column_num].'" has a Radio Option that is '.$length.' characters long, but the maximum length allowed is 255 characters',
                                        ),
                                    );
                                }
                            }
                        }
                        else {
                            // Check length of option
                            if ($length > 255) {
                                $errors[] = array(
                                    'level' => 'Warning',
                                    'body' => array(
                                        'line_num' => $line_num,
                                        'message' => 'Column "'.$column_names[$column_num].'" has a Radio Option that is '.$length.' characters long, but the maximum length allowed is 255 characters',
                                    ),
                                );
                            }
                        }
                        break;

                    case "Tag":
                        // Don't attempt to validate an empty string...it just means there are no tags selected
                        if ( $value == '' )
                            break;

                        // The delimiter should always exist
                        $tags = explode($column_delimiters[$column_num], $value);

                        // Explode the column's value to extract every single individual tag
                        $all_tags = array();
                        foreach ($tags as $num => $tag_name) {
                            $tag_name = trim($tag_name);

                            if ( isset($hierarchy_delimiters[$column_num]) ) {
                                $exploded_tags = explode( $hierarchy_delimiters[$column_num], $tag_name );
                                foreach ($exploded_tags as $num => $exploded_tag_name) {
                                    $exploded_tag_name = trim($exploded_tag_name);
                                    $all_tags[$exploded_tag_name] = 1;
                                }
                            }
                            else {
                                $all_tags[$tag_name] = 1;
                            }
                        }

                        // Ensure all of the tags mentioned in this field are neither blank nor
                        //  longer than 255 characters
                        foreach ($all_tags as $tag_name => $num) {
                            if ( trim($tag_name) === '' ) {
                                $errors[] = array(
                                    'level' => 'Warning',
                                    'body' => array(
                                        'line_num' => $line_num,
                                        'message' => 'Column "'.$column_names[$column_num].'" would create a blank tag during import...',
                                    ),
                                );
                            }
                            else if ( mb_strlen($tag_name, "utf-8") > 255 ) {
                                $errors[] = array(
                                    'level' => 'Warning',
                                    'body' => array(
                                        'line_num' => $line_num,
                                        'message' => 'Column "'.$column_names[$column_num].'" has a Tag that is '.$length.' characters long, but the maximum length allowed is 255 characters',
                                    ),
                                );
                            }
                        }

                        // Store the list of potential new tags for persisting back into the
                        // tracked job entity
                        $tags_in_column = $new_tags[$column_num];
                        foreach ($tags as $num => $tag_name) {
                            $tag_name = trim($tag_name);
                            $tags_in_column[$tag_name] = 1;
                        }
                        $new_tags[$column_num] = $tags_in_column;

                        // NOTE - not able to determine whether the column is attempting to "select"
                        //  a mid-level tag without knowing the complete tag structure beforehand...
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
            // Update the additional_data segment of the tracked job with any new data
            $additional_data['new_tags'] = $new_tags;
            $tracked_job->setAdditionalData($additional_data);

            // Update the job tracker
            $total = $tracked_job->getTotal();
            $count = $tracked_job->incrementCurrent($em);

            if ($count >= $total)
                $tracked_job->setCompleted( new \DateTime() );

            $em->persist($tracked_job);
            $em->flush();

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

            // Since this is only called via beanstalk, return exceptions as json
            $request->setRequestFormat('json');

            $source = 0xc2313827;
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var TagHelperService $th_service */
            $th_service = $this->container->get('odr.tag_helper_service');


            // ----------------------------------------
            /** @var TrackedJob $tracked_job */
            $tracked_job = $repo_tracked_job->find($tracked_job_id);
            if ($tracked_job == null)
                throw new ODRNotFoundException('TrackedJob');
            if ( $tracked_job->getJobType() !== "csv_import_validate" )
                throw new ODRNotFoundException('TrackedJob');

            $presets = $tracked_job->getAdditionalData();
            $target_entity = $tracked_job->getTargetEntity();
            $tmp = explode('_', $target_entity);
            $datatype_id = $tmp[1];

            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )    // TODO - less restrictive permissions?
                throw new ODRForbiddenException();

            // TODO - permissions check may need to be more involved than just checking whether the user accessing this can edit the datatype...
            // --------------------

            // This doesn't make sense on a master template...
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to import into a master template');
            // ...or a metadata datatype
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to import into a metadata datatype');


            // ----------------------------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // ----------------------------------------
            // Load settings for import.html.twig and layout.html.twig from the data stored in the tracked job entity
            $parent_datatype_id = '';
            $parent_datatype = null;
            if ( isset($presets['parent_datatype_id']) && $presets['parent_datatype_id'] !== '' ) {
                $parent_datatype_id = $presets['parent_datatype_id'];

                /** @var DataType $parent_datatype */
                $parent_datatype = $repo_datatype->find($parent_datatype_id);
                if ($parent_datatype == null)
                    throw new ODRException('Invalid Form');
            }


            // Also locate any child or linked datatypes for this datatype
            $datatree_array = $dti_service->getDatatreeArray();
            /** @var DataType[]|null $childtypes */
            $childtypes = null;
            if ($parent_datatype_id !== '') {
                $childtypes = array();
                foreach ($datatree_array['descendant_of'] as $dt_id => $parent_dt_id) {
                    if ($parent_dt_id == $datatype_id) {
                        // Ensure user has permissions to modify this childtype before storing it
                        if (isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dr_edit']) ) {

                            // Only store the childtype if it doesn't have children of its own...
                            if ( !in_array($dt_id, $datatree_array['descendant_of']) )
                                $childtypes[] = $repo_datatype->find($dt_id);
                        }
                    }
                }
                if ( count($childtypes) == 0 )
                    $childtypes = null;
            }

            /** @var DataType[]|null $linked_types */
            $linked_types = null;
            if ($parent_datatype_id !== '') {
                $linked_types = array();
                foreach ($datatree_array['linked_from'] as $descendant_dt_id => $ancestor_ids) {
                    if ( in_array($datatype_id, $ancestor_ids) ) {
                        // Ensure user has permissions to modify this linked type before storing it
                        if (isset($datatype_permissions[$descendant_dt_id]) && isset($datatype_permissions[$descendant_dt_id]['dr_edit']) ) {
                            $linked_types[] = $repo_datatype->find($descendant_dt_id);
                        }
                    }
                }
                if ( count($linked_types) === 0 )
                    $linked_types = null;
            }


            // ----------------------------------------
            // If importing into top-level dataype, $datatype is the top-level datatype and $parent_datatype is null
            $import_into_top_level = false;
            if ($parent_datatype == null)
                $import_into_top_level = true;

            // If importing linked datatype, $datatype is the remote datatype and $parent_datatype is the local datatype
            $import_as_linked_datatype = false;
            if (!$import_into_top_level && $datatype->getParent()->getId() !== $parent_datatype_id)
                $import_as_linked_datatype = true;
            // ----------------------------------------


            // ----------------------------------------
            // Grab all datafields belonging to the correct datatype
            $datatype_array = $dti_service->getDatatypeArray($grandparent_datatype->getId(), false);    // don't load linked datatypes
            $datafields = $datatype_array[$datatype->getId()]['dataFields'];
            uasort($datafields, "self::name_sort");

            // Grab the FieldTypes that the csv importer can read data into
            /** @var FieldType[] $fieldtypes */
            $fieldtypes = $repo_fieldtype->findAll();
            $allowed_fieldtypes = array();

            foreach ($fieldtypes as $num => $fieldtype) {
                // Every field can be imported into except for Markdown fields
                $typename = $fieldtype->getTypeName();
                if ($typename === 'Markdown') {
                    unset( $fieldtypes[$num] );
                }
                else {
                    $allowed_fieldtypes[ $fieldtype->getId() ] = $fieldtype->getTypeName();
                }
            }

            // Also need to keep track of any tag datafields that allow parent/child tags...
            $multilevel_tag_datafields = array();
            foreach ($datafields as $df_id => $df) {
                if ( $df['dataFieldMeta']['tags_allow_multiple_levels'] == true )
                    $multilevel_tag_datafields[$df_id] = 1;
            }


            // ----------------------------------------
            // Read column names from the file
            $csv_import_path = $this->getParameter('odr_web_directory').'/uploads/csv/user_'.$user->getId().'/';
            $csv_filename = $presets['csv_filename'];
            $delimiter = $presets['delimiter'];

            if ( !file_exists($csv_import_path.$csv_filename) )
                throw new ODRException('Target CSV File does not exist');

            // Apparently SplFileObject doesn't do this before opening the file...
            ini_set('auto_detect_line_endings', TRUE);

            $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
            $reader = new CsvReader($csv_file, $delimiter);
            $reader->setHeaderRowNumber(0);     // want associative array
            $file_headers = $reader->getColumnHeaders();

            // TODO - In theory, this shouldn't rewrite the file, since it was called back in layoutAction()...
            // TODO - ...figure out a clean way to get column lengths without doing this?
            $column_lengths = array();
            $file_encoding_converted = self::trimCSVFile($csv_import_path, $csv_filename, $delimiter, $column_lengths);


            // ----------------------------------------
            // The validation process will have stored an array of every tag that will get selected
            //  during the import
            $new_tags = $presets['new_tags'];
            $datafield_mapping = $presets['datafield_mapping'];
            $hierarchy_delimiters = $presets['hierarchy_delimiters'];

            $new_tag_arrays = array();
            foreach ($new_tags as $col_num => $tag_list) {
                // If this column is for a new datafield...
                if ( $datafield_mapping[$col_num] === 'new' ) {
                    // ...then all these tags will have to be created, since they don't exist
                    $existing_tag_array = array();

                    // $would_create_new_tags is going to be true...
                    $would_create_new_tags = true;
                    foreach ($tag_list as $tag_name => $num) {
                        // Convert the tag into the correct format for insertTagsForListImport()...
                        $tag_names = array(0 => $tag_name);
                        if ( isset($hierarchy_delimiters[$col_num]) )
                            $tag_names = explode($hierarchy_delimiters[$col_num], $tag_name);
                        foreach ($tag_names as $num => $tag_name)
                            $tag_names[$num] = trim($tag_name);

                        $existing_tag_array = $th_service->insertTagsForListImport($existing_tag_array, $tag_names, $would_create_new_tags);
                    }

                    // The only point of this entire if statement is to render stuff for user
                    //  verification
                    $new_tag_arrays[$col_num] = $existing_tag_array;
                }
                else {
                    // ...otherwise, this is for an existing datafield
                    $df_id = $datafield_mapping[$col_num];

                    // Locate the tags already in the datafield
                    $existing_tag_array = $datafields[$df_id]['tags'];
                    $existing_tag_array = $th_service->convertTagsForListImport($existing_tag_array);

                    // Determine whether the tags to be selected will require any new tags
                    $would_create_new_tags = false;
                    foreach ($tag_list as $tag_name => $num) {
                        // Convert the tag into the correct format for insertTagsForListImport()...
                        $tag_names = array(0 => $tag_name);
                        if ( isset($hierarchy_delimiters[$col_num]) )
                            $tag_names = explode($hierarchy_delimiters[$col_num], $tag_name);
                        foreach ($tag_names as $num => $tag_name)
                            $tag_names[$num] = trim($tag_name);

                        $existing_tag_array = $th_service->insertTagsForListImport($existing_tag_array, $tag_names, $would_create_new_tags);
                    }

                    // Only care when some new tag is created
                    if ( $would_create_new_tags )
                        $new_tag_arrays[$col_num] = $existing_tag_array;
                }
            }

            // Need to render the end result of tag creation, so sort those fields by tag name
            foreach ($new_tag_arrays as $col_num => $tags) {
                $th_service->orderStackedTagArray($tags, true);
                $new_tag_arrays[$col_num] = $tags;
            }


            // ----------------------------------------
            // Get any errors reported during validation of this import
            $error_messages = parent::ODR_getTrackedErrorArray($em, $tracked_job_id);

            // TODO - since the complete tag structure is known by now, locate attempts to select mid-level tags?
            // TODO - ...attempts to do so should probably create a warning

            // If some sort of serious error encountered during validation, prevent importing
            $allow_import = true;
            foreach ($error_messages as $message) {
                if ( $message['error_level'] == 'Error' )
                    $allow_import = false;
            }

//exit( '<pre>'.print_r($error_messages, true).'</pre>' );
//exit( '<pre>'.print_r($presets, true).'</pre>' );

            // Render the page...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:CSVImport:import.html.twig',
                    array(
                        'datatype' => $datatype,    // as expected if importing into a top-level or child datatype, or equivalent to the remote datatype if importing links
                        'childtypes' => $childtypes,
                        'linked_types' => $linked_types,
                        'upload_type' => '',

                        'presets' => $presets,
                        'errors' => $error_messages,

                        'datatree_array' => $datatree_array,

                        'resulting_tag_arrays' => $new_tag_arrays,

                        // These get passed to layout.html.twig
                        'parent_datatype' => $parent_datatype,    // as expected if importing into a child datatype, or null if importing into top-level datatype, or equivalent to the local datatype if importing links
                        'linked_importing' => $import_as_linked_datatype,

                        'csv_delimiter' => $delimiter,
                        'columns' => $file_headers,
                        'column_lengths' => $column_lengths,
                        'datafields' => $datafields,
                        'fieldtypes' => $fieldtypes,

                        'allowed_fieldtypes' => $allowed_fieldtypes,
                        'multilevel_tag_datafields' => $multilevel_tag_datafields,

                        'tracked_job_id' => $tracked_job_id,
                        'allow_import' => $allow_import,

                        'file_encoding_converted' => false,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xee919ae8;
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
     * Given the id of a completed csv_import_validate job, begins the process of a csv import by
     * creating a beanstalk job to import each line in the csv file.
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

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var TagHelperService $th_service */
            $th_service = $this->container->get('odr.tag_helper_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');
            /** @var UUIDService $uuid_service */
            $uuid_service = $this->container->get('odr.uuid_service');


            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            // ----------------------------------------
            // Load the data from the finished validation job
            /** @var TrackedJob $validate_tracked_job */
            $validate_tracked_job = $repo_tracked_job->find($job_id);
            if ($validate_tracked_job->getCompleted() == null)
                throw new ODRException('Invalid job');

            $job_data = $validate_tracked_job->getAdditionalData();
            $target_entity = $validate_tracked_job->getTargetEntity();
            $tmp = explode('_', $target_entity);
            $datatype_id = $tmp[1];

            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )    // TODO - less restrictive permissions?
                throw new ODRForbiddenException();

            // TODO - permissions check may need to be more involved than just checking whether the user accessing this can edit the datatype...
            // --------------------

            // This doesn't make sense on a master template...
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to import into a master template');
            // ...or a metadata datatype
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to import into a metadata datatype');


            // ----------------------------------------
            // TODO - better way of handling this, if possible
            // Block csv imports if there's already one in progress for this datatype
            /** @var TrackedJob $tracked_job */
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import_validate', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('A CSV Import Validation for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('A CSV Import for this DataType is already in progress...multiple imports at the same time have the potential to completely break the DataType');
            // Also block if there's a datafield migration in place
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('One of the DataFields for this DataType is being migrated to a new FieldType...blocking CSV Imports to this DataType...');


            // ----------------------------------------
            // Read column names from the file
            $csv_import_path = $this->getParameter('odr_web_directory').'/uploads/csv/user_'.$user->getId().'/';
            $csv_filename = $job_data['csv_filename'];
            $delimiter = $job_data['delimiter'];

            if ( !file_exists($csv_import_path.$csv_filename) )
                throw new ODRException('Target CSV File does not exist');

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


            // Delete the original tracked Job (CSV Import Validate)
            $em->remove($validate_tracked_job);
            // TODO Not sure if the other flush will fire every time.  Probably should flush all at the end.
            $em->flush();


            $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
            $tracked_job_id = $tracked_job->getId();

            // Not going to need the TrackedError entries for this job anymore, get rid of them
            parent::ODR_deleteTrackedErrorsByJob($em, $job_id);


            // ----------------------------------------
            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');

            $redis_prefix = $this->container->getParameter('memcached_key_prefix');


            // Extract data from the tracked job
            $unique_columns = $job_data['unique_columns'];
            $datafield_mapping = $job_data['datafield_mapping'];
            $fieldtype_mapping = $job_data['fieldtype_mapping'];
            $column_delimiters = $job_data['column_delimiters'];
            $hierarchy_delimiters = $job_data['hierarchy_delimiters'];
            $synch_columns = $job_data['synch_columns'];
            $parent_external_id_column = $job_data['parent_external_id_column'];
            $parent_datatype_id = $job_data['parent_datatype_id'];
            $remote_external_id_column = $job_data['remote_external_id_column'];
            $new_tags = $job_data['new_tags'];

//print_r($job_data);  return;

            // ----------------------------------------
            // If importing into top-level dataype, $datatype is the top-level datatype and $parent_datatype_id is the empty string
            $import_into_top_level = false;
            if ($parent_datatype_id == '')
                $import_into_top_level = true;

            // If importing linked datatype, $datatype is the remote datatype and $parent_datatype is the local datatype
            $import_as_linked_datatype = false;
            if (!$import_into_top_level && $datatype->getParent()->getId() !== $parent_datatype_id)
                $import_as_linked_datatype = true;
            // ----------------------------------------


            // For readability, linking datarecords with csv importing uses a different controller action
            $url = $this->generateUrl('odr_csv_import_worker', array(), UrlGeneratorInterface::ABSOLUTE_URL);
            if ($import_as_linked_datatype)
                $url = $this->generateUrl('odr_csv_link_worker', array(), UrlGeneratorInterface::ABSOLUTE_URL);


            // ----------------------------------------
            // Store hydrated versions of datafields
            /** @var DataFields[] $hydrated_datafields */
            $hydrated_datafields = array();

            // Create any necessary datafields
            $new_datafields = array();
            $new_mapping = array();
            $created = false;
            /** @var RenderPlugin $render_plugin */
            $render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->findOneBy( array('pluginClassName' => 'odr_plugins.base.default') );
            foreach ($datafield_mapping as $column_id => $datafield_id) {
                $datafield = null;

                if ( is_numeric($datafield_id) ) {
                    // Load datafield from repository
                    /** @var DataFields $datafield */
                    $datafield = $repo_datafield->find($datafield_id);
                    if ($datafield == null)
                        throw new ODRException('Invalid Form');

                    // Store for later...
                    $hydrated_datafields[$datafield->getId()] = $datafield;

//print 'loaded existing datafield '.$datafield_id."\n";
                    $logger->notice('Using existing datafield '.$datafield->getId().' "'.$datafield->getFieldName().'" for csv import of datatype '.$datatype->getId().' by '.$user->getId());
                }
                else {  // $datafield_id == 'new'
                    // Grab desired fieldtype from post
                    if ( $fieldtype_mapping == null )
                        throw new ODRException('Invalid Form');

                    /** @var FieldType $fieldtype */
                    $fieldtype = $repo_fieldtype->find( $fieldtype_mapping[$column_id] );
                    if ($fieldtype == null)
                        throw new ODRException('Invalid Form');

                    // Create new datafield...not delaying flush on purpose, need datafield id...
                    $created = true;
                    $datafield = $ec_service->createDatafield($user, $datatype, $fieldtype, $render_plugin);

                    // Set the datafield's name
                    $datafield_meta = $datafield->getDataFieldMeta();
                    $datafield_meta->setFieldName( $column_names[$column_id] );

                    // Set whether it's supposed to be unique or not
                    if ( isset($unique_columns[$column_id]) )
                        $datafield_meta->setIsUnique(true);

                    // If a tags datafield, then have it default to allow multiple levels
                    if ($fieldtype->getTypeName() === 'Tags')
                        $datafield_meta->setTagsAllowMultipleLevels(true);

                    $em->persist($datafield_meta);

                    // Don't need to flush the datafieldMeta entry just yet...nothing needs it before
                    //  the theme_datafield gets flushed later on

                    $new_datafields[] = $datafield;

                    $logger->notice('Created new datafield '.$datafield->getId().' "'.$column_names[$column_id].'" for csv import of datatype '.$datatype->getId().' by '.$user->getId());
//print 'created new datafield of fieldtype "'.$fieldtype->getTypeName().'" with name "'.$column_names[$column_id].'"'."\n";
                }

                // Store ID of target datafield
                $new_mapping[$column_id] = $datafield->getId();
            }
            /** @var DataFields[] $new_datafields */

            if ($created) {
                // Since datafields were created for this import, create a new theme element and
                //  attach the new datafields to it
                $theme = $theme_service->getDatatypeMasterTheme($datatype->getId());
                $theme_element = $ec_service->createThemeElement($user, $theme, true);    // don't flush immediately...

                foreach ($new_datafields as $new_datafield) {
                    // Attach each of the previously created datafields to the new theme_element
                    $ec_service->createThemeDatafield($user, $theme_element, $new_datafield, true);    // don't flush immediately...

                    // If this is a newly created image datafield, ensure it has the required
                    //  ImageSizes entities
                    if ($new_datafield->getFieldType()->getTypeClass() == 'Image')
                        $ec_service->createImageSizes($user, $new_datafield, true);    // don't flush immediately...

                    // Also need to delete some search cache entries here...
                    $search_cache_service->onDatafieldCreate($new_datafield);

                    $hydrated_datafields[$new_datafield->getId()] = $new_datafield;
                }

                // Save all changes
                // TODO Is this needed here?
                $em->flush();

                // Update cached versions of datatype and master theme since new datafields were added
                $dti_service->updateDatatypeCacheEntry($datatype, $user);
                $theme_service->updateThemeCacheEntry($theme, $user);

                // Don't need to worry about datafield permissions here, those are taken care of
                //  inside $ec_service->createDatafield()
            }

/*
print 'datafield mapping: ';
print_r($new_mapping);
*/
//return;

            // ----------------------------------------
            // Create any needed tags based on the additional_data stored in the tracked job...
            $dt_array = $dti_service->getDatatypeArray($grandparent_datatype->getId(), false);
            $df_array = $dt_array[$datatype->getId()]['dataFields'];

            // The validation process will have stored an array of every tag that will get selected
            //  during the import
            $created_new_tags = false;
            $datafields_to_resort = array();
            foreach ($new_tags as $column_id => $tag_data) {
                // At this point, $new_tags contains every tag that will eventually be selected

                // Need to convert the existing tags in the datafield into a different format so
                //  new tags can be created more easily...
                $df_id = $new_mapping[$column_id];
                $df = $hydrated_datafields[$df_id];
                $stacked_tag_array = $df_array[$df_id]['tags'];
                $stacked_tag_array = $th_service->convertTagsForListImport($stacked_tag_array);

                // Going to need the hydrated versions of all tags for this datafield in order to
                //  properly create TagTree entries...
                $query = $em->createQuery(
                   'SELECT t
                    FROM ODRAdminBundle:Tags AS t
                    WHERE t.dataField = :datafield_id
                    AND t.deletedAt IS NULL'
                )->setParameters( array('datafield_id' => $df->getId()) );
                $results = $query->getResult();

                /** @var Tags[] $results */
                $hydrated_tag_array = array();
                foreach ($results as $tag) {
                    // Have to store by tag uuid because tag names aren't guaranteed to be unique
                    //  across the entire tree
                    $hydrated_tag_array[ $tag->getTagUuid() ] = $tag;
                }
                /** @var Tags[] $hydrated_tag_array */

                foreach ($tag_data as $tag_name => $num) {
                    // Convert the tag into the correct format for insertTagsForListImport()...
                    $tag_names = array(0 => $tag_name);
                    if ( isset($hierarchy_delimiters[$column_id]) )
                        $tag_names = explode($hierarchy_delimiters[$column_id], $tag_name);
                    foreach ($tag_names as $num => $tag_name)
                        $tag_names[$num] = trim($tag_name);

                    $stacked_tag_array = self::createTagsForListImport(
                        $em,           // Needed to persist new tag uuids and tag tree entries
                        $ec_service,   // Needed to create new tags
                        $uuid_service, // Needed to create new tags
                        $user,         // Needed to create new tags
                        $df,           // Needed to create new tags
                        $hydrated_tag_array,
                        $stacked_tag_array,
                        $tag_names,
                        $created_new_tags,
                        null    // This initial call is for top-level tags...they don't have a parent
                    );
                }

                if ( $df->getRadioOptionNameSort() === true)
                    $datafields_to_resort[] = $df;
            }

            // Only do these things when tags have been created
            if ($created_new_tags) {
                // Wipe the cached tag tree arrays...
                $cache_service->delete('cached_tag_tree_'.$grandparent_datatype->getId());
                $cache_service->delete('cached_template_tag_tree_'.$grandparent_datatype->getId());

                // Re-sort each datafield that needs it...
                foreach ($datafields_to_resort as $num => $df) {
                    // This function doesn't use cache entries, so it can be called prior to updateDatatypeCacheEntry()
                    $sort_service->sortTagsByName($user, $df);
                }

                // Update the cached version of the datatype
                $dti_service->updateDatatypeCacheEntry($datatype, $user);

                // Shouldn't need to worry about the search cache...
            }


            // ----------------------------------------
            // Re-read the csv file so a beanstalk job can be created for each line in the file
            $csv_import_path = $this->getParameter('odr_web_directory').'/uploads/csv/user_'.$user->getId().'/';
            $csv_filename = $job_data['csv_filename'];
            $delimiter = $job_data['delimiter'];

            if ( !file_exists($csv_import_path.$csv_filename) )
                throw new ODRException('Target CSV File does not exist');

            // Apparently SplFileObject doesn't do this before opening the file...
            ini_set('auto_detect_line_endings', TRUE);

            $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
            $reader = new CsvReader($csv_file, $delimiter);
//            $reader->setHeaderRowNumber(0);   // don't want associative array


            // TODO - (partially) rewrite file so it references tag ids instead of tag names?
            // TODO - do the same for radio options?  that wouldn't really change the amount of work needed...

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
                        'redis_prefix' => $redis_prefix,    // debug purposes only

                        'column_delimiters' => $column_delimiters,
                        'hierarchy_delimiters' => $hierarchy_delimiters,
                        'synch_columns' => $synch_columns,
                        'mapping' => $new_mapping,
                        'line' => $row,

                        // Only used when importing into a child/linked datatype
                        'parent_external_id_column' => $parent_external_id_column,
                        'parent_datatype_id' => $parent_datatype_id,

                        // Will have a value if importing into a linked datatype, or a child datatype that has its external column mapped
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

            $return['d'] = array("tracked_job_id" => $tracked_job_id);

        }
        catch (\Exception $e) {
            $source = 0x61dc8b30;
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
     * TODO -
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EntityCreationService $ec_service
     * @param UUIDService $uuid_service
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param Tags[] $hydrated_tag_array A flat array of all tags for this datafield, organized by
     *                                   their uuids
     * @param array $stacked_tag_array @see self::convertTagsForListImport()
     * @param array $posted_tags A flat array of the tag(s) that may end up being inserted into the
     *                           datafield...top level tag at index 0, its child at 1, etc
     * @param bool $created_new_tags Set to true when a tag is created
     * @param Tags|null $parent_tag
     *
     * @return array
     */
    private function createTagsForListImport($em, $ec_service, $uuid_service, $user, $datafield, &$hydrated_tag_array, &$stacked_tag_array, $posted_tags, &$created_new_tags, $parent_tag)
    {
        $current_tag = null;
        $tag_name = $posted_tags[0];
        if ( isset($stacked_tag_array[$tag_name]) ) {
            // This tag exists already
            $tag_uuid = $stacked_tag_array[$tag_name]['tagUuid'];
            $current_tag = $hydrated_tag_array[$tag_uuid];
        }
        else {
            // A tag with this name doesn't exist at this level...create a new tag for it
            $created_new_tags = true;

            $force_create = true;
            $delay_uuid = true;
            $current_tag = $ec_service->createTag($user, $datafield, $force_create, $tag_name, $delay_uuid);

            // Generate a new uuid for this tag...
            $new_tag_uuid = $uuid_service->generateTagUniqueId();
            $current_tag->setTagUuid($new_tag_uuid);
            $em->persist($current_tag);

            // Need to store the new stuff for later reference...
            $hydrated_tag_array[$new_tag_uuid] = $current_tag;

            $stacked_tag_array[$tag_name] = array(
                'id' => $new_tag_uuid,    // Don't really care what the ID is...only used for rendering
                'tagMeta' => array(
                    'tagName' => $tag_name
                ),
                'tagUuid' => $new_tag_uuid,
            );


            // If the parent tag isn't null, then this new tag also needs a new TagTree entry to
            //  insert it at the correct spot in the tag hierarchy
            if ( !is_null($parent_tag) ) {
                // TODO - ...createTagTree() needs a flush before, or the lock file doesn't have all the info it needs to lock properly
//                $ec_service->createTagTree($user, $parent_tag, $new_tag);

                $tag_tree = new TagTree();
                $tag_tree->setParent($parent_tag);
                $tag_tree->setChild($current_tag);

                $tag_tree->setCreatedBy($user);

                $em->persist($tag_tree);
            }
        }

        // If there are more children/grandchildren to the tag to add...
        if ( count($posted_tags) > 1 ) {
            // ...get any children the existing tag already has
            $existing_child_tags = array();
            if ( isset($stacked_tag_array[$tag_name]['children']) )
                $existing_child_tags = $stacked_tag_array[$tag_name]['children'];

            // This level has been processed, move on to its children
            $new_tags = array_slice($posted_tags, 1);
            $stacked_tag_array[$tag_name]['children'] = self::createTagsForListImport(
                $em,           // Needed to persist new tag uuids and tag tree entries
                $ec_service,   // Needed to create new tags
                $uuid_service, // Needed to create new tags
                $user,         // Needed to create new tags
                $datafield,    // Needed to create new tags
                $hydrated_tag_array,
                $existing_child_tags,
                $new_tags,
                $created_new_tags,
                $current_tag
            );
        }

        return $stacked_tag_array;
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
//exit( '<pre>'.print_r($post, true).'</pre>' );

            if ( !isset($post['tracked_job_id'])
                || !isset($post['mapping'])
                || !isset($post['line'])
                || !isset($post['datatype_id'])
                || !isset($post['user_id'])
                || !isset($post['api_key'])
            ) {
                throw new ODRException('Invalid job data');
            }

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
            $hierarchy_delimiters = null;
            if ( isset($post['hierarchy_delimiters']) )
                $hierarchy_delimiters = $post['hierarchy_delimiters'];
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

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_tags = $em->getRepository('ODRAdminBundle:Tags');
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');


            if ($api_key !== $beanstalk_api_key)
                throw new ODRException('Invalid Form');

            /** @var ODRUser $user */
            $user = $repo_user->find($user_id);
            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new ODRException('Invalid Form...Datatype is deleted');
            // This doesn't make sense on a master datatype
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to import into a master template');

            // Going to need the cached datatype array if tags are involved...
            $cached_dt_array = $dti_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);


            // ----------------------------------------
            // If importing into a child dataype, $parent_external_id_column must not be the empty string
            // It makes no sense to import into a child datarecord when the parent datarecord can't be identified
            $import_into_child_datatype = true;
            if ($parent_external_id_column == '')
                $import_into_child_datatype = false;


            // ----------------------------------------
            // Attempt to locate the child datarecord's parent, if neccessary
            $parent_datarecord = null;
            $parent_external_id_field = null;
            $parent_external_id_value = '';
            $multiple_allowed = false;

            if ($parent_external_id_column !== '') {
                // $datatype_id points to a child datatype
                $datatree_array = $dti_service->getDatatreeArray();

                // Locate the top-level datatype
                $parent_datatype_id = null;
                if ( isset($datatree_array['descendant_of'][$datatype_id]) )
                    $parent_datatype_id = $datatree_array['descendant_of'][$datatype_id];
                else
                    throw new ODRException('Invalid Datatype ID');

                // Find the datarecord pointed to by the value in $parent_external_id_column
                $parent_external_id_value = trim( $line[$parent_external_id_column] );

                /** @var DataType $parent_datatype */
                $parent_datatype = $repo_datatype->find($parent_datatype_id);
                $parent_external_id_field = $parent_datatype->getExternalIdField();
                if ($parent_external_id_field == null)
                    throw new ODRException('Parent datatype does not have an external id field');

                // Since this is importing into a child datatype, parent datarecord must exist
                // csvvalidateAction() purposely only gives a warning so the user is not prevented from importing the rest of the file
                $parent_datarecord = $dri_service->getDatarecordByExternalId($parent_external_id_field, $parent_external_id_value);
                if ($parent_datarecord == null)
                    throw new ODRException('Parent Datarecord pointed to by datafield '.$parent_external_id_field->getId().', value "'.$parent_external_id_value.'" does not exist');

                // If importing into a child datatype, figure out whether multiple child datarecords are allowed
                if ($import_into_child_datatype) {
                    /** @var DataTree $datatree */
                    $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy( array('ancestor' => $parent_datatype->getId(), 'descendant' => $datatype->getId()) );
                    if ($datatree == null)
                        throw new ODRException('Datatree entry does not exist');

                    $multiple_allowed = $datatree->getMultipleAllowed();
                }
            }

            // ----------------------------------------
            // Attempt to locate the datarecord that this row of data will be imported into
            /** @var DataRecord|null $datarecord */
            $datarecord = null;
            $external_id_field = $datatype->getExternalIdField();
            $external_id_value = '';

            if (!$import_into_child_datatype) {
                if ($external_id_field != null) {
                    // Pull the external ID value from the CSV file
                    $datafield_id = $external_id_field->getId();
                    foreach ($mapping as $column_num => $df_id) {
                        if ($df_id == $datafield_id)
                            $external_id_value = trim( $line[$column_num] );
                    }

                    // Have an external ID field, so attempt to locate a top-level datarecord
                    $datarecord = $dri_service->getDatarecordByExternalId($external_id_field, $external_id_value);
                }
                else {
                    // Otherwise, no external ID...leave $datarecord as null so a new datarecord gets created
                }
            }
            else {
                if ($external_id_field != null) {
                    // Pull the external ID value from the CSV file
                    $datafield_id = $external_id_field->getId();
                    foreach ($mapping as $column_num => $df_id) {
                        if ($df_id == $datafield_id)
                            $external_id_value = trim( $line[$column_num] );
                    }

                    // Have an external ID field, so attempt to locate the child datarecord with the parent
                    $datarecord = $dri_service->getChildDatarecordByExternalId($external_id_field, $external_id_value, $parent_external_id_field, $parent_external_id_value);
                }
                else {
                    // Otherwise, no external ID...
                    if (!$multiple_allowed) {
                        // ...if only a single child datarecord is allowed for this datatype, attempt to locate it
                        $datarecord = $dri_service->getSingleChildDatarecordByParent($datatype, $parent_external_id_field, $parent_external_id_value);
                    }
                    else {
                        // ...otherwise, multiple child datarecords are allowed...don't attempt to locate a child datarecord here so the import process always creates a new child datarecord
                    }
                }
            }
/*
if ( is_null($parent_datarecord) )
    print "parent_datarecord: ''\n";
else
    print "parent_datarecord: ".$parent_datarecord->getId()."\n";
if ( is_null($parent_external_id_field) )
    print "parent_external_id_field: ''\n";
else
    print "parent_external_id_field: ".$parent_external_id_field->getId()."\n";
print "parent_external_id_value: '".$parent_external_id_value."'\n";

if ( is_null($datarecord) )
    print "datarecord: ''\n";
else
    print "datarecord: ".$datarecord->getId()."\n";
if ( is_null($external_id_field) )
    print "external_id_field: ''\n";
else
    print "external_id_field: ".$external_id_field->getId()."\n";
print "external_id_value: '".$external_id_value."'\n";
exit();
*/
            // One of four possibilities at this point...
            // 1) $parent_datarecord != null, $datarecord != null   -- importing into an existing child datarecord
            // 2) $parent_datarecord != null, $datarecord == null   -- importing into a new child datarecord
            // 3) $parent_datarecord == null, $datarecord != null   -- importing into an existing top-level datarecord
            // 4) $parent_datarecord == null, $datarecord == null   -- importing into a new top-level datarecord


            // ----------------------------------------
            // Determine whether to create a new datarecord or not
            if ($datarecord == null) {
                // Create a new datarecord, since one doesn't exist
                $datarecord = $ec_service->createDatarecord($user, $datatype, true);    // don't flush immediately...
                if ( !is_null($parent_datarecord) ) {
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
            // May need to delete some extra cache entries depending on what gets created during this
            $need_datatype_cache_rebuild = false;
            $datafields_needing_name_sort = array();

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

                    $column_data = trim($column_data);

                    if ($typeclass == 'Boolean') {
                        // Get the existing entity for this datarecord/datafield, or create a new one if it doesn't exist
                        /** @var ODRBoolean $entity */
                        $entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);

                        // Assume the field is checked initially...
                        $checked = true;
                        switch ($column_data) {
                            case '':
                            case 'N':
                            case 'No':
                            case '0':    // $column_data is a string at this point
                                // ...but if it matches any of the above strings, make it unchecked instead
                                $checked = false;
                                break;
                        }

                        // Ensure the value in the datafield matches the value in the import file
                        $emm_service->updateStorageEntity($user, $entity, array('value' => $checked));
                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$checked.'"...'."\n";
                    }
                    else if ($typeclass == 'File' || $typeclass == 'Image') {
                        $csv_filenames = array();
                        $status .= '    -- datafield '.$datafield->getId().' ('.$typeclass.') '."\n";

                        // ----------------------------------------
                        // If a filename is in this column...
                        if ($column_data !== '') {
                            // Grab the associated datarecordfield entity
                            $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

                            // Store the path to the user's upload area...
                            $path_prefix = $this->getParameter('odr_web_directory').'/';
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
                                // Don't attempt to upload files with no name
                                $csv_filename = trim($csv_filename);
                                if ($csv_filename === '')
                                    continue;

                                // ...there are three possibilities...
                                if ( !isset($existing_files[$csv_filename]) ) {
                                    // ...need to add a new file/image
                                    parent::finishUpload($em, $storage_filepath, $csv_filename, $user->getId(), $drf->getId());

                                    $status .= '      ...uploaded new '.$typeclass.' ("'.$csv_filename.'")'."\n";

                                    // The version of the file in the storage directory will get
                                    //  deleted as part of the crypto worker job
                                }
                                else if ( $existing_files[$csv_filename]->getOriginalChecksum() == md5_file($path_prefix.$storage_filepath.'/'.$csv_filename) ) {
                                    // ...the specified file/image is already in datafield
                                    $status .= '      ...'.$typeclass.' ("'.$csv_filename.'") is an exact copy of existing version, skipping.'."\n";

                                    // Delete the file/image from the csv import storage directory
                                    //  on the server since it already exists as an officially
                                    //  uploaded file
                                    if ( file_exists($path_prefix.$storage_filepath.'/'.$csv_filename) )
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
                                        $filepath = $this->getParameter('odr_web_directory').'/uploads/files/File_'.$old_obj->getId().'.'.$old_obj->getExt();
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
                                            $filepath = $this->getParameter('odr_web_directory').'/uploads/images/Image_'.$img->getId().'.'.$img->getExt();
                                            if ( file_exists($filepath) )
                                                unlink($filepath);
                                        }
                                    }

                                    // "Upload" the new file, and copy over the existing metadata
                                    $new_obj = parent::finishUpload($em, $storage_filepath, $csv_filename, $user->getId(), $drf->getId());
                                    if ($typeclass == 'File')
                                        $emm_service->updateFileMeta($user, $new_obj, $properties);
                                    else
                                        $emm_service->updateImageMeta($user, $new_obj, $properties);

                                    // Delete the old object and its metadata entry
                                    $old_obj->setDeletedBy($user);
                                    $old_obj->setDeletedAt(new \DateTime());
                                    $em->persist($old_obj);

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
                                        $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
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
                                        $file_meta->setDeletedAt(new \DateTime());
                                        $em->persist($file_meta);

                                        $file->setDeletedBy($user);
                                        $file->setDeletedAt(new \DateTime());
                                        $em->persist($file);

                                        $need_flush = true;
                                    }
                                    else if ($typeclass == 'Image') {
                                        /** @var Image $file */
                                        if ($file->getOriginal() == 1) {
                                            $status .= '      ...'.$typeclass.' ("'.$original_filename.'") not listed in csv file, deleting...'."\n";

                                            // Delete the image's associated metadata entry
                                            $image_meta = $file->getImageMeta();
                                            $image_meta->setDeletedAt(new \DateTime());
                                            $em->persist($image_meta);
                                        }

                                        // Ensure no decrypted version of the image (or thumbnails) exists on the server
                                        $local_filepath = $this->getParameter('odr_web_directory').'/uploads/images/Image_'.$file->getId().'.'.$file->getExt();
                                        if ( file_exists($local_filepath) )
                                            unlink($local_filepath);

                                        // Save who deleted the image
                                        $file->setDeletedBy($user);
                                        $file->setDeletedAt(new \DateTime());
                                        $em->persist($file);

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
                        $entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);

                        // NOTE - intentionally not using intval() here...self::csvvalidateAction()
                        //  would've already warned if column data wasn't an integer
                        // In addition, updateStorageEntity() has to have values passed as strings,
                        //  and will convert back to integer before saving
                        $value = $column_data;

                        // Ensure the value stored in the entity matches the value in the import file
                        $emm_service->updateStorageEntity($user, $entity, array('value' => $value));
                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$value.'"...'."\n";

                    }
                    else if ($typeclass == 'DecimalValue') {
                        // Get the existing entity for this datarecord/datafield, or create a new one if it doesn't exist
                        /** @var DecimalValue $entity */
                        $entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);

                        // NOTE - intentionally not using floatval() here...self::csvvalidateAction()
                        //  would've already warned if column data wasn't a float
                        // In addition, updateStorageEntity() has to have values passed as strings,
                        //  and DecimalValue::setValue() will deal with any string received
                        $value = $column_data;

                        // Ensure the value stored in the entity matches the value in the import file
                        $emm_service->updateStorageEntity($user, $entity, array('value' => $value));
                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$value.'"...'."\n";

                    }
                    else if ($typeclass == 'LongText' || $typeclass == 'LongVarchar' || $typeclass == 'MediumVarchar' || $typeclass == 'ShortVarchar') {
                        // Get the existing entity for this datarecord/datafield, or create a new one if it doesn't exist
                        /** @var LongText|LongVarchar|MediumVarchar|ShortVarchar $entity */
                        $entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);

                        // Need to truncate overly-long strings here...otherwise doctrine will throw
                        //  an error and the import of this record will fail
                        $truncated = false;
                        if ( $typeclass == 'ShortVarchar' && strlen($column_data) > 32 ) {
                            $truncated = true;
                            $column_data = substr($column_data, 0, 32);
                        }
                        else if ( $typeclass == 'MediumVarchar' && strlen($column_data) > 64 ) {
                            $truncated = true;
                            $column_data = substr($column_data, 0, 64);
                        }
                        else if ( $typeclass == 'LongVarchar' && strlen($column_data) > 255 ) {
                            $truncated = true;
                            $column_data = substr($column_data, 0, 255);
                        }

                        // Ensure the value stored in the entity matches the value in the import file
                        $emm_service->updateStorageEntity($user, $entity, array('value' => $column_data));

                        if ( $truncated )
                            $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$column_data.'" (TRUNCATED)...'."\n";
                        else
                            $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$column_data.'"...'."\n";

                    }
                    else if ($typeclass == 'DatetimeValue') {
                        // Get the existing entity for this datarecord/datafield, or create a new one if it doesn't exist
                        /** @var DatetimeValue $entity */
                        $entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);

                        // Turn the data into a DateTime object...csvvalidateAction() already would've warned if column data isn't actually a date
                        $value = null;
                        if ( $column_data !== '' )
                            $value = new \DateTime($column_data);

                        // Ensure the value stored in the entity matches the value in the import file
                        $emm_service->updateStorageEntity($user, $entity, array('value' => $value));
                        if ($value == null)
                            $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to ""...'."\n";
                        else
                            $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.') to "'.$value->format('Y-m-d H:i:s').'"...'."\n";

                    }
                    else if ($typeclass == 'Radio') {
                        $status .= '    -- datafield '.$datafield->getId().' ('.$typeclass.') '."\n";

                        if ($column_data === '')
                            continue;

                        // Going to need the datarecordfield entry for later...
                        $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

                        // If multiple radio/select, get an array of all the options...
                        $options = array($column_data);
                        if ($typename == "Multiple Select" || $typename == "Multiple Radio")
                            $options = explode( $column_delimiters[$column_num], $column_data );

                        foreach ($options as $num => $option_name) {
                            // Don't look for or create a blank radio option
                            $option_name = trim($option_name);
                            if ( $option_name == '' )
                                continue;

                            // Attempt to load an existing radio option with this name
                            /** @var RadioOptions $radio_option */
                            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                                array(
                                    'optionName' => $option_name,
                                    'dataField' => $datafield->getId()
                                )
                            );
                            if ( $radio_option == null ) {
                                // Create a radio_option entity for this datafield with this name if it
                                //  doesn't already exist.  $force_create MUST be false, otherwise it
                                //  will create duplicate radio options
                                $force_create = false;
                                $radio_option = $ec_service->createRadioOption(
                                    $user,
                                    $datafield,
                                    $force_create,
                                    $option_name
                                );
                                // createRadioOption() automatically flushes when $force_create == false
                                $status .= '      ...created new radio_option ("'.$option_name.'").'."\n";

                                // Going to need to do some extra cache-related stuff since a radio
                                //  option got related
                                $need_datatype_cache_rebuild = true;
                                // If the datafield is ordered by name, then the creation of a new
                                //  radio option also requires a redo of the sort order
                                if ($datafield->getRadioOptionNameSort())
                                    $datafields_needing_name_sort[$datafield->getId()] = $datafield;
                            }
                            else {
                                $status .= '      ...found existing radio_option ("'.$radio_option->getOptionName().'").'."\n";
                            }

                            // If this field only allows a single selection...
                            if ($typename == 'Single Radio' || $typename == 'Single Select') {
                                /** @var RadioSelection[] $radio_selections */
                                $radio_selections = $em->getRepository('ODRAdminBundle:RadioSelection')->findBy(
                                    array(
                                        'dataRecordFields' => $drf->getId()
                                    )
                                );

                                // ...then for every radio selection entity in this datafield...
                                foreach ($radio_selections as $rs) {
                                    // ...if it's not the one that's supposed to be selected...
                                    if ( $rs->getRadioOption()->getId() !== $radio_option->getId()
                                        && $rs->getSelected() == 1
                                    ) {
                                        // ...ensure it's deselected
                                        $properties = array('selected' => 0);
                                        $emm_service->updateRadioSelection($user, $rs, $properties, true);    // don't flush immediately

                                        $status .= '      >> deselected radio selection for radio_option ("'.$rs->getRadioOption()->getOptionName().'").'."\n";
                                    }
                                }
                            }

                            // TODO - add Radio equivalent of "delete all unlisted files/images" for Multiple Radio/Select?

                            // Now that there won't be extraneous radio options selected afterwards...
                            //  ensure the radio selection entity for the desired radio option exists
                            $radio_selection = $ec_service->createRadioSelection($user, $radio_option, $drf);

                            // Ensure it has the correct selected status
                            $properties = array('selected' => 1);
                            $emm_service->updateRadioSelection($user, $radio_selection, $properties);

                            $status .= '      >> radio_selection for radio_option ("'.$radio_option->getOptionName().'") now selected'."\n";
                        }
                        $status .= "\n";
                    }
                    else if ($typeclass == 'Tag') {
                        $status .= '    -- datafield '.$datafield->getId().' ('.$typeclass.') '."\n";

                        if ($column_data === '')
                            continue;

                        // At this point, no tags need to be created, so all that should be required
                        //  is to locate the bottom-level tag being selected...
                        $df_array = $cached_dt_array[$datatype->getId()]['dataFields'][$datafield_id];
                        $stacked_tag_array = $df_array['tags'];

                        // Going to need the datarecordfield entry for later...
                        $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

                        // TODO - add Tag equivalent of "delete all unlisted files/images"?

                        // Convert the contents of this field into individual tags
                        $tags = explode($column_delimiters[$column_num], $column_data);

                        if ( !isset($hierarchy_delimiters[$column_num]) ) {
                            // This datafield only allows a single level of tags
                            foreach ($tags as $num => $tag_name)
                                $tags[$num] = trim($tag_name);

                            // For each tag that needs to be selected...
                            foreach ($tags as $num => $tag_name) {
                                // ...locate the tag that this string is referencing
                                foreach ($stacked_tag_array as $tag_id => $tag_data) {
                                    if ( $tag_name === $tag_data['tagName'] ) {
                                        /** @var Tags $tag */
                                        $tag = $repo_tags->find($tag_id);

                                        // Ensure a tagSelection entity exists
                                        $tag_selection = $ec_service->createTagSelection($user, $tag, $drf);

                                        // Mark it as selected
                                        $properties = array('selected' => 1);
                                        $emm_service->updateTagSelection($user, $tag_selection, $properties, true);    // don't flush immediately...

                                        $status .= '      >> tag_selection for tag ("'.$tag_name.'") now selected'."\n";

                                        // Stop trying to locate this tag, move on to the next one
                                        break;
                                    }
                                }
                            }
                        }
                        else {
                            // This datafield is a tag hierarchy
                            foreach ($tags as $tmp => $tag) {
                                // Split the hierarchy into an array of levels
                                $tag_chain = explode($hierarchy_delimiters[$column_num], $tag);
                                foreach ($tag_chain as $num => $tag_name)
                                    $tag_chain[$num] = trim($tag_name);

                                $tags[$tmp] = $tag_chain;
                            }

                            // Need to locate the bottom-level tag being selected...
                            foreach ($tags as $num => $tag_chain) {
                                // ...locate the tag this array is referencing
                                $tag = null;
                                $tag_id = self::locateTagForCSVSelection($stacked_tag_array, $tag_chain);

                                if ( is_null($tag_id) ) {
                                    // If null, then this tag chain referenced a mid-level tag or
                                    //  some sort of unexpected error occured...do nothing

                                    $full_tag_name = implode(' '.$hierarchy_delimiters[$column_num].' ', $tag_chain);
                                    $status .= '      ** unable to locate tag ("'.$full_tag_name.'")'."\n";
                                }
                                else {
                                    // Otherwise, load the bottom-level tag
                                    /** @var Tags $tag */
                                    $tag = $repo_tags->find($tag_id);

                                    // Ensure a tagSelection entity exists
                                    $tag_selection = $ec_service->createTagSelection($user, $tag, $drf);

                                    // Mark it as selected
                                    $properties = array('selected' => 1);
                                    $emm_service->updateTagSelection($user, $tag_selection, $properties, true);    // don't flush immediately...

                                    $full_tag_name = implode(' '.$hierarchy_delimiters[$column_num].' ', $tag_chain);
                                    $status .= '      >> tag_selection for tag ("'.$full_tag_name.'") now selected'."\n";

                                    // Move on to the next tag
                                }
                            }
                        }

                        // Flush now that all the tags have been marked as selected
                        $em->flush();
                    }
                }
            }


            // ----------------------------------------
            // Load the job so it can be updated
            /** @var TrackedJob $tracked_job */
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

            // Check whether any more "additional data" needs to be stored
            $additional_data = $tracked_job->getAdditionalData();

            // Creation of new radio options and/or tags requires a rebuild of the cached datatype
            //  array, but that has to happen after all importing is completed...
            if ($need_datatype_cache_rebuild) {
                $additional_data['rebuild_datatype_cache'] = true;
                $status .= ' >> requiring end of import to rebuild cached datatype array...'."\n";
            }

            // Creation of new radio options in a datafield sorted by name requires the relevant
            //  datafield gets resorted, but that has to happen after all importing is completed
            // Tags were created and resorted back in startworkerAction(), so those don't need it
            /** @var DataFields[] $datafields_needing_name_sort */
            foreach ($datafields_needing_name_sort as $df_id => $df) {
                if ( !isset($additional_data['datafields_needing_resort']) )
                    $additional_data['datafields_needing_resort'] = array();

                $additional_data['datafields_needing_resort'][$df_id] = 1;
                $status .= ' >> requiring end of import to resort datafield '.$df_id.' ("'.$df->getFieldName().'")'."\n";
            }

            $tracked_job->setAdditionalData($additional_data);
            $em->persist($tracked_job);
            $em->flush();
            $em->refresh($tracked_job);


            // ----------------------------------------
            // Increment the job counter
            $total = $tracked_job->getTotal();
            $count = $tracked_job->incrementCurrent($em);

            if ($count >= $total) {
                // Job is completed...
                $tracked_job->setCompleted( new \DateTime() );

                // TODO - really want a better system than this...

                // Check whether any more "additional data" was stored...
                $additional_data = $tracked_job->getAdditionalData();

                // Re-sort each of the datafields that need it as a result of creating new radio
                //  options or tags
                if ( isset($additional_data['datafields_needing_resort']) ) {
                    foreach ($additional_data['datafields_needing_resort'] as $df_id => $num) {
                        /** @var DataFields $df */
                        $df = $em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
                        if ( $df != null ) {
                            if ( $df->getFieldType()->getTypeClass() === 'Radio' ) {
                                $sort_service->sortRadioOptionsByName($user, $df);
                                $status .= ' == re-sorting radio options by name for datafield '.$df_id.' ("'.$df->getFieldName().'")'."\n";
                            }
                            else if ( $df->getFieldType()->getTypeClass() === 'Tag' ) {
                                $sort_service->sortTagsByName($user, $df);
                                $status .= ' == re-sorting tasg by name for datafield '.$df_id.' ("'.$df->getFieldName().'")'."\n";
                            }
                        }
                    }
                }

                // Mark the datatype as updated and rebuild its cache entries if needed
                if ( isset($additional_data['rebuild_datatype_cache']) ) {
                    $dti_service->updateDatatypeCacheEntry($datatype, $user);
                    $status .= ' == updated datatype cache entry for datatype '.$datatype->getId().' ("'.$datatype->getShortName().'")'."\n";
                }

                // Since the job is now done (in theory), delete all search cache entries
                //  relevant to this datatype
                $search_cache_service->onDatatypeImport($datatype);
                $status .= ' == deleting all search cache entries for datatype '.$datatype->getId().' ("'.$datatype->getShortName().'")'."\n";

                // Job will be flushed shortly...
                $em->persist($tracked_job);
            }

            // Import is finished, ensure the datarecord can be accessed by other parts of the site
            $datarecord->setProvisioned(false);
            $em->persist($datarecord);

            $em->flush();


            // ----------------------------------------
            // Rebuild the list of sorted datarecords, since the datarecord order may have changed
            $dti_service->resetDatatypeSortOrder($datatype->getId());

            // Mark this datarecord as updated...
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);
            $status .= ' == updating datarecord cache entry for datarecord '.$datarecord->getId()."\n";

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

            // Since this is only called via beanstalk, return exceptions as json
            $request->setRequestFormat('json');

            $source = 0x121707ab;
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
     * Locates the tag referenced by the $tag_chain inside $stacked_tag_array.  Returns the id if
     * a bottom-level tag is found, returns null otherwise.  If $tag_chain references a tag that is
     * not bottom-level, null is returned.
     *
     * @param array $stacked_tag_array
     * @param string[] $tag_chain
     *
     * @param int|null
     */
    private function locateTagForCSVSelection($stacked_tag_array, $tag_chain)
    {
        // Need to locate the current tag in the current level of the stacked tag array
        $tag_name = $tag_chain[0];

        foreach ($stacked_tag_array as $tag_id => $tag) {
            if ( $tag_name === $tag['tagName'] ) {
                if ( !isset($tag['children']) && count($tag_chain) === 1 ) {
                    // Successfully found the desired bottom-level tag
                    return intval($tag_id);
                }
                else if ( isset($tag['children']) && count($tag_chain) > 1 ) {
                    // Successfully found a mid-level tag, need to continue to locate children
                    $child_tag_chain = array_slice($tag_chain, 1);
                    return self::locateTagForCSVSelection($tag['children'], $child_tag_chain);
                }
                else if ( !isset($tag['children']) && count($tag_chain) > 1 ) {
                    // No children of this tag, but tag chain references a child...the startWorker()
                    //  action was supposed to create it, but apparently something went wrong...
                    return null;
                }
                else if ( isset($tag['children']) && count($tag_chain) === 1 ) {
                    // This tag does have children, but the tag chain doesn't continue...since it
                    //  doesn't make sense to select a mid-level tag, return null
                    return null;
                }
            }
        }

        // If this point is reached, then the tag wasn't found...so something went wrong
        return null;
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
//exit( print_r($post, true) );

            // TODO - correct requirements
            if ( !isset($post['tracked_job_id'])
                /*|| !isset($post['mapping']) */
                || !isset($post['line'])
                || !isset($post['datatype_id'])
                || !isset($post['user_id'])
                || !isset($post['api_key'])
            ) {
                throw new ODRException('Invalid job data');
            }

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

            // If the import is for a child or linked datatype, then one of the columns from the csv
            //  file has to be mapped to the parent/local datatype's external id datafield
            $parent_datatype_id = '';
            if ( isset($post['parent_datatype_id']) )
                $parent_datatype_id = $post['parent_datatype_id'];
            $parent_external_id_column = '';
            if ( isset($post['parent_external_id_column']) )
                $parent_external_id_column = $post['parent_external_id_column'];

            // If the import is for a linked datatype, then another column from the csv file also
            //  has to be mapped to the remote datatype's external id datafield...
            $remote_external_id_column = '';
            if ( isset($post['remote_external_id_column']) )
                $remote_external_id_column = $post['remote_external_id_column'];


            // ----------------------------------------
            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $logger = $this->get('logger');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            if ($api_key !== $beanstalk_api_key)
                throw new ODRException('Invalid job data');

            /** @var ODRUser $user */
            $user = $repo_user->find($user_id);
            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new ODRException('Invalid Form...Datatype is deleted');

            /** @var DataType $parent_datatype */
            $parent_datatype = $repo_datatype->find($parent_datatype_id);
            if ($parent_datatype == null)
                throw new ODRException('Invalid Form...Parent Datatype is deleted');

            // This doesn't make sense on a master template...
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to import into a master template');
            if ( $parent_datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to import into a master template');
            // ...or a metadata datatype
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to import into a metadata datatype');
            if ( !is_null($parent_datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to import into a metadata datatype');


            // ----------------------------------------
            // Locate "local" and "remote" datarecords
            $local_external_id_field = $parent_datatype->getExternalIdField();
            $local_external_id = trim( $line[$parent_external_id_column] );
            $local_datarecord = $dri_service->getDatarecordByExternalId($local_external_id_field, $local_external_id);

            $remote_external_id_field = $datatype->getExternalIdField();
            $remote_external_id = trim( $line[$remote_external_id_column] );
            $remote_datarecord = $dri_service->getDatarecordByExternalId($remote_external_id_field, $remote_external_id);


            // ----------------------------------------
            // Ensure a link exists from the local datarecord to the remote datarecord
            $ec_service->createDatarecordLink($user, $local_datarecord, $remote_datarecord);
            $status .= ' -- Datarecord '.$local_datarecord->getId().' Datatype '.$parent_datatype->getId().' (external id: "'.$local_external_id.'") is now linked to Datarecord '.$remote_datarecord->getId().' Datatype '.$datatype->getId().' (external id: "'.$remote_external_id.'")'."\n";

            // Force a rebuild of the cached entry for the ancestor datarecord
            $dri_service->updateDatarecordCacheEntry($local_datarecord, $user);
            // Also rebuild the cached list of which datarecords this ancestor datarecord now links to
            $dri_service->deleteCachedDatarecordLinkData( array($local_datarecord->getId()) );
            $search_cache_service->onLinkStatusChange($remote_datarecord->getDataType());

            // Linking/unlinking a datarecord has no effect on datarecord order


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

            // Since this is only called via beanstalk, return exceptions as json
            $request->setRequestFormat('json');

            $source = 0xf520f9b1;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
