<?php

/**
* Open Data Repository Data Publisher
* Record Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This controller handles everything required to edit any kind of
* data stored in a DataRecord.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\FormError;


class RecordController extends ODRCustomController
{
    /**
     * Creates a new DataRecord.
     * 
     * @param integer $datatype_id The database id of the DataType this DataRecord will belong to.
     * @param Request $request
     * 
     * @return Response
     */
    public function adddatarecordAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'add' ])) )
                return parent::permissionDeniedError("create new DataRecords for");
            // --------------------


            // Create new Data Record
            $datarecord = parent::ODR_addDataRecord($em, $user, $datatype);

            $em->flush();
            $em->refresh($datarecord);

            // Top Level Record - must have grandparent and parent set to itself
            /** @var DataRecord $parent */
            $parent = $repo_datarecord->find($datarecord->getId());
            /** @var DataRecord $grandparent */
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
            if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
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
     * @return Response
     */
    public function addchildrecordAction($datatype_id, $parent_id, $grandparent_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            // Grab needed Entities from the repository
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            /** @var DataRecord $parent */
            $parent = $repo_datarecord->find($parent_id);
            if ( $parent == null )
                return parent::deletedEntityError('DataRecord');

            /** @var DataRecord $grandparent */
            $grandparent = $repo_datarecord->find($grandparent_id);
            if ( $grandparent == null )
                return parent::deletedEntityError('DataRecord');


            // --------------------
            // Determine user privileges
            /** @var User $user */
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
            $options = array('mark_as_updated' => true);
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
     * @return Response
     */
    public function deletedatarecordAction($datarecord_id, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new \Exception('DISABLED PENDING SECOND HALF OF THEME REWORK');

            // Grab memcached stuff
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_linked_data_tree = $em->getRepository('ODRAdminBundle:LinkedDataTree');

            // Grab the necessary entities
            /** @var DataRecord $datarecord */
            $datarecord = $repo_datarecord->find($datarecord_id);
            if ($datarecord == null)
                return parent::deletedEntityError('Datarecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');
            $datatype_id = $datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'delete' ])) )
                return parent::permissionDeniedError("delete DataRecords from");
            // --------------------


            // TODO - move most of this to beanstalk...leave deleting actual datarecord, its children, and wiping cache entries here


            // -----------------------------------
            // Delete DataRecordField entries for this datarecord
            $entities_to_remove = array();

            $query = $em->createQuery(
               'SELECT drf.id AS drf_id
                FROM ODRAdminBundle:DataRecordFields AS drf
                WHERE drf.dataRecord = :datarecord'
            )->setParameters( array('datarecord' => $datarecord->getId()) );
            $results = $query->getResult();

            foreach ($results as $num => $data) {
                $drf_id = $data['drf_id'];
                /** @var DataRecordFields $drf */
                $drf = $repo_datarecordfields->find($drf_id);
                $entities_to_remove[] = $drf;
            }

            // Build a list of all datarecords that need recaching as a result of this deletion
            $recache_list = array();

            // Locate and delete any LinkedDataTree entities so rendering doesn't crash
            /** @var LinkedDataTree[] $linked_data_trees */
            $linked_data_trees = $repo_linked_data_tree->findBy( array('descendant' => $datarecord->getId()) );
            foreach ($linked_data_trees as $ldt) {
                // Need to recache the datarecord on the other side of the link
                $ancestor_id = $ldt->getAncestor()->getGrandparent()->getId();
                if ( !in_array($ancestor_id, $recache_list) )
                    $recache_list[] = $ancestor_id;

                $ldt->setDeletedBy($user);
                $em->persist($ldt);

                $entities_to_remove[] = $ldt;
            }

            /** @var LinkedDataTree[] $linked_data_trees */
            $linked_data_trees = $repo_linked_data_tree->findBy( array('ancestor' => $datarecord->getId()) );
            foreach ($linked_data_trees as $ldt) {
                // Need to recache the datarecord on the other side of the link
                $descendant_id = $ldt->getDescendant()->getGrandparent()->getId();
                if ( !in_array($descendant_id, $recache_list) )
                    $recache_list[] = $descendant_id;

                $ldt->setDeletedBy($user);
                $em->persist($ldt);

                $entities_to_remove[] = $ldt;
            }

            // Delete the datarecord entity like the user wanted, in addition to all children of this datarecord so external_id doesn't grab them
            /** @var DataRecord[] $datarecords */
            $datarecords = $repo_datarecord->findBy( array('grandparent' => $datarecord->getId()) );
            foreach ($datarecords as $dr) {
                $dr_meta = $dr->getDataRecordMeta();

                $entities_to_remove[] = $dr_meta;
                $entities_to_remove[] = $dr;

                $dr->setDeletedBy($user);
                $em->persist($dr);
                // TODO - links to/from child datarecord?
            }

            // Save who is doing the deletions for all the entities, then remove all of them
            $em->flush();
            foreach ($entities_to_remove as $entity)
                $em->remove($entity);

            $em->flush();

            // Delete the list of DataRecords for this DataType that ShortResults uses to build its list
            $memcached->delete($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');


            // Schedule all datarecords that were connected to the now deleted datarecord for a recache
            $options = array();
            foreach ($recache_list as $num => $dr_id)
                parent::updateDatarecordCache($dr_id, $options);

            // ----------------------------------------
            // See if any cached search results need to be deleted...
            $cached_searches = $memcached->get($memcached_prefix.'.cached_search_results');
            if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
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
                FROM ODRAdminBundle:DataRecord AS dr
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
     * @return Response
     */
    public function deletechildrecordAction($datarecord_id, $datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Grab the necessary entities
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');
            $datatype = $datarecord->getDataType();

            // --------------------
            // Determine user privileges
            /** @var User $user */
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
            $datarecord->setDeletedBy($user);
            $em->persist($datarecord);
            $em->flush();
            $em->refresh($datarecord);

            $datarecord_meta = $datarecord->getDataRecordMeta();
            $em->remove($datarecord_meta);
            $em->remove($datarecord);
            $em->flush();

            // Refresh the cache entries for the grandparent
            $options = array('mark_as_updated' => true);
            parent::updateDatarecordCache($grandparent->getId(), $options);

            // TODO - schedule recaches for other datarecords?

            // Get record_ajax.html.twig to re-render the datarecord
            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
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
     *
     * @param integer $file_id The database id of the File to delete.
     * @param Request $request
     * 
     * @return Response
     */
    public function deletefileAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Grab the necessary entities
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
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

            // Files that aren't done encrypting shouldn't be modified
            if ($file->getOriginalChecksum() == '')
                return parent::deletedEntityError('File');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("delete files from");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Delete the decrypted version of this file from the server, if it exists
            $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
            $filename = 'File_'.$file_id.'.'.$file->getExt();
            $absolute_path = realpath($file_upload_path).'/'.$filename;

            if ( file_exists($absolute_path) )
                unlink($absolute_path);

            // Save who deleted the file
            $file->setDeletedBy($user);
            $em->persist($file);
            $em->flush($file);

            // Delete the file and its current metadata entry
            $file_meta = $file->getFileMeta();
            $em->remove($file);
            $em->remove($file_meta);
            $em->flush();


            // Determine whether ShortResults needs a recache
            // TODO - replace this block with code to directly update the cached version of the datarecord
            // TODO - execute graph plugin if needed
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
     * @return Response
     */
    public function publicfileAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Grab the necessary entities
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
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

            // Files that aren't done encrypting shouldn't be modified
            if ($file->getOriginalChecksum() == '')
                return parent::deletedEntityError('File');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Toggle public status of specified file...
            $public_date = null;
            if ( $file->isPublic() ) {
                // Make the file non-public
                $public_date = new \DateTime('2200-01-01 00:00:00');

                $properties = array('publicDate' => $public_date);
                parent::ODR_copyFileMeta($em, $user, $file, $properties);

                // Delete the decrypted version of the file, if it exists
                $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
                $filename = 'File_'.$file_id.'.'.$file->getExt();
                $absolute_path = realpath($file_upload_path).'/'.$filename;

                if ( file_exists($absolute_path) )
                    unlink($absolute_path);
            }
            else {
                // Make the file public
                $public_date = new \DateTime();

                $properties = array('publicDate' => $public_date);
                parent::ODR_copyFileMeta($em, $user, $file, $properties);

                // Immediately decrypt the file
                parent::decryptObject($file->getId(), 'file');
            }

            // Reload the file entity so its associated meta entry gets updated in the EntityManager
            $em->refresh($file);

            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'is_public' => $file->isPublic(),
                'public_date' => $public_date->format('Y-m-d'),
            );


            // Determine whether ShortResults needs a recache
            // TODO - replace this block with code to directly update the cached version of the datarecord
            // TODO - execute graph plugin if needed
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
     * @return Response
     */
    public function publicimageAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the necessary entities
            /** @var Image $image */
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

            // Images that aren't done encrypting shouldn't be downloaded
            if ($image->getOriginalChecksum() == '')
                return parent::deletedEntityError('Image');

            // --------------------
            // Determine user privileges
            /** @var User $user */
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
            /** @var Image[] $all_images */
            $all_images = $repo_image->findBy( array('parent' => $image->getId()) );
            $all_images[] = $image;

            // Toggle public status of specified image...
            if ( $image->isPublic() ) {
                // Make the original image non-public
                $properties = array('publicDate' => new \DateTime('2200-01-01 00:00:00'));
                parent::ODR_copyImageMeta($em, $user, $image, $properties);

                // Delete the decrypted version of the image and all of its children, if any of them exist
                foreach ($all_images as $img) {
                    $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
                    $filename = 'Image_'.$img->getId().'.'.$img->getExt();
                    $absolute_path = realpath($image_upload_path).'/'.$filename;

                    if ( file_exists($absolute_path) )
                        unlink($absolute_path);
                }
            }
            else {
                // Make the original image public
                $properties = array('publicDate' => new \DateTime());
                parent::ODR_copyImageMeta($em, $user, $image, $properties);

                // Immediately decrypt the image and all of its children
                foreach ($all_images as $img)
                    parent::decryptObject($img->getId(), 'image');
            }


            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'datarecord' => $datarecord->getId(),
                'datafield' => $datafield->getId()
            );


            // Determine whether ShortResults needs a recache
            // TODO - replace this block with code to directly update the cached version of the datarecord?
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
     * @return Response
     */
    public function deleteimageAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the necessary entities
            /** @var Image $image */
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

            // Images that aren't done encrypting shouldn't be modified
            if ($image->getOriginalChecksum() == '')
                return parent::deletedEntityError('Image');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Grab all alternate sizes of the original image (thumbnail is only current one) and remove them
            /** @var Image[] $images */
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            foreach ($images as $img) {
                // Ensure no decrypted version of any of the thumbnails exist on the server
                $local_filepath = dirname(__FILE__).'/../../../../web/uploads/images/Image_'.$img->getId().'.'.$img->getExt();
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);

                // Delete the alternate sized image from the database
                $em->remove($img);
            }

            // Ensure no decrypted version of the original image exists on the server
            $local_filepath = dirname(__FILE__).'/../../../../web/uploads/images/Image_'.$image->getId().'.'.$image->getExt();
            if ( file_exists($local_filepath) )
                unlink($local_filepath);

            // Save who deleted the image
            $image->setDeletedBy($user);
            $em->persist($image);
            $em->flush($image);

            // Delete the original image and its associated meta entry as well
            $image_meta = $image->getImageMeta();
            $em->remove($image);
            $em->remove($image_meta);
            $em->flush();


            // Determine whether ShortResults needs a recache
            // TODO - replace this block with code to directly update the cached version of the datarecord?
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
     * Rotates a given image by 90 degrees (counter)clockwise, and saves it
     *
     * @param integer $image_id The database id of the Image to delete.
     * @param integer $direction -1 for 90 degrees counter-clockwise rotation, 1 for 90 degrees clockwise rotation
     * @param Request $request
     *
     * @return Response
     */
    public function rotateimageAction($image_id, $direction, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the necessary entities
            /** @var Image $image */
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

            // Images that aren't done encrypting shouldn't be modified
            if ($image->getOriginalChecksum() == '')
                return parent::deletedEntityError('Image');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Determine how long it's been since the creation of this image...
            $create_date = $image->getCreated();
            $current_date = new \DateTime();
            $interval = $create_date->diff($current_date);

            // TODO - duration in which image can be rotated without creating new entry?
            // If the image has existed on the server for less than 30 minutes
            $replace_existing = false;
            if ($interval->days == 0 && $interval->h == 0 && $interval->i <= 30)
                $replace_existing = true;


            // ----------------------------------------
            // Image is going to be rotated, so clear the original checksum for the original image and its thumbnails
            $image_path = parent::decryptObject($image_id, 'image');
            if ($replace_existing) {
                $image->setOriginalChecksum('');    // checksum of the image will be replaced after rotation
                $em->persist($image);
            }

            /** @var Image[] $images */
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            foreach ($images as $img) {
                // Ensure no decrypted version of any of the thumbnails exist on the server
                $local_filepath = dirname(__FILE__).'/../../../../web/uploads/images/Image_'.$img->getId().'.'.$img->getExt();
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);

                if ($replace_existing) {
                    $img->setOriginalChecksum('');    // checksum of the thumbnail will be replaced after rotation
                    $em->persist($img);
                }
            }

            if ($replace_existing)
                $em->flush();


            // ----------------------------------------
            // If not replacing existing image, have image rotation write back to the same file
            $dest_path = $image_path;
            if (!$replace_existing) {
                // ...otherwise, determine the path to the user's upload folder
                // The image rotation function will save the rotated image there so it can be "uploaded again"...this is the easiest way to ensure everything neccessary exists
                $dest_path = dirname(__FILE__).'/../../../../web/uploads/files';
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );
                $dest_path .= '/chunks';
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );
                $dest_path .= '/user_'.$user->getId();
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );
                $dest_path .= '/completed';
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );

                $dest_path.= '/'.$image->getOriginalFileName();
            }

            // Rotate and save image back to server...apparently positive degrees mean counter-clockwise rotation with imagerotate()
            $degrees = 90;
            if ($direction == 1)
                $degrees = -90;

            $im = null;
            switch ( strtolower($image->getExt()) ) {
                case 'gif':
                    $im = imagecreatefromgif($image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagegif($im, $dest_path);
                    break;
                case 'png':
                    $im = imagecreatefrompng($image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagepng($im, $dest_path);
                    break;
                case 'jpg':
                case 'jpeg':
                    $im = imagecreatefromjpeg($image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagejpeg($im, $dest_path);
                    break;
            }
            imagedestroy($im);


            // ----------------------------------------
            if ($replace_existing) {
                // Update the image's height/width as stored in the database
                $sizes = getimagesize($image_path);
                $image->setImageWidth($sizes[0]);
                $image->setImageHeight($sizes[1]);
                // Create thumbnails and other sizes/versions of the uploaded image
                self::resizeImages($image, $user);

                // Encrypt parent image AFTER thumbnails are created
                self::encryptObject($image_id, 'image');

                // Set original checksum for original image
                $filepath = self::decryptObject($image_id, 'image');
                $original_checksum = md5_file($filepath);
                $image->setOriginalChecksum($original_checksum);

                // A decrypted version of the Image still exists on the server...delete it
                unlink($filepath);

                // Save changes again
                $em->persist($image);
                $em->flush();
            }
            else {
                // "Upload" the "new" rotated image
                $filepath = 'uploads/files/chunks/user_'.$user->getId().'/completed';
                $original_filename = $image->getOriginalFileName();

                $new_image = parent::finishUpload($em, $filepath, $original_filename, $user->getId(), $image->getDataRecordFields()->getId());

                // Copy any metadata from the old image over to the new image
                $old_image_meta = $image->getImageMeta();
                $properties = array(
                    'caption' => $old_image_meta->getCaption(),
                    'original_filename' => $old_image_meta->getOriginalFileName(),
                    'external_id' => $old_image_meta->getExternalId(),
                    'publicDate' => $old_image_meta->getPublicDate(),
                    'display_order' => $old_image_meta->getDisplayorder()
                );
                parent::ODR_copyImageMeta($em, $user, $new_image, $properties);


                // Ensure no decrypted version of the original image exists on the server
                $local_filepath = dirname(__FILE__).'/../../../../web/uploads/images/Image_'.$image->getId().'.'.$image->getExt();
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);

                // Delete the original image and its metadata entry
                $em->remove($image);
                $em->remove($old_image_meta);

                // Delete any thumbnails of the original image
                foreach ($images as $img)
                    $em->remove($img);

                $em->flush();
            }


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
     * @return Response
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
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Grab the first image just to check permissions
            $image = null;
            foreach ($post as $index => $image_id) {
                $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
                break;
            }
            /** @var Image $image */

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
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Ensure that the provided image ids are all from the same datarecordfield, and that all images from that datarecordfield are listed in the post
            $query = $em->createQuery(
               'SELECT e
                FROM ODRAdminBundle:Image AS e
                WHERE e.dataRecordFields = :drf AND e.original = 1
                AND e.deletedAt IS NULL'
            )->setParameters( array('drf' => $image->getDataRecordFields()->getId()) );
            $results = $query->getResult();

            $all_images = array();
            foreach ($results as $image)
                $all_images[ $image->getId() ] = $image;
            /** @var Image[] $all_images */

            // Throw exceptions if the post request doesn't match the expected image list
            if ( count($post) !== count($all_images) ) {
                throw new \Exception('Invalid POST request...wrong number of images');
            }
            else {
                foreach ($post as $index => $image_id) {
                    if ( !isset($all_images[$image_id]) )
                        throw new \Exception('Invalid POST request...unexpected image id');
                }
            }


            // Update the image order based on the post request if required
            foreach ($post as $index => $image_id) {
                $image = $all_images[$image_id];

                if ( $image->getDisplayorder() != $index ) {
//print 'set "'.$image->getOriginalFilename().'" to index '.$index."\n";
                    $properties = array('display_order' => $index);
                    parent::ODR_copyImageMeta($em, $user, $image, $properties);
                }
            }


            // Determine whether ShortResults needs a recache
            // TODO - replace this block with code to directly update the cached version of the datarecord?
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
     * @return Response
     */
    public function publicdatarecordAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Toggle the public status of the datarecord
            $public = 0;
            if ( $datarecord->isPublic() ) {
                // Make the datarecord non-public
                $public_date = new \DateTime('2200-01-01 00:00:00');

                $properties = array('publicDate' => $public_date);
                parent::ODR_copyDatarecordMeta($em, $user, $datarecord, $properties);
            }
            else {
                // Make the datarecord non-public
                $public_date = new \DateTime();

                $properties = array('publicDate' => $public_date);
                parent::ODR_copyDatarecordMeta($em, $user, $datarecord, $properties);

                $public = 1;
            }

            // Refresh the cache entries for this datarecord
            $options = array('mark_as_updated' => true);
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
     * Handles selection changes made to SingleRadio, MultipleRadio, SingleSelect, and MultipleSelect DataFields
     *
     * @param integer $data_record_field_id The database id of the DataRecord/DataField pair being modified.
     * @param integer $radio_option_id      The database id of the RadioOption entity being (de)selected.  If 0, then no RadioOption should be selected.
     * @param Request $request
     *
     * @return Response
     */
    public function radioselectionAction($data_record_field_id, $radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            /** @var DataRecordFields $datarecordfield */
            $datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields')->find($data_record_field_id);
            if ( $datarecordfield == null )
                return parent::deletedEntityError('DataRecordField');

            $datafield = $datarecordfield->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            /** @var RadioOptions $radio_option */
            if ($radio_option_id != 0) {
                $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($radio_option_id);
                if ($radio_option == null)
                    return parent::deletedEntityError('RadioOption');
            }

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // Course of action differs based on whether multiple selections are allowed
            $typename = $datafield->getFieldType()->getTypeName();

            // A RadioOption id of 0 has no effect on a Multiple Radio/Select datafield
            if ( $radio_option_id != 0 && ($typename == 'Multiple Radio' || $typename == 'Multiple Select') ) {
                // Don't care about selected status of other RadioSelection entities...
                /** @var RadioSelection $old_radio_selection */
                $old_radio_selection = $repo_radio_selection->findOneBy( array('radioOption' => $radio_option_id, 'dataRecordFields' => $data_record_field_id) );

                // Default to a value of 'selected' if an older RadioSelection entity does not exist
                $new_value = 1;
                if ($old_radio_selection !== null) {
                    // An older version does exist...determine what the new value should be
                    if ($old_radio_selection->getSelected() == 1)
                        $new_value = 0;

                    // Delete the old entity
                    $em->remove($old_radio_selection);
                }

                // Create the new RadioSelection entity
                $new_radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield, $new_value);
                $em->persist($new_radio_selection);
                $em->flush();
            }
            else if ($typename == 'Single Radio' || $typename == 'Single Select') {
                // Probably need to change selected status of at least one other RadioSelection entity...
                /** @var RadioSelection[] $radio_selections */
                $radio_selections = $repo_radio_selection->findBy( array('dataRecordFields' => $data_record_field_id) );

                $found = false;
                foreach ($radio_selections as $radio_selection) {

                    if ( $radio_option_id != $radio_selection->getRadioOption()->getId() ) {
                        if ($radio_selection->getSelected() == 1) {
                            // Deselect all RadioOptions that are selected and are not the one the user wants to be selected
                            $radio_option_tmp = $radio_selection->getRadioOption();
                            $em->remove($radio_selection);

                            $new_radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option_tmp, $datarecordfield, 0);
                            $em->persist($new_radio_selection);
                        }
                    }
                    else {
                        // Found a prior entry for the RadioOption the user is attempting to modify...
                        $found = true;

                        if ($radio_selection->getSelected() == 0) {
                            // The RadioOption the user wants to be selected is not selected...delete the old entity and create a RadioSelection for this one
                            $radio_option_tmp = $radio_selection->getRadioOption();
                            $em->remove($radio_selection);

                            $new_radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option_tmp, $datarecordfield, 1);
                            $em->persist($new_radio_selection);
                        }
                        else {
                            // The RadioOption the user wants to be selected is somehow already selected...do nothing in this case
                        }
                    }
                }

                // If a RadioSelection entity for this RadioOption doesn't exist, create it
                if (!$found && $radio_option_id != 0) {
                    $new_radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield, 1);
                    $em->persist($new_radio_selection);
                }

                $em->flush();
            }
            else {
                // No point doing anything if not a radio fieldtype
                throw new \Exception('RecordController::radioselectionAction() called on Datafield that is not a Radio FieldType');
            }


            // ----------------------------------------
            // TODO - replace this block with code to directly update the cached version of the datarecord
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
     * Parses a $_POST request to update the contents of a datafield.
     * File and Image uploads are handled by @see FlowController
     * Changes to RadioSelections are handled by RecordController::radioselectionAction()
     * 
     * @param string $record_type    Apparently, the typeclass of the datafield being modified.     TODO - change the variable name to $typeclass or something?
     * @param integer $datarecord_id The database id of the datarecord being modified.
     * @param Request $request
     * 
     * @return Response
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
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            $datafield_id = $_POST[$record_type.'Form']['data_field'];
            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');


            $datatype_id = $datatype->getId();
            $external_id_datafield = $datatype->getExternalIdField();
            $sort_datafield = $datatype->getSortField();
            $name_datafield = $datatype->getNameField();


            // --------------------
            // Determine user privileges
            /** @var User $user */
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
                case 'Boolean':
                case 'DatetimeValue':
                case 'DecimalValue':
                case 'IntegerValue':
                case 'LongVarchar':
                case 'LongText':    // paragraph text
                case 'MediumVarchar':
                case 'ShortVarchar':
                    $my_obj = new $obj_classname();
                    $post = $_POST;
                    if ( isset($post['id']) && $post['id'] > 0 ) {
                        $repo = $em->getRepository('ODRAdminBundle:'.$record_type);
                        $my_obj = $repo->find($post['id']);
                    }
                    break;

                case 'File':
                case 'Image':
                    /* do nothing */
                    break;

                case 'Radio':
                    // No point continuing if a radio fieldtype
                    throw new \Exception('RecordController::updateAction() called on Datafield with a Radio FieldType');
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
            /** @var DataRecordFields $drf */
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
                    // ...determine whether the new value is a duplicate of a value that already exists
                    $found_existing_value = self::findExistingValue($em, $datafield, $datarecord->getParent()->getId(), $new_value);
                    if ($found_existing_value)
                        $form->addError( new FormError('Another Datarecord already has the value "'.$new_value.'" stored in this Datafield...reverting back to old value.') );
                }

//$form->addError( new FormError('do not save') );

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
//                    // Save changes
//                    $em->persist($my_obj);
//                    $em->flush();

                    // ----------------------------------------
                    // If saving from a datetime field, convert the submitted string into a datetime object
                    if ( $record_type == 'DatetimeValue' ) {
                        if ($new_value == '')
                            $new_value = new \DateTime('0000-00-00 00:00:00');
                        else
                            $new_value = new \DateTime($new_value);
                    }


                    // Save the change in the database
                    switch ($record_type) {
                        case 'Boolean':
                        case 'DatetimeValue':
                        case 'DecimalValue':
                        case 'IntegerValue':
                        case 'LongVarchar':
                        case 'LongText':    // paragraph text
                        case 'MediumVarchar':
                        case 'ShortVarchar':
                            // Delete the old storage entity and create a new one to take its place
                            $em->remove($my_obj);

                            $new_obj = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                            $new_obj->setValue($new_value);

                            $em->persist($new_obj);
                            $em->flush();
                            break;

                        case 'File':
                        case 'Image':
                            // Save changes
                            $em->persist($my_obj);
                            $em->flush();
                            break;
                    }

                    // ----------------------------------------
                    // TODO - replace this block with code to directly update the cached version of the datarecord
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
                    if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
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
                    $return['r'] = 2;
                    $return['old_value'] = $old_value;

                    $return['error'] = parent::ODR_getErrorMessages($form);
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
     * Returns whether the provided value would violate uniqueness constraints for the given datafield.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param Datafields $datafield
     * @param integer $parent_datarecord_id
     * @param mixed $new_value
     *
     * @return boolean
     */
    private function findExistingValue($em, $datafield, $parent_datarecord_id, $new_value)
    {
        // Going to need these...
        $datatype_id = $datafield->getDataType()->getId();
        $typeclass = $datafield->getFieldType()->getTypeClass();

        // Determine if this datafield belongs to a top-level datatype or not
        $is_child_datatype = false;
        $datatree_array = parent::getDatatreeArray($em);
        if ( isset($datatree_array['descendant_of'][$datatype_id]) && $datatree_array['descendant_of'][$datatype_id] !== '' )
            $is_child_datatype = true;

        // Mysql requires a different comparision if checking for duplicates of a null value...
        $comparision = $parameters = null;
        if ($new_value != null) {
            $comparision = 'e.value = :value';
            $parameters = array('datafield' => $datafield->getId(), 'value' => $new_value);
        }
        else {
            $comparision = '(e.value IS NULL OR e.value = :value)';
            $parameters = array('datafield' => $datafield->getId(), 'value' => '');
        }

        // Also search on parent datarecord id if it was passed in
        if ($is_child_datatype)
            $parameters['parent_datarecord_id'] = $parent_datarecord_id;

        if (!$is_child_datatype) {
            $query = $em->createQuery(
               'SELECT e.id
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE e.dataField = :datafield AND '.$comparision.'
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters($parameters);
            $results = $query->getArrayResult();

            // The given value already exists in this datafield
            if ( count($results) > 0 )
                return true;
        }
        else {
            $query = $em->createQuery(
               'SELECT e.id
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
                WHERE e.dataField = :datafield AND '.$comparision.' AND parent.id = :parent_datarecord_id
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL'
            )->setParameters($parameters);
            $results = $query->getArrayResult();

            // The given value already exists in this datafield
            if ( count($results) > 0 )
                return true;
        }

        // The given value does not exist in this datafield
        return false;
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
     * @return Response
     */
    public function getlinkablerecordsAction($ancestor_datatype_id, $descendant_datatype_id, $local_datarecord_id, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            // Grab the datatypes from the database
            /** @var DataRecord $local_datarecord */
            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ( $local_datarecord == null )
                return parent::deletedEntityError('DataRecord');

            /** @var DataType $ancestor_datatype */
            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ( $ancestor_datatype == null )
                return parent::deletedEntityError('DataType');

            /** @var DataType $descendant_datatype */
            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ( $descendant_datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            /** @var User $user */
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
            /** @var DataType $remote_datatype */

if ($debug)
    print "\nremote datatype: ".$remote_datatype->getId()."\n";


            // ----------------------------------------
            // Grab all datarecords currently linked to the local_datarecord
            $linked_datarecords = array();
            if ($local_datarecord_is_ancestor) {
                // local_datarecord is on the ancestor side of the link
                $query = $em->createQuery(
                   'SELECT descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS ancestor
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
                    FROM ODRAdminBundle:DataRecord AS descendant
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
               'SELECT dtm.multiple_allowed AS multiple_allowed
                FROM ODRAdminBundle:DataTree AS dt
                JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                WHERE dt.ancestor = :ancestor AND dt.descendant = :descendant
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
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
            // Convert the list of already-linked datarecords into table format for displaying and manipulation
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $remote_datatype->getId(), 'themeType' => 'table') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            // Convert the list of linked datarecords into a slightly different format so renderTextResultsList() can build it
            $datarecord_list = array();
            foreach ($linked_datarecords as $dr_id => $value)
                $datarecord_list[] = $dr_id;

            $table_html = parent::renderTextResultsList($em, $datarecord_list, $theme, $request);
            $table_html = json_encode($table_html);
//print_r($table_html);

            // Grab the column names for the datatables plugin
            $column_data = parent::getDatatablesColumnNames($em, $theme);
            $column_names = $column_data['column_names'];
            $num_columns = $column_data['num_columns'];
/*
print '<pre>';
print_r($column_data);
print '</pre>';
exit();
*/
            // Render the dialog box for this request
            $templating = $this->get('templating');
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
     * @return Response
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
            $allow_multiple_links = $post['allow_multiple_links'];      // TODO - not used when it should be?
            $datarecords = array();
            if ( isset($post['datarecords']) )
                $datarecords = $post['datarecords'];


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            /** @var DataRecord $local_datarecord */
            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ( $local_datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $local_datatype = $local_datarecord->getDataType();
            if ( $local_datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $local_datatype->getId() ]) && isset($user_permissions[ $local_datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab the datatypes from the database
            /** @var DataType $ancestor_datatype */
            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ( $ancestor_datatype == null )
                return parent::deletedEntityError('DataType');

            /** @var DataType $descendant_datatype */
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
            /** @var LinkedDataTree[] $linked_datatree */

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
                    $ldt->setDeletedBy($user);
                    $em->persist($ldt);
                    $em->flush($ldt);

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
    * @return Response
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
    * @return Response
    */  
    public function reloaddatafieldAction($datafield_id, $datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                return parent::deletedEntityError('Datarecord');
            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
//            $logged_in = true;

            // Ensure user has permissions to be doing this
            if ( !( isset($user_permissions[$datatype->getId()]) && ( isset($user_permissions[$datatype->getId()]['edit']) || isset($user_permissions[$datatype->getId()]['child_edit']) ) ) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $theme_datafield = $datafield->getThemeDataField();
            foreach ($theme_datafield as $tdf) {
                if ($tdf->getTheme()->getId() == 1) {
                    $theme_datafield = $tdf;
                    break;
                }
            }

            /** @var DataRecordFields $datarecordfield */
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
                    'force_image_reload' => true,
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
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        // --------------------
        // Determine user privileges
        /** @var User $user */
        $user = $this->container->get('security.context')->getToken()->getUser();
        $datatype_permissions = parent::getPermissionsArray($user->getId(), $request);
        $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);
        // --------------------

        // Always bypass cache in dev mode
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;


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
        /** @var DataRecord $parent_datarecord */
        /** @var DataRecord $datarecord */
        /** @var DataType $datatype */

        // TODO - move this?
        /** @var Theme $theme */
        $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
        if ($theme == null)
            return parent::deletedEntityError('Theme');
/*
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
                FROM ODRAdminBundle:DataRecord AS dr
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
                FROM ODRAdminBundle:LinkedDataTree AS ldt
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
*/

        // ----------------------------------------
        // Grab all datarecords "associated" with the desired datarecord...TODO
        $associated_datarecords = $memcached->get($memcached_prefix.'.associated_datarecords_for_'.$datarecord_id);
        if ($bypass_cache || $associated_datarecords == false) {
            $associated_datarecords = parent::getAssociatedDatarecords($em, array($datarecord_id));

//print '<pre>'.print_r($associated_datarecords, true).'</pre>';  exit();

            $memcached->set($memcached_prefix.'.associated_datarecords_for_'.$datarecord_id, $associated_datarecords, 0);
        }


        // Grab the cached versions of all of the associated datarecords, and store them all at the same level in a single array
        $datarecord_array = array();
        foreach ($associated_datarecords as $num => $dr_id) {
            $datarecord_data = $memcached->get($memcached_prefix.'.cached_datarecord_'.$dr_id);
            if ($bypass_cache || $datarecord_data == false)
                $datarecord_data = parent::getDatarecordData($em, $dr_id, $bypass_cache);

            foreach ($datarecord_data as $dr_id => $data)
                $datarecord_array[$dr_id] = $data;
        }
//print '<pre>'.print_r($datarecord_array, true).'</pre>';  exit();

        // ----------------------------------------
        $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

        // Grab all datatypes associated with the desired datarecord
        // NOTE - using parent::getAssociatedDatatypes() here because we need to be able to see child/linked datatypes even if none are attached to this datarecord
        $include_links = true;
        $associated_datatypes = parent::getAssociatedDatatypes($em, array($datatype->getId()), $include_links);

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = $memcached->get($memcached_prefix.'.cached_datatype_'.$dt_id);
            if ($bypass_cache || $datatype_data == false)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

//print '<pre>'.print_r($datatype_array, true).'</pre>';  exit();

        // ----------------------------------------
        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        parent::filterByUserPermissions($datatype->getId(), $datatype_array, $datarecord_array, $datatype_permissions, $datafield_permissions);


        // Determine which template to use for rendering
        $template = 'ODRAdminBundle:Record:record_ajax.html.twig';
        if ($template_name == 'child')
            $template = 'ODRAdminBundle:Record:record_area_child_load.html.twig';
/*
        // Determine what datatypes link to this datatype
        $ancestor_linked_datatypes = array();
        if ($template_name == 'default') {
            $query = $em->createQuery(
               'SELECT ancestor.id AS ancestor_id, ancestor_meta.shortName AS ancestor_name
                FROM ODRAdminBundle:DataTree AS dt
                JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
                JOIN ODRAdminBundle:DataTypeMeta AS ancestor_meta WITH ancestor_meta.dataType = ancestor
                WHERE dtm.is_link = 1 AND dt.descendant = :datatype
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND ancestor_meta.deletedAt IS NULL'
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
               'SELECT descendant.id AS descendant_id, descendant_meta.shortName AS descendant_name
                FROM ODRAdminBundle:DataTree AS dt
                JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
                JOIN ODRAdminBundle:DataTypeMeta AS descendant_meta WITH descendant_meta.dataType = descendant
                WHERE dtm.is_link = 1 AND dt.ancestor = :datatype
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND descendant.deletedAt IS NULL AND descendant_meta.deletedAt IS NULL'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $results = $query->getArrayResult();
            foreach ($results as $num => $result) {
                $id = $result['descendant_id'];
                $name = $result['descendant_name'];
                $descendant_linked_datatypes[$id] = $name;
            }
        }
*/

        // Render the DataRecord
        $templating = $this->get('templating');
        $html = $templating->render(
            $template,
            array(
/*
                'search_key' => $search_key,

                'parent_datarecord' => $parent_datarecord,

                'datatype_tree' => $datatype_tree,
                'datarecord_tree' => $datarecord_tree,
                'theme' => $theme,

                'ancestor_linked_datatypes' => $ancestor_linked_datatypes,
                'descendant_linked_datatypes' => $descendant_linked_datatypes,
*/
                'datatype_array' => $datatype_array,
                'datarecord_array' => $datarecord_array,
                'theme_id' => $theme->getId(),

                'initial_datatype_id' => $datatype->getId(),
                'initial_datarecord_id' => $datarecord->getId(),

                'datatype_permissions' => $datatype_permissions,
                'datafield_permissions' => $datafield_permissions,

                'search_key' => $search_key,
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
     * @return Response
     */
    public function editAction($datarecord_id, $search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get necessary objects
/*
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
*/
            $session = $request->getSession();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Get Record In Question
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('Datarecord');

            // TODO - not accurate, technically...
            if ($datarecord->getProvisioned() == true)
                return parent::permissionDeniedError();

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');
            $datatype_id = $datatype->getId();


            // Grab the theme to use to display this
            // TODO - alternate themes?
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $logged_in = true;

            // Ensure user has permissions to be doing this
            if ( !( isset($user_permissions[$datatype_id]) && ( isset($user_permissions[$datatype_id]['edit']) || isset($user_permissions[$datatype_id]['child_edit']) ) ) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Ensure all objects exist before rendering
//            parent::verifyExistence($datarecord);


            // ----------------------------------------
            // If this datarecord is being viewed from a search result list...
            $datarecord_list = '';
            $encoded_search_key = '';
            if ($search_key !== '') {
                // ...attempt to grab the list of datarecords from that search result
                $data = parent::getSavedSearch($datatype->getId(), $search_key, $logged_in, $request);
                $encoded_search_key = $data['encoded_search_key'];
                $datarecord_list = $data['datarecord_list'];

                if ($data['error'] == true || ($encoded_search_key !== '' && $datarecord_list === '') ) {
                    // Some sort of error encounted...bad search query, invalid permissions, or empty datarecord list
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
    * @param integer $datarecordfield_id The database id of the DataRecord/DataField pair to look-up in the transaction log
    * @param Request $request
    * 
    * @return Response
    */
    public function getfieldhistoryAction($datarecordfield_id, Request $request)
    {
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Get Entity Manager and setup repositories
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataRecordFields $drf */
            $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->find($datarecordfield_id);
            if ($drf == null)
                return parent::deletedEntityError('Datarecordfield');

            $datatype = $drf->getDataRecord()->getDataType();

            // ----------------------------------------
            // Ensure user has permissions to be doing this
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                return parent::permissionDeniedError('You need to be a super-admin to view datafield history, for now');

            // Ensure user has permissions to be doing this
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // ----------------------------------------


            // ----------------------------------------
            // Don't check field history of certain fieldtypes
            $typeclass = $drf->getDataField()->getFieldType()->getTypeClass();
            if ($typeclass == 'File' || $typeclass == 'Image' || $typeclass == 'Markdown' || $typeclass == 'Radio')
                throw new \Exception('Unable to view history of a '.$typeclass.' datafield, for now');


            // Grab all fieldtypes that the datafield has been
            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows
            $query = $em->createQuery(
               'SELECT ft.id AS id, ft.typeClass AS type_class, ft.typeName AS type_name
                FROM ODRAdminBundle:DataRecordFields AS drf
                JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
                WHERE drf = :drf_id'
            )->setParameters( array('drf_id' => $datarecordfield_id) );
            $results = $query->getArrayResult();

            $all_typeclasses = array();
            $all_typenames = array();
            foreach ($results as $result) {
                $fieldtype_id = $result['id'];
                $typeclass = $result['type_class'];
                $typename = $result['type_name'];

                if ( $typeclass !== 'File' && $typeclass !== 'Image' && $typeclass !== 'Markdown' && $typeclass !== 'Radio' ) {
                    $all_typeclasses[$fieldtype_id] = $typeclass;
                    $all_typenames[$fieldtype_id] = $typename;
                }
            }

            // Grab all values that the datafield has had across all fieldtypes
            $historical_values = array();
            foreach ($all_typeclasses as $ft_id => $typeclass) {
                $query = $em->createQuery(
                   'SELECT e.value AS value, e.created AS create_date, creator.firstName, creator.lastName, creator.username
                    FROM ODRAdminBundle:'.$typeclass.' AS e
                    JOIN ODROpenRepositoryUserBundle:User AS creator WITH e.createdBy = creator
                    WHERE e.dataRecordFields = :drf_id'
                )->setParameters( array('drf_id' => $datarecordfield_id) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $value = $result['value'];
                    $create_date = $result['create_date'];

                    $user_string = $result['username'];
                    if ( $result['firstName'] !== '' && $result['lastName'] !== '' )
                        $user_string = $result['firstName'].' '.$result['lastName'];

                    $historical_values[] = array('value' => $value, 'user' => $user_string, 'create_date' => $create_date, 'typeclass' => $typeclass, 'typename' => $all_typenames[$ft_id]);
                }
            }

            $em->getFilters()->enable('softdeleteable');    // Re-enable the softdeleteable filter


            // ----------------------------------------
            // Sort array from earliest date to latest date
            usort($historical_values, function ($a, $b) {
                // Sort by display order first if possible
                $interval = date_diff($a['create_date'], $b['create_date']);
                if ( $interval->invert == 0 )
                    return -1;
                else
                    return 1;
            });


            // ----------------------------------------
            // Use the resulting keys of the array after the sort as version numbers
            foreach ($historical_values as $num => $data)
                $historical_values[$num]['version'] = ($num+1);

            // Create a form for the object to use if value gets reverted
            $current_typeclass = $drf->getDataField()->getFieldType()->getTypeClass();
            $form_classname = "\\ODR\\AdminBundle\\Form\\".$current_typeclass."Form";

            $my_obj = $drf->getAssociatedEntity();
            $form = $this->createForm(new $form_classname($em), $my_obj);

            // Render the dialog box for this request
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Record:field_history_dialog_form.html.twig',
                    array(
                        'historical_values' => $historical_values,
                        'current_typeclass' => $current_typeclass,
                        'drf' => $drf,

                        'form' => $form->createView()
                    )
                )
            );
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
