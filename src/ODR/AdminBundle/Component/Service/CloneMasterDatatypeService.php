<?php

/**
 * Open Data Repository Data Publisher
 * Clone Datatype Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains the functions required to create a datatype from a "master template", which really is
 * just another datatype.
 *
 * For the most part, this is done by cloning each individual part of the master template into
 * newly created entities, and then connecting them up together again.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
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


class CloneMasterDatatypeService
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
     * @var CloneMasterTemplateThemeService
     */
    private $clone_theme_service;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var ThemeInfoService
     */
    private $tif_service;

    /**
     * @var UserManagerInterface
     */
    private $user_manager;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * The user that started this cloning process
     *
     * @var ODRUser
     */
    private $user;

    /**
     * The datatype that was created back in DatatypeController::addAction()
     *
     * @var DataType
     */
    private $original_datatype;

    /**
     * The master template datatype being cloned
     *
     * @var DataType
     */
    private $master_datatype;

    /**
     * @var DataType[]
     */
    private $associated_datatypes;

    /**
     * @var DataType[]
     */
    private $created_datatypes;

    /**
     * @var Group[]
     */
    private $created_groups;


    /**
     * @var DataType[]
     */
    private $dt_mapping;

    /**
     * @var DataFields[]
     */
    private $df_mapping;

    /**
     * @var DataType[]
     */
    private $existing_datatypes;

    /**
     * @var Theme[]
     */
    private $t_mapping;


    /**
     * CloneDatatypeService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param CloneMasterTemplateThemeService $clone_theme_service
     * @param DatatypeInfoService $datatype_info_service
     * @param PermissionsManagementService $permissions_service
     * @param ThemeInfoService $theme_info_service
     * @param UserManagerInterface $user_manager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        CloneMasterTemplateThemeService $clone_theme_service,
        DatatypeInfoService $datatype_info_service,
        PermissionsManagementService $permissions_service,
        ThemeInfoService $theme_info_service,
        UserManagerInterface $user_manager,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->clone_theme_service = $clone_theme_service;
        $this->dti_service = $datatype_info_service;
        $this->pm_service = $permissions_service;
        $this->tif_service = $theme_info_service;
        $this->user_manager = $user_manager;
        $this->logger = $logger;
    }


    /**
     * Saves and reloads the provided object from the database.
     *
     * @param mixed $obj
     * @param bool $delay_flush
     */
    private function persistObject($obj, $delay_flush = false)
    {
        //
        if (method_exists($obj, "setCreated"))
            $obj->setCreated(new \DateTime());
        if (method_exists($obj, "setUpdated"))
            $obj->setUpdated(new \DateTime());

        //
        if (method_exists($obj, "setCreatedBy"))
            $obj->setCreatedBy($this->user);
        if (method_exists($obj, "setUpdatedBy"))
            $obj->setUpdatedBy($this->user);

        $this->em->persist($obj);

        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($obj);
        }
    }


    /**
     * This function takes the id of an otherwise empty datatype in its "create" phase, and creates
     * its datafields, render plugins, themes, child/linked datatypes, and permissions by cloning
     * them from its "master template".
     *
     * @param integer $datatype_id
     * @param integer $user_id
     * @param string $template_group
     *
     * @return string
     */
    public function createDatatypeFromMaster($datatype_id, $user_id, $template_group = "")
    {
        try {
            // This function waits a long time and tends to time out and drop its db connection
            // https://stackoverflow.com/questions/16233835/refresh-the-database-connection-if-connection-drops-or-times-out
            if(FALSE == $this->em->getConnection()->ping()){
                $this->logger->debug('----------------------------------------');
                $this->logger->debug('MySQL connection was closed: ' . $template_group . ' - reconnecting.');
                $this->em->getConnection()->close();
                $this->em->getConnection()->connect();
                $this->logger->debug('----------------------------------------');
            }

            // Save which user started this creation process
            $this->user = $this->user_manager->findUserBy( array('id' => $user_id) );
            if ( is_null($this->user) )
                throw new ODRNotFoundException('User');

            $this->logger->debug('----------------------------------------');
            $this->logger->debug('CloneDatatypeService: entered createDatatypeFromMaster(' . $template_group . '), user '.$user_id.' is attempting to clone a datatype');

            // Get the DataType to work with
            $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');

            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ( is_null($datatype) )
                throw new ODRNotFoundException('Datatype');

            // Check if datatype is not in "initial" mode
            if ($datatype->getSetupStep() != DataType::STATE_INITIAL)
                throw new ODRException("Datatype is not in the correct setup mode.  Setup step was: ".$datatype->getSetupStep());

            if ( is_null($datatype->getMasterDataType()) || $datatype->getMasterDataType()->getId() < 1 ) {
                throw new ODRException("Invalid master template id");
            }


            // ----------------------------------------
            // Save which datatype this creation process was originally started on
            $this->original_datatype = $datatype;
            $datatype_prefix = $datatype->getShortName();

            // Get all grandparent datatype ids that need cloning...
            $this->master_datatype = $datatype->getMasterDataType();
            $this->logger->debug('CloneDatatypeService: Master Datatype ID:' . $this->master_datatype->getId());

            $include_links = true;
            $datatype_data = $this->dti_service->getDatatypeArray($this->master_datatype->getId(), $include_links);
            $grandparent_datatype_ids = array_keys($datatype_data);
            $this->logger->debug('CloneDatatypeService: $grandparent_datatype_ids: '.print_r($grandparent_datatype_ids, true));

            // Load all children of these grandparent datatypes
            $query = $this->em->createQuery(
               'SELECT dt.id AS dt_id
                FROM ODRAdminBundle:DataType AS dt
                WHERE dt.grandparent IN (:grandparent_ids)
                AND dt.deletedAt IS NULL'
            )->setParameters( array('grandparent_ids' => $grandparent_datatype_ids) );
            $results = $query->getArrayResult();

            $associated_datatypes = array();
            foreach ($results as $result)
                $associated_datatypes[] = $result['dt_id'];
            $this->logger->debug('CloneDatatypeService: $associated_datatypes: '.print_r($associated_datatypes, true));

            // Remove linked datatypes that already exist in template group
            $this->existing_datatypes = array(); // $repo_datatype->findBy(array('template_group' => $template_group));
            if($template_group !== "" && strlen($template_group) > 0) {
                $this->existing_datatypes = $repo_datatype->findBy(
                    array(
                        'template_group' => $template_group,
                        'metadata_for' => null,
                        'metadata_datatype' => null
                    )
                );
                // Need to determine which existing match the needed master types...
                $valid_existing_datatypes = array();
                /** @var DataType $dt */
                foreach($this->existing_datatypes as $dt) {
                    if (
                        $dt->getMasterDataType() !== null // We're only dealing with master types
                        && $dt->getId() !== $datatype->getId() // Always allow the initial request to go through
                        && ($key = array_search($dt->getMasterDataType()->getId(), $associated_datatypes)) !== false
                    ) {
                        array_push($valid_existing_datatypes, $dt);
                        unset($associated_datatypes[$key]);
                    }
                }
                $this->existing_datatypes = $valid_existing_datatypes;
            }
            $this->logger->debug('CloneDatatypeService: $associated_datatypes [filtered]: '.print_r($associated_datatypes, true));

            // Save this associated datatypes for later use
            $this->associated_datatypes = $associated_datatypes;

            // Clone the master template datatype, and all its linked/child datatypes as well
            $this->created_datatypes = array();
            foreach ($associated_datatypes as $dt_id) {
                $this->logger->info('----------------------------------------');
                $new_datatype = null;
                $dt_master = null;

                if ($dt_id == $this->master_datatype->getId()) {
                    // This is the master template datatype...the $datatype that was created back in
                    // DatatypeController::addAction() should become a copy of this master template
                    $new_datatype = $datatype;
                    $dt_master = $this->master_datatype;

                    $this->logger->debug('CloneDatatypeService: attempting to clone master datatype '.$this->master_datatype->getId().' "'.$this->master_datatype->getShortName().'" into datatype '.$new_datatype->getId());
                }
                else {
                    // This is one of the child/linked datatypes of the master template...need to
                    //  create a new datatype based off of it

                    /** @var DataType $dt_master */
                    $dt_master = $repo_datatype->find($dt_id);
                    if ( is_null($dt_master) )
                        throw new ODRException('Unable to clone the deleted Datatype '.$dt_id);

                    $this->logger->debug('CloneDatatypeService: attempting to clone master datatype '.$dt_id.' "'.$dt_master->getShortName().'" into new datatype...');
                }

                // Clone the datatype $dt_master into $new_datatype
                self::cloneDatatype($dt_master, $new_datatype, $datatype_prefix, $template_group);
            }


            // ----------------------------------------
            // For convenience, define an array where the keys are ids of the master template
            //  datatypes, and the values are the new datatypes cloned from the master template
            $this->logger->info('----------------------------------------');
            $this->dt_mapping = array($this->original_datatype->getId() => $this->original_datatype);    // TODO - why does $dt_mapping contain this?
            // $this->dt_mapping = array($this->original_datatype->getMasterDataType()->getId() => $this->original_datatype);    // TODO - why does $dt_mapping contain this?

            // This creates the dt_mapping array
            foreach ($this->created_datatypes as $dt)
                $this->dt_mapping[ $dt->getMasterDataType()->getId() ] = $dt;

            $dt_str = '';
            foreach ($this->dt_mapping as $dt_id => $dt)
                $dt_str .= '['.$dt_id.'] => '.$dt->getId().'  ';
            $this->logger->debug('CloneDatatypeService: $this->datatype_mapping: '.$dt_str);

            // TODO Like to get rid of this
            $df_str = '';
            foreach ($this->df_mapping as $df_id => $df)
                $df_str .= '['.$df_id.'] => '.$df->getId().'  ';
            $this->logger->debug('CloneDatatypeService: $this->datafield_mapping: '.$df_str);


            // ----------------------------------------
            // Now that the datatypes are created, ensure their parent/grandparent datatype entries
            //  are properly set...couldn't do it in self::cloneDatatype() because they might have
            //  been created out of order, and cloning themes requires them to be properly set...
            $this->logger->info('----------------------------------------');

            foreach ($this->created_datatypes as $dt) {
                $corrected_parent = $this->dt_mapping[ $dt->getParent()->getId() ];
                $corrected_grandparent = $this->dt_mapping[ $dt->getGrandparent()->getId() ];

                $dt->setParent($corrected_parent);
                $dt->setGrandparent($corrected_grandparent);
                $this->em->persist($dt, true);

                $this->logger->info('CloneDatatypeService: correcting ancestors for datatype '.$dt->getId().' "'.$dt->getShortName().'"...parent set to dt '.$corrected_parent->getId().', grandparent set to dt '.$corrected_grandparent->getId());
            }

            // TODO Let's make this the first flush.... Or possibly defer to later...
            $this->em->flush();


            // TODO Add removed linked types to associated, dt_mapping, and created_datatypes
            // to resume creation of datatype.
            foreach($this->existing_datatypes as $dt) {
                array_push($this->created_datatypes, $dt);
            }
            // This creates the dt_mapping array
            foreach ($this->created_datatypes as $dt)
                $this->dt_mapping[ $dt->getMasterDataType()->getId() ] = $dt;

            // ----------------------------------------
            // Clone all themes for this master template...
            $this->logger->info('----------------------------------------');
            self::cloneTheme($this->master_datatype);


            // ----------------------------------------
            // Clone Datatree and DatatreeMeta entries
            $this->logger->info('----------------------------------------');
            // TODO Convert to delayed flush and flush at once
            self::cloneDatatree($this->master_datatype);

            foreach ($this->created_datatypes as $dt)
                $this->em->refresh($dt);


            // ----------------------------------------
            // Create all of the Group entries required for cloning permissions
            $this->logger->info('----------------------------------------');
            foreach ($this->created_datatypes as $dt)
                // TODO Convert to delayed flush and flush at once
                self::cloneDatatypeGroups($dt);


            // Flush so groups are available for next portion.
            $this->em->flush();

            // Clone the datatype and datafield permissions for each of the created datatypes
            $this->logger->info('----------------------------------------');
            foreach ($this->created_datatypes as $dt) {
                $this->logger->info('----------------------------------------');
                // TODO Convert to delayed flush and flush at once
                self::cloneDatatypePermissions($dt);

                /** @var DataFields[] $datafields */
                $datafields = $dt->getDataFields();
                foreach ($datafields as $df)
                    // TODO Convert to delayed flush and flush at once
                    self::cloneDatafieldPermissions($df);
            }


            // ----------------------------------------
            // The datatypes are now ready for viewing since they have all their datafield, theme,
            //  datatree, and various permission entries
            foreach ($this->created_datatypes as $dt) {
                $dt->setSetupStep(DataType::STATE_OPERATIONAL);

                // These don't need to be immediately flushed...
                $this->em->persist($dt);
            }

            $this->em->flush();


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

            // Also wipe cached entry for all affected users...should typically just be super
            //  admins and whoever created the datatype
            foreach ($user_list as $user_id => $num)
                $this->cache_service->delete('user_'.$user_id.'_permissions');


            // ----------------------------------------
            $this->logger->info('----------------------------------------');
            $this->logger->info('CloneDatatypeService: cloning of datatype '.$datatype->getId().' is complete');
            $this->logger->info('----------------------------------------');

            return 'complete';
        }
        catch (\Exception $e) {
            $this->logger->debug('CLONE DT EXCEPTION: '.$e->getMessage());
            return $e->getMessage();
        }
    }


    /**
     * Clones the provided $parent_datatype into a new database entry
     *
     * @param DataType $parent_datatype A datatype with isMasterType=1 from which the datatype will be cloned.
     * @param DataType|null $new_datatype
     * @param string $datatype_prefix
     * @param string $template_group
     */
    private function cloneDatatype($parent_datatype, $new_datatype = null, $datatype_prefix = "", $template_group = "")
    {
        // If $new_dataype isn't created yet, clone $parent_datatype to use as a starting point
        if ( is_null($new_datatype) ) {
            $new_datatype = clone $parent_datatype;
            // All clones from parent need new UUIDs
            $unique_id = $this->dti_service->generateDatatypeUniqueId();
            $new_datatype->setUniqueId($unique_id);
        }

        if ($new_datatype->getUniqueId() == null) {
            // Set a unique ID if this is a clone - existing DT should already have one.
            $unique_id = $this->dti_service->generateDatatypeUniqueId();
            $new_datatype->setUniqueId($unique_id);
        }

        // $new_datatype is based off a "master template" datatype
        $new_datatype->setIsMasterType(false);
        $new_datatype->setTemplateGroup($template_group);
        $new_datatype->setMasterDataType($parent_datatype);
        $new_datatype->setSetupStep(DataType::STATE_INITIAL);

        self::persistObject($new_datatype, true);

        array_push($this->created_datatypes, $new_datatype);

        $this->logger->debug('CloneDatatypeService: datatype '.$new_datatype->getId().' using datatype '.$parent_datatype->getId().' as its master template...');

        $parent_meta = $parent_datatype->getDataTypeMeta();
        // Meta might already exist - need to copy relevant fields and delete
        $existing_meta = $new_datatype->getDataTypeMeta();  // NOTE - if $new_datatype doesn't have a meta entry this will be false, NOT null

        $new_meta = clone $parent_meta;
        $new_meta->setDataType($new_datatype);
        if ($existing_meta) {
            // $existing_meta was created back in DatatypeController::addAction()

            // Copy the properties from the existing DatatypeMeta entry into the cloned entry
            $new_meta->setShortName($existing_meta->getShortName());

            // All cloned datatypes get a unique id which can be used as search slug
            $new_meta->setSearchSlug($new_datatype->getUniqueId());
            $new_meta->setLongName($existing_meta->getLongName());
            $new_meta->setDescription($existing_meta->getDescription());

            // Ensure the "in-memory" version of $new_datatype doesn't references the old meta entry
            // TODO - I don't think this makes sense now....
            $new_datatype->removeDataTypeMetum($existing_meta);     // TODO - investigate whether this helps or hurts
        }
        else {
            // All cloned datatypes get a unique id which can be used as search slug
            $new_meta->setSearchSlug($new_datatype->getUniqueId());
        }


        // Use a prefix if short name not equal prefix
        if ($new_meta->getShortName() != $datatype_prefix)
            $new_meta->setLongName($datatype_prefix." - ".$new_meta->getShortName());

        // Track the published version
        $new_meta->setMasterRevision(0);
        $new_meta->setMasterPublishedRevision(0);

        /** @var DataTypeMeta $temp_parent_meta */
        $temp_parent_meta = $parent_datatype->getDataTypeMeta();
        if ( !$temp_parent_meta || is_null($temp_parent_meta->getMasterPublishedRevision()) )
            $new_meta->setTrackingMasterRevision(-100);
        else
            $new_meta->setTrackingMasterRevision($parent_datatype->getDataTypeMeta()->getMasterPublishedRevision());

        // Preserve the Render Plugin
        $parent_render_plugin = $parent_meta->getRenderPlugin();
        $new_meta->setRenderPlugin($parent_render_plugin);

        // Ensure the "in-memory" version of $new_datatype knows about its meta entry
        $new_datatype->addDataTypeMetum($new_meta);
        self::persistObject($new_meta, true);
        $this->logger->debug('CloneDatatypeService: meta entry cloned for datatype '.$new_datatype->getId());

        // ----------------------------------------
        // Process data fields so themes and render plugin map can be created
        /** @var DataFields[] $parent_df_array */
        $parent_df_array = $parent_datatype->getDataFields();
        foreach ($parent_df_array as $parent_df) {
            // Copy over all of the parent datatype's datafields
            $new_df = clone $parent_df;
            $new_df->setDataType($new_datatype);
            $new_df->setIsMasterField(false);
            $new_df->setMasterDataField($parent_df);

            // Copy old unique id to template_field_uuid
            $new_df->setTemplateFieldUuid($new_df->getFieldUuid());
            // Assign new uuid
            $new_df->setFieldUuid($this->dti_service->generateDataFieldUniqueId());

            // Ensure the "in-memory" version of $new_datatype knows about the new datafield
            $new_datatype->addDataField($new_df);
            self::persistObject($new_df, true);

            // This is the field map
            $this->df_mapping[ $parent_df->getId() ] = $new_df;

            $this->logger->info('CloneDatatypeService: copied master datafield '.$parent_df->getId().' "'.$parent_df->getFieldName().'" into new datafield '.$new_df->getId());

            // Need to update datatype meta to point to correct fields
            // Set the DatatypeMeta External ID field
            if(
                $parent_meta->getExternalIdField() != null
                && $parent_df->getId() == $parent_meta->getExternalIdField()->getId()
            ) {
                // This is the new external ID
                $new_meta->setExternalIdField($new_df);
            }

            if(
                $parent_meta->getNameField() != null
                && $parent_df->getId() == $parent_meta->getNameField()->getId()
            ) {
                // This is the new external ID
                $new_meta->setNameField($new_df);
            }

            if(
                $parent_meta->getSortField() != null
                && $parent_df->getId() == $parent_meta->getSortField()->getId()
            ) {
                // This is the new external ID
                $new_meta->setSortField($new_df);
            }

            if(
                $parent_meta->getBackgroundImageField() != null
                && $parent_df->getId() == $parent_meta->getBackgroundImageField()->getId()
            ) {
                // This is the new external ID
                $new_meta->setBackgroundImageField($new_df);
            }

            // Process Meta Records
            $parent_df_meta = $parent_df->getDataFieldMeta();
            // Check if object
            if ($parent_df_meta) {
                // TODO - why does this not exist sometimes?  failed migration?  error elsewhere?
                $new_df_meta = clone $parent_df_meta;
                $new_df_meta->setDataField($new_df);
                $new_df_meta->setMasterRevision(0);
                $new_df_meta->setMasterPublishedRevision(0);
                $new_df_meta->setTrackingMasterRevision($parent_df_meta->getMasterPublishedRevision());

                // Ensure the "in-memory" version of $new_df knows about the new meta entry
                $new_df->addDataFieldMetum($new_df_meta);
                self::persistObject($new_df_meta, true);

                $this->logger->debug('CloneDatatypeService: -- meta entry cloned for datafield '.$new_df->getId());
            }

            // Need to process Radio Options....
            /** @var RadioOptions[] $parent_ro_array */
            $parent_ro_array = $parent_df->getRadioOptions();
            if ( count($parent_ro_array) > 0 ) {
                foreach ($parent_ro_array as $parent_ro) {
                    // Clone all the radio options for this datafield
                    $new_ro = clone $parent_ro;
                    $new_ro->setDataField($new_df);

                    // Ensure the "in-memory" version of $new_df knows about its new radio option
                    $new_df->addRadioOption($new_ro);
                    self::persistObject($new_ro, true);

                    // Also clone the radio option's meta entry
                    $parent_ro_meta = $parent_ro->getRadioOptionMeta();
                    $new_ro_meta = clone $parent_ro_meta;
                    $new_ro_meta->setRadioOption($new_ro);

                    // Ensure the "in-memory" version of $new_ro knows about its meta entry
                    $new_ro->addRadioOptionMetum($new_ro_meta);
                    self::persistObject($new_ro_meta, true);

                    $this->logger->debug('CloneDatatypeService: -- cloned radio option '.$parent_ro->getId().' "'.$new_ro->getOptionName().'" and its meta entry');
                }
            }

            // Persist the DataType metadata changes (after field remapping fixes)
            self::persistObject($new_meta, true);

            // Copy any render plugin settings for this datafield from the master template
            self::cloneRenderPluginSettings($parent_df->getRenderPlugin(), null, $new_df);
        }

        // The datafields are now created...
        // If the parent datatype has a render plugin, copy its settings as well
        self::cloneRenderPluginSettings($parent_datatype->getRenderPlugin(), $new_datatype);
    }


    /**
     * After the Datatype, Datafield, Radio Option, and any RenderPlugin settings are cloned, the
     * Theme stuff from the master template needs to be cloned too...
     */
    private function cloneTheme(DataType $master_datatype)
    {
        // Need to store each theme that got created...
        /** @var Theme[] $results */
        $query = $this->em->createQuery(
            'SELECT t
            FROM ODRAdminBundle:Theme AS t
            WHERE t.dataType IN (:datatype_ids) AND t = t.parentTheme
            AND t.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $this->associated_datatypes) );
        // )->setParameters( array('datatype_ids' => [$master_datatype->getId()]) );
        $results = $query->getResult();

        foreach ($results as $t) {
            // TODO If we don't flush the theme, we can reassign datatypes before committing
            $new_theme = $this->
            clone_theme_service->
            cloneSourceTheme(
                $this->user,
                $t,
                $t->getThemeType(),
                $this->dt_mapping,
                $this->df_mapping,
                true
            );

            $new_parent_themes[] = $new_theme;
        }

        /** @var Theme[] $new_parent_themes */
        foreach($new_parent_themes as $t) {
            /** @var Theme $t */
            // Go through theme and fix datatype ids to new datatype ids
            // These should all be same parent?
            self::correctThemeData($t, $t, $t->getSourceTheme());
            // Persist after correction
            self::persistObject($t, true);
        }

        // Flush to commit theme fixes...
        $this->em->flush();

    }

    private function correctThemeData(Theme $theme, Theme $parent_theme, Theme $source_theme) {
        $this->logger->info('Theme analysis... ');
        $this->logger->info('Theme type: ' . $theme->getThemeType());
        $this->logger->info('Theme ID: ' . $theme->getId());
        $this->logger->info('Theme Source: ' . $theme->getSourceTheme()->getId());
        $this->logger->info('Theme Name: ' . $theme->getThemeMeta()->getTemplateName());
        $this->logger->info('Theme Datatype: ' . $theme->getDataType()->getId());

        // Correct the Datatype ID if needed
        /*
        $corrected_dt = $this->dt_mapping[$theme->getDataType()->getId()];
        $this->dt_mapping[$corrected_dt->getId()] = $corrected_dt;
        $theme->setDataType($corrected_dt);
        $this->logger->info('        Theme Corrected Datatype ID: ' . $corrected_dt->getId());
        */


        // Set source and parent
        $theme->setParentTheme($parent_theme);
        /*
        $theme->setSourceTheme($source_theme);
        */

        /** @var ThemeElement[] $te_array */
        $te_array = $theme->getThemeElements();
        foreach($te_array as $te) {
            /** @var ThemeElement $te */
            self::correctThemeElement($theme, $parent_theme, $te);
        }
        // Save changes to object
        // self::persistObject($theme, true);
        // $this->logger->info('Persisting Theme Changes ID: ' . $theme->getId());
    }

    private function correctThemeElement(Theme $theme, Theme $parent_theme, ThemeElement $te) {
        /** @var ThemeElement $te */
        $this->logger->info('    Theme Element ID: ' . $te->getId());
        $this->logger->info('    Theme Element Meta ID: ' . $te->getThemeElementMeta()->getId());

//        /** @var ThemeDataField[] $tdf_array */
//        $tdf_array = $te->getThemeDataFields();
//        foreach($tdf_array as $tdf) {
//            /** @var ThemeDataField $tdf */
//            self::correctThemeDataField($tdf);
//        }


        /** @var ThemeDataType[] $tdt_array */
        $tdt_array = $te->getThemeDataType();
        foreach($tdt_array as $tdt) {
            // NOTE Parent theme is actually grandparent theme.
            // Using $theme results in a tree structure as opposed to the
            // flat grandparent theme structure.
            /** @var ThemeDataType $tdt */
            self::correctThemeDataType($parent_theme, $tdt);
        }

    }

    private function correctThemeDataField(ThemeDataField $tdf) {
        $this->logger->info('        Theme Data Field ID: ' . $tdf->getId());
        $this->logger->info('        Theme Data Field -> Field ID: ' . $tdf->getDataField()->getId());

        $corrected_df = $this->df_mapping[$tdf->getDataField()->getId()];
        $this->df_mapping[$corrected_df->getId()] = $corrected_df;
        $tdf->setDataField($corrected_df);
        $this->logger->info('        Theme Data Field -> Corrected Field ID: ' . $corrected_df->getId());
    }

    private function correctThemeDataType(Theme $parent_theme, ThemeDataType $tdt) {
        // May need to fix theme
        $this->logger->info('        Theme Data Type ID: ' . $tdt->getId());
        $this->logger->info('        Theme Data Type DT ID: ' . $tdt->getDataType()->getId());

        /*
        $corrected_dt = $this->dt_mapping[$tdt->getDataType()->getId()];
        $this->dt_mapping[$corrected_dt->getId()] = $corrected_dt;
        $tdt->setDataType($corrected_dt);
        $this->logger->info('        Theme Data Type Corrected DT ID: ' . $corrected_dt->getId());
        */

        self::correctThemeData($tdt->getChildTheme(), $parent_theme, $tdt->getChildTheme()->getSourceTheme());
    }

    /**
     * Once the theme stuff from the master template and its children are fully cloned, the
     * datatree entries describing parent/child datatype relations also need to be cloned...
     *
     * @param DataType $parent_datatype
     */
    private function cloneDatatree($parent_datatype)
    {
        $this->logger->info('CloneDatatypeService: attempting to clone datatree entries for datatype '.$parent_datatype->getId().' "'.$parent_datatype->getShortName().'"...');

        /** @var DataTree[] $datatree_array */
        $datatree_array = $this->em->getRepository('ODRAdminBundle:DataTree')->findBy( array('ancestor' => $parent_datatype->getId()) );
        if ( empty($datatree_array) )
            $this->logger->debug('CloneDatatypeService: -- no datatree entries found');

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
                    // ...create a Datatree entry to set up the relationship
                    $new_dt = new DataTree();
                    $new_dt->setAncestor($current_ancestor);
                    $new_dt->setDescendant($datatype);
                    self::persistObject($new_dt);

                    // Clone the datatree's meta entry
                    $new_meta = clone $datatree->getDataTreeMeta();
                    $new_meta->setDataTree($new_dt);
                    self::persistObject($new_meta);

                    $new_dt->addDataTreeMetum($new_meta);
                    self::persistObject($new_dt);

                    $this->logger->info('CloneDatatypeService: -- created new datatree with datatype '.$current_ancestor->getId().' "'.$current_ancestor->getShortName().'" as ancestor and datatype '.$datatype->getId().' "'.$datatype->getShortName().'" as descendant, is_link = '.$new_meta->getIsLink());
                    // Also create any datatree entries required for this newly-created datatype
                    self::cloneDatatree($datatype->getMasterDataType());
                }
            }
        }
    }


    /**
     * Clones all Group entries for top-level datatypes for the purposes of permissions.
     * Datatree entries are assumed to already exist.
     *
     * @param Datatype $datatype
     */
    private function cloneDatatypeGroups($datatype)
    {
        // Ensure this is a top-level datatype before doing anything...child datatypes use their
        //  grandparent datatype's groups instead of having their own

        /** @var DataTree[] $datatree_array */
        $datatree_array = $this->em->getRepository('ODRAdminBundle:DataTree')->findBy( array('descendant' => $datatype->getId()) );
        foreach ($datatree_array as $datatree) {
            if ($datatree->getDataTreeMeta()->getIsLink() == 0) {
                // This datatype is a child of some other datatype...do NOT create any groups for it
                return;
            }
        }

        // Load all groups from this datatype's master
        $master_datatype = $datatype->getMasterDataType();
        $this->logger->info('CloneDatatypeService: attempting to clone group entries for datatype '.$datatype->getId().' "'.$datatype->getShortName().'" from master datatype '.$master_datatype->getId().'...');

        /** @var Group[] $master_groups */
        $master_groups = $master_datatype->getGroups();
        if ( is_null($master_groups) )
            throw new ODRException('CloneDatatypeService: Master Datatype '.$master_datatype->getId().' has no group entries to clone.');

        // Clone all of the master datatype's groups
        foreach ($master_groups as $master_group) {
            $new_group = clone $master_group;
            $new_group->setDataType($datatype);

            // Ensure the "in-memory" version of $datatype knows about the new group
            $datatype->addGroup($new_group);
            self::persistObject($new_group);

            // Store that a group was created
            $this->created_groups[] = $new_group;

            // ...also needs the associated group meta entry
            $parent_group_meta = $master_group->getGroupMeta();
            $new_group_meta = clone $parent_group_meta;
            $new_group_meta->setGroup($new_group);

            // Ensure the "in-memory" version of $new_group knows about its new meta entry
            $new_group->addGroupMetum($new_group_meta);
            self::persistObject($new_group_meta);

            $this->logger->info('CloneDatatypeService: created new Group '.$new_group->getId().' from parent "'.$master_group->getPurpose().'" Group '.$master_group->getId().' for datatype '.$datatype->getId());

            // If an admin group got created, then all super-admins need to be added to it
            if ($new_group->getPurpose() == "admin") {
                /** @var ODRUser[] $user_list */
                $user_list = $this->user_manager->findUsers();    // disabled users set to ROLE_USER, so doesn't matter if they're in the list

                // Locate those with super-admin permissions...
                foreach ($user_list as $u) {
                    if ( $u->hasRole('ROLE_SUPER_ADMIN') ) {
                        // ...add the super admin to this new admin group
                        $this->pm_service->createUserGroup($u, $new_group, $this->user, true);    // These don't need to be flushed/refreshed immediately...
                        $this->logger->debug('-- added super_admin user '.$u->getId().' to admin group');

                        // Don't bother deleting this user's cached permissions here...
                        // There's no guarantee they won't access the datatype before all the
                        //  permissions are ready anyways.
                    }
                }

                // If the user isn't a super-admin, then add them to the admin group as well...
                // ...otherwise, they won't be able to see the new datatype either
                if (!$this->user->hasRole('ROLE_SUPER_ADMIN')) {
                    $this->pm_service->createUserGroup($this->user, $new_group, $this->user, true);    // These don't need to be flushed/refreshed immediately...
                    $this->logger->debug('-- added user '.$this->user->getId().' to admin group');

                    // If the user's cached permissions were deleted here, the user would likely
                    //  get a stale/incomplete version when accessing the datatype later on
                }
            }

            // TODO No reason to flush here....
            $this->em->flush();

            // Don't need to delete cached permissions for any other users or groups...nobody
            //  belongs to them yet
        }
    }


    /**
     * Clones all GroupDatatype permission entries from $datatype's master template.
     * The Group entries are assumed to already exist.
     *
     * @param Datatype $datatype
     */
    private function cloneDatatypePermissions($datatype)
    {
        // Load all datatype permission entries for this datatype's master template
        $master_datatype = $datatype->getMasterDataType();
        $this->logger->info('CloneDatatypeService: attempting to clone datatype permission entries for datatype '.$datatype->getId().' "'.$datatype->getShortName().'" from master datatype '.$master_datatype->getId().'...');

        /** @var GroupDatatypePermissions[] $master_gdt_permissions */
        $master_gdt_permissions = $master_datatype->getGroupDatatypePermissions();
        if ( is_null($master_gdt_permissions) )
            throw new ODRException('CloneDatatypeService: Master Datatype '.$master_datatype->getId().' has no permission entries to clone.');


        // NOTE - can't use $this->dti_service->getGrandparentDatatypeId() for this...the functions
        //  that rely on that service assume that they're only dealing with datatypes that are
        //  functional, and this datatype is currently still incomplete

        // Going to need this datatype's grandparent...
        $repo_datatree = $this->em->getRepository('ODRAdminBundle:DataTree');
        $grandparent_datatype_id = $datatype->getId();

        $datatree_array = array();
        // TODO Seriously bad.
        do {
            //
            $is_child = false;

            /** @var DataTree[] $datatree_array */
            $datatree_array = $repo_datatree->findBy( array('descendant' => $grandparent_datatype_id) );
            foreach ($datatree_array as $datatree) {
                if ($datatree->getDataTreeMeta()->getIsLink() == 0) {
                    $is_child = true;
                    $grandparent_datatype_id = $datatree->getAncestor()->getId();
                }
            }

            // If a datatype links to this datatype, then $datatree_array will never be empty...
            // ...exit the loop so it doesn't continue infinitely
            if (!$is_child)
                break;

        } while ( count($datatree_array) > 0 );


        // Get all groups for this datatype's grandparent
        /** @var Group[] $grandparent_groups */
        $grandparent_groups = $this->em->getRepository('ODRAdminBundle:Group')->findBy( array('dataType' => $grandparent_datatype_id) );
        if ( is_null($grandparent_groups) )
            throw new ODRException('CloneDatatypeService: Grandparent Datatype '.$grandparent_datatype_id.' has no group entries');


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

                    // Ensure the "in-memory" versions of both the group and the new datatype know
                    // about this new permission entry
                    $group->addGroupDatatypePermission($new_permission);
                    $datatype->addGroupDatatypePermission($new_permission);
                    self::persistObject($new_permission, true);    // These don't need to be flushed/refreshed immediately...

                    $this->logger->debug('CloneDatatypeService: -- cloned GroupDatatypePermission entry from master template Group '.$master_group->getId().' to Group '.$group->getId().' for new datatype '.$datatype->getId());
                }
            }
        }

        // TODO No reason to flush here....
        $this->em->flush();
    }


    /**
     * Clones all GroupDatafield permission entries from $datafield's master datafield.
     * Group entries are already assumed to exist.
     *
     * @param DataFields $datafield
     */
    private function cloneDatafieldPermissions($datafield)
    {
        // Pull up all the datafield permission entries for all the groups this datafield's master template belongs to
        $master_datafield = $datafield->getMasterDataField();
        $this->logger->debug('CloneDatatypeService: attempting to clone datafield permission entries for datafield '.$datafield->getId().' "'.$datafield->getFieldName().'" from master datafield '.$master_datafield->getId().'...');

        /** @var GroupDatafieldPermissions[] $master_gdf_permissions */
        $master_gdf_permissions = $master_datafield->getGroupDatafieldPermissions();
        if ( is_null($master_gdf_permissions) )
            throw new ODRException('CloneDatatypeService: Master Datafield '.$master_datafield->getId().' has no permission entries to clone.');


        // NOTE - can't use $this->dti_service->getGrandparentDatatypeId() for this...the functions
        //  that rely on that service assume that they're only dealing with datatypes that are
        //  functional, and this datatype is currently still incomplete

        // Going to need this datatype's grandparent...
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
                if ($datatree->getDataTreeMeta()->getIsLink() == 0) {
                    $is_child = true;
                    $grandparent_datatype_id = $datatree->getAncestor()->getId();
                }
            }

            // If a datatype links to this datatype, then $datatree_array will never be empty...
            // ...exit the loop so it doesn't continue infinitely
            if (!$is_child)
                break;

        } while ( count($datatree_array) > 0 );


        // Get all groups for this datatype's grandparent
        /** @var Group[] $grandparent_groups */
        $grandparent_groups = $this->em->getRepository('ODRAdminBundle:Group')->findBy( array('dataType' => $grandparent_datatype_id) );
        if ( is_null($grandparent_groups) )
            throw new ODRException('CloneDatatypeService: Grandparent Datatype '.$grandparent_datatype_id.' has no group entries');


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

                    // Ensure the "in-memory" versions of both the group and the new datafield know
                    //  about this new permission
                    $group->addGroupDatafieldPermission($new_permission);
                    $datafield->addGroupDatafieldPermission($new_permission);
                    self::persistObject($new_permission, true);    // These don't need to be flushed/refreshed immediately...

                    $this->logger->debug('CloneDatatypeService: -- cloned GroupDatafieldPermission entry from master template Group '.$master_group->getId().' to Group '.$group->getId().' for new datafield '.$datafield->getId());
                }
            }
        }

        // TODO No reason to flush here....
        $this->em->flush();
    }


    /**
     * Given a Datatype or Datafield, completely clone all the relevant information for its
     * render plugin, assuming it's currently using one.
     *
     * @param RenderPlugin|null $parent_render_plugin
     * @param DataType|null $datatype
     * @param DataFields|null $datafield
     */
    private function cloneRenderPluginSettings($parent_render_plugin, $datatype = null, $datafield = null)
    {
        // Don't need to clone anything if using the default render plugin
        if ( is_null($parent_render_plugin) || $parent_render_plugin->getPluginClassName() == 'odr_plugins.base.default')
            return;

        $repo_rpi = $this->em->getRepository('ODRAdminBundle:RenderPluginInstance');
        $parent_rpi = null;

        if ( !is_null($datatype) ) {
            $master_datatype = $datatype->getMasterDataType();

            $this->logger->info('CloneDatatypeService: attempting to clone settings for render plugin '.$parent_render_plugin->getId().' "'.$parent_render_plugin->getPluginName().'" in use by master datatype '.$master_datatype->getId());
            $parent_rpi = $repo_rpi->findOneBy( array('dataType' => $master_datatype->getId(), 'renderPlugin' => $parent_render_plugin->getId()) );
        }
        else {
            $master_datafield = $datafield->getMasterDataField();

            $this->logger->info('CloneDatatypeService: -- attempting to clone settings for render plugin '.$parent_render_plugin->getId().' "'.$parent_render_plugin->getPluginName().'" in use by master datafield '.$master_datafield->getId());
            $parent_rpi = $repo_rpi->findOneBy( array('dataField' => $master_datafield->getId(), 'renderPlugin' => $parent_render_plugin->getId()) );
        }
        /** @var RenderPluginInstance $parent_rpi */

        if ( !is_null($parent_rpi) ) {
            // If the parent datatype/datafield is using a render plugin, then clone that instance
            $new_rpi = clone $parent_rpi;
            $new_rpi->setDataType($datatype);
            $new_rpi->setDataField($datafield);
            self::persistObject($new_rpi);

            $df_id = 'NULL';
            if ( !is_null($datafield) )
                $df_id = $datafield->getId();
            $dt_id = 'NULL';
            if ( !is_null($datatype) )
                $dt_id = $datatype->getId();
            $this->logger->debug('CloneDatatypeService: -- -- cloned render_plugin_instance '.$parent_rpi->getId().', set datafield to '.$df_id.' and set datatype to '.$dt_id);

            // Clone each option for this instance of the render plugin
            /** @var RenderPluginOptions[] $parent_rpo_array */
            $parent_rpo_array = $parent_rpi->getRenderPluginOptions();
            foreach ($parent_rpo_array as $parent_rpo) {
                $new_rpo = clone $parent_rpo;
                $new_rpo->setRenderPluginInstance($new_rpi);
                self::persistObject($new_rpo, true);    // These don't need to be flushed/refreshed immediately...

                $this->logger->debug('CloneDatatypeService: -- -- cloned render_plugin_option '.$parent_rpo->getId().' "'.$parent_rpo->getOptionName().'" => "'.$parent_rpo->getOptionValue().'"');
            }

            // Clone each datafield that's being used by this instance of the render plugin
            /** @var RenderPluginMap[] $parent_rpm_array */
            $parent_rpm_array = $parent_rpi->getRenderPluginMap();
            foreach ($parent_rpm_array as $parent_rpm) {
                $new_rpm = clone $parent_rpm;
                $new_rpm->setRenderPluginInstance($new_rpi);

                if ( !is_null($datatype) )
                    $new_rpm->setDataType($datatype);       // TODO - if null, then a datafield plugin...but why does it work like that in the first place again?

                // Find the analogous datafield in the new (cloned) datatype
                /** @var DataFields $matching_df */
                $matching_df = $this->df_mapping[ $parent_rpm->getDataField()->getId() ];
                $new_rpm->setDataField($matching_df);
                self::persistObject($new_rpm, true);    // These don't need to be flushed/refreshed immediately...

                $df_id = $matching_df->getId();
                $dt_id = 'NULL';
                if ( !is_null($datatype) )
                    $dt_id = $datatype->getId();
                $this->logger->debug('CloneDatatypeService: -- -- cloned render_plugin_map '.$parent_rpm->getId().', set datafield to '.$df_id.' and set datatype to '.$dt_id);
            }

            // TODO NO FLUSH
            // $this->em->flush();
        }
    }
}
