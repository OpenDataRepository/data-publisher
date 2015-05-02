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

// Entites
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
    public function importAction($datatype_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo_datatype->find($datatype_id);

            if ( $datatype== null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Create a File form object for the user to upload a csv file through Symfony
            $obj_classname = "ODR\\AdminBundle\\Entity\\File";
            $form_classname = "\\ODR\\AdminBundle\\Form\\FileForm";

            $form_obj = new $obj_classname();
            $form_obj->setDataField(null);
            $form_obj->setFieldType(null);
            $form_obj->setDataRecord(null);
            $form_obj->setDataRecordFields(null);
            $form_obj->setCreatedBy(null);
            $form_obj->setUpdatedBy(null);
            $form_obj->setGraphable('0');

            $form = $this->createForm( new $form_classname($em), $form_obj );


            // Render the basic csv import page
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:CSVImport:import.html.twig',
                    array(
                        'datatype' => $datatype,
                        'form' => $form->createView(),
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
     * Handles the upload of a CSV file by moving it from the file upload directory, and saving some helper variables in the user's session.
     * @see CSVImportController::layoutAction()
     *
     * @param integer $datatype_id Which datatype the CSV data is being imported into.
     * @param Request $request
     *
     * @return an empty Symfony JSON response, unless some sort of error occurred
     */
    public function uploadAction($datatype_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            $post = $_POST;
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


            // Ensure the file uploaded correctly
            $form_classname = "\\ODR\\AdminBundle\\Form\\FileForm";
            $my_obj = new File();
            $form = $this->createForm( new $form_classname($em), $my_obj );

            $form->bind($request, $my_obj);
            if (!$form->isValid())
                throw new \Exception( "\n".$form->getErrorsAsString() );


            // Move the uploaded file into a directory specifically for csv imports
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');
            $tmp_filename = substr($tokenGenerator->generateToken(), 0, 12);

            $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/';
            if ( !file_exists($csv_import_path) )
                mkdir($csv_import_path);

            $my_obj->getUploadedFile()->move( $csv_import_path, $tmp_filename.'.csv' );


            // Store the filename in the user's session
            $session = $request->getSession();
            if ( $session->has('csv_file') ) {
                // delete the old file?
                $filename = $session->get('csv_file');
                if ( file_exists($csv_import_path.$filename) )
                    unlink($csv_import_path.$filename);

                $session->remove('csv_file');
            }
            $session->set('csv_file', $tmp_filename.'.csv');


            // Store the desired delimiter in user's session
            $csv_delimiter = $post['csv_delimiter'];
            switch ($csv_delimiter) {
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
            }
            $session->set('csv_delimiter', $csv_delimiter);

            // the iframe that uploaded the file will fire off another ajax call to layoutAction
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
     * Reads the previously uploaded CSV file to extract column names, and renders a form to let the user decide what data to import and which DataFields to import it to.
     * @see CSVImportController:uploadAction()
     *
     * @param integer $datatype_id Which datatype the CSV data is being imported into.
     * @param Request $request
     *
     * @return a Symfony JSON response containing the HTML TODO 
     */
    public function layoutAction($datatype_id, Request $request) {
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


            // Grab all datafields belonging to that datatype
//            $datafields = $datatype->getDataFields();
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
            // TODO - any other fieldtypes?
            $fieldtype_array = array(
                1, // boolean
//                2, // file
//                3, // image
                4, // integer
                5, // paragraph text
                6, // long varchar
                7, // medium varchar
                8, // single radio
                9, // short varchar
                11, // datetime
//                13, // multiple radio
                14, // single select
//                15, // multiple select
                16, // decimal
//                17, // markdown
            );
            $fieldtypes = $repo_fieldtype->findBy( array('id' => $fieldtype_array) );


            // Attempt to load the previously uploaded csv file
            if ( !$session->has('csv_file') )
                throw new \Exception('No CSV file uploaded');

            $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/';
            $csv_filename = $session->get('csv_file');
            $delimiter = $session->get('csv_delimiter');

            $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
            $reader = new CsvReader($csv_file, $delimiter);
            $reader->setHeaderRowNumber(0);

            // Get the first row of the csv file
            $line_num = 1;
            $first_row = array();
            $json_errors = array();
            foreach ($reader as $row) {
                $line_num++;

                // Save the contents of the header row so column names can be extracted
                $first_row = $row;

                // Loop through the rest of the file...this will let the CsvReader pick up some of the possible errors
//                break;

                // Attempt to json_encode each line to catch utf-8 errors here
                $result = json_encode($row);

                switch (json_last_error()) {
                    case JSON_ERROR_NONE:
                    break;
                    case JSON_ERROR_CTRL_CHAR:
                        $json_errors['Unexpected control character found'][] = $line_num;
                    break;
                    case JSON_ERROR_UTF8:
                        $json_errors['Malformed UTF-8 characters'][] = $line_num;
                    break;
                    default:
                        $json_errors['Unknown error'][] = $line_num;
                    break;
                }
            }

            // TODO - better error messages?
            // TODO - more strenuous error checking?
            if ( count($json_errors) > 0 ) {
                $str = '';
                foreach($json_errors as $error => $lines) {
                    $str .= '"'.$error.'" on lines ';
                    foreach ($lines as $key => $line)
                        $str .= $line.',';
                    $str .= "\n\n";
                }
                throw new \Exception($str);
            }
            else if (count($reader->getErrors()) > 0 ) {
//                $errors = print_r($reader->getErrors(), true);
//                throw new \Exception( $errors );

                throw new \Exception('Error while attempting to read column names...');
            }


            // Grab column names from first row
            $columns = array();
            foreach ($first_row as $column => $value)
                $columns[] = $column;

            // Render the page
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:CSVImport:layout.html.twig',
                    array(
                        'columns' => $columns,
                        'datatype' => $datatype,
                        'datafields' => $datafields,
                        'fieldtypes' => $fieldtypes,
                    )
                )
            );

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
     * Deletes any csv-specific data from the user's session, and also deletes any csv file they uploaded.
     *
     * @param Request $request
     *
     * @return an empty Symfony JSON response, unless some sort of error occurred.
     */
    public function cancelAction(Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Attempt to locate the csv file stored in the user's session
            $session = $request->getSession();
            if ( $session->has('csv_file') ) {
                // Delete the file if it exists
                $filename = $session->get('csv_file');
                $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/';
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
            $return['d'] = 'Error 042627153467 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Reads a $_POST request for importing a CSV file, and creates a pheanstalk job to import each line in the file.
     *
     * @param Request $request
     *
     * @return an empty Symfony JSON response, unless an error occurred.
     */
    public function processAction(Request $request) {
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

            // Pull data from the post
            $datafield_mapping = $post['datafield_mapping'];
            $datatype_id = $post['datatype_id'];

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

            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            $datatype = $repo_datatype->find($datatype_id);


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab fieldtype mapping for datafields this import is going to create, if the user chose to create new datafields
            $fieldtype_mapping = null;
            if ( isset($post['fieldtype_mapping']) )
                $fieldtype_mapping = $post['fieldtype_mapping'];
            // Grab which column was designated for use as an external_id, if possible
            $external_id_column = null;
            if ( isset($post['external_id_column']) )
                $external_id_column = $post['external_id_column'];

/*
print "External ID Column: ".$external_id_column."\n";
print_r($datafield_mapping);
print "\n";
print_r($fieldtype_mapping);
return;
*/

            // ------------------------------
            // Attempt to load csv file
            if ( !$session->has('csv_file') )
                throw new \Exception('No CSV file uploaded');

            $csv_import_path = dirname(__FILE__).'/../../../../web/uploads/csv/';
            $csv_filename = $session->get('csv_file');
            $delimiter = $session->get('csv_delimiter');

            $csv_file = new \SplFileObject( $csv_import_path.$csv_filename );
            $reader = new CsvReader($csv_file, $delimiter);
//            $reader->setHeaderRowNumber(0);   // don't want associative array

            // Grab headers from csv file incase a new datafield is created
            $headers = array();
            foreach ($reader as $row) {
                $headers = $row;
                break;
            }

            // If a column is marked as the external id column, go through and ensure that there are no duplicate values in that column
            if ($external_id_column !== null) {
                $count = 0;
                $external_ids = array();
                foreach ($reader as $row) {
                    $count++;
                    $value = $row[$external_id_column];
                    if ( isset($external_ids[$value]) ) {
                        // duplicate value, complain and abort
                        throw new \Exception( 'Column '.$external_id_column.' ("'.$headers[$external_id_column].'") has a duplicate value "'.$value.'", on at least lines '.$external_ids[$value].' and '.$count );
                    }
                    else {
                        // ...otherwise, not found, just store the value
                        $external_ids[$value] = $count;
                    }
                }
            }

//print_r($headers);
//return;

            // ------------------------------
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

//print 'loaded existing datafield'."\n";
                    $logger->notice('Using existing datafield '.$datafield->getId().' "'.$datafield->getFieldName().'" for csv import of datatype '.$datatype->getId().' by '.$user->getId());
                }
                else {
                    // Grab desired fieldtype from post
                    if ( $fieldtype_mapping == null )
                        throw new \Exception('Invalid Form');

                    $fieldtype = $repo_fieldtype->find( $fieldtype_mapping[$column_id] );
                    if ($fieldtype == null)
                        throw new \Exception('Invalid Form');

                    // Create new datafield
                    $datafield = parent::ODR_addDataFieldsEntry($em, $user, $datatype, $fieldtype, $render_plugin);
                    $created = true;
//print 'created new datafield of fieldtype "'.$fieldtype->getTypeName().'"'."\n";

                    // Set the datafield's name, then persist/reload it
                    $datafield->setFieldName( $headers[$column_id] );
                    $em->persist($datafield);
                    $em->flush($datafield);     // required, or can't get id
                    $em->refresh($datafield);

                    $new_datafields[] = $datafield;

                    $logger->notice('Created new datafield '.$datafield->getId().' "'.$datafield->getFieldName().'" for csv import of datatype '.$datatype->getId().' by '.$user->getId());
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
return;
*/
            // ----------------------------------------
            // Get/create an entity to track the progress of this csv import
            $job_type = 'csv_import';
            $target_entity = 'datatype_'.$datatype->getId();
            $description = 'Importing data into DataType '.$datatype_id.'...';
            $restrictions = '';
            $total = ($reader->count() - 1);
            $reuse_existing = false;

            $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $description, $restrictions, $total, $reuse_existing);
            $tracked_job_id = $tracked_job->getId();


            // ------------------------------
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

                        'api_key' => $beanstalk_api_key,
                        'url' => $url,
                        'memcached_prefix' => $memcached_prefix,    // debug purposes only

                        'external_id_column' => $external_id_column,
                        'mapping' => $new_mapping,
                        'line' => $row,
                        'datatype_id' => $datatype->getId(),
                        'user_id' => $user->getId(),
                    )
                );

                $pheanstalk->useTube('csv_import')->put($payload);
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
    public function csvworkerAction(Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
//return;

            if ( !isset($post['tracked_job_id']) || !isset($post['mapping']) || !isset($post['line']) || !isset($post['datatype_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid job data');

            // Pull data from the post
            $tracked_job_id = $post['tracked_job_id'];
            $mapping = $post['mapping'];
            $line = $post['line'];
            $datatype_id = $post['datatype_id'];
            $user_id = $post['user_id'];
            $api_key = $post['api_key'];

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

            $datatype = $repo_datatype->find($datatype_id);
            $user = $repo_user->find($user_id);

            // Attempt to grab external id to use for locating datarecord
            $status = '';
            $external_id = null;
            $datarecord = null;
            if ( isset($post['external_id_column']) ) {
                $external_id_column = $post['external_id_column'];
                $external_id = $line[$external_id_column];

                $datarecord = $repo_datarecord->findOneBy( array('dataType' => $datatype->getId(), 'external_id' => $external_id) );
            }

            if ($datarecord == null) {
                // Create a new datarecord, since one doesn't exist
                $datarecord = parent::ODR_addDataRecord($em, $user, $datatype);
                $datarecord->setParent($datarecord);
                $datarecord->setGrandparent($datarecord);

                // Set external id if possible
                if ($external_id !== null)
                    $datarecord->setExternalId($external_id);

                $em->persist($datarecord);
                $status = "\n".'Created new datarecord for csv import of datatype '.$datatype_id.'...'."\n";
                $logger->notice('Created datarecord '.$datarecord->getId().' for csv import of datatype '.$datatype_id.' by '.$user->getId());

                // Since a new datarecord got imported, rebuild the list of sorted datarecords
                $memcached->delete($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');
            }
            else {
                $status = "\n".'Found existing datarecord ('.$datarecord->getId().') for csv import of datatype '.$datatype_id.'...'."\n";
                $logger->notice('Using existing datarecord ('.$datarecord->getId().') pointed to by external_id "'.$external_id.'" for csv import of datatype '.$datatype_id.' by '.$user->getId());
            }

            // TODO - don't need to refresh datarecord?  but have to refresh created datafield from earler?


            // All datarecordfield and storage entities should be created now...

            // Break apart the line into constituent columns...
            foreach ($line as $column_num => $column_data) {
                // Only care about this column if it's mapped to a datafield...
                if ( isset($mapping[$column_num]) ) {
                    // ...grab which datafield is getting mapped to
                    $datafield_id = $mapping[$column_num];
                    $datafield = $repo_datafield->find($datafield_id);

                    $typeclass = $datafield->getFieldType()->getTypeClass();
                    $classname = "ODR\\AdminBundle\\Entity\\".$typeclass;

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

                        // TODO - validation on these? or should it happen earlier...

                        // Save value from csv file
                        $checked = '';
                        if ( trim($column_data) !== '' ) {
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
                        $entity->setValue( intval($column_data) );
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
                        $entity->setValue( floatval($column_data) );
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

                        // TODO - validation on these? or should it happen earlier...

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
                        $datetime = new \DateTime($column_data);
                        $entity->setValue($datetime);
                        $em->persist($entity);

                        $status .= '    -- set datafield '.$datafield->getId().' ('.$typeclass.' '/*.$entity->getId()*/.') to "'.$datetime->format('Y-m-d H:i:s').'"...'."\n";
                    }
                    else if ($typeclass == 'Radio') {
                        // TODO - csv file indicating multiple radio/select options selected for a single datarecord?

                        $status .= '    -- datafield '.$datafield->getId().' ('.$typeclass.' '/*.$entity->getId()*/.') ';

                        // See if a radio_option entity with this name already exists
                        $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy( array('optionName' => $column_data, 'dataFields' => $datafield->getId()) );
                        if ($radio_option == null) {
                            // TODO - CURRENTLY WORKS, BUT MIGHT WANT TO LOOK INTO AN OFFICIAL MUTEX...

                            // define and execute a query to manually create the absolute minimum required for a RadioOption entity...
                            $option_name = $column_data;
                            $query = 
                               'INSERT INTO odr_radio_options (option_name, data_fields_id)
                                SELECT * FROM (SELECT :name, :df_id) AS tmp
                                WHERE NOT EXISTS (
                                    SELECT option_name FROM odr_radio_options WHERE option_name = :name AND data_fields_id = :df_id AND deletedAt IS NULL
                                ) LIMIT 1;';
                            $params = array('name' => $option_name, 'df_id' => $datafield->getId());
                            $conn = $em->getConnection();
                            $rowsAffected = $conn->executeUpdate($query, $params);

                            if ($rowsAffected > 0) {
                                $logger->notice('Created new RadioOption ("'.$option_name.'") as part of csv import for datatype '.$datatype->getId().' by '.$user->getId());
                                $status .= '...created new radio_option ("'.$option_name.'")';
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

                        $status .= '...selected'."\n";
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

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x232383515 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}

?>
