<?php

/**
 * Open Data Repository Data Publisher
 * Create Datatype Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains the functions required to create a datatype from a "master template" (really just another datatype).
 *
 * For the most part, this is done by cloning each individual part of the master template into newly created
 * entities, and then connecting them up together again.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\UserGroup;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use FOS\UserBundle\Model\UserManagerInterface;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class CreateDatatypeService
{

    /**
     * @var EntityManager $em
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var UserManagerInterface
     */
    private $user_manager;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * @var ODRUser
     */
    private $user;

    /**
     * @var DataType
     */
    private $original_datatype;

    /**
     * @var DataType[]
     */
    private $created_datatypes;

    /**
     * @var Group[]
     */
    private $created_groups;


    /**
     * CreateDatatypeService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatatypeInfoService $datatype_info_service
     * @param PermissionsManagementService $permissions_service
     * @param UserManagerInterface $user_manager
     * @param Logger $logger
     */
    public function __construct(EntityManager $entity_manager, CacheService $cache_service, DatatypeInfoService $datatype_info_service, PermissionsManagementService $permissions_service, UserManagerInterface $user_manager, Logger $logger) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dti_service = $datatype_info_service;
        $this->pm_service = $permissions_service;
        $this->user_manager = $user_manager;
        $this->logger = $logger;
    }


    /**
     * Saves and reloads the provided object from the database.
     *
     * @param mixed $obj
     * @param bool $update_user_info
     */
    private function persistObject($obj, $update_user_info = false) {
        //
        if ($update_user_info) {
            if (method_exists($obj, "setCreatedBy"))
                $obj->setCreatedBy($this->user);

            if (method_exists($obj, "setUpdatedBy"))
                $obj->setUpdatedBy($this->user);
        }

        $this->em->persist($obj);
        $this->em->flush();
        $this->em->refresh($obj);
    }


    /**
     * Given the id of a datatype in the "create" phase...this function makes the provided datatype a copy of its
     * "master template" along with all of the master template's child/linked datatypes.
     *
     * @param integer $datatype_id
     * @param integer $user_id
     *
     * @return string
     */
    public function createDatatypeFromMaster($datatype_id, $user_id)
    {
        try {
            // Save which user started this creation process
            $this->user = $this->user_manager->findUserBy( array('id' => $user_id) );
            if ($this->user == null)
                throw new ODRNotFoundException('User');

            $this->logger->debug('CreateDatatypeService: entered createDatatypeFromMaster(), user '.$user_id.' is attempting to clone a datatype');

            // Get the DataType to work with
            $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');

            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Check if datatype is not in "initial" mode
            if ($datatype->getSetupStep() != "initial")
                throw new ODRException("Datatype is not in the correct setup mode.  Setup step was: " . $datatype->getSetupStep());
            if ($datatype->getMasterDataType() == null || $datatype->getMasterDataType()->getId() < 1)
                throw new ODRException("Invalid master template id");


            // ----------------------------------------
            // Save which datatype this creation process was originally started on
            $this->original_datatype = $datatype;
            $datatype_prefix = $datatype->getShortName();

            // Get all datatypes that need cloning, including child and linked datatypes
            $master_datatype = $datatype->getMasterDataType();
            $include_links = true;
            $associated_datatypes = $this->dti_service->getAssociatedDatatypes( array($master_datatype->getId()), $include_links );
            $this->logger->debug('CreateDatatypeService: $associated_datatypes: '.print_r($associated_datatypes, true) );

            // Clone the master template datatype, and all its linked/child datatypes as well
            $this->created_datatypes = array();
            foreach ($associated_datatypes as $dt_id) {
                $this->logger->debug('----------------------------------------');
                $new_datatype = null;
                $dt_master = null;

                if ($dt_id == $master_datatype->getId()) {
                    // This is the master template datatype...the $datatype that was created back in DatatypeController::addAction() should become a copy of the master template
                    $new_datatype = $datatype;
                    $dt_master = $master_datatype;

                    $this->logger->debug('CreateDatatypeService: attempting to clone master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'" into datatype '.$new_datatype->getId());
                }
                else {
                    // This is one of the child/linked datatypes of the master template...create a new datatype based off of it
                    /** @var DataType $dt_master */
                    $dt_master = $repo_datatype->find($dt_id);
                    if ($dt_master == null)
                        throw new ODRException('Unable to clone the deleted Datatype '.$dt_id);

                    $this->logger->debug('CreateDatatypeService: attempting to clone master datatype '.$dt_id.' "'.$dt_master->getShortName().'" into new datatype...');
                }

                // Clone the datatype $dt_master into $new_datatype
                self::cloneDatatype($dt_master, $new_datatype, $datatype_prefix);
            }


            // ----------------------------------------
            // TODO - currently this only copies the 'master' theme...should it copy others?
            // Clone associated datatype themes
            $this->logger->debug('----------------------------------------');
            foreach ($this->created_datatypes as $dt) {
                $this->logger->debug('----------------------------------------');
                self::cloneDatatypeThemeFromMaster($dt);
            }


            // ----------------------------------------
            // Clone Datatree and DatatreeMeta entries
            $this->logger->debug('----------------------------------------');
            self::cloneDatatree($master_datatype);

            // Create all of the Group entries required for cloning permissions
            $this->logger->debug('----------------------------------------');
            foreach ($this->created_datatypes as $dt)
                self::cloneDatatypeGroups($dt);    // The function does the check for whether $dt is top-level or not

            // Clone the datatype and datafield permissions for each of the created datatypes
            $this->logger->debug('----------------------------------------');
            foreach ($this->created_datatypes as $dt) {
                $this->logger->debug('----------------------------------------');
                self::cloneDatatypePermissions($dt);

                /** @var DataFields[] $datafields */
                $datafields = $dt->getDataFields();
                foreach ($datafields as $df)
                    self::cloneDatafieldPermissions($df);
            }


            // ----------------------------------------
            // The datatypes are now ready for viewing since they have all their datafield, theme, datatree, and permission entries
            foreach ($this->created_datatypes as $dt) {
                $dt->setSetupStep(Datatype::STATE_INCOMPLETE);
                self::persistObject($dt);
            }

            // ----------------------------------------
            // Delete the cached versions of the top-level datatypes and the datatree array
            $this->cache_service->delete('top_level_datatypes');
            $this->cache_service->delete('cached_datatree_array');

            //
            $user_list = array();
            foreach ($this->created_groups as $created_group) {
                // Store which users are in this group
                /** @var UserGroup[] $user_groups */
                $user_groups = $created_group->getUserGroups();
                foreach ($user_groups as $ug)
                    $user_list[ $ug->getUser()->getId() ] = 1;

                // Wipe the cached entry for this group since it likely has changed
                $this->cache_service->delete('group_'.$created_group->getId().'_permissions');
            }

            // Also wipe cached entry for all affected users (typically, just super admins and whoever created the datatype)
            foreach ($user_list as $user_id => $num)
                $this->cache_service->delete('user_'.$user_id.'_permissions');


            // ----------------------------------------
            $this->logger->debug('----------------------------------------');
            $this->logger->debug('CreateDatatypeService: cloning of datatype '.$datatype->getId().' is complete');
            $this->logger->debug('----------------------------------------');

            return 'complete';
        }
        catch (\Exception $e) {
            $this->logger->debug('EXCEPTION: '.$e->getMessage());
            return $e->getMessage();
        }
    }


    /**
     * Clones the provided $parent_datatype into a new database entry
     *
     * @param DataType $parent_datatype
     * @param DataType|null $new_datatype
     * @param string $datatype_prefix
     */
    private function cloneDatatype($parent_datatype, $new_datatype = null, $datatype_prefix = "")
    {
        // If $new_dataype isn't created yet, clone $parent_datatype to use as a starting point
        if ($new_datatype == null)
            $new_datatype = clone $parent_datatype;


        // $new_datatype is based off a "master template" datatype
        $new_datatype->setIsMasterType(false);
        $new_datatype->setMasterDataType($parent_datatype);
        $new_datatype->setSetupStep('initial');
        self::persistObject($new_datatype);
        array_push($this->created_datatypes, $new_datatype);

        $this->logger->debug('CreateDatatypeService: datatype '.$new_datatype->getId().' using datatype '.$parent_datatype->getId().' as its master template...');

        $parent_meta = $parent_datatype->getDataTypeMeta();
        // Meta might already exist - need to copy relevant fields and delete
        $existing_meta = $new_datatype->getDataTypeMeta();

        $new_meta = clone $parent_meta;
        if ($existing_meta != null) {
            // Copy the properties from the existing DatatypeMeta entry into the cloned DatatypeMeta entry
            $new_meta->setShortName($existing_meta->getShortName());
            $new_meta->setSearchSlug('data_' . $new_datatype->getId());
            $new_meta->setLongName($existing_meta->getLongName());
            $new_meta->setDescription($existing_meta->getDescription());

            // Ensure the "in-memory" version of $new_datatype no longer references the old meta entry
            $new_datatype->removeDataTypeMetum($existing_meta);

            // Delete the existing DatatypeMeta entry
            $this->em->remove($existing_meta);
            $this->em->persist($new_meta);
            $this->em->flush();
        }

        // Use a prefix if short name not equal prefix
        if ($new_meta->getShortName() != $datatype_prefix)
            $new_meta->setLongName($datatype_prefix . " - " . $new_meta->getShortName());

        $new_meta->setDataType($new_datatype);

        // Track the published version
        $new_meta->setMasterRevision(0);
        $new_meta->setMasterPublishedRevision(0);
        if ($parent_datatype->getDataTypeMeta()->getMasterPublishedRevision() == null)
            $new_meta->setTrackingMasterRevision(-100);
        else
            $new_meta->setTrackingMasterRevision($parent_datatype->getDataTypeMeta()->getMasterPublishedRevision());

        // Preserve the Render Plugin
        $parent_render_plugin = $parent_meta->getRenderPlugin();
        $new_meta->setRenderPlugin($parent_render_plugin);

        // Ensure the "in-memory" version of $new_datatype knows about its meta entry
        $new_datatype->addDataTypeMetum($new_meta);
        self::persistObject($new_meta);
        $this->logger->debug('CreateDatatypeService: meta entry cloned for datatype '.$new_datatype->getId());


        // ----------------------------------------
        // Process data fields so themes and render plugin map can be created
        /** @var DataFields[] $parent_df_array */
        $parent_df_array = $parent_datatype->getDataFields();
        foreach ($parent_df_array as $parent_df) {
            // Copy over all of the parent datatype's datafields
            $new_df = clone $parent_df;
            $new_df->setDataType($new_datatype);
            $new_df->setIsMasterField(false);
            $new_df->setMasterDatafield($parent_df);

            // Ensure the "in-memory" version of $new_datatype knows about the new datafield
            $new_datatype->addDataField($new_df);
            self::persistObject($new_df, true);

            $this->logger->debug('CreateDatatypeService: copied master datafield '.$parent_df->getId().' "'.$parent_df->getFieldName().'" into new datafield '.$new_df->getId());

            // Process Meta Records
            $parent_df_meta = $parent_df->getDataFieldMeta();
            if ($parent_df_meta) {
                // TODO This should always exist.  Likely issue was caused by
                // the fact that a previous test failed. It's bad news that these
                // things can fail and now warn the user...
                $new_df_meta = clone $parent_df_meta;
                $new_df_meta->setDataField($new_df);
                $new_df_meta->setMasterRevision(0);
                $new_df_meta->setMasterPublishedRevision(0);
                $new_df_meta->setTrackingMasterRevision($parent_df_meta->getMasterPublishedRevision());

                // Ensure the "in-memory" version of $new_df knows about the new meta entry
                $new_df->addDataFieldMetum($new_df_meta);
                self::persistObject($new_df_meta, true);

                $this->logger->debug('CreateDatatypeService: -- meta entry cloned for datafield '.$new_df->getId());
            }

            // Need to process Radio Options....
            /** @var RadioOptions[] $parent_ro_array */
            $parent_ro_array = $parent_df->getRadioOptions();
            if ( count($parent_ro_array) > 0 ) {
                foreach($parent_ro_array as $parent_ro) {
                    // Copy over all the radio options for this datafield, and their associated meta entries
                    $new_ro = clone $parent_ro;
                    $new_ro->setDataField($new_df);

                    // Ensure the "in-memory" version of $new_df knows about its new radio option
                    $new_df->addRadioOption($new_ro);
                    self::persistObject($new_ro, true);

                    $parent_ro_meta = $parent_ro->getRadioOptionMeta();
                    $new_ro_meta = clone $parent_ro_meta;
                    $new_ro_meta->setRadioOption($new_ro);

                    // Ensure the "in-memory" version of $new_ro knows about its meta entry
                    $new_ro->addRadioOptionMetum($new_ro_meta);
                    self::persistObject($new_ro_meta, true);

                    $this->logger->debug('CreateDatatypeService: copied radio option '.$parent_ro->getId().' "'.$new_ro->getOptionName().'" and its meta entry');
                }
            }

            // If the datafield in the master template has a render plugin, copy its settings for the new datafield
            self::cloneRenderPluginSettings($parent_df->getRenderPlugin(), null, $new_df);
        }

        // Now that the datafields are created...if the parent datatype has a render plugin, copy its settings as well
        self::cloneRenderPluginSettings($parent_datatype->getRenderPlugin(), $new_datatype);
    }


    /**
     * Once the theme stuff from the master template and its children are fully cloned, the datatree entries describing
     * parent/child datatype relations also need to be cloned...
     *
     * @param DataType $parent_datatype
     */
    private function cloneDatatree($parent_datatype)
    {
        $this->logger->debug('CreateDatatypeService: attempting to clone datatree entries for datatype '.$parent_datatype->getId().'...');

        /** @var DataTree[] $datatree_array */
        $datatree_array = $this->em->getRepository('ODRAdminBundle:DataTree')->findBy( array('ancestor' => $parent_datatype->getId()) );

        // Locate the newly created datatype corresponding to $parent_datatype
        $current_ancestor = null;
        foreach ($this->created_datatypes as $datatype) {
            if ($datatype->getMasterDataType()->getId() == $parent_datatype->getId())
                $current_ancestor = $datatype;
        }

        // For each descendant of the given $parent_datatype...
        foreach ($datatree_array as $datatree) {
            foreach ($this->created_datatypes as $datatype) {
                // ...if this newly-created datatype should be the given datatype's child...
                if ($datatree->getDescendant()->getId() == $datatype->getMasterDataType()->getId()) {
                    // ...create a Datatree entry to make this datatype a child of the given datatype
                    $new_dt = new DataTree();
                    $new_dt->setAncestor($current_ancestor);
                    $new_dt->setDescendant($datatype);
                    self::persistObject($new_dt, true);     // apparently doesn't clone createdBy by default?

                    // Clone the datatree's meta entry
                    $new_meta = clone $datatree->getDataTreeMeta();
                    $new_meta->setDataTree($new_dt);
                    self::persistObject($new_meta);

                    $is_link = 0;
                    if ($new_dt->getIsLink())
                        $is_link = 1;

                    $this->logger->debug('CreateDatatypeService: created new datatree with datatype '.$current_ancestor->getId().' "'.$current_ancestor->getShortName().'" as ancestor and datatype '.$datatype->getId().' "'.$datatype->getShortName().'" as descendant, is_link = '.$is_link);

                    // Also create any datatree entries required for this newly-created datatype
                    self::cloneDatatree($datatype->getMasterDataType());
                }
            }
        }
    }


    /**
     * Clones all Group entries for top-level datatypes.  Datatree entries are assumed to already exist.
     *
     * @param Datatype $datatype
     */
    private function cloneDatatypeGroups($datatype)
    {
        // Ensure this is a top-level datatype before doing anything...child datatypes don't have their own groups since
        // they're connected to their grandparent's groups through GroupDatatypePermission entries

        /** @var DataTree[] $datatree_array */
        $datatree_array = $this->em->getRepository('ODRAdminBundle:DataTree')->findBy( array('descendant' => $datatype->getId()) );
        foreach ($datatree_array as $datatree) {
            if ($datatree->getIsLink() == 0) {
                // This datatype is a child of some other datatype...do NOT create any groups for it
                return;
            }
        }

        // Load all groups from this datatype's master
        $master_datatype = $datatype->getMasterDataType();
        $this->logger->debug('CreateDatatypeService: attempting to clone group entries for datatype '.$datatype->getId().' "'.$datatype->getShortName().'" from master datatype '.$master_datatype->getId().'...');

        /** @var Group[] $master_groups */
        $master_groups = $master_datatype->getGroups();
        if ($master_groups == null)
            throw new ODRException('CreateDatatypeService: Master Datatype '.$master_datatype->getId().' has no group entries to clone.');

        // Clone all of the master datatype's groups
        foreach ($master_groups as $master_group) {
            $new_group = clone $master_group;
            $new_group->setDataType($datatype);

            // Ensure the "in-memory" version of $datatype knows about the new group
            $datatype->addGroup($new_group);
            self::persistObject($new_group, true);

            // Store that a group was created
            $this->created_groups[] = $new_group;

            // ...also needs the associated group meta entry
            $parent_group_meta = $master_group->getGroupMeta();
            $new_group_meta = clone $parent_group_meta;
            $new_group_meta->setGroup($new_group);

            // Ensure the "in-memory" version of $new_group knows about its new meta entry
            $new_group->addGroupMetum($new_group_meta);
            self::persistObject($new_group_meta, true);

            $this->logger->debug('CreateDatatypeService: created new Group '.$new_group->getId().' from parent "'.$master_group->getPurpose().'" Group '.$master_group->getId().' for datatype '.$datatype->getId());

            // If an admin group got created, then all super-admins need to be added to it
            if ($new_group->getPurpose() == "admin") {
                /** @var ODRUser[] $user_list */
                $user_list = $this->user_manager->findUsers();

                // Locate those with super-admin permissions...
                foreach ($user_list as $u) {
                    if ( $u->hasRole('ROLE_SUPER_ADMIN') ) {
                        // ...add the super admin to this new admin group
                        $this->pm_service->createUserGroup($u, $new_group, $this->user);
                        $this->logger->debug('-- added user '.$u->getId().' to admin group');

                        // Don't bother deleting their cached permissions here...there's no guarantee they won't access the datatype before their permissions are ready anyways
                    }
                }

                // If the user isn't a super-admin, then he needs to be added to the admin group as well...
                // ...otherwise, he won't be able to see the new datatype either
                if (!$this->user->hasRole('ROLE_SUPER_ADMIN')) {
                    $this->pm_service->createUserGroup($this->user, $new_group, $this->user);
                    $this->logger->debug('-- added user '.$this->user->getId().' to admin group');

                    // Don't bother deleting their cached permissions here...they'll likely attempt to access the datatype before the permissions are ready, and get a stale version
                }
            }

            // Don't need to delete cached versions of any other users or groups...these groups are brand-new
        }
    }


    /**
     * Clones all permission entries from $datatype's master datatype.  Group entries are assumed to already exist.
     *
     * @param Datatype $datatype
     */
    private function cloneDatatypePermissions($datatype)
    {
        // Pull up all the datatype permission entries for all the groups this datatype's master template belongs to
        $master_datatype = $datatype->getMasterDataType();
        $this->logger->debug('CreateDatatypeService: attempting to clone datatype permission entries for datatype '.$datatype->getId().' "'.$datatype->getShortName().'" from master datatype '.$master_datatype->getId().'...');

        /** @var GroupDatatypePermissions[] $master_gdt_permissions */
        $master_gdt_permissions = $master_datatype->getGroupDatatypePermissions();
        if ($master_gdt_permissions == null)
            throw new ODRException('CreateDatatypeService: Master Datatype '.$master_datatype->getId().' has no permission entries to clone.');


        // Going to need this datatype's grandparent...
        // NOTE - can't use $this->dti_service->getGrandparentDatatypeId() for this...the new datatypes are still in the "initial" state, and will be ignored by that function
        $repo_datatree = $this->em->getRepository('ODRAdminBundle:DataTree');
        $grandparent_datatype_id = $datatype->getId();

        $datatree_array = array();
        do {
            //
            $is_child = false;

            /** @var DataTree[] $datatree_array */
            $datatree_array = $repo_datatree->findBy( array('descendant' => $grandparent_datatype_id) );
            foreach ($datatree_array as $datatree) {
                if ($datatree->getIsLink() == 0) {
                    $is_child = true;
                    $grandparent_datatype_id = $datatree->getAncestor()->getId();
                }
            }

            // If this datatype is linked to from somewhere, then $datatree_array will never be empty...exit the loop
            if (!$is_child)
                break;

        } while ( count($datatree_array) > 0 );


        // Get all groups for this datatype's grandparent
        /** @var Group[] $grandparent_groups */
        $grandparent_groups = $this->em->getRepository('ODRAdminBundle:Group')->findBy( array('dataType' => $grandparent_datatype_id) );
        if ($grandparent_groups == null)
            throw new ODRException('CreateDatatypeService: Grandparent Datatype '.$grandparent_datatype_id.' has no group entries');


        // For each datatype permission from the master template...
        foreach ($master_gdt_permissions as $master_permission) {
            $master_group = $master_permission->getGroup();

            // ...locate the corresponding group from this datatype's grandparent
            foreach ($grandparent_groups as $group) {
                if ($master_group->getGroupName() == $group->getGroupName()) {
                    // Clone the permission from the master datatype
                    $new_permission = clone $master_permission;
                    $new_permission->setGroup($group);
                    $new_permission->setDataType($datatype);

                    // Ensure the "in-memory" versions of both the group and the new datatype know about this new permission
                    $group->addGroupDatatypePermission($new_permission);
                    $datatype->addGroupDatatypePermission($new_permission);
                    self::persistObject($new_permission, true);

                    $this->logger->debug('CreateDatatypeService: cloned GroupDatatypePermission entry from master template Group '.$master_group->getId().' to Group '.$group->getId().' for new datatype '.$datatype->getId());
                }
            }
        }
    }


    /**
     * Clones all permission entries from $datafield's master datafield.  Group entries are already assumed to exist.
     *
     * @param DataFields $datafield
     */
    private function cloneDatafieldPermissions($datafield)
    {
        // Pull up all the datafield permission entries for all the groups this datafield's master template belongs to
        $master_datafield = $datafield->getMasterDataField();
        $this->logger->debug('CreateDatatypeService: attempting to clone datafield permission entries for datafield '.$datafield->getId().' "'.$datafield->getFieldName().'" from master datafield '.$master_datafield->getId().'...');

        /** @var GroupDatafieldPermissions[] $master_gdf_permissions */
        $master_gdf_permissions = $master_datafield->getGroupDatafieldPermissions();
        if ($master_gdf_permissions == null)
            throw new ODRException('CreateDatatypeService: Master Datafield '.$master_datafield->getId().' has no permission entries to clone.');

        // Going to need this datafield's datatype's grandparent...
        // NOTE - can't use $this->dti_service->getGrandparentDatatypeId() for this...the new datatypes are still in the "initial" state, and will be ignored by that function
        $datatype = $datafield->getDataType();
        $repo_datatree = $this->em->getRepository('ODRAdminBundle:DataTree');
        $grandparent_datatype_id = $datatype->getId();

        $datatree_array = array();
        do {
            //
            $is_child = false;

            /** @var DataTree[] $datatree_array */
            $datatree_array = $repo_datatree->findBy( array('descendant' => $grandparent_datatype_id) );
            foreach ($datatree_array as $datatree) {
                if ($datatree->getIsLink() == 0) {
                    $is_child = true;
                    $grandparent_datatype_id = $datatree->getAncestor()->getId();
                }
            }

            // If this datatype is linked to from somewhere, then $datatree_array will never be empty...exit the loop
            if (!$is_child)
                break;

        } while ( count($datatree_array) > 0 );


        // Get all groups for this datatype's grandparent
        /** @var Group[] $grandparent_groups */
        $grandparent_groups = $this->em->getRepository('ODRAdminBundle:Group')->findBy( array('dataType' => $grandparent_datatype_id) );
        if ($grandparent_groups == null)
            throw new ODRException('CreateDatatypeService: Grandparent Datatype '.$grandparent_datatype_id.' has no group entries');


        // For each datafield permission from the master template...
        foreach ($master_gdf_permissions as $master_permission) {
            $master_group = $master_permission->getGroup();

            // ...locate the corresponding group from this datatype's grandparent
            foreach ($grandparent_groups as $group) {
                if ($master_group->getGroupName() == $group->getGroupName()) {
                    // Clone the permission from the master datafield
                    $new_permission = clone $master_permission;
                    $new_permission->setGroup($group);
                    $new_permission->setDataField($datafield);

                    // Ensure the "in-memory" versions of both the group and the new datafield know about this new permission
                    $group->addGroupDatafieldPermission($new_permission);
                    $datafield->addGroupDatafieldPermission($new_permission);
                    self::persistObject($new_permission, true);

                    $this->logger->debug('CreateDatatypeService: cloned GroupDatafieldPermission entry from master template Group '.$master_group->getId().' to Group '.$group->getId().' for new datafield '.$datafield->getId());
                }
            }
        }
    }


    /**
     * Given a Datatype or Datafield, completely clone all the relevant information for that entity's render plugin
     * if it's currently using one.
     *
     * @param RenderPlugin|null $parent_render_plugin
     * @param DataType|null $datatype
     * @param DataFields|null $datafield
     */
    private function cloneRenderPluginSettings($parent_render_plugin, $datatype = null, $datafield = null)
    {
        // Don't need to clone anything if using the default render plugin
//        if ($parent_render_plugin == null || $parent_render_plugin->getId() == 1)
//            return;

        $repo_rpi = $this->em->getRepository('ODRAdminBundle:RenderPluginInstance');
        $repo_datafield = $this->em->getRepository('ODRAdminBundle:DataFields');
        $parent_rpi = null;

        if ($datatype != null) {
            $this->logger->debug('CreateDatatypeService: attempting to clone settings for render plugin '.$parent_render_plugin->getId().' in use by datatype '.$datatype->getId());
            $parent_rpi = $repo_rpi->findOneBy( array('dataType' => $datatype->getId(), 'renderPlugin' => $parent_render_plugin->getId()) );
        }
        else {
            $this->logger->debug('CreateDatatypeService: attempting to clone settings for render plugin '.$parent_render_plugin->getId().' in use by datafield '.$datafield->getId());
            $parent_rpi = $repo_rpi->findOneBy( array('dataField' => $datafield->getId(), 'renderPlugin' => $parent_render_plugin->getId()) );
        }
        /** @var RenderPluginInstance $parent_rpi */

        if ($parent_rpi != null) {
            // If the parent datatype/datafield is using a render plugin, then clone that instance of the render plugin
            $new_rpi = clone $parent_rpi;
            $new_rpi->setDataType($datatype);
            $new_rpi->setDataField($datafield);
            self::persistObject($new_rpi);

            // Clone each option for this instance of the render plugin
            /** @var RenderPluginOptions[] $parent_rpo_array */
            $parent_rpo_array = $parent_rpi->getRenderPluginOptions();
            foreach ($parent_rpo_array as $parent_rpo) {
                $new_rpo = clone $parent_rpo;
                $new_rpo->setRenderPluginInstance($new_rpi);
                self::persistObject($new_rpo);

                $this->logger->debug('CreateDatatypeService: copied render_plugin_option '.$parent_rpo->getId());
            }

            // Clone each datafield that's being used by this instance of the render plugin
            /** @var RenderPluginMap[] $parent_rpm_array */
            $parent_rpm_array = $parent_rpi->getRenderPluginMap();
            foreach ($parent_rpm_array as $parent_rpm) {
                $new_rpm = clone $parent_rpm;
                $new_rpm->setRenderPluginInstance($new_rpi);

                if ($datatype == null)
                    $new_rpm->setDataType($datatype);       // TODO - if null, then a datafield plugin...but why does it work like that in the first place again?
                else
                    $datatype = $datafield->getDataType();

                // This rpm entry refers to a datafield in the master template...find the analogous datafield in the new (cloned) datatype
                /** @var DataFields $matching_df */
                $matching_df = $repo_datafield->findOneBy( array('dataType' => $datatype->getId(), 'masterDatafield' => $parent_rpm->getDataField()) );
                $new_rpm->setDataField($matching_df);
                self::persistObject($new_rpm);

                $this->logger->debug('CreateDatatypeService: copied render_plugin_map '.$parent_rpm->getId());
            }
        }
    }


    /**
     * Clones the theme, theme_meta, theme elements, theme datafields, and theme datatype entries associated with a
     * master template, and assigns all of them to the given datatype.
     *
     * @param DataType $datatype
     * @Deprecated
     */
    private function cloneDatatypeThemeFromMaster($datatype)
    {
        // Get Themes
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
        $parent_datatype = $datatype->getMasterDataType();

        $this->logger->debug('CreateDatatypeService: attempting to clone theme from master datatype '.$parent_datatype->getId().' into new datatype '.$datatype->getId().' "'.$datatype->getShortName().'"...');

        // This is the theme we will be copying from
        /** @var Theme $parent_theme */
        $parent_theme = $repo_theme->findOneBy( array('dataType' => $parent_datatype->getId(), 'themeType' => 'master') );
        if ($parent_theme == null)
            throw new ODRException('CreateDatatypeService: master theme for parent datatype '.$parent_datatype->getId().' does not exist');

        // This is the theme we will be copying to
        /** @var Theme $datatype_theme */
        $datatype_theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
        if ($datatype_theme != null) {
            // Need to delete any existing theme
            $this->em->remove($datatype_theme);

            // Ensure the "in-memory" version of $datatype doesn't reference this deleted theme
            $datatype->removeTheme($datatype_theme);
            $this->em->flush();

            $this->logger->debug('CreateDatatypeService: deleted existing theme '.$datatype_theme->getId().' from new datatype');
        }


        // Clone the parent datatype's theme
        $new_theme = clone $parent_theme;
        $new_theme->setDataType($datatype);

        // Ensure the "in-memory" representation of $datatype knows about the new theme entry
        $datatype->addTheme($new_theme);
        self::persistObject($new_theme);


        // Also clone the parent datatype's theme's meta entry
        $new_theme_meta = clone $parent_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);

        // Ensure the "in-memory" representation of $new_theme knows about the new theme meta entry
        $new_theme->addThemeMetum($new_theme_meta);
        self::persistObject($new_theme_meta);

        $this->logger->debug('CreateDatatypeService: cloned parent theme '.$parent_theme->getId().' and associated meta entry into new datatype theme '.$new_theme->getId());

        // Get all of this Datatype's datafields
        /** @var DataFields[] $datafields */
        $datafields = $datatype->getDataFields();

        // Get Theme Elements
        /** @var ThemeElement[] $parent_te_array */
        $parent_te_array = $parent_theme->getThemeElements();
        foreach ($parent_te_array as $parent_te) {
            // Clone each theme element belonging to the parent datatype's master theme
            $new_te = clone $parent_te;
            $new_te->setTheme($new_theme);

            // Ensure the "in-memory" representation of $new_theme knows about the new theme entry
            $new_theme->addThemeElement($new_te);
            self::persistObject($new_te);


            // Also clone each of these theme element's meta entries
            $parent_te_meta = $parent_te->getThemeElementMeta();
            $new_te_meta = clone $parent_te_meta;
            $new_te_meta->setThemeElement($new_te);

            // Ensure the "in-memory" representation of $new_te knows about its meta entry
            $new_te->addThemeElementMetum($new_te_meta);
            self::persistObject($new_te_meta);

            $this->logger->debug('CreateDatatypeService: -- copied parent theme_element '.$parent_te->getId().' into new datatype theme_element '.$new_te->getId());

            // Also clone each ThemeDatafield entry in each of these theme elements
            /** @var ThemeDataField[] $parent_theme_df_array */
            $parent_theme_df_array = $parent_te->getThemeDataFields();
            foreach ($parent_theme_df_array as $parent_tdf) {
                $new_tdf = clone $parent_tdf;
                $new_tdf->setThemeElement($new_te);

                foreach ($datafields as $datafield) {
                    if ($datafield->getMasterDataField()->getId() == $parent_tdf->getDataField()->getId()) {
                        $new_tdf->setDataField($datafield);

                        // Ensure the "in-memory" version of $new_te knows about the new theme_datafield entry
                        $new_te->addThemeDataField($new_tdf);
                        self::persistObject($new_tdf);

                        $this->logger->debug('CreateDatatypeService: -- -- copied theme_datafield '.$parent_tdf->getId().' for master datafield '.$parent_tdf->getDataField()->getId().' "'.$datafield->getFieldName().'" into new datafield '.$datafield->getId());
                        break;
                    }
                }
            }

            // Also clone each ThemeDatatype entry in each of these theme elements
            /** @var ThemeDataType[] $parent_theme_dt_array */
            $parent_theme_dt_array = $parent_te->getThemeDataType();
            foreach ($parent_theme_dt_array as $parent_tdt) {
                $new_tdt = clone $parent_tdt;
                $new_tdt->setThemeElement($new_te);

                foreach ($this->created_datatypes as $created_datatype) {
                    if ($created_datatype->getMasterDataType()->getId() == $parent_tdt->getDataType()->getId()) {
                        $new_tdt->setDataType($created_datatype);

                        // Ensure the "in-memory" version of $new_te knows about the new theme_datatype entry
                        $new_te->addThemeDataType($new_tdt);
                        self::persistObject($new_tdt);

                        $this->logger->debug('CreateDatatypeService: -- -- copied theme_datatype '.$parent_tdt->getId().' for master datatype '.$parent_tdt->getDataType()->getId().' "'.$created_datatype->getShortName().'" into new datatype '.$created_datatype->getId());
                        break;
                    }
                }
            }
        }
    }

}
