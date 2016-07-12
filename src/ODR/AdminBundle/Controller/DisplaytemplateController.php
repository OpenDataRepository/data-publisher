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
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\LinkedDataTree;
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
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\UserFieldPermissions;
use ODR\AdminBundle\Entity\UserPermissions;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\UpdateDataTreeForm;
use ODR\AdminBundle\Form\UpdateThemeElementForm;
use ODR\AdminBundle\Form\UpdateThemeDatafieldForm;
use ODR\AdminBundle\Form\UpdateThemeDatatypeForm;
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
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            // Grab entity manager and repositories
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');
            $datatype_id = $datatype->getId();

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $datatree_array = parent::getDatatreeArray($em, true);
            $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $datatype->getId());
//            $grandparent_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($grandparent_datatype_id);

            // --------------------
            // TODO - better way of handling this?
            // Prevent deletion of datafields if a csv import is in progress, as this could screw the importing over
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$grandparent_datatype_id, 'completed' => null) );   // TODO - not datatype_id, right?
            if ($tracked_job !== null)
                throw new \Exception('Preventing deletion of any DataField for this DataType, because a CSV Import for this DataType is in progress...');


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

            // Save which users are going to get datafield permissions deleted
            $query = $em->createQuery(
               'SELECT u.id AS user_id
                FROM ODRAdminBundle:UserFieldPermissions AS ufp
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ufp.user = u
                WHERE ufp.dataField = :datafield AND ufp.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId()) );
            $all_affected_users = $query->getResult();

//print '<pre>'.print_r($all_affected_users, true).'</pre>'; exit();


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
            // ...theme_datafield entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:ThemeDataField AS tdf
                SET tdf.deletedAt = :now, tdf.deletedBy = :deleted_by
                WHERE tdf.dataField = :datafield AND tdf.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datafield' => $datafield->getId()) );
            $rows = $query->execute();

            // ...datafield permissions
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:UserFieldPermissions AS ufp
                SET ufp.deletedAt = :now
                WHERE ufp.dataField = :datafield AND ufp.deletedAt IS NULL'
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
                $redis->delete($redis_prefix.'.data_type_'.$datatype->getId().'_record_order');
            }

            // Ensure that the datatype doesn't continue to think this datafield is its background image field
            if ($datatype->getBackgroundImageField() !== null && $datatype->getBackgroundImageField()->getId() === $datafield->getId())
                $properties['backgroundImageField'] = null;

            if ( count($properties) > 0 )
                parent::ODR_copyDatatypeMeta($em, $user, $datatype, $properties);


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


            // TODO - delete all storage entities via beanstalk?  or just stack delete statements for all 12 entities in here?

/*
            // Schedule the cache for an update
            parent::updateDatatypeCache($datatype->getId(), $options);
*/
            $update_datatype = true;
            foreach ($all_datafield_themes as $theme) {
                parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
                $update_datatype = false;
            }


            // ----------------------------------------
            // Wipe cached data for the grandparent datatype
//            $redis->delete($redis_prefix.'.cached_datatype_'.$grandparent_datatype_id);

            // Wipe cached data for all the datatype's datarecords
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype_id'
            )->setParameters( array('datatype_id' => $grandparent_datatype_id) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $redis->delete($redis_prefix.'.cached_datarecord_'.$dr_id);

                // TODO - schedule each of these datarecords for a recache?
            }

            // Wipe datafield permissions involving this datafield
            foreach ($all_affected_users as $user) {
                $user_id = $user['user_id'];
                $redis->delete($redis_prefix.'.user_'.$user_id.'_datafield_permissions');

                // TODO - schedule each of these for a recache?
            }


            // See if any cached search results need to be deleted...
            $cached_searches = parent::getRedisData(($redis->get($redis_prefix.'.cached_search_results')));
            if ( $cached_searches != false && isset($cached_searches[$grandparent_datatype_id]) ) {
                // Delete all cached search results for this datatype that were run with criteria for this specific datafield
                foreach ($cached_searches[$grandparent_datatype_id] as $search_checksum => $search_data) {
                    $searched_datafields = $search_data['searched_datafields'];
                    $searched_datafields = explode(',', $searched_datafields);

                    if ( in_array($datafield_id, $searched_datafields) )
                        unset( $cached_searches[$grandparent_datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $redis->set($redis_prefix.'.cached_search_results', gzcompress(serialize($cached_searches)));
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
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find( $radio_option_id );
            if ($radio_option == null)
                return parent::deletedEntityError('RadioOption');

            $datafield = $radio_option->getDataField();
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');
            $datafield_id = $datafield->getId();

            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');
            $datatype_id = $datatype->getId();

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');


            $datatree_array = parent::getDatatreeArray($em, true);
            $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $datatype->getId());

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id][ 'design' ])) )
                return parent::permissionDeniedError("change layout of");
            // --------------------


            // ----------------------------------------
            // Save which themes are currently using this datafield
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

/*
            // Schedule the cache for an update
            // TODO - how to update all datarecords of this datatype?
            $options = array();
            $options['mark_as_updated'] = true;
            $search_theme_data_field = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield, 'theme' => 2) );
            if ($search_theme_data_field !== null && $search_theme_data_field->getActive() == true)
                $options['force_shortresults_recache'] = true;

            parent::updateDatatypeCache($datatype->getId(), $options);
*/
            $update_datatype = true;
            foreach ($all_datafield_themes as $theme) {
                parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
                $update_datatype = false;
            }


            // ----------------------------------------
            // Wipe cached data for the grandparent datatype
//            $redis->delete($redis_prefix.'.cached_datatype_'.$grandparent_datatype_id);

            // Wipe cached data for all the datatype's datarecords
            $query = $em->createQuery(
                'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype_id'
            )->setParameters( array('datatype_id' => $grandparent_datatype_id) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $redis->delete($redis_prefix.'.cached_datarecord_'.$dr_id);

                // TODO - schedule each of these datarecords for a recache?
            }

            // See if any cached search results need to be deleted...
            $cached_searches = parent::getRedisData(($redis->get($redis_prefix.'.cached_search_results')));
            if ( $cached_searches != false && isset($cached_searches[$grandparent_datatype_id]) ) {
                // Delete all cached search results for this datatype that were run with criteria for this specific datafield
                foreach ($cached_searches[$grandparent_datatype_id] as $search_checksum => $search_data) {
                    $searched_datafields = $search_data['searched_datafields'];
                    $searched_datafields = explode(',', $searched_datafields);

                    if ( in_array($datafield_id, $searched_datafields) )
                        unset( $cached_searches[$grandparent_datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $redis->set($redis_prefix.'.cached_search_results', gzcompress(serialize($cached_searches)));
            }

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
     * @return Response
     */
    public function defaultradiooptionAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_radio_option_meta = $em->getRepository('ODRAdminBundle:RadioOptionsMeta');

            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find( $radio_option_id );
            if ($radio_option == null)
                return parent::deletedEntityError('RadioOption');
            $datafield = $radio_option->getDataField();
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');
            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("change layout of");
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

/*
            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);
*/
            $update_datatype = true;
            parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');

            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            $top_level_datatypes = parent::getTopLevelDatatypes();

            $datatree_array = parent::getDatatreeArray($em, true);
            $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $datatype->getId());

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
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

            // TODO - prevent datatype deletion when jobs are in progress?


            // ----------------------------------------
            // Locate ids of all datatypes that need deletion
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

            // ----------------------------------------
            // Delete cached versions of Datarecords if needed
            if ($datatype->getId() == $grandparent_datatype_id) {
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype_id'
                )->setParameters( array('datatype_id' => $grandparent_datatype_id) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];

                    $redis->delete($redis_prefix.'.cached_datarecord_'.$dr_id);
                    $redis->delete($redis_prefix.'.associated_datarecords_for_'.$dr_id);
                }
            }

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
            // Delete LinkedDatatree entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecord AS ancestor, ODRAdminBundle:LinkedDataTree AS ldt, ODRAdminBundle:DataRecord AS descendant
                SET ldt.deletedAt = :now, ldt.deletedBy = :deleted_by
                WHERE ldt.ancestor = ancestor AND ldt.descendant = descendant
                AND (ancestor.dataType IN (:datatype_ids) OR descendant.dataType IN (:datatype_ids) )
                AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
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
            // Delete DatafieldPermission entries (cached versions deleted later)
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataFields AS df, ODRAdminBundle:UserFieldPermissions AS ufp
                SET ufp.deletedAt = :now
                WHERE ufp.dataField = df
                AND df.dataType IN (:datatype_ids)
                AND df.deletedAt IS NULL AND ufp.deletedAt IS NULL'
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
               'UPDATE ODRAdminBundle:DataTree AS dt, ODRAdminBundle:DataTreeMeta AS dtm
                SET dt.deletedAt = :now, dt.deletedBy = :deleted_by, dtm.deletedAt = :now
                WHERE dtm.dataTree = dt
                AND (dt.ancestor IN (:datatype_ids) OR dt.descendant IN (:datatype_ids) )
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();


            // ----------------------------------------
/*
            // Delete all Datatype permission entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:UserPermission AS up
                SET up.deletedAt = :now
                WHERE up.dataType IN (:datatype_ids)
                AND up.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();
*/
            // Delete all Datatype and DatatypeMeta entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataType AS dt, ODRAdminBundle:DataTypeMeta AS dtm
                SET dt.deletedAt = :now, dt.deletedBy = :deleted_by, dtm.deletedAt = :now
                WHERE dtm.dataType = dt
                AND dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datatype_ids' => $datatypes_to_delete) );
            $query->execute();


            // TODO - move (just about) all this preceeding stuff to beanstalk?


            // ----------------------------------------
            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var User[] $user_list */
            $user_list = $user_manager->findUsers();

            // Delete all cached permissions
            foreach ($user_list as $user) {
                $redis->delete($redis_prefix.'.user_'.$user->getId().'_datatype_permissions');
                $redis->delete($redis_prefix.'.user_'.$user->getId().'_datafield_permissions');
            }

            // ...cached searches
            $cached_searches = parent::getRedisData(($redis->get($redis_prefix.'.cached_search_results')));
            if ( $cached_searches != false && isset($cached_searches[$datatype_id]) ) {
                unset( $cached_searches[$datatype_id] );

                // Save the collection of cached searches back to memcached
                $redis->set($redis_prefix.'.cached_search_results', gzcompress(serialize($cached_searches)));
            }

            // ...and layout data
            foreach ($datatypes_to_delete as $num => $dt_id)
                $redis->delete($redis_prefix.'.cached_datatype_'.$dt_id);

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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => self::GetDisplayData($datatype_id, 'default', $datatype_id, $request),
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
     * Saves changes made to a Datatree entity.
     * 
     * @param integer $datatype_id  The id of the DataType entity this Datatree belongs to
     * @param integer $datatree_id  The id of the Datatree entity being changed
     * @param Request $request
     * 
     * @return Response
     */
    public function savedatatreeAction($datatype_id, $datatree_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->find($datatree_id);
            if ($datatree == null)
                return parent::deletedEntityError('Datatree');
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
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


                    // TODO - modify cached version of datatype directly?
                    parent::tmp_updateDatatypeCache($em, $datatype, $user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datatree_form);
                    throw new \Exception($error_str);
                }
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
     * @return Response
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


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

/*
            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);
*/
            $update_datatype = true;
            parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);


            // ----------------------------------------
            // Since new datafields were created, wipe datafield permission entries for all users
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var User[] $user_list */
            $user_list = $user_manager->findUsers();
            foreach ($user_list as $u) {
                $redis->delete($redis_prefix.'.user_'.$u->getId().'_datafield_permissions');

                // TODO - schedule a permissions recache via beanstalk?
            }
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
     *
     * @param integer $theme_element_id The database id of the ThemeElement to insert the new datafield into.
     * @param integer $datafield_id     The database id of the DataField to copy data from.
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            /** @var DataFields $old_datafield */
            $old_datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($old_datafield == null)
                return parent::deletedEntityError('DataField');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab objects required to create a datafield entity
            /** @var FieldType $fieldtype */
            $fieldtype = $em->getRepository('ODRAdminBundle:FieldType')->findOneBy( array('typeName' => 'Short Text') );
            /** @var RenderPlugin $render_plugin */
            $render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find('1');

            // Create the datafield
            $objects = parent::ODR_addDataField($em, $user, $datatype, $fieldtype, $render_plugin);
            /** @var DataFields $new_datafield */
            $new_datafield = $objects['datafield'];
            /** @var DataFieldsMeta $new_datafield_meta */
            $new_datafield_meta = $objects['datafield_meta'];

            // Tie the new datafield to the theme element
            $new_theme_datafield = parent::ODR_addThemeDataField($em, $user, $new_datafield, $theme_element);
            $em->flush();


            // TODO - copy anything else?
            // Copy fieldtype of old datafield over to new datafield
            $em->refresh($new_datafield_meta);
            $properties = array(
                'fieldType' => $old_datafield->getFieldType()->getId(),
                'fieldName' => 'Copy of '.$old_datafield->getFieldName(),

                'allow_multiple_uploads' => $old_datafield->getAllowMultipleUploads(),
                'shorten_filename' => $old_datafield->getShortenFilename(),
                'children_per_row' => $old_datafield->getChildrenPerRow(),
                'radio_option_name_sort' => $old_datafield->getRadioOptionNameSort(),
                'radio_option_display_unselected' => $old_datafield->getRadioOptionDisplayUnselected(),
                'searchable' => $old_datafield->getSearchable(),
                'user_only_search' =>$old_datafield->getUserOnlySearch(),

            );
            parent::ODR_copyDatafieldMeta($em, $user, $new_datafield, $properties);


            // Copy widths of old datafield over to new datafield
            $em->refresh($new_theme_datafield);
            /** @var ThemeDataField $old_theme_datafield */
            $old_theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataField' => $old_datafield->getId(), 'theme' => $theme->getId()) );
            $properties = array(
                'cssWidthMed' => $old_theme_datafield->getCssWidthMed(),
                'cssWidthXL' => $old_theme_datafield->getCssWidthXL(),
            );
            parent::ODR_copyThemeDatafield($em, $user, $new_theme_datafield, $properties);


            // Save any other changes
            $em->flush();

            // design_ajax.html.twig calls ReloadThemeElement()

/*
            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);
*/
            $update_datatype = true;
            parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
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
     * @return Response
     */
    public function getradiooptionsAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
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
     * @return Response
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

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($radio_option_id);
            if ($radio_option == null)
                return parent::deletedEntityError('RadioOption');
            $datafield = $radio_option->getDataField();
            if ($datafield == null)
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
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

/*
            // Schedule the cache for an update
            // TODO - how to update all datarecords of this datatype?
            $options = array();
            $options['mark_as_updated'] = true;
            $search_theme_data_field = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield, 'theme' => 2) );   // TODO
            if ($search_theme_data_field !== null && $search_theme_data_field->getActive() == true)
                $options['force_shortresults_recache'] = true;

            parent::updateDatatypeCache($datatype->getId(), $options);
*/
            $update_datatype = true;
            parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
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
     * @return Response
     */
    public function radiooptionorderAction($datafield_id, $alphabetical_sort, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $_POST;
//print_r($post);
//return;

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
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

                        $properties = array(
                            'displayOrder' => $index
                        );
//print 'updated "'.$radio_option_meta->getOptionName().'" to index '.$index."\n";
                        parent::ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties);
                    }

                    $index++;
                }
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
                        throw new \Exception('Invalid POST request');

                    $radio_option_meta = $all_options_meta[$radio_option_id];
                    $radio_option = $radio_option_meta->getRadioOption();

                    if ( $radio_option_meta->getDisplayOrder() != $index ) {
                        $properties = array(
                            'displayOrder' => $index
                        );
//print 'updated "'.$radio_option_meta->getOptionName().'" to index '.$index."\n";
                        parent::ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties);
                    }
                }
            }

/*
            // Schedule the cache for an update
            // TODO - how to update all datarecords of this datatype?
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);
*/
            $update_datatype = true;
            parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
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
     * @return Response
     */
    public function addradiooptionAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError($theme);


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Create a new RadioOption
            $force_create = true;
            /*$radio_option = */parent::ODR_addRadioOption($em, $user, $datafield, $force_create);
            $em->flush();
//            $em->refresh($radio_option);

/*
            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            // NOTE - strictly speaking, this should force a shortresults recache, but the user is almost certainly going to rename the new option, so don't do it here
//            $search_theme_data_field = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield, 'theme' => 2) );
//            if ($search_theme_data_field !== null && $search_theme_data_field->getActive() == true)
//                $options['force_shortresults_recache'] = true;

            parent::updateDatatypeCache($datatype->getId(), $options);
*/
            $update_datatype = true;
            parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $parent_datatype = $theme->getDataType();
            if ($parent_datatype == null)
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $parent_datatype->getId() ]) && isset($user_permissions[ $parent_datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            if ($theme->getThemeType() !== 'master')
                throw new \Exception('Unable to create a new child Datatype outside of the master Theme');

            // ----------------------------------------
            // Defaults
            /** @var RenderPlugin $render_plugin */
            $default_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);

            // TODO - mostly duplicated with DataType controller...move this somewhere else?
            // Create the new child Datatype
            $child_datatype = new DataType();
            $child_datatype->setRevision(0);
            $child_datatype->setHasShortresults(false);
            $child_datatype->setHasTextresults(false);

            $child_datatype->setCreatedBy($user);
            $child_datatype->setUpdatedBy($user);
            $em->persist($child_datatype);

            // TODO - delete these 10 properties
            $child_datatype->setShortName("New Child");
            $child_datatype->setLongName("New Child");
            $child_datatype->setDescription("New Child Type");
            $child_datatype->setXmlShortName('');
            $child_datatype->setRenderPlugin($default_render_plugin);

            $child_datatype->setUseShortResults('1');
            $child_datatype->setExternalIdField(null);
            $child_datatype->setNameField(null);
            $child_datatype->setSortField(null);
            $child_datatype->setDisplayType(0);
            $child_datatype->setPublicDate(new \DateTime('1980-01-01 00:00:00'));

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

            $datatype_meta->setDisplayType(0);
            $datatype_meta->setUseShortResults(true);
            $datatype_meta->setPublicDate( new \DateTime('1980-01-01 00:00:00') );

            $datatype_meta->setExternalIdField(null);
            $datatype_meta->setNameField(null);
            $datatype_meta->setSortField(null);
            $datatype_meta->setBackgroundImageField(null);

            $datatype_meta->setCreatedBy($user);
            $datatype_meta->setUpdatedBy($user);
            $em->persist($datatype_meta);


            // Create a new DataTree entry to link the original datatype and this new child datatype
            $datatree = new DataTree();
            $datatree->setAncestor($parent_datatype);
            $datatree->setDescendant($child_datatype);
            $datatree->setCreatedBy($user);

            // TODO - delete these two properties
            $datatree->setIsLink(false);
            $datatree->setMultipleAllowed(true);
            $em->persist($datatree);


            // Create a new master theme for this new child datatype
            $theme = new Theme();
            $theme->setDataType($child_datatype);
            $theme->setThemeType('master');
            $theme->setCreatedBy($user);
            $theme->setUpdatedBy($user);
            $em->persist($theme);


            $em->flush();
            $em->refresh($datatree);
            $em->refresh($theme);


            // Create a new DataTreeMeta entity to store properties of the DataTree
            $datatree_meta = new DataTreeMeta();
            $datatree_meta->setDataTree($datatree);
            $datatree_meta->setIsLink(false);
            $datatree_meta->setMultipleAllowed(true);
            $datatree_meta->setCreatedBy($user);
            $datatree_meta->setUpdatedBy($user);
            $em->persist($datatree_meta);

            // Create a new ThemeMeta entity to store properties of the childtype's Theme
            $theme_meta = new ThemeMeta();
            $theme_meta->setTheme($theme);
            $theme_meta->setTemplateName('');
            $theme_meta->setTemplateDescription('');
            $theme_meta->setIsDefault(true);
            $theme_meta->setCreatedBy($user);
            $theme_meta->setUpdatedBy($user);
            $em->persist($theme_meta);


            // ----------------------------------------
            // Create a new ThemeDatatype entry to let the renderer know it has to render a child datatype in this ThemeElement
            $theme_datatype = parent::ODR_addThemeDatatype($em, $user, $child_datatype, $theme_element);
            $em->flush($theme_datatype);


            // ----------------------------------------
            // Copy the permissions this user has for the parent datatype to the new child datatype
            $query = $em->createQuery(
               'SELECT up
                FROM ODRAdminBundle:UserPermissions AS up
                WHERE up.user = :user_id AND up.dataType = :datatype'
            )->setParameters( array('user_id' => $user->getId(), 'datatype' => $parent_datatype->getId()) );
            $results = $query->getArrayResult();
            $parent_permission = $results[0];

            $initial_permissions = array(
                'can_view_type' => $parent_permission['can_view_type'],
                'can_add_record' => $parent_permission['can_add_record'],
                'can_edit_record' => $parent_permission['can_edit_record'],
                'can_delete_record' => $parent_permission['can_delete_record'],
                'can_design_type' => $parent_permission['can_design_type'],
                'is_type_admin' => 0    // DO NOT set admin permissions on childtypes
            );
            parent::ODR_addUserPermission($em, $user->getId(), $user->getId(), $child_datatype->getId(), $initial_permissions);


            // ----------------------------------------
            // Clear memcached of all datatype permissions for all users...the entries will get rebuilt the next time they do something
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $user_manager = $this->container->get('fos_user.user_manager');
            $users = $user_manager->findUsers();
            foreach ($users as $user)
                $redis->delete($redis_prefix.'.user_'.$user->getId().'_datatype_permissions');


            // ----------------------------------------
/*
            $return['d'] = array(
//                'theme_element_id' => $theme_element->getId(),
//                'html' => self::GetDisplayData($request, null, 'theme_element', $theme_element->getId()),
            );

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($parent_datatype->getId(), $options);
*/
            $update_datatype = true;
            parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);

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
     * @param integer $theme_element_id The database id of the ThemeElement that is/would be where the linked DataType
     *                                  rendered in this DataType...
     * @param Request $request
     * 
     * @return Response
     */
    public function getlinktypesAction($datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            /** @var DataType $local_datatype */
            $local_datatype = $repo_datatype->find($datatype_id);
            if ($local_datatype == null)
                return parent::deletedEntityError('DataType');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $local_datatype->getId() ]) && isset($user_permissions[ $local_datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            if ($theme->getThemeType() !== 'master')
                throw new \Exception('Unable to link to a remote Datatype outside of the master Theme');


            // Locate the previously linked datatype if it exists
            /** @var DataType|null $current_remote_datatype */
            $has_linked_datarecords = false;
            $current_remote_datatype = null;
            if ($theme_element->getThemeDataType()->count() > 0) {
                $current_remote_datatype = $theme_element->getThemeDataType()->first()->getDataType();  // should only ever be one theme_datatype entry

                // Determine whether any datarecords of the local datatype link to datarecords of the remote datatype
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor.dataType = :local_datatype_id AND descendant.dataType = :remote_datatype_id
                    AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters( array('local_datatype_id' => $local_datatype->getId(), 'remote_datatype_id' => $current_remote_datatype->getId()) );
                $results = $query->getArrayResult();

                if ( count($results) > 0 )
                    $has_linked_datarecords = true;
            }


            // Going to need the id of the local datatype's grandparent datatype
            $current_datatree_array = parent::getDatatreeArray($em);
            $grandparent_datatype_id = parent::getGrandparentDatatypeId($current_datatree_array, $local_datatype->getId());


            // ----------------------------------------
            // Grab all the ids of all datatypes currently in the database
            $query = $em->createQuery(
               'SELECT dt.id AS id
                FROM ODRAdminBundle:DataType AS dt
                WHERE dt.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            $all_datatype_ids = array();
            foreach ($results as $result)
                $all_datatype_ids[] = $result['id'];

            // Iterate through all the datatype ids...
            $linkable_datatype_ids = array();
            foreach ($all_datatype_ids as $dt_id) {
                // TODO - should this permission actually be required here?
                // Prevent user from linking to a datatype they don't have view permissions for
                if ( !(isset($user_permissions[ $dt_id ]) && isset($user_permissions[ $dt_id ]['view'])) )
                    continue;

                // Don't allow linking to child datatypes
                if ( isset($current_datatree_array['descendant_of'][ $dt_id ]) && $current_datatree_array['descendant_of'][ $dt_id ] !== '' )
                    continue;

                // Don't allow linking to the local datatype's grandparent
                if ($dt_id == $grandparent_datatype_id)
                    continue;

                // Don't allow the local datatype to link to a remote datatype more than once
                if ( isset($current_datatree_array['linked_from'][$dt_id]) && in_array($local_datatype->getId(), $current_datatree_array['linked_from'][$dt_id]) )
                    continue;

                // Don't allow the local datatype to link to this remote datatype if it would cause the renderer to recurse
                // e.g. datatype_a => datatype_b and datatype_b => datatype_a
                // or datatype_a => datatype_b, datatype_b => datatype_c, and datatype_c => datatype_a, etc
                if ( self::willDatatypeLinkRecurse($current_datatree_array, $local_datatype->getId(), $dt_id) )
                    continue;

                // Otherwise, linking to this datatype is acceptable
                $linkable_datatype_ids[] = $dt_id;
            }


            // If this theme element currently contains a linked datatype, ensure that remote datatype exists in the array
            if ($current_remote_datatype !== null) {
                if ( !in_array($current_remote_datatype->getId(), $linkable_datatype_ids) )
                    $linkable_datatype_ids[] = $current_remote_datatype->getId();
            }

            // Load all datatypes which can be linked to
            $linkable_datatypes = array();
            foreach ($linkable_datatype_ids as $dt_id)
                $linkable_datatypes[] = $repo_datatype->find($dt_id);

            // Sort the linkable datatypes list by name
            usort($linkable_datatypes, function($a, $b) {
                /** @var DataType $a */
                /** @var DataType $b */
                return strcmp($a->getShortName(), $b->getShortName());
            });


            // ----------------------------------------
            // Get Templating Object
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:link_type_dialog_form.html.twig',
                    array(
                        'local_datatype' => $local_datatype,
                        'remote_datatype' => $current_remote_datatype,
                        'theme_element' => $theme_element,
                        'linkable_datatypes' => $linkable_datatypes,

                        'has_linked_datarecords' => $has_linked_datarecords,
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
     * If linked, DataRecords of the 'local' DataType will have the option to link to DataRecords of the 'remote'
     * DataType.
     * 
     * @param Request $request
     * 
     * @return Response
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
//print_r($post);
//exit();

            $local_datatype_id = $post['local_datatype_id'];
            $remote_datatype_id = $post['selected_datatype'];
            $previous_remote_datatype_id = $post['previous_remote_datatype'];
            $theme_element_id = $post['theme_element_id'];

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            /** @var DataType $local_datatype */
            $local_datatype = $repo_datatype->find($local_datatype_id);
            if ($local_datatype == null)
                return parent::deletedEntityError('DataType');

            $remote_datatype = null;
            if ($remote_datatype_id !== '')
                $remote_datatype = $repo_datatype->find($remote_datatype_id);   // Looking to create a link
            else
                $remote_datatype = $repo_datatype->find($previous_remote_datatype_id);   // Looking to remove a link
            /** @var DataType $remote_datatype */

            if ($remote_datatype == null)
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $local_datatype->getId() ]) && isset($user_permissions[ $local_datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");

            // TODO - should this permission actually be required here?
            // Prevent user from linking to a datatype they can't view
            if ( !(isset($user_permissions[ $remote_datatype->getId() ]) && $user_permissions[ $remote_datatype->getId() ]['view']) )
                return parent::permissionDeniedError('edit');
            // --------------------

            if ($theme->getThemeType() !== 'master')
                throw new \Exception('Unable to link to a remote Datatype outside of the master Theme');


            // ----------------------------------------
            // Get the most recent version of the datatree array
            $current_datatree_array = parent::getDatatreeArray($em, true);

            // Perform various checks to ensure that this link request is valid
            if ($local_datatype_id == $remote_datatype_id)
                throw new \Exception("A Datatype can't be linked to itself");
            if ($remote_datatype_id == $previous_remote_datatype_id)
                throw new \Exception("Already linked to this Datatype");


            if ( isset($current_datatree_array['descendant_of'][$remote_datatype_id]) && $current_datatree_array['descendant_of'][$remote_datatype_id] !== '' )
                throw new \Exception("Not allowed to link to child Datatypes");

            $grandparent_datatype_id = parent::getGrandparentDatatypeId($current_datatree_array, $local_datatype_id);
            if ($remote_datatype_id == $grandparent_datatype_id)
                throw new \Exception("Child Datatypes are not allowed to link to their parents");

            if ( isset($current_datatree_array['linked_from'][$remote_datatype_id]) && in_array($local_datatype_id, $current_datatree_array['linked_from'][$remote_datatype_id]) )
                throw new \Exception("Unable to link to the same Datatype multiple times");


            if ($remote_datatype_id !== '') {
                // If a link currently exists, remove it from the array for purposes of locating recursion
                if ($previous_remote_datatype_id !== '') {
                    $key = array_search($local_datatype_id, $current_datatree_array['linked_from'][$previous_remote_datatype_id]);
                    unset( $current_datatree_array['linked_from'][$previous_remote_datatype_id][$key] );
                }

                // Determine whether this link would cause infinite rendering recursion
                if ( self::willDatatypeLinkRecurse($current_datatree_array, $local_datatype_id, $remote_datatype_id) )
                    throw new \Exception('Unable to link these two datatypes...rendering would become stuck in an infinite loop');
            }


            // ----------------------------------------
            // Now that this link request is guaranteed to be valid...

            // Locate and delete any existing LinkedDatatree entries for the previous link
            if ($previous_remote_datatype_id !== '') {
                $query = $em->createQuery(
                   'SELECT grandparent.id AS grandparent_id, ldt.id AS ldt_id
                    FROM ODRAdminBundle:DataRecord AS grandparent
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ancestor.grandparent = grandparent
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor.dataType = :ancestor_datatype AND descendant.dataType = :descendant_datatype
                    AND grandparent.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters( array('ancestor_datatype' => $local_datatype_id, 'descendant_datatype' => $previous_remote_datatype_id) );
                $results = $query->getArrayResult();
//print '<pre>'.print_r($results, true).'</pre>'; exit();

                $ldt_ids = array();
                $datarecords_to_recache = array();
                foreach ($results as $result) {
                    $dr_id = $result['grandparent_id'];
                    $ldt_id = $result['ldt_id'];

                    $datarecords_to_recache[ $dr_id ] = 1;
                    $ldt_ids[] = $ldt_id;
                }

                if ( count($ldt_ids) > 0 ) {
                    // Perform a DQL mass update to soft-delete all the LinkedDatatree entries
                    $query = $em->createQuery(
                       'UPDATE ODRAdminBundle:LinkedDataTree AS ldt
                        SET ldt.deletedAt = :now, ldt.deletedBy = :user_id
                        WHERE ldt.id IN (:ldt_ids)'
                    );
                    $parameters = array('now' => new \DateTime(), 'user_id' => $user->getId(), 'ldt_ids' => $ldt_ids);
                    $query->execute($parameters);
                }

                $entities_to_remove = array();

                // Soft-delete the old datatree entry
                /** @var DataTree $datatree */
                $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy( array('ancestor' => $local_datatype_id, 'descendant' => $previous_remote_datatype_id) );
                if ($datatree !== null) {
                    $datatree_meta = $datatree->getDataTreeMeta();

                    $datatree->setDeletedBy($user);
                    $em->persist($datatree);

                    $entities_to_remove[] = $datatree;
                    $entities_to_remove[] = $datatree_meta;
                }

                // Soft-delete the old theme_datatype entry
                /** @var ThemeDataType $theme_datatype */
                $theme_datatype = $theme_element->getThemeDataType()->first();
                $theme_datatype->setDeletedBy($user);
                $em->persist($theme_datatype);

                $em->flush();
                foreach ($entities_to_remove as $entity)
                    $em->remove($entity);
                $em->flush();


                // Delete memcached key that stores linked datarecords for each of the affected datarecords
                foreach ($datarecords_to_recache as $dr_id => $num)
                    $redis->delete($redis_prefix.'.associated_datarecords_for_'.$dr_id);
            }

//throw new \Exception('do not continue');


            $using_linked_type = 0;
            if ($remote_datatype_id !== '') {
                // Create a link between the two datatypes
                $using_linked_type = 1;

                $datatree = new DataTree();
                $datatree->setAncestor($local_datatype);
                $datatree->setDescendant($remote_datatype);
                $datatree->setCreatedBy($user);

                // TODO - delete these two properties
                $datatree->setIsLink(true);
                $datatree->setMultipleAllowed(true);

                $em->persist($datatree);
                $em->flush($datatree);
                $em->refresh($datatree);

                // Create a new meta entry for this DataTree
                $datatree_meta = new DataTreeMeta();
                $datatree_meta->setDataTree( $datatree );
                $datatree_meta->setIsLink(true);
                $datatree_meta->setMultipleAllowed(true);

                $datatree_meta->setCreatedBy($user);
                $datatree_meta->setUpdatedBy($user);
                $em->persist($datatree_meta);


                // Create a new theme_datatype entry between the local and the remote datatype
                parent::ODR_addThemeDatatype($em, $user, $remote_datatype, $theme_element);
                $em->flush();
            }


            if ($remote_datatype_id === '')
                $remote_datatype_id = $previous_remote_datatype_id;

            // Reload the theme element
            $return['d'] = array(
                'element_id' => $theme_element->getId(),
                'using_linked_type' => $using_linked_type,
                'linked_datatype_id' => $remote_datatype_id,
            );


            // TODO - update cached version directly?
            $update_datatype = true;
            parent::tmp_updateThemeCache($em, $theme_element->getTheme(), $user, $update_datatype);
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
     * Returns whether a potential link from $local_datatype_id to $remote_datatype_id would cause infinite
     * loops in the template rendering.
     *
     * @param array $datatree_array
     * @param integer $local_datatype_id   The id of the datatype attempting to become the "local" datatype
     * @param integer $remote_datatype_id  The id of the datatype that could become the "remote" datatype
     *
     * @return boolean true if creating this link would cause infinite rendering recursion, false otherwise
     */
    private function willDatatypeLinkRecurse($datatree_array, $local_datatype_id, $remote_datatype_id)
    {
        // Easiest way to determine whether a link from local_datatype to remote_datatype will recurse is to see if a cycle emerges by adding said link
        $datatree_array = $datatree_array['linked_from'];

        // 1) Temporarily add a link from local_datatype to remote_datatype
        if ( !isset($datatree_array[$remote_datatype_id]) )
            $datatree_array[$remote_datatype_id] = array();
        if ( !in_array($local_datatype_id, $datatree_array[$remote_datatype_id]) )
            $datatree_array[$remote_datatype_id][] = $local_datatype_id;

        // 2) Treat the datatree array as a graph, and, starting from $remote_datatype_id...
        $is_cyclic = false;
        foreach ($datatree_array[$remote_datatype_id] as $parent_datatype_id) {
            // 3) ...run a depth-first search on the graph to see if a cycle can be located
            if ( isset($datatree_array[$parent_datatype_id]) )
                $is_cyclic = self::datatypeLinkRecursionWorker($datatree_array, $remote_datatype_id, $parent_datatype_id);

            // 4) If a cycle was found, then adding a link from $local_datatype_id to $remote_datatype_id would cause rendering recursion...therefore, do not allow this link to be created
            if ($is_cyclic)
                return true;
        }

        // Otherwise, no cycle was found...adding a link from $local_datatype_id to $remote_datatype_id will not cause rendering recursion
        return false;
    }


    /**
     * Handles the recursive depth-first search needed for self::willDatatypeLinkRecurse()
     *
     * @param array $datatree_array
     * @param integer $target_datatype_id
     * @param integer $current_datatype_id
     *
     * @return boolean
     */
    private function datatypeLinkRecursionWorker($datatree_array, $target_datatype_id, $current_datatype_id)
    {
        $is_cyclic = false;
        foreach ($datatree_array[$current_datatype_id] as $parent_datatype_id) {
            // If we found $target_datatype_id in this part of the array, then we've managed to find a cycle
            if ( $parent_datatype_id == $target_datatype_id )
                return true;

            // ...otherwise, continue the depth-first search
            if ( isset($datatree_array[$parent_datatype_id]) )
                $is_cyclic = self::datatypeLinkRecursionWorker($datatree_array, $target_datatype_id, $parent_datatype_id);

            // If a cycle was found, return true
            if ($is_cyclic)
                return true;
        }

        // Otherwise, no cycles found in this section of the graph
        return false;
    }


    /**
     * Builds and returns a list of available Render Plugins for a DataType or a DataField.
     *
     * TODO - this currently only reads plugin list from the database
     * 
     * @param integer|null $datatype_id  The id of the Datatype that might be having its RenderPlugin changed
     * @param integer|null $datafield_id The id of the Datafield that might be having its RenderPlugin changed
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
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
                // Locate required entities
                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                if ( $datatype == null )
                    return parent::deletedEntityError('DataType');

                $current_render_plugin = $datatype->getRenderPlugin();

                // Grab available render plugins for this datatype
                $render_plugins = array();
                /** @var RenderPlugin[] $all_render_plugins */
                $all_render_plugins = $repo_render_plugin->findAll(); 
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
                $datafield = $repo_datafields->find($datafield_id);
                if ( $datafield == null )
                    return parent::deletedEntityError('DataField');
                $datatype = $datafield->getDataType();
                if ( $datatype == null )
                    return parent::deletedEntityError('DataType');

                $current_render_plugin = $datafield->getRenderPlugin();

                // Grab available render plugins for this datafield
                $render_plugins = array();
                /** @var RenderPlugin[] $all_render_plugins */
                $all_render_plugins = $repo_render_plugin->findAll();
                foreach ($all_render_plugins as $plugin) {
                    if ($plugin->getPluginType() >= 2 && $plugin->getActive() == 1) // 1: datatype only plugins...2: both...3: datafield only plugins
                        $render_plugins[] = $plugin;
                }

                /** @var RenderPluginInstance $render_plugin_instance */
                $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $current_render_plugin, 'dataField' => $datafield) );
            }


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // Ensure the relevant entities exist
            $datatype = null;
            $datafield = null;
            $all_datafields = null; // of datatype

            if ($datafield_id == 0) {
                // Locate required entities
                $datatype = $repo_datatype->find($datatype_id);
                if ( $datatype == null )
                    return parent::deletedEntityError('DataType');

                $all_datafields = $repo_datafields->findBy(array('dataType' => $datatype));
            }
            else {
                // Locate required entities
                $datafield = $repo_datafields->find($datafield_id);
                if ( $datafield == null )
                    return parent::deletedEntityError('DataField');
                $datatype = $datafield->getDataType();
                if ( $datatype == null )
                    return parent::deletedEntityError('DataType');
            }
            /** @var DataType $datatype */
            /** @var DataFields|null $datafield */
            /** @var DataFields[]|null $all_datafields */

            /** @var RenderPlugin $current_render_plugin */
            $current_render_plugin = $repo_render_plugin->find($render_plugin_id);
            if ( $current_render_plugin == null )
                return parent::deletedEntityError('RenderPlugin');

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
            if ( count($results) > 0 )
                $render_plugin_instance = $results[0];
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

                    /** @var RenderPlugin $plugin */
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
                            throw new \Exception('RenderPlugin "'.$current_render_plugin->getPluginName().'" config: Invalid Fieldtype "'.$allowed_typeclass.'" in list for field "'.$field_name.'"');

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
                    $ft = $repo_fieldtype->findOneBy( array('typeClass' => $allowed_typeclass) );
                    if ($ft == null)
                        throw new \Exception('RenderPlugin "'.$current_render_plugin->getPluginName().'" config: Invalid Fieldtype "'.$allowed_typeclass.'" in list for field "'.$field_name.'"');

                    $allowed_fieldtypes[$rpf_id][] = $ft->getId();
                }
                $rpf->setAllowedFieldtypes( implode(',', $allowed_fieldtypes[$rpf_id]) );

                $em->persist($rpf);
                $em->flush();
                $em->refresh($rpf);
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
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $local_datatype_id ]) && isset($user_permissions[ $local_datatype_id ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');

            /** @var DataType|null $target_datatype */
            $target_datatype = null;
            /** @var DataFields|null $target_datafield */
            $target_datafield = null;
            /** @var DataType $associated_datatype */
            $associated_datatype = null;

            $reload_datatype = false;

            $changing_datatype_plugin = false;
            $changing_datafield_plugin = false;

            if ($local_datafield_id == 0) {
                $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($local_datatype_id);
                if ($target_datatype == null)
                    return parent::deletedEntityError('DataType');

                $associated_datatype = $target_datatype;
                $changing_datatype_plugin = true;
            }
            else {
                $target_datafield = $repo_datafields->find($local_datafield_id);
                if ($target_datafield == null)
                    return parent::deletedEntityError('DataField');
                $associated_datatype = $target_datafield->getDataType();

                $changing_datafield_plugin = true;
            }


            /** @var RenderPlugin $render_plugin */
            $render_plugin = $repo_render_plugin->find($selected_plugin_id);
            if ( $render_plugin == null )
                return parent::deletedEntityError('RenderPlugin');

            // 1: datatype only  2: both datatype and datafield  3: datafield only
            if ($changing_datatype_plugin && $render_plugin->getPluginType() == 1 && $target_datatype == null)
                throw new \Exception('Unable to save a Datatype plugin to a Datafield');
            else if ($changing_datafield_plugin && $render_plugin->getPluginType() == 3 && $target_datafield == null)
                throw new \Exception('Unable to save a Datafield plugin to a Datatype');
            else if ($render_plugin->getPluginType() == 2 && $target_datatype == null && $target_datafield == null)
                throw new \Exception('No target specified');


            // ----------------------------------------
            // Ensure the plugin map doesn't have multiple the same datafield mapped to multiple renderplugin_fields
            $mapped_datafields = array();
            foreach ($plugin_map as $rpf_id => $df_id) {
                if ($df_id != '-1') {
                    if ( isset($mapped_datafields[$df_id]) )
                        throw new \Exception('Invalid Form...multiple datafields mapped to the same renderpluginfield');

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
                    throw new \Exception('Invalid Form...missing datafield mapping');
                // Ensure that all datafields marked as "new" have a fieldtype mapping
                if ($plugin_map[$rpf_id] == '-1' && !isset($plugin_fieldtypes[$rpf_id]) )
                    throw new \Exception('Invalid Form...missing fieldtype mapping');

                if ($plugin_map[$rpf_id] != '-1') {
                    // Ensure all required datafields have a valid fieldtype
                    $allowed_fieldtypes = $rpf->getAllowedFieldtypes();
                    $allowed_fieldtypes = explode(',', $allowed_fieldtypes);

                    // Ensure referenced datafields exist
                    /** @var DataFields $df */
                    $df = $repo_datafields->find( $plugin_map[$rpf_id] );
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
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $associated_datatype->getId(), 'themeType' => 'master') );

            $theme_element = null;
            foreach ($plugin_fieldtypes as $rpf_id => $ft_id) {
                // Since new datafields are being created, instruct ajax success handler in plugin_settings_dialog.html.twig to call ReloadChild() afterwards
                $reload_datatype = true;

                // Create a single new ThemeElement to store the new datafields in, if necessary
                if ($theme_element == null) {
                    $data = parent::ODR_addThemeElement($em, $user, $associated_datatype, $theme);
                    $theme_element = $data['theme_element'];
                    //$theme_element_meta = $data['theme_element_meta'];
                }

                // Load information for the new datafield
                /** @var RenderPlugin $default_render_plugin */
                $default_render_plugin = $repo_render_plugin->find(1);
                /** @var FieldType $fieldtype */
                $fieldtype = $em->getRepository('ODRAdminBundle:FieldType')->find($ft_id);
                if ($fieldtype == null)
                    throw new \Exception('Invalid Form');
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
            }

            // If new datafields created, flush entity manager to save the theme_element and datafield meta entries
            if ($reload_datatype) {
                $em->flush();

                // Since new datafields were created, wipe datafield permission entries for all users
                $redis = $this->container->get('snc_redis.default');;
                // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
                $redis_prefix = $this->container->getParameter('memcached_key_prefix');

                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var User[] $user_list */
                $user_list = $user_manager->findUsers();
                foreach ($user_list as $u) {
                    $redis->delete($redis_prefix.'.user_'.$u->getId().'_datafield_permissions');

                    // TODO - schedule a permissions recache via beanstalk?
                }
            }


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

/*
            // See if there's a previous render_plugin_instance that matches this datatype and selected plugin id
            // 1: datatype only  2: both datatype and datafield  3: datafield only
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
*/

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
                        $df = $repo_datafields->find($df_id);

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

            $em->flush();

            $return['d'] = array(
                'datafield_id' => $local_datafield_id,
                'datatype_id' => $local_datatype_id,
                'render_plugin_id' => $render_plugin->getId(),
                'render_plugin_name' => $render_plugin->getPluginName(),
                'html' => '',

                'reload_datatype' => $reload_datatype,
            );

/*
            // Schedule the cache for an update
            if ($datatype == null)
                $datatype = $datafield->getDataType();

            $options = array();
            $options['mark_as_updated'] = true;

            parent::updateDatatypeCache($datatype->getId(), $options);
*/
            $update_datatype = true;
            parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                return parent::deletedEntityError('Source Datatype');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $return['d'] = array(
                'datatype_id' => $datatype_id,
                'html' => self::GetDisplayData($source_datatype_id, 'child_datatype', $datatype_id, $request),
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
     * @return Response
     */
    public function reloadthemeelementAction($source_datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                return parent::deletedEntityError('Source Datatype');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $datatype_id = null;
            $return['d'] = array(
                'theme_element_id' => $theme_element_id,
                'html' => self::GetDisplayData($source_datatype_id, 'theme_element', $theme_element_id, $request),
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
     * @return Response
     */
    public function reloaddatafieldAction($source_datatype_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                return parent::deletedEntityError('Source Datatype');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');
            

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $datatype_id = null;
            $return['d'] = array(
                'datafield_id' => $datafield_id,
                'html' => self::GetDisplayData($source_datatype_id, 'datafield', $datafield_id, $request),
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
     * @param integer $source_datatype_id  The datatype that originally requested this Displaytemplate rendering
     * @param string $template_name        One of 'default', 'child_datatype', 'theme_element', 'datafield'
     * @param integer $target_id           If $template_name == 'default', then $target_id should be a top-level datatype id
     *                                     If $template_name == 'child_datatype', then $target_id should be a child/linked datatype id
     *                                     If $template_name == 'theme_element', then $target_id should be a theme_element id
     *                                     If $template_name == 'datafield', then $target_id should be a datafield id
     * @param Request $request
     *
     * @throws \Exception
     *
     * @return string
     */
    private function GetDisplayData($source_datatype_id, $template_name, $target_id, Request $request)
    {
        // Don't need to check permissions

        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // Always bypass cache in dev mode?
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;

        // Going to need this a lot...
        $datatree_array = parent::getDatatreeArray($em, $bypass_cache);


        // ----------------------------------------
        // Load required objects based on parameters
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

        // Don't need to check whether these entities are deleted or not
        if ($template_name == 'default') {
            $datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
        }
        else if ($template_name == 'child_datatype') {
            $child_datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy( array('dataType' => $child_datatype->getId(), 'themeType' => 'master') );

            // Need to determine the top-level datatype to be able to load all necessary data for rendering this child datatype
            if ( isset($datatree_array['descendant_of'][ $child_datatype->getId() ]) && $datatree_array['descendant_of'][ $child_datatype->getId() ] !== '' ) {
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
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
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
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
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
        }


        // ----------------------------------------
        // Determine which datatypes/childtypes to load from the cache
        $include_links = true;
        $associated_datatypes = parent::getAssociatedDatatypes($em, array($datatype->getId()), $include_links);

//print '<pre>'.print_r($associated_datatypes, true).'</pre>'; exit();

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$dt_id)));
            if ($bypass_cache || $datatype_data == null)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

//print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();

        // ----------------------------------------
        // Going to need an array of fieldtype ids and fieldtype typenames for notifications about changing fieldtypes
        $fieldtype_array = array();
        /** @var FieldType[] $fieldtypes */
        $fieldtypes = $em->getRepository('ODRAdminBundle:FieldType')->findAll();
        foreach ($fieldtypes as $fieldtype)
            $fieldtype_array[ $fieldtype->getId() ] = $fieldtype->getTypeName();

        // Store whether this datatype has datarecords..affects warnings when changing datafield fieldtypes
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
                    'theme_id' => $theme->getId(),

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


            // If the top-level datatype id found doesn't match the original datatype id of the design page, then this is a request for a linked datatype
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
                    'theme_id' => $theme->getId(),

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

            // If the top-level datatype id found doesn't match the original datatype id of the design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $datatype->getId())
                $is_link = 1;

            // design_fieldarea.html.twig attempts to render all theme_elements in the given theme...
            // Since this is a request to only re-render one of them, unset all theme_elements in the theme other than the one the user wants to re-render
            foreach ($datatype_array[ $datatype->getId() ]['themes'][ $theme->getId() ]['themeElements'] as $te_num => $te) {
                if ( $te['id'] != $target_id )
                    unset( $datatype_array[ $datatype->getId() ]['themes'][ $theme->getId() ]['themeElements'][$te_num] );
            }

//            print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();

            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:design_fieldarea.html.twig',
                array(
                    'datatype_array' => $datatype_array,

                    'target_datatype_id' => $target_datatype_id,
                    'theme_id' => $theme->getId(),

                    'is_top_level' =>  $is_top_level,
                    'is_link' => $is_link,
                )
            );
        }
        else if ($template_name == 'datafield') {

            // Locate the array versions of the requested datafield and its associated theme_datafield entry
            $datafield_array = null;
            $theme_datafield_array = null;

            foreach ($datatype_array[ $child_datatype->getId() ]['themes'][ $theme->getId() ]['themeElements'] as $te_num => $te) {
                if ( isset($te['themeDataFields']) ) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        if ( isset($tdf['dataField']) && $tdf['dataField']['id'] == $datafield->getId() ) {
                            $theme_datafield_array = $tdf;
                            $datafield_array = $tdf['dataField'];
                            break;
                        }
                    }
                }

                if ( $datafield_array !== null )
                    break;
            }

            if ( $datafield_array == null )
                throw new \Exception('Unable to locate array entry for datafield '.$datafield->getId());


            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:design_datafield.html.twig',
                array(
                    'theme_datafield' => $theme_datafield_array,
                    'datafield' => $datafield_array,
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $site_baseurl = $this->container->getParameter('site_baseurl');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
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
                    throw new \Exception('Datatree entry does not exist');

                $datatree_meta = $datatree->getDataTreeMeta();
                if ($datatree_meta == null)
                    throw new \Exception('DatatreeMeta entry does not exist');

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
                    throw new \Exception('ThemeDatatype entry does not exist');
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
            $is_top_level = true;
            if ($datatree !== null)
                $is_top_level = false;

            $datatype_form = $this->createForm(UpdateDataTypeForm::class, $submitted_data, array('datatype_id' => $datatype->getId(), 'is_top_level' => $is_top_level));
            $datatype_form->handleRequest($request);

            if ($datatype_form->isSubmitted()) {

                if ( $submitted_data->getSearchSlug() !== $datatype->getSearchSlug() ) {
                    // ...check that a change to the search slug doesn't collide with an existing search slug
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

                    if ($parent_datatype_id !== '' && ($old_external_id_field !== $new_external_id_field || $old_namefield !== $new_namefield || $old_sortfield !== $new_sortfield) ) {
                        // Locate all datarecords of this datatype
                        $query = $em->createQuery(
                           'SELECT dr.id AS dr_id
                            FROM ODRAdminBundle:DataRecord AS dr
                            WHERE dr.dataType = :datatype_id
                            AND dr.deletedAt IS NULL'
                        )->setParameters(array('datatype_id' => $datatype->getId()));
                        $results = $query->getArrayResult();

                        // Wipe all cached entries for these datarecords
                        // TODO - update them instead?
                        $redis = $this->container->get('snc_redis.default');;
                        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
                        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

                        foreach ($results as $result) {
                            $dr_id = $result['dr_id'];
                            $redis->delete($redis_prefix.'.cached_datarecord_'.$dr_id);
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

                        'useShortResults' => $submitted_data->getUseShortResults(),
                        'display_type' => $submitted_data->getDisplayType(),
                        'publicDate' => $submitted_data->getPublicDate(),
                    );

                    // These properties can be null...
                    if ( $submitted_data->getExternalIdField() !== null )
                        $properties['externalIdField'] = $submitted_data->getExternalIdField()->getId();
                    if ( $submitted_data->getNameField() !== null )
                        $properties['nameField'] = $submitted_data->getNameField()->getId();
                    if ( $submitted_data->getSortField() !== null )
                        $properties['sortField'] = $submitted_data->getSortField()->getId();
                    if ( $submitted_data->getBackgroundImageField() !== null )
                        $properties['backgroundImageField'] = $submitted_data->getBackgroundImageField()->getId();

                    parent::ODR_copyDatatypeMeta($em, $user, $datatype, $properties);

                    // TODO - modify cached version of datatype directly?
                    parent::tmp_updateDatatypeCache($em, $datatype, $user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datatype_form);
                    throw new \Exception($error_str);
                }
            }
            else {
                // Create the required form objects
                $datatype_meta = $datatype->getDataTypeMeta();
                $is_top_level = true;
                if ( $parent_datatype_id !== '' && $parent_datatype_id !== $datatype_id )
                    $is_top_level = false;

                $datatype_form = $this->createForm(UpdateDataTypeForm::class, $datatype_meta, array('datatype_id' => $datatype->getId(), 'is_top_level' => $is_top_level));


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
                if ($theme_datatype !== null)
                    $theme_datatype_form = $this->createForm(UpdateThemeDatatypeForm::class, $theme_datatype)->createView();

                // Determine whether user can view permissions of other users
                $can_view_permissions = false;
                if ( $user->hasRole('ROLE_SUPER_ADMIN') || ( isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['admin']) ) )
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
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2838920 ' . $e->getMessage();
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
            // Grab objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');

            /** @var DataFields $datafield */
            $datafield = $repo_datafield->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('themeType' => 'master', 'dataType' => $datatype->getId()) );
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Get current datafieldMeta entry
            $current_datafield_meta = $datafield->getDataFieldMeta();


            // ----------------------------------------
            // Need to immediately force a reload of the right design slideout if certain fieldtypes change
            $force_slideout_reload = false;

            // Keep track of conditions where parts of the datafield shouldn't be changed...
            $ret = self::canDeleteDatafield($em, $datafield);
            $prevent_datafield_deletion = $ret['prevent_deletion'];
            $prevent_datafield_deletion_message = $ret['prevent_deletion_message'];
            $ret = self::canChangeFieldtype($em, $datafield);
            $prevent_fieldtype_change = $ret['prevent_change'];
            $prevent_fieldtype_change_message = $ret['prevent_change_message'];


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

                    $prevent_datafield_deletion = true;
                    $prevent_datafield_deletion_message = 'This Datafield is currently required by the "'.$datatype->getRenderPlugin()->getPluginName().'" for this Datatype...unable to delete';
                }
                else {
                    // Datafield not in use, no restrictions
                    /* do nothing */
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

//$datafield_form->addError( new FormError("Do not save") );


                if ($datafield_form->isValid()) {
                    // No errors in form

                    // ----------------------------------------
                    // Easier to deal with change of fieldtype and how it relates to searchable here
                    switch ($submitted_data->getFieldType()->getTypeClass()) {
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
                            if ($submitted_data->getSearchable() == 1)
                                $submitted_data->setSearchable(2);
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
                        'displayOrder' => $submitted_data->getDisplayOrder(),
                        'children_per_row' => $submitted_data->getChildrenPerRow(),
                        'radio_option_name_sort' => $submitted_data->getRadioOptionNameSort(),
                        'radio_option_display_unselected' => $submitted_data->getRadioOptionDisplayUnselected(),
                        'searchable' => $submitted_data->getSearchable(),
                        'user_only_search' =>$submitted_data->getUserOnlySearch(),
                    );
                    parent::ODR_copyDatafieldMeta($em, $user, $datafield, $properties);


                    $em->refresh($datafield);

                    //
                    if ($sort_radio_options)
                        self::radiooptionorderAction($datafield->getId(), true, $request);  // TODO - might be race condition issue with design_ajax

                    if ($update_field_order)
                        self::removeDatafieldFromTableThemes($em, $user, $datafield);

                    if ($migrate_data)
                        self::startDatafieldMigration($em, $user, $datafield, $old_fieldtype, $new_fieldtype);


                    // ----------------------------------------
                    // TODO - directly update cache?
                    parent::tmp_updateDatatypeCache($em, $datatype, $user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datafield_form);
                    throw new \Exception($error_str);
                }

            }


            if ( !$datafield_form->isSubmitted() || !$datafield_form->isValid() || $force_slideout_reload ) {
                // This was a GET request, or the form wasn't valid originally, or the form was valid but needs to be reloaded anyways
                $em->refresh($datafield);
                $em->refresh($datafield->getDataFieldMeta());

                // ----------------------------------------
                // Get relevant theme_datafield entry for this datatype's master theme and create the associated form
                $query = $em->createQuery(
                   'SELECT tdf
                    FROM ODRAdminBundle:ThemeElement AS te
                    JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
                    WHERE te.theme = :theme_id AND tdf.dataField = :datafield
                    AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL'
                )->setParameters( array('theme_id' => $theme->getId(), 'datafield' => $datafield->getId()) );
                $result = $query->getResult();
                /** @var ThemeDataField $theme_datafield */
                $theme_datafield = $result[0];
                $theme_datafield_form = $this->createForm(UpdateThemeDatafieldForm::class, $theme_datafield);


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
     * Helper function to determine whether a datafield can be deleted
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
            $ret = array(
                'prevent_deletion' => true,
                'prevent_deletion_message' => "This datafield is currently in use as the Datatype's external ID field...unable to delete",
            );
        }

        return $ret;
    }


    /**
     * Helper function to determine whether a datafield can have its fieldtype changed
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

        // Always bypass cache in dev mode
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;


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
            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);
            $top_level_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $datatype->getId());


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
                if ( $tdf->getDataField()->getId() !== $removed_datafield->getId() )
                    $datafield_list[ $tdf->getDisplayOrder() ] = $tdf;
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
    }


    /**
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
            // Grab necessary objects
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
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
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
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x182537020 ' . $e->getMessage();

            if ($em !== null)
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
     * @return Response
     */
    public function undeleteDatafieldAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {

            throw new \Exception('DISABLED UNTIL SOFT-DELETION OF DATAFIELDS AND THEME STUFF IS WORKING PROPERLY');

            $post = $_POST;
//print_r($post);
//return;
            $datafield_id = $post['datafield_id'];

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
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
     * @return Response
     */
    public function datatypepublicAction($datatype_id, Request $request)
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
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
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


            // If the datatype is public, make it non-public...if datatype is non-public, make it public
            if ( $datatype->isPublic() ) {

                // Don't allow the datatype to be marked as non-public if it's set as the default search
//                if ($datatype->getIsDefaultSearchDatatype() == true)
//                    throw new \Exception("This Datatype can't be set as non-public because it's marked as the default search datatype...");

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

            // TODO - update cached version directly?
            parent::tmp_updateDatatypeCache($em, $datatype, $user);
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
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataFields $datafield
     *
     * @return boolean true if the datafield has no duplicate values, false otherwise
     */
    private function datafieldCanBeUnique($em, $datafield)
    {
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
        $datatree_array = parent::getDatatreeArray($em);
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

}
