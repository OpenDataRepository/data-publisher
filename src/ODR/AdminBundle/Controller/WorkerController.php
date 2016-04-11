<?php

/**
* Open Data Repository Data Publisher
* Worker Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The worker controller holds all of the functions that are called
* by the worker processes, excluding those in the XML, CSV, and 
* MassEdit controllers.
*
*/

namespace ODR\AdminBundle\Controller;

use ODR\AdminBundle\Entity\DataTreeMeta;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class WorkerController extends ODRCustomController
{

    /**
     * Called by the recaching background process to rebuild all the different versions of a DataRecord and store them in memcached.
     * 
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function recacherecordAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if ( !isset($post['tracked_job_id']) || !isset($post['datarecord_id']) || !isset($post['api_key']) || !isset($post['scheduled_at']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $datarecord_id = $post['datarecord_id'];
            $api_key = $post['api_key'];
            $scheduled_at = \DateTime::createFromFormat('Y-m-d H:i:s', $post['scheduled_at']);

            $delay = new \DateInterval( 'PT1S' );    // one second delay

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // TODO - get rid of $block

            // ----------------------------------------
            // Grab necessary objects
            $block = false;
            $datarecord = $repo_datarecord->find($datarecord_id);
            $datatype_id = null;

            $ret = '';
            if ($datarecord == null) {
                $ret = 'RecacheRecordCommand.php: Recache request for deleted DataRecord '.$datarecord_id.', skipping';
                $block = true;
            }
            else if ($datarecord->getProvisioned() == true) {
                $ret = 'RecacheRecordCommand.php: Recache request for provisioned Datarecord '.$datarecord_id.', skipping';
                $block = true;
            }

            if (!$block) {
                $em->refresh($datarecord);  // TODO - apparently $datarecord is sometimes NULL at this point?!
                $datatype = $datarecord->getDataType();
                if ($datatype == null) {
                    $ret = 'RecacheRecordCommand.php: Recache request involving DataRecord '.$datarecord_id.' requires deleted DataType, skipping';
                    $block = true;
                }

                $datatype_id = $datatype->getId();
            }


            // ----------------------------------------
            if (!$block) {
                // See if there are migration jobs in progress for this datatype
                $tracked_job = $repo_tracked_job->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
                if ($tracked_job !== null) {
                    $target_entity = $tracked_job->getTargetEntity();
                    $tmp = explode('_', $target_entity);
                    $datafield_id = $tmp[1];

                    $ret = 'RecacheRecordCommand.php: Datafield '.$datafield_id.' is currently being migrated to a different fieldtype...'."\n";
                    $return['r'] = 2;
                    $block = true;
                }
            }

            $tracked_job = null;
            $tracked_job_target = null;
            if (!$block) {
                // TODO - can this get moved to later, or does it have to stay here...STAYS HERE, UNTIL SYSTEM NEEDS TO GET TORN APART
                // Stores tracked job target incase ODRCustomController:updateDatatypeCache() starts another update while this action is running
                if ($tracked_job_id !== -1) {
                    $tracked_job = $repo_tracked_job->find($tracked_job_id);
                    if ($tracked_job !== null)
                        $tracked_job_target = $tracked_job->getRestrictions();
                }
            }


            // ----------------------------------------
            if (!$block) {
/*
                // TODO 
                $external_id_field = $datatype->getExternalIdField();
                $namefield = $datatype->getNameField();
                $sortfield = $datatype->getSortField();
                
                if ($external_id_field !== null) {
                    $typeclass = $external_id_field->getFieldType()->getTypeClass();
                
                    $query = $em->createQuery(
                       'SELECT e.value AS value
                        FROM ODRAdminBundle:'.$typeclass.' AS e
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                        WHERE e.dataRecord = :datarecord AND e.dataField = :datafield
                        AND e.deletedAt IS NULL AND drf.deletedAt IS NULL'
                    )->setParameters( array('datarecord' => $datarecord_id, 'datafield' => $external_id_field->getId()) );
                    $result = $query->getArrayResult();
                    $current_value = $result[0]['value'];
                
                    if ($typeclass == 'DatetimeValue')
                        $current_value = $current_value->format('Y-m-d');
                
                    if ($datarecord->getExternalId() !== $current_value) {
                //print 'set external_id to '.$current_value."\n";
                        $datarecord->setExternalId($current_value);
                    }
                }
                
                if ($namefield !== null) {
                    $typeclass = $namefield->getFieldType()->getTypeClass();
                
                    $query = $em->createQuery(
                       'SELECT e.value AS value
                        FROM ODRAdminBundle:'.$typeclass.' AS e
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                        WHERE e.dataRecord = :datarecord AND e.dataField = :datafield
                        AND e.deletedAt IS NULL AND drf.deletedAt IS NULL'
                    )->setParameters( array('datarecord' => $datarecord_id, 'datafield' => $namefield->getId()) );
                    $result = $query->getArrayResult();
                    $current_value = $result[0]['value'];
                
                    if ($typeclass == 'DatetimeValue')
                        $current_value = $current_value->format('Y-m-d');
                
                    if ($datarecord->getNamefieldValue() !== $current_value) {
                //print 'set namefield_value to '.$current_value."\n";
                        $datarecord->setNamefieldValue($current_value);
                    }
                }
                
                if ($sortfield !== null) {
                    $typeclass = $sortfield->getFieldType()->getTypeClass();
                
                    $query = $em->createQuery(
                       'SELECT e.value AS value
                        FROM ODRAdminBundle:'.$typeclass.' AS e
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                        WHERE e.dataRecord = :datarecord AND e.dataField = :datafield
                        AND e.deletedAt IS NULL AND drf.deletedAt IS NULL'
                    )->setParameters( array('datarecord' => $datarecord_id, 'datafield' => $sortfield->getId()) );
                    $result = $query->getArrayResult();
                    $current_value = $result[0]['value'];
                
                    if ($typeclass == 'DatetimeValue')
                        $current_value = $current_value->format('Y-m-d');
                
                    if ($datarecord->getSortfieldValue() !== $current_value) {
                //print 'set sortfield_value to '.$current_value."\n";
                        $datarecord->setSortfieldValue($current_value);
                    }
                }
                
                $em->persist($datarecord);
                $em->flush();
*/

                // ----------------------------------------
                // Determine if the datarecord is missing any memcached entries
                $oldest_revision = null;
                $missing_cache_entries = false;
                $memcache_keys = array('data_record_short_form', 'data_record_short_text_form', 'data_record_long_form', 'data_record_long_form_public');
                foreach ($memcache_keys as $memcache_key) {
                    $data = $memcached->get($memcached_prefix.'.'.$memcache_key.'_'.$datarecord_id);

                    if ($data == null)
                        $missing_cache_entries = true;
                    else if ($oldest_revision == null || $data['revision'] < $oldest_revision)
                        $oldest_revision = $data['revision'];                
                }

$ret = 'RecacheRecordCommand.php: Recache request for DataRecord '.$datarecord->getId().', datarecord_revision: '.$oldest_revision.'  datatype_revision: '.$datatype->getRevision().', ';
if ($missing_cache_entries == true)
    $ret .= 'missing_cache_entries: true'."\n";
else
    $ret .= 'missing_cache_entries: false'."\n";

                if ( $missing_cache_entries || $oldest_revision < $datatype->getRevision() ) {
                    // 
$ret .= 'Attempting to recache DataRecord '.$datarecord->getId().' of DataType '.$datatype->getId()."\n";
$logger->info('WorkerController::recacherecordAction()  Attempting to recache DataRecord '.$datarecord->getId().' of DataType '.$datatype->getId());

                    // Ensure all entities exist prior to attempting to render HTML for the datarecord
                    parent::verifyExistence($datarecord);
                    $current_revision = $datatype->getRevision();

                    // Render and cache the ShortResults form of the record
                    $short_form_html = parent::Short_GetDisplayData($request, $datarecord->getId());
                    $data = array( 'revision' => $current_revision, 'html' => $short_form_html );
                    $memcached->set($memcached_prefix.'.data_record_short_form_'.$datarecord_id, $data, 0);
/*
                    // Render and cache the TextResults form of the record
                    $short_form_html = parent::Text_GetDisplayData($request, $datarecord->getId());
                    $data = array( 'revision' => $current_revision, 'html' => $short_form_html );
                    $memcached->set($memcached_prefix.'.data_record_short_text_form_'.$datarecord_id, $data, 0);
*/
                    // Also render and store the public and non-public forms of the record
                    $long_form_html = parent::Long_GetDisplayData($request, $datarecord->getId(), 'force_render_all');
                    $data = array( 'revision' => $current_revision, 'html' => $long_form_html );
                    $memcached->set($memcached_prefix.'.data_record_long_form_'.$datarecord_id, $data, 0);

                    $long_form_html = parent::Long_GetDisplayData($request, $datarecord->getId(), 'public_only');
                    $data = array( 'revision' => $current_revision, 'html' => $long_form_html );
                    $memcached->set($memcached_prefix.'.data_record_long_form_public_'.$datarecord_id, $data, 0);

                    // Also recreate the XML version of the datarecord
                    $xml_export_path = dirname(__FILE__).'/../../../../web/uploads/xml_export/';

                    // Ensure directory exists
                    if ( !file_exists($xml_export_path) )
                        mkdir( $xml_export_path );

                    $filename = 'DataRecord_'.$datarecord_id.'.xml';
                    $handle = fopen($xml_export_path.$filename, 'w');
                    if ($handle !== false) {
                        $content = parent::XML_GetDisplayData($request, $datarecord->getId());
                        fwrite($handle, $content);
                        fclose($handle);
                    }

$ret .= '>> Recached DataRecord '.$datarecord->getId().' to datatype revision '.$current_revision."\n";
$logger->info('WorkerController::recacherecordAction() >> Recached DataRecord '.$datarecord->getId().' to datatype revision '.$datatype->getRevision());

                    // Update the job tracker if necessary
                    if ($tracked_job_id !== -1 && $tracked_job !== null) {

                        $em->refresh($tracked_job);

$ret .= '  original tracked_job_target: '.$tracked_job_target.'  current tracked_job_target: '.$tracked_job->getRestrictions()."\n";

                        if ( $tracked_job !== null && intval($tracked_job_target) == intval($tracked_job->getRestrictions()) ) {
                            $total = $tracked_job->getTotal();
                            $count = $tracked_job->incrementCurrent($em);

                            if ($count >= $total)
                                $tracked_job->setCompleted( new \DateTime() );
                        
                            $em->persist($tracked_job);
                            $em->flush();
$ret .= '  Set current to '.$count."\n";
                        }
                    }

                }
                else {
$ret = '>> Ignored update request for DataRecord '.$datarecord->getId()."\n";
$logger->info('WorkerController::recacherecordAction() >> Ignored update request for DataRecord '.$datarecord->getId());
                }

            }

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            // TODO - increment tracked job counter on error?

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x6642397853 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called by the migration background process to transfer data from one storage entity to another compatible storage entity.
     * 
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function migrateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
            if ( !isset($post['tracked_job_id']) || !isset($post['datarecord_id']) || !isset($post['datafield_id']) || !isset($post['user_id']) || !isset($post['old_fieldtype_id']) || !isset($post['new_fieldtype_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = $post['tracked_job_id'];
            $datarecord_id = $post['datarecord_id'];
            $datafield_id = $post['datafield_id'];
            $user_id = $post['user_id'];
            $old_fieldtype_id = $post['old_fieldtype_id'];
            $new_fieldtype_id = $post['new_fieldtype_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            $ret = '';

            // Grab necessary objects
            $user = $repo_user->find( $user_id );
            $datarecord = $repo_datarecord->find( $datarecord_id );
            $datafield = $repo_datafield->find( $datafield_id );
            $em->refresh($datafield);
            $old_fieldtype = $repo_fieldtype->find( $old_fieldtype_id );
            $old_typeclass = $old_fieldtype->getTypeClass();
            $new_fieldtype = $repo_fieldtype->find( $new_fieldtype_id );
            $new_typeclass = $new_fieldtype->getTypeClass();

            // Ensure datarecord/datafield pair exist
            if ($datarecord == null)
                throw new \Exception('Datarecord '.$datarecord_id.' is deleted');
            if ($datafield == null)
                throw new \Exception('Datafield '.$datafield_id.' is deleted');


            // Create a new datarecord field entity if it doesn't exist
            $em->refresh($datafield);
            $drf = $repo_datarecordfields->findOneBy( array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()) );
            if ($drf == null) {
                $drf = parent::ODR_addDataRecordField($em, $user, $datarecord, $datafield);
                $em->flush();
                $em->refresh($drf);
            }

            // Need to handle radio options separately...
            $old_typename = $old_fieldtype->getTypeName();
            $new_typename = $new_fieldtype->getTypeName();
            if ( ($old_typename == 'Multiple Radio' || $old_typename == 'Multiple Select') && ($new_typename == 'Single Radio' || $new_typename == 'Single Select') ) {
                // Grab all selected radio options
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id, rs.id AS rs_id, rs.selected AS selected, ro.id AS ro_id, rom.optionName AS option_name
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.dataRecordFields = drf
                    JOIN ODRAdminBundle:RadioOptions AS ro WITH rs.radioOption = ro
                    JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOptions = ro
                    WHERE drf.dataRecord = :datarecord AND drf.dataField = :datafield AND rs.selected = 1
                    AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL'
                )->setParameters( array('datarecord' => $datarecord->getId(), 'datafield' => $datafield->getId()) );
                $results = $query->getArrayResult();

                // If more than one radio option selected
                if ( count($results) > 1 ) {
                    // Deselect all but the first one in the list
                    for ($i = 1; $i < count($results); $i++) {
                        $rs_id = $results[$i]['rs_id'];
                        $radio_selection = $repo_radio_selection->find($rs_id);
                        $radio_selection->setSelected(0);

                        $ro_id = $results[$i]['ro_id'];
                        $option_name = $results[$i]['option_name'];
                        $ret .= '>> Deselected Radio Option '.$ro_id.' ('.$option_name.')'."\n";
                    }
                    $em->flush();
                }
            }
            else if ( $new_typeclass !== 'Radio' ) {
                // Grab both the source entity repository and the destination entity repository
                $src_repository = $em->getRepository('ODRAdminBundle:'.$old_typeclass);
                $dest_repository = $em->getRepository('ODRAdminBundle:'.$new_typeclass);

                // Grab the entity that needs to be migrated
//$ret .= '>> Looking for "'.$old_fieldtype->getTypeClass().'" for datafield '.$datafield->getId().' and datarecordfield '.$drf->getId()."\n";
                $src_entity = $src_repository->findOneBy(array('dataField' => $datafield->getId(), 'dataRecordFields' => $drf->getId()));

                // No point migrating anything if the src entity doesn't exist in the first place...would be no data in it
                if ($src_entity !== null) {
                    $logger->info('WorkerController::migrateAction() >> Attempting to migrate data from "'.$old_typeclass.'" '.$src_entity->getId().' to "'.$new_typeclass.'"');
                    $ret .= '>> Attempting to migrate data from "'.$old_typeclass.'" '.$src_entity->getId().' to "'.$new_typeclass.'"'."\n";

                    // See if the destination repository already has an entry matching this combination of datafield and datarecordfield...
                    $dest_entity = $dest_repository->findOneBy(array('dataField' => $datafield->getId(), 'dataRecordFields' => $drf->getId()));
                    if ($dest_entity == null) {
                        // Create a new storage entity for the destination if none exists
                        $dest_entity = parent::ODR_addStorageEntity($em, $user, $drf->getDataRecord(), $datafield);
                        $ret .= '>> >> [new '.$new_typeclass.']  ';
                    }
                    else {
                        $ret .= '>> >> ['.$new_typeclass.' id '.$dest_entity->getId().']  ';
                    }

                    $value = null;
                    if ( ($old_typeclass == 'ShortVarchar' || $old_typeclass == 'MediumVarchar' || $old_typeclass == 'LongVarchar' || $old_typeclass == 'LongText') && ($new_typeclass == 'ShortVarchar' || $new_typeclass == 'MediumVarchar' || $new_typeclass == 'LongVarchar' || $new_typeclass == 'LongText') ) {
                        // text -> text requires nothing special
                        $value = $src_entity->getValue();
                    }
                    else if ( ($old_typeclass == 'IntegerValue' || $old_typeclass == 'DecimalValue') && ($new_typeclass == 'ShortVarchar' || $new_typeclass == 'MediumVarchar' || $new_typeclass == 'LongVarchar' || $new_typeclass == 'LongText') ) {
                        // number -> text is easy
                        $value = strval($src_entity->getValue());
                    }
                    else if ($old_typeclass == 'IntegerValue' && $new_typeclass == 'DecimalValue') {
                        // integer -> decimal
                        $value = floatval($src_entity->getValue());
                    }
                    else if ($old_typeclass == 'DecimalValue' && $new_typeclass == 'IntegerValue') {
                        // decimal -> integer
                        $value = intval($src_entity->getValue());
                    }
                    else if ( ($old_typeclass == 'ShortVarchar' || $old_typeclass == 'MediumVarchar' || $old_typeclass == 'LongVarchar' || $old_typeclass == 'LongText') && ($new_typeclass == 'IntegerValue') ) {
                        // text -> integer
                        $pattern = '/[^0-9\.\-]+/i';
                        $replacement = '';
                        $new_value = preg_replace($pattern, $replacement, $src_entity->getValue());

                        $value = intval($new_value);
                    }
                    else if ( ($old_typeclass == 'ShortVarchar' || $old_typeclass == 'MediumVarchar' || $old_typeclass == 'LongVarchar' || $old_typeclass == 'LongText') && ($new_typeclass == 'DecimalValue') ) {
                        // text -> decimal
                        $pattern = '/[^0-9\.\-]+/i';
                        $replacement = '';
                        $new_value = preg_replace($pattern, $replacement, $src_entity->getValue());

                        $value = floatval($new_value);
                    }

                    // Save changes
                    $ret .= 'set dest_entity to "'.$value.'"'."\n";
                    $dest_entity->setValue($value);
                    $dest_entity->setUpdatedBy($user);

                    $em->persist($dest_entity);
                    $em->flush();
                }
                else {
                    $ret .= '>> No '.$old_typeclass.' source entity for datarecord "'.$datarecord->getId().'" datafield "'.$datafield->getId().'", skipping'."\n";
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
                $em->flush();
$ret .= '  Set current to '.$count."\n";
            }

/*
            // ----------------------------------------
            // Schedule the datarecord for an update
            $options = array();
            parent::updateDatarecordCache($datarecord_id, $options);

            $logger->info('WorkerController:migrateAction()  >> scheduled DataRecord '.$datarecord_id.' for update');
            $ret .= '>> scheduled DataRecord '.$datarecord_id.' for update'."\n";
*/
            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            // TODO - increment tracked job counter on error?

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x6642397856: '.$e->getMessage()."\n".$ret;
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Begins the process of rebuilding the image thumbnails for a specific datatype.
     * 
     * @param integer $datatype_id Which datatype should have all its image thumbnails rebuilt
     * @param Request $request
     *
     * @return Response
     */
    public function startrebuildthumbnailsAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $memcached = $this->get('memcached');
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $api_key = $this->container->getParameter('beanstalk_api_key');

            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            // TODO - check for permissions?  restrict rebuild of thumbnails to certain datatypes?

            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------


            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_rebuild_thumbnails');

            // Grab a list of all full-size images on the site
            $query = $em->createQuery(
               'SELECT e.id
                FROM ODRAdminBundle:Image AS e
                JOIN ODRAdminBundle:DataRecord AS dr WITH e.dataRecord = dr
                WHERE dr.dataType = :datatype AND e.parent IS NULL
                AND e.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters(array('datatype' => $datatype_id));
            $results = $query->getArrayResult();

//print_r($results);
//return;

            if (count($results) > 0) {
                // ----------------------------------------
                // Get/create an entity to track the progress of this thumbnail rebuild
                $job_type = 'rebuild_thumbnails';
                $target_entity = 'datatype_'.$datatype_id;
                $additional_data = array('description' => 'Rebuild of all image thumbnails for DataType '.$datatype_id);
                $restrictions = '';
                $total = count($results);
                $reuse_existing = false;

                $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
                $tracked_job_id = $tracked_job->getId();

                // ----------------------------------------
                $object_type = 'image';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('rebuild_thumbnails')->put($payload, $priority, $delay);
                }
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38472782 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called by the rebuild_thumbnails worker process to rebuild the thumbnails of one of the uploaded images on the site. 
     * 
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function rebuildthumbnailsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        $tracked_job_id = -1;

        try {
            $post = $_POST;
            if ( !isset($post['tracked_job_id']) || !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = $post['tracked_job_id'];
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Ensure the full-size image exists
            parent::decryptObject($object_id, $object_type);

            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');
            $img = $repo_image->find($object_id);

            if ($img == null)
                throw new \Exception('Image '.$object_id.' has been deleted');

            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
            $user = $repo_user->find(2);    // TODO

            // Recreate the thumbnail from the full-sized image
            parent::resizeImages($img, $user);


            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total)
                    $tracked_job->setCompleted( new \DateTime() );

                $em->persist($tracked_job);
                $em->flush();
//$ret .= '  Set current to '.$count."\n";
            }

            $return['d'] = '>> Rebuilt thumbnails for '.$object_type.' '.$object_id."\n";
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
            $return['d'] = 'Error 0x38472782 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO
     *
     * @param Request $request
     *
     * @return Response TODO
     */
    public function cryptorequestAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['crypto_type']) || !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['target_filepath']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $crypto_type = $post['crypto_type'];
            $object_type = strtolower( $post['object_type'] );
            $object_id = $post['object_id'];
            $target_filepath = $post['target_filepath'];
            $api_key = $post['api_key'];

            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $crypto = $this->get("dterranova_crypto.crypto_adapter");
            $crypto_dir = dirname(__FILE__).'/../../../../app/crypto_dir/';     // TODO - load from config file somehow?

            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            if ( !is_numeric($post['object_id']) )
                throw new \Exception('Invalid Form');
            else
                $object_id = intval($object_id);


            // ----------------------------------------
            if ($crypto_type == 'encrypt') {

                // ----------------------------------------
                // Locate the directory with the encrypted file chunks
                $base_obj = null;
                if ($object_type == 'file') {
                    $crypto_dir .= 'File_'.$object_id;
                    $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
                }
/*
                else if ($object_type == 'image') {
                    $crypto_dir .= 'Image_'.$object_id;
                    $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
                }
*/
                if ($base_obj == null)
                    throw new \Exception('Invalid Form');

                // Move file from completed directory to decrypted directory in preparation for encryption...
                $destination_path = dirname(__FILE__).'/../../../../web';
                $destination_filename = $base_obj->getUploadDir().'/File_'.$object_id.'.'.$base_obj->getExt();
                rename( $base_obj->getLocalFileName(), $destination_path.'/'.$destination_filename );

                // Update local filename in database...
                $base_obj->setLocalFileName($destination_filename);

                // Encryption of a given file/image is simple...
                parent::encryptObject($object_id, $object_type);

                // Calculate/store the checksum of the file to indicate the encryption process is complete
                $filepath = parent::decryptObject($object_id, $object_type);
                $original_checksum = md5_file($filepath);
                $base_obj->setOriginalChecksum($original_checksum);

                $em->persist($base_obj);
                $em->flush();
                $em->refresh($base_obj);

                // Delete the decrypted version of the file/image off the server after it's completed
                unlink($filepath);

                // TODO - run graph plugin on new file
            }
            else {
/*
                // ...otherwise, need to manually decrypt all file chunks and write them to the specified file

                // ----------------------------------------
                // Grab the hex string representation that the file was encrypted with
                $key = $base_obj->getEncryptKey();
                // Convert the hex string representation to binary...php had a function to go bin->hex, but didn't have a function for hex->bin for at least 7 years?!?
                $key = pack("H*" , $key);   // don't have hex2bin() in current version of php...this appears to work based on the "if it decrypts to something intelligible, you did it right" theory


                // ----------------------------------------
                // Open the target file
                $handle = fopen($target_filepath, "wb");
                if (!$handle)
                    throw new \Exception('Unable to open "'.$target_filepath.'" for writing');

                // Decrypt each chunk and write to target file
                $chunk_id = 0;
                while( file_exists($crypto_dir.'/'.'enc.'.$chunk_id) ) {
                    if ( !file_exists($crypto_dir.'/'.'enc.'.$chunk_id) )
                        throw new \Exception('Encrypted chunk not found: '.$crypto_dir.'/'.'enc.'.$chunk_id);

                    $data = file_get_contents($crypto_dir.'/'.'enc.'.$chunk_id);
                    fwrite($handle, $crypto->decrypt($data, $key));
                    $chunk_id++;
                }
                fclose($handle);

                // Don't need to do anything else
*/
/*
                $obj = null;
                if ( strtolower($object_type) == 'file')
                    $obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
                else if ( strtolower($object_type) == 'image')
                    $obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
                else
                    throw new \Exception('Invalid Form, got "'.strtolower($object_type).'"');

                if ($obj == null)
                    throw new \Exception('Deleted '.$object_type);


                $absolute_path = parent::decryptObject($object_id, $object_type);

                if ( strtolower($object_type) == 'file') {
                    clearstatcache(true, $absolute_path);
                    $filesize = filesize($absolute_path);
                    $checksum = md5_file($absolute_path);

                    $obj->setFilesize($filesize);
                    $obj->setOriginalChecksum($checksum);
                    $em->persist($obj);
                    $em->flush();
                }
                else if ( strtolower($object_type) == 'image') {
                    $checksum = md5_file($absolute_path);

                    $obj->setOriginalChecksum($checksum);
                    $em->persist($obj);
                    $em->flush();
                }

                unlink($absolute_path);
*/
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x65384782 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...checks for non-deleted datarecord entities belonging to deleted datatypes
     *
     * @param Request $request
     *
     * @return string
     */
    public function drcheckAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT dt.id AS dt_id, dr.id AS dr_id
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
            WHERE dt.deletedAt IS NOT NULL AND dr.deletedAt IS NULL
            ORDER BY dt.id, dr.id');
        $results = $query->getArrayResult();
        $em->getFilters()->enable('softdeleteable');

print '<pre>';
        foreach ($results as $result) {
            $datatype_id = $result['dt_id'];
            $datarecord_id = $result['dr_id'];

            print 'DataRecord '.$datarecord_id.' (DataType '.$datatype_id.') was not deleted'."\n";

            // Delete DataRecordField entries for this datarecord
            // TODO - do this with a DQL update query?
            $query = $em->createQuery(
               'SELECT drf.id AS drf_id
                FROM ODRAdminBundle:DataRecordFields drf
                WHERE drf.dataRecord = :datarecord'
            )->setParameters( array('datarecord' => $datarecord_id) );
            $results = $query->getResult();
            foreach ($results as $num => $data) {
                $drf_id = $data['drf_id'];
                print '-- deleting drf '.$drf_id."\n";

                $drf = $repo_datarecordfields->find($drf_id);
                $em->remove($drf);
            }

            $datarecord = $repo_datarecord->find($datarecord_id);
            $em->remove($datarecord);
        }
print '</pre>';

        $em->flush();

    }


    /**
     * Debug function...checks for orphaned datarecordfield entities that somehow didn't get deleted after a datafield got deleted
     *
     * @param Request $request
     *
     * @return string
     */
    public function dfcheckAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT df.id AS df_id, ft.typeName AS type_name
            FROM ODRAdminBundle:DataFields df
            JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
            JOIN ODRAdminBundle:FieldType AS ft WITH df.fieldType = ft
            WHERE df.deletedAt IS NOT NULL
            ORDER BY df.id');
        $datafields = $query->getResult();
        $em->getFilters()->enable('softdeleteable');

print '<pre>';
        foreach ($datafields as $tmp) {
            $datafield_id = $tmp['df_id'];
            $type_name = $tmp['type_name'];
            print "\n".'DataField '.$datafield_id.' ('.$type_name.')'."\n";

            $query = $em->createQuery(
               'SELECT drf.id AS drf_id, dr.id AS dr_id
                FROM ODRAdminBundle:DataRecordFields AS drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE drf.dataField = :datafield
                AND drf.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            $results = $query->getResult();

            $entries = array();
            foreach ($results as $result) {
                $datarecord_id = $result['dr_id'];
                $datarecordfield_id = $result['drf_id'];

                print '-- drf '.$datarecordfield_id.' (dr '.$datarecord_id.', df '.$datafield_id.') was not deleted'."\n";

                $drf = $repo_datarecordfields->find($datarecordfield_id);
                $em->remove($drf);
            }
            print "\n";
        }

        $em->flush();

print '</pre>';
    }


    /**
     * Debuf function...checks for duplicate datarecordfield entities (those that share the same datarecord/datafield key pair)
     * TODO - check for non-deleted drf entities that point to deleted datarecords? 
     *
     * @param integer $datatype_id
     * @param Request $request
     * 
     * @return string
     */
    public function drfcheckAction($datatype_id, Request $request)
    {
        $delete_entities = true;
        $delete_entities = false;

        $deleted_entities = array();
        $em = $this->getDoctrine()->getManager();
        $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

        $query = $em->createQuery(
           'SELECT df.id AS df_id, ft.typeName AS type_name
            FROM ODRAdminBundle:DataFields df
            JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
            JOIN ODRAdminBundle:FieldType AS ft WITH df.fieldType = ft
            WHERE dt.id = :datatype AND df.deletedAt IS NULL AND dt.deletedAt IS NULL
            ORDER BY df.id'
        )->setParameters( array('datatype' => $datatype_id) );
        $datafields = $query->getArrayResult();
print '<pre>';
        foreach ($datafields as $tmp) {
            $datafield_id = $tmp['df_id'];
            $type_name = $tmp['type_name'];
            print "\n".'DataField '.$datafield_id.' ('.$type_name.')'."\n";

            $query = $em->createQuery(
               'SELECT drf.id AS drf_id, dr.id AS dr_id
                FROM ODRAdminBundle:DataRecordFields drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE drf.dataField = :datafield
                AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            $results = $query->getResult();

//print_r($results);

            $entries = array();
            foreach ($results as $result) {
                $datarecord_id = $result['dr_id'];
                $datarecordfield_id = $result['drf_id'];

                if ( isset($entries[$datarecord_id]) ) {
                    print '-- DataRecord '.$datarecord_id.': duplicate datarecordfield entry '.$datarecordfield_id.' (had '.$entries[$datarecord_id].')'."\n";

                    $old_drf = $repo_datarecordfields->find($entries[$datarecord_id]);
                    $new_drf = $repo_datarecordfields->find($datarecordfield_id);
                    $fieldtype = $old_drf->getDataField()->getFieldType()->getTypeClass();
                    $skip = false;
                    $delete_new_drf = true;
                    switch ($fieldtype) {
                        case 'Radio':
                            $query = $em->createQuery(
                               'SELECT drf.id AS drf_id, rom.optionName AS option_name
                                 FROM ODRAdminBundle:RadioOptions AS ro
                                 JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOptions = ro
                                 JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.radioOption = ro
                                 JOIN ODRAdminBundle:DataRecordFields AS drf WITH rs.dataRecordFields = drf
                                 WHERE ro.dataFields = :datafield AND (drf.id = :old_drf OR drf.id = :new_drf) AND rs.selected = 1
                                 AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL AND rs.deletedAt IS NULL AND drf.deletedAt IS NULL'
                            )->setParameters( array('datafield' => $datafield_id, 'old_drf' => $old_drf->getId(), 'new_drf' => $new_drf->getId()) );
                            $sub_results = $query->getResult();
//print_r($sub_results);
                            $old = array();  $new = array();
                            foreach ($sub_results as $sub_result) {
                                $drf_id = $sub_result['drf_id'];
                                $option_name = $sub_result['option_name'];
                                if ($drf_id == $old_drf->getId())
                                    $old[] = $option_name;
                                else
                                    $new[] = $option_name;
                            }
                            $old_str = implode(', ', $old);
                            $new_str = implode(', ', $new);
                            if ( strcmp($old_str, $new_str) !== 0 )
                                print '-- -- old values: "'.implode(', ', $old).'" new values: "'.implode(', ', $new).'"'."\n";
                            else
                                print '-- -- values are identical'."\n";

                            if ( $old_str == '' && $new_str != '' )
                                $delete_new_drf = false;
                            else if ( $old_str != '' && $new_str != '' )
                                $skip = true;

                            break;

                        case 'DatetimeValue':
                            if ($old_drf->getAssociatedEntity() == null) {
                                // old drf doesn't point to a storage entity, get rid of it
                                $delete_new_drf = false;
                                print '-- -- no old value'."\n";
                            }
                            else if ($new_drf->getAssociatedEntity() == null) {
                                // new drf doesn't point to a storage entity, get rid of it
                                $delete_new_drf = true;
                                print '-- -- no new value'."\n";
                            }
                            else {
                                $old_value = $old_drf->getAssociatedEntity()->getValue();
                                $old_value = $old_value->format('Y-m-d');
                                $new_value = $new_drf->getAssociatedEntity()->getValue();
                                $new_value = $new_value->format('Y-m-d');

                                if ( strcmp($old_value, $new_value) !== 0 )
                                    print '-- -- old value: "'.$old_value.'" new value: "'.$new_value.'"'."\n";
                                else
                                    print '-- -- values are identical'."\n";

                                if ( $old_value == '-0001-11-30' && $new_value != '-0001-11-30' )
                                    $delete_new_drf = false;
                                else if ( $old_value != '-0001-11-30' && $new_value != '-0001-11-30' )
                                    $skip = true;
                            }

                            break;

                        case 'Boolean':
                        case 'ShortVarchar':
                        case 'MediumVarchar':
                        case 'LongVarchar':
                        case 'LongText':
                        case 'IntegerValue':
                            if ($old_drf->getAssociatedEntity() == null) {
                                // old drf doesn't point to a storage entity, get rid of it
                                $delete_new_drf = false;
                                print '-- -- no old value'."\n";
                            }
                            else if ($new_drf->getAssociatedEntity() == null) {
                                // new drf doesn't point to a storage entity, get rid of it
                                $delete_new_drf = true;
                                print '-- -- no new value'."\n";
                            }
                            else {
                                $old_value = $old_drf->getAssociatedEntity()->getValue();
                                $new_value = $new_drf->getAssociatedEntity()->getValue();
                                if ( strcmp($old_value, $new_value) !== 0 )
                                    print '-- -- old value: "'.$old_value.'" new value: "'.$new_value.'"'."\n";
                                else
                                    print '-- -- values are identical'."\n";

                                if ( $old_value == '' && $new_value != '' )
                                    $delete_new_drf = false;
                                else if ( $old_value != '' && $new_value != '' )
                                    $skip = true;
                            }

                            break;

                        case 'File':
                        case 'Image':
                            $skip = true;
                            break;
                    }

                    if (!$skip) {
                        if ($delete_new_drf) {
                            $deleted_entities[] = $new_drf->getId();
                            if ($delete_entities) {
                                $em->remove($new_drf);
                                print '-- >> new drf deleted'."\n";
                            }
                            else {
                                print '-- >> new drf would be deleted'."\n";
                            }
                        }
                        else {
                            $deleted_entities[] = $old_drf->getId();
                            if ($delete_entities) {
                                $em->remove($old_drf);
                                print '-- >> old drf deleted'."\n";
                            }
                            else {
                                print '-- >> old drf would be deleted'."\n";
                            }
                        }

                        if ($delete_entities)
                            $em->flush();
                    }

                }
                else {
                    $entries[$datarecord_id] = $datarecordfield_id;
                }
            }
        }

if ($delete_entities)
   print "\n".'Deleted these drf entities...'."\n";
else
   print "\n".'Would deleted these drf entities...'."\n";

print_r($deleted_entities);
print '</pre>';
    }


    /**
     * Debug function...check for duplicate storage entities (those with the same datarecord/datafield key pair)
     * TODO ...
     * 
     * @param Request $request
     * 
     * @return string
     */
    public function entitycheckAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

print '<pre>';
        $entities = array('Boolean', /*'File', 'Image',*/ 'IntegerValue', 'LongText', 'LongVarchar', 'MediumVarchar', /*'Radio',*/ 'ShortVarchar', 'DatetimeValue', 'DecimalValue');
        foreach ($entities as $entity) {
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT e.id AS e_id, dr.id AS dr_id, df.id AS df_id, e.value AS value, drf.id AS drf_id, drf.deletedAt AS drf_deletedAt
                FROM ODRAdminBundle:'.$entity.' e
                JOIN ODRAdminBundle:DataRecord AS dr WITH e.dataRecord = dr
                JOIN ODRAdminBundle:DataRecord AS df WITH e.dataField = df
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                WHERE e.deletedAt IS NULL AND dr.deletedAt IS NULL AND df.deletedAt IS NULL AND drf.deletedAt IS NOT NULL
                ORDER BY dr.id, df_id, e.id');
            $iterableResult = $query->iterate();
print $entity."\n";

            $prev_dr = $prev_df = $prev_e = $prev_value = null;
            $i = 0;
            foreach ($iterableResult as $result) {
//print_r($result);
                $row = $result[$i];
                $e_id = $row['e_id'];
                $dr_id = $row['dr_id'];
                $df_id = $row['df_id'];
                $drf_id = $row['drf_id'];

                $drf_deletedAt = $row['drf_deletedAt'];
                $drf_deletedAt = $drf_deletedAt->format('Y-m-d');

                $value = '';
                if ($entity == 'DatetimeValue') {
                    $e = $row['value'];
                    $value = $e->format('Y-m-d');
                }
                else {
                    $value = $row['value'];
                }

                print 'Datarecord '.$dr_id.' Datafield '.$df_id."\n";
                print '-- entity '.$e_id.' points to deleted drf '.$drf_id."\n";

                $i++;
            }

            $em->getFilters()->enable('softdeleteable');
print "\n\n";
        }
print '</pre>';
    }


    /**
     * Begins the process of forcibly (re)encrypting every uploaded file/image on the site
     *
     * @param string $object_type "File" or "Image"...which type of entity to encrypt
     * @param Request $request
     *
     */
    public function startencryptAction($object_type, Request $request)
    {
/*
        $em = $this->getDoctrine()->getManager();
        $pheanstalk = $this->get('pheanstalk');
        $router = $this->container->get('router');
        $memcached = $this->get('memcached');
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        $api_key = $this->container->getParameter('beanstalk_api_key');

        // Generate the url for cURL to use
        $url = $this->container->getParameter('site_baseurl');
        $url .= $router->generate('odr_force_encrypt');

        if ($object_type == 'file' || $object_type == 'File')
            $object_type = 'File';
        else if ($object_type == 'image' || $object_type == 'Image')
            $object_type = 'Image';
        else
            return null;

        $query = $em->createQuery(
           'SELECT e.id
            FROM ODRAdminBundle:'.$object_type.' AS e
            WHERE e.deletedAt IS NULL'
        );
        $results = $query->getResult();

//print_r($results);
//return;

        $object_type = strtolower($object_type);
        foreach ($results as $num => $result) {
            $object_id = $result['id'];

            // Insert the new job into the queue
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "object_type" => $object_type,
                    "object_id" => $object_id,
                    "memcached_prefix" => $memcached_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                )
            );

            $delay = 1;
            $pheanstalk->useTube('mass_encrypt')->put($payload, $priority, $delay);

//return;
        }
*/
    }


    /**
     * Called by the mass_encrypt worker background process to (re)encrypt a single file or image.
     *
     * @param Request $request
     *
     * @return Response TODO
     */
    public function encryptAction(Request $request)
    {
/*
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            parent::decryptObject($object_id, $object_type);    // ensure a decrypted object exists prior to attempting to encrypt
            parent::encryptObject($object_id, $object_type);

            $return['d'] = '>> Encrypted '.$object_type.' '.$object_id."\n";
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38378231 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
*/
    }


    /**
     * Begins the process of forcibly decrypting every uploaded file/image on the site.
     *
     * @param string $object_type "File" or "Image"...which type of entity to encrypt
     * @param Request $request
     *
     */
    public function startdecryptAction($object_type, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $memcached = $this->get('memcached');
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $api_key = $this->container->getParameter('beanstalk_api_key');

            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_crypto_request');

            if ($object_type == 'file' || $object_type == 'File')
                $object_type = 'File';
            else if ($object_type == 'image' || $object_type == 'Image')
                $object_type = 'Image';
            else
                return null;

            $query = null;
            if ($object_type == 'File') {
                $query = $em->createQuery(
                    'SELECT e.id
                    FROM ODRAdminBundle:'.$object_type.' AS e
                    WHERE e.deletedAt IS NULL AND (e.original_checksum IS NULL OR e.filesize = 0)'
                );
            }
            else if ($object_type == 'Image') {
                $query = $em->createQuery(
                    'SELECT e.id
                    FROM ODRAdminBundle:'.$object_type.' AS e
                    WHERE e.deletedAt IS NULL AND e.original_checksum IS NULL'
                );
            }
            $results = $query->getResult();

            $object_type = strtolower($object_type);
            foreach ($results as $num => $file) {
                // Insert the new job into the queue
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "object_type" => $object_type,
                        "object_id" => $file['id'],
                        "target_filepath" => '',
                        "crypto_type" => 'decrypt',
                        "memcached_prefix" => $memcached_prefix,    // debug purposes only
                        "url" => $url,
                        "api_key" => $api_key,
                    )
                );

                $delay = 1;
                $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x45387831 '.$e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called by the mass_encrypt worker background process to decrypt a single file/image.
     *
     * @param Request $request
     *
     * @return Response TODO
     */
    public function decryptAction(Request $request)
    {
/*
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $em = $this->getDoctrine()->getManager();

            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            $obj = null;
            if ($object_type == 'file')
                $obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
//            else if ($object_type == 'image')
//                $obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);

            $absolute_path = parent::decryptObject($object_id, $object_type);
            $return['d'] = '>> Decrypted '.$object_type.' '.$object_id."\n";

            $obj->setFilesize( filesize($absolute_path) );

            $em->persist($obj);
            $em->flush();

            unlink($absolute_path);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38373431 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
*/
    }


    /**
     * Debug function...deletes radio selection entities belonging to deleted radio options
     *
     * @param Request $request
     *
     */
    public function deletedradiocheckAction(Request $request)
    {
return;

        $em = $this->getDoctrine()->getManager();

        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT ro.id AS ro_id
            FROM ODRAdminBundle:RadioOptions AS ro
            WHERE ro.deletedAt IS NOT NULL
            ORDER BY ro.id');
        $results = $query->getArrayResult();
        $em->getFilters()->enable('softdeleteable');

        foreach ($results as $num => $tmp) {
            $radio_option_id = $tmp['ro_id'];

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:RadioSelection AS rs
                SET rs.deletedAt = :now
                WHERE rs.radioOption = :radio_option_id AND rs.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'radio_option_id' => $radio_option_id) );
            $updated = $query->execute();
        }
    }


    /**
     * displays/deletes duplicate radio selection options
     * TODO - figure out why the page appears to auto-reload when deletion is enabled
     * TODO - convert to native SQL because doctrine can't handle the number of results when run on UAMM
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return string TODO
     */
    public function duplicateradiocheckAction($datatype_id, Request $request)
    {

return;

        $em = $this->getDoctrine()->getManager();

        $query = $em->createQuery(
           'SELECT dt.id AS dt_id, dt.shortName, dr.id AS dr_id, df.id AS df_id, df.fieldName, ro.id AS ro_id, rom.optionName, rs.id AS rs_id, rs.selected
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
            JOIN ODRAdminBundle:FieldType AS ft WITH df.fieldType = ft
            JOIN ODRAdminBundle:RadioOptions AS ro WITH ro.dataFields = df
            JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOptions = ro
            JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.radioOption = ro
            JOIN ODRAdminBundle:DataRecordFields AS drf WITH rs.dataRecordFields = drf
            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
            WHERE dt.id = :datatype AND ft.typeClass = :typeclass
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL AND rs.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL
            ORDER BY dt.id, dr.id, df.id, ro.id'
        )->setParameters( array('datatype' => $datatype_id, 'typeclass' => 'Radio') );
        $results = $query->getArrayResult();
/*
print '<pre>';
print_r($results);
print '</pre>';
*/

        // Convert result into array format
        $datatype_names = array();
        $datafield_names = array();
        $radio_option_names = array();
        $data = array();
        foreach ($results as $num => $result) {
            $dt_id = $result['dt_id'];
            $datatype_name = $result['shortName'];
            $dr_id = $result['dr_id'];
            $df_id = $result['df_id'];
            $datafield_name = $result['fieldName'];
            $ro_id = $result['ro_id'];
            $radio_option_name = $result['optionName'];
            $rs_id = $result['rs_id'];
            $selected = $result['selected'];
//$selected = $selected->format('Y-m-d h:m:s');

            // Deal with names first
            if ( !isset($datatype_names[$dt_id]) )
                $datatype_names[$dt_id] = $datatype_name;
            if ( !isset($datafield_names[$df_id]) )
                $datafield_names[$df_id] = $datafield_name;
            if ( !isset($radio_option_names[$ro_id]) )
                $radio_option_names[$ro_id] = $radio_option_name;

            // Now deal with content
            if ( !isset($data[$dt_id]) )
                $data[$dt_id] = array();

            if ( !isset($data[$dt_id][$dr_id]) )
                $data[$dt_id][$dr_id] = array();

            if ( !isset($data[$dt_id][$dr_id][$df_id]) )
                $data[$dt_id][$dr_id][$df_id] = array();

            if ( !isset($data[$dt_id][$dr_id][$df_id][$ro_id]) )
                $data[$dt_id][$dr_id][$df_id][$ro_id] = array();

            $data[$dt_id][$dr_id][$df_id][$ro_id][$rs_id] = $selected;
        }
/*
print '<pre>';
print_r($data);
print '</pre>';
*/
        // Copy duplicates into another array
        $duplicates = array();
        foreach ($data as $dt_id => $datatype_data) {
            foreach ($datatype_data as $dr_id => $datarecord_data) {
                foreach ($datarecord_data as $df_id => $datafield_data) {
                    foreach ($datafield_data as $ro_id => $radio_option_data) {
                        if ( count($radio_option_data) > 1 )
                            $duplicates[$dt_id][$dr_id][$df_id][$ro_id] = $radio_option_data;
                    }
                }
            }
        }
/*
print '<pre>';
print_r($duplicates);
print '</pre>';
*/

        // Print duplicates
$count = 0;
print '<pre>';
        foreach ($duplicates as $dt_id => $datatype_data) {
            print 'Datatype '.$dt_id.' ('.$datatype_names[$dt_id].'): '."\n";

            foreach ($datatype_data as $dr_id => $datarecord_data) {
                print '-- Datarecord '.$dr_id.':'."\n";

                foreach ($datarecord_data as $df_id => $datafield_data) {
                    print '-- -- Datafield '.$df_id.' ('.$datafield_names[$df_id].'): '."\n";

                    foreach ($datafield_data as $ro_id => $radio_option_data) {
                        print '-- -- -- Radio Option '.$ro_id.' ('.$radio_option_names[$ro_id].'): '."\n";

                        foreach ($radio_option_data as $rs_id => $selected) {
                            print '-- -- -- -- Radio Selection '.$rs_id.': '.$selected."\n";
/*
if ($selected == 1) {
    $rs = $em->getRepository('ODRAdminBundle:RadioSelection')->find($rs_id);
    $em->remove($rs);
    $count++;
    if ( ($count % 10) == 0 )
        $em->flush();
}
*/
                        }
                    }
                }
            }
        }
print '</pre>';

//$em->flush();

    }


    /**
     * Looks for entities without metadata entries in the database, and schedules them for beanstalk to create
     *
     * @param integer $datatype_id Which datatype should have all its image thumbnails rebuilt
     * @param Request $request
     *
     * @return Response
     */
    public function startbuildmetadataentriesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $memcached = $this->get('memcached');
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $api_key = $this->container->getParameter('beanstalk_api_key');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            // TODO - check for permissions?  restrict rebuild of thumbnails to certain datatypes?

            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------


            // ----------------------------------------
            // Radio Options
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_radio_option_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT ro.id AS id
                FROM ODRAdminBundle:RadioOptions AS ro'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print_r($results);
//return;

            if (count($results) > 0) {
                $object_type = 'radio_option';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

            // ----------------------------------------
            // Files
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_file_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT e.id AS id
                FROM ODRAdminBundle:File AS e'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print_r($results);
//return;

            if (count($results) > 0) {
                $object_type = 'file';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

            // ----------------------------------------
            // Images
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_image_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT e.id AS id
                FROM ODRAdminBundle:Image AS e
                WHERE e.original = 1'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print_r($results);
//return;

            if (count($results) > 0) {
                $object_type = 'image';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

            // ----------------------------------------
            // DataTree
            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_datatree_metadata');

            // Grab a list of all radio options in the database
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT dt.id AS id
                FROM ODRAdminBundle:DataTree AS dt'
            );
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

//print_r($results);
//return;

            if (count($results) > 0) {
                $object_type = 'datatree';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
//                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('build_metadata')->put($payload, $priority, $delay);
                }
            }

            // ----------------------------------------
            // TODO - Datafields
            // ----------------------------------------

            // ----------------------------------------
            // TODO - Datarecords
            // ----------------------------------------

            // ----------------------------------------
            // TODO - Datatypes
            // ----------------------------------------
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x472127862 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified radio option if needed
     *
     * @param Request $request
     */
    public function buildradiooptionmetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted radio options too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the radio option entity exists...
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($object_id);
            if ($radio_option == null)
                throw new \Exception('Radio Option does not exist');

            // Attempt to locate a metadata entry for the provided entity
            $radio_option_meta = $em->getRepository('ODRAdminBundle:RadioOptionsMeta')->findOneBy( array('radioOptions' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($radio_option_meta !== null) {
                $return['d'] = 'Metadata entry already exists for Radio Option '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $radio_option_meta = new RadioOptionsMeta();
                $radio_option_meta->setRadioOptions( $radio_option );

                $radio_option_meta->setOptionName( $radio_option->getOptionNameOriginal() );
                $radio_option_meta->setXmlOptionName( $radio_option->getXmlOptionNameOriginal() );
                $radio_option_meta->setDisplayOrder( $radio_option->getDisplayOrderOriginal() );
                $radio_option_meta->setIsDefault( $radio_option->getIsDefaultOriginal() );

                $radio_option_meta->setCreatedBy( $radio_option->getUpdatedBy() );
                $radio_option_meta->setCreated( $radio_option->getUpdated() );

                $radio_option_meta->setDeletedAt( $radio_option->getDeletedAt() );

                $em->persist($radio_option_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for Radio Option '.$object_id;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x212738622 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified file if needed
     *
     * @param Request $request
     */
    public function buildfilemetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted files too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the file entity exists...
            $file = $em->getRepository('ODRAdminBundle:File')->find($object_id);
            if ($file == null)
                throw new \Exception('File does not exist');

            // Attempt to locate a metadata entry for the provided entity
            $file_meta = $em->getRepository('ODRAdminBundle:FileMeta')->findOneBy( array('File' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($file_meta !== null) {
                $return['d'] = 'Metadata entry already exists for File '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $file_meta = new FileMeta();
                $file_meta->setFile( $file );

                $file_meta->setDescription( $file->getDescriptionOriginal() );
                $file_meta->setOriginalFileName( $file->getOriginalFileNameOriginal() );
                $file_meta->setExternalId( $file->getExternalIdOriginal() );
                $file_meta->setPublicDate( $file->getPublicDateOriginal() );

                $file_meta->setCreatedBy( $file->getUpdatedBy() );
                $file_meta->setCreated( $file->getUpdated() );

                $file_meta->setDeletedAt( $file->getDeletedAt() );

                $em->persist($file_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for File '.$object_id;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x271386622 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified image if needed
     *
     * @param Request $request
     */
    public function buildimagemetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted images too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the file entity exists...
            $image = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            if ($image == null)
                throw new \Exception('Image does not exist');

            // Attempt to locate a metadata entry for the provided entity
            $image_meta = $em->getRepository('ODRAdminBundle:ImageMeta')->findOneBy( array('Image' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($image_meta !== null) {
                $return['d'] = 'Metadata entry already exists for Image '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $image_meta = new ImageMeta();
                $image_meta->setImage( $image );

                $image_meta->setDisplayorder( $image->getDisplayorderOriginal() );
                $image_meta->setCaption( $image->getCaptionOriginal() );
                $image_meta->setOriginalFileName( $image->getOriginalFileNameOriginal() );
                $image_meta->setExternalId( $image->getExternalIdOriginal() );
                $image_meta->setPublicDate( $image->getPublicDateOriginal() );

                $image_meta->setCreatedBy( $image->getUpdatedBy() );
                $image_meta->setCreated( $image->getUpdated() );

                $image_meta->setDeletedAt( $image->getDeletedAt() );

                $em->persist($image_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for Image '.$object_id;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x273786222 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified datatree if needed
     *
     * @param Request $request
     */
    public function builddatatreemetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Want to create a metadata entry for deleted images too
            $em->getFilters()->disable('softdeleteable');

            // Ensure the file entity exists...
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->find($object_id);
            if ($datatree == null)
                throw new \Exception('DataTree does not exist');

            // Attempt to locate a metadata entry for the provided entity
            $datatree_meta = $em->getRepository('ODRAdminBundle:DataTreeMeta')->findOneBy( array('DataTree' => $object_id) );

            // No longer need softdeleteable filter disabled
            $em->getFilters()->enable('softdeleteable');

            if ($datatree_meta !== null) {
                $return['d'] = 'Metadata entry already exists for DataTree '.$object_id.', skipping';
            }
            else {
                // Create a new meta entry and populate from original entity
                $datatree_meta = new DataTreeMeta();
                $datatree_meta->setDataTree( $datatree );

                $datatree_meta->setIsLink( $datatree->getIsLinkOriginal() );
                $datatree_meta->setMultipleAllowed( $datatree->getMultipleAllowedOriginal() );

                $datatree_meta->setCreatedBy( $datatree->getCreatedBy() );
                $datatree_meta->setCreated( $datatree->getCreated() );

                $datatree_meta->setDeletedAt( $datatree->getDeletedAt() );

                $em->persist($datatree_meta);
                $em->flush();

                $return['d'] = 'Created new metadata entry for DataTree '.$object_id;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x3728566222 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified datafield if needed
     *
     * @param Request $request
     */
    public function builddatafieldmetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // TODO - stuff here
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2738672902 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified datarecord if needed
     *
     * @param Request $request
     */
    public function builddatarecordmetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // TODO - stuff here
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x239864522 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds metadata entry for a specified datatype if needed
     *
     * @param Request $request
     */
    public function builddatatypemetadataAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->getDoctrine()->getManager();

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // TODO - stuff here
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x738863422 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
