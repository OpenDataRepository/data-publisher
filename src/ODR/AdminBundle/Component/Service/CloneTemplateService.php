<?php

/**
 * Open Data Repository Data Publisher
 * Clone Template Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains the functions required to clone or sync a datatype with its master template.
 * Apparently also works with metadata datatypes, provided they have a master template of their own.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptionsMap;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\TagTree;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeRenderPluginInstance;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatatypeImportedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class CloneTemplateService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var EntityMetaModifyService
     */
    private $emm_service;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var CloneThemeService
     */
    private $ct_service;

    /**
     * @var DatabaseInfoService
     */
    private $dbi_service;

    /**
     * @var DatatreeInfoService
     */
    private $dti_service;

    /**
     * @var EntityCreationService
     */
    private $ec_service;

    /**
     * @var LockService
     */
    private $lock_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var ThemeInfoService
     */
    private $ti_service;

    /**
     * @var UUIDService
     */
    private $uuid_service;

    /**
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var DataType[]
     */
    private $template_datatypes;

    /**
     * @var DataFields[]
     */
    private $template_datafields;

    /**
     * @var RadioOptions[]
     */
    private $template_radio_options;

    /**
     * @var Tags[]
     */
    private $template_tags;

    /**
     * @var DataType[]
     */
    private $derived_datatypes;

    /**
     * @var DataFields[]
     */
    private $derived_datafields;

    /**
     * @var RadioOptions[]
     */
    private $derived_radio_options;

    /**
     * @var Tags[]
     */
    private $derived_tags;

    /**
     * @var TagTree[]
     */
    private $derived_tag_trees;

    /**
     * @var DataType[]
     */
    private $modified_linked_datatypes;

    /**
     * @var DataFields[]
     */
    private $created_datafields;

    /**
     * @var DataTypeSpecialFields[]
     */
    private $created_dtsf_entries;


    /**
     * CloneTemplateService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param CloneThemeService $clone_theme_service
     * @param DatabaseInfoService $database_info_service
     * @param DatatreeInfoService $datatree_info_service
     * @param EntityCreationService $entity_creation_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param LockService $lock_service
     * @param PermissionsManagementService $permissions_service
     * @param ThemeInfoService $theme_info_service
     * @param UUIDService $uuid_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        CloneThemeService $clone_theme_service,
        DatabaseInfoService $database_info_service,
        DatatreeInfoService $datatree_info_service,
        EntityCreationService $entity_creation_service,
        EntityMetaModifyService $entity_meta_modify_service,
        LockService $lock_service,
        PermissionsManagementService $permissions_service,
        ThemeInfoService $theme_info_service,
        UUIDService $uuid_service,
        EventDispatcherInterface $event_dispatcher,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->ct_service = $clone_theme_service;
        $this->dbi_service = $database_info_service;
        $this->dti_service = $datatree_info_service;
        $this->ec_service = $entity_creation_service;
        $this->emm_service = $entity_meta_modify_service;
        $this->lock_service = $lock_service;
        $this->pm_service = $permissions_service;
        $this->ti_service = $theme_info_service;
        $this->uuid_service = $uuid_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->logger = $logger;

        $this->template_datatypes = array();
        $this->template_datafields = array();
        $this->template_radio_options = array();
        $this->template_tags = array();

        $this->derived_datatypes = array();
        $this->derived_datafields = array();
        $this->derived_radio_options = array();
        $this->derived_tags = array();
        $this->derived_tag_trees = array();

        $this->modified_linked_datatypes = array();

        $this->created_datafields = array();
        $this->created_dtsf_entries = array();
    }


    /**
     * Saves and reloads the provided object from the database.
     *
     * @param mixed $obj
     * @param ODRUser $user
     * @param bool $delay_flush
     */
    private function persistObject($obj, $user, $delay_flush = false)
    {
        //
        if (method_exists($obj, "setCreated"))
            $obj->setCreated(new \DateTime());
        if (method_exists($obj, "setUpdated"))
            $obj->setUpdated(new \DateTime());

        //
        if ($user != null) {
            if (method_exists($obj, "setCreatedBy"))
                $obj->setCreatedBy($user);

            if (method_exists($obj, "setUpdatedBy"))
                $obj->setUpdatedBy($user);
        }

        $this->em->persist($obj);

        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($obj);
        }
    }


    /**
     * Returns whether the given datafield is out of sync with its master template version.
     * TODO - should this instead fail if any part of the datatype is out of sync?
     *
     * @param DataFields $datafield
     * @return bool
     */
    public function isDatafieldOutOfSync($datafield)
    {
        // if this is not a derived field, then it's not "out of sync" by definition
        if ( is_null($datafield->getMasterDataField()) )
            return false;

        // Going to need these...
        $datatype = $datafield->getDataType();
        $master_datafield = $datafield->getMasterDataField();
        $master_datatype = $datatype->getMasterDataType();

        $diff = self::getDiffWithTemplate($datatype->getGrandparent());
        if ( empty($diff) ) {
            // If the diff array is empty, then the datafield is in sync with its master datafield
            return false;
        }

        // Otherwise, since the datafield in question could belong to a child datatype, and the diff
        //  array is stacked...it's better to use recursion
        $dt = $diff[$master_datatype->getGrandparent()->getId()];
        return self::isDatafieldOutOfSync_worker($dt, $master_datafield->getId());
    }


    /**
     * Does the recursive work for self::isDatafieldOutOfSync().
     *
     * @param array $dt_array
     * @param integer $datafield_id
     * @return bool
     */
    private function isDatafieldOutOfSync_worker($dt_array, $datafield_id)
    {
        // If this datafield has an entry in the array of differences...
        if ( isset($dt_array['dataFields'][$datafield_id]) ) {
            // ...then the field is out out sync
            return true;
        }
        else if ( isset($dt_array['descendants']) ) {
            // ...otherwise, check whether this datatype's descendants have the datafield
            foreach ($dt_array['descendants'] as $dt_id => $tmp) {
                $child_dt = $tmp['datatype'][$dt_id];
                $ret = self::isDatafieldOutOfSync_worker($child_dt, $datafield_id);
                if ( $ret ) {
                    // If the datafield was found in this descendant and is out of sync, don't
                    //  continue looking
                    return true;
                }
            }
        }

        // If this point is reached, the datafield is either in sync or hasn't been found
        return false;
    }


    /**
     * Returns true if the provided Datatype is missing Datafields and/or child/linked Datatypes
     * that its Master Template has.  Also, the user needs to be capable of actually making changes
     * to the layout for this to return true.
     *
     * @param DataType $datatype
     * @param ODRUser $user
     *
     * @return bool
     */
    public function canSyncWithTemplate($datatype, $user)
    {
        // ----------------------------------------
        // If the user isn't allowed to make changes to this datatype, or the datatype is not
        //  derived from a master template, then it makes no sense to continue...
        if ($user === 'anon.')
            return false;
        if ( !$this->pm_service->isDatatypeAdmin($user, $datatype) )
            return false;
        if ( is_null($datatype->getMasterDataType()) )
            return false;


        // ----------------------------------------
        // Determine if this datatype's master template has any datafields/datatypes that the
        //  given datatype does not have...
        $diff = self::getDiffWithTemplate($datatype);
        if ( count($diff) > 0 )
            return true;

        // Otherwise, no appreciable changes have been made...no need to synchronize
        return false;
    }


    /**
     * Locates all datatypes/datafields in the given datatype's master template that aren't in
     *  the given datatype.
     *
     * @param DataType $datatype
     *
     * @return array
     */
    public function getDiffWithTemplate($datatype)
    {
        // ----------------------------------------
        if ( is_null($datatype->getMetadataFor()) ) {
            // This is not a metadata datatype...
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Child datatypes should not be directly checked for differences...check their grandparent datatypes instead');
        }
        else {
            // This is a metadata datatype...require it to be top-level
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Only top-level metadata datatypes should be checked for differences');
        }
        if ( is_null($datatype->getMasterDataType()) )
            throw new ODRBadRequestException('The given datatype is not derived from a Master Template...unable to check for differences');


        // At the moment, only new datafields, new child/linked datatypes, and fieldtype changes
        //  are considered "noteworthy"...TODO - is there more stuff that should be?


        // ----------------------------------------
        // Load, stack, and clean the cached_datatype array for the master template
        $master_datatype = $datatype->getMasterDataType();

        $template_datatype = $this->dbi_service->getDatatypeArray($master_datatype->getId());
        $template_datatype[ $master_datatype->getId() ] = $this->dbi_service->stackDatatypeArray($template_datatype, $master_datatype->getId());

        // TODO - stackDatatypeArray() apparently leaves the child/linked datatypes lying around in the stacked array?
        foreach ($template_datatype as $dt_id => $dt) {
            if ( $dt_id !== $master_datatype->getId() )
                unset( $template_datatype[$dt_id] );
        }
        $template_datatype = self::cleanDatatypeArray($template_datatype);


        // ----------------------------------------
        // Load, stack, and clean the cached_datatype array for the derived datatype
        $derived_datatype = $this->dbi_service->getDatatypeArray($datatype->getId());
        $derived_datatype[ $datatype->getId() ] = $this->dbi_service->stackDatatypeArray($derived_datatype, $datatype->getId());

        // TODO - stackDatatypeArray() apparently leaves the child/linked datatypes lying around in the stacked array?
        foreach ($derived_datatype as $dt_id => $dt) {
            if ( $dt_id !== $datatype->getId() )
                unset( $derived_datatype[$dt_id] );
        }
        $derived_datatype = self::cleanDatatypeArray($derived_datatype);


        // ----------------------------------------
        // Remove all entries from the template's array that the derived datatype already has
        $diff = self::removeMatchingEntries($template_datatype, $derived_datatype);

        return $diff;
    }


    /**
     * It's easier to use the existing cached_datatype arrays to detect template-related changes,
     *  but there's a lot of stuff in that array that's not relevant to this...
     *
     * @param array $datatype
     * @param bool $is_top_level
     *
     * @return array
     */
    private function cleanDatatypeArray($datatype)
    {
        // Only want to keep these keys...defining it this way because isset() is faster than in_array()
        $keep = array(
            'id' => 1,
//            'revision' => 1,
//            'unique_id' => 1,
//            'setup_step' => 1,
//            'preload_status' => 1,
//            'is_master_type' => 1,
            'template_group' => 1,
//            'datatype_type' => 1,
//            'metadata_datatype' => 1,
//            'metadata_for' => 1,
            'dataTypeMeta' => 1,
            'masterDataType' => 1,
            'dataFields' => 1,
            'descendants' => 1,
//            'nameFields' => 1,
//            'sortFields' => 1,

            'copy_theme_structure' => 1,
        );

        $meta_keep = array(
//            'master_revision' => 1,
//            'master_published_revision' => 1,
//            'tracking_master_revision' => 1,
//            'searchSlug' => 1,
//            'shortName' => 1,
//            'longName' => 1,
//            'description' => 1,
//            'searchNotesUpper' => 1,
//            'searchNotesLower' => 1,
//            'publicDate' => 1,
//            'newRecordsArePublic' => 1,
//            'externalIdField' => 1,
//            'backgroundImageField' => 1,
        );

        // NOTE - derived datatypes don't really have much of a reason to change external_id/name/sort
        //  fields (assuming the template is properly designed), but actually enforcing this is both
        //  difficult and doesn't really make sense either

        foreach ($datatype as $dt_id => $dt) {
            // This flag controls whether the template's theme_elements/theme_datafield entries are
            //  cloned directly from the template later during the synchronization process.
            // It's set to false when the derived datatype has at least one datafield from the
            //  master template already, but needs additional datafields to be "in sync".
            $datatype[$dt_id]['copy_theme_structure'] = 1;

            foreach ($dt as $key => $value) {
                if ( !isset($keep[$key]) )
                    unset( $datatype[$dt_id][$key] );
            }
            foreach ($dt['dataTypeMeta'] as $key => $value) {
                if ( !isset($meta_keep[$key]) )
                    unset( $datatype[$dt_id]['dataTypeMeta'][$key] );
            }

            // Clean up all the unneeded stuff in the datafields segment of the array...
            if ( isset($dt['dataFields']) )
                $datatype[$dt_id]['dataFields'] = self::cleanDatafieldArray($dt['dataFields']);

            // Clean up child/linked datatypes as well...
            if ( isset($dt['descendants']) ) {
                foreach ($dt['descendants'] as $c_dt_id => $c_dt) {
                    // Save for later convenience
                    $datatype[$dt_id]['descendants'][$c_dt_id]['is_top_level'] = 0;

                    $datatype[$dt_id]['descendants'][$c_dt_id]['datatype'] = self::cleanDatatypeArray($c_dt['datatype']);
                }
            }
        }

        return $datatype;
    }


    /**
     * For logistical simplicity, it's easier to split this out from self::cleanDatatypeArray()...
     *
     * @param array $datafields
     *
     * @return array
     */
    private function cleanDatafieldArray($datafields)
    {
        // Only want to keep these keys...defining it this way because isset() is faster than in_array()
        $keep = array(
            'id' => 1,
            'dataFieldMeta' => 1,
//            'is_master_field' => 1,
//            'fieldUuid' => 1,
//            'templateFieldUuid' => 1,
            'masterDataField' => 1,
            'fieldType' => 1,
            'radioOptions' => 1,
            'tags' => 1,
            'tagTree' => 1,
        );
        $keep_df_meta = array(
            'is_unique' => 1,
            'allow_multiple_uploads' => 1,
            'radio_option_name_sort' => 1,
            'tags_allow_multiple_levels' => 1,
        );
        // NOTE - if adding/removing any of these datafieldMeta entries, need to modify both
        //  DisplaytemplateController and UpdateDataFieldsForm as well

        foreach ($datafields as $df_id => $df) {
            // Move the fieldtype from the datafieldMeta entry into the datafield itself
            $datafields[$df_id]['fieldType'] = $df['dataFieldMeta']['fieldType']['typeClass'];

            // Get rid of every key that's not relevant to comparing with a template...
            foreach ($df as $key => $value) {
                if ( !isset($keep[$key]) )
                    unset( $datafields[$df_id][$key] );
            }
            foreach ($df['dataFieldMeta'] as $key => $value) {
                if ( !isset($keep_df_meta[$key]) )
                    unset( $datafields[$df_id]['dataFieldMeta'][$key] );
            }

            // TODO - change of datafield public status

            // Flatten the array of radio options if it exists
            if ( isset($df['radioOptions']) ) {
                $new_ro_list = array();
                foreach ($df['radioOptions'] as $num => $ro)
                    $new_ro_list[ $ro['radioOptionUuid'] ] = $ro['optionName'];
                $datafields[$df_id]['radioOptions'] = $new_ro_list;
            }

            // If tag tree entries exist, they need to be flattened and stored by tagUuid instead
            //  of by tag ID...since they're stacked, this needs to be done recursively
            if ( isset($df['tags']) ) {
                $new_tag_list = array();
                $new_tag_tree = array();
                self::cleanTagArray($df['tags'], $new_tag_list, $new_tag_tree, null);

                $datafields[$df_id]['tags'] = $new_tag_list;
                $datafields[$df_id]['tagTree'] = $new_tag_tree;
            }
        }

        return $datafields;
    }


    /**
     * Because tags are stored in stacked format, recursive shennanigans are needed to flatten them...
     *
     * @param array $tag_array
     * @param array $new_tag_list
     * @param array $new_tag_tree
     * @param string $parent_tag_uuid
     */
    private function cleanTagArray($tag_array, &$new_tag_list, &$new_tag_tree, $parent_tag_uuid)
    {
        foreach ($tag_array as $tag_id => $tag) {
            $tag_uuid = $tag['tagUuid'];
            $tag_name = $tag['tagName'];
            $display_order = $tag['tagMeta']['displayOrder'];

            // Flatten the tag array and store by uuid...
            $new_tag_list[$tag_uuid] = array(
                'tagName' => $tag_name,
                'displayOrder' => $display_order,
            );

            // Also convert the tag tree from parent_id => array of child_ids to store uuids instead
            if ( !is_null($parent_tag_uuid) ) {
                if ( !isset($new_tag_tree[$parent_tag_uuid]) )
                    $new_tag_tree[$parent_tag_uuid] = array();
                $new_tag_tree[$parent_tag_uuid][$tag_uuid] = '';
            }

            // If this tag has children, continue to flatten them
            if ( isset($tag['children']) )
                self::cleanTagArray($tag['children'], $new_tag_list, $new_tag_tree, $tag_uuid);
        }
    }


    /**
     * Crawl through two cached_datatype arrays...one for a master template, the second for a
     *  datatype derived from that template...and attempt to locate any differences between them.
     *
     * Areas where the derived datatype matches the template are removed from the template array,
     *  and anything leftover in the template array indicates the derived datatype is out of date.
     *
     * @param array $template_array
     * @param array $derived_array
     *
     * @return array
     */
    private function removeMatchingEntries($template_array, $derived_array)
    {
        // Each array should only have one key at the top-level, the datatype_id
        $t_dt_id = array_keys($template_array)[0];
        $dt_id = array_keys($derived_array)[0];

        // ----------------------------------------
        // Check for differences in the dataTypeMeta entity...
        foreach ($derived_array[$dt_id]['dataTypeMeta'] as $key => $value) {
            // Should only need to check whether the values match...
            if ( $template_array[$t_dt_id]['dataTypeMeta'][$key] === $value ) {
                //  The template datatype and the derived datatype match, unset the array entry
                unset( $template_array[$t_dt_id]['dataTypeMeta'][$key] );
            }
        }


        // ----------------------------------------
        // Check for differences between datafields in this datatype...
        if ( isset($derived_array[$dt_id]['dataFields']) ) {
            $derived_datafields = $derived_array[$dt_id]['dataFields'];
            foreach ($derived_datafields as $df_id => $df) {
                // If a field was manually added in the derived datatype, ignore it
                if ( is_null($df['masterDataField']) )
                    continue;
                $master_df_id = $df['masterDataField']['id'];
                $fieldtype = $df['fieldType'];

                // Otherwise, see if the master datafield still exists in the template...
                if ( isset($template_array[$t_dt_id]['dataFields']) ) {
                    $template_datafields = $template_array[$t_dt_id]['dataFields'];

                    if ( !isset($template_datafields[$master_df_id]) ) {
                        // This datafield got deleted out of the template datatype...create a "fake"
                        //  entry so that syncDatatype() can figure out it needs to delete the
                        //  relevant derived datafield

                        // TODO - revisit, disabled again because deleting a derived field will likely cause data loss
//                        $template_array[$t_dt_id]['dataFields'][$master_df_id] = array(
//                            'id' => $master_df_id,
//                            'masterDataField' => null,
//                            'deleted' => true,
//                        );
                    }
                    else {
                        // Field exists
                        $change_made = false;

                        if ( $template_datafields[$master_df_id]['fieldType'] !== $fieldtype ) {
                            // The derived datafield has a different fieldtype than the template
                            //  datafield...TODO - figure out what it would take to enable this...
//                            $change_made = true;
                        }

                        // TODO - change of datafield public status?

                        // Check datafield properties...
                        foreach ($template_datafields[$master_df_id]['dataFieldMeta'] as $key => $value) {
                            if ( $df['dataFieldMeta'][$key] !== $value ) {
                                // The derived field has a different value than the master field
                                $change_made = true;
                            }
                            else {
                                // Don't need to modify this property
                                unset( $template_array[$t_dt_id]['dataFields'][$master_df_id]['dataFieldMeta'][$key] );
                            }
                        }

                        // Need to check radio options...
                        if ( isset($template_datafields[$master_df_id]['radioOptions']) ) {
                            $template_options = $template_datafields[$master_df_id]['radioOptions'];
                            $derived_options = array();
                            if ( isset($derived_datafields[$df_id]['radioOptions']) )
                                $derived_options = $derived_datafields[$df_id]['radioOptions'];

                            $radio_option_changelists = self::buildRadioOptionsChangelist($template_options, $derived_options);

                            if ( count($radio_option_changelists) > 0 ) {
                                $change_made = true;
                                $template_array[$t_dt_id]['dataFields'][$master_df_id]['radioOptions'] = $radio_option_changelists;
                            }
                            else {
                                $template_array[$t_dt_id]['dataFields'][$master_df_id]['radioOptions'] = array(
                                    'created' => array(),
                                    'updated' => array(),
                                    'deleted' => array(),
                                );
                            }
                        }

                        // Need to check tags...
                        if ( isset($template_datafields[$master_df_id]['tags']) ) {
                            $template_tags = $template_datafields[$master_df_id]['tags'];
                            $derived_tags = array();
                            if ( isset($derived_datafields[$df_id]['tags']) )
                                $derived_tags = $derived_datafields[$df_id]['tags'];

                            $tags_changelist = self::buildTagsChangelist($template_tags, $derived_tags);

                            if ( count($tags_changelist) > 0 ) {
                                $change_made = true;
                                if ( isset($tags_changelist['created']) ) {
                                    $template_array[$t_dt_id]['dataFields'][$master_df_id]['tags']['created'] = $tags_changelist['created'];
                                    $template_array[$t_dt_id]['dataFields'][$master_df_id]['tags']['updated'] = $tags_changelist['updated'];
                                    $template_array[$t_dt_id]['dataFields'][$master_df_id]['tags']['deleted'] = $tags_changelist['deleted'];
                                }
                            }
                            else {
                                // Can't leave these lying around
                                $template_array[$t_dt_id]['dataFields'][$master_df_id]['tags'] = array(
                                    'created' => array(),
                                    'updated' => array(),
                                    'deleted' => array(),
                                );
                            }
                        }

                        // Need to check tag hierarchy...
                        if ( isset($template_datafields[$master_df_id]['tagTree']) ) {
                            $template_tag_hierarchy = $template_datafields[$master_df_id]['tagTree'];
                            $derived_tag_hierarchy = $derived_datafields[$df_id]['tagTree'];
                            $tag_tree_changelist = self::buildTagTreeChangelist($template_tag_hierarchy, $derived_tag_hierarchy);

                            if ( count($tag_tree_changelist) > 0 ) {
                                $change_made = true;
                                $template_array[$t_dt_id]['dataFields'][$master_df_id]['tagTree'] = $tag_tree_changelist;
                            }
                            else {
                                // Can't leave these lying around
                                $template_array[$t_dt_id]['dataFields'][$master_df_id]['tagTree'] = array();
                            }
                        }


                        if ( !$change_made ) {
                            // If there's no difference between this field in the derived datatype
                            //  and the template, then get rid of it
                            unset( $template_array[$t_dt_id]['dataFields'][$master_df_id] );

                            // Also, since the derived datatype has at least one datafield from the
                            //  template datatype...if any copying needs to be done later, don't
                            //  copy straight from the template's theme structure
                            $template_array[$t_dt_id]['copy_theme_structure'] = 0;
                        }
                    }
                }
            }
        }


        // ----------------------------------------
        // Check for differences between any child datatypes...
        if ( isset($derived_array[$dt_id]['descendants']) ) {
            foreach ($derived_array[$dt_id]['descendants'] as $c_dt_id => $c_dt) {
                // If a child/linked datatype was manually added in the derived datatype, ignore it
                if ( is_null($c_dt['datatype'][$c_dt_id]['masterDataType']) )
                    continue;
                $child_master_id = $c_dt['datatype'][$c_dt_id]['masterDataType']['id'];

                if ( !isset($template_array[$t_dt_id]['descendants'][$child_master_id]) ) {
                    // TODO - childtype deleted out of template datatype
                    // TODO - linked datatype removed from template datatype
                }
                else {
                    // Recursively determine if any changes were made to these child datatypes
                    $template_child_datatype = $template_array[$t_dt_id]['descendants'][$child_master_id]['datatype'];
                    $derived_child_datatype = $c_dt['datatype'];

                    $cleaned_template_child = self::removeMatchingEntries($template_child_datatype, $derived_child_datatype);

                    if ( count($cleaned_template_child) > 0 ) {
                        // If there were differences in this child datatype, then save them
                        $template_array[$t_dt_id]['descendants'][$child_master_id]['datatype'][$child_master_id] = $cleaned_template_child[$child_master_id];
                    }
                    else {
                        // No differences...leave no trace of this child datatype in the template array
                        unset( $template_array[$t_dt_id]['descendants'][$child_master_id]/*['datatype'][$child_master_id]*/ );
                    }
                }
            }
        }


        // ----------------------------------------
        // Assume no changes made to the template...
        $has_datatype_changes = false;
        $has_datafield_changes = false;
        $has_childtype_changes = false;

        if ( isset($template_array[$t_dt_id]['dataTypeMeta']) ) {
            if ( count($template_array[$t_dt_id]['dataTypeMeta']) > 0 )
                $has_datatype_changes = true;
        }

        if ( isset($template_array[$t_dt_id]['dataFields']) ) {
            if ( count($template_array[$t_dt_id]['dataFields']) > 0 )
                $has_datafield_changes = true;
            else
                unset( $template_array[$t_dt_id]['dataFields'] );
        }

        if ( isset($template_array[$t_dt_id]['descendants']) ) {
            if ( count($template_array[$t_dt_id]['descendants']) > 0 )
                $has_childtype_changes = true;
            else
                unset( $template_array[$t_dt_id]['descendants'] );
        }


        // If the derived datatype matches the template datatype, then return that no changes need to be made
        if ( !$has_datatype_changes && !$has_datafield_changes && !$has_childtype_changes )
            return array();
        else
            return $template_array;
    }


    /**
     * For logisitical simplicity, it's easier to split this out from self::removeMatchingEntries()...
     *
     * @param array $template_options
     * @param array $derived_options
     *
     * @return array
     */
    private function buildRadioOptionsChangelist($template_options, $derived_options)
    {
        $changelist = array(
            'created' => array(),
            'updated' => array(),
            'deleted' => array(),
        );

        // Check every radio option listed in the template datatype...
        foreach ($template_options as $ro_uuid => $option_name) {
            if ( !isset($derived_options[$ro_uuid]) ) {
                // The derived datatype does not have this radio option
                $changelist['created'][$ro_uuid] = $option_name;
            }
            else if ( $derived_options[$ro_uuid] !== $option_name ) {
                // The radio option in the derived datatype has a different name
                $changelist['updated'][$ro_uuid] = $option_name;
            }
        }

        // Check every radio option listed in the derived datatype...
        foreach ($derived_options as $ro_uuid => $option_name) {
            if ( !isset($template_options[$ro_uuid]) ) {
                // Derived datatypes can't create their own radio options, so an entry in the derived
                //  datatype but not in the template datatype indicates a deleted radio option
                $changelist['deleted'][$ro_uuid] = $option_name;
            }
        }

        // If there were any changes made, return them
        if ( !empty($changelist['created']) || !empty($changelist['updated']) || !empty($changelist['deleted']) )
            return $changelist;
        else
            return array();
    }


    /**
     * For logisitical simplicity, it's easier to split this out from self::removeMatchingEntries()...
     *
     * @param array $template_tags
     * @param array $derived_tags
     *
     * @return array
     */
    private function buildTagsChangelist($template_tags, $derived_tags)
    {
        $changelist = array(
            'created' => array(),
            'updated' => array(),
            'deleted' => array(),
        );

        // Check every tag listed in the template datatype...
        foreach ($template_tags as $tag_uuid => $tag_data) {
            // Need to check tag name and display order...
            $template_tag_name = $tag_data['tagName'];
            $template_display_order = $tag_data['displayOrder'];

            if ( !isset($derived_tags[$tag_uuid]) ) {
                // The derived datatype does not have this tag
                $changelist['created'][$tag_uuid] = $tag_data;
            }
            else {
                $derived_tag_name = $derived_tags[$tag_uuid]['tagName'];
                $derived_display_order = $derived_tags[$tag_uuid]['displayOrder'];

                // If the template tag's name/displayOrder does not match the name/displayOrder for
                //  the derived tag...
                if ( $template_tag_name !== $derived_tag_name
                    || $template_display_order !== $derived_display_order
                ) {
                    // ...then the derived tag is going to need updated
                    $changelist['updated'][$tag_uuid] = $tag_data;
                }
            }
        }

        // Derived datatypes can't create their own tags, so an entry in the derived datatype that
        //  isn't in the template datatype indicates the template deleted a tag
        foreach ($derived_tags as $tag_uuid => $tag_data) {
            if ( !isset($template_tags[$tag_uuid]) )
                $changelist['deleted'][$tag_uuid] = $tag_data;

            // Don't need check for name/displayOrder updates here
        }

        // If there were any changes made, return them
        if ( !empty($changelist['created']) || !empty($changelist['updated']) || !empty($changelist['deleted']) )
            return $changelist;
        else
            return array();
    }


    /**
     * For logisitical simplicity, it's easier to split this out from self::removeMatchingEntries()...
     *
     * @param array $template_tag_hierarchy
     * @param array $derived_tag_hierarchy
     *
     * @return array
     */
    private function buildTagTreeChangelist($template_tag_hierarchy, $derived_tag_hierarchy)
    {
        $changelist = array(
            'created' => array(),
            'deleted' => array(),
        );

        // For every tag tree entry listed in the template datatype...
        foreach ($template_tag_hierarchy as $parent_tag_uuid => $child_tags) {
            foreach ($child_tags as $child_tag_uuid => $str) {
                // ...if the derived datatype does not have an identical entry...
                if ( !isset($derived_tag_hierarchy[$parent_tag_uuid])
                    || !isset($derived_tag_hierarchy[$parent_tag_uuid][$child_tag_uuid])
                ) {
                    // ...then the derived datatype needs a new tag tree entry
                    if ( !isset($changelist['created'][$parent_tag_uuid]) )
                        $changelist['created'][$parent_tag_uuid] = array();
                    $changelist['created'][$parent_tag_uuid][$child_tag_uuid] = '';
                }
            }
        }

        // For every tag tree entry listed in the derived datatype...
        foreach ($derived_tag_hierarchy as $parent_tag_uuid => $child_tags) {
            foreach ($child_tags as $child_tag_uuid => $str) {
                // ...if the template datatype does not have an identical entry...
                if ( !isset($template_tag_hierarchy[$parent_tag_uuid])
                    || !isset($template_tag_hierarchy[$parent_tag_uuid][$child_tag_uuid])
                ) {
                    // ...then the derived datatype needs to delete a tag tree entry
                    if ( !isset($changelist['deleted'][$parent_tag_uuid]) )
                        $changelist['deleted'][$parent_tag_uuid] = array();
                    $changelist['deleted'][$parent_tag_uuid][$child_tag_uuid] = '';
                }
            }
        }

        // If there were any changes made, return them
        if ( !empty($changelist['created']) || !empty($changelist['deleted']) )
            return $changelist;
        else
            return array();
    }


    /**
     * Ensures the given datatype is synchronized with its master template.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     *
     * @return bool
     */
    public function syncWithTemplate($user, $datatype)
    {
        // ----------------------------------------
        // If the user isn't an admin of this datatype, don't do anything
        if ( !$this->pm_service->isDatatypeAdmin($user, $datatype) )
            return false;

        // If no changes need to be made, don't do anything
        $diff = self::getDiffWithTemplate($datatype);
        if ( count($diff) === 0 )
            return false;


        // Bad Things (tm) happen if multiple processes attempt to synchronize the same template at
        //  the same time, so use Symfony's LockHandler component to prevent that...
        $lockHandler = $this->lock_service->createLock('datatype_'.$datatype->getId().'_sync_with_master.lock', 900.0);    // acquire lock for 15 minutes?
        if ( !$lockHandler->acquire() ) {
            // Another process is already synchronizing this template...block until it's done...
            $lockHandler->acquire(true);
            // ...then abort the synchronization without duplicating any changes
            return false;
        }


        $master_datatype = $datatype->getMasterDataType();
        $this->logger->info('----------------------------------------');
        $this->logger->info('CloneTemplateService: attempting to sync datatype '.$datatype->getId().' "'.$datatype->getShortName().'" with its master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"...');

        // Traverse the diff array to locate the template's datatypes/datafields/radio options
        //  that need to be hydrated
        self::locateEntriesToHydrate($diff);


        // ----------------------------------------
        // Need to get a list of all top-level datatypes associated with the master template
        $template_grandparents = $this->dti_service->getAssociatedDatatypes($master_datatype->getId());

        // Convert the arrays of datatype/datafield/radio option ids into a format for querying
        $query_datatypes = array_keys($this->template_datatypes);
        $query_datafields = array_keys($this->template_datafields);
        $query_radio_options = array_keys($this->template_radio_options);
        $query_tags = array_keys($this->template_tags);


        // For convenience, pre-load and hydrate all relevant datatypes, datafields, radio options,
        //  and tags across the master template
        $type = 'template';
        $this->template_datatypes = self::hydrateDatatypes($template_grandparents, $query_datatypes, $type);
        $this->template_datafields = self::hydrateDatafields($template_grandparents, $query_datafields, $type);
        $this->template_radio_options = self::hydrateRadioOptions($template_grandparents, $query_radio_options, $type);
        $this->template_tags = self::hydrateTags($template_grandparents, $query_tags, $type);

        // Don't need to hydrate template-side tagTree entries...any of these type of entries that
        //  need to get created can be done with just tag ids


        // ----------------------------------------
        // Also need to get a list of all top-level datatypes associated with the derived datatype
        $derived_grandparents = $this->dti_service->getAssociatedDatatypes($datatype->getId());

        // Convert the arrays of datatype/datafield/radio option ids into a format for querying
        $query_datatypes = array_keys($this->derived_datatypes);
        $query_datafields = array_keys($this->derived_datafields);
        $query_radio_options = array_keys($this->derived_radio_options);
        $query_tags = array_keys($this->derived_tags);


        // For convenience, pre-load and hydrate all relevant datatypes, datafields, radio options,
        //  and tags across the derived datatypes
        $type = 'derived';
        $this->derived_datatypes = self::hydrateDatatypes($derived_grandparents, $query_datatypes, $type);
        $this->derived_datafields = self::hydrateDatafields($derived_grandparents, $query_datafields, $type);
        $this->derived_radio_options = self::hydrateRadioOptions($derived_grandparents, $query_radio_options, $type);
        $this->derived_tags = self::hydrateTags($derived_grandparents, $query_tags, $type);
        $this->derived_tag_trees = self::hydrateTagTrees($derived_grandparents, $this->derived_tag_trees);


        // ----------------------------------------
        // Because of deletion of radio options, tags, and (eventually) datafields, wrap this in a
        //  transaction
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        // Synchronize the derived datatype with its source
        self::syncDatatype($datatype, $user, $diff[$master_datatype->getId()], 0);

        // If a linked theme got modified, then the updates only went to its master theme...
        if ( count($this->modified_linked_datatypes) > 0 ) {
            // ...need to synchronize the master themes of the given datatype with their source themes
            //  so the updates are immediately visible
            $master_theme = $this->ti_service->getDatatypeMasterTheme($datatype->getId());

            $this->logger->info('CloneTemplateService: synchronizing datatype '.$datatype->getId().' "'.$datatype->getShortName().'" with its source to pick up changes to linked datatypes...');
            $this->ct_service->syncThemeWithSource($user, $master_theme);
        }


        // ----------------------------------------
        // Done with the synchronization
        $conn->commit();

        $this->logger->info('CloneTemplateService: datatype '.$datatype->getId().' "'.$datatype->getShortName().'" is now synchronized with its master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"');

        // Wipe all potentially relevant cache entries
        $this->cache_service->delete('top_level_datatypes');
        $this->cache_service->delete('top_level_themes');
        $this->cache_service->delete('cached_datatree_array');

        $modified_top_level_datatypes = array();
        foreach ($this->derived_datatypes as $dt) {
            // Don't remember whether $this->derived_datatypes contains linked datatypes or not...
            //  ...do it this way to be safe
            if ( $dt->getGrandparent()->getId() === $dt->getId() )
                $modified_top_level_datatypes[ $dt->getId() ] = $dt;
        }

        foreach ($modified_top_level_datatypes as $dt_id => $dt) {
            // Since the job is now done (in theory), delete all search cache entries
            //  relevant to this datatype
            try {
                $event = new DatatypeImportedEvent($dt, $user);
                $this->event_dispatcher->dispatch(DatatypeImportedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            $master_theme = $this->ti_service->getDatatypeMasterTheme($dt_id);
            $this->cache_service->delete('cached_theme_'.$master_theme->getId());

            // TODO - ...do the datarecord entries need to be wiped too?  Theoretically nothing was deleted, so the entries should be fine?
        }

        // Need to also delete the permissions related cache entries...technically they've already
        //  been deleted because of calling createGroupsForDatatype() and createGroupsForDatafield()
        //  during creation of those entities, but the background sync status checker will likely
        //  have rebuilt the relevant cache entries (and has done so repeatedly during testing)...

        // Locate all users and groups that have been modified by this
        $query = $this->em->createQuery(
           'SELECT u.id AS user_id
            FROM ODRAdminBundle:Group AS g
            LEFT JOIN ODRAdminBundle:UserGroup AS ug WITH ug.group = g
            LEFT JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
            WHERE g.dataType IN (:datatype_ids)
            AND g.deletedAt IS NULL AND ug.deletedAt IS NULL'
        )->setParameters(
            array(
                'datatype_ids' => array_keys($modified_top_level_datatypes)
            )
        );
        $results = $query->getArrayResult();

        $affected_users = array();
        foreach ($results as $result) {
            $user_id = $result['user_id'];

            if ( !is_null($user_id) )
                $affected_users[$user_id] = 1;
        }

        // Need to separately locate all super_admins, since they're going to need permissions
        //  cleared too
        $query = $this->em->createQuery(
           'SELECT u.id AS user_id
            FROM ODROpenRepositoryUserBundle:User AS u
            WHERE u.roles LIKE :role'
        )->setParameters( array('role' => '%ROLE_SUPER_ADMIN%') );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            $user_id = $result['user_id'];

            $affected_users[$user_id] = 1;
        }

        // Delete all of the cached entries for the affected users
        foreach ($affected_users as $user_id => $num)
            $this->cache_service->delete('user_'.$user_id.'_permissions');


        $this->logger->debug('CloneTemplateService: all relevant cache entries have been deleted');
        $this->logger->info('----------------------------------------');


        // TODO - this checker construct needs a database entry, but i'm pretty sure this isn't the intended use
        $datatype->setDatatypeType(null);
        $this->em->persist($datatype);
        $this->em->flush();

        // Can release the lock on the template cloning now
        $lockHandler->release();

        return true;
    }


    /**
     * Recursively locates the ids of all the template datatypes/datafields/radio options that need
     * to be hydrated, and temporarily stores all of those ids in $this->template_datatypes,
     * $this->template_datafields, and $this->template_radio_options.
     *
     * @param array $diff
     */
    private function locateEntriesToHydrate($diff)
    {
        foreach ($diff as $dt_id => $dt) {
            // This datatype either has properties that changed, or needs to be created in the
            //  derived datatype
            $this->template_datatypes[$dt_id] = 1;
            // Might as well hydrate the derived datatype too
            $this->derived_datatypes[$dt_id] = 1;

            if ( isset($dt['dataFields']) ) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    // This datafield also is either new or had properties that changed
                    $this->template_datafields[$df_id] = 1;
                    // Might as well hydrate the derived datafield too
                    $this->derived_datafields[$df_id] = 1;

                    if ( isset($df['radioOptions']) ) {
                        if ( isset($df['radioOptions']['created']) ) {
                            // Datafield exists in both derived and template datatypes...

                            // Radio options to be created will be cloned from template entity
                            foreach ($df['radioOptions']['created'] as $ro_uuid => $option_name)
                                $this->template_radio_options[$ro_uuid] = 1;
                            // Radio options to be renamed require the derived entity...the name is in the diff
                            foreach ($df['radioOptions']['updated'] as $ro_uuid => $option_name)
                                $this->derived_radio_options[$ro_uuid] = 1;
                            // Radio options to be deleted require the derived entity
                            foreach ($df['radioOptions']['deleted'] as $ro_uuid => $option_name)
                                $this->derived_radio_options[$ro_uuid] = 1;
                        }
                        else {
                            // Datafield only exists in template datatype...all listed radio options
                            //  are going to be cloned from template entity
                            foreach ($df['radioOptions'] as $ro_uuid => $ro_name)
                                $this->template_radio_options[$ro_uuid] = 1;
                        }
                    }

                    if ( isset($df['tags']) ) {
                        if ( isset($df['tags']['created']) ) {
                            // Datafield exists in both derived and template datatypes...

                            // Tags to be created will be cloned from template entity
                            foreach ($df['tags']['created'] as $tag_uuid => $tag_data)
                                $this->template_tags[$tag_uuid] = 1;
                            // Tags to be renamed require the derived entity...the name is in the diff
                            foreach ($df['tags']['updated'] as $tag_uuid => $tag_data)
                                $this->derived_tags[$tag_uuid] = 1;
                            // Tags to be deleted require the derived entity
                            foreach ($df['tags']['deleted'] as $tag_uuid => $tag_data)
                                $this->derived_tags[$tag_uuid] = 1;
                        }
                        else if ( isset($df['tags']) ) {
                            // Datafield only exists in template datatype...all listed tags are
                            //  going to be cloend from template entity
                            foreach ($df['tags'] as $tag_uuid => $tag_data)
                                $this->template_tags[$tag_uuid] = 1;
                        }
                    }

                    if ( isset($df['tagTree']) ) {
                        if ( isset($df['tagTree']['created']) ) {
                            // All tag tree entries being created require both parent/child tags
                            //  to be hydrated
                            foreach ($df['tagTree']['created'] as $parent_tag_id => $child_tags) {
                                $this->derived_tags[$parent_tag_id] = 1;

                                foreach ($child_tags as $child_tag_id => $str)
                                    $this->derived_tags[$child_tag_id] = 1;
                            }

                            // Need the tag ids for the tag tree entries getting deleted, but don't
                            //  have access to them at this point...get them hydrated for later
                            foreach ($df['tagTree']['deleted'] as $parent_tag_id => $child_tags) {
                                $this->derived_tags[$parent_tag_id] = 1;

                                foreach ($child_tags as $child_tag_id => $str) {
                                    $this->derived_tags[$child_tag_id] = 1;

                                    $key = $parent_tag_id.'|'.$child_tag_id;
                                    $this->derived_tag_trees[$key] = 1;
                                }
                            }
                        }
                        else {
                            // Datafield only exists in template datatype...all tag tree entries in
                            //  here are being created, so both parent/child tags need to be hydrated
                            foreach ($df['tagTree'] as $parent_tag_id => $child_tags) {
                                $this->derived_tags[$parent_tag_id] = 1;

                                foreach ($child_tags as $child_tag_id => $str)
                                    $this->derived_tags[$child_tag_id] = 1;
                            }
                        }
                    }
                }
            }

            if ( isset($dt['descendants']) ) {
                foreach ($dt['descendants'] as $c_dt_id => $c_dt) {
                    // Recursively do the same thing for any child/linked datatypes of this datatype
                    self::locateEntriesToHydrate( $c_dt['datatype'] );
                }
            }
        }
    }


    /**
     * Hydrates a collection of datatypes identified by their id, so the synchronization process
     * can clone them (from a template), or make changes to them (in a derived datatype).
     *
     * @param int[] $grandparent_ids
     * @param int[] $query_datatypes
     * @param string $type 'template' or 'derived'
     *
     * @return array
     */
    private function hydrateDatatypes($grandparent_ids, $query_datatypes, $type)
    {
        $hydrated_datatypes = array();

        $query_str =
           'SELECT dt
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.grandparent IN (:grandparent_ids) AND dt.id IN (:datatype_ids)
            AND dt.deletedAt IS NULL';

        if ($type == 'derived') {
            // Ignore the ones that don't have a masterDatatype, they'll never be updated with this
            $query_str =
               'SELECT dt
                FROM ODRAdminBundle:DataType AS dt
                WHERE dt.grandparent IN (:grandparent_ids) AND dt.masterDataType IN (:datatype_ids)
                AND dt.deletedAt IS NULL';
        }

        $query = $this->em->createQuery($query_str)->setParameters(
            array(
                'grandparent_ids' => $grandparent_ids,
                'datatype_ids' => $query_datatypes,
            )
        );
        $results = $query->getResult();

        /** @var DataType $dt */
        $logging_contents = array();
        foreach ($results as $dt) {
            $hydrated_datatypes[$dt->getId()] = $dt;
            $logging_contents[$dt->getId()] = $dt->getShortName();
        }
        $this->logger->debug('CloneTemplateService: -- modified '.$type.'_datatypes: '.print_r($logging_contents, true));

        return $hydrated_datatypes;
    }


    /**
     * Hydrates a collection of datafields identified by their id, so the synchronization process
     * can clone them (from a template), or make changes to them (in a derived datatype).
     *
     * @param int[] $grandparent_ids
     * @param int[] $query_datafields
     * @param string $type 'template' or 'derived'
     *
     * @return array
     */
    private function hydrateDatafields($grandparent_ids, $query_datafields, $type)
    {
        $hydrated_datafields = array();

        $query_str =
           'SELECT df
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
            WHERE dt.grandparent IN (:grandparent_ids) AND df.id IN (:datafield_ids)
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL';

        if ($type == 'derived') {
            $query_str =
               'SELECT df
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                WHERE dt.grandparent IN (:grandparent_ids) AND df.masterDataField IN (:datafield_ids)
                AND dt.deletedAt IS NULL AND df.deletedAt IS NULL';
        }

        $query = $this->em->createQuery($query_str)->setParameters(
            array(
                'grandparent_ids' => $grandparent_ids,
                'datafield_ids' => $query_datafields,
            )
        );
        $results = $query->getResult();

        /** @var DataFields $df */
        $logging_contents = array();
        foreach ($results as $df) {
            $hydrated_datafields[$df->getId()] = $df;
            $logging_contents[$df->getId()] = $df->getFieldName();
        }
        $this->logger->debug('CloneTemplateService: -- modified '.$type.'_datafields: '.print_r($logging_contents, true));

        return $hydrated_datafields;
    }


    /**
     * Hydrates a collection of radio options identified by their uuid, so the synchronization
     * process can clone them (from a template), or make changes to them (in a derived datatype).
     *
     * @param int[] $grandparent_ids
     * @param string[] $query_radio_options
     * @param string $type 'template' or 'derived'
     *
     * @return array
     */
    private function hydrateRadioOptions($grandparent_ids, $query_radio_options, $type)
    {
        $hydrated_radio_options = array();

        $query_str =
           'SELECT ro
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
            JOIN ODRAdminBundle:RadioOptions AS ro WITH ro.dataField = df
            WHERE dt.grandparent IN (:grandparent_ids) AND ro.radioOptionUuid IN (:ro_uuids)
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND ro.deletedAt IS NULL';

        if ($type == 'derived') {
            $query_str =
               'SELECT ro
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                JOIN ODRAdminBundle:RadioOptions AS ro WITH ro.dataField = df
                WHERE dt.grandparent IN (:grandparent_ids) AND ro.radioOptionUuid IN (:ro_uuids)
                AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND ro.deletedAt IS NULL';
        }

        $query = $this->em->createQuery($query_str)->setParameters(
            array(
                'grandparent_ids' => $grandparent_ids,
                'ro_uuids' => $query_radio_options,
            )
        );
        $results = $query->getResult();

        /** @var RadioOptions $ro */
        $logging_contents = array();
        foreach ($results as $ro) {
            $hydrated_radio_options[$ro->getRadioOptionUuid()] = $ro;
            $logging_contents[$ro->getRadioOptionUuid()] = $ro->getOptionName();
        }
        $this->logger->debug('CloneTemplateService: -- modified '.$type.'_radio_options: '.print_r($logging_contents, true));

        return $hydrated_radio_options;
    }


    /**
     * Hydrates a collection of tags identified by their uuid, so the synchronization process can
     * clone them (from a template), or make changes to them (in a derived datatype).
     *
     * @param int[] $grandparent_ids
     * @param string[] $query_tags
     * @param string $type 'template' or 'derived'
     *
     * @return array
     */
    private function hydrateTags($grandparent_ids, $query_tags, $type)
    {
        $hydrated_tags = array();

        $query_str =
           'SELECT t
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
            JOIN ODRAdminBundle:Tags AS t WITH t.dataField = df
            WHERE dt.grandparent IN (:grandparent_ids) AND t.tagUuid IN (:tag_uuids)
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND t.deletedAt IS NULL';

        if ($type == 'derived') {
            $query_str =
               'SELECT t
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                JOIN ODRAdminBundle:Tags AS t WITH t.dataField = df
                WHERE dt.grandparent IN (:grandparent_ids) AND t.tagUuid IN (:tag_uuids)
                AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND t.deletedAt IS NULL';
        }

        $query = $this->em->createQuery($query_str)->setParameters(
            array(
                'grandparent_ids' => $grandparent_ids,
                'tag_uuids' => $query_tags,
            )
        );
        $results = $query->getResult();

        /** @var Tags $t */
        $logging_contents = array();
        foreach ($results as $t) {
            $hydrated_tags[$t->getTagUuid()] = $t;
            $logging_contents[$t->getTagUuid()] = $t->getTagName();
        }
        $this->logger->debug('CloneTemplateService: -- modified '.$type.'_tags: '.print_r($logging_contents, true));

        return $hydrated_tags;
    }


    /**
     * Hydrates tag tree entries in the derived datafield that are marked for deletion
     *
     * @param array $grandparent_ids
     * @param array $tag_trees
     *
     * @return array
     */
    private function hydrateTagTrees($grandparent_ids, $tag_trees)
    {
        $hydrated_tag_trees = array();

        $pieces = array();
        $params = array('grandparent_ids' => $grandparent_ids);

        $count = 0;
        foreach ($tag_trees as $key => $num) {
            $tag_uuids = explode('|', $key);
            $pieces[] = '(parent.tagUuid = :parent_uuid_'.$count.' AND child.tagUuid = :child_uuid_'.$count.')';

            $params['parent_uuid_'.$count] = $tag_uuids[0];
            $params['child_uuid_'.$count] = $tag_uuids[1];

            $count++;
        }

        // If no tag tree entries were listed in the diff, then don't attempt to hydrate anything
        if  ( !empty($pieces) ) {
            $pieces = implode(' OR ', $pieces);

            $query = $this->em->createQuery(
               'SELECT tt
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                JOIN ODRAdminBundle:Tags AS parent WITH parent.dataField = df
                JOIN ODRAdminBundle:TagTree AS tt WITH tt.parent = parent
                JOIN ODRAdminBundle:Tags AS child WITH tt.child = child
                WHERE dt.grandparent IN (:grandparent_ids) AND
                '.$pieces.'
                AND parent.deletedAt IS NULL AND tt.deletedAt IS NULL AND child.deletedAt IS NULL'
            )->setParameters($params);
            $results = $query->getResult();

            /** @var TagTree $tt */
            foreach ($results as $tt) {
                $parent_tag_uuid = $tt->getParent()->getTagUuid();
                $child_tag_uuid = $tt->getChild()->getTagUuid();

                $key = $parent_tag_uuid.'|'.$child_tag_uuid;
                $hydrated_tag_trees[$key] = $tt;
            }
        }

        return $hydrated_tag_trees;
    }


    /**
     * Recursively ensures that $derived_datatype is up to date with its master datatype.
     *
     * @param DataType $derived_datatype
     * @param ODRUser $user
     * @param array $diff_array
     * @param int $indent
     */
    private function syncDatatype($derived_datatype, $user, $diff_array, $indent)
    {
        // Debugging assistance on recursive functions...
        $indent_text = '';
        for ($i = 0; $i < $indent; $i++)
            $indent_text .= ' --';

        $this->logger->debug('CloneTemplateService:'.$indent_text.' synchronizing datatype '.$derived_datatype->getId().' "'.$derived_datatype->getShortName().'" with its master datatype '.$derived_datatype->getMasterDataType()->getId().' "'.$derived_datatype->getMasterDataType()->getShortName().'"...');

        $master_theme = $this->ti_service->getDatatypeMasterTheme($derived_datatype->getId());
        $template_master_theme = $this->ti_service->getDatatypeMasterTheme($derived_datatype->getMasterDataType()->getId());

        // Going to increment this number a few times to "fake" updates, mostly so CloneThemeService
        //  continues to work as expected
        $source_sync_version = $master_theme->getSourceSyncVersion();
        if ( is_null($source_sync_version) )
            $source_sync_version = 0;


        // ----------------------------------------
        // Update datatype properties if needed
        if ( isset($diff_array['dataTypeMeta']) ) {
            $props = array();
            foreach ($diff_array['dataTypeMeta'] as $key => $value) {
                $props[$key] = $value;
                $this->logger->debug('CloneTemplateService:'.$indent_text.' -- setting "'.$key.'" to "'.$value.'"...');
            }

            $this->emm_service->updateDatatypeMeta($user, $derived_datatype, $props, true);
        }


        // ----------------------------------------
        // Create/modify all datafields necessary
        $local_created_datafields = array();
        if ( isset($diff_array['dataFields']) ) {
            foreach ($diff_array['dataFields'] as $df_id => $df) {
                // Locate an existing datafield in the derived datatype that has $master_df as its
                //  masterDatafield, if possible
                $derived_df = null;
                foreach ($this->derived_datafields as $d_df) {
                    if ( $d_df->getMasterDataField()->getId() === $df_id ) {
                        $derived_df = $d_df;
                        break;
                    }
                }

                if ( !isset($this->template_datafields[$df_id]) ) {
                    // This datafield got deleted out of the template datatype

                    // TODO - revisit, disabled again because deleting a derived field will likely cause data loss
//                    self::deleteDatafield($derived_df, $user);
//
//                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- deleted datafield '.$df_id.' "'.$derived_df->getFieldName().'" (derived_dt: '.$derived_datatype->getId().')');

                    // Skip the rest of this loop and move on to the next datafield
                    continue;
                }

                $master_df = $this->template_datafields[$df_id];
                $master_dt = $master_df->getDataType();

                if ( is_null($derived_df) ) {
                    // If the derived datafield does not exist, clone it from the master template
                    $new_df = clone $master_df;
                    $new_df->setMasterDataField($master_df);
                    $new_df->setTemplateFieldUuid($master_df->getFieldUuid());
                    $new_df->setFieldUuid($this->uuid_service->generateDatafieldUniqueId());
                    $new_df->setIsMasterField(false);
                    $new_df->setDataType($derived_datatype);

                    // Don't flush this immediately...
                    $derived_datatype->addDataField($new_df);
                    self::persistObject($new_df, $user, true);

                    $new_df_meta = clone $master_df->getDataFieldMeta();
                    $new_df_meta->setDataField($new_df);

                    // Don't flush this immediately...
                    $new_df->addDataFieldMetum($new_df_meta);
                    self::persistObject($new_df_meta, $user, true);

                    // Need to keep track of whether a datafield was created for this datatype...
                    $local_created_datafields[ $master_df->getId() ] = $new_df;
                    // ...and do it separately from the list of all created datafields, which is
                    //  needed when cloning render plugins for a newly created datatype...
                    $this->created_datafields[ $master_df->getId() ] = $new_df;

                    $derived_df = $new_df;

                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- cloned new datafield "'.$new_df->getFieldName().'" (dt '.$derived_datatype->getId().') from master datafield '.$master_df->getId().' (dt_id '.$master_dt->getId().')');

                    // If the new datafield is an Image field, ensure it has ImageSize entries...
                    if ( $new_df->getFieldType()->getTypeName() === 'Image' ) {
                        /** @var ImageSizes[] $image_sizes */
                        $image_sizes = $master_df->getImageSizes();
                        foreach ($image_sizes as $image_size) {
                            // ...by cloning each of the master datafield's image size entities
                            $new_image_size = clone $image_size;
                            $new_image_size->setDataField($new_df);

                            // Don't flush immediately...
                            self::persistObject($new_image_size, $user, true);

                            // NOTE - can't use EntityCreationService::createImageSizes() because
                            //  that function will load $master_df's ImageSize entities instead of
                            //  realizing that $new_df doesn't have any...has to do with doctrine
                            //  still thinking $new_df->getId() === $master_df->getId() prior to the
                            //  first flush
                        }

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- >> created ImageSize entries for new datafield "'.$new_df->getFieldName().'" (dt '.$derived_datatype->getId().')' );
                    }

                    // Clone all render plugins for the newly created datafield
                    self::cloneRenderPlugins($indent_text, $user, null, null, $new_df);
                }
                else if ( !empty($df['dataFieldMeta']) ) {
                    // If the derived datafield does exist, then ensure that several relevant
                    //  properties remain in sync with its master datafield
                    $props = array();
                    foreach ($df['dataFieldMeta'] as $key => $value) {
                        $props[$key] = $value;
                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- setting "'.$key.'" to "'.$value.'"...');
                    }

                    $this->emm_service->updateDatafieldMeta($user, $derived_df, $props, true);
                }

                $derived_df_typeclass = $derived_df->getFieldType()->getTypeClass();
                $master_df_typeclass = $master_df->getFieldType()->getTypeClass();
                if ( $derived_df_typeclass !== $master_df_typeclass ) {
                    // TODO - how to deal with change to the fieldtype of master datafield?
                    // TODO - refactor datafield migration so that it's not as slow?
                    // TODO - ...make datafield migration into a service?
                    // TODO - give the user the choice of which stuff to synchronize? (instead of "all at once")
                    // TODO - how to deal with deletion of datafields from the template?
//                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- datafield "'.$derived_df->getFieldName().'" (dt '.$derived_datatype->getId().') has the fieldtype "'.$derived_df_typeclass.'", while the master datafield '.$master_df->getId().' (dt_id '.$master_dt->getId().') has the fieldtype "'.$master_df_typeclass.'"');
                }

                // TODO - change of datafield public status

                // Create/rename/delete radio options as needed so the derived datatype is in sync
                //  with its template
                if ( isset($df['radioOptions']) ) {
                    if ( !isset($df['radioOptions']['created']) ) {
                        // If this array entry doesn't exist, then this is a new datafield...all
                        //  radio options are therefore meant to be created
                        $ro_list = $df['radioOptions'];
                        $df['radioOptions'] = array(
                            'created' => $ro_list,
                            'updated' => array(),
                            'deleted' => array(),
                        );
                    }

                    foreach ($df['radioOptions']['created'] as $ro_uuid => $option_name) {
                        // The derived datatype doesn't have this radio option...clone it
                        $master_ro = $this->template_radio_options[$ro_uuid];
                        $new_ro = clone $master_ro;
                        $new_ro->setDataField($derived_df);

                        // Don't flush this immediately...
                        $derived_df->addRadioOption($new_ro);
                        self::persistObject($new_ro, $user, true);

                        $new_ro_meta = clone $master_ro->getRadioOptionMeta();
                        $new_ro_meta->setRadioOption($new_ro);

                        // Don't flush this immediately
                        $new_ro->addRadioOptionMetum($new_ro_meta);
                        self::persistObject($new_ro_meta, $user, true);

                        // If the radio option is marked as default, then need to clear a relevant
                        //  cached entry
                        if ( $new_ro->getIsDefault() )
                            $this->cache_service->delete('default_radio_options');

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- cloned new radio option "'.$new_ro->getOptionName().'" (derived_df: '.$derived_df->getId().') (derived_dt: '.$derived_datatype->getId().') from master radio option '.$master_ro->getId().' (master_df: '.$master_df->getId().') (master_dt: '.$master_dt->getId().')');
                    }

                    foreach ($df['radioOptions']['updated'] as $ro_uuid => $option_name) {
                        // The derived datatype has this radio option, and it needs renaming
                        $derived_ro = $this->derived_radio_options[$ro_uuid];
                        $properties['optionName'] = $option_name;

                        $this->emm_service->updateRadioOptionsMeta($user, $derived_ro, $properties, true);    // Don't want to immediately flush these changes

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- renamed radio option '.$ro_uuid.' to "'.$option_name.'" (derived_df: '.$derived_df->getId().') (derived_dt: '.$derived_datatype->getId().')');
                    }

                    foreach ($df['radioOptions']['deleted'] as $ro_uuid => $option_name) {
                        // The derived datatype has this radio option, but it needs to be deleted
                        $derived_ro = $this->derived_radio_options[$ro_uuid];

                        $derived_ro_meta = $derived_ro->getRadioOptionMeta();
                        $derived_ro_meta->setDeletedAt(new \DateTime());
                        $this->em->persist($derived_ro_meta);

                        $derived_ro->setDeletedBy($user);
                        $derived_ro->setDeletedAt(new \DateTime());
                        $this->em->persist($derived_ro);

                        // Delete all radio selections for this radio option
                        $query = $this->em->createQuery(
                           'UPDATE ODRAdminBundle:RadioSelection AS rs
                            SET rs.deletedAt = :now
                            WHERE rs.radioOption = :radio_option_id AND rs.deletedAt IS NULL'
                        )->setParameters(
                            array(
                                'now' => new \DateTime(),
                                'radio_option_id' => $derived_ro->getId(),
                            )
                        );
                        $rows = $query->execute();

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- deleted radio option '.$ro_uuid.' "'.$option_name.'" (derived_df: '.$derived_df->getId().') (derived_dt: '.$derived_datatype->getId().')');
                    }
                }

                // Create any missing tags, update the names of existing tags, or delete tags that
                //  no longer exist
                if ( isset($df['tags']) ) {
                    if ( !isset($df['tags']['created']) ) {
                        // If this array entry doesn't exist, then this is a new datafield...all
                        //  tags are therefore meant to be created
                        $tag_list = $df['tags'];
                        $df['tags'] = array(
                            'created' => $tag_list,
                            'updated' => array(),
                            'deleted' => array(),
                        );
                    }

                    foreach ($df['tags']['created'] as $tag_uuid => $tag_data) {
                        // The derived datatype doesn't have this tag...clone it
                        $master_tag = $this->template_tags[$tag_uuid];
                        $new_tag = clone $master_tag;
                        $new_tag->setDataField($derived_df);

                        // Don't flush this immediately...
                        $derived_df->addTag($new_tag);
                        self::persistObject($new_tag, $user, true);

                        $new_tag_meta = clone $master_tag->getTagMeta();
                        $new_tag_meta->setTag($new_tag);

                        // Don't flush this immediately
                        $new_tag->addTagMetum($new_tag_meta);
                        self::persistObject($new_tag_meta, $user, true);

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- cloned new tag "'.$new_tag->getTagName().'" (derived_df: '.$derived_df->getId().') (derived_dt: '.$derived_datatype->getId().') from master tag '.$master_tag->getId().' (master_df: '.$master_df->getId().') (master_dt: '.$master_dt->getId().')');

                        // Store the newly created tag incase it needs a tag tree entry
                        $this->derived_tags[$tag_uuid] = $new_tag;
                    }

                    foreach ($df['tags']['updated'] as $tag_uuid => $tag_data) {
                        // The derived datatype has this tag, and it needs renaming
                        $derived_tag = $this->derived_tags[$tag_uuid];

                        $properties = array(
                            'tagName' => $tag_data['tagName'],
                            'displayOrder' => $tag_data['displayOrder'],
                        );
                        $this->emm_service->updateTagMeta($user, $derived_tag, $properties, true);    // Don't want to immediately flush these changes

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- updated tag '.$tag_uuid.' to "'.$tag_data['tagName'].'", displayOrder '.$tag_data['displayOrder'].' (derived_df: '.$derived_df->getId().') (derived_dt: '.$derived_datatype->getId().')');
                    }

                    foreach ($df['tags']['deleted'] as $tag_uuid => $tag_data) {
                        // The derived datatype has this tag, but it needs to be deleted
                        $derived_tag = $this->derived_tags[$tag_uuid];

                        // Don't need to worry about locating the children of this derived tag...
                        //  ...they will already be in this array of deleted tags

                        $derived_tag_meta = $derived_tag->getTagMeta();
                        $derived_tag_meta->setDeletedAt(new \DateTime());
                        $this->em->persist($derived_tag_meta);

                        $derived_tag->setDeletedBy($user);
                        $derived_tag->setDeletedAt(new \DateTime());
                        $this->em->persist($derived_tag);

                        // Delete all tag selections for this tag
                        $query = $this->em->createQuery(
                           'UPDATE ODRAdminBundle:TagSelection AS ts
                            SET ts.deletedAt = :now
                            WHERE ts.tag = :tag_id AND ts.deletedAt IS NULL'
                        )->setParameters(
                            array(
                                'now' => new \DateTime(),
                                'tag_id' => $derived_tag->getId(),
                            )
                        );
                        $rows = $query->execute();

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- deleted tag '.$tag_uuid.' "'.$tag_data['tagName'].'" (derived_df: '.$derived_df->getId().') (derived_dt: '.$derived_datatype->getId().')');
                    }
                }

                // Create or delete tag tree entries so the derived datatype matches the template
                //  datatype
                if ( isset($df['tagTree']) ) {
                    if ( !isset($df['tagTree']['created']) ) {
                        // If this array entry doesn't exist, then this is a new datafield...all
                        //  tag tree entries are therefore meant to be created
                        $tag_tree_list = $df['tagTree'];
                        $df['tagTree'] = array(
                            'created' => $tag_tree_list,
                            'deleted' => array(),
                        );
                    }

                    foreach ($df['tagTree']['created'] as $parent_tag_uuid => $child_tags) {
                        // The derived datatype needs a tag tree entry
                        foreach ($child_tags as $child_tag_uuid => $str) {
                            // Even if these parent/child tags didn't exist prior to the sync request,
                            //  they will now
                            $parent_tag = $this->derived_tags[$parent_tag_uuid];
                            $child_tag = $this->derived_tags[$child_tag_uuid];

                            $tag_tree = new TagTree();
                            $tag_tree->setParent($parent_tag);
                            $tag_tree->setChild($child_tag);

                            self::persistObject($tag_tree, $user, true);

                            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created tag tree between parent tag '.$parent_tag_uuid.' "'.$parent_tag->getTagName().'" and child tag '.$child_tag_uuid.' "'.$child_tag->getTagName().'"');
                        }
                    }

                    foreach ($df['tagTree']['deleted'] as $parent_tag_uuid => $child_tags) {
                        foreach ($child_tags as $child_tag_uuid => $str) {
                            $key = $parent_tag_uuid.'|'.$child_tag_uuid;
                            $tag_tree = $this->derived_tag_trees[$key];

                            $tag_tree->setDeletedAt(new \DateTime());
                            $tag_tree->setDeletedBy($user);

                            $this->em->persist($tag_tree);

                            $parent_tag = $this->derived_tags[$parent_tag_uuid];
                            $child_tag = $this->derived_tags[$child_tag_uuid];
                            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- deleted tag tree between parent tag '.$parent_tag_uuid.' "'.$parent_tag->getTagName().'" and child tag '.$child_tag_uuid.' "'.$child_tag->getTagName().'"');
                        }
                    }
                }
            }


            // If datafields got created, then they need to be attached to the datatype's theme...
            if ( count($local_created_datafields) > 0 ) {

                if ($diff_array['copy_theme_structure'] == 1) {
                    // If this flag is set, the derived datatype doesn't have any of the datafields
                    //  in the template datatype...therefore, it's possible to clone theme element
                    //  and themeDatafield settings straight from the template

                    // Using the hydrated version of the template datatype's theme instead of the
                    //  array version since it's easier to clone stuff that way...
                    foreach ($template_master_theme->getThemeElements() as $te) {
                        /** @var ThemeElement $te */
                        $tdf_list = $te->getThemeDataFields();

                        if ( count($tdf_list) > 0 ) {
                            // Create a new theme element to store the themeDatafield entries
                            // Do NOT clone the relevant source themeElement, as that seems to carry
                            //  over that source themeElement's themeDatafield list
                            $new_te = new ThemeElement();
                            $new_te->setTheme($master_theme);

                            $master_theme->addThemeElement($new_te);
                            self::persistObject($new_te, $user, true);

                            $new_te_meta = clone $te->getThemeElementMeta();
                            $new_te_meta->setThemeElement($new_te);

                            $new_te->addThemeElementMetum($new_te_meta);
                            self::persistObject($new_te_meta, $user, true);

                            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- cloned theme_element for derived datatype '.$derived_datatype->getId().' "'.$derived_datatype->getShortName().'"');

                            // Now clone each datafield in the theme element...
                            $derived_df = null;
                            foreach ($tdf_list as $num => $tdf) {
                                /** @var ThemeDataField $tdf */
                                // Locate the new datafield
                                $master_df_id = $tdf->getDataField()->getId();
                                $this->logger->debug('CloneTemplateService:'.$indent_text.' ** ** attempting to locate field derived from datafield '.$master_df_id.' "'.$tdf->getDataField()->getFieldName().'"');
                                $derived_df = $this->created_datafields[$master_df_id];

                                if($derived_df !== null) {
                                    // Clone the existing theme datafield entry
                                    $new_tdf = clone $tdf;
                                    $new_tdf->setThemeElement($new_te);
                                    $new_tdf->setDataField($derived_df);
    
                                    $new_te->addThemeDataField($new_tdf);
                                    self::persistObject($new_tdf, $user, true);

                                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- cloned theme_datafield entry for datafield '.$master_df_id.' "'.$derived_df->getFieldName().'"');
                                }
                            }
                        }
                        else {
                            // This is a theme element for a child/linked datatype...ignore for
                            //  now, it'll be cloned in the next segment
                        }
                    }
                }
                else {
                    // There were datafields already in here prior to synchronization...since the
                    //  user may have modified the display_order and/or size of the datafields that
                    //  already existed, we can't attempt to match the template structure, and
                    //  should instead attach the new datafields into a new ThemeElement

                    /** @var ThemeElement $new_te */
                    $new_te = null;

                    /** @var DataFields[] $local_created_datafields */
                    foreach ($local_created_datafields as $master_df_id => $new_df) {
                        //
                        $source_sync_version++;

                        // If a theme_element hasn't been created to store new themeDatafield entries
                        //  for this datatype, then create one now
                        if (is_null($new_te)) {
                            $new_te = $this->ec_service->createThemeElement($user, $master_theme, true);    // don't flush immediately...

                            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created new theme_element for derived datatype '.$derived_datatype->getId().' "'.$derived_datatype->getShortName().'"');
                        }

                        // Create a new ThemeDataField entry for this new datafield...
                        $this->ec_service->createThemeDatafield($user, $new_te, $new_df, true);    // don't flush immediately...
                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created new theme_datafield entry for datafield '.$new_df->getId().' "'.$new_df->getFieldName().'"');
                    }

                    // Flush the new theme_element and the new theme_datafield entries
                    $this->em->flush();
                }
            }
        }

        // Create the permission entries for each of the new datafields...
        foreach ($local_created_datafields as $master_df_id => $new_df) {
            $this->ec_service->createGroupsForDatafield($user, $new_df, true);
            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created GroupDatafieldPermission entries for datafield '.$new_df->getId().' "'.$new_df->getFieldName().'" (master df '.$master_df_id.')');
        }

        // Can flush here now that the newly created datafields and their GroupDatafield entries
        // have been created
        $this->em->flush();


        // ----------------------------------------
        // Create all the datatypes (and their datafields) that don't exist
        if ( isset($diff_array['descendants']) ) {

            $copy_theme_structure = false;
            if ( $diff_array['copy_theme_structure'] == 1 )
                $copy_theme_structure = true;

            foreach ($diff_array['descendants'] as $c_dt_id => $c_dt) {
                // Locate this derived datatype's master template
                $master_datatype = $this->template_datatypes[$c_dt_id];

                $is_link = $c_dt['is_link'];

                $multiple_allowed = true;
                if ( $c_dt['multiple_allowed'] === 0 )
                    $multiple_allowed = false;


                // Locate the child/linked datatype in the derived datatype's "family", if possible
                $datatype_was_created = false;
                $derived_child_datatype = null;
                foreach ($this->derived_datatypes as $dt) {
                    if ( $dt->getMasterDataType()->getId() === $c_dt_id ) {
                        $derived_child_datatype = $dt;
                        break;
                    }
                }

                if ( is_null($derived_child_datatype) ) {
                    // The derived datatype does not have the required child/linked datatype
                    $source_sync_version++;

                    // Nonexistant child and linked datatypes will need a new theme element...
                    $new_te = $this->ec_service->createThemeElement($user, $master_theme, true);    // don't flush immediately...

                    $new_te_meta = $new_te->getThemeElementMeta();
                    $new_te_meta->setDisplayOrder(999);    // send new theme elements to the back

                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created new theme_element for derived datatype '.$derived_datatype->getId());

                    if ($copy_theme_structure) {
                        // In order to copy the theme structure, the "original" theme element needs
                        //  to be located and a couple properties copied from its meta entry...
                        $query = $this->em->createQuery(
                           'SELECT tem
                            FROM ODRAdminBundle:Theme t
                            JOIN ODRAdminBundle:ThemeElement te WITH te.theme = t
                            JOIN ODRAdminBundle:ThemeElementMeta tem WITH tem.themeElement = te
                            JOIN ODRAdminBundle:ThemeDataType tdt WITH tdt.themeElement = te
                            WHERE t = :template_theme AND tdt.dataType = :template_datatype
                            AND t.deletedAt IS NULL AND te.deletedAt IS NULL
                            AND tem.deletedAt IS NULL AND tdt.deletedAt IS NULL'
                        )->setParameters(
                            array(
                                'template_theme' => $template_master_theme->getId(),
                                'template_datatype' => $master_datatype->getId(),
                            )
                        );
                        /** @var ThemeElementMeta $template_te_meta */
                        $sub_result = $query->getResult();
                        $template_te_meta = $sub_result[0];

                        $new_te_meta->setDisplayOrder( $template_te_meta->getDisplayOrder() );
                        $new_te_meta->setCssWidthMed( $template_te_meta->getCssWidthMed() );
                        $new_te_meta->setCssWidthXL( $template_te_meta->getCssWidthXL() );
                        $this->em->persist($new_te_meta);    // don't flush immediately

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- cloned dimensions of original theme_element');
                    }

                    if ( $is_link === 0 ) {
                        // ...then need to create a child datatype in that theme element
                        $derived_child_datatype = self::createChildDatatype($user, $new_te, $derived_datatype, $master_datatype, $multiple_allowed, $indent_text);
                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created new child datatype, derived from master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"...');
                    }
                    else {
                        // ...then need to create a linked datatype in that theme element
                        $derived_child_datatype = self::createLinkedDatatype($user, $new_te, $derived_datatype, $master_datatype, $multiple_allowed, $indent_text);
                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created new linked datatype, derived from master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"...');

                        // May need to reference this later if the new datatype is linked to more
                        //  than once
                        $this->derived_datatypes[ $derived_child_datatype->getId() ] = $derived_child_datatype;
                    }

                    // Make a note that a child datatype was created here...can't set render plugin
                    //  or external_id/name/sort/etc fields until after self::syncDatatype() is run
                    $datatype_was_created = true;
                }

                // Now that the child/linked datatype is guaranteed to exist, need to continue going
                //  through the diff array to ensure the child/linked datatype is up to date

                if ( $is_link === 0 ) {
                    // This is a child datatype...ensure it's up to date with its master template
                    $child_diff_array = $c_dt['datatype'][$derived_child_datatype->getMasterDataType()->getId()];
                    self::syncDatatype($derived_child_datatype, $user, $child_diff_array, $indent + 2);

                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- child datatype '.$derived_child_datatype->getId().' "'.$derived_child_datatype->getShortName().'" is up to date with its master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"');
                }
                else {
                    // This is a linked datatype...if a "primary" version was just created by
                    //  self::createLinkedDatatype(), then it already has the required theme,
                    //  theme element, and themeDatatype entries...self::linkToExistingDatatype()
                    //  should not be run.
                    $query = $this->em->createQuery(
                       'SELECT tdt
                        FROM ODRAdminBundle:Theme AS t
                        JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                        JOIN ODRAdminBundle:ThemeDataType AS tdt WITH tdt.themeElement = te
                        WHERE t.id = :master_theme_id AND tdt.dataType = :linked_datatype_id
                        AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdt.deletedAt IS NULL'
                    )->setParameters(
                        array(
                            'master_theme_id' => $master_theme->getId(),
                            'linked_datatype_id' => $derived_child_datatype->getId()
                        )
                    );
                    $results = $query->getArrayResult();

                    if ( count($results) === 0 ) {
                        // If a themeDatatype entry doesn't exist, then the derived datatype links
                        //  to this remote datatype more than once...another theme, theme element,
                        //  and themeDatatype entity is required for this additional instance
                        self::linkToExistingDatatype($user, $derived_datatype, $derived_child_datatype, $multiple_allowed, $indent_text);

                        $source_sync_version++;
                    }

                    // Only want to synchronize the "primary" version of the linked datatype once...
                    if ( !isset($this->modified_linked_datatypes[$derived_child_datatype->getId()]) ) {
                        // Don't run this block of code a second time
                        $this->modified_linked_datatypes[$derived_child_datatype->getId()] = $derived_child_datatype;

                        // Ensure the linked datatype is up to date with its master template
                        $child_diff_array = $c_dt['datatype'][$derived_child_datatype->getMasterDataType()->getId()];
                        self::syncDatatype($derived_child_datatype, $user, $child_diff_array, $indent + 2);

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- linked datatype '.$derived_child_datatype->getId().' "'.$derived_child_datatype->getShortName().'" is up to date with its master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"');
                    }
                    else {
                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- linked datatype '.$derived_child_datatype->getId().' "'.$derived_child_datatype->getShortName().'" is already up to date with its master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"');
                    }
                }

                if ( $datatype_was_created ) {
                    // If a datatype was created earlier, then it needs to also check for render
                    //  plugin and the external_id/name/sort/etc fields...this can't be done earlier
                    //  because the datafields don't exist until after self::syncDatatype() gets
                    //  called
                    $derived_child_theme = $this->ti_service->getDatatypeMasterTheme($derived_child_datatype->getId());
                    self::cloneRenderPlugins($indent_text, $user, $derived_child_theme, $derived_child_datatype, null);

                    // Need to set the external_id fields for the new datatype...
                    $child_properties = array();
                    if ( !is_null($master_datatype->getExternalIdField()) ) {
                        $child_df = $this->created_datafields[ $master_datatype->getExternalIdField()->getId() ];
                        $child_properties['externalIdField'] = $child_df;
                    }
                    if ( !is_null($master_datatype->getBackgroundImageField()) ) {
                        $child_df = $this->created_datafields[ $master_datatype->getBackgroundImageField()->getId() ];
                        $child_properties['backgroundImageField'] = $child_df;
                    }

                    $this->emm_service->updateDatatypeMeta($user, $derived_child_datatype, $child_properties, true);

                    // ...and also set the name/sort fields for the new datatype
                    foreach ($this->created_dtsf_entries as $dtsf) {
                        // This is a global array, meaning it triggers for every single datatype that
                        //  got created...need to ensure the loop only attempts to update dtsf
                        //  entries that belong to the chrrent child/linked datatype being modified
                        if ( $derived_child_datatype->getId() === $dtsf->getDataType()->getId() ) {
                            // This entry should have the correct datatype already, but it won't have the
                            //  correct datafield
                            $old_df = $dtsf->getDataField();

                            $this->logger->debug('CloneTemplateService:'.$indent_text.' ** fixing dtsf entries for dt '.$dtsf->getDataType()->getId().' "'.$dtsf->getDataType()->getShortName().'", attempting to locate datafield derived from df '.$old_df->getId().' "'.$old_df->getFieldName().'" ...');

                            // So if we locate its derived counterpart...
                            $derived_df = $this->created_datafields[ $old_df->getId() ];
                            // ...then we can set this entry to use it
                            $dtsf->setDataField($derived_df);

                            // Don't need to flush right this minute, technically
                            $this->em->persist($dtsf);
                        }
                    }
                }
            }
        }

        // Update the source sync version property of the Theme that (might have) had new
        //  datafields and/or datatypes added to it
        if ( $source_sync_version !== $master_theme->getSourceSyncVersion() ) {
            $master_theme_meta = $master_theme->getThemeMeta();
            $master_theme_meta->setSourceSyncVersion($source_sync_version);
            $this->em->persist($master_theme_meta);
        }

        // Do a final flush
        $this->em->flush();
    }


    /**
     * Given a Datatype or Datafield, completely clone all the relevant information for its
     * render plugin, assuming it's currently using one.
     *
     * @param string $indent_text
     * @param ODRUser $user
     * @param Theme|null $derived_theme Will only exist when $derived_datatype exists
     * @param DataType|null $derived_datatype
     * @param DataFields|null $derived_datafield
     */
    private function cloneRenderPlugins($indent_text, $user, $derived_theme, $derived_datatype, $derived_datafield)
    {
        // Need to have either a datatype or a datafield...
        if ( is_null($derived_datatype) && is_null($derived_datafield) )
            throw new ODRException('CloneTemplateService::cloneRenderPlugins() needs either a null datatype or a null datafield, but was called with both being null');
        if ( !is_null($derived_datatype) && !is_null($derived_datafield) )
            throw new ODRException('CloneTemplateService::cloneRenderPlugins() needs either a null datatype or a null datafield, but was called with both being non-null');


        if ( !is_null($derived_datatype) ) {
            $master_datatype = $derived_datatype->getMasterDataType();

            // If the master datatype has a render plugin...
            foreach ($master_datatype->getRenderPluginInstances() as $master_rpi) {
                /** @var RenderPluginInstance $master_rpi */
                $this->logger->debug('CloneTemplateService:'.$indent_text.' -- >> attempting to clone settings for render plugin '.$master_rpi->getRenderPlugin()->getId().' "'.$master_rpi->getRenderPlugin()->getPluginName().'" in use by master datatype '.$master_datatype->getId());

                $plugin_type = $master_rpi->getRenderPlugin()->getPluginType();
                if ( $plugin_type === RenderPlugin::DATATYPE_PLUGIN || $plugin_type === RenderPlugin::THEME_ELEMENT_PLUGIN || $plugin_type === RenderPlugin::ARRAY_PLUGIN ) {
                    // Clone the renderPluginInstance
                    $new_rpi = clone $master_rpi;
                    $new_rpi->setDataType($derived_datatype);
                    $new_rpi->setDataField(null);

                    self::persistObject($new_rpi, $user, true);    // don't flush immediately...
                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- >> cloned render_plugin_instance '.$master_rpi->getId());

                    // Clone the renderPluginFields and renderPluginOptions mappings
                    self::cloneRenderPluginSettings($indent_text, $user, $master_rpi, $new_rpi, $derived_theme, $derived_datatype, null);
                }
                else {
                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- ** skipped render_plugin_instance '.$master_rpi->getId().' because it is a datafield plugin');
                }

            }
        }
        else {
            $master_datafield = $derived_datafield->getMasterDataField();

            // If the master datafield has a render plugin...
            foreach ($master_datafield->getRenderPluginInstances() as $master_rpi) {
                /** @var RenderPluginInstance $master_rpi */
                $this->logger->debug('CloneTemplateService:'.$indent_text.' -- >> attempting to clone settings for render plugin '.$master_rpi->getRenderPlugin()->getId().' "'.$master_rpi->getRenderPlugin()->getPluginName().'" in use by master datafield '.$master_datafield->getId());

                $plugin_type = $master_rpi->getRenderPlugin()->getPluginType();
                if ( $plugin_type === RenderPlugin::DATATYPE_PLUGIN || $plugin_type === RenderPlugin::THEME_ELEMENT_PLUGIN || $plugin_type === RenderPlugin::ARRAY_PLUGIN ) {
                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- ** skipped render_plugin_instance '.$master_rpi->getId().' because it is not a datafield plugin');
                }
                else {
                    // Clone the renderPluginInstance
                    $new_rpi = clone $master_rpi;
                    $new_rpi->setDataType(null);
                    $new_rpi->setDataField($derived_datafield);

                    self::persistObject($new_rpi, $user, true);    // don't flush immediately...
                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- >> cloned render_plugin_instance '.$master_rpi->getId());

                    // Clone the renderPluginFields and renderPluginOptions mappings
                    self::cloneRenderPluginSettings($indent_text, $user, $master_rpi, $new_rpi, null, null, $derived_datafield);
                }
            }
        }
    }


    /**
     * Clones the renderPluginOptionsMap and renderPlugin(Field)Map entries from the given master
     * renderPluginInstance into the given derived renderPluginInstance.
     *
     * Also clones any themeRenderPluginInstances required...though due to this service focusing on
     * datatypes/datafield content while mostly ignoring individual themeElements, cloning these
     * themeRenderPluginInstance entries can't be guaranteed to 100% match up with the master template.
     *
     * @param string $indent_text
     * @param ODRUser $user
     * @param RenderPluginInstance $master_rpi
     * @param RenderPluginInstance $derived_rpi
     * @param Theme|null $derived_theme Will only exist when $derived_datatype exists
     * @param DataType|null $derived_datatype
     * @param DataFields|null $derived_datafield
     */
    private function cloneRenderPluginSettings($indent_text, $user, $master_rpi, $derived_rpi, $derived_theme, $derived_datatype, $derived_datafield)
    {
        // Clone each option mapping defined for this renderPluginInstance
        /** @var RenderPluginOptionsMap[] $parent_rpom_array */
        $parent_rpom_array = $master_rpi->getRenderPluginOptionsMap();
        foreach ($parent_rpom_array as $parent_rpom) {
            $new_rpom = clone $parent_rpom;
            $new_rpom->setRenderPluginInstance($derived_rpi);
            self::persistObject($new_rpom, $user, true);    // don't flush immediately...

            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- >> cloned render_plugin_option_map '.$parent_rpom->getId().' "'.$parent_rpom->getRenderPluginOptionsDef()->getDisplayName().'" => "'.$parent_rpom->getValue().'"');
        }

        // Clone each field mapping defined for this renderPluginInstance
        /** @var RenderPluginMap[] $parent_rpfm_array */
        $parent_rpfm_array = $master_rpi->getRenderPluginMap();
        foreach ($parent_rpfm_array as $parent_rpfm) {
            $new_rpfm = clone $parent_rpfm;
            $new_rpfm->setRenderPluginInstance($derived_rpi);

            if ( !is_null($derived_datatype) )
                $new_rpfm->setDataType($derived_datatype);       // TODO - if null, then a datafield plugin...but why does it work like that in the first place again?

            // Find the analogous datafield in the derived datatype, if it exists
            if ( !is_null($parent_rpfm->getDataField()) ) {

                $this->logger->debug('CloneTemplateService:'.$indent_text.' ** ** ** attempting to locate field derived from df '.$parent_rpfm->getDataField()->getId().' "'.$parent_rpfm->getDataField()->getFieldName().'" in created_datafields...');

                /** @var DataFields $matching_df */
                $matching_df = $this->created_datafields[ $parent_rpfm->getDataField()->getId() ];
                $new_rpfm->setDataField($matching_df);

                self::persistObject($new_rpfm, $user, true);    // These don't need to be flushed/refreshed immediately...
                $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- >> cloned render_plugin_map '.$parent_rpfm->getId().' for render_plugin_field "'.$parent_rpfm->getRenderPluginFields()->getFieldName().', attached to datafield "'.$matching_df->getFieldName().'" of datatype "'.$matching_df->getDataType()->getShortName().'"');
            }
            else {
                $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- >> cloned render_plugin_map '.$parent_rpfm->getId().' for render_plugin_field "'.$parent_rpfm->getRenderPluginFields()->getFieldName().'", but did not update since it is mapped as unused optional rpf');
            }
        }

        // If this is a datatype plugin, then check whether any themeRenderPluginInstance entries
        //  need to be cloned
        if ( !is_null($derived_datatype) && !is_null($derived_theme) ) {
            /** @var ThemeRenderPluginInstance[] $parent_trpi_array */
            $parent_trpi_array = $master_rpi->getThemeRenderPluginInstance();
            foreach ($parent_trpi_array as $master_trpi) {
                // Only want to clone the themeRenderPluginInstances attached to the master theme
                //  of the master datatype...
                $master_te = $master_trpi->getThemeElement();
                $master_t = $master_te->getTheme();
                if ( $master_t->getId() === $master_t->getSourceTheme()->getId()
                    && $master_t->getThemeType() === 'master'
                ) {
                    // Need to clone the relevant themeElement from the master theme...
                    $new_te = clone $master_te;
                    $new_te->setTheme($derived_theme);
                    self::persistObject($new_te, $user, true);    // don't flush immediately...

                    $new_te_meta = clone $master_te->getThemeElementMeta();
                    $new_te_meta->setThemeElement($new_te);
                    self::persistObject($new_te_meta, $user, true);    // don't flush immediately...

                    // ...so a clone of the master themeRenderPluginInstance can be set to use the
                    //  derived renderPluginInstance
                    $new_trpi = clone $master_trpi;
                    $new_trpi->setThemeElement($new_te);
                    $new_trpi->setRenderPluginInstance($derived_rpi);
                    self::persistObject($new_trpi, $user, true);    // don't flush immediately...

                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- >> cloned theme_element '.$master_te->getId().' to hold a themeRenderPluginInstance entry required by renderPluginInstance '.$master_rpi->getId().' for RenderPlugin '.$master_rpi->getRenderPlugin()->getId().' "'.$master_rpi->getRenderPlugin()->getPluginName().'"');
                }
            }
        }
    }


    /**
     * Creates a new child datatype for compliance with templates
     *
     * @param ODRUser $user The user creating this child datatype
     * @param ThemeElement $theme_element The theme_element this child datatype is being attached to
     * @param DataType $parent_datatype The parent of this new child datatype
     * @param DataType $master_datatype The "master template" datatype for this child datatype
     * @param bool $multiple_allowed
     * @param string $indent_text For logging purposes
     *
     * @return DataType
     */
    private function createChildDatatype($user, $theme_element, $parent_datatype, $master_datatype, $multiple_allowed, $indent_text)
    {
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- creating new child datatype derived from master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"...');

        // Create a new datatype that uses $master_datatype as its template...
        $child_datatype = $this->ec_service->createDatatype(
            $user,
            $master_datatype->getShortName(),
            true
        );

        $child_datatype->setParent($parent_datatype);
        $child_datatype->setGrandparent($parent_datatype->getGrandparent());
        $child_datatype->setTemplateGroup($parent_datatype->getTemplateGroup());
        $child_datatype->setMasterDataType($master_datatype);

        $this->em->persist($child_datatype);

        $child_datatype_meta = $child_datatype->getDataTypeMeta();
        $child_datatype_meta->setSearchSlug(null);    // child datatypes don't have search slugs

        // These are the only other settings that are safe to immediately clone...
        $child_datatype_meta->setDescription($master_datatype->getDescription());
        $child_datatype_meta->setNewRecordsArePublic($master_datatype->getNewRecordsArePublic());
        $child_datatype_meta->setPublicDate($master_datatype->getPublicDate());

        // Have to ignore the external_id field and the render plugin for the moment...they don't
        //  exist right now.

        $this->em->persist($child_datatype_meta);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new child datatype '.$child_datatype->getId());

        // Also need to clone the datatypeSpecialField entries, if any exist...though they also
        //  can't be completely set up at the moment either
        foreach ($master_datatype->getDataTypeSpecialFields() as $dtsf) {
            /** @var DataTypeSpecialFields $dtsf */
            // Can't use $ec_service->createDatatypeSpecialField() because the datafield doesn't exist
            $new_dtsf = clone $dtsf;
            $new_dtsf->setDataType($child_datatype);

            // Store for later
            $this->created_dtsf_entries[ $dtsf->getId() ] = $new_dtsf;
            $this->em->persist($new_dtsf);

            $this->logger->debug('CloneTemplateService:'.$indent_text.' ** ** added special field derived from old dtsf ('.$dtsf->getId().', type '.$dtsf->getFieldPurpose().'), df '.$dtsf->getDataField()->getId().' "'.$dtsf->getDataField()->getFieldName().'" from child datatype');
        }

        $this->em->flush();

        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created special field entries');


        // Need to create a Datatree entry connecting the new child Datatype...
        $is_link = false;
        $this->ec_service->createDatatree($user, $parent_datatype, $child_datatype, $is_link, $multiple_allowed);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new datatree entry between ancestor '.$parent_datatype->getId().' and descendant '.$child_datatype->getId());

        // ...and create a Theme for the new child Datatype...
        $child_theme = $this->ec_service->createTheme($user, $child_datatype, true);
        // Need to set this and at least somewhat keep it updated throughout this process, otherwise
        //  the CloneThemeService will tend to believe stuff is "up to date" even when it's not
        $child_theme_meta = $child_theme->getThemeMeta();
        $child_theme_meta->setSourceSyncVersion(0);
        $this->em->persist($child_theme_meta);

        $parent_master_theme = $this->ti_service->getDatatypeMasterTheme($parent_datatype->getGrandparent()->getId());
        $child_theme->setParentTheme($parent_master_theme);
        $this->em->persist($child_theme);

        $this->em->flush();
        $this->em->refresh($child_datatype);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new master theme for child datatype '.$child_datatype->getId());

        // If a child datatype is being created, then a themeDatatype entry will always need to be
        //  created as well...
        $this->ec_service->createThemeDatatype($user, $theme_element, $child_datatype, $child_theme, true);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created theme_datatype entry for child datatype '.$child_datatype->getId());

        // ...and finally need to create groups for the new child Datatype
        $this->ec_service->createGroupsForDatatype($user, $child_datatype);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- copied groups for child datatype '.$child_datatype->getId());


        // Child datatype is ready for use
        $child_datatype->setSetupStep(DataType::STATE_OPERATIONAL);
        $this->em->persist($child_datatype);

        $this->em->flush();

        return $child_datatype;
    }


    /**
     * Creates a new linked datatype for compliance with templates
     *
     * @param ODRUser $user The user creating this linked datatype
     * @param ThemeElement $theme_element The theme_element this linked datatype is being attached to
     * @param DataType $parent_datatype The datatype linking to this new datatype
     * @param DataType $master_datatype The "master template" datatype for this linked datatype
     * @param bool $multiple_allowed
     * @param string $indent_text For logging purposes
     *
     * @return DataType
     */
    private function createLinkedDatatype($user, $theme_element, $parent_datatype, $master_datatype, $multiple_allowed, $indent_text)
    {
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- creating new linked datatype derived from master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"...');

        // Create a new datatype that uses $master_datatype as its template...
        $linked_datatype = $this->ec_service->createDatatype(
            $user,
            $master_datatype->getShortName(),
            true    // don't flush immediately...
        );

        $linked_datatype->setTemplateGroup($parent_datatype->getTemplateGroup());
        $linked_datatype->setMasterDataType($master_datatype);

        $this->em->persist($linked_datatype);

        $linked_datatype_meta = $linked_datatype->getDataTypeMeta();

        // These are the only other settings that are safe to immediately clone...
        $linked_datatype_meta->setDescription($master_datatype->getDescription());
        $linked_datatype_meta->setNewRecordsArePublic($master_datatype->getNewRecordsArePublic());
        $linked_datatype_meta->setPublicDate($master_datatype->getPublicDate());

        // Have to ignore the external_id field and the render plugin for the moment...they don't
        //  exist right now.

        $this->em->persist($linked_datatype_meta);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new linked datatype '.$linked_datatype->getId());

        // Also need to clone the datatypeSpecialField entries, if any exist...though they also
        //  can't be completely set up at the moment either
        foreach ($master_datatype->getDataTypeSpecialFields() as $dtsf) {
            /** @var DataTypeSpecialFields $dtsf */
            // Can't use $ec_service->createDatatypeSpecialField() because the datafield doesn't exist
            $new_dtsf = clone $dtsf;
            $new_dtsf->setDataType($linked_datatype);

            // Store for later
            $this->created_dtsf_entries[ $dtsf->getId() ] = $new_dtsf;
            $this->em->persist($new_dtsf);

            $this->logger->debug('CloneTemplateService:'.$indent_text.' ** ** added special field derived from old dtsf ('.$dtsf->getId().', type '.$dtsf->getFieldPurpose().'), df '.$dtsf->getDataField()->getId().' "'.$dtsf->getDataField()->getFieldName().'" from linked datatype');
        }

        $this->em->flush();

        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created special field entries');


        // Need to create a Datatree entry connecting the new linked Datatype...
        $is_link = true;
        $this->ec_service->createDatatree($user, $parent_datatype, $linked_datatype, $is_link, $multiple_allowed);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new datatree entry between ancestor '.$parent_datatype->getId().' and descendant '.$linked_datatype->getId());


        // ...and create a top-level Theme for the new linked Datatype...
        $linked_theme = $this->ec_service->createTheme($user, $linked_datatype, true);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new top-level theme for linked datatype '.$linked_datatype->getId());
        $this->em->persist($linked_theme);
        // Need to set this and at least somewhat keep it updated throughout this process, otherwise
        //  the CloneThemeService will tend to believe stuff is "up to date" even when it's not
        $linked_theme_meta = $linked_theme->getThemeMeta();
        $linked_theme_meta->setSourceSyncVersion(0);
        $this->em->persist($linked_theme_meta);


        // ...and create a copy of that Theme...
        $linked_theme_copy = $this->ec_service->createTheme($user, $linked_datatype, true);
        // ...the copy of $linked_datatype's theme needs to use $linked_theme as its source...
        $linked_theme_copy->setSourceTheme($linked_theme);

        $parent_master_theme = $this->ti_service->getDatatypeMasterTheme($parent_datatype->getGrandparent()->getId());
        // ...and reside inside $parent_datatype's theme group
        $linked_theme_copy->setParentTheme($parent_master_theme);

        $this->em->persist($linked_theme_copy);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- copied top-level theme to link within ancestor master theme '.$parent_master_theme->getId());

        // $parent_datatype needs a themeDatatype entry to point to the new linked datatype's theme
        $this->ec_service->createThemeDatatype($user, $theme_element, $linked_datatype, $linked_theme_copy, true);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created theme_datatype entry for linked datatype '.$linked_datatype->getId());

        // ...and finally need to create groups for the new linked Datatype
        $this->ec_service->createGroupsForDatatype($user, $linked_datatype);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created groups for linked datatype '.$linked_datatype->getId());


        // Linked datatype is ready for use
        $linked_datatype->setSetupStep(DataType::STATE_OPERATIONAL);
        $this->em->persist($linked_datatype);

        $this->em->flush();

        return $linked_datatype;
    }


    /**
     * Link to an existing datatype for compliance with templates
     *
     * @param ODRUser $user
     * @param DataType $parent_datatype
     * @param DataType $linked_datatype
     * @param bool $multiple_allowed
     * @param string $indent_text For logging purposes
     */
    private function linkToExistingDatatype($user, $parent_datatype, $linked_datatype, $multiple_allowed, $indent_text)
    {
        // Need to locate the master themes for both $parent_datatype and $linked_datatype...
        $parent_datatype_master_theme = $this->ti_service->getDatatypeMasterTheme($parent_datatype->getId());
        $linked_datatype_master_theme = $this->ti_service->getDatatypeMasterTheme($linked_datatype->getId());

        $new_linked_theme = $this->ec_service->createTheme($user, $linked_datatype, true);
        $new_linked_theme->setSourceTheme($linked_datatype_master_theme);
        $new_linked_theme->setParentTheme($parent_datatype_master_theme->getParentTheme());
        $this->em->persist($new_linked_theme);

        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- (secondary) copied top-level theme to link within ancestor master theme '.$parent_datatype_master_theme->getId());

        // ...then need to create a new themeElement and themeDatatype to attach this linked datatype into
        $new_te = $this->ec_service->createThemeElement($user, $parent_datatype_master_theme, true);    // don't flush immediately...
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- (secondary) created new theme_element for derived datatype '.$parent_datatype->getId());

        $this->ec_service->createThemeDatatype($user, $new_te, $linked_datatype, $new_linked_theme, true);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- (secondary) created theme_datatype entry for linked datatype '.$linked_datatype->getId());


        // Also, the datatree entry linking the two datatypes doesn't exist at this point in time...create it
        $is_link = true;
        $this->ec_service->createDatatree($user, $parent_datatype, $linked_datatype, $is_link, $multiple_allowed);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- (secondary) created new datatree entry between ancestor '.$parent_datatype->getId().' and descendant '.$linked_datatype->getId());

        $this->em->flush();
    }
}
