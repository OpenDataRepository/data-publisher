<?php

/**
* Open Data Repository Data Publisher
* Record Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The record handles everything required to edit any kind of
* data stored in a DataRecord.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entites
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\Boolean;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\IntegerValue;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Form\FormError;


class RecordController extends ODRCustomController
{

    /**
     * Handles selection changes made to SingleRadio, MultipleRadio, SingleSelect, and MultipleSelect DataFields
     * 
     * @param integer $data_record_field_id The database id of the DataRecord/DataField pair being modified.
     * @param integer $radio_option_id      The database id of the RadioOption entity being (de)selected.
     * @param integer $multiple             '1' if RadioOption allows multiple selections, '0' otherwise.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function radioselectionAction($data_record_field_id, $radio_option_id, $multiple, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields')->find($data_record_field_id);
            if ( $datarecordfield == null )
                return parent::deletedEntityError('DataRecordField');

            $datafield = $datarecordfield->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            $radio_selections = $em->getRepository('ODRAdminBundle:RadioSelection')->findBy( array('dataRecordFields' => $datarecordfield->getId()) );

            $radio_option = null;
            if ($radio_option_id != "0") {
                $repo_radio_options = $em->getRepository('ODRAdminBundle:RadioOptions');
                $radio_option = $repo_radio_options->find($radio_option_id);
                if ( $radio_option == null )
                    return parent::deletedEntityError('RadioOption');
            }

            // Go through all the radio selections
            $found = false;
            if ($radio_selections != null) {
                foreach ($radio_selections as $selection) {

                    // If the radio selection already exists
                    if ($radio_option_id == $selection->getRadioOption()->getId()) {
                        // Found the one that was selected
                        $found = true;
                        $selection->setUpdatedBy($user);

                        if ($multiple == "1") {
                            // Radio group permits multiple selections, toggle the selected option
                            if ($selection->getSelected() == 0)
                                $selection->setSelected(1);
                            else
                                $selection->setSelected(0);
                        }
                        else {
                            // Radio group only permits single selection, set to selected
                            $selection->setSelected(1);
                        }
                    }
                    else if ($multiple == "0") {  // if the radio group only permits a single selection
                        // Unselect the other options
                        $selection->setUpdatedBy($user);
                        $selection->setSelected(0);
                    }
                }
            }

            if (!$found && $radio_option_id != "0") {
                // Create a new radio selection
                $initial_value = 1;
                parent::ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield, $initial_value);
            }

            // Flush all changes
            $em->flush();

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Refresh the cache entries for this datarecord
            $datarecord = $datarecordfield->getDataRecord();
            parent::updateDatarecordCache($datarecord->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x18373679 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Creates a new DataRecord.
     * 
     * @param integer $datatype_id The database id of the DataType this DataRecord will belong to.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function addAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo 
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'add' ])) )
                return parent::permissionDeniedError("create new DataRecords for");
            // --------------------

            // TODO - ???
            // Get default form theme (theme_type = "form"
            $query = $em->createQuery(
                'SELECT t FROM ODRAdminBundle:Theme t WHERE t.isDefault = 1 AND t.templateType = :template_type'
                )->setParameter('template_type', 'form');

            $themes = $query->getResult();
            if(count($themes) > 1 || count($themes) == 0) {
                throw new \Exception("An invalid form theme was found.  Error: 0X82383992.");
            }
            $theme = $themes[0];


            // Create new Data Record
            $datarecord = parent::ODR_addDataRecord($em, $user, $datatype);

            $em->flush();
            $em->refresh($datarecord);

            // Top Level Record - must have grandparent and parent set to itself
            $parent = $repo_datarecord->find($datarecord->getId());
            $grandparent = $repo_datarecord->find($datarecord->getId());
            $datarecord->setGrandparent($grandparent);
            $datarecord->setParent($parent);

            // Datarecord is ready, remove provisioned flag
            $datarecord->setProvisioned(false);

            $em->persist($datarecord);
            $em->flush();
            $em->refresh($datarecord);

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'datarecord_id' => $datarecord->getId()
            );


            // ----------------------------------------
            // Build the cache entries for this new datarecord
            $options = array();
            parent::updateDatarecordCache($datarecord->getId(), $options);

            // Delete the cached string containing the ordered list of datarecords for this datatype
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $memcached->delete($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');

            // See if any cached search results need to be deleted...
            $cached_searches = $memcached->get($memcached_prefix.'.cached_search_results');
            if ( isset($cached_searches[$datatype_id]) ) {
                // Delete all cached search results for this datatype that were NOT run with datafield criteria
                foreach ($cached_searches[$datatype_id] as $search_checksum => $search_data) {
                    $searched_datafields = $search_data['searched_datafields'];
                    if ($searched_datafields == '')
                        unset( $cached_searches[$datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $memcached->set($memcached_prefix.'.cached_search_results', $cached_searches, 0);
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x29328834 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Creates a new DataRecord and sets it as a child of the given DataRecord.
     * 
     * @param integer $datatype_id    The database id of the child DataType this new child DataRecord will belong to.
     * @param integer $parent_id      The database id of the DataRecord...
     * @param integer $grandparent_id The database id of the top-level DataRecord in this inheritance chain.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function addchildrecordAction($datatype_id, $parent_id, $grandparent_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Entity Manager and setup repo 
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            // Grab needed Entities from the repository
            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            $parent = $repo_datarecord->find($parent_id);
            if ( $parent == null )
                return parent::deletedEntityError('DataRecord');

            $grandparent = $repo_datarecord->find($grandparent_id);
            if ( $grandparent == null )
                return parent::deletedEntityError('DataRecord');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'add' ])) )
                return parent::permissionDeniedError("add child DataRecords to");
            // --------------------

            // Create new Data Record
            $datarecord = parent::ODR_addDataRecord($em, $user, $datatype);

            $datarecord->setGrandparent($grandparent);
            $datarecord->setParent($parent);

            // Datarecord is ready, remove provisioned flag
            $datarecord->setProvisioned(false);

            $em->persist($datarecord);
            $em->flush();

            // Ensure the new child record has all its fields
            parent::verifyExistence($datarecord);


            // Get record_ajax.html.twig to re-render the datarecord
            $return['d'] = array(
                'new_datarecord_id' => $datarecord->getId(),
                'datatype_id' => $datatype_id,
                'parent_id' => $parent->getId(),
            );

            // Refresh the cache entries for this datarecord
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatarecordCache($grandparent->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x293288355555 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a DataRecord.
     * 
     * @param integer $datarecord_id The database id of the datarecord to delete.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function deleteAction($datarecord_id, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab memcached stuff
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_linked_data_tree = $em->getRepository('ODRAdminBundle:LinkedDataTree');

            // Grab the necessary entities
            $datarecord = $repo_datarecord->find($datarecord_id);
            if ($datarecord == null)
                return parent::deletedEntityError('Datarecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');
            $datatype_id = $datatype->getId();


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'delete' ])) )
                return parent::permissionDeniedError("delete DataRecords from");
            // --------------------


            // -----------------------------------
            // Delete DataRecordField entries for this datarecord
            // TODO - do this with a DQL update query?

//            $datarecordfields = $repo_datarecordfields->findBy( array('dataRecord' => $datarecord->getId()) );
//            foreach ($datarecordfields as $drf)
//                $em->remove($drf);
            $query = $em->createQuery(
               'SELECT drf.id AS drf_id
                FROM ODRAdminBundle:DataRecordFields drf
                WHERE drf.dataRecord = :datarecord'
            )->setParameters( array('datarecord' => $datarecord->getId()) );
            $results = $query->getResult();
            foreach ($results as $num => $data) {
                $drf_id = $data['drf_id'];
                $drf = $repo_datarecordfields->find($drf_id);
                $em->remove($drf);
            }

            // Build a list of all datarecords that need recaching as a result of this deletion
            $recache_list = array();

            // Locate and delete any LinkedDataTree entities so rendering doesn't crash
            $linked_data_trees = $repo_linked_data_tree->findBy( array('descendant' => $datarecord->getId()) );
            foreach ($linked_data_trees as $ldt) {
                // Need to recache the datarecord on the other side of the link
                $ancestor_id = $ldt->getAncestor()->getGrandparent()->getId();
                if ( !in_array($ancestor_id, $recache_list) )
                    $recache_list[] = $ancestor_id;

                $em->remove($ldt);
            }
            $linked_data_trees = $repo_linked_data_tree->findBy( array('ancestor' => $datarecord->getId()) );
            foreach ($linked_data_trees as $ldt) {
                // Need to recache the datarecord on the other side of the link
                $descendant_id = $ldt->getDescendant()->getGrandparent()->getId();
                if ( !in_array($descendant_id, $recache_list) )
                    $recache_list[] = $descendant_id;
 
                $em->remove($ldt);
            }

            // Delete the datarecord entity like the user wanted, in addition to all children of this datarecord so external_id doesn't grab them
            $datarecords = $repo_datarecord->findBy( array('grandparent' => $datarecord->getId()) );
            foreach ($datarecords as $dr) {
                $em->remove($dr);

                // TODO - links to/from child datarecord?
            }
            $em->flush();

            // Delete the list of DataRecords for this DataType that ShortResults uses to build its list
            $memcached->delete($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');


            // Schedule all datarecords that were connected to the now deleted datarecord for a recache
            foreach ($recache_list as $num => $dr_id) {
                parent::updateDatarecordCache($dr_id);
            }

            // ----------------------------------------
            // See if any cached search results need to be deleted...
            $cached_searches = $memcached->get($memcached_prefix.'.cached_search_results');
            if ( isset($cached_searches[$datatype_id]) ) {
                // Delete all cached search results for this datatype that contained this now-deleted datarecord
                foreach ($cached_searches[$datatype_id] as $search_checksum => $search_data) {

                    $datarecord_list = '';
                    if ( isset($search_data['logged_in']) )
                        $datarecord_list = $search_data['logged_in']['datarecord_list'];
                    else
                        $datarecord_list = $search_data['not_logged_in']['datarecord_list'];

                    $datarecord_list = explode(',', $datarecord_list);
                    if ( in_array($datarecord_id, $datarecord_list) )
                        unset ( $cached_searches[$datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $memcached->set($memcached_prefix.'.cached_search_results', $cached_searches, 0);
            }


            // ----------------------------------------
            // Determine how many datarecords of this datatype remain
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord dr
                WHERE dr.deletedAt IS NULL AND dr.dataType = :datatype'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $remaining = $query->getArrayResult();

            // Determine where to redirect since the current datareccord is now deleted
            $url = '';
            if ($search_key == '')
                $search_key = 'dt_id='.$datatype->getId();

            if ( count($remaining) > 0 ) {
                // Return to the list of datarecords since at least one datarecord of this datatype still exists
                $url = $this->generateURL('odr_search_render', array('search_key' => $search_key));
            }
            else {
                // ...otherwise, return to the list of datatypes
                $url = $this->generateURL('odr_list_types', array('section' => 'records'));
            }

            $return['d'] = $url;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2039183556 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a child DataRecord, and re-renders the DataRecord so the child disappears.
     * TODO - modify this so $datatype_id isn't needed?
     * 
     * @param integer $datarecord_id The database id of the datarecord being deleted
     * @param integer $datatype_id
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function deletechildrecordAction($datarecord_id, $datatype_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            // Grab the necessary entities
            $datarecord = $repo_datarecord->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'delete' ])) )
                return parent::permissionDeniedError("delete child DataRecords from");
            // --------------------

            // Grab the grandparent data record so GetDisplayData creates html for all the child records of this datatype
            $parent = $datarecord->getParent();
            $grandparent = $datarecord->getGrandparent();

            // Delete the datarecord entity like the user wanted
            $em->remove($datarecord);
            $em->flush();

            // Refresh the cache entries for the grandparent
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatarecordCache($grandparent->getId(), $options);

            // TODO - schedule recaches for other datarecords?

            // Get record_ajax.html.twig to rRe-render the datarecord
            $return['d'] = array(
                'datatype_id' => $datatype_id,
                'parent_id' => $parent->getId(),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x203288355556 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a user-uploaded file from the database.
     * TODO - delete from server as well?
     * 
     * @param integer $file_id The database id of the File to delete.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function deletefileAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_file = $em->getRepository('ODRAdminBundle:File');

            // Grab the necessary entities
            $file = $repo_file->find($file_id);
            if ( $file == null )
                return parent::deletedEntityError('File');

            $datafield = $file->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datarecord = $file->getDataRecord();
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("delete files from");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Delete the file entity like the user wanted
            $em->remove($file);
            $em->flush();

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);

            // If this datafield only allows a single upload, tell record_ajax.html.twig to refresh that datafield so the upload button shows up
            if ($datafield->getAllowMultipleUploads() == "0")
                $return['d'] = array('need_reload' => true);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x203288355556 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of a file.
     * 
     * @param integer $file_id The database id of the File to modify.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function publicfileAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_file = $em->getRepository('ODRAdminBundle:File');

            // Grab the necessary entities
            $file = $repo_file->find($file_id);
            if ( $file == null )
                return parent::deletedEntityError('File');

            $datafield = $file->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datarecord = $file->getDataRecord();
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // If the file is public, make it non-public...if file is non-public, make it public
            $public_date = $file->getPublicDate();
            if ( $file->isPublic() ) {
                // Make the record non-public
                $file->setPublicDate(new \DateTime('2200-01-01 00:00:00'));

                // Delete the decrypted version of the file, if it exists
                $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
                $filename = 'File_'.$file_id.'.'.$file->getExt();
                $absolute_path = realpath($file_upload_path).'/'.$filename;

//                if ( file_exists($absolute_path) )
//                    unlink($absolute_path);
            }
            else {
                // Make the record public
                $file->setPublicDate(new \DateTime());

                // Immediately decrypt the file
                parent::decryptObject($file->getId(), 'file');
            }

            $file->setUpdatedBy($user);
            $em->persist($file);
            $em->flush();

            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'datarecord' => $datarecord->getId(),
                'datafield' => $datafield->getId()
            );

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x203288355556 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of an image.
     * 
     * @param integer $image_id The database id of the Image to modify
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function publicimageAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the necessary entities
            $image = $repo_image->find($image_id);
            if ( $image == null )
                return parent::deletedEntityError('Image');

            $datafield = $image->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datarecord = $image->getDataRecord();
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Grab all children of the original image (resizes, i believe)
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            $images[] = $image;

            // If the images are public, make them non-public...if images are non-public, make them public
            $public_date = $image->getPublicDate();
            if ( $image->isPublic() ) {
                foreach ($images as $img) {
                    // Make the image non-public
                    $img->setPublicDate(new \DateTime('2200-01-01 00:00:00'));
                    $img->setUpdatedBy($user);
                    $em->persist($img);

                    // Delete the decrypted version of the image, if it exists
                    $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
                    $filename = 'Image_'.$img->getId().'.'.$img->getExt();
                    $absolute_path = realpath($image_upload_path).'/'.$filename;

//                    if ( file_exists($absolute_path) )
//                        unlink($absolute_path);
                }
            }
            else {
                foreach ($images as $img) {
                    // Make the image public
                    $img->setPublicDate(new \DateTime());
                    $img->setUpdatedBy($user);
                    $em->persist($img);

                    // Immediately decrypt the image
                    parent::decryptObject($img->getId(), 'image');
                }
            }

            $em->flush();


            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'datarecord' => $datarecord->getId(),
                'datafield' => $datafield->getId()
            );

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2038825456 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a user-uploaded image from the repository.
     * 
     * @param integer $image_id The database id of the Image to delete.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function deleteimageAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the necessary entities
            $image = $repo_image->find($image_id);
            if ( $image == null )
                return parent::deletedEntityError('Image');

            $datafield = $image->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datarecord = $image->getDataRecord();
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Grab all children of the original image (resizes, i believe) and remove them
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            foreach ($images as $img)
                $em->remove($img);
            // Remove the original image as well
            $em->remove($image);
            $em->flush();

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);

            // If this datafield only allows a single upload, tell record_ajax.html.twig to refresh that datafield so the upload button shows up
            if ($datafield->getAllowMultipleUploads() == "0")
                $return['d'] = array('need_reload' => true);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2078485256 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Modifies the display order of the images in an Image control.
     * 
     * @param Request $request 
     * 
     * @return Response TODO
     */
    public function saveimageorderAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $post = $_POST;
//print_r($post);
//return;
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the first image just to check permissions
            $image = null;
            foreach ($post as $index => $image_id) {
                $image = $repo_image->find($image_id);
                break;
            }

            $datafield = $image->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datarecord = $image->getDataRecord();
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // If user has permissions, go through all of the image thumbnails setting the order
            for($i = 0; $i < count($post); $i++) {
                $image = $repo_image->find( $post[$i] );
                $em->refresh($image);

                $image->setDisplayorder($i);
                $image->setUpdatedBy($user);

                $em->persist($image);
            }
            $em->flush();

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId());
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x822889302 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of a DataRecord.
     * 
     * @param integer $datarecord_id The database id of the DataRecord to modify.
     * @param Request $request 
     * 
     * @return Response TODO
     */
    public function publicdatarecordAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $datarecord = $repo_datarecord->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
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


            // Toggle the public status of the datarecord
            $public = 0;
            if ( $datarecord->isPublic() ) {
                // Make the record non-public
                $datarecord->setPublicDate(new \DateTime('2200-01-01 00:00:00'));
                $public = 0;
            }
            else {
                // Make the record public
                $datarecord->setPublicDate(new \DateTime());
                $public = 1;
            }

            // Save the change to this child datarecord
            $em->persist($datarecord);
            $em->flush();

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);

            // re-render?  wat
            $return['d'] = array(
                'datarecord_id' => $datarecord_id,
//                'datarecord_id' => $datarecord->getGrandparent()->getId(),
//                'datatype_id' => $datatype->getId(),
                'public' => $public,
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2028983556 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Parses a $_POST request to update the contents of a datafield.
     * File and Image uploads are handled by FlowController
     * 
     * @param string $record_type    Apparently, the typeclass of the datafield being modified.
     * @param integer $datarecord_id The database id of the datarecord being modified.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function updateAction($record_type, $datarecord_id, Request $request) 
    {
        // Save Data Record Entries
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get the Entity Manager
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $datarecord = $repo_datarecord->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            $datatype_id = $datatype->getId();
            $external_id_datafield = $datatype->getExternalIdField();
            $sort_datafield = $datatype->getSortField();
            $name_datafield = $datatype->getNameField();

            $datafield_id = $_POST[$record_type.'Form']['data_field'];
            $datafield = $repo_datafield->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield_id ]) && isset($datafield_permissions[ $datafield_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Need to reload the datafield on file/image update
            $need_datafield_reload = false;

            // Determine Form based on Type
            $form_classname = "\\ODR\\AdminBundle\\Form\\" . $record_type . 'Form';
            $obj_classname = "ODR\\AdminBundle\\Entity\\" . $record_type;

            $form = null;
            $my_obj = null;
            switch($record_type) {
                case 'DatetimeValue':
                case 'ShortVarchar':
                case 'MediumVarchar':
                case 'LongVarchar':
                case 'LongText':    // paragraph text
                case 'IntegerValue':
                case 'DecimalValue':
                case 'Boolean':
                    $my_obj = new $obj_classname();
                    $post = $_POST;
                    if ( isset($post['id']) && $post['id'] > 0 ) {
                        $repo = $em->getRepository('ODRAdminBundle:'.$record_type);
                        $my_obj = $repo->find($post['id']);
                    }
                break;
            }
            $form = $this->createForm(
                new $form_classname($em), 
                $my_obj
            );

//print_r($_POST);
//exit();

            // Grab the new value for the datafield
            $old_value = $new_value = '';
            if ( isset($_POST[$record_type.'Form']['value']) )
                $new_value = $_POST[$record_type.'Form']['value'];

            // Save the old value incase we have to revert
            $drf = $my_obj->getDataRecordFields();
             $tmp_obj = $drf->getAssociatedEntity();
            $old_value = $tmp_obj->getValue();

            if ($record_type == 'DatetimeValue')
                $old_value = $old_value->format('Y-m-d');


            // Only save if the new value is different from the old value
            if ($new_value !== $old_value && $request->getMethod() == 'POST') {
                $form->bind($request, $my_obj);

                // If the datafield is marked as unique...
                if ( $datafield->getIsUnique() == true ) {
                    // Mysql requires a different comparision if checking for duplicates of a null value...
                    $comparision = $parameters = null;
                    if ($new_value != null) {
                        $comparision = '= :value';
                        $parameters = array('datafield' => $datafield->getId(), 'value' => $new_value);
                    }
                    else {
                        $comparision = 'IS NULL';
                        $parameters = array('datafield' => $datafield->getId());
                    }

                    // Run a quick query to check whether the new value is a duplicate of an existing value 
                    $query = $em->createQuery(
                       'SELECT e.id
                        FROM ODRAdminBundle:'.$record_type.' AS e
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                        JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                        WHERE e.dataField = :datafield AND e.value '.$comparision.'
                        AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
                    )->setParameters( $parameters );
                    $results = $query->getArrayResult();

                    // If something got returned, add a Symfony error to the form so the subsequent isValid() call will fail
                    if ( count($results) > 0 )
                        $form->addError( new FormError('Another Datarecord already has the value "'.$new_value.'" stored in this Datafield...reverting back to old value.') );
                }

                // Ensure the form has no errors
                if ($form->isValid()) {
/*
                    // ----------------------------------------
                    // If the field that got modified is the name/sort/external_id field for this datatype, update this datarecord's cache values to match the new value
                    if ($name_datafield !== null && $name_datafield->getId() == $datafield->getId()) {
                        $datarecord->setNamefieldValue( $new_value );
                        $em->persist($datarecord);
                    }
                    if ($sort_datafield !== null && $sort_datafield->getId() == $datafield->getId()) {
                        $datarecord->setSortfieldValue( $new_value );
                        $em->persist($datarecord);

                        // Since a sort value got changed, also delete the default sorted list of datarecords for this datatype
                        $memcached->delete($memcached_prefix.'.data_type_'.$datatype_id.'_record_order');
                    }
                    if ($external_id_datafield !== null && $external_id_datafield->getId() == $datafield->getId()) {
                        $datarecord->setExternalId( $new_value );
                        $em->persist($datarecord);
                    }
*/
                    // Save changes
                    $em->persist($my_obj);
                    $em->flush();


                    // ----------------------------------------
                    // Determine whether ShortResults needs a recache
                    $options = array();
                    $options['mark_as_updated'] = true;
                    if ( parent::inShortResults($datafield) )
                        $options['force_shortresults_recache'] = true;
                    if ( $datafield->getDisplayOrder() != -1 )
                        $options['force_textresults_recache'] = true;

                    // Refresh the cache entries for this datarecord
                    parent::updateDatarecordCache($datarecord_id, $options);


                    // ----------------------------------------
                    // See if any cached search results need to be deleted...
                    $cached_searches = $memcached->get($memcached_prefix.'.cached_search_results');
                    if ( isset($cached_searches[$datatype_id]) ) {
                        // Delete all cached search results for this datatype that were run with criteria for this specific datafield
                        foreach ($cached_searches[$datatype_id] as $search_checksum => $search_data) {
                            $searched_datafields = $search_data['searched_datafields'];
                            $searched_datafields = explode(',', $searched_datafields);

                            if ( in_array($datafield_id, $searched_datafields) )
                                unset( $cached_searches[$datatype_id][$search_checksum] );
                        }

                        // Save the collection of cached searches back to memcached
                        $memcached->set($memcached_prefix.'.cached_search_results', $cached_searches, 0);
                    }
                }
                else {
                    // Form validation failed
                    // TODO - fix parent::ODR_getErrorMessages() to be consistent enough to use
                    $return['r'] = 2;
                    $return['old_value'] = $old_value;

                    $errors = $form->getErrors();
                    $error_str = '';
                    foreach ($errors as $num => $error)
                        $error_str .= 'ERROR: '.$error->getMessage()."\n";

                    $return['error'] = $error_str;
                }
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88320029 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Builds and returns a list of available 'descendant' datarecords to link to from this 'ancestor' datarecord.
     * If such a link exists, GetDisplayData() will render a read-only version of the 'remote' datarecord in a ThemeElement of the 'local' datarecord.
     * 
     * @param integer $ancestor_datatype_id   The database id of the DataType that is being linked from
     * @param integer $descendant_datatype_id The database id of the DataType that is being linked to
     * @param integer $local_datarecord_id    The database id of the DataRecord being modified.
     * @param string $search_key              The current search on this tab
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function getlinkablerecordsAction($ancestor_datatype_id, $descendant_datatype_id, $local_datarecord_id, $search_key, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo 
            $em = $this->getDoctrine()->getManager();
            $repo_linked_datatree = $em->getRepository('ODRAdminBundle:LinkedDataTree');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

            // Grab the datatypes from the database
            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ( $local_datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ( $ancestor_datatype == null )
                return parent::deletedEntityError('DataType');

            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ( $descendant_datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $ancestor_datatype->getId() ]) && isset($user_permissions[ $ancestor_datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

$debug = true;
$debug = false;
if ($debug) {
    print "local datarecord: ".$local_datarecord_id."\n";
    print "ancestor datatype: ".$ancestor_datatype_id."\n";
    print "descendant datatype: ".$descendant_datatype_id."\n";
}

            // ----------------------------------------
            // Determine which datatype we're trying to create a link with
            $local_datarecord_is_ancestor = false;
            $local_datatype = $local_datarecord->getDataType();
            $remote_datatype = null;
            if ($local_datatype->getId() == $ancestor_datatype_id) {
                $remote_datatype = $repo_datatype->find($descendant_datatype_id);   // Linking to a remote datarecord from this datarecord
                $local_datarecord_is_ancestor = true;
            }
            else {
                $remote_datatype = $repo_datatype->find($ancestor_datatype_id);     // Getting a remote datarecord to link to this datarecord
                $local_datarecord_is_ancestor = false;
            }

if ($debug)
    print "\nremote datatype: ".$remote_datatype->getId()."\n";


            // ----------------------------------------
            // Grab all datarecords currently linked to the local_datarecord
            $linked_datarecords = array();
            if ($local_datarecord_is_ancestor) {
                // local_datarecord is on the ancestor side of the link
                $query = $em->createQuery(
                   'SELECT descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor = :local_datarecord AND descendant.dataType = :remote_datatype AND descendant.provisioned = false
                    AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
                )->setParameters( array('local_datarecord' => $local_datarecord->getId(), 'remote_datatype' => $remote_datatype->getId()) );
                $results = $query->getResult();
                foreach ($results as $num => $data) {
                    $descendant_id = $data['descendant_id'];
                    if ( $descendant_id == null || trim($descendant_id) == '' )
                        continue;

                    $linked_datarecords[ $descendant_id ] = 1;
                }
            }
            else {
                // local_datarecord is on the descendant side of the link
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id
                    FROM ODRAdminBundle:DataRecord descendant
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                    WHERE descendant = :local_datarecord AND ancestor.dataType = :remote_datatype AND ancestor.provisioned = false
                    AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters( array('local_datarecord' => $local_datarecord->getId(), 'remote_datatype' => $remote_datatype->getId()) );
                $results = $query->getResult();
                foreach ($results as $num => $data) {
                    $ancestor_id = $data['ancestor_id'];
                    if ( $ancestor_id == null || trim($ancestor_id) == '' )
                        continue;

                    $linked_datarecords[ $ancestor_id ] = 1;
                }

            }

if ($debug) {
    print "\nlinked datarecords\n";
    foreach ($linked_datarecords as $id => $value)
        print '-- '.$id."\n";
}


            // ----------------------------------------
            // Determine whether the link allows multiples or not
            $allow_multiple_links = true;
            $query = $em->createQuery(
               'SELECT dt.multiple_allowed AS multiple_allowed
                FROM ODRAdminBundle:DataTree AS dt
                WHERE dt.ancestor = :ancestor AND dt.descendant = :descendant
                AND dt.deletedAt IS NULL'
            )->setParameters( array('ancestor' => $ancestor_datatype->getId(), 'descendant' => $descendant_datatype->getId()) );
            $result = $query->getArrayResult();

            // Save whether only allowed to link to a single datarecord at a time
            if ( $result[0]['multiple_allowed'] == 0 )
                $allow_multiple_links = false;

if ($debug) {
    if ($allow_multiple_links)
        print "\nallow multiple links: true\n";
    else
        print "\nallow multiple links: false\n";
}

            // ----------------------------------------
            // Determine which, if any, datarecords can't be linked to because doing so would violate the "multiple_allowed" rule
            $illegal_datarecords = array();
            if ($local_datarecord_is_ancestor) {
                /* do nothing...the javascript will force compliance with the "multiple_allowed" rule */
            }
            else if (!$allow_multiple_links) {
                // If linking from descendant side, and link is setup to only allow to linking to a single descendant...
                // ...then determine which datarecords on the ancestor side already have links to datarecords on the descendant side
                $query = $em->createQuery(
                   'SELECT ancestor.id
                    FROM ODRAdminBundle:DataRecord AS descendant
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                    WHERE descendant.dataType = :descendant_datatype AND ancestor.dataType = :ancestor_datatype
                    AND descendant.deletedAt IS NULL AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
                )->setParameters( array('descendant_datatype' => $descendant_datatype->getId(), 'ancestor_datatype' => $ancestor_datatype->getId()) );
                $results = $query->getArrayResult();
//print_r($results);
                foreach ($results as $num => $result) {
                    $dr_id = $result['id'];
                    $illegal_datarecords[$dr_id] = 1;
                }
            }

if ($debug) {
    print "\nillegal datarecords\n";
    foreach ($illegal_datarecords as $key => $id)
        print '-- datarecord '.$id."\n";
}

            // ----------------------------------------
            // Need memcached for this...
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $templating = $this->get('templating');
//            $theme = $em->getRepository('ODRAdminBundle:Theme')->find(2);
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find(4);   // TODO - need an offcial theme to indicate "textresults"

            // Convert the list of linked datarecords into a slightly different format so renderTextResultsList() can build it
            $datarecord_list = array();
            foreach ($linked_datarecords as $dr_id => $value)
                $datarecord_list[] = $dr_id;

            $table_html = parent::renderTextResultsList($datarecord_list, $remote_datatype, $request);
            $table_html = json_encode($table_html);
//print_r($table_html);

            // Grab the column names for the datatables plugin
            $column_data = parent::getDatatablesColumnNames($remote_datatype->getId());
            $column_names = $column_data['column_names'];
            $num_columns = $column_data['num_columns'];


            // Render the dialog box for this request
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Record:link_datarecord_form.html.twig',
                    array(
                        'search_key' => $search_key,

                        'local_datarecord' => $local_datarecord,
                        'local_datarecord_is_ancestor' => $local_datarecord_is_ancestor,
                        'ancestor_datatype' => $ancestor_datatype,
                        'descendant_datatype' => $descendant_datatype,

                        'allow_multiple_links' => $allow_multiple_links,
                        'linked_datarecords' => $linked_datarecords,
                        'illegal_datarecords' => $illegal_datarecords,

                        'count' => count($linked_datarecords),
                        'table_html' => $table_html,
                        'column_names' => $column_names,
                        'num_columns' => $num_columns,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x293428835555 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Parses a $_POST request to modify whether a 'local' datarecord is linked to a 'remote' datarecord.
     * If such a link exists, GetDisplayData() will render a read-only version of the 'remote' datarecord in a ThemeElement of the 'local' datarecord.
     * 
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function linkrecordAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab the data from the POST request 
            $post = $_POST;
//print_r($post);
//return;

            $local_datarecord_id = $post['local_datarecord_id'];
            $ancestor_datatype_id = $post['ancestor_datatype_id'];
            $descendant_datatype_id = $post['descendant_datatype_id'];
            $allow_multiple_links = $post['allow_multiple_links'];
            $datarecords = array();
            if ( isset($post['datarecords']) )
                $datarecords = $post['datarecords'];


            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();

            $repo_linked_datatree = $em->getRepository('ODRAdminBundle:LinkedDataTree');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ( $local_datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $local_datatype = $local_datarecord->getDataType();
            if ( $local_datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $local_datatype->getId() ]) && isset($user_permissions[ $local_datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab the datatypes from the database
            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ( $ancestor_datatype == null )
                return parent::deletedEntityError('DataType');

            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ( $descendant_datatype == null )
                return parent::deletedEntityError('DataType');


            $linked_datatree = null;
            $local_datarecord_is_ancestor = true;
            if ($local_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
                $local_datarecord_is_ancestor = false;
            }

            // Grab records currently linked to the local_datarecord
            $remote = 'ancestor';
            if (!$local_datarecord_is_ancestor)
                $remote = 'descendant';

            $query = $em->createQuery(
               'SELECT ldt
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                WHERE ldt.'.$remote.' = :datarecord
                AND ldt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $local_datarecord->getId()) );
            $results = $query->getResult();

            $linked_datatree = array();
            foreach ($results as $num => $ldt)
                $linked_datatree[] = $ldt;

$debug = true;
$debug = false;
if ($debug) {
    print_r($datarecords);
    print "\nlocal datarecord: ".$local_datarecord_id."\n";
    print "ancestor datatype: ".$ancestor_datatype_id."\n";
    print "descendant datatype: ".$descendant_datatype_id."\n";
    if ($local_datarecord_is_ancestor)
        print "local datarecord is ancestor\n";
    else
        print "local datarecord is descendant\n";
}

if ($debug) {
    print "\nlinked datatree\n";
    foreach ($linked_datatree as $ldt)
        print "-- ldt ".$ldt->getId().' ancestor: '.$ldt->getAncestor()->getId().' descendant: '.$ldt->getDescendant()->getId()."\n";
}

            foreach ($linked_datatree as $ldt) {
                $remote_datarecord = null;
                if ($local_datarecord_is_ancestor)
                    $remote_datarecord = $ldt->getDescendant();
                else
                    $remote_datarecord = $ldt->getAncestor();

                // Ensure that this descendant datarecord is of the same datatype that's being modified...don't want to delete links to datarecords of another datatype
                if ($local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $descendant_datatype->getId()) {
if ($debug)
    print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match descendant datatype\n";
                    continue;
                }
                else if (!$local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
if ($debug)
    print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match ancestor datatype\n";
                    continue;
                }

                // If a descendant datarecord isn't listed in $datarecords, it got unlinked
                if ( !isset($datarecords[$remote_datarecord->getId()]) ) {
if ($debug)
    print 'removing link between ancestor datarecord '.$ldt->getAncestor()->getId().' and descendant datarecord '.$ldt->getDescendant()->getId()."\n";

                    // Remove the linked_data_tree entry
                    $em->remove($ldt);
                }
                else {
                    // Otherwise, a datarecord was linked and still is linked...
                    unset( $datarecords[$remote_datarecord->getId()] );
if ($debug)
    print 'link between local datarecord '.$local_datarecord->getId().' and remote datarecord '.$remote_datarecord->getId()." already exists\n";
                }
            }

            // Anything remaining in $datarecords is a newly linked datarecord
            foreach ($datarecords as $id => $num) {
                $remote_datarecord = $repo_datarecord->find($id);

                // Attempt to find a link between these two datarecords that was deleted at some point in the past
                $ancestor_datarecord = null;
                $descendant_datarecord = null;
                if ($local_datarecord_is_ancestor) {
                    $ancestor_datarecord = $local_datarecord;
                    $descendant_datarecord = $remote_datarecord;
if ($debug)
    print 'ensuring link from local datarecord '.$local_datarecord->getId().' to remote datarecord '.$remote_datarecord->getId()."\n";
                }
                else {
                    $ancestor_datarecord = $remote_datarecord;
                    $descendant_datarecord = $local_datarecord;
if ($debug)
    print 'ensuring link from remote datarecord '.$remote_datarecord->getId().' to local datarecord '.$local_datarecord->getId()."\n";
                }

                // Ensure there is a link between the two datarecords
                parent::ODR_linkDataRecords($em, $user, $ancestor_datarecord, $descendant_datarecord);
            }

            $em->flush();

            $return['d'] = array(
                'datatype_id' => $descendant_datatype->getId(),
                'datarecord_id' => $local_datarecord->getId()
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x832812835 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
    * Given a child datatype id and a datarecord, re-render and return the html for that child datatype.
    * 
    * @param integer $datarecord_id The database id of the parent DataRecord
    * @param integer $datatype_id   The database id of the child DataType to re-render
    * @param Request $request
    * 
    * @return Response TODO
    */
    public function reloadchildAction($datatype_id, $datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Don't actually need a search_key for a child reload, but GetDisplayData() expects the parameter
            $search_key = '';

            $return['d'] = array(
                'datarecord_id' => $datarecord_id,
                'html' => self::GetDisplayData($request, $datarecord_id, $search_key, 'child', $datatype_id),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x833871285 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
    * Given a datarecord and datafield, re-render and return the html for that datafield.
    * 
    * @param integer $datafield_id  The database id of the DataField inside the DataRecord to re-render.
    * @param integer $datarecord_id The database id of the DataRecord to re-render
    * @param Request $request
    *  
    * @return Response TODO
    */  
    public function reloaddatafieldAction($datafield_id, $datarecord_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $theme_datafield = $datafield->getThemeDataField();
            foreach ($theme_datafield as $tdf) {
                if ($tdf->getTheme()->getId() == 1) {
                    $theme_datafield = $tdf;
                    break;
                }
            }

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
//            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            // --------------------

            $datatype = $datafield->getDataType();
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array('dataRecord' => $datarecord_id, 'dataField' => $datafield_id) );
            $form = parent::buildForm($em, $user, $datarecord, $datafield, $datarecordfield, false, 0);

            $templating = $this->get('templating');
            $html = $templating->render(
                'ODRAdminBundle:Record:record_datafield.html.twig',
                array(
                    'mytheme' => $theme_datafield,
                    'field' => $datafield,
                    'datatype' => $datatype,
                    'datarecord' => $datarecord,
                    'datarecordfield' => $datarecordfield,
                    'form' => $form,
                )
            );

            $return['d'] = array('html' => $html);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x833871285 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders the HTML required to edit datafield values for a given record.
     *
     * If $template_name == 'child', $datarecord_id is the id of the parent datarecord and $child_datatype_id is the id of the child datatype
     *
     * @param Request $request
     * @param integer $datarecord_id
     * @param string $search_key
     * @param string $template_name
     * @param integer $child_datatype_id
     *
     * @return string
     */
    private function GetDisplayData(Request $request, $datarecord_id, $search_key, $template_name = 'default', $child_datatype_id = null)
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);

        // --------------------
        // Determine user privileges
        $user = $this->container->get('security.context')->getToken()->getUser();
        $datatype_permissions = parent::getPermissionsArray($user->getId(), $request);
        $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);
        // --------------------

        $parent_datarecord = null;
        $datarecord = null;
        $datatype = null;
        $theme_element = null;

        if ( $template_name === 'child' && $child_datatype_id !== null ) {
            $datarecord = $repo_datarecord->find($datarecord_id);
            $datatype = $repo_datatype->find($child_datatype_id);
            $parent_datarecord = $datarecord->getParent();
        }
        else {
            $datarecord = $repo_datarecord->find($datarecord_id);
            $parent_datarecord = $datarecord;
            $datatype = $datarecord->getDataType();
        }

        $datarecords = array($datarecord);

        $indent = 0;
        $is_link = 0;
        $top_level = 1;
        $short_form = false;
        $use_render_plugins = false;
        $public_only = false;

        if ($template_name == 'child') {
            // Determine if this is a 'child' render request for a top-level datatype
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id
                FROM ODRAdminBundle:DataTree AS dt
                WHERE dt.deletedAt IS NULL AND dt.descendant = :datatype'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $results = $query->getArrayResult();

            // If query found something, then it's not a top-level datatype
            if ( count($results) > 0 )
                $top_level = 0;

            // Since this is a child reload, need to grab all child/linked datarecords that belong in this childtype
            // TODO - determine whether this will end up grabbing child datarecords or linked datarecords?  only one of these will return results, and figuring out which one to run would require a second query anyways...
            $datarecords = array();
            $query = $em->createQuery(
               'SELECT dr
                FROM ODRAdminBundle:DataRecord dr
                JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
                WHERE dr.parent = :datarecord AND dr.id != :datarecord_id AND dr.dataType = :datatype AND dr.provisioned = false
                AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $datarecord->getId(), 'datarecord_id' => $datarecord->getId(), 'datatype' => $datatype->getId()) );
            $results = $query->getResult();
            foreach ($results as $num => $child_datarecord)
                $datarecords[] = $child_datarecord;

            // ...do the same for any datarecords that this datarecord links to
            $query = $em->createQuery(
               'SELECT descendant
                FROM ODRAdminBundle:LinkedDataTree ldt
                JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                JOIN ODRAdminBundle:DataType AS dt WITH descendant.dataType = dt
                WHERE ldt.ancestor = :datarecord AND descendant.dataType = :datatype AND descendant.provisioned = false
                AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $datarecord->getId(), 'datatype' => $datatype->getId()) );
            $results = $query->getResult();
            foreach ($results as $num => $linked_datarecord)
                $datarecords[] = $linked_datarecord;

        }

$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

$start = microtime(true);
if ($debug)
    print "\n>> starting timing...\n\n";

        // Construct the arrays which contain all the required data
        $datatype_tree = parent::buildDatatypeTree($user, $theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);
if ($debug)
    print "\n>> datatype_tree done in: ".(microtime(true) - $start)."\n\n";

        $datarecord_tree = array();
        foreach ($datarecords as $datarecord) {
            $datarecord_tree[] = parent::buildDatarecordTree($datarecord, $em, $user, $short_form, $use_render_plugins, $public_only, $debug, $indent);

if ($debug)
    print "\n>> datarecord_tree for datarecord ".$datarecord->getId()." done in: ".(microtime(true) - $start)."\n\n";

        }

if ($debug)
    print '</pre>';

        // Determine which template to use for rendering
        $template = 'ODRAdminBundle:Record:record_ajax.html.twig';
        if ($template_name == 'child')
            $template = 'ODRAdminBundle:Record:record_area_child_load.html.twig';

        // Determine what datatypes link to this datatype
        $ancestor_linked_datatypes = array();
        if ($template_name == 'default') {
            $query = $em->createQuery(
               'SELECT ancestor.id AS ancestor_id, ancestor.shortName AS ancestor_name
                FROM ODRAdminBundle:DataTree dt
                JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
                WHERE dt.is_link = 1 AND dt.descendant = :datatype
                AND dt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $results = $query->getArrayResult();
            foreach ($results as $num => $result) {
                $id = $result['ancestor_id'];
                $name = $result['ancestor_name'];
                $ancestor_linked_datatypes[$id] = $name;
            }
        }

        // Determine what datatypes link to this datatype
        $descendant_linked_datatypes = array();
        if ($template_name == 'default') {
            $query = $em->createQuery(
               'SELECT descendant.id AS descendant_id, descendant.shortName AS descendant_name
                FROM ODRAdminBundle:DataTree dt
                JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
                WHERE dt.is_link = 1 AND dt.ancestor = :datatype
                AND dt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $results = $query->getArrayResult();
            foreach ($results as $num => $result) {
                $id = $result['descendant_id'];
                $name = $result['descendant_name'];
                $descendant_linked_datatypes[$id] = $name;
            }
        }


        // Render the DataRecord
        $templating = $this->get('templating');
        $html = $templating->render(
            $template,
            array(
                'search_key' => $search_key,

                'parent_datarecord' => $parent_datarecord,

                'datatype_tree' => $datatype_tree,
                'datarecord_tree' => $datarecord_tree,
                'theme' => $theme,
                'datatype_permissions' => $datatype_permissions,
                'datafield_permissions' => $datafield_permissions,
                'ancestor_linked_datatypes' => $ancestor_linked_datatypes,
                'descendant_linked_datatypes' => $descendant_linked_datatypes,
            )
        );

        return $html;
    }


    /**
     * Renders the edit form for a DataRecord if the user has the requisite permissions.
     * 
     * @param integer $datarecord_id The database id of the DataRecord the user wants to edit
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function editAction($datarecord_id, $search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get necessary objects
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();

            $em = $this->getDoctrine()->getManager();
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');

            // Get Default Theme
            $theme = $repo_theme->find(1);  // TODO - default theme

            // Get Record In Question
            $datarecord = $repo_datarecord->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('Datarecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');
            $datatype_id = $datatype->getId();

            // TODO - not accurate, technically...
            if ($datarecord->getProvisioned() == true)
                return parent::permissionDeniedError();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $logged_in = true;

            // Ensure user has permissions to be doing this
            if ( !( isset($user_permissions[$datatype_id]) && ( isset($user_permissions[$datatype_id]['edit']) || isset($user_permissions[$datatype_id]['child_edit']) ) ) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Ensure all objects exist before rendering
            parent::verifyExistence($datarecord);


            // ----------------------------------------
            // If this datarecord is being viewed from a search result list, attempt to grab the list of datarecords from that search result
            $datarecord_list = '';
            $encoded_search_key = '';
            if ($search_key !== '') {
                //
                $data = parent::getSavedSearch($datatype_id, $search_key, $logged_in, $request);
                $encoded_search_key = $data['encoded_search_key'];
                $datarecord_list = $data['datarecord_list'];

                // If the user is attempting to view a datarecord from a search that returned no results...
                if ($encoded_search_key !== '' && $datarecord_list === '') {
                    // ...get the search controller to redirect to "no results found" page
                    $search_controller = $this->get('odr_search_controller', $request);
                    return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
                }
            }


            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];

            // Locate a sorted list of datarecords for search_header.html.twig if possible
            if ( $session->has('stored_tab_data') && $odr_tab_id !== '' ) {
                // Prefer the use of the sorted lists created during usage of the datatables plugin over the default list created during searching
                $stored_tab_data = $session->get('stored_tab_data');

                if ( isset($stored_tab_data[$odr_tab_id]) ) {
                    // Grab datarecord list if it exists
                    if ( isset($stored_tab_data[$odr_tab_id]['datarecord_list']) )
                        $datarecord_list = $stored_tab_data[$odr_tab_id]['datarecord_list'];

                    // Grab start/length from the datatables state object if it exists
                    if ( isset($stored_tab_data[$odr_tab_id]['state']) ) {
                        $start = intval($stored_tab_data[$odr_tab_id]['state']['start']);
                        $length = intval($stored_tab_data[$odr_tab_id]['state']['length']);

                        // Calculate which page datatables claims it's on
                        $datatables_page = 0;
                        if ($start > 0)
                            $datatables_page = $start / $length;
                        $datatables_page++; 

                        // If the offset doesn't match the page, update it
                        if ( $offset !== '' && intval($offset) !== intval($datatables_page) ) {
                            $new_start = strval( (intval($offset) - 1) * $length );

                            $stored_tab_data[$odr_tab_id]['state']['start'] = $new_start;
                            $session->set('stored_tab_data', $stored_tab_data);
                        }
                    }
                }
            }


            // ----------------------------------------
            // Build an array of values to use for navigating the search result list, if it exists
            $search_header = parent::getSearchHeaderValues($datarecord_list, $datarecord->getId(), $request);

            $router = $this->get('router');
            $templating = $this->get('templating');

            $redirect_path = $router->generate('odr_record_edit', array('datarecord_id' => 0));    // blank path
            $record_header_html = $templating->render(
                'ODRAdminBundle:Record:record_header.html.twig',
                array(
                    'datatype_permissions' => $user_permissions,
                    'datarecord' => $datarecord,
                    'datatype' => $datatype,

                    // values used by search_header.html.twig 
                    'search_key' => $encoded_search_key,
                    'offset' => $offset,
                    'page_length' => $search_header['page_length'],
                    'next_datarecord' => $search_header['next_datarecord'],
                    'prev_datarecord' => $search_header['prev_datarecord'],
                    'search_result_current' => $search_header['search_result_current'],
                    'search_result_count' => $search_header['search_result_count'],
                    'redirect_path' => $redirect_path,
                )
            );


            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $record_header_html.self::GetDisplayData($request, $datarecord->getId(), $encoded_search_key, 'default'),
            );

            // Store which datarecord this is in the session so 
            $session = $request->getSession();
            $session->set('scroll_target', $datarecord->getId());
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x435858435 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
    * Builds an array of all prior values of the given datafield, to serve as a both display of field history and a reversion dialog.
    * 
    * @param DataRecordFields $datarecordfield_id The database id of the DataRecord/DataField pair to look-up in the transaction log
    * @param mixed $entity_id                     The database id of the storage entity to look-up in the transaction log
    * @param Request $request 
    * 
    * @return Response TODO
    */
    public function getfieldhistoryAction($datarecordfield_id, $entity_id, Request $request) {
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure user has permissions to be doing this
            $user = $this->container->get('security.context')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN')) {
                $return['r'] = 2;
            }
            else {
                // Get Entity Manager and setup repositories
                $em = $this->getDoctrine()->getManager();
                $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
                $drf = $repo_datarecordfields->find($datarecordfield_id);

                $type_class = $drf->getDataField()->getFieldType()->getTypeClass();
//print $type_class."\n";
                $repo_entity = $em->getRepository("ODR\\AdminBundle\\Entity\\".$type_class);
                $entity = $repo_entity->find($entity_id);

                // Get all log entries in array format for this entity from gedmo
                $all_log_entries = array();
                $repo_logging = $em->getRepository('Gedmo\Loggable\Entity\LogEntry');
                $all_log_entries = $repo_logging->getLogEntriesQuery($entity)->getArrayResult();
//print_r($all_log_entries);
//return;

                $user_manager = $this->container->get('fos_user.user_manager');
                $all_users = $user_manager->findUsers();
                $users = array();
                foreach ($all_users as $user) {
                    $users[ $user->getUsername() ] = $user;
                }


                $log_entries = array();
                foreach ($all_log_entries as $entry) {
                    $data = $entry['data'];
//print_r($data);
//return;

                    // Due to log entries not being identical, need to create a new array so the templating doesn't get confused
                    $tmp = array();
                    $tmp['id'] = $entry['id'];
                    $tmp['version'] = $entry['version'];
                    $tmp['loggedat'] = $entry['loggedAt'];

                    $username = $entry['username'];
                    if ( isset($users[$username]) )
                        $tmp['user'] = $users[$username];
                    else
                        $tmp['user'] = '';

                    if ( $type_class == 'DatetimeValue' ) {
                        // Null values in the log entries screw up the datetime to string formatter
                        if ( $data['value'] != null )
                            $tmp['value'] = $data['value']->format('Y-m-d');
                        else
                            $tmp['value'] = '';
                    }
                    else if ( isset($data['value']) ) {
                        // Otherwise, just store the value
                        $tmp['value'] = $data['value'];
                    }
                    else {
                        // Don't bother if there's no value listed...
                        continue;
                    }

                    $log_entries[] = $tmp;
                }
//print "--------------------\n";
//print_r($log_entries);
//return;

                $form_classname = "\\ODR\\AdminBundle\\Form\\".$type_class."Form";
//                $datarecordfield = $repo_datarecordfields->find($datarecordfield_id);

                // Get related object using switch
                $ignore_request = false;
                $form = null;
                switch($type_class) {
                    case 'File':
                    case 'Image':
                    case 'Radio':
                        $ignore_request = true;
                        break;
                    default:
//                        $my_obj = parent::loadFromDataRecordField($datarecordfield, $field_type);
                        $my_obj = $drf->getAssociatedEntity();
                        $form = $this->createForm(new $form_classname($em), $my_obj);
                    break;
                }

                // Render the dialog box for this request
                if (!$ignore_request) {
                    $templating = $this->get('templating');
                    $return['d'] = array(
                        'html' => $templating->render(
                            'ODRAdminBundle:Record:field_history_dialog_form.html.twig',
                            array(
                                'log_entries' => $log_entries,
                                'record_type' => $type_class,
                                'data_record_field_id' => $drf->getId(),
                                'datarecord_id' => $drf->getDataRecord()->getId(),
                                'entity_id' => $entity_id,
                                'form' => $form->createView()
                            )
                        )
                    );
                }
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x29534288935 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
