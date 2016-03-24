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

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;


class XMLController extends ODRCustomController
{

    /**
     * Utility function to print out XML errors during importing or exporting
     * 
     * @param mixed $error
     * 
     * @return string
     */
    public function libxml_display_error($error)
    {
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
    public function libxml_display_errors()
    {
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
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response TODO
     */
    public function importAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $status = '';

        try {
            // Grab required objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            $api_key = $this->container->getParameter('beanstalk_api_key');
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->get('router');

            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

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
            $url .= $router->generate('odr_xml_import_start');

            // Insert the new job into the queue
            $payload = json_encode(
                array(
                    "datatype_id" => $datatype->getId(),
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
     * @return Response TODO
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
            if ( !isset($post['datatype_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid job data');

            // Pull data from the post
            $datatype_id = $post['datatype_id'];
            $user_id = $post['user_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->get('router');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');

            if ($datatype_id == '')
                throw new \Exception('Invalid job data');

            $schema_path = dirname(__FILE__).'/../../../../web/uploads/xsd/';
            $xml_path = dirname(__FILE__).'/../../../../web/uploads/xml/user_'.$user_id.'/';
            $ret = '';


            // ----------------------------------------
            // Determine the schema filename
            $datatype = $repo_datatype->find($datatype_id);
            $schema_filename = $datatype->getXmlShortName().'.xsd';

            // Ensure schema file exists
            if ( !file_exists($schema_path.$schema_filename) )
                throw new \Exception('Unable to load schema file');

            $handle =  fopen($schema_path.$schema_filename, 'r');
            if (!$handle)
                throw new \Exception('Unable to load schema file');
            else
                fclose($handle);

            // Ensure xml directory exists
            if ( !file_exists($xml_path.'unprocessed') )
                mkdir( $xml_path.'unprocessed' );

            // ----------------------------------------
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
                $url .= $router->generate('odr_xml_import_validate');

                $payload = json_encode(
                    array(
                        'xml_filename' => $xml_filename,
                        'api_key' => $beanstalk_api_key,
                        'url' => $url,
                        'memcached_prefix' => $memcached_prefix,    // debug purposes only
                        'datatype_id' => $datatype_id,
                        'user_id' => $user_id
                    )
                );
                $pheanstalk->useTube('validate_import')->put($payload);

                $ret .= 'Scheduled "'.$xml_filename.'" of datatype '.$datatype_id.' for validation'."\n";
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
     * @return Response TODO
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
            if ( !isset($post['xml_filename']) || !isset($post['datatype_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid job data');

            // Pull data from the post
            $xml_filename = $post['xml_filename'];
            $datatype_id = $post['datatype_id'];
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

            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');

            if ($datatype_id == '')
                throw new \Exception('Invalid job data');

            // Enable user error handling
            libxml_use_internal_errors(true);

            $schema_path = dirname(__FILE__).'/../../../../web/uploads/xsd/';
            $xml_path = dirname(__FILE__).'/../../../../web/uploads/xml/user_'.$user_id.'/';
            $ret = '';


            // ----------------------------------------
            // Determine the schema filename
            $datatype = $repo_datatype->find($datatype_id);
            $schema_filename = $datatype->getXmlShortName().'.xsd';

            // Ensure schema file exists
            if ( !file_exists($schema_path.$schema_filename) )
                throw new \Exception('Unable to load schema file');

            $handle =  fopen($schema_path.$schema_filename, 'r');
            if (!$handle)
                throw new \Exception('Unable to load schema file');
            else
                fclose($handle);


            // ----------------------------------------
            // Attempt to validate the given xml file against the correct XSD file
            // Load the file as an XML Document
            $xml_file = new \DOMDocument();
            $ret .= 'Attempting to load "'.$xml_path.'unprocessed/'.$xml_filename.'"'."\n";
            if ($xml_file->load($xml_path.'unprocessed/'.$xml_filename, LIBXML_NOBLANKS) !== false) {
                $ret .= 'Loaded "'.$xml_filename.'"'."\n";

                // Attempt to validate the XML file...
                if (!$xml_file->schemaValidate($schema_path.$schema_filename)) {
                    // If validation failed, display errors
                    $ret .= 'Schema errors in "'.$xml_filename.'" >> '.self::libxml_display_errors()."\n";
                    $logger->err('WorkerController:importvalidateAction()  Schema errors in "'.$xml_filename.'" >> '.self::libxml_display_errors());

                    // Ensure failed directory exists
                    if ( !file_exists($xml_path.'failed') )
                        mkdir( $xml_path.'failed' );

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
                    // TODO - validate existence of files
                    // TODO - validate uniqueness
                    // TODO - tracked job/errors

                    $ret .= 'Validated "'.$xml_filename.'"'."\n";

                    // Queue the file for a full import...
                    $url = $this->container->getParameter('site_baseurl');
                    $url .= $router->generate('odr_xml_import_worker');

                    $payload = json_encode(
                        array(
                            'xml_filename' => $xml_filename,
                            'api_key' => $beanstalk_api_key,
                            'url' => $url,
                            'memcached_prefix' => $memcached_prefix,    // debug purposes only
                            'datatype_id' => $datatype_id,
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
     * @return Response TODO
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
            if ( !isset($post['xml_filename']) || !isset($post['datatype_id']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid job data');

            // Pull data from the post
            $xml_filename = $post['xml_filename'];
            $datatype_id = $post['datatype_id'];
            $user_id = $post['user_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
            $user = $repo_user->find($user_id);

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid job data');

            if ($datatype_id == '')
                throw new \Exception('Invalid job data');


            // Enable user error handling
            libxml_use_internal_errors(true);
            $xml_path = dirname(__FILE__).'/../../../../web/uploads/xml/user_'.$user_id.'/';
            $ret = "\n----------\n";

            // Load the file as an XML Document
            $xml_file = new \DOMDocument();
            $ret .= 'Attempting to load '.$xml_path.'unprocessed/'.$xml_filename."\n";
            if ($xml_file->load($xml_path.'unprocessed/'.$xml_filename, LIBXML_NOBLANKS) === false)
                throw new \Exception('Could not load "'.$xml_filename.'" for import >> '.self::libxml_display_errors());

            $parent_datatype = $repo_datatype->find($datatype_id);

            // $xml_file is currently document root
            $xml_datarecords = $xml_file->childNodes->item(0);

$write = true;
$write = false;

            foreach ($xml_datarecords->childNodes as $xml_datarecord) {
                // Need to keep track of the entities created for this import
                $created_objects = array();
                $import_ret = null;

                $indent = 0;

                // ----------------------------------------
                // Attempt to locate a pre-existing datarecord to import into
                $grandparent = null;
                $update_datarecord = false;
                // Attempt to locate an external id for the datarecord
                $metadata = self::getODRMetadata($xml_datarecord, 'datarecord');
                if ( isset($metadata['external_id']) && $parent_datatype->getExternalIdField() !== null ) {
                    $external_id_value = $metadata['external_id'];
                    $grandparent = parent::getDatarecordByExternalId($em, $parent_datatype->getExternalIdField()->getId(), $external_id_value);

                    $ret .= 'Attempting to find datarecord identified by external_id: "'.$external_id_value."\"...\n";
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
                $import_ret = self::importDatarecord($em, $user, $xml_datarecord, $grandparent, $grandparent, $update_datarecord, $write, $indent);


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

                    // Ensure failed directory exists
                    if ( !file_exists($xml_path.'failed') )
                        mkdir( $xml_path.'failed' );

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

                    // Ensure succeeded directory exists
                    if ( !file_exists($xml_path.'succeeded') )
                        mkdir( $xml_path.'succeeded' );

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
     * @param integer $level
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
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user
     * @param \DOMNode $xml_datarecord           The XML structure describing the data that is being imported into this datarecord
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
        $metadata = self::getODRMetadata($xml_datarecord, 'datarecord');

        $external_id = null;
        if ( isset($metadata['external_id']) ) {
            $external_id = $metadata['external_id'];
//            $datarecord->setExternalId($external_id);
        }

        $create_date = null;
        if ( isset($metadata['create_date']) ) {
            $create_date = $metadata['create_date'];
            $datarecord->setCreated( new \DateTime($create_date) );
        }

        $create_auth = null;
        if ( isset($metadata['create_auth']) ) {
            $create_auth = $metadata['create_auth'];
//            $datarecord->setCreatedBy($create_auth);
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
        foreach ($xml_datarecord->childNodes as $child_node) {
            // Don't want metadata, child datarecords, or linked datarecords
            if ($child_node->nodeName !== 'datafields')
                continue;

            // $child_node now contains the 'datafields' element
            foreach ($child_node->childNodes as $xml_datafield) {
                // TEMP
                $datafield = null;
                foreach ($datatype->getDataFields() as $df) {
                    if ($df->getXmlFieldName() == $xml_datafield->nodeName) {
                        $datafield = $df;
                        break;
                    }
                }

                // Locate the XML element that holds the data for this field...because xml document is valid, this will always return something
                $ret .= self::indent($indent + 1).'>> '.$xml_datafield->nodeName;

                // Import the data from the XML element
                $import_ret = self::importData($em, $user, $xml_datafield, $datarecord, $datafield, $update_datarecord, $write, $indent + 2);

                $ret .= ' => '.$import_ret['status'];

                if ($import_ret['error'] == true) {
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

        }


        // ----------------------------------------
        // Need to deal with any occurence of child/linked datatypes now...
        $parent_external_id = $datarecord->getExternalId();  // TODO - possible problem due to datafield getting changed earlier?

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
        // Search for any child datarecords that need to be created
        if (!$error_during_import) {

            foreach ($xml_datarecord->childNodes as $child_node) {
                // Don't want metadata, datafields, or linked datarecords
                if ($child_node->nodeName !== 'child_datarecords')
                    continue;

                // $child_node now contains the 'child_datarecords' element

                // TEMP
                foreach ($child_datatypes as $num => $child_datatype) {
                    foreach ($child_node->childNodes as $child_datatype_element) {

                        if ($child_datatype_element->nodeName == $child_datatype->getXmlShortName()) {

                            $ret .= "\n";
                            $ret .= self::indent($indent).'---------------';
                            $ret .= self::indent($indent).'-- child datarecords';
                            $ret .= self::indent($indent+1).'>> '.$child_datatype_element->nodeName;

                            $child_xml_datarecords = $child_datatype_element->childNodes->item(0);

                            foreach ($child_xml_datarecords->childNodes as $child_xml_datarecord) {

                                $ret .= self::indent($indent+1).'---------------';

                                // ----------------------------------------
                                // Grab child datarecord metadata
                                $metadata = self::getODRMetadata($child_xml_datarecord, 'datarecord');

                                // Attempt to locate the child datarecord
                                $child_datarecord = null;
                                if ( isset($metadata['external_id']) && $child_datatype->getExternalIdField() !== null ) {
                                    $child_external_id = $metadata['external_id'];
                                    $child_datarecord = parent::getChildDatarecordByExternalId($em, $child_datatype->getExternalIdField()->getId(), $child_external_id, $datatype->getExternalIdField()->getId(), $parent_external_id);
                                }

                                // ------------------------------
                                // Ensure a child datarecord entity exists to import into
                                $update_child_datarecord = false;
                                if ($child_datarecord == null) {
                                    $ret .= self::indent($indent+1).' ...not found, creating new child_datarecord';

if ($write) {
                                    $child_datarecord = parent::ODR_addDataRecord($em, $user, $child_datatype);
                                    $child_datarecord->setParent($datarecord);
                                    $child_datarecord->setGrandparent($grandparent_datarecord);

                                    $em->persist($child_datarecord);
                                    $em->flush();
                                    $em->refresh($child_datarecord);

                                    // Save the new child datarecord incase it needs to get deleted on an error
                                    $created_objects = array_merge($created_objects, array($child_datarecord));
}
else {
    $child_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy( array("dataType" => $child_datatype->getId()) );   // DEBUGGING
}
                                }
                                else {
                                    $update_child_datarecord = true;
                                    $ret .= self::indent($indent+1).' ...Using existing child_datarecord...';
                                }

                                // ----------------------------------------
                                // Import into the child datarecord
                                $import_ret = self::importDatarecord($em, $user, $child_xml_datarecord, $child_datarecord, $grandparent_datarecord, $update_child_datarecord, $write, $indent+3);

                                $error_during_import = $import_ret['error'];
                                $ret .= $import_ret['status'];
                                $created_objects = array_merge($created_objects, $import_ret['objects']);

                                if ($error_during_import)
                                    break;
                            }

                            if ($error_during_import)
                                break;
                        }

                        if ($error_during_import)
                            break;
                    }

                    if ($error_during_import)
                        break;
                }

                if ($error_during_import)
                    break;
            }
        }


        // ----------------------------------------
        // Search for any linked datarecords that need to be created
        if (!$error_during_import) {

            foreach ($xml_datarecord->childNodes as $child_node) {
                // Don't want metadata, datafields, or child datarecords
                if ($child_node->nodeName !== 'linked_datarecords')
                    continue;

                // $child_node now contains the 'linked_datarecords' element

                // TEMP
                foreach ($linked_datatypes as $num => $linked_datatype) {
                    foreach ($child_node->childNodes as $linked_datatype_element) {

                        if ($linked_datatype_element->nodeName == $linked_datatype->getXmlShortName()) {

                            $ret .= "\n";
                            $ret .= self::indent($indent).'---------------';
                            $ret .= self::indent($indent).'-- linked datarecords';
                            $ret .= self::indent($indent + 1).'>> '.$linked_datatype_element->nodeName;

                            $linked_xml_datarecords = $linked_datatype_element->childNodes->item(0);

                            foreach ($linked_xml_datarecords->childNodes as $linked_xml_datarecord) {

                                $ret .= self::indent($indent+1).'---------------';

                                // Grab external ID of remote datarecord
                                $remote_external_id = $linked_xml_datarecord->getElementsByTagname('_external_id')->item(0)->nodeValue;
                                $ret .= self::indent($indent+1).$remote_external_id;

                                // Attempt to locate the remote datarecord
                                $remote_datarecord = null;
                                if ( $linked_datatype->getExternalIdField() !== null )
                                    $remote_datarecord = parent::getDatarecordByExternalId($em, $linked_datatype->getExternalIdField()->getId(), $remote_external_id);

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

                            }
                        }
                    }
                }
            }
        }

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
     * @param \DOMNode $xml_entity
     * @param string $metadata_type 'datarecord'|'file'|'image'
     *
     * @return array
     */
    private function getODRMetadata($xml_entity, $metadata_type)
    {
        $odr_metadata = array();
        $metadata_type = '_'.$metadata_type.'_metadata';

        // Look for the metadata element
        foreach ($xml_entity->childNodes as $childNode) {
            if ($childNode->nodeName == $metadata_type) {
                // Turn each property listed in the metadata element into an array element
                foreach ($childNode->childNodes as $metadata_node) {
                    $name = substr($metadata_node->nodeName, 1);
                    $value = $metadata_node->nodeValue;

                    // Skip over create_auth for now...
                    if ($name == 'create_auth')     // TODO - how to translate names from create_auth into useful info
                        continue;

                    $odr_metadata[$name] = $value;
                }
            }
        }

        return $odr_metadata;
    }


    /**
     * Creates a new Entity to hold the XML data from $element.
     * 
     * @param \Doctrine\ORM\EntityManager $entity_manager
     * @param ODRUser $user
     * @param \DOMNode $xml_element          The xml describing this element
     * @param DataRecord $datarecord     The datarecord getting this piece of data
     * @param DataFields $datafield      The datafield this data is being stored in
     * @param boolean $update_datarecord Whether to attempt to update the existing data in the datarecord, or just create new storage entities for everything
     * 
     * @param boolean $write  Whether to persist/flush all changes
     * @param integer $indent Debugging purposes...
     * 
     * @return array
     */
    private function importData($entity_manager, $user, $xml_element, $datarecord, $datafield, $update_datarecord, $write, $indent)
    {
        $status = '';

        $return = array();
        $return['status'] = '';
        $return['error'] = false;
        $return['objects'] = array();

        // Shouldn't happen, obviously
        if ($xml_element == null) {
            $return['status'] = 'ERROR: element equal to null';
            return $return;
        }

        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
        $api_key = $this->container->getParameter('beanstalk_api_key');
        $router = $this->container->get('router');
        $pheanstalk = $this->get('pheanstalk');
        $url = $this->container->getParameter('site_baseurl');
        $url .= $router->generate('odr_xml_import_file_download');


        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ($typeclass == 'File') {

            try {
                // ----------------------------------------
                // Going to need these
                $repo_datarecordfields = $entity_manager->getRepository('ODRAdminBundle:DataRecordFields');
                $repo_file = $entity_manager->getRepository('ODRAdminBundle:File');
                $drf = $repo_datarecordfields->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()) );

                // Store the files listed in the xml file
                $changelist = array();
                $need_flush = false;


                // ----------------------------------------
                // Iterate through all the <file> tags for this xml element
                foreach ($xml_element->childNodes as $file_element) {
                    $original_name = $file_element->getElementsByTagName('original_name')->item(0)->nodeValue;
//                    $checksum = $file_element->getElementsByTagName('checksum')->item(0)->nodeValue;

                    $href = '';
                    if ($file_element->getElementsByTagName('href')->length > 0)
                        $href = $file_element->getElementsByTagName('href')->item(0)->nodeValue;

                    // No point if there's no href/filename...
                    if ($original_name == '' && $href == '') {
                        $status .= self::indent($indent).'No source specified for file "'.$original_name.'", skipping...';
                        continue;

                        // TODO - throw exception instead?
                    }

                    // TODO - need to decode all of these file properties?

                    // Store that this filename was listed in the xml file
                    $changelist[] = $original_name;

                    // ----------------------------------------
                    // Check for metadata
                    $create_date = null;
                    $create_auth = null;
                    $public_date = '2200-01-01 00:00:00';   // default to not public
                    $external_id = null;

                    $metadata = self::getODRMetadata($file_element, 'file');
                    if ( isset($metadata['create_date']) )
                        $create_date = $metadata['create_date'];
                    if ( isset($metadata['create_auth']) )
                        $create_auth = $metadata['create_auth'];
                    if ( isset($metadata['public_date']) )
                        $public_date = $metadata['public_date'];
                    else
                        $metadata['public_date'] = $public_date;
                    if ( isset($metadata['external_id']) )
                        $external_id = $metadata['external_id'];

                    // TODO - need to decode all of these metadata properties?

                    // ----------------------------------------
                    // Import the file
                    $status .= self::indent($indent).' >> scheduled file for download...';

                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "object_type" => 'File',
                            "drf_id" => $drf->getId(),
                            "user_id" => $user->getId(),
                            "href" => $href,
                            "original_name" => $original_name,
                            "metadata" => $metadata,

                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
if ($write)
                    $pheanstalk->useTube('import_file')->put($payload, $priority, $delay);


                    // ----------------------------------------
                    $status .= self::indent($indent).'-- original_name: '.$original_name;
                    $status .= self::indent($indent).'-- href: '.$href;
//                    $status .= self::indent($indent).'-- checksum: '.$checksum;
                    $status .= self::indent($indent).'-- create_auth: '.$create_date;
                    $status .= self::indent($indent).'-- create_date: '.$create_auth;
                    $status .= self::indent($indent).'-- public_date: '.$public_date;
                    $status .= self::indent($indent).'-- external_id: '.$external_id;
                    $status .= "\n";
                }

                // ----------------------------------------
                // Determine whether to delete all files not listed in the xml file
                if ($xml_element->hasAttributes() && $xml_element->attributes->item(0)->nodeName == '_delete_unlisted') {   // attribute is always false if it exists
                    $status .= self::indent($indent).'>> preserving unlisted files';
                }
                else {
                    $status .= self::indent($indent).'>> deleting all unlisted files...';

                    // Grab all uploaded files in this datafield
                    $files = $repo_file->findBy( array("dataRecordFields" => $drf->getId()) );
                    foreach ($files as $file) {
                        // If the file wasn't listed in the xml file...
                        $filename = $file->getOriginalFileName();
                        if ( !in_array($filename, $changelist) ) {
                            // ...delete it
if ($write) {
                            $entity_manager->remove($file);
                            $need_flush = true;
}
                            $status .= self::indent($indent+1).'-- "'.$filename.'"';
                        }
                    }

                    $status .= self::indent($indent).'>> ...done';
                }

if ($write) {
                // ----------------------------------------
                // Commit changes if necessary
                if ($need_flush)
                    $entity_manager->flush();
}
            }
            catch (\Exception $e) {
                $return['error'] = true;
            }
        }
        else if ($typeclass == 'Image') {

            try {
                // ----------------------------------------
                // Going to need these
                $repo_datarecordfields = $entity_manager->getRepository('ODRAdminBundle:DataRecordFields');
                $repo_image = $entity_manager->getRepository('ODRAdminBundle:Image');
                $drf = $repo_datarecordfields->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()) );

                // Store the images listed in the xml file
                $changelist = array();
                $need_flush = false;


                // ----------------------------------------
                // Iterate through all the <image> tags for this xml element
                foreach ($xml_element->childNodes as $image_element) {
                    $original_name = $image_element->getElementsByTagName('original_name')->item(0)->nodeValue;
//                    $checksum = $image_element->getElementsByTagName('checksum')->item(0)->nodeValue;
                    $caption = $image_element->getElementsByTagName('caption')->item(0)->nodeValue;

                    $href = '';
                    if ($image_element->getElementsByTagName('href')->length > 0)
                        $href = $image_element->getElementsByTagName('href')->item(0)->nodeValue;

                    // No point if there's no href/filename...
                    if ($original_name == '' && $href == '') {
                        $status .= self::indent($indent).'No source specified for file "'.$original_name.'", skipping...';
                        continue;

                        // TODO - throw exception instead?
                    }

                    // TODO - need to decode all of these image properties?

                    // ----------------------------------------
                    // Check for metadata
                    $create_date = null;
                    $create_auth = null;
                    $public_date = '2200-01-01 00:00:00';   // default to not public
                    $external_id = null;
                    $display_order = null;

                    $metadata = self::getODRMetadata($image_element, 'image');
                    if ( isset($metadata['create_date']) )
                        $create_date = $metadata['create_date'];
                    if ( isset($metadata['create_auth']) )
                        $create_auth = $metadata['create_auth'];
                    if ( isset($metadata['public_date']) )
                        $public_date = $metadata['public_date'];
                    else
                        $metadata['public_date'] = $public_date;
                    if ( isset($metadata['external_id']) )
                        $external_id = $metadata['external_id'];
                    if ( isset($metadata['display_order']) )
                        $display_order = $metadata['display_order'];

                    // TODO - need to decode all of these metadata properties?

                    // ----------------------------------------
                    // Import the image
                    $status .= self::indent($indent).' >> scheduled file for download';

                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "object_type" => 'Image',
                            "drf_id" => $drf->getId(),
                            "user_id" => $user->getId(),
                            "href" => $href,
                            "original_name" => $original_name,
                            "metadata" => $metadata,

                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
if ($write)
                    $pheanstalk->useTube('import_file')->put($payload, $priority, $delay);


                    // ----------------------------------------
                    $status .= self::indent($indent).'-- original_name: '.$original_name;
                    $status .= self::indent($indent).'-- caption: '.$caption;
                    $status .= self::indent($indent).'-- href: '.$href;
//                    $status .= self::indent($indent).'-- checksum: '.$checksum;
                    $status .= self::indent($indent).'-- create_auth: '.$create_date;
                    $status .= self::indent($indent).'-- create_date: '.$create_auth;
                    $status .= self::indent($indent).'-- public_date: '.$public_date;
                    $status .= self::indent($indent).'-- external_id: '.$external_id;
                    $status .= self::indent($indent).'-- display_order: '.$display_order;
                    $status .= "\n";
                }

                // ----------------------------------------
                // Determine whether to delete all images not listed in the xml file
                if ($xml_element->hasAttributes() && $xml_element->attributes->item(0)->nodeName == '_delete_unlisted') {   // attribute is always false if it exists
                    $status .= self::indent($indent).'>> preserving unlisted images';
                }
                else {
                    $status .= self::indent($indent).'>> deleting all unlisted images...';

                    // Grab all uploaded files in this datafield
                    $images = $repo_image->findBy( array("dataRecordFields" => $drf->getId()) );
                    foreach ($images as $image) {
                        // If the file wasn't listed in the xml file...
                        $filename = $image->getOriginalFileName();
                        if ( !in_array($filename, $changelist) ) {
                            // ...delete it
if ($write) {
                            $entity_manager->remove($image);
                            $need_flush = true;
}

                            $status .= self::indent($indent+1).'-- "'.$filename.'"';
                            if ($image->getOriginal() == 1)
                                $status .= ' (original)';
                            else
                                $status .= ' (thumbnail)';
                        }
                    }

                    $status .= self::indent($indent).'>> ...done';
                }

if ($write) {
                // ----------------------------------------
                // Commit changes if necessary
                if ($need_flush)
                    $entity_manager->flush();
}
            }
            catch (\Exception $e) {
                $return['error'] = true;
            }
        }
        else if ($typeclass == 'Radio') {
            try {
                // ----------------------------------------
                // Going to need these
                $repo_datarecordfields = $entity_manager->getRepository('ODRAdminBundle:DataRecordFields');
                $repo_radio_selection = $entity_manager->getRepository('ODRAdminBundle:RadioSelection');
                $repo_radio_option = $entity_manager->getRepository('ODRAdminBundle:RadioOptions');

                $drf = $repo_datarecordfields->findOneBy( array("dataField" => $datafield->getId(), "dataRecord" => $datarecord->getId()) );
                $radio_options = $repo_radio_option->findBy( array("dataFields" => $datafield->getId()) );  // TEMP

                // Keep track of which radio options were changed
                $changelist = array();
                $need_flush = false;


                // ----------------------------------------
                // Grab all the options stored in this xml entity
                foreach ($xml_element->childNodes as $xml_radio_option) {
                    $option_name = $xml_radio_option->nodeName;
                    $option_value = intval($xml_radio_option->nodeValue);

                    //$option_name = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $option_name);    // TODO - still needed?
                    //$option_value = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $option_value);     // TODO - still needed?

                    // Store that this radio option was listed in the xml file
                    $changelist[] = $option_name;

                    // TEMP
                    $radio_option = null;
                    foreach ($radio_options as $ro) {
                        if ($ro->getXmlOptionName() == $option_name) {
                            $radio_option = $ro;
                            break;
                        }
                    }

                    if ($radio_option == null) {
                        // This shouldn't happen because the document is valid
                        throw new \Exception('Could not find option_name: "'.$option_name.'" in the database');
                    }

                    // Attempt to locate a radio_selction object for this
                    $radio_selection = $repo_radio_selection->findOneBy( array("radioOption" => $radio_option->getId(), "dataRecordFields" => $drf->getId()) );

if ($write) {
                    if ($radio_selection == null) {
                        // Create a new RadioSelection object
                        $radio_selection = parent::ODR_addRadioSelection($entity_manager, $user, $radio_option, $drf, $option_value);
                        $need_flush = true;

                        // Store the radio option so they can all be deleted if an error occurs
                        $return['objects'][] = $radio_selection;
                    }
                    else {
                        // Don't update the radio selection if the value didn't change
                        if ($radio_selection->getSelected() != $option_value) {
                            $radio_selection->setSelected($option_value);
                            $entity_manager->persist($radio_selection);
                            $need_flush = true;
                        }
                    }
}

                    $status .= self::indent($indent).'-- "'.$option_name.'": '.$option_value;
                }

                // ----------------------------------------
                // Determine whether to deselect all radio options not listed in the xml file
                if ($xml_element->hasAttributes() && $xml_element->attributes->item(0)->nodeName == '_deselect_unlisted') {     // attribute is always false if it exists
                    $status .= self::indent($indent).'>> preserve selected status of unlisted radio options';
                }
                else {
                    $status .= self::indent($indent).'>> deselecting all unlisted radio options...';

                    // Grab all radio selection objects for this datafield
                    $radio_selections = $repo_radio_selection->findBy( array("dataRecordFields" => $drf->getId()) );
                    foreach ($radio_selections as $rs) {
                        // If the radio option wasn't listed in the xml file...
                        $option_name = $rs->getRadioOption()->getXmlOptionName();
                        if ( !in_array($option_name, $changelist) && $rs->getSelected() == 1 ) {
                            // ...deselect it
if ($write) {
                            $rs->setSelected(0);
                            $entity_manager->persist($rs);
                            $need_flush = true;
}
                            $status .= self::indent($indent+1).'-- "'.$option_name.'"';
                        }
                    }

                    $status .= self::indent($indent).'>> ...done';
                }

if ($write) {
                // ----------------------------------------
                // Commit changes if necessary
                if ($need_flush)
                    $entity_manager->flush();
}

            }
            catch (\Exception $e) {
                $return['error'] = true;
            }
        }
        else {
            // All other typeclasses

            try {
                // ----------------------------------------
                // Grab the field's value from the XML
                $value = $xml_element->nodeValue;

                //$value = str_replace(array('&gt;', '&lt;', '&amp;', '&quot;'), array('>', '<', '&', '"'), $value);    // TODO - needed still?

                // This section doesn't care whether a datarecord is being created or updated, because the storage entity should ALWAYS exist at this point...
                // ...verifyExistence() will have created the storage entity if the (child)datarecord was created for importing

                // Locate the field in the database
                $classname = "ODR\\AdminBundle\\Entity\\".$typeclass;
                $my_obj = $entity_manager->getRepository($classname)->findOneBy( array("dataField" => $datafield->getId(), "dataRecord" => $datarecord->getId()) );

                $update_storage_entity = true;
                if ($typeclass == "Boolean" ) {
                    // special consideration for boolean field
                    $value = intval($value);

                    if ($my_obj->getValue() == $value)
                        $update_storage_entity = false;
                    else
                        $my_obj->setValue($value);
                }
                else if ($typeclass == "DateTime") {
                    // also special consideration for datetime
                    if ($my_obj->getValue()->format('Y-m-d') == $value)
                        $update_storage_entity = false;
                    else
                        $my_obj->setValue( new \DateTime($value) );
                }
                else {
                    // rest of the fields are straightforward
                    if ($my_obj->getValue() == $value)
                        $update_storage_entity = false;
                    else
                        $my_obj->setValue($value);
                }

if ($write) {
                // ----------------------------------------
                // Commit changes to the database if necessary
                if ($update_storage_entity) {
                    $my_obj->setUpdatedBy($user);
                    $entity_manager->persist($my_obj);
                    $entity_manager->flush();
                }
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
     * @return Response TODO
     */
    public function importfileAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $ret = '';

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['object_type']) || !isset($post['drf_id']) || !isset($post['user_id']) || !isset($post['original_name']) || !isset($post['href']) || !isset($post['metadata']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $drf_id = $post['drf_id'];
            $user_id = $post['user_id'];
            $original_name = $post['original_name'];
            $href = $post['href'];
            $metadata = $post['metadata'];
            $api_key = $post['api_key'];


            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
            $repo_datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_file = $em->getRepository('ODRAdminBundle:File');
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

$write = true;
$write = false;

            // ----------------------------------------
            // Grab necessary objects
            $user = $repo_user->find($user_id);
            $drf = $repo_datarecordfield->find($drf_id);
            $path_prefix = dirname(__FILE__).'/../../../../web/';
            $storage_path = 'uploads/xml/user_'.$user_id.'/storage/';

            // Ensure storage directory exists
            if ( !file_exists($path_prefix.$storage_path) )
                mkdir( $path_prefix.$storage_path );

            $my_obj = null;
            if ($object_type == 'File')
                $my_obj = $repo_file->findOneBy( array('originalFileName' => $original_name, 'dataRecordFields' => $drf_id) );
            else if ($object_type == 'Image')
                $my_obj = $repo_image->findOneBy( array('originalFileName' => $original_name, 'dataRecordFields' => $drf_id, 'original' => true) );
            else
                throw new \Exception('Invalid Form');


            // ----------------------------------------
            // Attempt to grab the file/image
            $file_contents = null;
            if ($href == '') {
                // File/Image already exists on server
                $file_contents = file_get_contents($path_prefix.$storage_path.$original_name);
            }
            else {
                // Grab the file from a remote server using cURL...
                $ret .= 'Attempting to download file from "'.$href.'" in DataRecord '.$drf->getDataRecord()->getId().' DataField '.$drf->getDataField()->getId().'...';
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
                if ( !$file_contents = curl_exec($ch) ) {
                    if (curl_errno($ch) == 6) {
                        // Could not resolve host
                        throw new \Exception('retry');
                    }
                    else {
                        throw new \Exception( curl_error($ch) );
                    }
                }

                // Ensure the remote server didn't return something weird...
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code >= 400) {
                    throw new \Exception('HTTP Request returned status code '.$code.'...aborting file download attempt');
                }
                else {
                    // For convenience, temporarily save the file in the xml storage directory
                    $handle = fopen($path_prefix.$storage_path.$original_name, 'w');
                    if ($handle == false)
                        throw new \Exception('Could not save downloaded file to storage directory');

                    fwrite($handle, $file_contents);
                    fclose($handle);

                    $ret .= 'success'."\n";
                }
            }


            // ----------------------------------------
            // Three possibilities...
            $ret .= 'Attempting to save '.$object_type.' "'.$original_name.'" in DataRecord '.$drf->getDataRecord()->getId().' DataField '.$drf->getDataField()->getId().'...'."\n";
            if ($my_obj == null) {
                // ...need to add a new file/image
                $ret .= '>> '.$object_type.' does not exist, uploading...';

if ($write) {
                $my_obj = parent::finishUpload($em, $storage_path, $original_name, $user_id, $drf_id);
}
            }
            else if ($my_obj->getOriginalChecksum() == md5($file_contents)) {
                // ...the specified file/image is already in datafield
                $ret .= '>> '.$object_type.' is an exact copy of existing version, skipping...'."\n";

                // Delete the file/image from the server since it already officially exists
                unlink($path_prefix.$storage_path.$original_name);
            }
            else {
                // ...need to "update" the existing file/image
                $ret .= '>> '.$object_type.' is different than uploaded version...';

                // Determine the path to the current file/image
                $local_filepath = $path_prefix.'uploads/files/File_';
                if ($object_type == 'Image')
                    $local_filepath = $path_prefix.'uploads/images/Image_';
                $local_filepath .= $my_obj->getId().'.'.$my_obj->getExt();

if ($write) {
                $handle = fopen($local_filepath, 'w');
                if ($handle == false)
                    throw new \Exception('Could not write to '.$local_filepath);

                // Update the current file/image with the new contents
                fwrite($handle, $file_contents);
                fclose($handle);

                // Delete the uploaded file/image from the storage directory
                unlink($path_prefix.$storage_path.$original_name);
                $ret .= 'overwritten...';

                // Update other properties of the file/image that got changed
                $my_obj->setOriginalChecksum( md5($file_contents) );

                if ($object_type == 'Image') {
                    $sizes = getimagesize($local_filepath);
                    $my_obj->setImageWidth($sizes[0]);
                    $my_obj->setImageHeight($sizes[1]);
                }

                $em->persist($my_obj);
                $em->flush();

                if ($object_type == 'Image') {
                    // Generate other sizes of image
                    parent::resizeImages($my_obj, $user);
                    $ret .= 'rebuilt thumbnails...';
                }

                // Re-encrypt the uploaded file/image
                parent::encryptObject($my_obj->getId(), $object_type);
                $ret .= 'encrypted...';
}
                // A decrypted version of the File/Image might still exist on the server...delete it here since all its properties have been saved
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);
            }


if ($write) {
            // ----------------------------------------
            // Update metadata for the file/image
            if ( isset($metadata['create_date']) && $metadata['create_date'] !== '' )
                $my_obj->setCreated( new \DateTime($metadata['create_date']) );
//            if ( isset($metadata['create_auth']) && $metadata['create_auth'] !== '' )
//                $my_obj->setCreatedBy( $user );
            if ( isset($metadata['public_date']) && $metadata['public_date'] !== '' )
                $my_obj->setPublicDate( new \DateTime($metadata['public_date']) );
            if ( isset($metadata['external_id']) && $metadata['external_id'] !== '' )
                $my_obj->setExternalId( $metadata['external_id'] );
            if ( isset($metadata['display_order']) && $metadata['display_order'] !== '' )
                $my_obj->setDisplayorder( $metadata['display_order'] );

            $em->persist($my_obj);
            $em->flush();

            $ret .= 'done'."\n";
}

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            if ( $e->getMessage() == 'retry' ) {
                // Could not resolve host error...apparently using '6' because it matches cURL's error code
                $return['r'] = 6;
            }
            else {
                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = $ret."\n".'Error 0x66271865: '.$e->getMessage();
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
     * @return Response TODO
     */
    public function exportAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $datarecord = $repo_datarecord->find($datarecord_id);
            $templating = $this->get('templating');

            $xml_export_path = dirname(__FILE__).'/../../../../web/uploads/xml_export/';

            // Ensure directory exists
            if ( !file_exists($xml_export_path) )
                mkdir( $xml_export_path );

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
     * @return Response TODO
     */
    public function downloadXMLAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        $response = new StreamedResponse();

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

//                $response->sendHeaders();

                // Use symfony's StreamedResponse to send the decrypted file back in chunks to the user
                $response->setCallback(function() use ($handle) {
                    while ( !feof($handle) ) {
                        $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                        echo $buffer;
                        flush();
                    }
                    fclose($handle);
                });
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
     * @return Response TODO
     */
    public function getXMLAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'xml';
        $return['d'] = '';

        try {
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            $datarecord = $repo_datarecord->find($datarecord_id);

            // Ensure all Entities exist before rendering the XML
            parent::verifyExistence($datarecord);
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


    public function testAction($str, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';

        $result = self::isValidXMLName($str);

        $return['d'] = array('result' => $result);

        // If error encountered, do a json return
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns whether the provided string is valid for use as an XML "name"
     * @see http://www.w3.org/TR/xml/#sec-common-syn
     *
     * @param $str
     *
     * @return boolean
     */
    public function isValidXMLName($str)
    {
        // Ensure str doesn't start with 'xml'
        if ( strpos( strtolower($str) , 'xml') !== false ) {
            print 'a';
            return false;
        }

        // Ensure str doesn't start with an invalid character
        $pattern = self::xml_invalidnamestartchar();
        print $pattern."\n";
        if ( preg_match($pattern, substr($str, 0, 1)) == 1 ) {
            print 'b';
            return false;
        }

        // Ensure rest of str only has legal characters
        $pattern = self::xml_invalidnamechar();
        print $pattern."\n";
        if ( preg_match($pattern, substr($str, 1)) == 1 ) {
            print 'c';
            return false;
        }

        // No error in name
        print 'd';
        return true;
    }


    /**
     * Utility function to return a regexp pattern for finding illegal "NameStartCharacters" for XML strings
     * @see http://www.w3.org/TR/xml/#sec-common-syn
     *
     * @return string
     */
    private function xml_invalidnamestartchar()
    {
        $tmp = array(
            "[\\x0-\\x39]",
            //"[\\x3A]",            // colon
            "[\\x3B-\\x40]",
            //"[\\x41-\\x5A]",      // A-Z
            "[\\x5B-\\x5E]",
            //"[\\x5F]",            // underscore
            "[\\x60]",
            //"[\\x61-\\x7A]",      // a-z
            "[\\x7B-\\xBF]",
            //"[\\xC0-\\xD6]",
            "[\\xD7]",              // multiplication sign
            //"[\\xD8-\\xF6]",
            "[\\xF7]",              // division sign
            //"[\\xF8-\\x2FF]",
            "[\\x300-\\x36F]",
            //"[\\x370-\\x37D]",
            "[\\x37E]",             // greek semicolon
            //"[\\x37F-\\x1FFF]",
            "[\\x{2000}-\\x{200B}]",
            //"[\\x200C-\\x200D]",
            "[\\x{200E}-\\x{206F}]",
            //"[\\x2070-\\x218F]",
            "[\\x{2190}-\\x{2BFF}]",
            //"[\\x2C00-\\x2FEF]",
            "[\\x{2FF0}-\\x{3000}]",
            //"[\\x3001-\\xD7FF]",
            "[\\x{D800}-\\x{F8FF}]",    // private use area
            //"[\\xF900-\\xFDCF]",
            "[\\x{FDD0}-\\x{FDEF}]",    // not characters
            //"[\\xFDF0-\\xFFFD]",
            "[\\x{FFFE}-\\x{FFFF}]",    // not characters
            //"[\\x10000-\\xEFFFF]"
        );

        return '/'.implode("|", $tmp).'/u';
    }


    /**
     * Utility function to return a regexp pattern for finding illegal "NameCharacters" for XML strings
     * @see http://www.w3.org/TR/xml/#sec-common-syn
     *
     * @return string
     */
    private function xml_invalidnamechar()
    {
        $tmp = array(
            "[\\x0-\\x2C]",
            //"[\\x2D-\\x2E]",      // hyphen, period
            "[\\x2F]",
            //"[\\x30-\\x39]",      // 0-9
            //"[\\x3A]",            // colon
            "[\\x3B-\\x40]",
            //"[\\x41-\\x5A]",      // A-Z
            "[\\x5B-\\x5E]",
            //"[\\x5F]",            // underscore
            "[\\x60]",
            //"[\\x61-\\x7A]",      // a-z
            "[\\x7B-\\xB6]",
            //"[\\xB7]",            // "middle dot"
            "[\\xB8-\\xBF]",
            //"[\\xC0-\\xD6]",
            "[\\xD7]",              // multiplication sign
            //"[\\xD8-\\xF6]",
            "[\\xF7]",              // division sign
            //"[\\xF8-\\x2FF]",
            //"[\\x300-\\x36F]",
            //"[\\x370-\\x37D]",
            "[\\x37E]",             // greek semicolon
            //"[\\x37F-\\x1FFF]",
            "[\\x{2000}-\\x{200B}]",
            //"[\\x200C-\\x200D]",
            "[\\x{200E}-\\x{203E}]",
            //"[\\x203F-\\x2040]",
            "[\\x{2041}-\\x{206F}]",
            //"[\\x2070-\\x218F]",
            "[\\x{2190}-\\x{2BFF}]",
            //"[\\x2C00-\\x2FEF]",
            "[\\x{2FF0}-\\x{3000}]",
            //"[\\x3001-\\xD7FF]",
            "[\\x{D800}-\\x{F8FF}]",    // private use area
            //"[\\xF900-\\xFDCF]",
            "[\\x{FDD0}-\\x{FDEF}]",    // not characters
            //"[\\xFDF0-\\xFFFD]",
            "[\\x{FFFE}-\\x{FFFF}]",    // not characters
            //"[\\x10000-\\xEFFFF]"
        );

        return '/'.implode("|", $tmp).'/u';
    }
}
