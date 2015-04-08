<?php

/**
* Open Data Repository Data Publisher
* XML Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The XML controller handles both importing and exporting XML
* versions of datarecords.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entites
use ODR\AdminBundle\StoredRecord;
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


class XMLController extends ODRCustomController
{

    /**
     * Utility function to print out XML errors during importing or exporting
     * 
     * @param mixed $error
     * 
     * @return string
     */
    public function libxml_display_error($error) {
        $return = "<br/>\n";
        switch ($error->level) {
            case LIBXML_ERR_WARNING:
                $return .= "<b>Warning $error->code</b>: ";
                break;
            case LIBXML_ERR_ERROR:
                $return .= "<b>Error $error->code</b>: ";
                break;
            case LIBXML_ERR_FATAL:
                $return .= "<b>Fatal Error $error->code</b>: ";
                break;
        }
        $return .= trim($error->message);
        if ($error->file) {
            $return .=    " in <b>$error->file</b>";
        }
        $return .= " on line <b>$error->line</b>\n";

        return $return;
    }


    /**
     * Utility function to print out XML errors during importing or exporting
     * 
     * @return string
     */
    public function libxml_display_errors() {
        $status = '';

        $errors = libxml_get_errors();
        foreach ($errors as $error) {
            $status .= self::libxml_display_error($error);
        }
        libxml_clear_errors();

        return $status;
    }


    /**
     * Creates a beanstalk job that will scan the XML upload directory for all available files to import.
     * 
     * @param string $type     'datatype' or 'datafield'...the type of importing that will happen
     * @param integer $id      The id of the object that will receive the import data
     * @param Request $request
     *
     * @return TODO
     */
    public function importAction($type, $id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $status = '';

        try {
            // Grab required objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            $api_key = $this->container->getParameter('beanstalk_api_key');
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->get('router');

            $datatype = null;
            $datatype_id = '';
            $datafield_id = '';
            if ($type == 'datatype') {
                $datatype = $repo_datatype->find($id);
                $datatype_id = $id;
            }
            else if ($type == 'datafield') {
                $datafield = $repo_datafield->find($id);
                $datafield_id = $id;
                $datatype = $datafield->getDataType();
            }

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
//            if ( $this->container->getParameter('kernel.environment') === 'dev') { $url .= './app_dev.php'; }
                $url .= $router->generate('odr_import_start');

            // Insert the new job into the queue
            $payload = json_encode(
                array(
                    "datatype_id" => $datatype_id,
                    "datafield_id" => $datafield_id,
                    "user_id" => $user->getId(),
                    "memcached_prefix" => $memcached_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                )
            );
            $pheanstalk->useTube('import_datatype')->put($payload);
        }
        catch (\Exception $e) {
            // TODO - ???
            $status = str_replace('</br>', "\n", $status);
            print $status;

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x232819234 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called by whichever background process is watching the 'import_datatype' beanstalk tube, this 
     * function will create a beanstalk job to validate every file in the XML upload directory.
     * 
     * @param Request $request
     * 
     * @return TODO
     */
    public function importstartAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['datatype_id']) || !isset($post['datafield_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid job data');

            // Pull data from the post
            $datatype_id = $post['datatype_id'];
            $datafield_id = $post['datafield_id'];
            $user_id = $post['user_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $router = $this->get('router');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->get('doctrine')->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');


            // Enable user error handling
            $xml_path = dirname(__FILE__).'/../../../../web/uploads/xml/';
            $ret = '';

            // Determine the schema filename
            $schema_filename = '';
            if ($datatype_id !== '') {
                $datatype = $repo_datatype->find($datatype_id);
                $schema_filename = $datatype->getXMLShortName().'.xsd';
            }
            else if ($datafield_id !== '') {
                $datafield = $repo_datafield->find($datafield_id);
                $schema_filename = 'Datafield_'.$datafield->getId().'xsd';
            }
            else {
                throw new \Exception('Invalid job data');
            }

            // Grab the list of all files in the unprocessed xml directory
            $xml_filenames = scandir($xml_path.'unprocessed/');
            if ( count($xml_filenames) == 2 )   // TODO - currently assuming linux?  (directory will have a "." and a ".." file)
                throw new \Exception("No XML files in the unprocessed directory");

            foreach ($xml_filenames as $xml_filename) {
                // Skip system files...
                if ($xml_filename === '.' || $xml_filename === '..')
                    continue;

                // Queue the file for a full import...
                $url = $this->container->getParameter('site_baseurl');
                $url .= $router->generate('odr_validate_import');

                $payload = json_encode(
                    array(
                        'xml_filename' => $xml_filename,
                        'api_key' => $beanstalk_api_key,
                        'url' => $url,
                        'memcached_prefix' => $memcached_prefix,    // debug purposes only
                        'datatype_id' => $datatype_id,
                        'datafield_id' => $datafield_id,
                        'user_id' => $user_id
                    )
                );
                $pheanstalk->useTube('validate_import')->put($payload);

                if ($datatype_id !== '')
                    $ret .= 'Scheduled "'.$xml_filename.'" of datatype '.$datatype_id.' for validation'."\n";
                else if ($datafield_id !== '')
                    $ret .= 'Scheduled "'.$xml_filename.'" of datafield '.$datafield_id.' for validation'."\n";

            }
            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x6642397854 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called by whichever background process is watching the 'validate_import' beanstalk tube, this
     * function will attempt to validate the given XML file...it will creat another beanstalk job to do the
     * actual importing if the file is valid, or move the file to a "failed" directory if it is invalid.
     *
     * @param Request $request
     * 
     * @return TODO
     */
    public function importvalidateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['xml_filename']) || !isset($post['datatype_id']) || !isset($post['datafield_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid job data');

            // Pull data from the post
            $xml_filename = $post['xml_filename'];
            $datatype_id = $post['datatype_id'];
            $datafield_id = $post['datafield_id'];
            $user_id = $post['user_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $router = $this->get('router');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->get('doctrine')->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');


            // Enable user error handling
            libxml_use_internal_errors(true);
            $schema_path = dirname(__FILE__).'/../../../../web/uploads/xsd/';
            $xml_path = dirname(__FILE__).'/../../../../web/uploads/xml/';
            $ret = '';

            // Determine the schema filename
            $schema_filename = '';
            if ($datatype_id !== '') {
                $datatype = $repo_datatype->find($datatype_id);
                $schema_filename = $datatype->getXMLShortName().'.xsd';
            }
            else if ($datafield_id !== '') {
                $datafield = $repo_datafield->find($datafield_id);
                $schema_filename = 'Datafield_'.$datafield->getId().'.xsd';
            }
            else {
                throw new \Exception('Invalid job data');
            }

            // Attempt to validate the given xml file against the correct XSD file
            // Load the file as an XML Document
            $xml_file = new \DOMDocument();
            $ret .= 'Attempting to load "'.$xml_path.'unprocessed/'.$xml_filename.'"'."\n";
            if ($xml_file->load($xml_path.'unprocessed/'.$xml_filename) !== false) {
                $ret .= 'Loaded "'.$xml_filename.'"'."\n";

                // Attempt to validate the XML file...
                if (!$xml_file->schemaValidate($schema_path.$schema_filename)) {
                    // If validation failed, display errors
                    $ret .= 'Schema errors in "'.$xml_filename.'" >> '.self::libxml_display_errors()."\n";
                    $logger->err('WorkerController:importvalidateAction()  Schema errors in "'.$xml_filename.'" >> '.self::libxml_display_errors());

                    // Move to failed directory
                    if ( rename($xml_path.'unprocessed/'.$xml_filename, $xml_path.'failed/'.$xml_filename) ) {
                        $logger->info('Moved "'.$xml_filename.'" to failed directory');
                        $ret .= 'Moved "'.$xml_filename.'" to failed directory';
                    }
                    else {
                        throw new \Exception('Could not move "'.$xml_filename.'" to failed directory');
                    }

                }
                else {
                    $ret .= 'Validated "'.$xml_filename.'"'."\n";

                    // Queue the file for a full import...
                    $url = $this->container->getParameter('site_baseurl');
                    $url .= $router->generate('odr_import_worker');

                    $importing = true;
                    $payload = json_encode(
                        array(
                            'xml_filename' => $xml_filename,
                            'api_key' => $beanstalk_api_key,
                            'url' => $url,
                            'memcached_prefix' => $memcached_prefix,    // debug purposes only
                            'datatype_id' => $datatype_id,
                            'datafield_id' => $datafield_id,
                            'user_id' => $user_id
                        )
                    );
                    $pheanstalk->useTube('import_datarecord')->put($payload);
                    $ret .= 'No schema errors in "'.$xml_filename.'", scheduled for import'."\n";

                 }
            }
            else {
                // Could not load XML file
                throw new \Exception('Could not load "'.$xml_filename.'" for validation >> '.self::libxml_display_errors());
            }

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x643392754 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called by whichever background process is watching the 'import_worker' beanstalk tube, this
     * function will go through the file and attempt to import its contents into a DataRecord.
     * 
     * @param Request $request
     * 
     * @return TODO
     */
    public function importworkerAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['xml_filename']) || !isset($post['datatype_id']) || !isset($post['datafield_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid job data');

            // Pull data from the post
            $xml_filename = $post['xml_filename'];
            $datatype_id = $post['datatype_id'];
            $datafield_id = $post['datafield_id'];
            $user_id = $post['user_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->get('doctrine')->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
            $user = $repo_user->find($user_id);

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');

            if ($datatype_id == '' && $datafield_id == '')
                throw new \Exception('Invalid job data');

            // Enable user error handling
            libxml_use_internal_errors(true);
            $schema_path = dirname(__FILE__).'/../../../../web/uploads/xsd/';
            $xml_path = dirname(__FILE__).'/../../../../web/uploads/xml/';
            $ret = "\n----------\n";

            // Load the file as an XML Document
            $xml_file = new \DOMDocument();
            $ret .= 'Attempting to load '.$xml_path.'unprocessed/'.$xml_filename."\n";
            if ($xml_file->load($xml_path.'unprocessed/'.$xml_filename) === false)
                throw new \Exception('Could not load "'.$xml_filename.'" for import >> '.self::libxml_display_errors());


            $parent_datatype = null;
            $datafield = null;
            if ($datatype_id !== '') {
                $parent_datatype = $repo_datatype->find($datatype_id);
            }
            else if ($datafield_id !== '') {
                $datafield = $repo_datafield->find($datafield_id);
                $parent_datatype = $datafield->getDataType();
            }


            // Import the object
            $xml_entities = null;
            if ($datafield == null)
                $xml_entities = $xml_file->getElementsByTagName('datarecord');
            else
                $xml_entities = $xml_file->getElementsByTagName('radio_option');

$write = true;
//$write = false;

            foreach ($xml_entities as $xml_entity) {

                // Need to keep track of the entities created for this import
                $created_objects = array();
                $import_ret = null;

                if ($datafield == null) {

                    // TODO - uniqueness

                    $indent = 0;

                    // ----------------------------------------
                    // Attempt to locate a pre-existing datarecord to import into
                    $grandparent = null;
                    $update_datarecord = false;
                    // Attempt to locate an external id for the datarecord
                    $metadata = self::getODRMetadata($xml_entity);
                    if ( isset($metadata['external_id']) ) {
                        $external_id = $metadata['external_id'];
                        $grandparent = $repo_datarecord->findOneBy( array('external_id' => $external_id, 'dataType' => $parent_datatype->getId()) );

                        $ret .= 'Attempting to find datarecord identified by external_id: "'.$external_id."\"...\n";
                    }

                    if ($grandparent == null) {
                        // Create a new top-level DataRecord object for the database
if ($write) {
                        $grandparent = parent::ODR_addDataRecord($em, $user, $parent_datatype);
                        $grandparent->setParent($grandparent);
                        $grandparent->setGrandparent($grandparent);

                        $em->persist($grandparent);
                        $em->flush();
                        $em->refresh($grandparent);

                        // Create all datarecordfield and storage entities required for this datarecord
//                        parent::verifyExistence($grandparent->getDataType(), $grandparent);   // this should already happen in parent::ODR_addDataRecord()    
                        $created_objects = array($grandparent);
}
else {
    $grandparent = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy( array("dataType" => $parent_datatype->getId()) );   // DEBUGGING
}

                        $ret .= 'Creating new datarecord...'."\n";
                    }
                    else {
                        $update_datarecord = true;
                        $ret .= 'Using existing datarecord...'."\n";
                    }

                    // Go through the process of importing this datarecord
                    $import_ret = self::importDatarecord($em, $user, $xml_entity, $grandparent, $grandparent, $update_datarecord, $write, $indent);

                }
                else {
                    // Go through the process of importing this radio option
                    $import_ret = self::importRadioOption($em, $user, $xml_entity, $datafield, $write, $indent);
                }


                // ----------------------------------------
                // Check for errors during the import of this entity
                $error_during_import = $import_ret['error'];
                $ret .= $import_ret['status'];
                $created_objects = array_merge($created_objects, $import_ret['objects']);


                // ----------------------------------------
                // If there was some sort of error while importing...
                if ($error_during_import) {
                    $ret .= "Error during import, attempting to remove created objects...";

if ($write) {
                    foreach ($created_objects as $obj)
                        $em->remove($obj);
                    $em->flush();

                    // Move xml file to failed directory
                    if ( rename($xml_path.'unprocessed/'.$xml_filename, $xml_path.'failed/'.$xml_filename) ) {
                        $logger->info('Moved "'.$xml_filename.'" to failed directory');
                        $ret .= 'Moved "'.$xml_filename.'" to failed directory';
                    }
                    else {
                        throw new \Exception('Could not move "'.$xml_filename.'" to failed directory');
                    }
}   // end if ($write)

                }
                else {

if ($write) {
                    // No errors
                    // Flush all changes to the database
                    $em->flush();

                    // Move xml file to succeeded directory
                    if ( rename($xml_path.'unprocessed/'.$xml_filename, $xml_path.'succeeded/'.$xml_filename) ) {
                        $logger->info('Moved "'.$xml_filename.'" to succeeded directory');
                        $ret .= 'Moved "'.$xml_filename.'" to succeeded directory'."\n";

                        // Rebuild the list of sorted datarecords, since the datarecord order may have changed
                        $memcached->delete($memcached_prefix.'.data_type_'.$datatype_id.'_record_order');
                    }
                    else {
                        throw new \Exception('Could not move "'.$xml_filename.'" to succeeded directory');
                    }
}   // end if ($write)

                }
            }

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x6642397855 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Helper function for debugging importing.
     *
     * @param integer $level How many tabs to print out so debug output looks nice
     *
     * @return string
     */
    private function indent($level)
    {
        $str = "\n";
        for ($i = 0; $i < $level; $i++)
            $str .= '  ';

        return $str;
    }


    /**
     * Does the actual work of importing everything for a datarecord, and links to datarecords if necessary
     * 
     * @param EntityManager $em
     * @param User $user
     * @param DOMNodeList $xml_datarecord        The XML structure describing the data that is being imported into this datarecord
     * @param DataRecord $datarecord             The datarecord that is getting data imported into it
     * @param DataRecord $grandparent_datarecord The top-level datarecord, if this function is currently working on importing a child datarecord
     * @param boolean $update_datarecord         Whether to attempt to update the existing data in the datarecord, or just create new storage entities for everything
     * 
     * @param boolean $write whether to persist/flush all changes
     * @param integer $indent
     * 
     * @return array
     */
    private function importDatarecord($em, $user, $xml_datarecord, $datarecord, $grandparent_datarecord, $update_datarecord, $write, $indent)
    {

        // Need to keep track of whether an error occurred or not, and the objects created up to the point of the error
        $error_during_import = false;
        $created_objects = array();

        // ----------------------------------------
        // Import metadata about this datarecord
        $metadata = self::getODRMetadata($xml_datarecord);

        $external_id = null;
        if ( isset($metadata['external_id']) ) {
            $external_id = $metadata['external_id'];
            $datarecord->setExternalId($external_id);
        }

        $create_date = null;
        if ( isset($metadata['create_date']) ) {
            $create_date = $metadata['create_date'];
            $datarecord->setCreated( new \DateTime($create_date) );
        }

        $create_auth = null;
        if ( isset($metadata['create_auth']) ) {
            $create_auth = $metadata['create_auth'];
            $datarecord->setCreatedBy($create_auth);
        }

        $public_date = null;
        if ( isset($metadata['public_date']) ) {
            $public_date = $metadata['public_date'];
            $datarecord->setPublicDate( new \DateTime($public_date) );
        }

if ($write) {
            $em->persist($datarecord);
}

        $ret = "\n";
        $ret .= self::indent($indent).'-- _datarecord_metadata';
        $ret .= self::indent($indent+1).'>> external_id: '.$external_id;
        $ret .= self::indent($indent+1).'>> create_date: '.$create_date;
        $ret .= self::indent($indent+1).'>> create_auth: '.$create_auth;
        $ret .= self::indent($indent+1).'>> public_date: '.$public_date;


        // ----------------------------------------
        $ret .= self::indent($indent).'---------------';
        $ret .= self::indent($indent).'-- datafields';

        // Import datafields directly attached to this datarecord
        $datatype = $datarecord->getDataType();
        foreach ($datatype->getDataFields() as $datafield) {
            // Locate the XML element that holds the data for this field...because xml document is valid, this will always return something
            $xml_element = $xml_datarecord->getElementsByTagName( $datafield->getXMLFieldName() )->item(0);
            $ret .= self::indent($indent+1).'>> '.$datafield->getXMLFieldName();

            // Import the data from the XML element
            $import_ret = self::importData($em, $user, $xml_element, $datarecord, $datafield, $update_datarecord, $write, $indent+2);

            $ret .= ' => '.$import_ret['status'];

            if ( $import_ret['error'] == true ) {
                // Encountered some sort of error during importing, stop trying to import immediately
                $error_during_import = true;
                $ret .= $import_ret['status'];
                break;
            }
            else {
                // Save the entities created during the import of this datafield
                $created_objects = array_merge($created_objects, $import_ret['objects']);
            }

        }


        // ----------------------------------------
        // Need to deal with any occurence of child/linked datatypes now...
        $child_datatypes = array();
        $linked_datatypes = array();
        if (!$error_during_import) {
            // Grab all linked/child types that the importer might have to deal with
            $query = $em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataTree AS dt
                WHERE dt.ancestor = :datatype OR dt.descendant = :datatype
                AND dt.deletedAt IS NULL'
            )->setParameters( array('datatype' => $datatype) );
            $results = $query->getResult();
            foreach ($results as $num => $datatree) {
                $ancestor = $datatree->getAncestor();
                $descendant = $datatree->getDescendant();

                if ($datatree->getIsLink() == 1) {
                    // Want to keep track of datatypes regardless of which side of the link they're on...it's bidirectional
                    if ($ancestor->getId() == $datatype->getId())
                        array_push($linked_datatypes, $descendant);
                    else
                        array_push($linked_datatypes, $ancestor);
                }
                else {
                    // Only want to keep track of child datatypes
                    if ($ancestor->getId() == $datatype->getId())
                        array_push($child_datatypes, $descendant);
                }
            }
        }


        // ----------------------------------------
        $ret .= "\n";
        $ret .= self::indent($indent).'-- child datarecords';

        // Search for any child datarecords that need to be created
        if (!$error_during_import) {
            foreach ($child_datatypes as $num => $child_datatype) {
                $ret .= self::indent($indent+1).'>> '.$child_datatype->getXMLShortName();

                // ------------------------------
                // Go looking through the xml for datarecord instances of childtypes
                $tmp = $xml_datarecord->getElementsByTagName( $child_datatype->getXMLShortName() );
                if ( $tmp->length > 0 ) {
                    $xml_child_datarecords = $tmp->item(0)->getElementsByTagName( '_'.$child_datatype->getXMLShortName().'_child' );
                    if ( $xml_child_datarecords->length == 0 )
                        $xml_child_datarecords = array();

                    foreach ($xml_child_datarecords as $xml_child_datarecord) {
                        // ------------------------------
                        // Grab metadata from child datarecord
                        $external_id = null;
                        $odr_keyfield = null;
                        $odr_namefield = null;

                        $direct = true;    // TODO - why the hell is this required...conceptually it shouldn't be...
                        $metadata = self::getODRMetadata($xml_child_datarecord, $direct);
                        if ( isset($metadata['external_id']) )
                            $external_id = $metadata['external_id'];
                        if ( isset($metadata['odr_keyfield']) )
                            $odr_keyfield = $metadata['odr_keyfield'];
                        if ( isset($metadata['odr_namefield']) )
                            $odr_namefield = $metadata['odr_namefield'];

                        $ret .= self::indent($indent+2).'-- looking for datarecord of datatype '.$child_datatype->getId().' with...';

                        // ------------------------------
                        // Attempt to locate child datarecord with that metadata
                        $child_datarecord = null;
                        if ($external_id !== null) {
                            $ret .= 'external_id: '.$external_id;
                            $child_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy( array('dataType' => $child_datatype->getId(), 'external_id' => $external_id) );
                        }
                        else if ($odr_keyfield !== null)  {
                            $ret .= 'odr_keyfield: '.$odr_keyfield.' DISABLED';
//                            $child_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy( array('dataType' => $remote_datatype->getId(), '' => $odr_keyfield) );
                        }
                        else if ($odr_namefield !== null)  {
                            $ret .= 'odr_namfield: '.$odr_namefield.' DISABLED';
//                            $child_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy( array('dataType' => $remote_datatype->getId(), '' => $odr_namefield) );
                        }

                        // ------------------------------
                        // Ensure a child datarecord entity exists to import into
                        $update_child_datarecord = false;
                        if ($child_datarecord == null) {
                            $ret .= ' ...not found, creating new child_datarecord';

if ($write) {
                            $child_datarecord = parent::ODR_addDataRecord($em, $user, $child_datatype);
                            $child_datarecord->setParent($datarecord);
                            $child_datarecord->setGrandparent($grandparent_datarecord);

                            $em->persist($child_datarecord);
                            $em->flush();
                            $em->refresh($child_datarecord);

                            // Create all datarecordfield and storage entities required for this datarecord
//                            parent::verifyExistence($child_datarecord->getDataType(), $child_datarecord); // ODR_addDataRecord() should take care of this...
                            $created_objects = array_merge($created_objects, array($child_datarecord));
}
else {
    $child_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy( array("dataType" => $child_datatype->getId()) );   // TEST
}

                        }
                        else {
                            $update_child_datarecord = true;
                            $ret .= ' ...Using existing child_datarecord...'."\n";
                        }

                        // ------------------------------
                        // Go through the process of importing this child datarecord
                        $import_ret = self::importDatarecord($em, $user, $xml_child_datarecord, $child_datarecord, $grandparent_datarecord, $update_child_datarecord, $write, $indent+3);

                        $error_during_import = $import_ret['error'];
                        $ret .= $import_ret['status'];
                        $created_objects = array_merge($created_objects, $import_ret['objects']);

                        if ($error_during_import)
                            break;
                    }

                    if ($error_during_import)
                        break;
                }
            }
        }


        // ----------------------------------------
        $ret .= "\n";
        $ret .= self::indent($indent).'-- linked datarecords';

        // Search for any datarecords that need to be linked to...
        // TODO - delete links not specified in the file
        // TODO - modify schema to permit updating links from both sides, instead of just from the 'parent' side?
        if (!$error_during_import) {
            foreach ($linked_datatypes as $num => $remote_datatype) {
                $ret .= self::indent($indent+1).'>> '.$remote_datatype->getXMLShortName();

                $tmp = $xml_datarecord->getElementsByTagName( $remote_datatype->getXMLShortName() );
                if ( $tmp->length > 0 ) {
                    $linked_datarecords = $tmp->item(0)->getElementsByTagName('linked_type');
                    foreach ($linked_datarecords as $linked_datarecord) {
                        // ------------------------------
                        // Grab metadata from linked datarecord
                        $external_id = null;
                        $odr_keyfield = null;
                        $odr_namefield = null;

                        $direct = true;
                        $metadata = self::getODRMetadata($linked_datarecord, $direct);
                        if ( isset($metadata['external_id']) )
                            $external_id = $metadata['external_id'];
                        if ( isset($metadata['odr_keyfield']) )
                            $odr_keyfield = $metadata['odr_keyfield'];
                        if ( isset($metadata['odr_namefield']) )
                            $odr_namefield = $metadata['odr_namefield'];

                        $ret .= self::indent($indent+2).'-- looking for datarecord of datatype '.$remote_datatype->getId().' with...';

                        // ------------------------------
                        // Attempt to locate remote datarecord with that metadata
                        $remote_datarecord = null;
                        if ($external_id !== null) {
                            $ret .= 'external_id: '.$external_id;
                            $remote_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy( array('dataType' => $remote_datatype->getId(), 'external_id' => $external_id) );
                        }
                        else if ($odr_keyfield !== null)  {
                            $ret .= 'odr_keyfield: '.$odr_keyfield.' DISABLED';
//                            $remote_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy( array('dataType' => $remote_datatype->getId(), '' => $odr_keyfield) );
                        }
                        else if ($odr_namefield !== null)  {
                            $ret .= 'odr_namfield: '.$odr_namefield.' DISABLED';
//                            $remote_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy( array('dataType' => $remote_datatype->getId(), '' => $odr_namefield) );
                        }

                        // ------------------------------
                        // Attempt to link to that remote datarecord
                        if ($remote_datarecord == null) {
                            $ret .= ' ...ERROR: not found';
                        }
                        else {
                            $ret .= ' ...found';
if ($write) {
                            // Create a link between the current datarecord and the remote datarecord
                            parent::ODR_linkDataRecords($em, $user, $datarecord, $remote_datarecord);
                            $ret .= ' ...linked';
}
                        }
                        $ret .= "\n";
                    }
                }
            }
        }

        $ret .= "\n".self::indent($indent).'---------------'."\n";

        // ----------------------------------------
        // Return results of importing this datarecord
        $return = array();
        $return['status'] = $ret;
        $return['error'] = $error_during_import;
        $return['objects'] = $created_objects;

        return $return;
    }


    /**
     * Given an XML DOMNodeList, converts the contents of any of the tags ['_datarecord_metadata', '_image_metadata', '_file_metadata', '_option_metadata'] into an array and returns it.
     * Each xml entity can only have one of the above tags at any given point by definition.
     * 
     * @param DOMNodeList $xml_entity
     * 
     * @return TODO
     */
    private function getODRMetadata($xml_entity, $direct = false)
    {
        $odr_metadata = array();

        // Grab the metadata element
        $metadata = null;
        if ( !$direct ) {
            $tmp = $xml_entity->getElementsByTagName('_datarecord_metadata');
            if ($metadata == null && $tmp->length > 0)
                $metadata = $tmp->item(0);
            $tmp = $xml_entity->getElementsByTagName('_file_metadata');
            if ($metadata == null && $tmp->length > 0)
                $metadata = $tmp->item(0);
            $tmp = $xml_entity->getElementsByTagName('_image_metadata');
            if ($metadata == null && $tmp->length > 0)
                $metadata = $tmp->item(0);
            $tmp = $xml_entity->getElementsByTagName('_option_metadata');
            if ($metadata == null && $tmp->length > 0)
                $metadata = $tmp->item(0);
        }
        else {
            $metadata = $xml_entity;
        }


        // If metadata exists...
        if ($metadata !== null) {
            // --------------------
            // Common properties
            $tmp = $metadata->getElementsByTagName('_create_date');
            if ($tmp->length > 0)
                $odr_metadata['create_date'] = $tmp->item(0)->nodeValue;
//            $tmp = $metadata->getElementsByTagName('_create_auth');
//            if ($tmp->length > 0)
//                $odr_metadata['create_auth'] = $tmp->item(0)->nodeValue;
            $tmp = $metadata->getElementsByTagName('_public_date');
            if ($tmp->length > 0)
                $odr_metadata['public_date'] = $tmp->item(0)->nodeValue;
            $tmp = $metadata->getElementsByTagName('_external_id');
            if ($tmp->length > 0)
                $odr_metadata['external_id'] = $tmp->item(0)->nodeValue;


            // --------------------
            // Datarecord-only properties
            /* none right now */

            // --------------------
            // File-only properties
            /* none right now */

            // --------------------
            // Image-only properties
            $tmp = $metadata->getElementsByTagName('_display_order');
            if ($tmp->length > 0)
                $odr_metadata['display_order'] = $tmp->item(0)->nodeValue;

            // --------------------
            // RadioOption-only properties
            $tmp = $metadata->getElementsByTagName('_parent_id');
            if ($tmp->length > 0)
                $odr_metadata['parent_id'] = $tmp->item(0)->nodeValue;
        }

        return $odr_metadata;
    }

    /**
     * Does the actual work of importing everything for a radio option
     * 
     * @param EntityManager $em
     * @param User $user
     * @param DOMNodeList $xml_entity The XML describing this RadioOption entity
     * @param DataFields $datafield   The DataField this RadioOption belongs to
     * 
     * @param boolean $write  Whether to persist/flush all changes
     * @param integer $indent Debugging purposes...
     * 
     * @return array
     */
    private function importRadioOption($em, $user, $xml_entity, $datafield, $write, $indent)
    {

        // Need to keep track of whether an error occurred or not, and the objects created up to the point of the error
        $error_during_import = false;
        $created_objects = array();

        // Grab the required 
        $option_name = $xml_entity->getElementsByTagName('option_name')->item(0)->nodeValue;

        // Grab metadata if possible
        $external_id = null;
        $parent_id = null;
        $create_date = null;

        $metadata = self::getODRMetadata($xml_entity);
        if ( isset($metadata['external_id']) )
            $external_id = $metadata['external_id'];
        if ( isset($metadata['parent_id']) )
            $parent_id = $metadata['parent_id'];
        if ( isset($metadata['create_date']) )
            $create_date = $metadata['create_date'];


        // Find a pre-existing radio option if possible
        $ret = '';
        $radio_option = null;
        if ($external_id !== null) {
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy( array('dataFields' => $datafield->getId(), 'external_id' => $external_id) );
        }

        if ($radio_option == null) {
            // No pre-existing radio option, need to create a new radio option

if ($write) {
            $radio_option = parent::ODR_addRadioOption($em, $user, $datafield);
            $em->flush();
            $em->refresh($radio_option);
}
else {
    $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find(1);
}

            $ret = 'Created new radio_option for ';
        }
        else {
            $ret = 'Loaded existing radio_option (id: '.$radio_option->getId().') for ';
        }

        // Save the option name
        $radio_option->setOptionName($option_name);
        $ret .= '"'.$option_name.'" >> ';

        // Set the optional attributes
        if ($create_date !== null) {
            $radio_option->setCreated( new \DateTime($create_date) );
            $ret .= 'create_date: '.$create_date.' ';
        }
        if ($external_id !== null) {
            $radio_option->setExternalId($external_id);
            $ret .= 'external_id: '.$external_id.' ';
        }
        if ($parent_id !== null && $parent_id !== '0') {
            // TODO - parent using internal id?  only seems to make sense when it's external...

            $parent = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy( array('dataFields' => $datafield->getId(), 'external_id' => $parent_id) );
            if ($parent == null) {
                $error_during_import = true;
                $ret .= 'ERROR: could not find parent';
            }
            else {
                $ret .= 'parent_id (df '.$datafield->getId().'): '.$parent_id.' ';
                $radio_option->setParent($parent);
            }
        }
        $ret .= "\n";


if ($write) {
        $em->persist($radio_option);
        $em->flush();
}

        // Return results of importing this datarecord
        $return = array();
        $return['status'] = $ret;
        $return['error'] = $error_during_import;
        $return['objects'] = $created_objects;

        return $return;
    }


    /**
     * Creates a new Entity to hold the XML data from $element.
     * 
     * @param Manager $entity_manager
     * @param User $user
     * @param DOMElement $element        The xml describing this element
     * @param DataRecord $datarecord     The datarecord getting this piece of data
     * @param DataFields $datafield      The datafield this data is being stored in
     * @param boolean $update_datarecord Whether to attempt to update the existing data in the datarecord, or just create new storage entities for everything
     * 
     * @param boolean $write  Whether to persist/flush all changes
     * @param integer $indent Debugging purposes...
     * 
     * @return array
     */
    private function importData($entity_manager, $user, $element, $datarecord, $datafield, $update_datarecord, $write, $indent)
    {
        $status = '';

        $return = array();
        $return['status'] = '';
        $return['error'] = false;
        $return['objects'] = array();

        // Shouldn't happen, obviously
        if ($element == null) {
            $return['status'] = 'ERROR: element equal to null';
            return $return;
        }

        // Going to need these for file/image downloads...
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
        $api_key = $this->container->getParameter('beanstalk_api_key');
        $router = $this->container->get('router');
        $pheanstalk = $this->get('pheanstalk');
        $url = $this->container->getParameter('site_baseurl');
        $url .= $router->generate('odr_import_file');


        if ($element->getElementsByTagName('file')->length > 0) {

            try {
                // Iterate through all the <file> tags for this element in the XML
                $files = $element->getElementsByTagName('file');
                foreach ($files as $file) {
                    // ------------------------------
                    // Grab the file's properties from the XML
                    $filename = $file->getElementsByTagName('original_name')->item(0)->nodeValue;
                    $href = $file->getElementsByTagName('href')->item(0)->nodeValue;

                    $filename = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $filename);
                    $href = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $href);

//print $filename.' '.$href;

                    // No point if there's no href?
                    if ($href == '') {
                        // No href specified, notify of failure
                        $status .= self::indent($indent).'No href specified for file "'.$filename.'", skipping...';
                        continue;

                        // TODO - throw exception instead?
                    }

                    // ------------------------------
                    // Check for metadata
                    $create_date = null;
                    $create_auth = null;
                    $public_date = '2200-01-01 00:00:00';   // default to not public
                    $external_id = null;

                    $metadata = self::getODRMetadata($file);
                    if ( isset($metadata['create_date']) )
                        $create_date = $metadata['create_date'];
                    if ( isset($metadata['create_auth']) )
                        $create_auth = $metadata['create_auth'];
                    if ( isset($metadata['public_date']) )
                        $public_date = $metadata['public_date'];
                    if ( isset($metadata['external_id']) )
                        $external_id = $metadata['external_id'];


                    // ------------------------------
                    // Attempt to locate an already existing file entity
                    $file_obj = null;

                    if ($external_id !== null) {
                        $file_obj = $entity_manager->getRepository('ODRAdminBundle:File')->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId(), 'external_id' => $external_id) );

//                        print self::indent($indent).'Attempting to locate file for DataRecord '.$datarecord->getId().', DataField '.$datafield->getId().', with external_id '.$external_id.'...';
                        $status .= self::indent($indent).'Attempting to locate file for DataRecord '.$datarecord->getId().', DataField '.$datafield->getId().', with external_id '.$external_id.'...';
                    }

                    // If file object doesn't exist, create it
                    if ($file_obj == null) {
if ($write) {
                        // Need to explicitely state this is a file fieldtype
                        $fieldtype = $entity_manager->getRepository('ODRAdminBundle:FieldType')->find('2');

                        $file_obj = new File();
                        $file_obj->setFieldType($fieldtype);
                        $file_obj->setOriginalFileName($filename);
                        $file_obj->setOriginalChecksum('');
                        $file_obj->setLocalFileName('temp');
                        $file_obj->setGraphable('1');
                        $file_obj->setUpdatedBy($user);
                        $file_obj->setCreatedBy($user);
                        $file_obj->setDataField($datafield);
                        $file_obj->setDataRecord($datarecord);
//                        $file_obj->setExt('temp');
                        $data_record_field = $entity_manager->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array("dataField" => $datafield->getId(), "dataRecord" => $datarecord->getId()) );
                        $file_obj->setDataRecordFields($data_record_field);

                        $file_obj->setPublicDate(new \DateTime($public_date));
                        if ($create_date !== null)
                            $file_obj->setCreated( new \DateTime($create_date) );
                        if ($create_auth !== null)
                            $file_obj->setCreatedBy($create_auth);
                        if ($external_id !== null)
                            $file_obj->setExternalId($external_id);

                        $entity_manager->persist($file_obj);
                        $entity_manager->flush();
                        $entity_manager->refresh($file_obj);

                        // Need to store all file objects so they can all be deleted if necessary
                        $return['objects'][] = $file_obj;
}    // end if ($write)
else {
    $file_obj = $entity_manager->getRepository('ODRAdminBundle:File')->findOneBy( array('deletedAt' => null) ); // just grab any file for debug purposes
}

                        $status .= '...not found, creating new File';
                    }
                    else {
                        // TODO - properties to update files with go here
                        $file_obj->setPublicDate(new \DateTime($public_date));
                        $file_obj->setOriginalFileName($filename);

if ($write) {
                        $entity_manager->persist($file_obj);
}

                        $status .= 'found';
                    }


if ($write) {

                    // ------------------------------
                    // Due to timeout concerns, get a separate worker process to download the file
                    $status .= self::indent($indent).' >> scheduled file for download';

                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "object_type" => 'File',
                            "object_id" => $file_obj->getId(),
                            "user_id" => $user->getId(),
                            "href" => $href,
                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('import_file')->put($payload, $priority, $delay);
}

                    // ------------------------------
                    $status .= self::indent($indent).'-- filename: '.$filename;
                    $status .= self::indent($indent).'-- href: '.$href;
                    $status .= self::indent($indent).'-- create_auth: '.$create_date;
                    $status .= self::indent($indent).'-- create_date: '.$create_auth;
                    $status .= self::indent($indent).'-- public_date: '.$public_date;
                    $status .= self::indent($indent).'-- external_id: '.$external_id;
                    $status .= "\n";
                }


            }
            catch (\Exception $e) {
                $return['error'] = true;
            }
        }
        else if ($element->getElementsByTagName('image')->length > 0) {

            try {
                // Iterate through all the <image> tags for this element in the XML
                $images = $element->getElementsByTagName('image');
                foreach ($images as $image) {
                    // ------------------------------
                    // Grab the image's properties from the XML
                    $original_name = $image->getElementsByTagName('original_name')->item(0)->nodeValue;
                    $href = $image->getElementsByTagName('href')->item(0)->nodeValue;
                    $caption = $image->getElementsByTagName('caption')->item(0)->nodeValue;

                    $original_name = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $original_name);
                    $href = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $href);
                    $caption = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $caption);

                    // No point if there's no href?
                    if ($href == '') {
                        // No href specified, notify of failure
                        $status .= 'No href specified for image "'.$original_name.'", skipping...';
                        continue;

                        // TODO - throw exception instead?
                    }

                    // ------------------------------
                    // Check for metadata
                    $create_date = null;
                    $create_auth = null;
                    $display_order = 0;
                    $public_date = '1980-01-01 00:00:00';   // default to public?
                    $external_id = null;

                    $metadata = self::getODRMetadata($image);
                    if ( isset($metadata['display_order']) )
                        $display_order = $metadata['display_order'];
                    if ( isset($metadata['create_date']) )
                        $create_date = $metadata['create_date'];
                    if ( isset($metadata['create_auth']) )
                        $create_auth = $metadata['create_auth'];
                    if ( isset($metadata['public_date']) )
                        $public_date = $metadata['public_date'];
                    if ( isset($metadata['external_id']) )
                        $external_id = $metadata['external_id'];


                    // ------------------------------
                    // Attempt to locate an already existing image entity
                    $image_obj = null;

                    if ($external_id !== null) {
                        $image_obj = $entity_manager->getRepository('ODRAdminBundle:Image')->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId(), 'external_id' => $external_id) );

                        $status .= 'Attempting to locate image for DataRecord '.$datarecord->getId().', DataField '.$datafield->getId().', with external_id '.$external_id.'...';
                    }

                    // If image object doesn't exist, create it
                    if ($image_obj == null) {
                        // Need to explicitely state this is an image fieldtype
                        $fieldtype = $entity_manager->getRepository('ODRAdminBundle:FieldType')->find('3');

if ($write) {
                        $image_obj = new Image();
                        $image_obj->setFieldType($fieldtype);
                        $image_obj->setOriginalFileName($original_name);
                        $image_obj->setOriginalChecksum('');
                        $image_obj->setLocalFileName('temp');
                        $image_obj->setDisplayOrder($display_order);
                        $image_obj->setUpdatedBy($user);
                        $image_obj->setDataField($datafield);
                        $image_obj->setDataRecord($datarecord);
                        $image_obj->setCaption($caption);
                        $image_obj->setOriginal(true);

                        $image_obj->setPublicDate(new \DateTime($public_date));
                        if ($create_date !== null)
                            $image_obj->setCreated( new \DateTime($create_date) );
                        if ($create_auth !== null)
                            $image_obj->setCreatedBy($create_auth);
                        if ($external_id !== null)
                            $image_obj->setExternalId($external_id);

                        $data_record_field = $entity_manager->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array("dataField" => $datafield->getId(), "dataRecord" => $datarecord->getId()) );
                        $image_obj->setDataRecordFields($data_record_field);

                        $entity_manager->persist($image_obj);
                        $entity_manager->flush();
                        $entity_manager->refresh($image_obj);

                        $return['objects'][] = $image_obj;
}    // end of if ($write)
else {
    $image_obj = $entity_manager->getRepository('ODRAdminBundle:Image')->findOneBy( array('deletedAt' => null) );   // just grab any image for debug purposes
}

                        $status .= '...not found, creating new Image';
                    }
                    else {
                        // TODO - properties of image to update here
                        $image_obj->setDisplayOrder($display_order);
                        $image_obj->setOriginalFileName($original_name);
                        $image_obj->setPublicDate(new \DateTime($public_date));

if ($write) {
                        $entity_manager->persist($image_obj);
}

                        $status .= 'found';
                    }


if ($write) {

                    // ------------------------------
                    // Due to timeout concerns, get a separate worker process to download the file
                    $status .= self::indent($indent).' >> scheduled file for download';

                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "object_type" => 'Image',
                            "object_id" => $image_obj->getId(),
                            "user_id" => $user->getId(),
                            "href" => $href,
                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('import_file')->put($payload, $priority, $delay);
}

                    // ------------------------------
                    $status .= self::indent($indent).'-- original_name: '.$original_name;
                    $status .= self::indent($indent).'-- href: '.$href;
                    $status .= self::indent($indent).'-- caption: '.$caption;
                    $status .= self::indent($indent).'-- create_auth: '.$create_date;
                    $status .= self::indent($indent).'-- create_date: '.$create_auth;
                    $status .= self::indent($indent).'-- public_date: '.$public_date;
                    $status .= self::indent($indent).'-- display_order: '.$display_order;
                    $status .= "\n";
                }

            }
            catch (\Exception $e) {
                $return['error'] = true;
            }
        }
        else if ($element->getElementsByTagName('radio_option')->length > 0) {

            try {
//                $status .= '~ ';

                // Grab the type of option from the XML
//                $option_type = $element->getAttribute('type');
//                $status .= 'type="'.$option_type.'" ';

                // Iterate through all the <option> tags for this element in the XML
                $options = $element->getElementsByTagName('radio_option');
                foreach ($options as $option) {
                    // Grab the option's properties from the XML
                    $option_name = $option->getElementsByTagName('option_name')->item(0)->nodeValue;
                    $value = $option->getElementsByTagName('value')->item(0)->nodeValue;

                    $option_name = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $option_name);
                    $value = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $value);

                    // Attempt to grab radio option metadata
                    $external_id = null;
                    $create_date = null;
                    $create_auth = null;

                    $metadata = self::getODRMetadata($option);
                    if ( isset($metadata['external_id']) )
                        $external_id = $metadata['external_id'];
                    if ( isset($metadata['create_date']) )
                        $create_date = $metadata['create_date'];
                    if ( isset($metadata['create_auth']) )
                        $create_auth = $metadata['create_auth'];


                    // Grab the radio option this field is supposed to represent
                    $radio_option = null;
                    if ($external_id !== null) {
                        // attempt to locate it via external id first
                        $radio_option = $entity_manager->getRepository('ODRAdminBundle:RadioOptions')->findOneBy( array("external_id" => $external_id, "dataFields" => $datafield->getId()) );
                    }
                    else if ($option_name !== '') {
                        // fall back to using option name
                        $radio_option = $entity_manager->getRepository('ODRAdminBundle:RadioOptions')->findOneBy( array("optionName" => $option_name, "dataFields" => $datafield->getId()) );
                    }

                    if ($radio_option == null) {
                        // This shouldn't happen because the document is valid
                        throw new \Exception('Could not find option_name: "'.$option_name.'", external_id: "'.$external_id.'" in the database');
                    }


                    // Attempt to locate a radio_selction object for this
                    $datarecordfield = $entity_manager->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array("dataField" => $datafield->getId(), "dataRecord" => $datarecord->getId()) );
                    $radio_selection = $entity_manager->getRepository('ODRAdminBundle:RadioSelection')->findOneBy( array("radioOption" => $radio_option->getId(), "dataRecordFields" => $datarecordfield->getId()) );

                    $selected = 0;
                    if ($value == 'selected')   // TODO - change the xml to use something other than 'selected', probably
                        $selected = 1;

if ($write) {
                    if ($radio_selection == null) {
                        // Create a new RadioSelection object
                        $radio_selection = parent::ODR_addRadioSelection($entity_manager, $user, $radio_option, $datarecordfield, $selected);

                        // Store the radio option so they can all be deleted if an error occurs
                        $return['objects'][] = $radio_selection;
                    }
                    else {
                        $radio_selection->setSelected($selected);
                    }

                    $entity_manager->persist($radio_selection);
                    $entity_manager->flush();
}

                    $status .= self::indent($indent).'-- option_name: '.$option_name;
                    $status .= self::indent($indent).'-- value: '.$selected;
                    $status .= self::indent($indent).'-- external_id: '.$external_id;
                    $status .= self::indent($indent).'-- create_auth: '.$create_date;
                    $status .= "\n";

                }

//                $status .= '~';
            }
            catch (\Exception $e) {
                $return['error'] = true;
            }
        }
        else if ($element->getElementsByTagName('value')->length > 0) {

            try {
                // Grab the field's value and type from the XML
                $value = $element->getElementsByTagName('value')->item(0)->nodeValue;
                $type = $element->getElementsByTagName('type')->item(0)->nodeValue; // not used at the moment

                $value = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $value);

                // TODO - metadata for field?

                // This section doesn't care whether a datarecord is being created or updated, because the storage entity should ALWAYS exist at this point...
                // ...verifyExistence() will have created the storage entity if the (child)datarecord was created for importing

                // Locate the field in the database
                $classname = "ODR\\AdminBundle\\Entity\\" . $datafield->getFieldType()->getTypeClass();
                $my_obj = $entity_manager->getRepository($classname)->findOneBy( array("dataField" => $datafield->getId(), "dataRecord" => $datarecord->getId()) );

                if ($type == "Boolean" ) {
                    // special consideration for boolean field
                    if ($value === "checked")
                        $my_obj->setValue(1);
                    else
                        $my_obj->setValue(0);
                }
                else if ($type == "DateTime") {
                    // also special consideration for datetime
                    $my_obj->setValue( new \DateTime($value) );
                }
                else {
                    // rest of the fields are straightforward
                    $my_obj->setValue( $value );
                }

                $my_obj->setUpdatedBy($user);

if ($write) {
                $entity_manager->persist($my_obj);
                $entity_manager->flush();
//                $return['objects'][] = $my_obj;   // TODO - should this be deleted on failure?  ...probably not
}

                $status .= $value;

            }
            catch (\Exception $e) {
                $return['error'] = true;
            }
        }

//print $status."\n";
        $return['status'] = $status;
        return $return;
    }


    /**
     * Called by the background process responsible for downloading files/images from some remote server.
     * 
     * @param Request $request
     * 
     * @return TODO
     */
    public function importfileAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['href']) || !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) || !isset($post['user_id']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $href = $post['href'];
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];
            $user_id = $post['user_id'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->get('doctrine')->getManager();
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');
            $repo_file = $em->getRepository('ODRAdminBundle:File');
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            $ret = '';

            // Grab necessary objects
            $user = $repo_user->find($user_id);
            $entity = null;
            $file_path = '';
            $local_filename = '';
            if ($object_type == 'File') {
                $entity = $repo_file->find($object_id);
                $file_path = dirname(__FILE__).'/../../../../web/uploads/files/File_';
                $local_filename = 'uploads/files/File_';
            }
            else if ($object_type == 'Image') {
                $entity = $repo_image->find($object_id);
                $file_path = dirname(__FILE__).'/../../../../web/uploads/images/Image_';
                $local_filename = 'uploads/images/Image_';
            }
            else {
                throw new \Exception('Invalid Form');
            }

            //
            if ($entity == null) {
                throw new \Exception('Invalid file/image object');
            }

            $ret .= 'Attempting to download file for DataRecord '.$entity->getDataRecord()->getId().' DataField '.$entity->getDataField()->getId().'...'."\n";

            // Grab the file from its original location using cURL...
            $ch = curl_init();

            // Set the options for the cURL request
            curl_setopt_array($ch,
                array(
                    CURLOPT_HEADER => 0,
                    CURLOPT_URL => $href,
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_BINARYTRANSFER => 1,
                    CURLOPT_FRESH_CONNECT => 1,
                    CURLOPT_FORBID_REUSE => 1,
                    CURLOPT_TIMEOUT => 120,  // TODO - timeout length?
                )
            );

            // Send the request
            $file_contents = '';
            if( ! $file_contents = curl_exec($ch)) {
                if ( curl_errno($ch) == 6 ) {
                    // Could not resolve host
                    throw new \Exception( 'retry' );
                }
                else {
                    throw new \Exception( curl_error($ch) );
                }
            }

            if ($file_contents !== false) {

                $downloaded_file_checksum = md5($file_contents);
                if ( $entity->getOriginalChecksum() == $downloaded_file_checksum ) {
                    $ret .= 'checksum match, not saving downloaded file'."\n";
                }
                else {
                    $ret .= 'checksum mis-match...';

                    // Determine the file's extension
                    $period = strrpos($href, '.');
                    $ext = substr($href, $period+1);
                    $entity->setExt($ext);

                    $handle = fopen( $file_path.$object_id.'.'.$ext, 'w' );
                    if ($handle === false) {
                        throw new \Exception('Could not write the file at "'.$href.'" to the server');
                    }
                    else {

                        // Upload the file to the server
                        fwrite($handle, $file_contents);
                        fclose($handle);

                        if ($object_type == 'Image') {
                            $sizes = getimagesize( $file_path.$object_id.'.'.$ext );
                            $entity->setImageWidth( $sizes[0] );
                            $entity->setImageHeight( $sizes[1] );
                        }

                        // Update the file object's filename and save
                        $entity->setLocalFileName( $local_filename.$object_id.'.'.$ext );
                        $entity->setOriginalChecksum($downloaded_file_checksum);
                        $em->persist($entity);
                        $em->flush();

                        $ret .= 'wrote downloaded file to "'.$file_path.$object_id.'.'.$ext.'"'."\n";

                        if ($object_type == 'Image') {
                            // Generate other sizes of image
                            parent::resizeImages($entity, $user);
                            $ret .= 'rebuilt thumbnails for downloaded image'."\n";
                        }

                        // (Re)encrypt the object
                        self::encryptObject($object_id, $object_type);
                        $ret .= 'encrypted downloaded file'."\n";
                    }
                }
            }
            else {
                // Could not load the file, notify of failure
                throw new \Exception('Could not load the file "'.$href.'" for import');
            }

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            if ( $e->getMessage() == 'retry' ) {
                // Could not resolve host error
                $return['r'] = 6;
            }
            else {
                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = 'Error 0x66271865: '.$e->getMessage()."\n".$ret;
            }
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Writes the XML version of the DataRecord into the xml export directory.
     * 
     * @param integer $datarecord_id The database id of the DataRecord to export
     * @param Request $request
     * 
     * @return TODO
     */
    public function exportAction($datarecord_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $datarecord = $repo_datarecord->find($datarecord_id);
            $datatype = $datarecord->getDataType();
            $templating = $this->get('templating');

            $xml_export_path = dirname(__FILE__).'/../../../../web/uploads/xml_export/';
            $filename = 'DataRecord_'.$datarecord_id.'.xml';

            $handle = fopen($xml_export_path.$filename, 'w');
            if ($handle !== false) {
                $content = parent::XML_GetDisplayData($request, $datarecord_id);
                fwrite($handle, $content);
                fclose($handle);

                $return['d'] = array(
                    'html' => $templating->render('ODRAdminBundle:XMLExport:xml_download.html.twig', array('datarecord_id' => $datarecord_id))
                );
            }
            else {
                // Shouldn't be an issue?
                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = 'Error 0x848128635 Could not open file at "'.$xml_export_path.$filename.'"';
            }

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
     * Sidesteps symfony to set up an XML file download...TODO
     * 
     * @param integer $datarecord_id The database id of the DataRecord to download...
     * @param Request $request
     * 
     * @return TODO
     */
    public function downloadXMLAction($datarecord_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        $response = new Response();

        try {
            $xml_export_path = dirname(__FILE__).'/../../../../web/uploads/xml_export/';
            $filename = 'DataRecord_'.$datarecord_id.'.xml';

            $handle = fopen($xml_export_path.$filename, 'r');
            if ($handle !== false) {

                // Set up a response to send the file back
                $response->setPrivate();
                $response->headers->set('Content-Type', mime_content_type($xml_export_path.$filename));
                $response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'";');

                $response->sendHeaders();

                $content = file_get_contents($xml_export_path.$filename);   // using file_get_contents() because apparently readfile() tacks on # of bytes read at end of file for firefox
                $response->setContent($content);

                fclose($handle);
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


    /**
     * Renders and returns the XML version of the given DataRecord
     * 
     * @param integer $datarecord_id
     * @param Request $request
     * 
     * @return TODO
     */
    public function getXMLAction($datarecord_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'xml';
        $return['d'] = '';

        try {
            $templating = $this->get('templating');
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            $datarecord = $repo_datarecord->find($datarecord_id);
            $datatype = $datarecord->getDataType();

            // Ensure all Entities exist before rendering the XML
            parent::verifyExistence($datatype, $datarecord);
            $return['d'] = array(
                'xml' => parent::XML_GetDisplayData($request, $datarecord_id)
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x841278653 ' . $e->getMessage();
        }

        // If error encountered, do a json return
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /** 
     * utility function for changing the external id for a lot of datarecords
     */
    public function testAction(Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $em = $this->getDoctrine()->getManager();
/*
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            $datarecords = $repo_datarecord->findBy( array('dataType' => 43) );
            foreach ($datarecords as $datarecord) {
                $datarecord->setExternalId( $datarecord->getNameFieldValue() );
                $em->persist($datarecord);
            }
            $em->flush();
*/

/*
            $query = $em->createQuery(
               'SELECT appraisal.value AS sv_value, uamm.value AS uamm_id
                FROM ODRAdminBundle:ShortVarchar AS appraisal
                JOIN ODRAdminBundle:DataRecord AS dr WITH appraisal.dataRecord = dr
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:ShortVarchar AS uamm WITH uamm.dataRecordFields = drf
                WHERE appraisal.dataField = 150 AND uamm.dataField = 141
                AND appraisal.deletedAt IS NULL AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND uamm.deletedAt IS NULL');
            $results = $query->getArrayResult();

//print_r($results);
//exit();

            $datarecord_ids = array();
            $values = array();
            foreach ($results as $num => $data) {
                $uamm_id = $data['uamm_id'];
                $sv_value = $data['sv_value'];

                $sv_value = str_replace( array('$', ','), '', $sv_value );
                if ( strpos($sv_value, '.') ) {
                    $tmp = explode('.', $sv_value);
                    $sv_value = $tmp[0];
                }
                $sv_value = intval($sv_value);

                if ($sv_value >= 30000) {
                    $values[$uamm_id] = $sv_value;
                }
            }
//            print_r($values);

            foreach ($values as $uamm_id => $value)
                print '"'.$uamm_id.'" || ';
*/
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x841278653 ' . $e->getMessage();
        }

        // If error encountered, do a json return
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }
}
