<?php

/**
* Open Data Repository Data Publisher
* DisplayTemplate Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The displaytemplate controller handles everything required to
* design the long-form view and edit layout of a database record.
* This includes but is not limited to addition, deletion, and setting
* of position and other related properties of DataFields, ThemeElement
* containers, and child DataTypes.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\UserPermissions;
// Forms
use ODR\AdminBundle\Form\DecimalValueForm;
use ODR\AdminBundle\Form\IntegerValueForm;
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\UpdateDataTreeForm;
use ODR\AdminBundle\Form\UpdateThemeElementForm;
use ODR\AdminBundle\Form\UpdateThemeDatafieldForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\FormError;

// YAML Parsing
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;


class DisplaytemplateController extends ODRCustomController
{
    /**
     * Deletes a DataField from the DataType.
     * 
     * @param integer $datafield_id The database id of the Datafield to delete.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function deletefieldAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Grab entity manager and repositories
            $em = $this->getDoctrine()->getManager();
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_theme_datafields = $em->getRepository('ODRAdminBundle:ThemeDataField');
            $repo_theme_element_fields = $em->getRepository('ODRAdminBundle:ThemeElementField');

            // 
            $datafield = $repo_datafield->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');
            $datatype_id = $datatype->getId();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // --------------------
            // TODO - better way of handling this
            // Prevent deletion of datafields if a csv import is in progress, as this could screw the importing over
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$datatype->getId(), 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('Preventing deletion of any DataField for this DataType, because a CSV Import for this DataType is in progress...');


            // Determine if shortresults needs to be recached as a result of this deletion
            $options = array();
            $options['mark_as_updated'] = true;
            $search_theme_data_field = $repo_theme_datafields->findOneBy( array('dataFields' => $datafield, 'theme' => 2) );   // TODO
            if ($search_theme_data_field !== null && $search_theme_data_field->getActive() == true)
                $options['force_shortresults_recache'] = true;
            if ($datafield->getDisplayOrder() != -1)
                $options['force_textresults_recache'] = true;

            // Delete all datarecordfield entries associated with the datafield
            $date = new \DateTime();
            $date = $date->format('Y-m-d');
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecordFields drf
                SET drf.deletedAt = :date
                WHERE drf.dataField = :datafield'
            )->setParameters( array('date' => $date, 'datafield' => $datafield->getId()) );
            $rows = $query->execute();


            // Ensure that the datatype doesn't continue to think this datafield is its external id field
            if ($datatype->getExternalIdField() !== null && $datatype->getExternalIdField()->getId() === $datafield->getId())
                $datatype->setExternalIdField(null);

            // Ensure that the datatype doesn't continue to think this datafield is its name field
            if ($datatype->getNameField() !== null && $datatype->getNameField()->getId() === $datafield->getId())
                $datatype->setNameField(null);

            // Ensure that the datatype doesn't continue to think this datafield is its sort field
            if ($datatype->getSortField() !== null && $datatype->getSortField()->getId() === $datafield->getId()) {
                $datatype->setSortField(null);

                // Delete the sort order for the datatype too, so it doesn't attempt to sort on a non-existent datafield
                $memcached->delete($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');
            }

            // De-activate theme_datafield entries attached to this datafield
            $theme_datafields = $repo_theme_datafields->findBy( array('dataFields' => $datafield->getId()) );
            foreach ($theme_datafields as $tdt) {
                $tdt->setActive(0);
                $em->persist($tdt);
            }

            // Delete any theme_element_field entries attached to the datafield so the renderer doesn't get confused
            $theme_element_fields = $repo_theme_element_fields->findBy( array('dataFields' => $datafield->getId()) );
            foreach ($theme_element_fields as $tef)
                $em->remove($tef);

            // Done cleaning up after the datafield, delete it
            $em->remove($datafield);

            // Save changes
            $em->flush();

            // Schedule the cache for an update
            parent::updateDatatypeCache($datatype->getId(), $options);


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
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x18392883 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a RadioOption entity from a Datafield.
     * 
     * @param integer $radio_option_id The database id of the RadioOption to delete.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function deleteradiooptionAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find( $radio_option_id );
            $datatype = $radio_option->getDataFields()->getDataType();
            $datafield = $radio_option->getDataFields();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("change layout of");
            // --------------------

            // Delete the radio option
            $em->remove($radio_option);
            $em->flush();

            // Delete all radio selection entities attached to the radio option
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:RadioSelection AS rs
                SET rs.deletedAt = :now
                WHERE rs.radioOption = :radio_option_id AND rs.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'radio_option_id' => $radio_option_id) );
            $updated = $query->execute();

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            $search_theme_data_field = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield, 'theme' => 2) );
            if ($search_theme_data_field !== null && $search_theme_data_field->getActive() == true)
                $options['force_shortresults_recache'] = true;

            parent::updateDatatypeCache($datatype->getId(), $options);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x18392884444 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Toggles whether a given RadioOption entity is automatically selected upon creation of a new datarecord.
     *
     * @param integer $radio_option_id The database id of the RadioOption to modify.
     * @param Request $request
     *
     * @return Response TODO
     */
    public function defaultradiooptionAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions');
            $radio_option = $repo_radio_option->find( $radio_option_id );
            $datafield = $radio_option->getDataFields();
            $datatype = $datafield->getDataType();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("change layout of");
            // --------------------


            $field_typename = $datafield->getFieldType()->getTypeName();
            if ( $field_typename == 'Single Radio' || $field_typename == 'Single Select' ) {
                // TODO - this probably needs to be done via DQL update
                // Only one option allowed to be default for Single Radio/Select DataFields, clear all other options
                $all_radio_options = $repo_radio_option->findBy( array('dataFields' => $datafield->getId()) );
                foreach ($all_radio_options as $option) {
                    $option->setIsDefault(false);
                    $em->persist($option);
                }

                // TODO - currently not allowed to remove a default option from one of these fields once a a default has been set
                // Set this option as default
                $radio_option->setIsDefault(true);
            }
            else {
                // Multiple options allowed as defaults, toggle default status of current
                if ($radio_option->getIsDefault() == true)
                    $radio_option->setIsDefault(false);
                else
                    $radio_option->setIsDefault(true);
            }

            // Save the changes
            $em->persist($radio_option);
            $em->flush();

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1397284454 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Deletes an entire DataType and all of the entities directly related to rendering it.
     * DataRecords and DataFields don't have to be deleted.
     * 
     * @param integer $datatype_id The database id of the DataType to be deleted.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function deletedatatypeAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');

            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            $top_level_datatypes = parent::getTopLevelDatatypes();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( in_array($datatype->getId(), $top_level_datatypes) ) {
                // require "is_type_admin" permission to delete a top-level datatype
                if ( !($user->hasRole('ROLE_ADMIN') && isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'admin' ])) )
                    return parent::permissionDeniedError("delete");
            }
            else {
                // only require "can_design_type" permission to delete a childtype
                if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                    return parent::permissionDeniedError("delete");
            }
            // --------------------

            // TODO - warn about deleting datatype when jobs are in progress

            // Don't delete this datatype if it's set as the default search
//            if ($datatype->getIsDefaultSearchDatatype() == true)
//                throw new \Exception("This Datatype can't be deleted because it's marked as the default search datatype...");

/*
            // TODO - Does each datarecord of the datatype also need to be deleted?
            // Get RecordController in order to render everything
            $odrc = $this->get('record_controller', $request);
            $odrc->setContainer($this->container);
            $datarecords = $repo_datarecord->findByDataType($datatype);
            foreach ($datarecords as $dr)
                $odrc->deleteAction($dr->getId(), $request);
*/


            // TODO - should all of these need to be done with DQL update?

/*
            // First, remove user permission entries
            $user_permissions = $repo_user_permissions->findBy( array('dataType' => $datatype->getId()) );
            foreach ($user_permissions as $permission) 
                $em->remove($permission);
*/

            // Second, remove the child/parent links from the datatree
            $childtree = $repo_datatree->findByAncestor($datatype);
            foreach($childtree as $branch) {
                if ($branch->getIsLink() == '0')
                    // Delete the childtype...the childtype will take care of the datatree entry
                    self::deletedatatypeAction( $branch->getDescendant()->getId(), $request );
                else
                    // Is a link...delete the datatree entry, but not the "child" type
                    $em->remove($branch);
            }

            $parenttree = $repo_datatree->findByDescendant($datatype);
            foreach($parenttree as $branch)
                $em->remove($branch);

            // Third, remove the theme datatype entity so it won't show up in Results/Records anymore
            $repo_theme_data_type = $em->getRepository('ODRAdminBundle:ThemeDataType');
            $theme_data_type = $repo_theme_data_type->findOneBy( array("dataType" => $datatype->getId(), "theme" => 1) );
            if ($theme_data_type != null)
                $em->remove($theme_data_type);

            // Fourth, also remove the theme element field, so the templating doesn't attempt to access the deleted datatype
            $repo_theme_element_field = $em->getRepository('ODRAdminBundle:ThemeElementField');
            $theme_element_fields = $repo_theme_element_field->findBy( array("dataType" => $datatype->getId()) );   // want all theme_element_field entries across all themes
            foreach ($theme_element_fields as $theme_element_field)
                $em->remove($theme_element_field);

            // Finally, delete the actual datatype itself
            $em->remove($datatype);

            // Commit Deletes
            $em->flush(); 

            // No point updating caches, datatype doesn't 'exist' anymore
            // TODO - updating cache for parent/linked datatypes if applicable

            // ----------------------------------------
            // Delete any cached searches for this datatype
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $cached_searches = $memcached->get($memcached_prefix.'.cached_search_results');
            if ( isset($cached_searches[$datatype_id]) ) {
                unset( $cached_searches[$datatype_id] );

                // Save the collection of cached searches back to memcached
                $memcached->set($memcached_prefix.'.cached_search_results', $cached_searches, 0);
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1883778 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  

    }


    /**
     * Debug function to locate/delete undeleted children of deleted datatypes
     */
    public function dtcheckAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, ancestor.shortName AS ancestor_name, dt.id AS datatree_id, descendant.id AS descendant_id, descendant.shortName AS descendant_name
            FROM ODRAdminBundle:DataType AS ancestor
            JOIN ODRAdminBundle:DataTree AS dt WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE dt.is_link = 0
            AND ancestor.deletedAt IS NOT NULL AND descendant.deletedAt IS NULL');
        $results = $query->getResult();
        $em->getFilters()->enable('softdeleteable');
//print_r($results);
//return;

print '<pre>';
        foreach ($results as $result) {
            $ancestor_id = $result['ancestor_id'];
            $ancestor_name = $result['ancestor_name'];
            $datatree_id = $result['datatree_id'];
            $descendant_id = $result['descendant_id'];
            $descendant_name = $result['descendant_name'];

            print 'DataType '.$ancestor_id.' ('.$ancestor_name.') deleted, but childtype '.$descendant_id.' ('.$descendant_name.') still exists'."\n";
            print '-- undeleting ancestor...'."\n";

            // temporarily undelete the ancestor datatype that didn't get properly deleted
            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:DataType AS dt SET dt.deletedAt = NULL WHERE dt.id = :ancestor'
            )->setParameters( array('ancestor' => $ancestor_id) );
            $query->execute();
            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:DataTree AS dt SET dt.deletedAt = NULL WHERE dt.id = :datatree'
            )->setParameters( array('datatree' => $datatree_id) );
            $query->execute();
            $em->getFilters()->enable('softdeleteable');

            // Get displaytemplate controller to properly delete it this time
            self::deletedatatypeAction($ancestor_id, $request);
            print '---- ancestor deleted again'."\n";
        }
print '</pre>';
    }


    /**
     * Loads and returns the DesignTemplate HTML for this DataType.
     * 
     * @param integer $datatype_id The database id of the DataType to be rendered.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function designAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);

            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => self::GetDisplayData($request, $datatype_id),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38288399 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves changes made to the order of a Datatype's ThemeElements.
     * 
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function themeelementorderAction(Request $request)
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
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            // Grab the first theme element just to check permissions
            $theme_element = null;
            foreach ($post as $index => $theme_element_id) {
                $theme_element = $repo_theme_element->find($theme_element_id);
                break;
            }
            $datatype = $theme_element->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // If user has permissions, go through all of the theme elements setting the order
            foreach ($post as $index => $theme_element_id) {
                $theme_element = $repo_theme_element->find($theme_element_id);
                $em->refresh($theme_element);
    
                $theme_element->setDisplayOrder($index);
                $theme_element->setUpdatedBy($user);
    
                $em->persist($theme_element);
            }
            $em->flush();

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x8283002 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Updates the display order of DataFields inside a ThemeElement, and/or moves the DataField to a new ThemeElement.
     * 
     * @param integer $initial_theme_element_id The database id of the ThemeElement the DataField was in before being moved.
     * @param integer $ending_theme_element_id  The database id of the ThemeElement the DataField is in after being moved.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function datafieldorderAction($initial_theme_element_id, $ending_theme_element_id, Request $request)
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
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_theme_element_field = $em->getRepository('ODRAdminBundle:ThemeElementField');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            // Grab the first datafield just to check permissions
            $datafield = null;
            foreach ($post as $index => $datafield_id) {
                $datafield = $repo_datafield->find($datafield_id);
                break;
            }
            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // If user has permissions, go through all of the datafields setting the order
            $ending_theme_element = $repo_theme_element->find($ending_theme_element_id);
            foreach ($post as $index => $datafield_id) {
                // Grab the ThemeElementField entry that corresponds to this datafield
                $theme_element_field = $repo_theme_element_field->findOneBy( array('dataFields' => $datafield_id, 'themeElement' => $ending_theme_element_id) );    // theme_element implies theme
                if ($theme_element_field == null) {
                    // If it doesn't exist, then the datafield got moved to the ending theme_element...locate the 
                    $theme_element_field = $repo_theme_element_field->findOneBy( array('dataFields' => $datafield_id, 'themeElement' => $initial_theme_element_id) );

                    // Update the ThemeElementField entry to use the ending theme_element
                    $theme_element_field->setThemeElement($ending_theme_element);
                }

                $theme_element_field->setDisplayOrder($index);
                $theme_element_field->setUpdatedBy($user);

                $em->persist($theme_element_field);
            }
            $em->flush();

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x28268302 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles whether a ThemeElement container is visible only visible to users with the view permission for this datatype, or is visible to everybody viewing the record.
     * 
     * @param integer $theme_element_id The database id of the ThemeElement to modify.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function themeelementvisibleAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            $theme_element = $repo_theme_element->find($theme_element_id);
            if ( $theme_element == null )
                return parent::deletedEntityError('ThemeElement');
            $datatype = $theme_element->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Update and save the theme element
            if ( $theme_element->getDisplayInResults() == 0 ) {
                // Set to visible
                $theme_element->setDisplayInResults(1);

                // Need to ensure all datafields in this theme element have the viewable permission for all users...doesn't make sense otherwise
                $repo_user_field_permissions = $em->getRepository('ODRAdminBundle:UserFieldPermissions');

                // TODO - replace this with a DQL update?
                // Grab all ThemeElementField entities attached to this ThemeElement
                $theme_element_fields = $theme_element->getThemeElementField();
                foreach ($theme_element_fields as $tef) {
                    if ($tef->getDataFields() !== null) {
                        $datafield_id = $tef->getDataFields()->getId();
                        // Find all user permissions involving this datafield
                        $field_permissions = $repo_user_field_permissions->findBy( array('dataFields' => $datafield_id) );
                        foreach ($field_permissions as $permission) {
                            // Ensure the user can view the datafield
                            if ($permission->getCanViewField() == 0) {
                                $permission->setCanViewField('1');
                                $em->persist($permission);
                            }
                        }
                    }
                }
            }
            else { 
                // Set to not visible
                $theme_element->setDisplayInResults(0);

                // TODO - notify of users/groups that can still view this ThemeElement?
            }

            $theme_element->setUpdatedBy($user);

            $em->persist($theme_element);
            $em->flush();

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x77389299 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Deletes a ThemeElement from a DataType, after ensuring it's empty.
     * 
     * @param integer $theme_element_id The database id of the ThemeElement to delete.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function deletethemeelementAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Current User
            $em = $this->getDoctrine()->getManager();

            // Grab the theme element from the repository
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            $em->refresh($theme_element);
            if ( $theme_element == null )
                return parent::deletedEntityError('ThemeElement');

            $datatype = $theme_element->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Determine if the theme element holds anything
            $theme_element_fields = $em->getRepository('ODRAdminBundle:ThemeElementField')->findBy( array("themeElement" => $theme_element_id) );
            if ( count($theme_element_fields) == 0 ) {
                // Delete the theme element
                $em->remove($theme_element);
                $em->flush();

                // Schedule the cache for an update
                $options = array();
                $options['mark_as_updated'] = true;
                parent::updateDatatypeCache($datatype->getId(), $options);

            }
            else {
                // Notify of inability to remove this theme element
//                throw new \Exception("This ThemeElement can't be removed...it still contains datafields or a datatype!");

                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = "This ThemeElement can't be removed...it still contains datafields or a datatype!";
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x77392699 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves width changes made to a DataField in it's associated ThemeDataField entity.
     * 
     * @param integer $theme_datafield_id The database id of the ThemeDataField entity to change.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function savethemedatafieldAction($theme_datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->find($theme_datafield_id);
            $datatype = $theme_datafield->getDataFields()->getDataType();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Populate new ThemeDataField form
            $form = $this->createForm(new UpdateThemeDatafieldForm($theme_datafield), $theme_datafield);
            $values = array('med_width_old' => $theme_datafield->getCssWidthMed(), 'xl_width_old' => $theme_datafield->getCssWidthXL());

            if ($request->getMethod() == 'POST') {
                $form->bind($request, $theme_datafield);
                $return['t'] = "html";
                if ($form->isValid()) {
                    // Save the changes made to the datatype
                    $theme_datafield->setUpdatedBy($user);
                    $em->persist($theme_datafield);
                    $em->flush();

                    $em->refresh($theme_datafield);
                    $values['med_width_current'] = $theme_datafield->getCssWidthMed();
                    $values['xl_width_current'] = $theme_datafield->getCssWidthXL();

                    // Schedule the cache for an update
                    $options = array();
                    $options['mark_as_updated'] = true;
                    parent::updateDatatypeCache($datatype->getId(), $options);

                }
/*
                else {
                    throw new \Exception( parent::ODR_getErrorMessages($form) );
                }
*/
            }

            // Don't need to return a form object...it's loaded with the regular datafield properties form
            $return['widths'] = $values;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x82399100 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Saves changes made to a Datatree entity.
     * 
     * @param integer $datatype_id  The id of the DataType entity this Datatree belongs to
     * @param integer $datatree_id  The id of the Datatree entity being changed
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function savedatatreeAction($datatype_id, $datatree_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->find($datatree_id);
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);

            if ($datatree == null)
                return parent::deletedEntityError('Datatree');
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


            // Ensure that the datatree isn't set to only allow single child/linked datarecords when it already is supporting multiple child/linked datarecords
            $parent_datatype_id = $datatree->getAncestor()->getId();
            $child_datatype_id = $datatree->getDescendant()->getId();

            $force_multiple = false;
            $results = array();
            if ($datatree->getIsLink() == 0) {
                // Determine whether a datarecord of this datatype has multiple child datarecords...if so, then require the "multiple allowed" property of the datatree to remain true
                $query = $em->createQuery(
                   'SELECT parent.id AS ancestor_id, child.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS parent
                    JOIN ODRAdminBundle:DataRecord AS child WITH child.parent = parent
                    WHERE parent.dataType = :parent_datatype AND child.dataType = :child_datatype AND parent.id != child.id
                    AND parent.deletedAt IS NULL AND child.deletedAt IS NULL'
                )->setParameters( array('parent_datatype' => $parent_datatype_id, 'child_datatype' => $child_datatype_id) );
                $results = $query->getArrayResult();
            }
            else {
                // Determine whether a datarecord of this datatype is linked to multiple datarecords...if so, then require the "multiple allowed" property of the datatree to remain true
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor.dataType = :ancestor_datatype AND descendant.dataType = :descendant_datatype
                    AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters( array('ancestor_datatype' => $parent_datatype_id, 'descendant_datatype' => $child_datatype_id) );
                $results = $query->getArrayResult();
            }

            $tmp = array();
            foreach ($results as $num => $result) {
                $ancestor_id = $result['ancestor_id'];
                if ( isset($tmp[$ancestor_id]) ) {
                    $force_multiple = true;
                    break;
                }
                else {
                    $tmp[$ancestor_id] = 1;
                }
            }


            // Populate new Datatree form
            $datatree_form = $this->createForm(new UpdateDataTreeForm($datatree), $datatree);
            if ($request->getMethod() == 'POST') {
                $datatree_form->bind($request, $datatree);

                // Ensure that "multiple allowed" is true if required
                if ($force_multiple) 
                    $datatree->setMultipleAllowed(true);


                if ( $datatree_form->isValid() ) {
                    // Save the changes made to the datatype
                    $datatree->setUpdatedBy($user);
                    $em->persist($datatree);
                    $em->flush();

                    // Schedule the cache for an update
                    $options = array();
                    $options['mark_as_updated'] = true;
                    parent::updateDatatypeCache($datatype_id, $options);
                }
/*
                else {
                    throw new \Exception( parent::ODR_getErrorMessages($form) );
                }
*/
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x82392700 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds the wrapper for the slide-out properties menu on the right of the screen.
     * 
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function navslideoutAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $templating = $this->get('templating');
            $return['t'] = "html";
            $return['d'] = $templating->render(
                'ODRAdminBundle:Displaytemplate:nav_slideout.html.twig'
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x82394557 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Adds a new ThemeElement to the given DataType.
     * 
     * @param integer $datatype_id The database id of the DataType to attach a new ThemeElement to.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function addthemeelementAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
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
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Create a new theme element entity
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy(array("isDefault" => "1"));
            $theme_element = parent::ODR_addThemeElementEntry($em, $user, $datatype, $theme);

            // Save changes
            $em->flush();
            $em->refresh($theme_element);

            // Return the new theme element's id
            $return['d'] = array(
                'theme_element_id' => $theme_element->getId(),
            );

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x831225029 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Adds a new DataField to the given DataType and ThemeElement.
     * TODO - get $datatype_id from ThemeElement instead of parameters?
     * 
     * @param integer $datatype_id      The database id of the DataType that...
     * @param integer $theme_element_id The database id of the ThemeElement to...
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function adddatafieldAction($datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            $theme_element = $repo_theme_element->find($theme_element_id);
            if ( $theme_element == null )
                return parent::deletedEntityError('ThemeElement');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab objects required to create a datafield entity
            $fieldtype = $repo_fieldtype->findOneBy( array('typeName' => 'Short Text') );
            $renderplugin = $repo_render_plugin->find('1');
            // Create the datafield
            $datafield = parent::ODR_addDataFieldsEntry($em, $user, $datatype, $fieldtype, $renderplugin);

            // Tie the datafield to the theme element
            $theme_element_field = parent::ODR_addThemeElementFieldEntry($em, $user, null, $datafield, $theme_element);

            // Save changes
            $em->flush();

            // design_ajax.html.twig calls ReloadThemeElement()

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x898272332 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Copies the layout of an existing DataField into a new DataField Entity.
     * TODO - get $datatype_id from ThemeElement instead of parameters?
     *
     * @param integer $datatype_id      The database id of the DataType that...
     * @param integer $theme_element_id The database id of the ThemeElement that...
     * @param integer $datafield_id     The database id of the DataField to copy data from.
     * @param Request $request
     *
     * @return Response TODO
     */
    public function copydatafieldAction($datatype_id, $theme_element_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            $datatype = $repo_datatype->find($datatype_id);
            $theme_element = $repo_theme_element->find($theme_element_id);
            $old_datafield = $repo_datafield->find($datafield_id);

            if ( $datatype == null )
                return parent::deletedEntityError('DataType');
            if ( $theme_element == null )
                return parent::deletedEntityError('ThemeElement');
            if ( $old_datafield == null )
                return parent::deletedEntityError('DataField');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab necessary entities
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $repo_theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField');

            $render_plugin = $repo_render_plugin->find('1');            
            $theme = $repo_theme->find('1');
            $old_theme_datafield = $repo_theme_datafield->findOneBy( array('dataFields' => $old_datafield->getId(), 'theme' => 1) );


            // Create the new datafield and required theme element stuff
            $new_datafield = parent::ODR_addDataFieldsEntry($em, $user, $datatype, $old_datafield->getFieldType(), $render_plugin);
            $new_theme_element_field = parent::ODR_addThemeElementFieldEntry($em, $user, null, $new_datafield, $theme_element);
            $new_theme_datafield = parent::ODR_addThemeDataFieldEntry($em, $user, $new_datafield, $theme);

            // Copy widths of old datafield over to new datafield
            $new_theme_datafield->setCssWidthMed( $old_theme_datafield->getCssWidthMed() );
            $new_theme_datafield->setCssWidthXL( $old_theme_datafield->getCssWidthXL() );
            $em->persist($new_theme_datafield);

            $em->flush();

            // design_ajax.html.twig calls ReloadThemeElement()

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88138720 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Gets all RadioOptions associated with a DataField, for display in the right slideout.
     * 
     * @param integer $datafield_id The database if of the DataField to grab RadioOptions from.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function getradiooptionsAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
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


            // Grab radio options
            $radio_options = $datafield->getRadioOptions();

            // Render the template
            $return['t'] = 'html';
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:radio_option_list.html.twig',
                    array(
                        'datafield' => $datafield,
                        'radio_options' => $radio_options,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x022896256 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renames a given RadioOption.
     * 
     * @param integer $radio_option_id The database id of the RadioOption to rename.
     * @param Request $request
     *
     * @return Response TODO
     */
    public function radiooptionnameAction($radio_option_id, Request $request)
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
            if ( !isset($post['option_name']) )
                throw new \Exception('Invalid Form');

            $em = $this->getDoctrine()->getManager();
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($radio_option_id);
            if ( $radio_option == null )
                return parent::deletedEntityError('RadioOption');

            $datafield = $radio_option->getDataFields();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Update the radio option's name
            $new_name = trim($post['option_name']);
            if ($radio_option->getOptionName() !== $new_name) {
                // TODO - regexp validation on new name?

                $radio_option->setOptionName($new_name);
                $radio_option->setUpdatedBy($user);
                $em->persist($radio_option);
                $em->flush();
            }


            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            $search_theme_data_field = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield, 'theme' => 2) );   // TODO
            if ($search_theme_data_field !== null && $search_theme_data_field->getActive() == true)
                $options['force_shortresults_recache'] = true;

            parent::updateDatatypeCache($datatype->getId(), $options);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x034896256 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Updates the display order of the DataField's associated RadioOption entities.
     * 
     * @param integer $datafield_id      The database id of the DataField that is having its RadioOption entities sorted.
     * @param boolean $alphabetical_sort Whether to order the RadioOptions alphabetically or in some user-specified order.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function radiooptionorderAction($datafield_id, $alphabetical_sort, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions');

            $datafield = $repo_datafield->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            if ($alphabetical_sort == 1 ) {
                // (Re)sort all non-deleted radio options by their name
                $radio_options = $datafield->getRadioOptions();

                $all_radio_options = array();
                foreach ($radio_options as $radio_option)
                    $all_radio_options[ $radio_option->getOptionName() ] = $radio_option;

                ksort($all_radio_options);

                $index = 0;
                foreach ($all_radio_options as $name => $radio_option) {
//print $radio_option->getOptionName()."\n";

                    $em->refresh($radio_option);
                    $radio_option->setDisplayOrder($index);
                    $radio_option->setUpdatedBy($user);
                    $index++;

                    $em->persist($radio_option);
                }
            }
            else {
                // Otherwise, look to the $_POST for the new order
                $post = $_POST;
                foreach ($post as $index => $radio_option_id) {
                    $radio_option = $repo_radio_option->find($radio_option_id);
//print $radio_option->getOptionName()."\n";
                    $em->refresh($radio_option);

                    $radio_option->setDisplayOrder($index);
                    $radio_option->setUpdatedBy($user);

                    $em->persist($radio_option);
                }
            }
            $em->flush();

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x828463002 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Adds a new RadioOption entity to a SingleSelect, MultipleSelect, SingleRadio, or MultipleRadio DataField.
     * 
     * @param integer $datafield_id The database id of the DataField to add a RadioOption to.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function addradiooptionAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Create a new RadioOption
            $radio_option = parent::ODR_addRadioOption($em, $user, $datafield);
            $em->flush();
            $em->refresh($radio_option);

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            // NOTE - strictly speaking, this should force a shortresults recache, but the user is almost certainly going to rename the new option, so don't do it here
//            $search_theme_data_field = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield, 'theme' => 2) );
//            if ($search_theme_data_field !== null && $search_theme_data_field->getActive() == true)
//                $options['force_shortresults_recache'] = true;

            parent::updateDatatypeCache($datatype->getId(), $options);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x81282679 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Adds a new child DataType to this DataType.
     * 
     * @param integer $datatype_id      The database id of the DataType that will be the new DataType's parent.
     * @param integer $theme_element_id The database id of the ThemeElement that the new DataType will be rendered in.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function addchildtypeAction($datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';  // TODO - ??
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $parent_datatype = $repo_datatype->find($datatype_id);
            if ( $parent_datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $parent_datatype->getId() ]) && isset($user_permissions[ $parent_datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // Defaults
            $render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);

            // TODO - mostly duplicated with DataType controller...move this somewhere else?
            // Create the new child Datatype
            $datatype = new DataType();
            $datatype->setShortName("New Child");
            $datatype->setLongName("New Child");
            $datatype->setDescription("New Child Type");
            $datatype->setRenderPlugin($render_plugin);
            $datatype->setUseShortResults('1');
            $datatype->setExternalIdField(null);
            $datatype->setNameField(null);
            $datatype->setSortField(null);
            $datatype->setDisplayType(0);
            $datatype->setRevision(0);
            $datatype->setHasShortresults(false);
            $datatype->setHasTextresults(false);
            $datatype->setPublicDate(new \DateTime('1980-01-01 00:00:00'));
            $datatype->setCreatedBy($user);
            $datatype->setUpdatedBy($user);
            $em->persist($datatype);

            // Create a new DataTree entry to link the original datatype and this new child datatype
            $datatree = new DataTree();
            $datatree->setAncestor($parent_datatype);
            $datatree->setDescendant($datatype);
            $datatree->setCreatedBy($user); 
            $datatree->setUpdatedBy($user);
            $datatree->setIsLink(false);
            $datatree->setMultipleAllowed(true);
            $em->persist($datatree);


            // ----------------------------------------
            // Create a new ThemeElementField entry to let the renderer know it has to render a child datatype in this ThemeElement
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->findOneBy(array("id" => $theme_element_id));
            $em->refresh($theme_element);
            $theme_element_field = parent::ODR_addThemeElementFieldEntry($em, $user, $datatype, null, $theme_element);

            $em->flush();
            $em->refresh($datatype);
//            $em->refresh($datatree);
//            $em->refresh($theme_element_field);


            // ----------------------------------------
            // Copy the permissions this user has for the parent datatype to the new child datatype
            $query = $em->createQuery(
               'SELECT up
                FROM ODRAdminBundle:UserPermissions AS up
                WHERE up.user_id = :user_id AND up.dataType = :datatype'
            )->setParameters( array('user_id' => $user->getId(), 'datatype' => $parent_datatype->getId()) );
            $results = $query->getArrayResult();
            $parent_permission = $results[0];

            $user_permission = new UserPermissions();
            $user_permission->setDataType($datatype);
            $user_permission->setUserId($user);
            $user_permission->setCreatedBy($user);

            $user_permission->setCanEditRecord( $parent_permission['can_edit_record'] );
            $user_permission->setCanAddRecord( $parent_permission['can_add_record'] );
            $user_permission->setCanDeleteRecord( $parent_permission['can_delete_record'] );
            $user_permission->setCanViewType( $parent_permission['can_view_type'] );
            $user_permission->setCanDesignType( $parent_permission['can_design_type'] );
            $user_permission->setIsTypeAdmin(0);    // Child datatypes do not have is_type_admin permissions...

            $em->persist($user_permission);
            $em->flush();


            // ----------------------------------------
            // Clear memcached of all datatype permissions for all users...the entries will get rebuilt the next time they do something
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $user_manager = $this->container->get('fos_user.user_manager');
            $users = $user_manager->findUsers();
            foreach ($users as $user)
                $memcached->delete($memcached_prefix.'.user_'.$user->getId().'_datatype_permissions');


            // ----------------------------------------
            $return['d'] = array(
//                'theme_element_id' => $theme_element->getId(),
//                'html' => self::GetDisplayData($request, null, 'theme_element', $theme_element->getId()),
            );

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x832819234 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Gets a list of DataTypes that could serve as linked DataTypes.
     * 
     * @param integer $datatype_id      The database id of the DataType that is looking to link to another DataType...
     * @param integer $theme_element_id The database id of the ThemeElement that is/would be where the linked DataType rendered in this DataType...
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function getlinktypesAction($datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');
            $repo_theme_element_field = $em->getRepository('ODRAdminBundle:ThemeElementField');

            // Locate basic stuff
            $local_datatype = $repo_datatype->find($datatype_id);
            if ( $local_datatype == null )
                return parent::deletedEntityError('DataType');

            $theme_element = $repo_theme_element->find($theme_element_id);
            if ( $theme_element == null )
                return parent::deletedEntityError('ThemeElement');


            $theme_element_fields = $repo_theme_element_field->findBy(array("themeElement" => $theme_element_id));

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $local_datatype->getId() ]) && isset($user_permissions[ $local_datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Locate the previously linked datatype if it exists
            $previous_remote_datatype = NULL;
            foreach ($theme_element_fields as $tef) {
                if ($tef->getDataType() !== NULL)
                    $previous_remote_datatype = $tef->getDataType();
            }

            // Locate the parent of this datatype if it exists
            $datatree = $repo_datatree->findOneBy(array('descendant' => $datatype_id));
            $parent_datatype_id = NULL;
            if ($datatree !== NULL)
                $parent_datatype_id = $datatree->getAncestor()->getId();

            // Grab all datatypes and all datatree entries...need to locate the datatypes which can be linked to
            $linkable_datatypes = array();
            $datatype_entries = $repo_datatype->findBy( array("deletedAt" => NULL) );
            $datatree_entries = $repo_datatree->findBy( array("deletedAt" => NULL) );

            // Iterate through all the datatypes...
            foreach ($datatype_entries as $datatype_entry) {
                // TODO - ...
                // Prevent user from linking to a datatype they can't view
                if ( !isset($user_permissions[ $datatype_entry->getId() ]) || !isset($user_permissions[ $datatype_entry->getId() ]['view']) )
                    continue;

                $block = false;
                foreach ($datatree_entries as $datatree_entry) {
                    // If the datatype is a non-linked descendant of another datatype, block it from being linked to
                    if (($datatree_entry->getDescendant()->getId() === $datatype_entry->getId()) && ($datatree_entry->getIsLink() == false)) {
                        $block = true;
                        break;
                    }
                    // If the datatype is the ancestor of this datatype, block it from being linked to...don't want rendering recursion
                    // TODO - block datatype_a => datatype_b => datatype_c => datatype_a situations
                    if ($parent_datatype_id === $datatype_entry->getId()) {
                        $block = true;
                        break;
                    }
                }

                // If the datatype passes all the tests, and isn't the datatype that originally called this action, add it to the array
                if (!$block && $local_datatype->getId() !== $datatype_entry->getId())
                    $linkable_datatypes[] = $datatype_entry;
            }

            // Get Templating Object
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:link_type_dialog_form.html.twig',
                    array(
                        'local_datatype' => $local_datatype,
                        'remote_datatype' => $previous_remote_datatype,
                        'theme_element' => $theme_element,
                        'linkable_datatypes' => $linkable_datatypes
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x838179235 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Parses a $_POST request to create/delete a link from a 'local' DataType to a 'remote' DataType.
     * If linked, DataRecords of the 'local' DataType will have the option to link to DataRecords of the 'remote' DataType.
     * 
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function linktypeAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab the data from the POST request 
            $post = $_POST;
            $local_datatype_id = $post['local_datatype_id'];
            $remote_datatype_id = $post['selected_datatype'];
            $previous_remote_datatype_id = $post['previous_remote_datatype'];
            $theme_element_id = $post['theme_element_id'];

            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_linked_datatree = $em->getRepository('ODRAdminBundle:LinkedDataTree');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');
            $repo_theme_element_field = $em->getRepository('ODRAdminBundle:ThemeElementField');

            $local_datatype = $repo_datatype->find($local_datatype_id);
            if ( $local_datatype == null )
                return parent::deletedEntityError('DataType');

            $remote_datatype = null;
            if ($remote_datatype_id !== '')
                $remote_datatype = $repo_datatype->find($remote_datatype_id);   // Looking to create a link
            else
                $remote_datatype = $repo_datatype->find($previous_remote_datatype_id);   // Looking to remove a link

            if ( $remote_datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $local_datatype->getId() ]) && isset($user_permissions[ $local_datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");

            // TODO - ...
            // Prevent user from linking to a datatype they can't view
            if ( !(isset($user_permissions[ $remote_datatype->getId() ]) && $user_permissions[ $remote_datatype->getId() ]['view']) )
                return parent::permissionDeniedError('edit');
            // --------------------


            // Going to need id of theme element regardless
            $theme_element = $repo_theme_element->find($theme_element_id);
            if ( $theme_element == null )
                return parent::deletedEntityError('ThemeElement');
            $em->refresh($theme_element);

            $deleting = null;
            if ($remote_datatype_id !== '') {
                // Create a link between the two datatypes
                $deleting = false;

                $datatree = new DataTree();
                $datatree->setAncestor($local_datatype);
                $datatree->setDescendant($remote_datatype);
                $datatree->setCreatedBy($user);
                $datatree->setUpdatedBy($user);
                $datatree->setIsLink(true);
                $datatree->setMultipleAllowed(true);
                $em->persist($datatree);

                // Tie Field to ThemeElement
                $theme_element_field = parent::ODR_addThemeElementFieldEntry($em, $user, $remote_datatype, null, $theme_element);

                // Remove the previous link if necessary
                if ($previous_remote_datatype_id !== '') {
                    // Remove the datatree entry
                    $datatree = $repo_datatree->findOneBy( array("ancestor" => $local_datatype_id, "descendant" => $previous_remote_datatype_id) );
                    if ($datatree !== null)
                        $em->remove($datatree);

                    // Remove the linked datatree entries between these two datatypes as well
                    $linked_datatree = $repo_linked_datatree->findAll();
                    foreach ($linked_datatree as $ldt) {
                        if ($ldt->getAncestor()->getDataType()->getId() == $local_datatype_id && $ldt->getDescendant()->getDataType()->getId() == $previous_remote_datatype_id)
                            $em->remove($ldt);
                    }

                    // Remove the theme_element_field entry
                    $theme_element_field = $repo_theme_element_field->findOneBy( array("themeElement" => $theme_element_id, "dataType" => $previous_remote_datatype_id) );
                    if ($theme_element_field !== null)
                        $em->remove($theme_element_field);
                }
            }
            else {
                $deleting = true;

                // Remove the datatree entry
                $datatree = $repo_datatree->findOneBy( array("ancestor" => $local_datatype_id, "descendant" => $previous_remote_datatype_id) );
                if ($datatree !== null)
                    $em->remove($datatree);

                // Remove the linked datatree entries between these two datatypes as well
                $query = $em->createQuery(
                   'SELECT ldt
                    FROM ODRAdminBundle:LinkedDataTree AS ldt
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor.dataType = :ancestor_datatype AND descendant.dataType = :descendant_datatype
                    AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters( array('ancestor_datatype' => $local_datatype_id, 'descendant_datatype' => $previous_remote_datatype_id) );
                $results = $query->getResult();

                foreach ($results as $ldt) 
                    $em->remove($ldt);

                // Remove the theme_element_field entry
                $theme_element_field = $repo_theme_element_field->findOneBy( array("themeElement" => $theme_element_id, "dataType" => $previous_remote_datatype_id) );
                if ($theme_element_field !== null)
                    $em->remove($theme_element_field);
            }

            $em->flush();

            $using_linked_type = 1; // assume there will be a linked datatype
            if ($deleting)
                $using_linked_type = 0; // if a datatree entry was deleted, there is no longer a linked datatype

            if ($remote_datatype_id === '')
                $remote_datatype_id = $previous_remote_datatype_id;

            // Reload the theme element
            $return['d'] = array(
                'element_id' => $theme_element->getId(),
                'using_linked_type' => $using_linked_type,
                'linked_datatype_id' => $remote_datatype_id,
            );

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($local_datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x832819235 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds and returns a list of available Render Plugins for a DataType or a DataField.
     *
     * TODO - this currently only reads plugin list from the database
     * 
     * @param integer $datatype_id  The database id of the DataType that is potentially having its RenderPlugin changed, or null
     * @param integer $datafield_id The database id of the DataField that is potentially having its RenderPlugin changed, or null
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function plugindialogAction($datatype_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Pre-define...
            $datatype = null;
            $datafield = null;
            $current_render_plugin = null;
            $render_plugins = null;
            $render_plugin_instance = null;

            // If datafield id isn't defined, this is a render plugin for the entire datatype
            if ($datafield_id == 0) {
                // Locate basic stuff
                $datatype = $repo_datatype->find($datatype_id);
                if ( $datatype == null )
                    return parent::deletedEntityError('DataType');

                $current_render_plugin = $datatype->getRenderPlugin();

                // Grab available render plugins for this datatype
                $render_plugins = array();
                $all_render_plugins = $repo_render_plugin->findAll(); 
                foreach ($all_render_plugins as $plugin) {
                    if ($plugin->getPluginType() <= 2 && $plugin->getActive() == 1)  // 1: datatype only plugins...2: both...3: datafield only plutins
                        $render_plugins[] = $plugin;
                }

                // Attempt to grab the field mapping between this render plugin and this datatype
                $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $current_render_plugin, 'dataType' => $datatype) );

            }
            else {
                // This is a render plugin for a specific datafield
                // Locate basic stuff
                $datafield = $repo_datafields->find($datafield_id);
                if ( $datafield == null )
                    return parent::deletedEntityError('DataField');

                $datatype = $datafield->getDataType();
                if ( $datatype == null )
                    return parent::deletedEntityError('DataType');

                $current_render_plugin = $datafield->getRenderPlugin();

                // Grab available render plugins for this datafield
                $render_plugins = array();
                $all_render_plugins = $repo_render_plugin->findAll();
                foreach ($all_render_plugins as $plugin) {
                    if ($plugin->getPluginType() >= 2 && $plugin->getActive() == 1) // 1: datatype only plugins...2: both...3: datafield only plugins
                        $render_plugins[] = $plugin;
                }

                $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $current_render_plugin, 'dataField' => $datafield) );
            }

            // Get Templating Object
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:plugin_settings_dialog_form.html.twig',
                    array(
                        'local_datatype' => $datatype,
                        'local_datafield' => $datafield,
                        'render_plugins' => $render_plugins,
                        'render_plugin_instance' => $render_plugin_instance
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x233219235 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads and renders required DataFields and plugin options for the selected Render Plugin.
     * 
     * @param integer $datatype_id      The database id of the DataType that is potentially having its RenderPlugin changed, or null
     * @param integer $datafield_id     The database id of the DataField that is potentially having its RenderPlugin changed, or null
     * @param integer $render_plugin_id The database id of the RenderPlugin to look up.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function pluginsettingsAction($datatype_id, $datafield_id, $render_plugin_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // ----------------------------------------
            // Ensure the relevant entities exist
            $datatype = null;
            $datafield = null;
            $datafields = null; // of datatype

            if ($datafield_id == 0) {
                $datatype = $repo_datatype->find($datatype_id);
                if ( $datatype == null )
                    return parent::deletedEntityError('DataType');

                $datafields = $repo_datafields->findBy(array('dataType' => $datatype));
            }
            else {
                $datafield = $repo_datafields->find($datafield_id);
                if ( $datafield == null )
                    return parent::deletedEntityError('DataField');

                $datatype = $datafield->getDataType();
                if ( $datatype == null )
                    return parent::deletedEntityError('DataType');
            }

            $current_render_plugin = $repo_render_plugin->find($render_plugin_id);
            if ( $current_render_plugin == null )
                return parent::deletedEntityError('RenderPlugin');

            $tmp = $repo_fieldtype->findAll();
            $all_fieldtypes = array();
            foreach ($tmp as $fieldtype)
                $all_fieldtypes[$fieldtype->getId()] = $fieldtype;

            // ----------------------------------------
            // Attempt to grab the field mapping between this render plugin and this datatype/datafield
            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows, because we want to display old selected mappings/options

            $query = null;
            if ($datafield == null) {
                $query = $em->createQuery(
                   'SELECT rpi
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    WHERE rpi.renderPlugin = :renderPlugin AND rpi.dataType = :dataType'
                )->setParameters( array('renderPlugin' => $current_render_plugin, 'dataType' => $datatype) );
            }
            else {
                $query = $em->createQuery(
                   'SELECT rpi
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    WHERE rpi.renderPlugin = :renderPlugin AND rpi.dataField = :dataField'
                )->setParameters( array('renderPlugin' => $current_render_plugin, 'dataField' => $datafield) );
            }

            $results = $query->getResult();
            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            $render_plugin_instance = null;
            if ( count($results) > 0 )
                $render_plugin_instance = $results[0];

            $render_plugin_map = null;
            if ($render_plugin_instance != null)
                $render_plugin_map = $repo_render_plugin_map->findBy( array('renderPluginInstance' => $render_plugin_instance) );


            // ----------------------------------------
            // Get available plugins
            $plugin_list = $this->container->getParameter('odr_plugins');
                    
            // Parse plugin options
            $plugin_options = array();
            foreach($plugin_list as $plugin) {
                if ( $plugin['name'] == $current_render_plugin->getPluginName() ) {
                    $plugin_name = $plugin['filename'];
                    $file = $plugin_name . ".yml";
                    try {
                        $yaml = Yaml::parse( file_get_contents('../src'.$plugin['bundle_path'].'/Plugins/'.$file) );
                        $plugin_options[$plugin_name] = $yaml;
                    } catch (ParseException $e) {
                        //throw new Exception("Unable to parse the YAML string: %s", $e->getMessage());
                        $return['r'] = 1;
                        $return['t'] = 'ex';
                        $return['d'] = 'Error 0x878169125 ' . $e->getMessage();
                        $plugin_options = array();
                        break;
                    }
                }
            }
//print_r($plugin_options);


            // ----------------------------------------
            // Update properties of the render plugin based on file contents
            // TODO - actual update system for render plugins
            $available_options = array();
            $required_fields = array();
            foreach ($plugin_options as $plugin_classname => $plugin_data) {
                foreach ($plugin_data as $plugin_path => $data) {
                    // The foreach loops are just to grab keys/values...there should never be more than a single plugin's data in $plugin_options
                    $plugin_name = $data['name'];
                    $fields = $data['required_fields'];
                    $options = $data['config_options'];
                    $override_fields = $data['override_fields'];
                    $override_child = $data['override_child'];

                    $plugin = $repo_render_plugin->findOneBy( array('pluginName' => $plugin_name) );
                    $plugin_id = $plugin->getId();
   
                    $required_fields[$plugin_id] = $fields;
                    if ($required_fields[$plugin_id] == '')
                        $required_fields = null;

                    $available_options[$plugin_id] = $options;
                    if ($available_options[$plugin_id] == '')
                        $available_options = null;

                    $em->refresh($plugin);
                    $plugin->setOverrideFields($override_fields);
                    $plugin->setOverrideChild($override_child);
                    $em->persist($plugin);
                }
            }

            // Flatten the required_fields array read from the config file for easier use
            foreach ($required_fields as $plugin_id => $data) {
                foreach ($data as $field_key => $field_data) {
                    $field_name = $field_data['name'];
                    $required_fields[$field_name] = $field_data;
                }
                unset( $required_fields[$plugin_id] );
            }
//print_r($required_fields);
//print_r($available_options);


            // Ensure the config file and the database are synched in regards to required fields for this render plugin
            $allowed_fieldtypes = array();
            $current_render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $current_render_plugin) );
            foreach ($current_render_plugin_fields as $rpf) {
                $rpf_id = $rpf->getId();
                $rpf_fieldname = $rpf->getFieldName();

                // If the render_plugin_field does not exist in the list read from the config file
                if ( !isset($required_fields[$rpf_fieldname]) ) {
                    // Delete the entry from the database
//print 'Deleted RenderPluginFields "'.$field_name.'" for RenderPlugin "'.$current_render_plugin->getPluginName()."\"\n";
                    $em->remove($rpf);
                }
                else {
                    // render plugin field entry exists, update attributes from the config file
                    $field = $required_fields[$rpf_fieldname];

                    // Pull the entity's attributes from the config file
                    $field_name = $field['name'];
                    $description = $field['description'];
                    $fieldtypes = explode('|', $field['type']);

                    // Save the fieldtypes allowed for this field
                    $allowed_fieldtypes[$rpf_id] = array();
                    foreach ($fieldtypes as $fieldtype) {
                        $ft = $repo_fieldtype->findOneBy( array('typeClass' => $fieldtype) );
                        if ($ft == null)
                            throw new \Exception('RenderPlugin "'.$current_render_plugin->getPluginName().'" config: Invalid Fieldtype "'.$fieldtype.'" in list for field "'.$field_name.'"');

                        $allowed_fieldtypes[$rpf_id][] = $ft->getId();
                    }

                    // Ensure description and fieldtype are up to date
//                    $rpf->setFieldName($field_name);     // TODO - unable to update this attribute?
                    $rpf->setDescription($description);
                    $rpf->setAllowedFieldtypes( implode(',', $allowed_fieldtypes[$rpf_id]) );
//print 'Updated RenderPluginFields "'.$rpf->getFieldName().'" for RenderPlugin "'.$current_render_plugin->getPluginName()."\"\n";
                    $em->persist($rpf);

                    // Remove the element from the array of fields so it doesn't get added later
                    unset( $required_fields[$rpf_fieldname] );
                }
            }

//print_r($required_fields);

            // If any fields remain in the array, then they need to be added to the database
            foreach ($required_fields as $field) {
                // Pull the entity's attributes from the config file
                $field_name = $field['name'];
                $description = $field['description'];
                $fieldtypes = explode('|', $field['type']);

                // Create and save the new entity
                $rpf = new RenderPluginFields();
                $rpf->setFieldName($field_name);
                $rpf->setDescription($description);
                $rpf->setActive(1);
                $rpf->setCreatedBy($user);
                $rpf->setUpdatedBy($user);
                $rpf->setRenderPlugin($current_render_plugin);
                $rpf->setAllowedFieldtypes('');
//print 'Created new RenderPluginFields "'.$field_name.'" for RenderPlugin "'.$current_render_plugin->getPluginName()."\"\n";

                $em->persist($rpf);
                $em->flush();
                $em->refresh($rpf);

                // Save the fieldtypes allowed for this field
                $rpf_id = $rpf->getId();
                $allowed_fieldtypes[$rpf_id] = array();
                foreach ($fieldtypes as $fieldtype) {
                    $ft = $repo_fieldtype->findOneBy( array('typeClass' => $fieldtype) );
                    if ($ft == null)
                        throw new \Exception('RenderPlugin "'.$current_render_plugin->getPluginName().'" config: Invalid Fieldtype "'.$fieldtype.'" in list for field "'.$field_name.'"');

                    $allowed_fieldtypes[$rpf_id][] = $ft->getId();
                }
                $rpf->setAllowedFieldtypes( implode(',', $allowed_fieldtypes[$rpf_id]) );

                $em->persist($rpf);
                $em->flush();
                $em->refresh($rpf);
            }

            // Now that db and config file are synched, reload the required fields
            $em->flush();
            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $current_render_plugin) );

//print 'allowed fieldtypes: '.print_r($allowed_fieldtypes, true)."\n";

            // ----------------------------------------
            // Grab the current batch of settings for this instance of the render plugin
            $current_plugin_options = $repo_render_plugin_options->findBy( array('renderPluginInstance' => $render_plugin_instance) );

            if ( $return['r'] == 0 ) {
                $templating = $this->get('templating');
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Displaytemplate:plugin_settings_dialog_form_data.html.twig',
                        array(
                            'local_datatype' => $datatype,
                            'local_datafield' => $datafield,
                            'datafields' => $datafields,

                            'plugin' => $current_render_plugin,
                            'render_plugin_fields' => $render_plugin_fields,
                            'allowed_fieldtypes' => $allowed_fieldtypes,
                            'all_fieldtypes' => $all_fieldtypes,

                            'available_options' => $available_options,
                            'current_plugin_options' => $current_plugin_options,

                            'render_plugin_instance' => $render_plugin_instance,
                            'render_plugin_map' => $render_plugin_map
                        )
                    )
                );
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x878179125 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves settings changes made to a RenderPlugin for a DataType
     * 
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function saverenderpluginsettingsAction(Request $request)
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

            if ( !isset($post['local_datafield_id']) || !isset($post['local_datatype_id']) || !isset($post['render_plugin_instance_id']) || !isset($post['previous_render_plugin']) || !isset($post['selected_render_plugin']) )
                throw new \Exception('Invalid Form');

            $local_datatype_id = $post['local_datatype_id'];
            $local_datafield_id = $post['local_datafield_id'];
            $render_plugin_instance_id = $post['render_plugin_instance_id'];
            $previous_plugin_id = $post['previous_render_plugin'];
            $selected_plugin_id = $post['selected_render_plugin'];

            $plugin_fieldtypes = array();
            if ( isset($post['plugin_fieldtypes']) )
                $plugin_fieldtypes = $post['plugin_fieldtypes'];

            $plugin_map = array();
            if ( isset($post['plugin_map']) )
                $plugin_map = $post['plugin_map'];

            $plugin_options = array();
            if ( isset($post['plugin_options']) )
                $plugin_options = $post['plugin_options'];


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $local_datatype_id ]) && isset($user_permissions[ $local_datatype_id ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');

            $datatype = null;
            $datafield = null;
            $reload_datatype = false;

            if ($local_datafield_id == 0) {
                $datatype = $repo_datatype->find($local_datatype_id);
                if ( $datatype == null )
                    return parent::deletedEntityError('DataType');
            }
            else {
                $datafield = $repo_datafields->find($local_datafield_id);
                if ( $datafield == null )
                    return parent::deletedEntityError('DataField');
            }

            $render_plugin = $repo_render_plugin->find($selected_plugin_id);
            if ( $render_plugin == null )
                return parent::deletedEntityError('RenderPlugin');

            // 1: datatype only  2: both datatype and datafield  3: datafield only
            if ($render_plugin->getPluginType() == 1 && $datatype == null)
                throw new \Exception('Unable to save a Datatype plugin to a Datafield');
            else if ($render_plugin->getPluginType() == 3 && $datafield == null)
                throw new \Exception('Unable to save a Datafield plugin to a Datatype');
            else if ($render_plugin->getPluginType() == 2 && $datatype == null && $datafield == null)
                throw new \Exception('No target specified');


            // ----------------------------------------
            // Ensure the plugin map object is properly formed
            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $render_plugin) );
            foreach ($render_plugin_fields as $rpf) {
                $rpf_id = $rpf->getId();

                // Ensure all required datafields for this RenderPlugin are listed in the $_POST
                if ( !isset($plugin_map[$rpf_id]) )
                    throw new \Exception('Invalid Form...missing datafield mapping');
                // Ensure that all datafields marked as "new" have a fieldtype mapping
                if ($plugin_map[$rpf_id] == '-1' && !isset($plugin_fieldtypes[$rpf_id]) )
                    throw new \Exception('Invalid Form...missing fieldtype mapping');

                if ($plugin_map[$rpf_id] != '-1') {
                    // Ensure all required datafields have a valid fieldtype
                    $allowed_fieldtypes = $rpf->getAllowedFieldtypes();
                    $allowed_fieldtypes = explode(',', $allowed_fieldtypes);

                    // Ensure referenced datafields exist
                    $df = $repo_datafields->find($plugin_map[$rpf_id]);
                    if ($df == null)
                        throw new \Exception('Invalid Form...datafield does not exist');

                    // Ensure referenced datafields have a valid fieldtype for this renderpluginfield
                    $ft_id = $df->getFieldType()->getId();
                    if ( !in_array($ft_id, $allowed_fieldtypes) )
                        throw new \Exception('Invalid Form...attempting to map renderpluginfield to invalid fieldtype');
                }
            }

            // TODO - ensure plugin options are valid?


            // ----------------------------------------
            // Create any new datafields required
            $theme = $repo_theme->find(1);
            $search_theme = $repo_theme->find(2);   // TODO - other themes?

            $theme_element = null;
            foreach ($plugin_fieldtypes as $rpf_id => $ft_id) {
                // Since new datafields are being created, instruct ajax success handler in plugin_settings_dialog.html.twig to call ReloadChild() afterwards
                $reload_datatype = true;

                // Create a single new ThemeElement to store the new datafields in, if necessary
                if ($theme_element == null) {
                    $theme_element = parent::ODR_addThemeElementEntry($em, $user, $datatype, $theme);

                    $em->flush();
                    $em->refresh($theme_element);
                }

                // Load information for the new datafield
                $default_render_plugin = $repo_render_plugin->find(1);
                $fieldtype = $repo_fieldtype->find($ft_id);
                if ($fieldtype == null)
                    throw new \Exception('Invalid Form');
                $rpf = $repo_render_plugin_fields->find($rpf_id);


                // Create the Datafield
                $df = parent::ODR_addDataFieldsEntry($em, $user, $datatype, $fieldtype, $default_render_plugin);
                $df->setFieldName( $rpf->getFieldName() );
                $df->setDescription( $rpf->getDescription() );
                $em->persist($df);

                // Also need to create a ThemeElementField...
                $theme_element_field = parent::ODR_addThemeElementFieldEntry($em, $user, null, $df, $theme_element);

                // ...and a ThemeDataField
                parent::ODR_addThemeDataFieldEntry($em, $user, $df, $theme);
                parent::ODR_addThemeDataFieldEntry($em, $user, $df, $search_theme);

                $em->flush();

                // Now that the datafield exists, update the plugin map
                $em->refresh($df);
                $plugin_map[$rpf_id] = $df->getId();
            }


            // ----------------------------------------
            // Mark the Datafield/Datatype as using the selected RenderPlugin
            // 1: datatype only  2: both datatype and datafield  3: datafield only
            if ($render_plugin->getPluginType() <= 2 && $datatype != null) {
                $datatype->setRenderPlugin($render_plugin);
                $datatype->setUpdatedBy($user);
                $em->persist($datatype);
            }
            else if ($render_plugin->getPluginType() >= 2 && $datafield != null) {
                $datafield->setRenderPlugin($render_plugin);
                $datafield->setUpdatedBy($user);
                $em->persist($datafield);
            }


            // ...delete the old render plugin instance object if we changed render plugins
            $render_plugin_instance = null;
            if ($render_plugin_instance_id != '')
                $render_plugin_instance = $repo_render_plugin_instance->findOneBy(array("id" => $render_plugin_instance_id));

            if ( $previous_plugin_id != $selected_plugin_id && $render_plugin_instance != null)
                $em->remove($render_plugin_instance);


            // See if there's a previous render_plugin_instance that matches this datatype and selected plugin id
            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows
            $query = null;
            if ($render_plugin->getPluginType() <= 2 && $datatype != null) {
                $query = $em->createQuery(
                   'SELECT rpi
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    WHERE rpi.renderPlugin = :renderPlugin AND rpi.dataType = :dataType'
                )->setParameters( array('renderPlugin' => $render_plugin, 'dataType' => $datatype) );
            }
            else if ($render_plugin->getPluginType() >= 2 && $datafield != null) {
                $query = $em->createQuery(
                   'SELECT rpi
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    WHERE rpi.renderPlugin = :renderPlugin AND rpi.dataField = :dataField'
                )->setParameters( array('renderPlugin' => $render_plugin, 'dataField' => $datafield) );
            }

            $results = $query->getResult();
            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            if ( count($results) > 0 ) {
                // Un-delete the previous render plugin instance and use that
                $render_plugin_instance = $results[0];
                $render_plugin_instance->setDeletedAt(null);
                $em->persist($render_plugin_instance);
                $em->flush();
            }


            // ----------------------------------------
            if ($render_plugin->getId() != 1) {
                // If not using the default RenderPlugin, create a RenderPluginInstance if no previous one exists
                if ($render_plugin_instance == null) {
                    $render_plugin_instance = new RenderPluginInstance();
                    $render_plugin_instance->setRenderPlugin($render_plugin);

                    if ($render_plugin->getPluginType() == 1 && $datatype != null) {
                        $render_plugin_instance->setDataType($datatype);
                    }
                    else if ($render_plugin->getPluginType() == 3 && $datafield != null) {
                        $render_plugin_instance->setDataField($datafield);
                    }
                    else {
                        throw new \Exception("Invalid type settings.");
                    }

                    $render_plugin_instance->setActive(1);
                    $render_plugin_instance->setCreatedBy($user);
                    $render_plugin_instance->setUpdatedBy($user);
                    $em->persist($render_plugin_instance);
                    $em->flush();
                }

                // Save the field mapping
                foreach ($plugin_map as $rpf_id => $df_id) {
                    // Attempt to locate the mapping for this field in this instance
                    $render_plugin_map = $repo_render_plugin_map->findOneBy( array('renderPluginInstance' => $render_plugin_instance, 'renderPluginFields' => $rpf_id) );

                    // If it doesn't exist, create it
                    if ($render_plugin_map == null) {
                        $render_plugin_map = new RenderPluginMap();
                        $render_plugin_map->setRenderPluginInstance($render_plugin_instance);
                        $render_plugin_map->setDataType($datatype);
                        $render_plugin_map->setCreatedBy($user);
                    }
                    $render_plugin_map->setUpdatedBy($user);

                    // Locate the correct render plugin field object
                    $render_plugin_field = $repo_render_plugin_fields->find($rpf_id);
                    $render_plugin_map->setRenderPluginFields($render_plugin_field);

                    // Locate the correct datafield object
                    $df = $repo_datafields->find($df_id);
                    $render_plugin_map->setDataField($df);

                    $em->persist($render_plugin_map);
                }

                // Save the plugin options
                foreach ($plugin_options as $option_name => $option_value) {
                    // Attempt to locate this particular render plugin option in this instance
                    $render_plugin_option = $repo_render_plugin_options->findOneBy( array('renderPluginInstance' => $render_plugin_instance, 'optionName' => $option_name) );

                    // If it doesn't exist, create it
                    if ($render_plugin_option == null) {
                        $render_plugin_option = new RenderPluginOptions();
                        $render_plugin_option->setRenderPluginInstance($render_plugin_instance);
                        $render_plugin_option->setOptionName($option_name);
                        $render_plugin_option->setActive(1);
                        $render_plugin_option->setCreatedBy($user);
                    }

                    $render_plugin_option->setOptionValue($option_value);
                    $render_plugin_option->setUpdatedBy($user);

                    $em->persist($render_plugin_option);
                }
            }

            $em->flush();

            $return['d'] = array(
                'datafield_id' => $local_datafield_id,
                'datatype_id' => $local_datatype_id,
                'render_plugin_id' => $render_plugin->getId(),
                'render_plugin_name' => $render_plugin->getPluginName(),
                'html' => '',

                'reload_datatype' => $reload_datatype,
            );

            // Schedule the cache for an update
            if ($datatype == null)
                $datatype = $datafield->getDataType();

            $options = array();
            $options['mark_as_updated'] = true;

            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x838328235 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a child DataType div in the design.
     *
     * @param integer $datatype_id The database id of the child DataType that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response TODO
     */
    public function reloadchildAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $return['d'] = array(
                'datatype_id' => $datatype_id,
                'html' => self::GetDisplayData($request, $datatype_id, 'child'),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x817913259' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a ThemeElement in the design.
     *
     * @param integer $theme_element_id The database id of the ThemeElement that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response TODO
     */
    public function reloadthemeelementAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $datatype_id = null;
            $return['d'] = array(
                'theme_element_id' => $theme_element_id,
                'html' => self::GetDisplayData($request, $datatype_id, 'theme_element', $theme_element_id),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x817913260' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a DataField in the design.
     *
     * @param integer $datafield_id THe database id of the DataField that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response TODO
     */
    public function reloaddatafieldAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $datatype_id = null;
            $return['d'] = array(
                'datafield_id' => $datafield_id,
                'html' => self::GetDisplayData($request, $datatype_id, 'datafield', $datafield_id),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x817913261 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns the HTML for a DesignTemplate version of a DataType.
     *
     * @param Request $request
     * @param integer $datatype_id  Which datatype needs to be re-rendered...if $template_name == 'child', this is the id of a child datatype
     * @param string $template_name One of 'default', 'child', 'theme_element', 'datafield'
     * @param integer $other_id     If $template_name == 'theme_element', $other_id is a theme_element id...if $template_name == 'datafield', $other_id is a datafield id
     *
     * @return string
     */
    private function GetDisplayData(Request $request, $datatype_id, $template_name = 'default', $other_id = null)
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');
        $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);
        $templating = $this->get('templating');

        $user = $this->container->get('security.context')->getToken()->getUser();

        $datatype = null;
        $theme_element = null;
        if ($datatype_id !== null) {
            $datatype = $repo_datatype->find($datatype_id);
        }
        else if ($template_name == 'theme_element' && $other_id !== null) {
            $theme_element = $repo_theme_element->find($other_id);
            $datatype = $theme_element->getDataType();
        }
        else if ($template_name == 'datafield' && $other_id !== null) {
            $datafield = $repo_datafield->find($other_id);
            $datatype = $datafield->getDataType();
        }

        $indent = 0;
        $is_link = 0;
        $top_level = 1;
        $short_form = false;

        if ($template_name == 'child') {
            // Determine if this is a 'child' render request for a top-level datatype
            $query = $em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataTree dt
                WHERE dt.is_link = 0 AND dt.deletedAt IS NULL AND dt.descendant = :datatype'
            )->setParameters( array('datatype' => $datatype_id) );
            $results = $query->getResult();

            // If query found something nothing, then it's a top-level datatype
            if ( count($results) == 0 )
                $top_level = 1;
            else
                $top_level = 0;
        }
        else if ($template_name == 'theme_element') {
            $is_link = 0;
            $top_level = 0; // not going to be in a situation where this equal to 1 ever?
        }


        $html = null;
        if ($template_name !== 'datafield') {
$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

            $tree = parent::buildDatatypeTree($user, $theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);

if ($debug)
    print '</pre>';

            // Potentially need to warn user when changing fieldtype would cause loss of data
            $datarecords = $em->getRepository('ODRAdminBundle:DataRecord')->findOneByDataType($datatype);
            $has_datarecords = false;
            if ($datarecords != null)
                $has_datarecords = true;

            $template = 'ODRAdminBundle:Displaytemplate:design_ajax.html.twig';
            if ($template_name == 'child')
                $template = 'ODRAdminBundle:Displaytemplate:design_area_child_load.html.twig';
            else if ($template_name == 'theme_element')
                $template = 'ODRAdminBundle:Displaytemplate:design_area_fieldarea.html.twig';
            
            $html = $templating->render(
                $template,
                array(
                    'datatype_tree' => $tree,
                    'has_datarecords' => $has_datarecords,
                    'theme' => $theme,
                )
            );
        }
        else {
            // Rendering a datafield doesn't require the entire tree...
            $em->refresh($datafield);
            parent::ODR_checkThemeDataField($user, $datafield, $theme);

            $query = $em->createQuery(
               'SELECT tdf
                FROM ODRAdminBundle:ThemeDataField tdf
                WHERE tdf.dataFields = :datafield AND tdf.theme = :theme AND tdf.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId(), 'theme' => $theme->getId()) );
            $result = $query->getResult();
            $theme_datafield = $result[0];
            $em->refresh($theme_datafield);

            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:design_area_datafield.html.twig',
                array(
                    'fieldtheme' => $theme_datafield,
                    'field' => $datafield,
                )
            );
        }

        return $html;
    }


    /**
     * Loads/saves a Symfony DataType properties Form.
     * 
     * @param integer $datatype_id       The database id of the Datatype that is being modified
     * @param mixed $parent_datatype_id  Either the id of the Datatype of the parent of $datatype_id, or the empty string
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function datatypepropertiesAction($datatype_id, $parent_datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
//print_r( $_POST );
//return;
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // If $parent_datatype_id is set, locate the datatree linking $datatype_id and $parent_datatype_id
            $datatree = null;
            if ($parent_datatype_id !== '') {
                $query = $em->createQuery(
                   'SELECT dt
                    FROM ODRAdminBundle:DataTree AS dt
                    WHERE dt.ancestor = :ancestor AND dt.descendant = :descendant
                    AND dt.deletedAt IS NULL'
                )->setParameters( array('ancestor' => $parent_datatype_id, 'descendant' => $datatype_id) );
                $results = $query->getResult();

                if ( isset($results[0]) )
                    $datatree = $results[0];
            }

            // Create required forms
            $datatype_form = $this->createForm(new UpdateDataTypeForm($datatype), $datatype);

            $force_multiple = false;
            $datatree_form = null;
            if ($datatree !== null) {
                $datatree_form = $this->createForm(new UpdateDataTreeForm($datatree), $datatree);
                $datatree_form = $datatree_form->createView();

                $results = array();
                if ($datatree->getIsLink() == 0) {
                    // Determine whether a datarecord of this datatype has multiple child datarecords...if so, then require the "multiple allowed" property of the datatree to remain true
                    $query = $em->createQuery(
                       'SELECT parent.id AS ancestor_id, child.id AS descendant_id
                        FROM ODRAdminBundle:DataRecord AS parent
                        JOIN ODRAdminBundle:DataRecord AS child WITH child.parent = parent
                        WHERE parent.dataType = :parent_datatype AND child.dataType = :child_datatype AND parent.id != child.id
                        AND parent.deletedAt IS NULL AND child.deletedAt IS NULL'
                    )->setParameters( array('parent_datatype' => $parent_datatype_id, 'child_datatype' => $datatype_id) );
                    $results = $query->getArrayResult();
                }
                else {
                    // Determine whether a datarecord of this datatype is linked to multiple datarecords...if so, then require the "multiple allowed" property of the datatree to remain true
                    $query = $em->createQuery(
                       'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                        FROM ODRAdminBundle:DataRecord AS ancestor
                        JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                        JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                        WHERE ancestor.dataType = :ancestor_datatype AND descendant.dataType = :descendant_datatype
                        AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                    )->setParameters( array('ancestor_datatype' => $parent_datatype_id, 'descendant_datatype' => $datatype_id) );
                    $results = $query->getArrayResult();
                }

                $tmp = array();
                foreach ($results as $num => $result) {
                    $ancestor_id = $result['ancestor_id'];
                    if ( isset($tmp[$ancestor_id]) ) {
                        $force_multiple = true;
                        break;
                    }
                    else {
                        $tmp[$ancestor_id] = 1;
                    }
                }
            }

            if ($request->getMethod() == 'POST') {
/*
                // Check to see if external_id/name/sort field got changed
                $post = $request->request->all();
                $post = $post['UpdateDataTypeForm'];

                $external_id_field = $post['externalIdField'];
                $namefield = $post['nameField'];
                $sortfield = $post['sortField'];

                $old_external_field = $datatype->getExternalIdField();
                if ($old_external_field !== null)
                    $old_external_field = strval($old_external_field->getId());
                $old_namefield = $datatype->getNameField();
                if ($old_namefield !== null)
                    $old_namefield = strval($old_namefield->getId());
                $old_sortfield = $datatype->getSortField();
                if ($old_sortfield !== null)
                    $old_sortfield = strval($old_sortfield->getId());
*/
                // Bind the changes to the datatype
                $datatype_form->bind($request, $datatype);
                $return['t'] = "html";
                if ($datatype_form->isValid()) {
/*
                    // If any of the external/name/sort datafields got changed, clear the relevant cache fields for datarecords of this datatype
                    if ($old_external_field !== $external_id_field) {
                        $query = $em->createQuery('UPDATE ODRAdminBundle:DataRecord AS dr SET dr.external_id = NULL WHERE dr.dataType = :datatype')->setParameters( array('datatype' => $datatype_id) );
                        $num_updated = $query->execute();
                    }
                    if ($old_namefield !== $namefield) {
                        $query = $em->createQuery('UPDATE ODRAdminBundle:DataRecord AS dr SET dr.namefield_value = NULL WHERE dr.dataType = :datatype')->setParameters( array('datatype' => $datatype_id) );
                        $num_updated = $query->execute();
                    }
                    if ($old_sortfield !== $sortfield) {
                        $memcached->delete($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');

                        $query = $em->createQuery('UPDATE ODRAdminBundle:DataRecord AS dr SET dr.sortfield_value = NULL WHERE dr.dataType = :datatype')->setParameters( array('datatype' => $datatype_id) );
                        $num_updated = $query->execute();
                    }
*/
                    // Save the changes made to the datatype
                    $datatype->setUpdatedBy($user);
                    $em->persist($datatype);
                    $em->flush();

                    // Schedule the cache for an update
                    $options = array();
                    $options['mark_as_updated'] = true;

                    parent::updateDatatypeCache($datatype->getId(), $options);
                }
            }
                    
            $templating = $this->get('templating');
            $return['d'] = $templating->render(
                'ODRAdminBundle:Displaytemplate:datatype_properties_form.html.twig', 
                array(
                    'datatype' => $datatype,
                    'datatype_form' => $datatype_form->createView(),

                    'datatree' => $datatree,
                    'datatree_form' => $datatree_form,
                    'force_multiple' => $force_multiple,
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2838920 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Loads/saves a Symfony DataFields properties Form.
     * 
     * @param integer $datafield_id The database id of the DataField being modified.
     * @param Request $request
     *
     * @return Response TODO
     */
    public function datafieldpropertiesAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab objects
            $em = $this->getDoctrine()->getManager();
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $em->refresh($datafield);
            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // --------------------
            // Need to immediately force a reload of the right design slideout if certain fieldtypes change
            $force_slideout_reload = false;
            
            // --------------------
            // Determine if shortresults may need to be recached
            $force_shortresults_recache = false;
            $search_theme_data_field = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield, 'theme' => 2) );   // TODO
            if ($search_theme_data_field !== null && $search_theme_data_field->getActive() == true)
                $force_shortresults_recache = true;


            // --------------------
            // Get relevant theme_datafield entry
            $query = $em->createQuery(
               'SELECT tdf
                FROM ODRAdminBundle:ThemeDataField AS tdf
                WHERE tdf.theme = 1 AND tdf.dataFields = :datafield
                AND tdf.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId()) );
            $result = $query->getResult();
            $theme_datafield = $result[0];


            // --------------------
            // Check to see whether the "allow multiple uploads" checkbox for file/image control needs to be disabled
            $has_multiple_uploads = 0;
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass == 'File' || $typeclass == 'Image') {
                // Count how many files/images are attached to this datafield across all datarecords
                $str = 
                    'SELECT COUNT(e.dataRecord)
                    FROM ODRAdminBundle:'.$typeclass.' e
                    JOIN ODRAdminBundle:DataFields AS df WITH e.dataField = df
                    JOIN ODRAdminBundle:DataRecord AS dr WITH e.dataRecord = dr
                    WHERE e.deletedAt IS NULL AND dr.deletedAt IS NULL AND df.id = :datafield';
                    if ($typeclass == 'Image') 
                        $str .= ' AND e.original = 1 ';
                    $str .= ' GROUP BY dr.id';
                $query = $em->createQuery($str)->setParameters( array('datafield' => $datafield) );
                $results = $query->getResult();

//print print_r($results, true);

                foreach ($results as $result) {
                    if ( $result[1] > 1 ) {
                        // If $result[1] > 1, then multiple files/images are attached to this datafield...
                        if ( $datafield->getAllowMultipleUploads() == 0 ) {
                            // This datafield somehow managed to acquire multiple uploads without being set as such...fix that
                            $datafield->setAllowMultipleUploads(true);
                            $datafield->setDisplayOrder(-1);
                            $em->persist($datafield);
                            $em->flush();
                        }
                        $has_multiple_uploads = 1;
                        break;
                    }
                }
            }


            // --------------------
            // Prevent a datafield's fieldtype from being changed if it's being used by a render plugin
            $prevent_fieldtype_change = false;
            $prevent_fieldtype_change_message = '';
            $render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap')->findAll();
            foreach ($render_plugin_map as $rpm) {
                if ($rpm->getDataField()->getId() == $datafield_id) {
                    // Ensure that this isn't a leftover field from a deleted plugin instance
                    $rpi = $em->getRepository('ODRAdminBundle:RenderPluginInstance')->findOneBy( array('id' => $rpm->getRenderPluginInstance()->getId()) );
                    if ($rpi !== null) {
                        $prevent_fieldtype_change = true;
                        $prevent_fieldtype_change_message = "The Fieldtype can't be changed because the Datafield is currently being used by a RenderPlugin.";
                        break;
                    }
                }
            }

            // Also prevent a datatfield's fieldtype from being changed if a migration is in progress
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'target_entity' => 'datafield_'.$datafield->getId(), 'completed' => null) );
            if ($tracked_job !== null) {
                $prevent_fieldtype_change = true;
                $prevent_fieldtype_change_message = "The Fieldtype can't be changed because the server hasn't finished migrating this Datafield's data to the currently displayed Fieldtype.";
            }

            // Also prevent a fieldtype change if the datafield is marked as unique
            if ($datafield->getIsUnique() == true) {
                $prevent_fieldtype_change = true;
                $prevent_fieldtype_change_message = "The Fieldtype can't be changed because the Datafield is currently marked as Unique.";
            }

            // --------------------
            // Populate new DataFields form
            $datafield_form = $this->createForm(new UpdateDatafieldsForm(), $datafield);
            $theme_datafield_form = $this->createForm(new UpdateThemeDatafieldForm(), $theme_datafield);
            if ($request->getMethod() == 'POST') {

                // --------------------
                // Deal with change of fieldtype
                $old_fieldtype = $new_fieldtype = null;
                $migrate_data = false;
                $extra_fields = $request->request->get('DataFieldsForm');
                $normal_fields = $request->request->get('DatafieldsForm');
                if ( isset($extra_fields['previous_field_type']) && isset($normal_fields['field_type']) ) {
                    $old_fieldtype_id = $extra_fields['previous_field_type'];
                    $new_fieldtype_id = $normal_fields['field_type'];

                    // If not allowed to change fieldtype...
                    if ($prevent_fieldtype_change) {
                        $new_fieldtype_id = $old_fieldtype_id;
                    }

                    // Determine if we need to migrate the data over to the new fieldtype
                    if ($old_fieldtype_id !== $new_fieldtype_id) {
                        // Grab necessary objects
                        $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
                        $old_fieldtype = $repo_fieldtype->find($old_fieldtype_id);
                        $new_fieldtype = $repo_fieldtype->find($new_fieldtype_id);

                        // Ensure fieldtype got changed from something that is migrate-able...
                        $migrate_data = true;
                        switch ($old_fieldtype->getTypeClass()) {
                            case 'IntegerValue':
                            case 'LongText':
                            case 'LongVarchar':
                            case 'MediumVarchar':
                            case 'ShortVarchar':
                            case 'DecimalValue':
                                break;

                            default:
                                $migrate_data = false;
                                break;
                        }
                        // ...to something that is also migrate-able
                        switch ($new_fieldtype->getTypeClass()) {
                            case 'IntegerValue':
                            case 'LongText':
                            case 'LongVarchar':
                            case 'MediumVarchar':
                            case 'ShortVarchar':
                            case 'DecimalValue':
                                break;
                            default:
                                $migrate_data = false;
                                break;
                        }

                        // Need to "migrate" data when going from Multiple radio/select to Single radio/select...
                        $old_typename = $old_fieldtype->getTypeName();
                        $new_typename = $new_fieldtype->getTypeName();
                        if ( ($old_typename == 'Multiple Select' || $old_typename == 'Multiple Radio') && ($new_typename == 'Single Select' || $new_typename == 'Single Radio') ) {
                            $migrate_data = true;
                        }

                        // If fieldtype got changed to/from Markdown, File, Image, or Radio...force a reload of the right slideout, because options on that slideout are different for these fieldtypes
                        switch ($old_fieldtype->getTypeClass()) {
                            case 'Radio':
                            case 'File':
                            case 'Image':
                            case 'Markdown':
                                $force_slideout_reload = true;
                                break;
                        }
                        switch ($new_fieldtype->getTypeClass()) {
                            case 'Radio':
                            case 'File':
                            case 'Image':
                            case 'Markdown':
                                $force_slideout_reload = true;
                                break;
                        }
                    }
                }

                // Save old fieldtype incase it somehow got changed when it shouldn't...
                $old_fieldtype = $datafield->getFieldType();
                $old_fieldname = $datafield->getFieldName();
                $old_shortenfilename = $datafield->getShortenFilename();
                $old_allowmultipleuploads = $datafield->getAllowMultipleUploads();

                // Deal with the rest of the form
                $datafield_form->bind($request, $datafield);

                // If not allowed to change fieldtype, ensure the datafield always has the old fieldtype
                if ($prevent_fieldtype_change) {
                    $datafield->setFieldType( $old_fieldtype );
                    $migrate_data = false;
                    $force_slideout_reload = false;
                }

                // If the datafield got set to unique...
                if ( isset($normal_fields['is_unique']) && $normal_fields['is_unique'] == 1 ) {
                    // ...if it has duplicate values, manually add an error to the Symfony form...this will conveniently cause the subsequent isValid() call to fail
                    if ( !self::datafieldCanBeUnique($datafield) )
                        $datafield_form->addError( new FormError("This Datafield can't be set to 'unique' because some Datarecords have duplicate values stored in this Datafield...click the gear icon to list which ones.") ); 
                }

/*
$errors = parent::ODR_getErrorMessages($datafield_form);
print_r($errors);
*/

                // --------------------
                $return['t'] = "html";
                if ($datafield_form->isValid()) {

                    // If datafield is being used as the datatype's external ID field, ensure it's marked as unique
                    if ( $datafield->getDataType()->getExternalIdField() !== null && $datafield->getDataType()->getExternalIdField()->getId() == $datafield->getId() )
                        $datafield->setIsUnique(true);

                    // ----------------------------------------
                    // Easier to deal with change of fieldtype and how it relates to searchable here
                    switch ($datafield->getFieldType()->getTypeClass()) {
                        case 'DecimalValue':
                        case 'IntegerValue':
                        case 'LongText':
                        case 'LongVarchar':
                        case 'MediumVarchar':
                        case 'ShortVarchar':
                        case 'Radio':
                            // All of the above fields can have any value for searchable
                            break;

                        case 'Image':
                        case 'File':
                        case 'Boolean':
                        case 'DatetimeValue':
                            // It only makes sense for these four fieldtypes to be searchable from advanced search
                            if ($datafield->getSearchable() == 1)
                                $datafield->setSearchable(2);
                            break;

                        default:
                            // All other fieldtypes can't be searched
                            $datafield->setSearchable(0);
                            break;
                    }

                    // ----------------------------------------
                    // Reset the datafield's displayOrder if it got changed to a fieldtype that can't go in TextResults
                    $update_field_order = false;
                    if ($new_fieldtype !== null) {
                        switch ( $new_fieldtype->getTypeName() ) {
                            case 'Image':
                            case 'Multiple Radio':
                            case 'Multiple Select':
                            case 'Markdown':
                                // Datafields with these fieldtypes can't be in TextResults
                                $datafield->setDisplayOrder(-1);
                                $update_field_order = true;
                                break;

                            case 'File':
                                // File datafields can be in TextResults if they're only allowed to have a single upload
                                if ( $datafield->getAllowMultipleUploads() == '1' ) {
                                    $datafield->setDisplayOrder(-1);
                                    $update_field_order = true;
                                }
                                break;

                            default:
                                // The remaining fieldtypes have no restrictions on being in TextResults
                                break;
                        }
                    }

                    // Ensure a File datafield isn't in TextResults if it is set to allow multiple uploads
                    if ( $old_allowmultipleuploads == '0' && $datafield->getAllowMultipleUploads() == '1' ) {
                        $datafield->setDisplayOrder(-1);
                        $update_field_order = true;
                    }


                    // ----------------------------------------
                    // If this field is in shortresults, do another check to determine whether the shortresults needs to be recached
                    if ($force_shortresults_recache) {
                        if ( $old_fieldname != $datafield->getFieldName() || $old_shortenfilename != $datafield->getShortenFilename() )
                            $force_shortresults_recache = true;
                        else
                            $force_shortresults_recache = false;
                    }

                    $datafield->setUpdatedBy($user);
                    $em->persist($datafield);
                    $em->flush();

                    $em->refresh($datafield);

                    //
                    if ($datafield->getRadioOptionNameSort() == true) {
                        self::radiooptionorderAction($datafield->getId(), true, $request);  // TODO - might be race condition issue with design_ajax
                    }

                    if ( $update_field_order )
                        self::updateTextResultsFieldOrder($em, $datatype, $datafield);


                    // ----------------------------------------
                    // If datafields are getting migrated, then the datatype will get updated
                    if ($migrate_data) {
                        // Grab necessary stuff for pheanstalk...
                        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
                        $api_key = $this->container->getParameter('beanstalk_api_key');
                        $pheanstalk = $this->get('pheanstalk');

                        $url = $this->container->getParameter('site_baseurl');
                        $url .= $this->container->get('router')->generate('odr_migrate_field');

                        // Locate all datarecords of this datatype for purposes of this fieldtype migration
                        $query = $em->createQuery(
                           'SELECT dr.id
                            FROM ODRAdminBundle:DataRecord dr
                            WHERE dr.dataType = :dataType AND dr.deletedAt IS NULL'
                        )->setParameters( array('dataType' => $datatype) );
                        $results = $query->getResult();

                        if ( count($results) > 0 ) {
                            // ----------------------------------------
                            // Need to determine the top-level datatype this datafield belongs to, so other background processes won't attempt to render any part of it and disrupt the migration
                            $top_level_datatype_id = $datafield->getDataType()->getId();
                            $datatree_array = parent::getDatatreeArray($em);

                            while ( isset($datatree_array['descendant_of'][$top_level_datatype_id]) && $datatree_array['descendant_of'][$top_level_datatype_id] !== '')
                                $top_level_datatype_id = $datatree_array['descendant_of'][$top_level_datatype_id];


                            // Get/create an entity to track the progress of this datafield migration
                            $job_type = 'migrate';
                            $target_entity = 'datafield_'.$datafield->getId();
                            $additional_data = array('description' => '', 'old_fieldtype' => $old_fieldtype->getTypeName(), 'new_fieldtype' => $new_fieldtype->getTypeName());
                            $restrictions = 'datatype_'.$top_level_datatype_id;
                            $total = count($results);
                            $reuse_existing = false;

                            $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
                            $tracked_job_id = $tracked_job->getId();


                            // ----------------------------------------
                            foreach ($results as $num => $result) {
                                $datarecord_id = $result['id'];

                                // Insert the new job into the queue
                                $priority = 1024;   // should be roughly default priority
                                $payload = json_encode(
                                    array(
                                        "tracked_job_id" => $tracked_job_id,
                                        "user_id" => $user->getId(),
                                        "datarecord_id" => $datarecord_id,
                                        "datafield_id" => $datafield->getId(),
                                        "old_fieldtype_id" => $old_fieldtype_id,
                                        "new_fieldtype_id" => $new_fieldtype_id,
//                                        "scheduled_at" => $current_time->format('Y-m-d H:i:s'),
                                        "memcached_prefix" => $memcached_prefix,    // debug purposes only
                                        "url" => $url,
                                        "api_key" => $api_key,
                                    )
                                );

                                $pheanstalk->useTube('migrate_datafields')->put($payload);
                            }

                            // TODO - Lock the datatype so no more edits?
                            // TODO - Lock other stuff?
                        }
                    }

                    $datatype = $datafield->getDataType();

                    $options = array();
                    $options['mark_as_updated'] = true;
                    $options['force_shortresults_recache'] = $force_shortresults_recache;
                    if ($datafield->getDisplayOrder() != -1)
                        $options['force_textresults_recache'] = true;

                    parent::updateDatatypeCache($datatype->getId(), $options);
                }
                else {
                    // Form validation failed
                    // TODO - fix parent::ODR_getErrorMessages() to be consistent enough to use
                    $return['r'] = 1;
                    $errors = $datafield_form->getErrors();

                    $error_str = '';
                    foreach ($errors as $num => $error)
                        $error_str .= 'ERROR: '.$error->getMessage()."\n";

                    $return['error'] = $error_str;
                }

            }


            // --------------------
            // Return the html for the right slideout if necessary
/*
print count($datafield_form->getErrors())."\n";
if ($force_slideout_reload)
    print 'forcing reload'."\n";
*/
            if ( $request->getMethod() == 'GET' || count($datafield_form->getErrors()) > 0 || $force_slideout_reload == true ) {
                $em->refresh($datafield);
                $em->refresh($theme_datafield);

                $datafield_form = $this->createForm(new UpdateDatafieldsForm(), $datafield);
                $theme_datafield_form = $this->createForm(new UpdateThemeDatafieldForm(), $theme_datafield);
                $templating = $this->get('templating');

                $return['d'] = array(
                    'force_slideout_reload' => $force_slideout_reload,
                    'html' => $templating->render(
                        'ODRAdminBundle:Displaytemplate:datafield_properties_form.html.twig', 
                        array(
                            'has_multiple_uploads' => $has_multiple_uploads,
                            'prevent_fieldtype_change' => $prevent_fieldtype_change,
                            'prevent_fieldtype_change_message' => $prevent_fieldtype_change_message,

                            'datafield' => $datafield,
                            'datafield_form' => $datafield_form->createView(),
                            'theme_datafield' => $theme_datafield,
                            'theme_datafield_form' => $theme_datafield_form->createView(),
                        )
                    )
                );
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x87705206 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Called after a user makes a change that requires a datafield be removed from TextResults
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataType $datatype
     * @param DataFields $removed_datafield
     *
     */
    private function updateTextResultsFieldOrder($em, $datatype, $removed_datafield)
    {
        // Grab all Datafields that are currently being used in TextResults
        $datafield_list = array();

        $query = $em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.dataType = :datatype AND df.displayOrder > 0
            AND df.deletedAt IS NULL'
        )->setParameters( array('datatype' => $datatype->getId()) );
        $results = $query->getResult();

        foreach ($results as $num => $datafield) {
            if ($datafield->getId() !== $removed_datafield->getId())
                $datafield_list[ $datafield->getDisplayOrder() ] = $datafield;
        }
        ksort($datafield_list);

        // Reset displayOrder to be sequential
        $datafield_list = array_values($datafield_list);
        for ($i = 0; $i < count($datafield_list); $i++) {
            $df = $datafield_list[$i];
            if ($df->getDisplayOrder() !== ($i+1)) {
                $df->setDisplayOrder( $i+1 );
                $em->persist($df);
            }
        }

        // If no Datafields remain that are being used by TextResults, set the Datatype as not using TextResults
        if ( count($datafield_list) == 0 ) {
            $datatype->setHasTextresults(false);
            $em->persist($datatype);
        }

        // Done with the changes
        $em->flush();
    }


    /**
     * Loads/saves a Symfony ThemeElement properties Form.
     * 
     * @param integer $theme_element_id The database id of the ThemeElement being modified.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function themeelementpropertiesAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( $theme_element == null )
                return parent::deletedEntityError('ThemeElement');

            $datatype = $theme_element->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Populate new DataFields form
            $form = $this->createForm(new UpdateThemeElementForm($theme_element), $theme_element);

            $values = array('med_width_old' => $theme_element->getCssWidthMed(), 'xl_width_old' => $theme_element->getCssWidthXL());

            if ($request->getMethod() == 'POST') {
                $form->bind($request, $theme_element);
                $return['t'] = "html";
                if ($form->isValid()) {
                    // Save the changes made to the datatype
                    $theme_element->setUpdatedBy($user);
                    $em->persist($theme_element);
                    $em->flush();

                    $em->refresh($theme_element);
                    $values['med_width_current'] = $theme_element->getCssWidthMed();
                    $values['xl_width_current'] = $theme_element->getCssWidthXL();

                    // Schedule the cache for an update
                    $options = array();
                    $options['mark_as_updated'] = true;

                    parent::updateDatatypeCache($datatype->getId(), $options);
                }
/*
                else {
                    throw new \Exception( parent::ODR_getErrorMessages($form) );
                }
*/
            }

            $templating = $this->get('templating');
            $return['d'] = $templating->render(
                'ODRAdminBundle:Displaytemplate:theme_element_properties_form.html.twig', 
                array(
                    'form' => $form->createView(),
                    'theme_element' => $theme_element,

                )
            );
            $return['widths'] = $values;

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x82377020 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Gets a list of all datafields that have been deleted from a given datatype.
     *
     * @param integer $datatype_id The database id of the DataType to lookup deleted DataFields from...
     * @param Request $request
     *
     * @return Response TODO
     */
    public function getdeleteddatafieldsAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows, because we want to display deleted datafields

            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields df
                WHERE df.dataType = :datatype AND df.deletedAt IS NOT NULL'
            )->setParameters( array('datatype' => $datatype) );
            $results = $query->getResult();

            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            // Collapse results array
            $count = 0;
            $datafields = array();
            foreach ($results as $num => $df) {
                $date = $df->getDeletedAt()->format('Y-m-d').'_'.$count;
                $count++;
                $datafields[$date] = $df;
            }

            krsort($datafields);

            // Get Templating Object
            $templating = $this->get('templating');
            $return['t'] = 'html';
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:undelete_fields_dialog_form.html.twig',
                    array(
                        'datafields' => $datafields,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x182537020 ' . $e->getMessage();

            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Undeletes a deleted DataField.
     *
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function undeleteDatafieldAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $post = $_POST;
//print_r($post);
//return;
            $datafield_id = $post['datafield_id'];

            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_theme_data_field = $em->getRepository('ODRAdminBundle:ThemeDataField');

$debug = true;
$debug = false;

            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows, because we want to display old selected mappings/options

            // need to do checking that stuff won't crash
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields df
                WHERE df.id = :datafield_id'
            )->setParameters( array('datafield_id' => $datafield_id) );
            $results = $query->getResult();

            // Ensure datafield matches undelete conditions
            $datafield = null;
            if ( count($results) == 1 ) {
                $datafield = $results[0];
                if ($datafield->getDeletedAt() === null) {
                    throw new \Exception('DataField is not deleted.');
                }
            }
            else {
                throw new \Exception('DataField does not exist.');
            }

            // no point undeleting datafield if datatype doesn't exist...
            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() !== null) {
                throw new \Exception('DataType of DataField is deleted.');
            }

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) ) {
                $em->getFilters()->enable('softdeleteable');
                return parent::permissionDeniedError("edit");
            }
            // --------------------


            // TODO - must have at least one theme_element_field?  or should it create one if one doesn't exist...will attach to theme element thanks to step 4
            $query = $em->createQuery(
               'SELECT tef
                FROM ODRAdminBundle:ThemeElementField tef
                WHERE tef.dataFields = :datafield'
            )->setParameters( array('datafield' => $datafield) );
            $results = $query->getResult();

            if ( count($results) < 1 ) {
                throw new \Exception('No ThemeElementField entry for DataField.');
            }

            // Step 1: undelete the datafield itself
            $datafield->setDeletedAt(null);
            $datafield->setUpdatedBy($user);
            $em->persist($datafield);
            $em->flush();
            $em->refresh($datafield);

            // Step 1.5: re-activate theme_datafield entries for theme 1
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield->getId(), 'theme' => 1) );
            $theme_datafield->setActive(1);
            $em->persist($theme_datafield);

if ($debug)
    print 'undeleted datafield '.$datafield->getId().' of datatype '.$datatype->getId()."\n\n";

            // Step 2: undelete the datarecordfield entries associated with this datafield to recover the data
            $query = $em->createQuery(
               'SELECT drf
                FROM ODRAdminBundle:DataRecordFields drf
                JOIN ODRAdminBundle:DataRecord dr WITH drf.dataRecord = dr
                WHERE drf.dataField = :datafield AND dr.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield) );
            $results = $query->getResult();

            foreach ($results as $drf) {
if ($debug)
    print 'datarecordfield '.$drf->getId().'...'."\n";
                $typeclass = $datafield->getFieldType()->getTypeClass();
                if ($typeclass == 'File' || $typeclass == 'Image' || $typeclass == 'Radio') {
                    $drf->setDeletedAt(null);
                    $drf->setUpdatedBy($user);
                    $em->persist($drf);

if ($debug)
    print 'undeleting datarecordfield '.$drf->getId().' on principle because it is a '.$typeclass.'...'."\n";   // TODO - right thing to do?
                }
                else {
                    if ($drf->getAssociatedEntity() !== null && $drf->getAssociatedEntity()->getDeletedAt() === null) { // TODO - these entities technically should never be deleted?
                        if ($drf->getAssociatedEntity()->getFieldType()->getTypeClass() == $datafield->getFieldType()->getTypeClass()) {
                            $drf->setDeletedAt(null);
                            $drf->setUpdatedBy($user);
                            $em->persist($drf);
if ($debug)
    print 'undeleted datarecordfield '.$drf->getId().' of datarecord '.$drf->getDataRecord()->getId()."\n";
                        }
                        else {
if ($debug)
    print 'skipped datarecordfield '.$drf->getId().' of datarecord '.$drf->getDataRecord()->getId().", wrong fieldtype\n";
                        }
                    }
                    else {
if ($debug)
    print 'skipped datarecordfield '.$drf->getId().' of datarecord '.$drf->getDataRecord()->getId().", associated entity is deleted\n";
                    }
                }
            }

            // Step 3: undelete the theme_element_field entries associated with this datafield so it can actually be rendered
            $query = $em->createQuery(
               'SELECT tef
                FROM ODRAdminBundle:ThemeElementField tef
                WHERE tef.dataFields = :datafield'
            )->setParameters( array('datafield' => $datafield) );
            $results = $query->getResult();
/*
            // although it should never happen, need to deal with the possibility that there can be more than one theme_element_field for a datafield...
            $theme_element_field = null;
            if ( count($results) > 1 ) {
                // ...going to assume that any undeleted theme_element_field is correct and do nothing
                $all_deleted = true;
                $tmp = null;
                foreach ($results as $tef) {
                    $tmp = $tef;
                    if ($tef->getDeletedAt() === null) {
                        $theme_element_field = $tef;
if ($debug)
    print 'using pre-existing theme_element_field '.$theme_element_field->getId()." for datafield\n";
                        $all_deleted = false;
                        break;
                    }
                }
                // ...if they're all deleted, just undelete the last one...good as any?
                if ($all_deleted) {
                    $tmp->setDeletedAt(null);
                    $tmp->setUpdatedBy($user);
                    $em->persist($tmp);
                    $em->flush();
                    $em->refresh($tmp);

                    $theme_element_field = $tmp;
if ($debug)
    print 'all usable theme_element_field entries deleted, undeleting theme_element_field '.$theme_element_field->getId()."\n";
                }
            }
            else {
*/
            foreach ($results as $theme_element_field) {
                $theme_element_field->setDeletedAt(null);
                $theme_element_field->setUpdatedBy($user);
                $em->persist($theme_element_field);
                $em->flush();
                $em->refresh($theme_element_field);

if ($debug)
    print 'undeleting theme_element_field '.$theme_element_field->getId()."\n";
            }


            // Step 4: move the theme_element_field to a different theme_element if the original theme_element got deleted
            $query = $em->createQuery(
               'SELECT te
                FROM ODRAdminBundle:ThemeElementField tef
                JOIN ODRAdminBundle:ThemeElement te WITH tef.themeElement = te
                WHERE tef.dataFields = :datafield AND te.theme = :theme'
            )->setParameters( array('datafield' => $datafield->getId(), 'theme' => 1) );
            $results = $query->getResult();

            $theme_element = $results[0];
            if ($theme_element->getDeletedAt() !== NULL) {
                // theme_element IS DELETED
if ($debug)
    print '-- theme_element '.$theme_element->getId().' deleted, locating new theme_element...'."\n";

                // need to locate the first datafield-oriented theme element in this datatype
                $theme_elements = array();
                foreach ($datatype->getThemeElement() as $te) {
                    if ($te->getDeletedAt() === NULL && $te->getTheme()->getID() == 1)   // TODO - deleted check needed?
                        $theme_elements[$te->getDisplayOrder()] = $te;
                }

                $found = false;
                foreach ($theme_elements as $display_order => $te) {
                    foreach ($te->getThemeElementField() as $tef) {
                        if ($tef->getDeletedAt() === NULL && $tef->getDataFields() !== null) {  // TODO - deleted check needed?
                            $theme_element_field->setThemeElement( $tef->getThemeElement() );
                            $em->persist($theme_element_field);

if ($debug)
    print "-- attaching theme_element_field to theme_element ".$tef->getThemeElement()->getId()."\n";

                            $theme_element = $tef->getThemeElement();   // save for the return value
                            $found = true;
                            break;
                        }
                    }

                    if ($found)
                        break;
                }
            }
            $em->flush();

            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            $return['d'] = array(
//                'datatype_id' => $datafield->getDataType()->getId(),
                'theme_element_id' => $theme_element->getId()
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x32327089 ' . $e->getMessage();

            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of a DataType.
     * 
     * @param integer $datatype_id The database id of the DataType to modify.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function publictypeAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            // Grab the necessary entities
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


            // If the datatype is public, make it non-public...if datatype is non-public, make it public
            if ( $datatype->isPublic() ) {

                // Don't allow the datatype to be marked as non-public if it's set as the default search
//                if ($datatype->getIsDefaultSearchDatatype() == true)
//                    throw new \Exception("This Datatype can't be set as non-public because it's marked as the default search datatype...");

                // Make the datatype non-public
                $datatype->setPublicDate(new \DateTime('2200-01-01 00:00:00'));
            }
            else {
                // Make the datatype public
                $datatype->setPublicDate(new \DateTime());
            }
            $datatype->setUpdatedBy($user);
            $em->persist($datatype);
            $em->flush();

/*
            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);
*/
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x20228935656 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Checks to see whether the given Datafield can be marked as unique or not.
     *
     * @param DataFields $datafield
     *
     * @return boolean true if the datafield has no duplicate values, false otherwise
     */
    private function datafieldCanBeUnique($datafield)
    {
        // Going to need these...
        $datafield_id = $datafield->getId();
        $fieldtype = $datafield->getFieldType();
        $typeclass = $fieldtype->getTypeClass();

        // Only run queries if field can be set to unique
        if ($fieldtype->getCanBeUnique() == 0)
            return false;

        // Get a list of all values in the datafield
        $em = $this->getDoctrine()->getManager();
        $query = $em->createQuery(
           'SELECT e.value
            FROM ODRAdminBundle:'.$typeclass.' AS e
            JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
            WHERE e.dataField = :datafield
            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
        )->setParameters( array('datafield' => $datafield_id) );
        $results = $query->getArrayResult();

        // Determine if there are any duplicates in the datafield...
        $values = array();
        foreach ($results as $result) {
            $value = $result['value'];
            if ( isset($values[$value]) )
                return false;
            else
                $values[$value] = 1;
        }

        // Didn't find a duplicate, return true
        return true;
    }

}
