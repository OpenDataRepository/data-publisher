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
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Forms
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\UpdateDataTreeForm;
use ODR\AdminBundle\Form\UpdateThemeDatafieldForm;
use ODR\AdminBundle\Form\UpdateThemeDatatypeForm;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
     * @return Response
     */
    public function deletedatafieldAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datatype');
            $grandparent_datatype_id = $grandparent_datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // --------------------
            // TODO - better way of handling this?
            // Prevent deletion of datafields if a csv import is in progress, as this could screw the importing over
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$grandparent_datatype_id, 'completed' => null) );   // TODO - not datatype_id, right?
            if ($tracked_job !== null)
                throw new ODRException('Preventing deletion of any DataField for this DataType, because a CSV Import for this DataType is in progress...');

            // Check that the datafield isn't being used for something else before deleting it
            if ( !self::canDeleteDatafield($em, $datafield) )
                throw new ODRBadRequestException('Datafield is in use, unable to delete');


            // ----------------------------------------
            // Save which themes are going to get theme_datafield entries deleted
            $query = $em->createQuery(
               'SELECT t
                FROM ODRAdminBundle:ThemeDataField AS tdf
                JOIN ODRAdminBundle:ThemeElement AS te WITH tdf.themeElement = te
                JOIN ODRAdminBundle:Theme AS t WITH te.theme = t
                WHERE tdf.dataField = :datafield
                AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId()) );
            $all_datafield_themes = $query->getResult();
            /** @var Theme[] $all_datafield_themes */

            // Save which users and groups need to delete their permission entries for this datafield
            $query = $em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                JOIN ODRAdminBundle:Group AS g WITH gdfp.group = g
                WHERE gdfp.dataField = :datafield
                AND gdfp.deletedAt IS NULL AND g.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId()) );
            $all_affected_groups = $query->getArrayResult();

//print '<pre>'.print_r($all_affected_groups, true).'</pre>';  //exit();

            $query = $em->createQuery(
               'SELECT u.id AS user_id
                FROM ODRAdminBundle:Group AS g
                JOIN ODRAdminBundle:UserGroup AS ug WITH ug.group = g
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE g.id IN (:groups)
                AND g.deletedAt IS NULL AND ug.deletedAt IS NULL'
            )->setParameters( array('groups' => $all_affected_groups) );
            $all_affected_users = $query->getArrayResult();

//print '<pre>'.print_r($all_affected_users, true).'</pre>'; exit();


            // TODO - disabled for now, but is this safe to delete?
            // Delete this datafield from all table themes and ensure all remaining datafields in the theme are still in sequential order
//            self::removeDatafieldFromTableThemes($em, $user, $datafield);


            // ----------------------------------------
            // Perform a series of DQL mass updates to immediately remove everything that could break if it wasn't deleted...
/*
            // ...datarecordfield entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecordFields AS drf
                SET drf.deletedAt = :now
                WHERE drf.dataField = :datafield AND drf.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datafield' => $datafield->getId()) );
            $rows = $query->execute();
*/
            // ...theme_datafield entries    TODO - SHOULD THESE STILL BE DELETED?
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:ThemeDataField AS tdf
                SET tdf.deletedAt = :now, tdf.deletedBy = :deleted_by
                WHERE tdf.dataField = :datafield AND tdf.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datafield' => $datafield->getId()) );
            $rows = $query->execute();

            // ...datafield permissions
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                SET gdfp.deletedAt = :now
                WHERE gdfp.dataField = :datafield AND gdfp.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datafield' => $datafield->getId()) );
            $rows = $query->execute();


            // Ensure that the datatype dosen't continue to think the deleted datafield is something special
            $properties = array();
            // Ensure that the datatype doesn't continue to think this datafield is its external id field
            if ($datatype->getExternalIdField() !== null && $datatype->getExternalIdField()->getId() === $datafield->getId())
                $properties['externalIdField'] = null;

            // Ensure that the datatype doesn't continue to think this datafield is its name field
            if ($datatype->getNameField() !== null && $datatype->getNameField()->getId() === $datafield->getId())
                $properties['nameField'] = null;

            // Ensure that the datatype doesn't continue to think this datafield is its sort field
            if ($datatype->getSortField() !== null && $datatype->getSortField()->getId() === $datafield->getId()) {
                $properties['sortField'] = null;

                // Delete the sort order for the datatype too, so it doesn't attempt to sort on a non-existent datafield
                $cache_service->delete('datatype_'.$datatype->getId().'_record_order');
            }

            // Ensure that the datatype doesn't continue to think this datafield is its background image field
            if ($datatype->getBackgroundImageField() !== null && $datatype->getBackgroundImageField()->getId() === $datafield->getId())
                $properties['backgroundImageField'] = null;

            if ( count($properties) > 0 )
                parent::ODR_copyDatatypeMeta($em, $user, $datatype, $properties);


            // ----------------------------------------
            // Save who deleted this datafield
            $datafield->setDeletedBy($user);
            $em->persist($datafield);
            $em->flush();

            // Done cleaning up after the datafield, delete it and its metadata
            $datafield_meta = $datafield->getDataFieldMeta();
            $em->remove($datafield_meta);
            $em->remove($datafield);

            // Save changes
            $em->flush();


            // ----------------------------------------
            // TODO - delete all storage entities for this datafield via beanstalk?  or just stack delete statements for all 12 entities in here?

            // Mark this datatype as updated
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Rebuild all cached theme entries the datafield belonged to
            foreach ($all_datafield_themes as $t)
                $theme_service->updateThemeCacheEntry($t->getParentTheme(), $user);


            // ----------------------------------------
            // Wipe cached data for all the datatype's datarecords
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype_id'
            )->setParameters( array('datatype_id' => $grandparent_datatype_id) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $cache_service->delete('cached_datarecord_'.$dr_id);
                $cache_service->delete('cached_table_data_'.$dr_id);
            }


            // Wipe cached entries for Group and User permissions involving this datafield
            foreach ($all_affected_groups as $group) {
                $group_id = $group['group_id'];
                $cache_service->delete('group_'.$group_id.'_permissions');
            }

            foreach ($all_affected_users as $user) {
                $user_id = $user['user_id'];
                $cache_service->delete('user_'.$user_id.'_permissions');
            }


            // ----------------------------------------
            // See if any cached search results need to be deleted...
            $cached_searches = $cache_service->get('cached_search_results');
            if ( $cached_searches != false && isset($cached_searches[$grandparent_datatype_id]) ) {
                // Delete all cached search results for this datatype that were run with criteria for this specific datafield
                foreach ($cached_searches[$grandparent_datatype_id] as $search_checksum => $search_data) {
                    $searched_datafields = $search_data['searched_datafields'];
                    $searched_datafields = explode(',', $searched_datafields);

                    if ( in_array($datafield_id, $searched_datafields) )
                        unset( $cached_searches[$grandparent_datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $cache_service->set('cached_search_results', $cached_searches);
            }
        }
        catch (\Exception $e) {
            $source = 0x4fc66d72;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
     * @return Response
     */
    public function deleteradiooptionAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find( $radio_option_id );
            if ($radio_option == null)
                throw new ODRNotFoundException('Radio Option');

            $datafield = $radio_option->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datatype');
            $grandparent_datatype_id = $grandparent_datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Delete all radio selection entities attached to the radio option
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:RadioSelection AS rs
                SET rs.deletedAt = :now
                WHERE rs.radioOption = :radio_option_id AND rs.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'radio_option_id' => $radio_option_id) );
            $updated = $query->execute();


            // Save who deleted this radio option
            $radio_option->setDeletedBy($user);
            $em->persist($radio_option);
            $em->flush($radio_option);

            // Delete the radio option and its current associated metadata entry
            $radio_option_meta = $radio_option->getRadioOptionMeta();
            $em->remove($radio_option);
            $em->remove($radio_option_meta);
            $em->flush();


            // ----------------------------------------
            // Mark this datatype as updated
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Wipe cached data for all the datatype's datarecords
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype_id'
            )->setParameters( array('datatype_id' => $grandparent_datatype_id) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $cache_service->delete('cached_datarecord_'.$dr_id);
                $cache_service->delete('cached_table_data_'.$dr_id);
            }

            // See if any cached search results need to be deleted...
            $cached_searches = $cache_service->get('cached_search_results');
            if ( $cached_searches != false && isset($cached_searches[$grandparent_datatype_id]) ) {
                // Delete all cached search results for this datatype that were run with criteria for this specific datafield
                foreach ($cached_searches[$grandparent_datatype_id] as $search_checksum => $search_data) {
                    $searched_datafields = $search_data['searched_datafields'];
                    $searched_datafields = explode(',', $searched_datafields);

                    if ( in_array($datafield_id, $searched_datafields) )
                        unset( $cached_searches[$grandparent_datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $cache_service->set('cached_search_results', $cached_searches);
            }
        }
        catch (\Exception $e) {
            $source = 0x00b86c51;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
     * @return Response
     */
    public function defaultradiooptionAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            $repo_radio_option_meta = $em->getRepository('ODRAdminBundle:RadioOptionsMeta');

            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find( $radio_option_id );
            if ($radio_option == null)
                throw new ODRNotFoundException('Radio Option');

            $datafield = $radio_option->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            $field_typename = $datafield->getFieldType()->getTypeName();
            if ( $field_typename == 'Single Radio' || $field_typename == 'Single Select' ) {
                // Only one option allowed to be default for Single Radio/Select DataFields, find the other option(s) where isDefault == true
                $query = $em->createQuery(
                   'SELECT rom.id
                    FROM ODRAdminBundle:RadioOptionsMeta AS rom
                    JOIN ODRAdminBundle:RadioOptions AS ro WITH rom.radioOption = ro
                    WHERE rom.isDefault = 1 AND ro.dataField = :datafield
                    AND rom.deletedAt IS NULL AND ro.deletedAt IS NULL'
                )->setParameters( array('datafield' => $datafield->getId()) );
                $results = $query->getResult();

                foreach ($results as $num => $result) {
                    /** @var RadioOptionsMeta $radio_option_meta */
                    $radio_option_meta = $repo_radio_option_meta->find( $result['id'] );
                    $ro = $radio_option_meta->getRadioOption();

                    $properties = array(
                        'isDefault' => false
                    );
                    parent::ODR_copyRadioOptionsMeta($em, $user, $ro, $properties);
                }

                // TODO - currently not allowed to remove a default option from one of these fields once a a default has been set
                // Set this radio option as selected by default
                $properties = array(
                    'isDefault' => true
                );
                parent::ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties);
            }
            else {
                // Multiple options allowed as defaults, toggle default status of current radio option
                $properties = array(
                    'isDefault' => true
                );
                if ($radio_option->getIsDefault() == true)
                    $properties['isDefault'] = false;

                parent::ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties);
            }


            // Force an update of this datatype's cached entries
            $dti_service->updateDatatypeCacheEntry($datatype, $user);
        }
        catch (\Exception $e) {
            $source = 0x5567b2f9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes an entire DataType and all of the entities directly related to rendering it.  Unlike
     * creating a datatype, this function works for both top-level and child datatypes.
     *
     * @param integer $datatype_id The database id of the DataType to be deleted.
     * @param Request $request
     * 
     * @return Response
     */
    public function deletedatatypeAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            $grandparent = $datatype->getGrandparent();
            if ($grandparent->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datatype');
            $grandparent_datatype_id = $grandparent->getId();


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // TODO - prevent datatype deletion when jobs are in progress?


            // ----------------------------------------
            // Locate ids of all datatypes that need deletion...can't just use grandparent datatype id
            //  since this could be a child datatype
            $datatree_array = $dti_service->getDatatreeArray();

            $tmp = array($datatype->getId() => 0);
            $datatypes_to_delete = array(0 => $datatype->getId());

            while ( count($tmp) > 0 ) {
                $new_tmp = array();
                foreach ($tmp as $dt_id => $num) {
                    $child_datatype_ids = array_keys($datatree_array['descendant_of'], $dt_id);
                    foreach ($child_datatype_ids as $num => $child_datatype_id) {
                        $new_tmp[$child_datatype_id] = 0;
                        $datatypes_to_delete[] = $child_datatype_id;
                    }
                    unset($tmp[$dt_id]);
                }
                $tmp = $new_tmp;
            }
            $datatypes_to_delete = array_unique($datatypes_to_delete);
            $datatypes_to_delete = array_values($datatypes_to_delete);

//print '<pre>'.print_r($datatypes_to_delete, true).'</pre>'; exit();

            // Determine all Groups and all Users affected by this
            $query = $em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:Group AS g
                WHERE g.dataType IN (:datatype_ids)
                AND g.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatypes_to_delete) );
            $groups_to_delete = $query->getArrayResult();

//print '<pre>'.print_r($groups_to_delete, true).'</pre>';  exit();

            $query = $em->createQuery(
               'SELECT u.id AS user_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE ug.group IN (:groups) AND ug.deletedAt IS NULL'
            )->setParameters( array('groups' => $groups_to_delete) );
            $all_affected_users = $query->getArrayResult();

//print '<pre>'.print_r($all_affected_users, true).'</pre>';  exit();


            // ----------------------------------------
/*
            // Delete Datarecordfield entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecord AS dr, ODRAdminBundle:DataRecordFields AS drf
                SET drf.deletedAt = :now
                WHERE drf.dataRecord = dr
                AND dr.dataType IN (:datatype_ids)
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();
*/
            // Delete LinkedDatatree entries...can't do multi-table updates in Doctrine, so have to split it apart into three queries
            $query = $em->createQuery(
               'SELECT ancestor.id AS ancestor_id
                FROM ODRAdminBundle:DataRecord AS ancestor
                WHERE ancestor.dataType IN (:datatype_ids)
                AND ancestor.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatypes_to_delete) );
            $results = $query->getArrayResult();

            $ancestor_ids = array();
            foreach ($results as $result)
                $ancestor_ids[] = $result['ancestor_id'];

            $query = $em->createQuery(
               'SELECT descendant.id AS descendant_id
                FROM ODRAdminBundle:DataRecord AS descendant
                WHERE descendant.dataType IN (:datatype_ids)
                AND descendant.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatypes_to_delete) );
            $results = $query->getArrayResult();

            $descendant_ids = array();
            foreach ($results as $result)
                $descendant_ids[] = $result['descendant_id'];

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:LinkedDataTree AS ldt
                SET ldt.deletedAt = :now, ldt.deletedBy = :deleted_by
                WHERE (ldt.ancestor IN (:ancestor_ids) OR ldt.descendant IN (:descendant_ids))
                AND ldt.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'ancestor_ids' => $ancestor_ids, 'descendant_ids' => $descendant_ids) );
            $query->execute();

/*
            // Delete Datarecord and DatarecordMeta entries
            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:DataRecord AS dr, ODRAdminBundle:DataRecordMeta AS drm
                SET dr.deletedAt = :now, dr.deletedBy = :deleted_by, drm.deletedAt = :now
                WHERE drm.dataRecord = dr
                AND dr.dataType IN (:datatype_ids)
                AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();
*/

            // ----------------------------------------
/*
            // Delete GroupDatafieldPermission entries (cached versions deleted later)
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataFields AS df, ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                SET gdfp.deletedAt = :now
                WHERE gdfp.dataField = df
                AND df.dataType IN (:datatype_ids)
                AND df.deletedAt IS NULL AND gdfp.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();

            // Delete Datafields and their DatafieldMeta entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataFields AS df, ODRAdminBundle:DataFieldsMeta AS dfm
                SET df.deletedAt = :now, df.deletedBy = :deleted_by, dfm.deletedAt = :now
                WHERE dfm.dataField = df
                AND df.dataType IN (:datatype_ids)
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();
*/

            // ----------------------------------------
/*
            // Delete all ThemeDatatype entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:ThemeDataType AS tdt, ODRAdminBundle:ThemeElement AS te, ODRAdminBundle:Theme AS t
                SET tdt.deletedAt = :now, tdt.deletedBy = :deleted_by
                WHERE tdt.themeElement = te AND te.theme = t
                AND t.dataType IN (:datatype_ids)
                AND tdt.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();
*/
            // Delete any leftover ThemeDatatype entries that refer to $datatypes_to_delete...these would be other datatypes linking to the ones being deleted
            // (if block above is commented, then it'll also arbitrarily delete themeDatatype entries for child datatypes)
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:ThemeDataType AS tdt
                SET tdt.deletedAt = :now, tdt.deletedBy = :deleted_by
                WHERE tdt.dataType IN (:datatype_ids)
                AND tdt.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();
/*
            // Delete all ThemeDatafield entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:ThemeDataField AS tdf, ODRAdminBundle:ThemeElement AS te, ODRAdminBundle:Theme AS t
                SET tdf.deletedAt = :now, tdf.deletedBy = :deleted_by
                WHERE tdf.themeElement = te AND te.theme = t
                AND t.dataType IN (:datatype_ids)
                AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();

            // Delete all ThemeElement and ThemeElementMeta entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:ThemeElement AS te, ODRAdminBundle:ThemeElementMeta AS tem ODRAdminBundle:Theme AS t
                SET te.deletedAt = :now, te.deletedBy = :deleted_by, tem.deletedAt = :now
                WHERE tem.themeElement = te AND te.theme = t
                AND t.dataType IN (:datatype_ids)
                AND te.deletedAt IS NULL AND tem.deletedAt IS NULL AND t.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();

            // Delete all Theme and ThemeMeta entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:Theme AS t, ODRAdminBundle:ThemeMeta AS tm
                SET t.deletedAt = :now, t.deletedBy = :deleted_by, tm.deletedAt = :now
                WHERE tm.theme = t
                AND t.dataType IN (:datatype_ids)
                AND t.deletedAt IS NULL AND tm.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();
*/

            // ----------------------------------------
            // Delete all Datatree and DatatreeMeta entries
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id
                FROM ODRAdminBundle:DataTree AS dt
                WHERE (dt.ancestor IN (:datatype_ids) OR dt.descendant IN (:datatype_ids) )
                AND dt.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatypes_to_delete) );
            $results = $query->getArrayResult();

            $datatree_ids = array();
            foreach ($results as $result)
                $datatree_ids[] = $result['dt_id'];

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataTreeMeta AS dtm
                SET dtm.deletedAt = :now
                WHERE dtm.dataTree IN (:datatree_ids)
                AND dtm.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datatree_ids' => $datatree_ids) );
            $query->execute();

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataTree AS dt
                SET dt.deletedAt = :now, dt.deletedBy = :deleted_by
                WHERE dt.id IN (:datatree_ids)
                AND dt.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatree_ids' => $datatree_ids) );
            $query->execute();


            // ----------------------------------------
/*
            // Delete GroupDatatypePermission entries (cached versions deleted later)
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:GroupDatatypePermissions AS gdtp
                SET gdtp.deletedAt = :now
                WHERE gdtp.dataType IN (:datatype_ids)
                AND gdtp.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();

            // Delete Groups and their GroupMeta entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:Group AS g, ODRAdminBundle:GroupMeta AS gm
                SET g.deletedAt = :now, g.deletedBy = :deleted_by, gm.deletedAt = :now
                WHERE gm.group = g
                AND g.dataType IN (:datatype_ids)
                AND g.deletedAt IS NULL AND gm.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();
*/

            // Remove members from the Groups for this Datatype
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:UserGroup AS ug
                SET ug.deletedAt = :now, ug.deletedBy = :deleted_by
                WHERE ug.group IN (:group_ids)
                AND ug.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'group_ids' => $groups_to_delete) );
            $query->execute();


            // ----------------------------------------
            // Delete all Datatype and DatatypeMeta entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataTypeMeta AS dtm
                SET dtm.deletedAt = :now
                WHERE dtm.dataType IN (:datatype_ids)
                AND dtm.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataType AS dt
                SET dt.deletedAt = :now, dt.deletedBy = :deleted_by
                WHERE dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();


            // ----------------------------------------
            // Delete cached versions of all Datarecords of this Datatype if needed
            if ($datatype->getId() == $grandparent_datatype_id) {
                $query = $em->createQuery(
                    'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype_id'
                )->setParameters( array('datatype_id' => $grandparent_datatype_id) );
                $results = $query->getArrayResult();

//print '<pre>'.print_r($results, true).'</pre>';  exit();

                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];

                    $cache_service->delete('cached_datarecord_'.$dr_id);
                    $cache_service->delete('cached_table_data_'.$dr_id);
                    $cache_service->delete('associated_datarecords_for_'.$dr_id);
                }
            }

            // Delete cached entries for Group and User permissions involving this Datafield
            foreach ($groups_to_delete as $group) {
                $group_id = $group['group_id'];
                $cache_service->delete('group_'.$group_id.'_permissions');
            }

            foreach ($all_affected_users as $user) {
                $user_id = $user['user_id'];
                $cache_service->delete('user_'.$user_id.'_permissions');
            }

            // ...cached searches
            $cached_searches = $cache_service->get('cached_search_results');
            if ( $cached_searches != false && isset($cached_searches[$datatype_id]) ) {
                unset( $cached_searches[$datatype_id] );

                // Save the collection of cached searches back to memcached
                $cache_service->set('cached_search_results', $cached_searches);
            }

            // ...cached datatype data
            foreach ($datatypes_to_delete as $num => $dt_id) {
                $cache_service->delete('cached_datatype_'.$dt_id);
                $cache_service->delete('associated_datatypes_for_'.$dt_id);

                $cache_service->delete('dashboard_'.$dt_id);
                $cache_service->delete('dashboard_'.$dt_id.'_public_only');
            }


            // TODO - delete custom themes as well as the default/master theme for this datatype?
            // ...layout data


            // ...and the cached version of the datatree array
            $cache_service->delete('top_level_datatypes');
            $cache_service->delete('cached_datatree_array');
        }
        catch (\Exception $e) {
            $source = 0xa6304ef8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads and returns the DesignTemplate HTML for this DataType.
     * 
     * @param integer $datatype_id The database id of the DataType to be rendered.
     * @param Request $request
     * 
     * @return Response
     */
    public function designAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();


            // ----------------------------------------
            // Check if this is a master template based datatype that is still in the creation process...
            if ($datatype->getSetupStep() == "initial" && $datatype->getMasterDataType() != null) {
                // The database is still in the process of being created...return the HTML for the page that'll periodically check for progress
                $templating = $this->get('templating');
                $return['t'] = "html";
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Datatype:create_status_checker.html.twig',
                        array(
                            "datatype" => $datatype
                        )
                    )
                );
            }
            else {
                // Ensure user has permissions to be doing this
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();

                $return['d'] = array(
                    'datatype_id' => $datatype->getId(),
                    'html' => self::GetDisplayData($em, $datatype_id, 'default', $datatype_id, $request),
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x8ae875b2;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves changes made to a Datatree entity.
     * 
     * @param integer $datatree_id  The id of the Datatree entity being changed
     * @param Request $request
     * 
     * @return Response
     */
    public function savedatatreeAction($datatree_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->find($datatree_id);
            if ($datatree == null)
                throw new ODRNotFoundException('Datatree');

            $ancestor_datatype = $datatree->getAncestor();
            if ($ancestor_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $ancestor_datatype) )
                throw new ODRForbiddenException();
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
            $submitted_data = new DataTreeMeta();
            $datatree_form = $this->createForm(UpdateDataTreeForm::class, $submitted_data);

            $datatree_form->handleRequest($request);
            if ( $datatree_form->isSubmitted() ) {

                // Ensure that "multiple allowed" is true if required
                if ($force_multiple) 
                    $submitted_data->setMultipleAllowed(true);

                if ( $datatree_form->isValid() ) {
                    // If a value in the form changed, create a new DataTree entity to store the change
                    $properties = array(
                        'multiple_allowed' => $submitted_data->getMultipleAllowed(),
                        'is_link' => $submitted_data->getIsLink(),
                    );
                    parent::ODR_copyDatatreeMeta($em, $user, $datatree, $properties);

                    // Need to delete the cached version of the datatree array
                    $cache_service->delete('cached_datatree_array');

                    // Then delete the cached version of the affected datatype
                    $dti_service->updateDatatypeCacheEntry($ancestor_datatype, $user);

                    // The 'is_link' or 'multiple_allowed' properties are also stored in the
                    //  cached theme entries, so they need to get rebuilt as well
                    $query = $em->createQuery(
                       'SELECT t.id AS theme_id
                        FROM ODRAdminBundle:Theme AS t
                        WHERE t.dataType = :datatype_id
                        AND t.deletedAt IS NULL'
                    )->setParameters( array('datatype_id' => $ancestor_datatype->getGrandparent()->getId()) );
                    $results = $query->getArrayResult();

                    foreach ($results as $result)
                        $cache_service->delete('cached_theme_'.$result['theme_id']);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datatree_form);
                    throw new ODRException($error_str);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x43a5ff6f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Adds a new DataField to the given DataType and ThemeElement.
     *
     * @param integer $theme_element_id The database id of the ThemeElement to attach this new Datafield to
     * @param Request $request
     * 
     * @return Response
     */
    public function adddatafieldAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure this is only called on a 'master' theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Datafields can only be added to a "master" theme');

            // Ensure there's not a child or linked datatype in this theme_element before going and creating a new datafield
            /** @var ThemeDataType[] $theme_datatypes */
            $theme_datatypes = $em->getRepository('ODRAdminBundle:ThemeDataType')->findBy( array('themeElement' => $theme_element_id) );
            if ( count($theme_datatypes) > 0 )
                throw new ODRBadRequestException('Unable to add a Datafield into a ThemeElement that already has a child/linked Datatype');


            // ----------------------------------------
            // Grab objects required to create a datafield entity
            /** @var FieldType $fieldtype */
            $fieldtype = $em->getRepository('ODRAdminBundle:FieldType')->findOneBy( array('typeName' => 'Short Text') );
            /** @var RenderPlugin $render_plugin */
            $render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find('1');

            // Create the datafield
            $objects = parent::ODR_addDataField($em, $user, $datatype, $fieldtype, $render_plugin);
            /** @var DataFields $datafield */
            $datafield = $objects['datafield'];

            // Tie the datafield to the theme element
            parent::ODR_addThemeDataField($em, $user, $datafield, $theme_element);

            // Save changes
            $em->flush();

            // design_ajax.html.twig calls ReloadThemeElement()

            // Update the cached version of the datatype and the master theme
            $dti_service->updateDatatypeCacheEntry($datatype, $user);
            $theme_service->updateThemeCacheEntry($theme, $user);

            // Don't need to worry about datafield permissions here, those are taken care of inside ODR_addDataField()
        }
        catch (\Exception $e) {
            $source = 0x6f6cfd5d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Clones the properties of an existing Datafield entity into a new one.
     * TODO - move into its own service like dataype/theme cloning?
     *
     * @param integer $theme_element_id The database id of the ThemeElement containing the Datafield
     * @param integer $datafield_id     The database id of the DataField to clone
     * @param Request $request
     *
     * @return Response
     */
    public function copydatafieldAction($theme_element_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataFields $old_datafield */
            $old_datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($old_datafield == null)
                throw new ODRNotFoundException('Datafield');

            /** @var ThemeDataField $old_theme_datafield */
            $old_theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                array(
                    'dataField' => $old_datafield->getId(),
                    'themeElement' => $theme_element->getId()
                )
            );
            if ($old_theme_datafield == null)
                throw new ODRNotFoundException('ThemeDatafield');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Don't allow cloning of a datafield outside the master theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to clone a datafield outside of a "master" theme');

            // TODO - allow cloning of radio datafields
            if ($old_datafield->getFieldType()->getTypeClass() == 'Radio')
                throw new ODRBadRequestException('Unable to clone a Radio Datafield.');

            // Datafields being used by render plugins shouldn't be cloned...
            // TODO - allow cloning of datafields using render plugins
            /** @var RenderPluginMap $rpm */
            $rpm = $em->getRepository('ODRAdminBundle:RenderPluginMap')->findOneBy( array('dataField' => $old_datafield->getId()) );
            if ($rpm != null)
                throw new ODRBadRequestException('Unable to clone a Datafield that is using, or being used by, a Render Plugin.');


            // ----------------------------------------
            // Clone the old datafield...
            /** @var DataFields $new_df */
            $new_df = clone $old_datafield;

            // TODO - clear other tracking/revision history properties?
            $new_df->setMasterDataField(null);

            // Ensure the "in-memory" version of $datatype knows about the new datafield
            $datatype->addDataField($new_df);
            self::persistObject($em, $new_df, $user);


            // Clone the old datafield's meta entry...
            /** @var DataFieldsMeta $new_df_meta */
            $new_df_meta = clone $old_datafield->getDataFieldMeta();
            $new_df_meta->setDataField($new_df);
            $new_df_meta->setFieldName('Copy of '.$old_datafield->getFieldName());

            // Ensure the "in-memory" version of $new_df knows about the new meta entry
            $new_df->addDataFieldMetum($new_df_meta);
            self::persistObject($em, $new_df_meta, $user);

            // Need to create the groups for the datafield...
            $pm_service->createGroupsForDatafield($user, $new_df);


            // Clone the old datafield's theme_datafield entry...
            /** @var ThemeDataField $new_tdf */
            $new_tdf = clone $old_theme_datafield;
            $new_tdf->setDataField($new_df);
            // Intentionally not setting displayOrder...new field should appear just after the
            //  old datafield, in theory

            // Ensure the "in-memory" theme_element knows about the new theme_datafield entry
            $theme_element->addThemeDataField($new_tdf);
            self::persistObject($em, $new_tdf, $user);

            // design_ajax.html.twig calls ReloadThemeElement()

            // Updated the cached version of the datatype and the master theme
            $dti_service->updateDatatypeCacheEntry($datatype, $user);
            $theme_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0x3db4c5ca;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves and reloads the provided object from the database.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param mixed $obj
     * @param User $user
     */
    private function persistObject($em, $obj, $user)
    {
        //
        if (method_exists($obj, "setCreated"))
            $obj->setCreated(new \DateTime());
        if (method_exists($obj, "setCreatedBy"))
            $obj->setCreatedBy($user);
        if (method_exists($obj, "setUpdated"))
            $obj->setUpdated(new \DateTime());
        if (method_exists($obj, "setUpdatedBy"))
            $obj->setUpdatedBy($user);

        $em->persist($obj);
        $em->flush();
        $em->refresh($obj);
    }


    /**
     * Gets all RadioOptions associated with a DataField, for display in the datafield properties
     * area.
     * 
     * @param integer $datafield_id The database if of the DataField to grab RadioOptions from.
     * @param Request $request
     * 
     * @return Response
     */
    public function getradiooptionsAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
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
            $source = 0x71d2cc47;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
     * @return Response
     */
    public function radiooptionnameAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Grab necessary objects
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['option_name']) )
                throw new ODRBadRequestException();


            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($radio_option_id);
            if ($radio_option == null)
                throw new ODRNotFoundException('RadioOption');

            $datafield = $radio_option->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Update the radio option's name
            $new_name = trim($post['option_name']);
            if ($radio_option->getOptionName() !== $new_name) {
                // TODO - regexp validation on new name?
                // TODO - reset xml_fieldname on change?

                // Update the radio option's name to prevent concurrency issues during CSV/XML importing
                $radio_option->setOptionName($new_name);
                $em->persist($radio_option);

                // Create a new meta entry using the new radio option's name
                $properties = array(
                    'optionName' => $new_name
                );
                parent::ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties);
            }


            // Update the cached version of the datatype...don't need to update any datarecords or themes
            $dti_service->updateDatatypeCacheEntry($datatype, $user);
        }
        catch (\Exception $e) {
            $source = 0xdf4e2574;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
     * @return Response
     */
    public function radiooptionorderAction($datafield_id, $alphabetical_sort, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            $post = $request->request->all();
//print_r($post);  exit();

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Load all RadioOptionMeta entities for this datafield
            $query = $em->createQuery(
               'SELECT rom
                FROM ODRAdminBundle:RadioOptionsMeta AS rom
                JOIN ODRAdminBundle:RadioOptions AS ro WITH rom.radioOption = ro
                WHERE ro.dataField = :datafield
                AND rom.deletedAt IS NULL AND ro.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            /** @var RadioOptionsMeta[] $results */
            $results = $query->getResult();


            if ($alphabetical_sort == 1 ) {
                // Sort the radio options by name
                self::sortRadioOptionsByName($em, $user, $datafield);
            }
            else {
                // Organize by radio option id
                $all_options_meta = array();
                foreach ($results as $radio_option_meta)
                    $all_options_meta[ $radio_option_meta->getRadioOption()->getId() ] = $radio_option_meta;
                /** @var RadioOptionsMeta[] $all_options_meta */

                // Look to the $_POST for the new order
                foreach ($post as $index => $radio_option_id) {
                    if ( !isset($all_options_meta[$radio_option_id]) )
                        throw new ODRBadRequestException();

                    $radio_option_meta = $all_options_meta[$radio_option_id];
                    $radio_option = $radio_option_meta->getRadioOption();

                    if ( $radio_option_meta->getDisplayOrder() != $index ) {
                        // This radio option should be in a different spot
                        $properties = array(
                            'displayOrder' => $index,
                        );
                        parent::ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties);
                    }
                }
            }


            // Update cached version of datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Don't need to update cached versions of datarecords or themes
        }
        catch (\Exception $e) {
            $source = 0x89f8d46f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Sorts all radio options of the given datafield by name
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param Datafields $datafield
     */
    private function sortRadioOptionsByName($em, $user, $datafield)
    {
        // Don't do anything if this datafield isn't sorting its radio options by name
        if (!$datafield->getRadioOptionNameSort())
            return;

        // Load all RadioOptionMeta entities for this datafield
        $query = $em->createQuery(
           'SELECT rom
            FROM ODRAdminBundle:RadioOptionsMeta AS rom
            JOIN ODRAdminBundle:RadioOptions AS ro WITH rom.radioOption = ro
            WHERE ro.dataField = :datafield
            AND rom.deletedAt IS NULL AND ro.deletedAt IS NULL'
        )->setParameters( array('datafield' => $datafield->getId()) );
        /** @var RadioOptionsMeta[] $results */
        $results = $query->getResult();


        // Organize by name, and re-sort the list
        $all_options_meta = array();
        foreach ($results as $radio_option_meta)
            $all_options_meta[ $radio_option_meta->getOptionName() ] = $radio_option_meta;
        ksort($all_options_meta);
        /** @var RadioOptionsMeta[] $all_options_meta */

        // Save any changes in the sort order
        $index = 0;
        foreach ($all_options_meta as $option_name => $radio_option_meta) {
            $radio_option = $radio_option_meta->getRadioOption();

            if ($radio_option_meta->getDisplayOrder() != $index) {
                // This radio option should be in a different spot
                $properties = array(
                    'displayOrder' => $index,
                );
                parent::ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties);
            }

            $index++;
        }
    }


    /**
     * Adds a new RadioOption entity to a SingleSelect, MultipleSelect, SingleRadio, or MultipleRadio DataField.
     * 
     * @param integer $datafield_id The database id of the DataField to add a RadioOption to.
     * @param Request $request
     * 
     * @return Response
     */
    public function addradiooptionAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Create a new RadioOption
            $force_create = true;
            /*$radio_option = */parent::ODR_addRadioOption($em, $user, $datafield, $force_create);
            $em->flush();
//            $em->refresh($radio_option);

            // If the datafield is sorting its radio options by name, then resort all of this datafield's radio options again
            if ($datafield->getRadioOptionNameSort() == true)
                self::sortRadioOptionsByName($em, $user, $datafield);


            // Update the cached version of the datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Don't need to update cached versions of datarecords or themes
        }
        catch (\Exception $e) {
            $source = 0x33ef7d94;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Adds a new child DataType to this DataType.
     *
     * @param integer $theme_element_id The database id of the ThemeElement that the new DataType will be rendered in.
     * @param Request $request
     * 
     * @return Response
     */
    public function addchilddatatypeAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $parent_datatype = $theme->getDataType();
            if ($parent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $parent_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure that this action isn't being called on a derivative theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to create a new child Datatype outside of the master Theme');

            // Ensure there are no datafields in this theme_element before going and creating a child datatype
            /** @var ThemeDataField[] $theme_datafields */
            $theme_datafields = $em->getRepository('ODRAdminBundle:ThemeDataField')->findBy( array('themeElement' => $theme_element_id) );
            if ( count($theme_datafields) > 0 )
                throw new ODRBadRequestException('Unable to add a child Datatype into a ThemeElement that already has Datafields');


            // ----------------------------------------
            // Defaults
            /** @var RenderPlugin $render_plugin */
            $default_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);

            // Create the new child Datatype
            $child_datatype = new DataType();
            $child_datatype->setRevision(0);
            $child_datatype->setParent($parent_datatype);
            $child_datatype->setGrandparent( $parent_datatype->getGrandparent() );
            $child_datatype->setCreatedBy($user);
            $child_datatype->setUpdatedBy($user);

            $child_datatype->setIsMasterType(false);
            $child_datatype->setSetupStep(DataType::STATE_INITIAL);
            if ($parent_datatype->getIsMasterType())
                $child_datatype->setIsMasterType(true);

            // Save all changes made
            $em->persist($child_datatype);
            $em->flush();
            $em->refresh($child_datatype);

            // Create the associated metadata entry for this new child datatype
            $datatype_meta = new DataTypeMeta();
            $datatype_meta->setDataType($child_datatype);
            $datatype_meta->setRenderPlugin($default_render_plugin);

            $datatype_meta->setSearchSlug(null);
            $datatype_meta->setShortName("New Child");
            $datatype_meta->setLongName("New Child");
            $datatype_meta->setDescription("New Child Type");
            $datatype_meta->setXmlShortName('');

            $datatype_meta->setPublicDate( new \DateTime('1980-01-01 00:00:00') );

            $datatype_meta->setExternalIdField(null);
            $datatype_meta->setNameField(null);
            $datatype_meta->setSortField(null);
            $datatype_meta->setBackgroundImageField(null);

            $datatype_meta->setMasterPublishedRevision(0);
            $datatype_meta->setMasterRevision(0);
            $datatype_meta->setTrackingMasterRevision(0);

            // Set the initial Master Revision
            if ($child_datatype->getIsMasterType())
                $datatype_meta->setMasterRevision(1);

            $datatype_meta->setCreatedBy($user);
            $datatype_meta->setUpdatedBy($user);

            // Ensure the "in-memory" version of the new child Datatype entry knows about its meta entry
            $child_datatype->addDataTypeMetum($datatype_meta);
            $em->persist($datatype_meta);


            // Create a new DataTree entry to link the original datatype and this new child datatype
            $datatree = new DataTree();
            $datatree->setAncestor($parent_datatype);
            $datatree->setDescendant($child_datatype);
            $datatree->setCreatedBy($user);
            $em->persist($datatree);


            // Create a new master theme for this new child datatype
            $child_theme = new Theme();
            $child_theme->setDataType($child_datatype);
            $child_theme->setThemeType('master');
            $child_theme->setParentTheme( $theme->getParentTheme() );
            $child_theme->setCreatedBy($user);
            $child_theme->setUpdatedBy($user);

            // Ensure the "in-memory" version of the new child Datatype entry knows about its master theme
            $child_datatype->addTheme($child_theme);
            $em->persist($child_theme);


            $em->flush();
            $em->refresh($datatree);
            $em->refresh($child_theme);

            // Master themes for child datatypes are their own source theme
            $child_theme->setSourceTheme($child_theme);
            $em->persist($child_theme);

            // Create a new DataTreeMeta entity to store properties of the DataTree
            $datatree_meta = new DataTreeMeta();
            $datatree_meta->setDataTree($datatree);
            $datatree_meta->setIsLink(false);
            $datatree_meta->setMultipleAllowed(true);
            $datatree_meta->setCreatedBy($user);
            $datatree_meta->setUpdatedBy($user);

            // Ensure the "in-memory" version of the new Datatree entry knows about its meta entry
            $datatree->addDataTreeMetum($datatree_meta);
            $em->persist($datatree_meta);

            // Create a new ThemeMeta entity to store properties of the childtype's Theme
            $theme_meta = new ThemeMeta();
            $theme_meta->setTheme($child_theme);
            $theme_meta->setTemplateName('');
            $theme_meta->setTemplateDescription('');
            $theme_meta->setIsDefault(true);
            $theme_meta->setShared(true);
            $theme_meta->setIsTableTheme(false);
            $theme_meta->setCreatedBy($user);
            $theme_meta->setUpdatedBy($user);

            // Ensure the "in-memory" version of the new Theme entry knows about its meta entry
            $theme->addThemeMetum($theme_meta);
            $em->persist($theme_meta);


            // ----------------------------------------
            // Create a new ThemeDatatype entry to let the renderer know it has to render a child datatype in this ThemeElement
            parent::ODR_addThemeDatatype($em, $user, $child_datatype, $theme_element);
            $em->flush();


            // Child datatype is technically viewable now...still needs permissions, but that should be dealt with briefly
            // Group creation through the permissions service currently requires the setup step to not be 'initial'
            $child_datatype->setSetupStep(DataType::STATE_INCOMPLETE);
            $em->persist($child_datatype);
            $em->flush();
            $em->refresh($child_datatype);

            // Delete the cached version of the datatree array because a child datatype was created
            $cache_service->delete('cached_datatree_array');

            // Create the default groups for this child datatype
            $pm_service->createGroupsForDatatype($user, $child_datatype);


            // ----------------------------------------
            // Update the cached version of this datatype
            $dti_service->updateDatatypeCacheEntry($parent_datatype, $user);
            // Do the same for the cached version of this theme
            $theme_service->updateThemeCacheEntry($theme, $user);

        }
        catch (\Exception $e) {
            $source = 0xe1cadbac;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
     * @param integer $datatype_id  The id of the Datatype that might be having its RenderPlugin changed
     * @param integer $datafield_id The id of the Datafield that might be having its RenderPlugin changed
     * @param Request $request
     * 
     * @return Response
     */
    public function plugindialogAction($datatype_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Grab necessary objects
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dt_admin' ])) )
                throw new ODRForbiddenException();
            // --------------------

            // Need to specify either a datafield or a datatype...
            if ($datafield_id == 0 && $datatype_id == 0)
                throw new ODRBadRequestException();

            // Pre-define...
            $datatype = null;
            $datafield = null;
            $current_render_plugin = null;
            $render_plugins = null;
            $render_plugin_instance = null;

            // If datafield id isn't defined, this is a render plugin for the entire datatype
            if ($datafield_id == 0) {
                // Locate required entities
                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                if ($datatype == null)
                    throw new ODRNotFoundException('Datatype');

                $current_render_plugin = $datatype->getRenderPlugin();

                // Grab available render plugins for this datatype
                $render_plugins = array();
                /** @var RenderPlugin[] $all_render_plugins */
                $all_render_plugins = $repo_render_plugin->findAll();
                // TODO This query would be better with parameters rather than a for loop.
                foreach ($all_render_plugins as $plugin) {
                    if ($plugin->getPluginType() <= 2 && $plugin->getActive() == 1)  // 1: datatype only plugins...2: both...3: datafield only plutins
                        $render_plugins[] = $plugin;
                }

                // Attempt to grab the field mapping between this render plugin and this datatype
                /** @var RenderPluginInstance $render_plugin_instance */
                $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $current_render_plugin, 'dataType' => $datatype) );
            }
            else {
                // ...otherwise, this is a render plugin for a specific datafield
                // Locate required entities
                /** @var DataFields $datafield */
                $datafield = $repo_datafield->find($datafield_id);
                if ($datafield == null)
                    throw new ODRNotFoundException('Datafield');
                $datatype = $datafield->getDataType();
                if ($datatype->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datatype');

                $current_render_plugin = $datafield->getRenderPlugin();

                // Grab available render plugins for this datafield
                $render_plugins = array();
                /** @var RenderPlugin[] $all_render_plugins */
                // TODO This query would be better with parameters rather than a for loop.
                $all_render_plugins = $repo_render_plugin->findAll();
                foreach ($all_render_plugins as $plugin) {
                    if ($plugin->getPluginType() >= 2 && $plugin->getActive() == 1) // 1: datatype only plugins...2: both...3: datafield only plugins
                        $render_plugins[] = $plugin;
                }

                /** @var RenderPluginInstance $render_plugin_instance */
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
            $source = 0x9a07165b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads and renders required DataFields and plugin options for the selected Render Plugin.
     *
     * @param integer|null $datatype_id  The id of the Datatype that might be having its RenderPlugin changed
     * @param integer|null $datafield_id The id of the Datafield that might be having its RenderPlugin changed
     * @param integer $render_plugin_id  The database id of the RenderPlugin to look up.
     * @param Request $request
     * 
     * @return Response
     */
    public function pluginsettingsAction($datatype_id, $datafield_id, $render_plugin_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Grab necessary objects
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dt_admin' ])) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure the relevant entities exist
            /** @var DataType $datatype */
            $datatype = null;
            /** @var DataFields|null $datafield */
            $datafield = null;
            /** @var DataFields[]|null $all_datafields */
            $all_datafields = null; // of datatype

            if ($datafield_id == 0) {
                // Locate required entities
                $datatype = $repo_datatype->find($datatype_id);
                if ($datatype == null)
                    throw new ODRNotFoundException('Datatype');

                $all_datafields = $repo_datafield->findBy(array('dataType' => $datatype));
            }
            else {
                // Locate required entities
                $datafield = $repo_datafield->find($datafield_id);
                if ($datafield == null)
                    throw new ODRNotFoundException('Datafield');
                $datatype = $datafield->getDataType();
                if ($datatype->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datatype');
            }


            /** @var RenderPlugin $current_render_plugin */
            $current_render_plugin = $repo_render_plugin->find($render_plugin_id);
            if ($current_render_plugin == null)
                throw new ODRNotFoundException('RenderPlugin');

            $all_fieldtypes = array();
            /** @var FieldType[] $tmp */
            $tmp = $repo_fieldtype->findAll();
            foreach ($tmp as $fieldtype)
                $all_fieldtypes[ $fieldtype->getId() ] = $fieldtype;

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
            if ( count($results) > 0 ) {
                // Only want the most recent RenderPluginInstance
                foreach ($results as $result)
                    $render_plugin_instance = $result;
            }
            /** @var RenderPluginInstance|null $render_plugin_instance */

            $render_plugin_map = null;
            if ($render_plugin_instance != null)
                $render_plugin_map = $repo_render_plugin_map->findBy( array('renderPluginInstance' => $render_plugin_instance) );
            /** @var RenderPluginMap|null $render_plugin_map */


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
                        // Just rethrow the exception
                        throw $e;
                    }
                }
            }
//print_r($plugin_options);  exit();


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

                    /** @var RenderPlugin $plugin */
                    // TODO Find out why we can't use the ID here - Names are not valid database ids
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
            if ( is_array($required_fields) ) {
                foreach ($required_fields as $plugin_id => $data) {
                    foreach ($data as $field_key => $field_data) {
                        $field_name = $field_data['name'];
                        $required_fields[$field_name] = $field_data;
                    }
                    unset($required_fields[$plugin_id]);
                }
            }
/*
print '<pre>'.print_r($required_fields, true).'</pre>';
print '<pre>'.print_r($available_options, true).'</pre>';
exit();
*/

            // Ensure the config file and the database are synched in regards to required fields for this render plugin
            $allowed_fieldtypes = array();
            /** @var RenderPluginFields[] $current_render_plugin_fields */
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
                    $allowed_typeclasses = explode('|', $field['type']);

                    // Save the fieldtypes allowed for this field
                    $allowed_fieldtypes[$rpf_id] = array();
                    foreach ($allowed_typeclasses as $allowed_typeclass) {
                        /** @var FieldType $ft */
                        $ft = $repo_fieldtype->findOneBy( array('typeClass' => $allowed_typeclass) );
                        if ($ft == null)
                            throw new ODRException('RenderPlugin "'.$current_render_plugin->getPluginName().'" config: Invalid Fieldtype "'.$allowed_typeclass.'" in list for field "'.$field_name.'"');

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
            if ( is_array($required_fields) ) {
                foreach ($required_fields as $field) {
                    // Pull the entity's attributes from the config file
                    $field_name = $field['name'];
                    $description = $field['description'];
                    $allowed_typeclasses = explode('|', $field['type']);

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
                    foreach ($allowed_typeclasses as $allowed_typeclass) {
                        /** @var FieldType $ft */
                        $ft = $repo_fieldtype->findOneBy(array('typeClass' => $allowed_typeclass));
                        if ($ft == null)
                            throw new ODRException('RenderPlugin "'.$current_render_plugin->getPluginName().'" config: Invalid Fieldtype "'.$allowed_typeclass.'" in list for field "'.$field_name.'"');

                        $allowed_fieldtypes[$rpf_id][] = $ft->getId();
                    }
                    $rpf->setAllowedFieldtypes(implode(',', $allowed_fieldtypes[$rpf_id]));

                    $em->persist($rpf);
                    $em->flush();
                    $em->refresh($rpf);
                }
            }

            // Now that db and config file are synched, reload the required fields
            $em->flush();
            /** @var RenderPluginFields[] $render_plugin_fields */
            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $current_render_plugin) );

//print 'allowed fieldtypes: '.print_r($allowed_fieldtypes, true)."\n";

            // ----------------------------------------
            // Grab the current batch of settings for this instance of the render plugin
            /** @var RenderPluginOptions[] $current_plugin_options */
            $current_plugin_options = $repo_render_plugin_options->findBy( array('renderPluginInstance' => $render_plugin_instance) );

            if ( $return['r'] == 0 ) {
                $templating = $this->get('templating');
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Displaytemplate:plugin_settings_dialog_form_data.html.twig',
                        array(
                            'local_datatype' => $datatype,
                            'local_datafield' => $datafield,
                            'datafields' => $all_datafields,

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
            $source = 0x001bf2cc;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
     * @return Response
     */
    public function saverenderpluginsettingsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab the data from the POST request
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['local_datafield_id']) || !isset($post['local_datatype_id']) || !isset($post['render_plugin_instance_id']) || !isset($post['previous_render_plugin']) || !isset($post['selected_render_plugin']) )
                throw new ODRBadRequestException('Invalid Form');

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


            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $local_datatype_id ]) && isset($datatype_permissions[ $local_datatype_id ][ 'dt_admin' ])) )
                throw new ODRForbiddenException();
            // --------------------


            /** @var DataType|null $target_datatype */
            $target_datatype = null;    // the datatype that is getting its render plugin modified

            /** @var DataFields|null $target_datafield */
            $target_datafield = null;   // the datafield that is getting its render plugin modified
            /** @var DataType $associated_datatype */
            $associated_datatype = null;

            $reload_datatype = false;

            $changing_datatype_plugin = false;
            $changing_datafield_plugin = false;

            if ($local_datafield_id == 0) {
                $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($local_datatype_id);
                if ($target_datatype == null)
                    throw new ODRNotFoundException('Datatype');

                $associated_datatype = $target_datatype;
                $changing_datatype_plugin = true;
            }
            else {
                $target_datafield = $repo_datafield->find($local_datafield_id);
                if ($target_datafield == null)
                    throw new ODRNotFoundException('Datafield');
                $associated_datatype = $target_datafield->getDataType();

                $changing_datafield_plugin = true;
            }


            /** @var RenderPlugin $render_plugin */
            $render_plugin = $repo_render_plugin->find($selected_plugin_id);
            if ( $render_plugin == null )
                throw new ODRNotFoundException('RenderPlugin');

            // 1: datatype only  2: both datatype and datafield  3: datafield only
            if ($changing_datatype_plugin && $render_plugin->getPluginType() == 1 && $target_datatype == null)
                throw new ODRBadRequestException('Unable to save a Datatype plugin to a Datafield');
            else if ($changing_datafield_plugin && $render_plugin->getPluginType() == 3 && $target_datafield == null)
                throw new ODRBadRequestException('Unable to save a Datafield plugin to a Datatype');
            else if ($render_plugin->getPluginType() == 2 && $target_datatype == null && $target_datafield == null)
                throw new ODRBadRequestException('No target specified for the Render Plugin');


            // ----------------------------------------
            // Ensure the plugin map doesn't have multiple the same datafield mapped to multiple renderplugin_fields
            $mapped_datafields = array();
            foreach ($plugin_map as $rpf_id => $df_id) {
                if ($df_id != '-1') {
                    if ( isset($mapped_datafields[$df_id]) )
                        throw new ODRBadRequestException('Invalid Form...multiple datafields mapped to the same renderpluginfield');

                    $mapped_datafields[$df_id] = 0;
                }
            }

            // Ensure the datafields in the plugin map are the correct fieldtype, and that none of the fields required for the plugin are missing
            /** @var RenderPluginFields[] $render_plugin_fields */
            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $render_plugin) );
            foreach ($render_plugin_fields as $rpf) {
                $rpf_id = $rpf->getId();

                // Ensure all required datafields for this RenderPlugin are listed in the $_POST
                if ( !isset($plugin_map[$rpf_id]) )
                    throw new ODRBadRequestException('Invalid Form...missing datafield mapping');
                // Ensure that all datafields marked as "new" have a fieldtype mapping
                if ($plugin_map[$rpf_id] == '-1' && !isset($plugin_fieldtypes[$rpf_id]) )
                    throw new ODRBadRequestException('Invalid Form...missing fieldtype mapping');

                if ($plugin_map[$rpf_id] != '-1') {
                    // Ensure all required datafields have a valid fieldtype
                    $allowed_fieldtypes = $rpf->getAllowedFieldtypes();
                    $allowed_fieldtypes = explode(',', $allowed_fieldtypes);

                    // Ensure referenced datafields exist
                    /** @var DataFields $df */
                    $df = $repo_datafield->find( $plugin_map[$rpf_id] );
                    if ($df == null)
                        throw new ODRNotFoundException('Invalid Form...datafield does not exist');

                    // Ensure referenced datafields have a valid fieldtype for this renderpluginfield
                    $ft_id = $df->getFieldType()->getId();
                    if ( !in_array($ft_id, $allowed_fieldtypes) )
                        throw new ODRBadRequestException('Invalid Form...attempting to map renderpluginfield to invalid fieldtype');
                }
            }

            // TODO - ensure plugin options are valid?


            // ----------------------------------------
            // Create any new datafields required
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $associated_datatype->getId(), 'themeType' => 'master') );

            $theme_element = null;
            foreach ($plugin_fieldtypes as $rpf_id => $ft_id) {
                // Since new datafields are being created, instruct ajax success handler in plugin_settings_dialog.html.twig to call ReloadChild() afterwards
                $reload_datatype = true;

                // Create a single new ThemeElement to store the new datafields in, if necessary
                if ($theme_element == null) {
                    $data = parent::ODR_addThemeElement($em, $user, $theme);
                    $theme_element = $data['theme_element'];
                    //$theme_element_meta = $data['theme_element_meta'];
                }

                // Load information for the new datafield
                /** @var RenderPlugin $default_render_plugin */
                $default_render_plugin = $repo_render_plugin->find(1);
                /** @var FieldType $fieldtype */
                $fieldtype = $em->getRepository('ODRAdminBundle:FieldType')->find($ft_id);
                if ($fieldtype == null)
                    throw new ODRBadRequestException('Invalid Form');
                /** @var RenderPluginFields $rpf */
                $rpf = $repo_render_plugin_fields->find($rpf_id);


                // Create the Datafield and set basic properties from the render plugin settings
                $objects = parent::ODR_addDataField($em, $user, $associated_datatype, $fieldtype, $default_render_plugin);
                /** @var DataFields $datafield */
                $datafield = $objects['datafield'];
                /** @var DataFieldsMeta $datafield_meta */
                $datafield_meta = $objects['datafield_meta'];

                $datafield_meta->setFieldName( $rpf->getFieldName() );
                $datafield_meta->setDescription( $rpf->getDescription() );
                $em->persist($datafield_meta);


                // Attach the new datafield to the previously created theme_element
                parent::ODR_addThemeDataField($em, $user, $datafield, $theme_element);

                // Now that the datafield exists, update the plugin map
                $em->refresh($datafield);
                $plugin_map[$rpf_id] = $datafield->getId();

                if ($fieldtype->getTypeClass() == 'Image')
                    parent::ODR_checkImageSizes($em, $user, $datafield);
            }

            // If new datafields created, flush entity manager to save the theme_element and datafield meta entries
            if ($reload_datatype)
                $em->flush();


            // ----------------------------------------
            // Mark the Datafield/Datatype as using the selected RenderPlugin
            // 1: datatype only  2: both datatype and datafield  3: datafield only
            if ($changing_datatype_plugin) {
                $properties = array(
                    'renderPlugin' => $render_plugin->getId()
                );
                parent::ODR_copyDatatypeMeta($em, $user, $target_datatype, $properties);
            }
            else if ($changing_datafield_plugin) {
                $properties = array(
                    'renderPlugin' => $render_plugin->getId()
                );
                parent::ODR_copyDatafieldMeta($em, $user, $target_datafield, $properties);
            }


            // ...delete the old render plugin instance object if the user changed render plugins
            $render_plugin_instance = null;
            if ($render_plugin_instance_id != '') {
                $render_plugin_instance = $repo_render_plugin_instance->find($render_plugin_instance_id);

                if ( $previous_plugin_id != $selected_plugin_id && $render_plugin_instance != null ) {
                    $em->remove($render_plugin_instance);
                    $render_plugin_instance = null;
                }
            }


            // ----------------------------------------
            if ($render_plugin->getId() != 1) {
                // If not using the default RenderPlugin, create a RenderPluginInstance if needed
                if ($render_plugin_instance == null)
                    $render_plugin_instance = parent::ODR_addRenderPluginInstance($em, $user, $render_plugin, $target_datatype, $target_datafield);
                /** @var RenderPluginInstance $render_plugin_instance */

//print 'rpi id: '.$render_plugin_instance->getId()."\n";

                // Save the field mapping
                foreach ($plugin_map as $rpf_id => $df_id) {
                    // Attempt to locate the mapping for this render plugin field field in this instance
                    /** @var RenderPluginMap $render_plugin_map */
//print 'attempting to locate render_plugin_map pointed to by rpi '.$render_plugin_instance->getId().' rpf '.$rpf_id."\n";
                    $render_plugin_map = $repo_render_plugin_map->findOneBy( array('renderPluginInstance' => $render_plugin_instance->getId(), 'renderPluginFields' => $rpf_id) );


                    // If the render plugin map entity doesn't exist, create it
                    if ($render_plugin_map == null) {
                        // Locate the render plugin field object being referenced
                        /** @var RenderPluginFields $render_plugin_field */
                        $render_plugin_field = $repo_render_plugin_fields->find($rpf_id);

                        // Locate the desired datafield object...already checked for its existence earlier
                        /** @var DataFields $df */
                        $df = $repo_datafield->find($df_id);

                        parent::ODR_addRenderPluginMap($em, $user, $render_plugin_instance, $render_plugin_field, $associated_datatype, $df);
//print '-- created new'."\n";
                    }
                    else {
                        // ...otherwise, update the existing entity
                        $properties = array(
                            'dataField' => $df_id
                        );
                        parent::ODR_copyRenderPluginMap($em, $user, $render_plugin_map, $properties);
//print '-- updated existing rpm '.$render_plugin_map->getId()."\n";
                    }
                }

                // Save the plugin options
                foreach ($plugin_options as $option_name => $option_value) {
                    // Attempt to locate this particular render plugin option in this instance
                    /** @var RenderPluginOptions $render_plugin_option */
//print 'attempting to locate render_plugin_option pointed to by rpi '.$render_plugin_instance->getId().' optionName "'.$option_name.'"'."\n";
                    $render_plugin_option = $repo_render_plugin_options->findOneBy( array('renderPluginInstance' => $render_plugin_instance->getId(), 'optionName' => $option_name) );


                    // If the render plugin option entity doesn't exist, create it
                    if ($render_plugin_option == null) {
                        parent::ODR_addRenderPluginOption($em, $user, $render_plugin_instance, $option_name, $option_value);
//print '-- created new'."\n";
                    }
                    else {
                        // ...otherwise, update the existing entity
                        $properties = array(
                            'optionValue' => $option_value
                        );
                        parent::ODR_copyRenderPluginOption($em, $user, $render_plugin_option, $properties);
//print '-- updated existing rpo '.$render_plugin_option->getId()."\n";
                    }
                }
            }


            // Deal with updating field & datatype
            if ($local_datafield_id == 0) {
                // Master Template Data Types must increment Master Revision on all change requests.
                if ($target_datatype->getIsMasterType()) {
                    $dtm_properties['master_revision'] = $target_datatype->getDataTypeMeta()->getMasterRevision() + 1;
                    parent::ODR_copyDatatypeMeta($em, $user, $target_datatype, $dtm_properties);
                }
            }
            else {
                // Master Template Data Types must increment Master Revision on all change requests.
                if ($target_datafield->getIsMasterField()) {
                    $dfm_properties['master_revision'] = $target_datafield->getDataFieldMeta()->getMasterRevision() + 1;
                    parent::ODR_copyDatafieldMeta($em, $user, $target_datafield, $dfm_properties);
                }
            }


            /** @var RenderPlugin $render_plugin */
            $render_plugin = $repo_render_plugin->find($selected_plugin_id);

            $em->flush();

            $return['d'] = array(
                'datafield_id' => $local_datafield_id,
                'datatype_id' => $local_datatype_id,
                'render_plugin_id' => $render_plugin->getId(),
                'render_plugin_name' => $render_plugin->getPluginName(),
                'html' => '',

                'reload_datatype' => $reload_datatype,
            );


            // TODO - figure out what the hell to update here and where
            $dti_service->updateDatatypeCacheEntry($associated_datatype, $user);
        }
        catch (\Exception $e) {
            $source = 0x75fbef09;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a child DataType div in the design.
     *
     * @param integer $source_datatype_id  The database id of the top-level Datatype
     * @param integer $datatype_id         The database id of the child DataType that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response
     */
    public function reloadchildAction($source_datatype_id, $datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                throw new ODRNotFoundException('Source Datatype');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException("Not allowed to re-render something that doesn't belong to the master Theme");


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $source_datatype) )
                throw new ODRForbiddenException();
            // --------------------

            $return['d'] = array(
                'datatype_id' => $datatype_id,
                'html' => self::GetDisplayData($em, $source_datatype_id, 'child_datatype', $datatype_id, $request),
            );
        }
        catch (\Exception $e) {
            $source = 0x940ecdfe;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a ThemeElement in the design.
     *
     * @param integer $source_datatype_id  The database id of the top-level datatype being rendered?
     * @param integer $theme_element_id    The database id of the ThemeElement that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response
     */
    public function reloadthemeelementAction($source_datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                throw new ODRNotFoundException('Source Datatype');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                throw new ODRNotFoundException('Theme');
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException("Not allowed to re-render something that doesn't belong to the master Theme");

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $source_datatype) )
                throw new ODRForbiddenException();
            // --------------------

            $datatype_id = null;
            $return['d'] = array(
                'theme_element_id' => $theme_element_id,
                'html' => self::GetDisplayData($em, $source_datatype_id, 'theme_element', $theme_element_id, $request),
            );
        }
        catch (\Exception $e) {
            $source = 0xf0be4790;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a DataField in the design.
     *
     * @param integer $source_datatype_id
     * @param integer $datafield_id       The database id of the DataField that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response
     */
    public function reloaddatafieldAction($source_datatype_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                throw new ODRNotFoundException('Source Datatype');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException("Not allowed to re-render something that doesn't belong to the master Theme");


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $source_datatype) || !$pm_service->canViewDatafield($user, $datafield) )
                throw new ODRForbiddenException();
            // --------------------

            $datatype_id = null;
            $return['d'] = array(
                'datafield_id' => $datafield_id,
                'html' => self::GetDisplayData($em, $source_datatype_id, 'datafield', $datafield_id, $request),
            );
        }
        catch (\Exception $e) {
            $source = 0xe45c0214;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns the HTML for a DesignTemplate version of a DataType.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $source_datatype_id  The datatype that originally requested this Displaytemplate rendering
     * @param string $template_name        One of 'default', 'child_datatype', 'theme_element', 'datafield'
     * @param integer $target_id           If $template_name == 'default', then $target_id should be a top-level datatype id
     *                                     If $template_name == 'child_datatype', then $target_id should be a child/linked datatype id
     *                                     If $template_name == 'theme_element', then $target_id should be a theme_element id
     *                                     If $template_name == 'datafield', then $target_id should be a datafield id
     * @param Request $request
     *
     * @throws ODRException
     *
     * @return string
     */
    private function GetDisplayData($em, $source_datatype_id, $template_name, $target_id, Request $request)
    {
        // ----------------------------------------
        // Don't need to check permissions

        // Required objects
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');
        /** @var ThemeInfoService $theme_service */
        $theme_service = $this->container->get('odr.theme_info_service');


        // Going to need this a lot...
        $datatree_array = $dti_service->getDatatreeArray();


        // ----------------------------------------
        // Load required objects based on parameters...don't need to check whether they're deleted
        /** @var DataType $datatype */
        $datatype = null;
        /** @var Theme $theme */
        $theme = null;

        /** @var DataType|null $child_datatype */
        $child_datatype = null;
        /** @var ThemeElement|null $theme_element */
        $theme_element = null;
        /** @var DataFields|null $datafield */
        $datafield = null;


        if ($template_name == 'default') {
            $datatype = $repo_datatype->find($target_id);
//            $theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
        }
        else if ($template_name == 'child_datatype') {
            $child_datatype = $repo_datatype->find($target_id);
//            $theme = $repo_theme->findOneBy( array('dataType' => $child_datatype->getId(), 'themeType' => 'master') );

            // Need to determine the top-level datatype to be able to load all necessary data for rendering this child datatype
            if ( isset($datatree_array['descendant_of'][ $child_datatype->getId() ]) && $datatree_array['descendant_of'][ $child_datatype->getId() ] !== '' ) {
                $datatype = $child_datatype->getGrandparent();
            }
            else if ( !isset($datatree_array['descendant_of'][ $child_datatype->getId() ]) || $datatree_array['descendant_of'][ $child_datatype->getId() ] == '' ) {
                // Was actually a re-render request for a top-level datatype...re-rendering should still work properly if various flags are set right
                $datatype = $child_datatype;
            }
        }
        else if ($template_name == 'theme_element') {
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($target_id);
            $theme = $theme_element->getTheme();

            // This could be a theme element from a child datatype...make sure objects get set properly if it is
            $datatype = $theme->getDataType();
            if ( isset($datatree_array['descendant_of'][ $datatype->getId() ]) && $datatree_array['descendant_of'][ $datatype->getId() ] !== '' ) {
                $child_datatype = $theme->getDataType();
                $datatype = $child_datatype->getGrandparent();
            }
        }
        else if ($template_name == 'datafield') {
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($target_id);
            $child_datatype = $datafield->getDataType();
            $theme = $repo_theme->findOneBy( array('dataType' => $child_datatype->getId(), 'themeType' => 'master') );

            // This could be a datafield from a child datatype...make sure objects get set properly if it is
            $datatype = $datafield->getDataType();
            if ( isset($datatree_array['descendant_of'][ $datatype->getId() ]) && $datatree_array['descendant_of'][ $datatype->getId() ] !== '' ) {
                $child_datatype = $theme->getDataType();
                $datatype = $child_datatype->getGrandparent();
            }
        }


        // ----------------------------------------
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        $user_permissions = $pm_service->getUserPermissionsArray($user);
        $datatype_permissions = $pm_service->getDatatypePermissions($user);

        // Store whether the user is an admin of this datatype...this usually is true, but the user
        //  may not have the permission if this function is reloading stuff for a linked datatype
        $is_datatype_admin = $pm_service->isDatatypeAdmin($user, $datatype);


        // ----------------------------------------
        // Grab the cached version of the grandparent datatype
        $include_links = true;
        $datatype_array = $dti_service->getDatatypeArray($datatype->getId(), $include_links);
//print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();

        // Also grab the cached version of the grandparent datatype's master theme
        $theme_array = $theme_service->getThemesForDatatype($datatype->getId(), $user, 'master', $include_links);
//print '<pre>'.print_r($theme_array, true).'</pre>'; exit();

        // Due to the possibility of linked datatypes the user may not have permissions for, the
        //  datatype array needs to be filtered.  TODO - should it also get stacked in the future?
        $datarecord_array = array();
        $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);
//print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();


        // ----------------------------------------
        // Need an array of fieldtype ids and typenames for notifications when changing fieldtypes
        $fieldtype_array = array();
        /** @var FieldType[] $fieldtypes */
        $fieldtypes = $em->getRepository('ODRAdminBundle:FieldType')->findAll();
        foreach ($fieldtypes as $fieldtype)
            $fieldtype_array[ $fieldtype->getId() ] = $fieldtype->getTypeName();

        // Store whether this datatype has datarecords..affects warnings when changing fieldtypes
        $query = $em->createQuery(
           'SELECT COUNT(dr) AS dr_count
            FROM ODRAdminBundle:DataRecord AS dr
            WHERE dr.dataType = :datatype_id'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        $has_datarecords = false;
        if ( $results[0]['dr_count'] > 0 )
            $has_datarecords = true;


        // ----------------------------------------
        // Render the required version of the page
        $templating = $this->get('templating');

        $html = '';
        if ($template_name == 'default') {
            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:design_ajax.html.twig',
                array(
                    'datatype_array' => $datatype_array,
                    'initial_datatype_id' => $datatype->getId(),
                    'theme_array' => $theme_array,

                    'datatype_permissions' => $datatype_permissions,

                    'fieldtype_array' => $fieldtype_array,
                    'has_datarecords' => $has_datarecords,
                )
            );
        }
        else if ($template_name == 'child_datatype') {

            // Set variables properly incase this was a theme_element for a child/linked datatype
            $target_datatype_id = $child_datatype->getId();
            $is_top_level = 1;
            if ($child_datatype->getId() !== $datatype->getId())
                $is_top_level = 0;


            // If the top-level datatype id found doesn't match the original datatype id of the
            //  design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $datatype->getId()) {
                $is_top_level = 0;
                $is_link = 1;
            }

            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:design_childtype.html.twig',
                array(
                    'datatype_array' => $datatype_array,
                    'target_datatype_id' => $target_datatype_id,
                    'theme_array' => $theme_array,

                    'datatype_permissions' => $datatype_permissions,
                    'is_datatype_admin' => $is_datatype_admin,

                    'is_link' => $is_link,
                    'is_top_level' => $is_top_level,
                )
            );
        }
        else if ($template_name == 'theme_element') {

            // Set variables properly incase this was a theme_element for a child/linked datatype
            $target_datatype_id = $datatype->getId();
            $is_top_level = 1;
            if ($child_datatype !== null) {
                $target_datatype_id = $child_datatype->getId();
                $is_top_level = 0;
            }

            // If the top-level datatype id found doesn't match the original datatype id of the
            //  design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $datatype->getId())
                $is_link = 1;

            // design_fieldarea.html.twig attempts to render all theme_elements in the given theme,
            //  but this request is to only re-render one of them...unset all theme_elements except
            //  the one that's being re-rendered
            foreach ($theme_array[$target_datatype_id]['themeElements'] as $te_num => $te) {
                if ( $te['id'] != $target_id )
                    unset( $theme_array[$target_datatype_id]['themeElements'][$te_num] );
            }
//print '<pre>'.print_r($theme_array, true).'</pre>'; exit();

            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:design_fieldarea.html.twig',
                array(
                    'datatype_array' => $datatype_array,
                    'target_datatype_id' => $target_datatype_id,
                    'theme_array' => $theme_array,

                    'datatype_permissions' => $datatype_permissions,
                    'is_datatype_admin' => $is_datatype_admin,

                    'is_top_level' =>  $is_top_level,
                    'is_link' => $is_link,
                )
            );
        }
        else if ($template_name == 'datafield') {

            // Locate the array versions of the requested datafield and its associated theme_datafield entry
            $datafield_array = null;
            $theme_datafield_array = null;

            if ( isset($datatype_array[ $child_datatype->getId() ]['dataFields'][ $datafield->getId() ]) )
                $datafield_array = $datatype_array[ $child_datatype->getId() ]['dataFields'][ $datafield->getId() ];

            foreach ($theme_array[ $child_datatype->getId() ]['themeElements'] as $te_num => $te) {
                if ( isset($te['themeDataFields']) ) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        if ( isset($tdf['dataField']) && $tdf['dataField']['id'] == $datafield->getId() ) {
                            $theme_datafield_array = $tdf;
                            break;
                        }
                    }
                }

                if ($theme_datafield_array !== null)
                    break;
            }

            if ( $datafield_array == null )
                throw new ODRException('Unable to locate array entry for datafield '.$datafield->getId());
            if ( $theme_datafield_array == null )
                throw new ODRException('Unable to locate theme array entry for datafield '.$datafield->getId());

            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:design_datafield.html.twig',
                array(
                    'theme_datafield' => $theme_datafield_array,
                    'datafield' => $datafield_array,

                    'is_datatype_admin' => $is_datatype_admin,
                )
            );
        }

        return $html;
    }


    /**
     * Loads/saves a Symfony DataType properties Form, and chain-loads Datatree and ThemeDataType properties forms as well.
     * 
     * @param integer $datatype_id       The database id of the Datatype that is being modified
     * @param mixed $parent_datatype_id  Either the id of the Datatype of the parent of $datatype_id, or the empty string
     * @param Request $request
     * 
     * @return Response
     */
    public function datatypepropertiesAction($datatype_id, $parent_datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $site_baseurl = $this->container->getParameter('site_baseurl');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // If $parent_datatype_id is set, locate the datatree and theme_datatype entities linking $datatype_id and $parent_datatype_id
            /** @var DataTree|null $datatree */
            $datatree = null;
            /** @var DataTreeMeta|null $datatree_meta */
            $datatree_meta = null;
            /** @var ThemeDataType|null $theme_datatype */
            $theme_datatype = null;

            if ($parent_datatype_id !== '') {
                $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy( array('ancestor' => $parent_datatype_id, 'descendant' => $datatype_id) );
                if ($datatree == null)
                    throw new ODRNotFoundException('Datatree');

                $datatree_meta = $datatree->getDataTreeMeta();
                if ($datatree_meta->getDeletedAt() != null)
                    throw new ODRNotFoundException('DatatreeMeta');

                $query = $em->createQuery(
                   'SELECT tdt
                    FROM ODRAdminBundle:Theme AS t
                    JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                    JOIN ODRAdminBundle:ThemeDataType AS tdt WITH tdt.themeElement = te
                    WHERE t.themeType = :theme_type AND t.dataType = :parent_datatype AND tdt.dataType = :child_datatype
                    AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdt.deletedAt IS NULL'
                )->setParameters( array('theme_type' => 'master', 'parent_datatype' => $parent_datatype_id, 'child_datatype' => $datatype_id) );
                $results = $query->getResult();

                if ( !isset($results[0]) )
                    throw new ODRNotFoundException('ThemeDatatype');
                $theme_datatype = $results[0];
            }

            // Store the current external id/name/sort datafield ids
            $old_external_id_field = $datatype->getExternalIdField();
            if ($old_external_id_field !== null)
                $old_external_id_field = $old_external_id_field->getId();
            $old_namefield = $datatype->getNameField();
            if ($old_namefield !== null)
                $old_namefield = $old_namefield->getId();
            $old_sortfield = $datatype->getSortField();
            if ($old_sortfield !== null)
                $old_sortfield = $old_sortfield->getId();


            // Create the form for the Datatype
            $submitted_data = new DataTypeMeta();
/*
            $is_top_level = true;
            if ($datatree != null)
                $is_top_level = false;
*/
            $is_top_level = true;
            if ( $parent_datatype_id !== '' && $parent_datatype_id !== $datatype_id )
                $is_top_level = false;

            $is_link = false;
            if ($datatree != null && $datatree->getIsLink() == true)
                $is_link = true;

            $datatype_form = $this->createForm(
                UpdateDataTypeForm::class,
                $submitted_data,
                array(
                    'datatype_id' => $datatype->getId(),
                    'is_top_level' => $is_top_level,
                    'is_link' => $is_link
                )
            );
            $datatype_form->handleRequest($request);

            if ($datatype_form->isSubmitted()) {

                if ( $submitted_data->getSearchSlug() !== $datatype->getSearchSlug() ) {
                    // ...check that the new search slug is restricted to alphanumeric characters and a few symbols
                    $pattern = '/^[0-9a-zA-Z][0-9a-zA-Z\_\-]+$/';
                    if ( !preg_match($pattern, $submitted_data->getSearchSlug()) )
                        $datatype_form->addError( new FormError('The abbreviation must consist an alphanumeric character; followed by any number of alphanumeric characters, hyphens, or underscores') );

                    // ...check that the new search slug isn't going to collide with other parts of the site
                    // TODO - make this automatic based on contents of routing files?
                    $search_slug_blacklist = $this->getParameter('odr.search_slug_blacklist');
                    $invalid_slugs = explode('|', $search_slug_blacklist);
                    if ( in_array(strtolower($submitted_data->getSearchSlug()), $invalid_slugs) )
                        $datatype_form->addError( new FormError('This abbreviation is reserved for use by ODR') );

                    // ...check that the new search slug doesn't collide with an existing search slug
                    $query = $em->createQuery(
                       'SELECT dtym.id
                        FROM ODRAdminBundle:DataTypeMeta AS dtym
                        WHERE dtym.searchSlug = :search_slug
                        AND dtym.deletedAt IS NULL'
                    )->setParameters(array('search_slug' => $submitted_data->getSearchSlug()));
                    $results = $query->getArrayResult();

                    if (count($results) > 0)
                        $datatype_form->addError( new FormError('A different Datatype is already using this abbreviation') );
                }

                if ($submitted_data->getShortName() == '')
                    $datatype_form->addError( new FormError('Short Name can not be empty') );
                if ($submitted_data->getLongName() == '')
                    $datatype_form->addError( new FormError('Long Name can not be empty') );

//$datatype_form->addError( new FormError('do not save') );

                // TODO - verify that the datafield provided as a (new) externalIdField can be unique
                // TODO - verify that the datafields provided as a (new) nameField and sortField are allowed...according to UpdateDataTypeForm.php, they don't have to be unique...

                if ($datatype_form->isValid()) {

                    // If any of the external/name/sort datafields got changed, clear the relevant cache fields for datarecords of this datatype
                    $new_external_id_field = $submitted_data->getExternalIdField();
                    if ($new_external_id_field !== null)
                        $new_external_id_field = $new_external_id_field->getId();
                    $new_namefield = $submitted_data->getNameField();
                    if ($new_namefield !== null)
                        $new_namefield = $new_namefield->getId();
                    $new_sortfield = $submitted_data->getSortField();
                    if ($new_sortfield !== null)
                        $new_sortfield = $new_sortfield->getId();

                    if ($old_external_id_field !== $new_external_id_field || $old_namefield !== $new_namefield || $old_sortfield !== $new_sortfield) {
                        // Locate all datarecords of this datatype's grandparent
                        $grandparent_datatype_id = $datatype->getGrandparent()->getId();

                        $query = $em->createQuery(
                           'SELECT dr.id AS dr_id
                            FROM ODRAdminBundle:DataRecord AS dr
                            WHERE dr.dataType = :datatype_id
                            AND dr.deletedAt IS NULL'
                        )->setParameters( array('datatype_id' => $grandparent_datatype_id) );
                        $results = $query->getArrayResult();

                        // Wipe all cached entries for these datarecords
                        foreach ($results as $result) {
                            $dr_id = $result['dr_id'];
                            $cache_service->delete('cached_datarecord_'.$dr_id);
                            $cache_service->delete('cached_table_data_'.$dr_id);
                        }
                    }


                    $properties = array(
                        'renderPlugin' => $datatype->getRenderPlugin()->getId(),

                        // These should technically be null, but isset() won't pick them up if they are...
                        'externalIdField' => -1,
                        'nameField' => -1,
                        'sortField' => -1,
                        'backgroundImageField' => -1,

                        'searchSlug' => $submitted_data->getSearchSlug(),
                        'shortName' => $submitted_data->getShortName(),
                        'longName' => $submitted_data->getLongName(),
                        'description' => $submitted_data->getDescription(),
                        'xml_shortName' => $submitted_data->getXmlShortName(),

                        'publicDate' => $submitted_data->getPublicDate(),
                    );

                    // These properties can be null...
                    $update_sort_order = false;
                    if ( $submitted_data->getExternalIdField() !== null )
                        $properties['externalIdField'] = $submitted_data->getExternalIdField()->getId();
                    if ( $submitted_data->getNameField() !== null )
                        $properties['nameField'] = $submitted_data->getNameField()->getId();
                    if ( $submitted_data->getSortField() !== null ) {
                        $properties['sortField'] = $submitted_data->getSortField()->getId();
                        $update_sort_order = true;
                    }
                    if ( $submitted_data->getBackgroundImageField() !== null )
                        $properties['backgroundImageField'] = $submitted_data->getBackgroundImageField()->getId();

                    // Master Template Data Types must increment Master Revision
                    // on all change requests.
                    if($datatype->getIsMasterType() > 0) {
                        $properties['master_revision'] = $datatype->getDataTypeMeta()->getMasterRevision() + 1;
                    }
                    parent::ODR_copyDatatypeMeta($em, $user, $datatype, $properties);

                    // Master Template Data Types must increment parent master template
                    // revision when changed.
                    if (!$is_link && $datatype->getIsMasterType() > 0) {
                        // TODO Need to update datatype revision for grandparent
                    }

                    // Update cached version of datatype
                    $dti_service->updateDatatypeCacheEntry($datatype, $user);

                    // Don't need to update cached versions of datarecords or themes


                    // If the sort datafield changed, then cached search results need to be updated as well
                    if ($update_sort_order) {
                        $cache_service->delete('datatype_'.$datatype->getId().'_record_order');

                        $cached_searches = $cache_service->get('cached_search_results');
                        if ( isset($cached_searches[$datatype->getId()]) ) {
                            unset( $cached_searches[$datatype->getId()] );
                            $cache_service->set('cached_search_results', $cached_searches);
                        }
                    }
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datatype_form);
                    throw new ODRException($error_str);
                }
            }
            else {
                // This is a GET request...need to create the required form objects
                $datatype_meta = $datatype->getDataTypeMeta();
                $datatype_form = $this->createForm(
                    UpdateDataTypeForm::class,
                    $datatype_meta,
                    array(
                        'datatype_id' => $datatype->getId(),
                        'is_top_level' => $is_top_level,
                        'is_link' => $is_link
                    )
                );

                // Create the form for the Datatree entity (stores whether the parent datatype is allowed to have multiple datarecords of the child datatype)
                $force_multiple = false;
                $datatree_form = null;
                if ($datatree_meta !== null) {
                    $datatree_form = $this->createForm(UpdateDataTreeForm::class, $datatree_meta)->createView();

                    $results = array();
                    if ($datatree_meta->getIsLink() == 0) {
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


                // Create the form for the ThemeDatatype entry (stores whether the child/linked datatype should use 'accordion', 'tabbed', 'dropdown', or 'list' rendering style)
                $theme_datatype_form = null;
                if ($theme_datatype !== null) {
                    // TODO - why was this moved out of the associated form?
                    // Allow header to be hidden for non-multiple-allowed child types
                    $display_choices = array(
                        'Accordion' => '0',
                        'Tabbed' => '1',
                        'Select Box' => '2',
                        'List' => '3'
                    );

                    $theme_datatype_form = $this->createForm(
                        UpdateThemeDatatypeForm::class,
                        $theme_datatype,
                        array(
                            'display_choices' => $display_choices,
                        )
                    )->createView();
                }

                // Determine whether user can view permissions of other users
                $can_view_permissions = false;
                if ( $user->hasRole('ROLE_SUPER_ADMIN') || $pm_service->isDatatypeAdmin($user, $datatype) )
                    $can_view_permissions = true;


                // Return the slideout html
                $templating = $this->get('templating');
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Displaytemplate:datatype_properties_form.html.twig',
                    array(
                        'datatype' => $datatype,
                        'datatype_form' => $datatype_form->createView(),
                        'site_baseurl' => $site_baseurl,
                        'is_top_level' => $is_top_level,
                        'can_view_permissions' => $can_view_permissions,

                        'datatree' => $datatree,
                        'datatree_form' => $datatree_form,              // not creating view here because form could be legitimately null
                        'force_multiple' => $force_multiple,

                        'theme_datatype' => $theme_datatype,
                        'theme_datatype_form' => $theme_datatype_form,  // not creating view here because form could be legitimately null
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x52de9520;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads/saves an ODR DataFields properties Form.
     * 
     * @param integer $datafield_id The database id of the DataField being modified.
     * @param Request $request
     *
     * @return Response
     */
    public function datafieldpropertiesAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');

            /** @var DataFields $datafield */
            $datafield = $repo_datafield->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('themeType' => 'master', 'dataType' => $datatype->getId()) );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Get current datafieldMeta entry
            $current_datafield_meta = $datafield->getDataFieldMeta();


            // ----------------------------------------
            // Need to immediately force a reload of the right design slideout if certain fieldtypes change
            $force_slideout_reload = false;

            // Keep track of conditions where parts of the datafield shouldn't be changed...
            $ret = self::canChangeFieldtype($em, $datafield);
            $prevent_fieldtype_change = $ret['prevent_change'];


            // Check whether this datafield is being used by a table theme
            $query = $em->createQuery(
               'SELECT tdf.id
                FROM ODRAdminBundle:Theme AS t
                JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
                WHERE t.themeType = :theme_type AND tdf.dataField = :datafield
                AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL'
            )->setParameters( array('theme_type' => 'table', 'datafield' => $datafield->getId()) );
            $results = $query->getArrayResult();

            $used_by_table_theme = false;
            if ( count($results) > 0 )
                $used_by_table_theme = true;


            // ----------------------------------------
            // Check to see whether the "allow multiple uploads" checkbox for file/image control needs to be disabled
            $need_refresh = false;
            $has_multiple_uploads = 0;
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass == 'File' || $typeclass == 'Image') {
                // Count how many files/images are attached to this datafield across all datarecords
                $str =
                   'SELECT COUNT(e.dataRecord)
                    FROM ODRAdminBundle:'.$typeclass.' AS e
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
                            $properties = array(
                                'allow_multiple_uploads' => true,
                                'displayOrder' => -1,   // do not allow in TextResults
                            );
                            parent::ODR_copyDatafieldMeta($em, $user, $datafield, $properties);

                            $need_refresh = true;
                        }

                        $has_multiple_uploads = 1;
                        break;
                    }
                }
            }

            if ($need_refresh) {
                $em->refresh($datafield);
                $current_datafield_meta = $datafield->getDataFieldMeta();
            }


            // ----------------------------------------
            // Get a list of fieldtype ids that the datafield is allowed to have
            /** @var FieldType[] $tmp */
            $tmp = $repo_fieldtype->findAll();
            $allowed_fieldtypes = array();
            foreach ($tmp as $ft)
                $allowed_fieldtypes[] = $ft->getId();

            // Determine if the datafield has a render plugin applied to it...
            $df_fieldtypes = $allowed_fieldtypes;
            if ( $datafield->getRenderPlugin()->getId() != '1' ) {
                /** @var RenderPluginInstance $rpi */
                $rpi = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $datafield->getRenderPlugin()->getId(), 'dataField' => $datafield->getId()) );
                if ($rpi !== null) {
                    /** @var RenderPluginMap $rpm */
                    $rpm = $repo_render_plugin_map->findOneBy( array('renderPluginInstance' => $rpi->getId(), 'dataField' => $datafield->getId()) );
                    $rpf = $rpm->getRenderPluginFields();

                    $df_fieldtypes = explode(',', $rpf->getAllowedFieldtypes());
                }
            }

            // Determine if the datafield's datatype has a render plugin applied to it...
            $dt_fieldtypes = $allowed_fieldtypes;
            // if ( $datatype->getDataTypeMeta()->getRenderPlugin()->getId() != '1' ) {
            if ( $datatype->getRenderPlugin()->getId() != '1' ) {
                // Datafield is part of a Datatype using a render plugin...check to see if the Datafield is actually in use for the render plugin
                /** @var RenderPluginInstance $rpi */
                $rpi = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $datatype->getRenderPlugin()->getId(), 'dataType' => $datatype->getId()) );

                /** @var RenderPluginMap $rpm */
                $rpm = $repo_render_plugin_map->findOneBy( array('renderPluginInstance' => $rpi->getId(), 'dataField' => $datafield->getId()) );
                if ($rpm !== null) {
                    // Datafield in use, get restrictions
                    $rpf = $rpm->getRenderPluginFields();

                    $dt_fieldtypes = explode(',', $rpf->getAllowedFieldtypes());
                }
                else {
                    /* Datafield is not being used by a render plugin, so there are no restrictions placed on it */
                }
            }

            // The allowed fieldtypes could be restricted by both the datafield's render plugin and the datafield's datatype's render plugin...
            // ...use the intersection of the restriction
            $allowed_fieldtypes = array_intersect($df_fieldtypes, $dt_fieldtypes);
            $allowed_fieldtypes = array_values($allowed_fieldtypes);


            // ----------------------------------------
            // Populate new DataFields form
            $submitted_data = new DataFieldsMeta();
            $datafield_form = $this->createForm(UpdateDataFieldsForm::class, $submitted_data, array('allowed_fieldtypes' => $allowed_fieldtypes) );

            $datafield_form->handleRequest($request);

            if ($datafield_form->isSubmitted()) {

                // ----------------------------------------
                // Deal with possible change of fieldtype
                $old_fieldtype = $current_datafield_meta->getFieldType();
                $old_fieldtype_id = $old_fieldtype->getId();
                $new_fieldtype = $submitted_data->getFieldType();

                $new_fieldtype_id = $old_fieldtype_id;
                if ($new_fieldtype !== null)
                    $new_fieldtype_id = $new_fieldtype->getId();

                $migrate_data = false;
                $check_image_sizes = false;

                if ( $old_fieldtype_id !== $new_fieldtype_id ) {
                    // If not allowed to change fieldtype or not allowed to change to this fieldtype...
                    if ( $prevent_fieldtype_change || !in_array($new_fieldtype_id, $allowed_fieldtypes) ) {
                        // ...revert back to old fieldtype
                        $prevent_fieldtype_change = true;
                    }
                    else {
                        // Determine if we need to migrate the data over to the new fieldtype
                        /** @var FieldType $old_fieldtype */
                        $old_fieldtype = $repo_fieldtype->find($old_fieldtype_id);
                        /** @var FieldType $new_fieldtype */
                        $new_fieldtype = $repo_fieldtype->find($new_fieldtype_id);

                        // Check whether the fieldtype got changed from something that could be migrated...
                        $migrate_data = true;
                        switch ($old_fieldtype->getTypeClass()) {
                            case 'IntegerValue':
                            case 'LongText':
                            case 'LongVarchar':
                            case 'MediumVarchar':
                            case 'ShortVarchar':
                            case 'DecimalValue':
                            case 'DatetimeValue':
                                break;

                            default:
                                $migrate_data = false;
                                break;
                        }
                        // ...to something that needs the migration proccess
                        switch ($new_fieldtype->getTypeClass()) {
                            case 'IntegerValue':
                            case 'LongText':
                            case 'LongVarchar':
                            case 'MediumVarchar':
                            case 'ShortVarchar':
                            case 'DecimalValue':
                            case 'DatetimeValue':
                                break;

                            case 'Image':
                                $check_image_sizes = true;  // need to ensure that ImageSizes entities exist for this datafield...
                                $migrate_data = false;
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

                // If not allowed to change fieldtype, ensure the datafield always has the old fieldtype
                if ($prevent_fieldtype_change) {
                    $submitted_data->setFieldType( $old_fieldtype );
                    $migrate_data = false;
                    $force_slideout_reload = false;
                }


                // ----------------------------------------
                // If datafield is being used as the datatype's external ID field, ensure it's marked as unique
                if ( $datatype->getExternalIdField() !== null && $datatype->getExternalIdField()->getId() == $datafield->getId() )
                    $submitted_data->setIsUnique(true);

                // If the datafield got set to unique...
                if ( !$current_datafield_meta->getIsUnique() && $submitted_data->getIsUnique() ) {
                    // ...if it has duplicate values, manually add an error to the Symfony form...this will conveniently cause the subsequent isValid() call to fail
                    if ( !self::datafieldCanBeUnique($em, $datafield) )
                        $datafield_form->addError( new FormError("This Datafield can't be set to 'unique' because some Datarecords have duplicate values stored in this Datafield...click the gear icon to list which ones.") ); 
                }

                // If the unique status of the datafield got changed at all, force a slideout reload so the fieldtype will have the correct state
                if ( $current_datafield_meta->getIsUnique() != $submitted_data->getIsUnique() )
                    $force_slideout_reload = true;

                // If the datafield is in use by a Table theme, then don't let it have multiple uploads
                if ($used_by_table_theme && $submitted_data->getAllowMultipleUploads() == true)
                    $datafield_form->addError( new FormError("This Datafield is being used by a Table theme...it can't be set to allow multiple uploads") );

//$datafield_form->addError( new FormError("Do not save") );


                if ($datafield_form->isValid()) {
                    // No errors in form

                    // ----------------------------------------
                    // Easier to deal with change of fieldtype and how it relates to searchable here
                    switch ($submitted_data->getFieldType()->getTypeClass()) {
//                        case 'DecimalValue':
                        case 'IntegerValue':
                        case 'LongText':
                        case 'LongVarchar':
                        case 'MediumVarchar':
                        case 'ShortVarchar':
                        case 'Radio':
                            // All of the above fields can have any value for searchable
                            break;

                        case 'DecimalValue':
                        case 'Image':
                        case 'File':
                        case 'Boolean':
                        case 'DatetimeValue':
                            // It only makes sense for these four fieldtypes to be searchable from advanced search
                            if ($submitted_data->getSearchable() == 1 || $submitted_data->getSearchable() == 2)
                                $submitted_data->setSearchable(3);
                            break;

                        default:
                            // All other fieldtypes can't be searched
                            $submitted_data->setSearchable(0);
                            break;
                    }


                    // ----------------------------------------
                    // If the fieldtype changed, then check several of the properties to see if they need changed too...
                    $update_field_order = false;
                    if ( $old_fieldtype_id !== $new_fieldtype_id ) {
                        // Reset the datafield's displayOrder if it got changed to a fieldtype that can't go in TextResults
                        switch ( $new_fieldtype->getTypeName() ) {
                            case 'Image':
                            case 'Multiple Radio':
                            case 'Multiple Select':
                            case 'Markdown':
                                // Datafields with these fieldtypes can't be in TextResults
                                $update_field_order = true;
                                break;

                            case 'File':
                                // File datafields can be in TextResults if they're only allowed to have a single upload
                                if ( $submitted_data->getAllowMultipleUploads() == '1' )
                                    $update_field_order = true;
                                break;

                            default:
                                // The remaining fieldtypes have no restrictions on being in TextResults
                                break;
                        }

                        // Reset a datafield's markdown text if it's not longer a markdown field
                        if ($new_fieldtype->getTypeName() !== 'Markdown')
                            $submitted_data->setMarkdownText('');

                        // Clear properties related to radio options if it's no longer a radio field
                        if ($new_fieldtype->getTypeClass() !== 'Radio') {
                            $submitted_data->setRadioOptionNameSort(false);
                            $submitted_data->setRadioOptionDisplayUnselected(false);
                        }
                    }

                    // TODO - disabled for now, but is this safe to delete?
                    // Ensure a File datafield isn't in TextResults if it is set to allow multiple uploads
                    if ( !$current_datafield_meta->getAllowMultipleUploads() && $submitted_data->getAllowMultipleUploads() )
                        $update_field_order = true;

                    // If the radio options are now supposed to be sorted by name, do that
                    $sort_radio_options = false;
                    if ( $submitted_data->getRadioOptionNameSort() == true && $current_datafield_meta->getRadioOptionNameSort() == false )
                        $sort_radio_options = true;


                    // ----------------------------------------
                    // Save all changes made via the submitted form
                    $properties = array(
                        'fieldType' => $submitted_data->getFieldType()->getId(),
                        'renderPlugin' => $datafield->getRenderPlugin()->getId(),

                        'fieldName' => $submitted_data->getFieldName(),
                        'description' => $submitted_data->getDescription(),
                        'xml_fieldName' => $submitted_data->getXmlFieldName(),
                        'markdownText' => $submitted_data->getMarkdownText(),
                        'regexValidator' => $submitted_data->getRegexValidator(),
                        'phpValidator' => $submitted_data->getPhpValidator(),
                        'required' => $submitted_data->getRequired(),
                        'is_unique' => $submitted_data->getIsUnique(),
                        'allow_multiple_uploads' => $submitted_data->getAllowMultipleUploads(),
                        'shorten_filename' => $submitted_data->getShortenFilename(),
                        'children_per_row' => $submitted_data->getChildrenPerRow(),
                        'radio_option_name_sort' => $submitted_data->getRadioOptionNameSort(),
                        'radio_option_display_unselected' => $submitted_data->getRadioOptionDisplayUnselected(),
                        'searchable' => $submitted_data->getSearchable(),
                        'publicDate' => $submitted_data->getPublicDate(),
                    );
                    parent::ODR_copyDatafieldMeta($em, $user, $datafield, $properties);


                    $em->refresh($datafield);

                    //
                    if ($sort_radio_options)
                        self::radiooptionorderAction($datafield->getId(), true, $request);  // TODO - might be race condition issue with design_ajax

                    // TODO - disabled for now, but is this safe to delete?
//                    if ($update_field_order)
//                        self::removeDatafieldFromTableThemes($em, $user, $datafield);

                    if ($check_image_sizes)
                        parent::ODR_checkImageSizes($em, $user, $datafield);

                    if ($migrate_data)
                        self::startDatafieldMigration($em, $user, $datafield, $old_fieldtype, $new_fieldtype);


                    // ----------------------------------------
                    // Mark the datatype as updated
                    $dti_service->updateDatatypeCacheEntry($datatype, $user);

                    // Don't need to update cached datarecords or themes
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datafield_form);
                    throw new ODRException($error_str);
                }

            }


            if ( !$datafield_form->isSubmitted() || !$datafield_form->isValid() || $force_slideout_reload ) {
                // This was a GET request, or the form wasn't valid originally, or the form was valid but needs to be reloaded anyways
                $em->refresh($datafield);
                $em->refresh($datafield->getDataFieldMeta());

                // ----------------------------------------
                // TODO - delete this?
                // Get relevant theme_datafield entry for this datatype's master theme and create the associated form
/*
                $query = $em->createQuery(
                   'SELECT tdf
                    FROM ODRAdminBundle:ThemeElement AS te
                    JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
                    WHERE te.theme = :theme_id AND tdf.dataField = :datafield
                    AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL'
                )->setParameters( array('theme_id' => $theme->getId(), 'datafield' => $datafield->getId()) );
                $result = $query->getResult();
                /** @var ThemeDataField $theme_datafield
                $theme_datafield = $result[0];
                $theme_datafield_form = $this->createForm(UpdateThemeDatafieldForm::class, $theme_datafield);
*/

                // Create the form for the datafield entry
                $datafield_meta = $datafield->getDataFieldMeta();
                $datafield_form = $this->createForm(UpdateDataFieldsForm::class, $datafield_meta, array('allowed_fieldtypes' => $allowed_fieldtypes) );


                // Keep track of conditions where parts of the datafield shouldn't be changed...
                $ret = self::canDeleteDatafield($em, $datafield);
                $prevent_datafield_deletion = $ret['prevent_deletion'];
                $prevent_datafield_deletion_message = $ret['prevent_deletion_message'];
                $ret = self::canChangeFieldtype($em, $datafield);
                $prevent_fieldtype_change = $ret['prevent_change'];
                $prevent_fieldtype_change_message = $ret['prevent_change_message'];


                // Render the html for the form
                $templating = $this->get('templating');
                $return['d'] = array(
                    'force_slideout_reload' => $force_slideout_reload,
                    'html' => $templating->render(
                        'ODRAdminBundle:Displaytemplate:datafield_properties_form.html.twig',
                        array(
                            'has_multiple_uploads' => $has_multiple_uploads,
                            'prevent_fieldtype_change' => $prevent_fieldtype_change,
                            'prevent_fieldtype_change_message' => $prevent_fieldtype_change_message,
                            'prevent_datafield_deletion' => $prevent_datafield_deletion,
                            'prevent_datafield_deletion_message' => $prevent_datafield_deletion_message,

                            'used_by_table_theme' => $used_by_table_theme,

                            'datafield' => $datafield,
                            'datafield_form' => $datafield_form->createView(),
//                            'theme_datafield' => $theme_datafield,
//                            'theme_datafield_form' => $theme_datafield_form->createView(),
                        )
                    )
                );
            }

        }
        catch (\Exception $e) {
            $source = 0xa7c7c3ae;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Helper function to determine whether a datafield can be deleted
     * TODO - move into a datafield info service?
     * 
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataFields $datafield
     *
     * @return array
     */
    private function canDeleteDatafield($em, $datafield)
    {
        $ret = array(
            'prevent_deletion' => false,
            'prevent_deletion_message' => '',
        );

        $datatype = $datafield->getDataType();
        if ($datatype->getExternalIdField() !== null && $datatype->getExternalIdField()->getId() == $datafield->getId()) {
            return array(
                'prevent_deletion' => true,
                'prevent_deletion_message' => "This datafield is currently in use as the Datatype's external ID field...unable to delete",
            );
        }

        if ( $datatype->getRenderPlugin()->getId() !== 1 ) {
            // Datafield is part of a Datatype using a render plugin...check to see if the Datafield is actually in use for the render plugin
            $query = $em->createQuery(
               'SELECT rpf.fieldName
                FROM ODRAdminBundle:RenderPluginInstance AS rpi
                JOIN ODRAdminBundle:RenderPluginMap AS rpm WITH rpm.renderPluginInstance = rpi
                JOIN ODRAdminBundle:RenderPluginFields AS rpf WITH rpm.renderPluginFields = rpf
                WHERE rpi.dataType = :datatype_id AND rpm.dataField = :datafield_id AND rpf.active = 1
                AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL AND rpf.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId(), 'datafield_id' => $datafield->getId()) );
            $results = $query->getArrayResult();

            if ( count($results) > 0 ) {
                return array(
                    'prevent_deletion' => true,
                    'prevent_deletion_message' => 'This Datafield is currently required by the "'.$datatype->getRenderPlugin()->getPluginName().'" for this Datatype...unable to delete',
                );
            }
        }

        return $ret;
    }


    /**
     * Helper function to determine whether a datafield can have its fieldtype changed
     * TODO - move into a datafield info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataFields $datafield
     *
     * @return array
     */
    private function canChangeFieldtype($em, $datafield)
    {
        $ret = array(
            'prevent_change' => false,
            'prevent_change_message' => '',
        );

        // Prevent a datatfield's fieldtype from being changed if a migration is in progress
        /** @var TrackedJob $tracked_job */
        $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'target_entity' => 'datafield_'.$datafield->getId(), 'completed' => null) );
        if ($tracked_job !== null) {
            $ret = array(
                'prevent_change' => true,
                'prevent_change_message' => "The Fieldtype can't be changed because the server hasn't finished migrating this Datafield's data to the currently displayed Fieldtype.",
            );
        }

        // Also prevent a fieldtype change if the datafield is marked as unique
        if ($datafield->getIsUnique() == true) {
            $ret = array(
                'prevent_change' => true,
                'prevent_change_message' => "The Fieldtype can't be changed because the Datafield is currently marked as Unique.",
            );
        }

        return $ret;
    }


    /**
     * Begins the process of migrating a Datafield from one Fieldtype to another
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param DataFields $datafield
     * @param FieldType $old_fieldtype
     * @param FieldType $new_fieldtype
     *
     */
    private function startDatafieldMigration($em, $user, $datafield, $old_fieldtype, $new_fieldtype)
    {
        // ----------------------------------------
        // Grab necessary stuff for pheanstalk...
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');
        $api_key = $this->container->getParameter('beanstalk_api_key');
        $pheanstalk = $this->get('pheanstalk');

        $url = $this->container->getParameter('site_baseurl');
        $url .= $this->container->get('router')->generate('odr_migrate_field');


        // ----------------------------------------
        // Locate all datarecords of this datatype for purposes of this fieldtype migration
        $datatype = $datafield->getDataType();
        $query = $em->createQuery(
           'SELECT dr.id
            FROM ODRAdminBundle:DataRecord AS dr
            WHERE dr.dataType = :dataType AND dr.deletedAt IS NULL'
        )->setParameters( array('dataType' => $datatype) );
        $results = $query->getResult();

        if ( count($results) > 0 ) {
            // Need to determine the top-level datatype this datafield belongs to, so other background processes won't attempt to render any part of it and disrupt the migration
            $top_level_datatype_id = $datatype->getGrandparent()->getId();


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
            // Create jobs for beanstalk to asynchronously migrate data
            foreach ($results as $num => $result) {
                $datarecord_id = $result['id'];

                // Insert the new job into the queue
//                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "tracked_job_id" => $tracked_job_id,
                        "user_id" => $user->getId(),
                        "datarecord_id" => $datarecord_id,
                        "datafield_id" => $datafield->getId(),
                        "old_fieldtype_id" => $old_fieldtype->getId(),
                        "new_fieldtype_id" => $new_fieldtype->getId(),
//                        "scheduled_at" => $current_time->format('Y-m-d H:i:s'),
                        "redis_prefix" => $redis_prefix,    // debug purposes only
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


    /**
     * Called after a user makes a change that requires a datafield be removed from TextResults
     * @deprecated
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param DataFields $removed_datafield
     *
     */
    private function removeDatafieldFromTableThemes($em, $user, $removed_datafield)
    {
        // Locate each table theme for this datatype
        $datatype = $removed_datafield->getDataType();

        /** @var Theme[] $themes */
        $themes = $em->getRepository('ODRAdminBundle:Theme')->findBy( array('themeType' => 'table', 'dataType' => $datatype->getId()) );
        foreach ($themes as $theme) {
            /** @var ThemeElement $theme_element */
            $theme_element = $theme->getThemeElements()->first();   // only ever a single ThemeElement in a table theme

            /** @var ThemeDataField[] $theme_datafields */
            $theme_datafields = $theme_element->getThemeDataFields();
            $datafield_list = array();

            foreach ($theme_datafields as $tdf) {
                if ( $tdf->getDataField()->getId() !== $removed_datafield->getId() ) {
                    // Store the themeDatafield by its current display order to sort later
                    $datafield_list[ $tdf->getDisplayOrder() ] = $tdf;
                }
                else {
                    // This datafield needs to be removed from the table theme...delete the themeDatafield entry
                    $tdf->setDeletedBy($user);
                    $em->persist($tdf);
                    $em->remove($tdf);
                }
            }
            /** @var ThemeDataField[] $datafield_list */
            ksort($datafield_list);

            // Reset displayOrder to be sequential
            $datafield_list = array_values($datafield_list);
            for ($i = 0; $i < count($datafield_list); $i++) {
                $tdf = $datafield_list[$i];
                if ($tdf->getDisplayOrder() !== $i) {

                    $properties = array(
                        'displayOrder' => $i
                    );
                    parent::ODR_copyThemeDatafield($em, $user, $tdf, $properties);
                }
            }

/*
            // TODO - still using datatype's hasTextResults() property?
            if ( count($datafield_list) == 0 ) {
                $datatype->setHasTextresults(false);
                $em->persist($datatype);
            }
*/
        }

        // Done with the changes
        $em->flush();

        // TODO - updated cached theme?
    }


    /**
     * @todo re-implement this
     * Gets a list of all datafields that have been deleted from a given datatype.
     *
     * @param integer $datatype_id The database id of the DataType to lookup deleted DataFields from...
     * @param Request $request
     *
     * @return Response
     */
    public function getdeleteddatafieldsAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $em = null;

        try {
            throw new ODRNotImplementedException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows, because we want to display deleted datafields

            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                WHERE df.dataType = :datatype AND df.deletedAt IS NOT NULL'
            )->setParameters( array('datatype' => $datatype) );
            $results = $query->getResult();

            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            // Collapse results array
            $count = 0;
            $datafields = array();
            foreach ($results as $num => $df) {
                /** @var DataFields $df */
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
            if ($em !== null)
                $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            $source = 0xf9e63ad1;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * @todo re-implement this
     * Undeletes a deleted DataField.
     *
     * @param Request $request
     * 
     * @return Response
     */
    public function undeleteDatafieldAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            throw new ODRNotImplementedException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $post = $_POST;
//print_r($post);
//return;
            $datafield_id = $post['datafield_id'];

            $repo_theme_data_field = $em->getRepository('ODRAdminBundle:ThemeDataField');

$debug = true;
$debug = false;

            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows, because we want to display old selected mappings/options

            // need to do checking that stuff won't crash
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                WHERE df.id = :datafield_id'
            )->setParameters( array('datafield_id' => $datafield_id) );
            $results = $query->getResult();

            // Ensure datafield matches undelete conditions
            /** @var DataFields $datafield */
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
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dt_admin' ])) ) {
                $em->getFilters()->enable('softdeleteable');
                return parent::permissionDeniedError("edit");
            }
            // --------------------


            // TODO - must have at least one theme_element_field?  or should it create one if one doesn't exist...will attach to theme element thanks to step 4
            $query = $em->createQuery(
               'SELECT tef
                FROM ODRAdminBundle:ThemeElementField AS tef
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
            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield->getId(), 'theme' => 1) );
            $theme_datafield->setActive(1);
            $em->persist($theme_datafield);

if ($debug)
    print 'undeleted datafield '.$datafield->getId().' of datatype '.$datatype->getId()."\n\n";

            // Step 2: undelete the datarecordfield entries associated with this datafield to recover the data
            $query = $em->createQuery(
               'SELECT drf
                FROM ODRAdminBundle:DataRecordFields AS drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE drf.dataField = :datafield AND dr.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield) );
            $results = $query->getResult();

            /** @var DataRecordFields[] $results */
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
                FROM ODRAdminBundle:ThemeElementField AS tef
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
            /** @var ThemeElementField[] $results */
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
                FROM ODRAdminBundle:ThemeElementField AS tef
                JOIN ODRAdminBundle:ThemeElement te WITH tef.themeElement = te
                WHERE tef.dataFields = :datafield AND te.theme = :theme'
            )->setParameters( array('datafield' => $datafield->getId(), 'theme' => 1) );
            $results = $query->getResult();

            /** @var ThemeElement $theme_element */
            $theme_element = $results[0];
            if ($theme_element->getDeletedAt() !== NULL) {
                // theme_element IS DELETED
if ($debug)
    print '-- theme_element '.$theme_element->getId().' deleted, locating new theme_element...'."\n";

                // need to locate the first datafield-oriented theme element in this datatype
                /** @var ThemeElement[] $theme_elements */
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
            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            $source = 0xf3b47c90;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
     * @return Response
     */
    public function datatypepublicAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // If the datatype is public, make it non-public...if datatype is non-public, make it public
            if ( $datatype->isPublic() ) {
                // Make the datatype non-public
                $properties = array(
                    'publicDate' => new \DateTime('2200-01-01 00:00:00')
                );
                parent::ODR_copyDatatypeMeta($em, $user, $datatype, $properties);
            }
            else {
                // Make the datatype public
                $properties = array(
                    'publicDate' => new \DateTime()
                );
                parent::ODR_copyDatatypeMeta($em, $user, $datatype, $properties);
            }

            // Updated cached version of datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Don't need to update cached datarecords or themes
        }
        catch (\Exception $e) {
            $source = 0xe2231afc;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of a Datafield.
     *
     * @param integer $datafield_id The database id of the Datafield to modify.
     * @param Request $request
     *
     * @return Response
     */
    public function datafieldpublicAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // If the datafield is public, make it non-public...if datafield is non-public, make it public
            if ( $datafield->isPublic() ) {
                // Make the datafield non-public
                $properties = array(
                    'publicDate' => new \DateTime('2200-01-01 00:00:00')
                );
                parent::ODR_copyDatafieldMeta($em, $user, $datafield, $properties);
            }
            else {
                // Make the datafield public
                $properties = array(
                    'publicDate' => new \DateTime()
                );
                parent::ODR_copyDatafieldMeta($em, $user, $datafield, $properties);
            }

            // Update cached version of datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Don't need to update cached datarecords or themes
        }
        catch (\Exception $e) {
            $source = 0xbd3dc347;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Checks to see whether the given Datafield can be marked as unique or not.
     * TODO - move into a datafield info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataFields $datafield
     *
     * @return boolean true if the datafield has no duplicate values, false otherwise
     */
    private function datafieldCanBeUnique($em, $datafield)
    {
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');

        // Going to need these...
        $datafield_id = $datafield->getId();
        $datatype = $datafield->getDataType();
        $datatype_id = $datatype->getId();
        $fieldtype = $datafield->getFieldType();
        $typeclass = $fieldtype->getTypeClass();

        // Only run queries if field can be set to unique
        if ($fieldtype->getCanBeUnique() == 0)
            return false;

        // Determine if this datafield belongs to a top-level datatype or not
        $is_child_datatype = false;
        $datatree_array = $dti_service->getDatatreeArray();
        if ( isset($datatree_array['descendant_of'][$datatype_id]) && $datatree_array['descendant_of'][$datatype_id] !== '' )
            $is_child_datatype = true;

        if ( !$is_child_datatype ) {
            // Get a list of all values in the datafield
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
                    // Found duplicate, return false
                    return false;
                else
                    // Found new value, save and continue checking
                    $values[$value] = 1;
            }
        }
        else {
            // Get a list of all values in the datafield, grouped by parent datarecord
            $query = $em->createQuery(
               'SELECT e.value, parent.id AS parent_id
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
                WHERE e.dataField = :datafield
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            $results = $query->getArrayResult();

            // Determine if there are any duplicates in the datafield...
            $values = array();
            foreach ($results as $result) {
                $value = $result['value'];
                $parent_id = $result['parent_id'];

                if ( !isset($values[$parent_id]) )
                    $values[$parent_id] = array();

                if ( isset($values[$parent_id][$value]) )
                    // Found duplicate, return false
                    return false;
                else
                    // Found new value, save and continue checking
                    $values[$parent_id][$value] = 1;
            }
        }

        // Didn't find a duplicate, return true
        return true;
    }


    /**
     * This otherwise trivial controller action is needed in order to work with the modal dialog...
     *
     * @param Request $request
     *
     * @return Response
     */
    public function markdownhelpAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $return['d'] = array(
                'html' => $this->get('templating')->render(
                    'ODRAdminBundle:Displaytemplate:markdown_help_dialog_form.html.twig'
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x6c5fbda1;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
