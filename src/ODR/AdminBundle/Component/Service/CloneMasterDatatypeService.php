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
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginOptionsMap;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\TagTree;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\UserGroup;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatatypeCreatedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use FOS\UserBundle\Model\UserManagerInterface;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


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
    private $clone_master_template_theme_service;

    /**
     * @var DatabaseInfoService
     */
    private $dbi_service;

    /**
     * @var EntityCreationService
     */
    private $ec_service;

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
     * @var int[]
     */
    private $associated_datatypes = array();

    /**
     * @var DataType[]
     */
    private $created_datatypes = array();

    /**
     * @var Group[]
     */
    private $created_groups = array();

    /**
     * @var DataType[]
     */
    private $dt_mapping = array();

    /**
     * @var DataFields[]
     */
    private $df_mapping = array();

    /**
     * @var DataTypeSpecialFields[]
     */
    private $dtsf_mapping = array();

    /**
     * @var RenderPluginInstance[]
     */
    private $rpi_mapping = array();

    /**
     * @var DataType[]
     */
    private $existing_datatypes = array();

    /**
     * @var Theme[]
     */
    private $source_themes = array();


    /**
     * CloneMasterDatatypeService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param CloneMasterTemplateThemeService $clone_master_template_theme_service
     * @param DatabaseInfoService $database_info_service
     * @param EntityCreationService $entity_creation_service
     * @param UUIDService $uuid_service
     * @param UserManagerInterface $user_manager
     * @param EventDispatcherInterface $event_dispatcher
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        CloneMasterTemplateThemeService $clone_master_template_theme_service,
        DatabaseInfoService $database_info_service,
        EntityCreationService $entity_creation_service,
        UUIDService $uuid_service,
        UserManagerInterface $user_manager,
        EventDispatcherInterface $event_dispatcher,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->clone_master_template_theme_service = $clone_master_template_theme_service;
        $this->dbi_service = $database_info_service;
        $this->ec_service = $entity_creation_service;
        $this->uuid_service = $uuid_service;
        $this->user_manager = $user_manager;
        $this->event_dispatcher = $event_dispatcher;
        $this->logger = $logger;

        $this->original_datatype = null;
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
     * @param bool $preserve_template_uuids
     *
     * @return string
     */
    public function createDatatypeFromMaster($datatype_id, $user_id, $template_group, $preserve_template_uuids = true)
    {
        try {
            // This function waits a long time and tends to time out and drop its db connection
            // https://stackoverflow.com/questions/16233835/refresh-the-database-connection-if-connection-drops-or-times-out
            if(FALSE == $this->em->getConnection()->ping()){
                $this->logger->debug('----------------------------------------');
                $this->logger->debug('MySQL connection was closed: '.$template_group.' - reconnecting.');
                $this->em->getConnection()->close();
                $this->em->getConnection()->connect();
                $this->logger->debug('----------------------------------------');
            }

            // Save which user started this creation process
            $this->user = $this->user_manager->findUserBy( array('id' => $user_id) );
            if ( is_null($this->user) )
                throw new ODRNotFoundException('User');

            // Get the DataType to work with
            $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($datatype_id);
            if ( is_null($datatype) )
                throw new ODRNotFoundException('Datatype');

            // Save which datatype this creation process was originally started on
            $this->original_datatype = $datatype;

            $this->logger->debug('----------------------------------------');
            if ( $datatype->getIsMasterType() )
                $this->logger->debug('CloneMasterDatatypeService: entered createDatatypeFromMaster('.$template_group.'), user '.$user_id.' is attempting to copy from the template "'.$datatype->getShortName().'"');
            else
                $this->logger->debug('CloneMasterDatatypeService: entered createDatatypeFromMaster('.$template_group.'), user '.$user_id.' is attempting to clone the datatype "'.$datatype->getShortName().'"');
            if ( $preserve_template_uuids )
                $this->logger->debug('CloneMasterDatatypeService: -- the new datatype WILL keep references to uuids of its source');
            else
                $this->logger->debug('CloneMasterDatatypeService: -- the new datatype WILL NOT keep references to uuids of its source');


            // Check if datatype is not in "initial" mode
            if ($datatype->getSetupStep() != DataType::STATE_INITIAL)
                throw new ODRException("Datatype ".$datatype->getId()." is not in the correct setup mode.  Setup step was: ".$datatype->getSetupStep());

            if ( is_null($datatype->getMasterDataType()) || $datatype->getMasterDataType()->getId() < 1 ) {
                throw new ODRException("Invalid master template id");
            }

            if ( $template_group === '' )
                throw new ODRException('template group can not be empty');


            // ----------------------------------------
            $datatype_prefix = $datatype->getShortName();

            // Get all grandparent datatype ids that need cloning...
            $this->master_datatype = $datatype->getMasterDataType();
            $this->logger->debug('CloneMasterDatatypeService: Master Datatype ID:'.$this->master_datatype->getId());

            $include_links = true;
            $datatype_data = $this->dbi_service->getDatatypeArray($this->master_datatype->getId(), $include_links);
            $grandparent_datatype_ids = array_keys($datatype_data);
            $this->logger->debug('CloneMasterDatatypeService: $grandparent_datatype_ids: '.print_r($grandparent_datatype_ids, true));

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

            $this->logger->debug('CloneMasterDatatypeService: $associated_datatypes: '.print_r($associated_datatypes, true));

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
            $this->logger->debug('CloneMasterDatatypeService: $associated_datatypes [filtered]: '.print_r($associated_datatypes, true));

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

                    $this->logger->debug('CloneMasterDatatypeService: attempting to clone master datatype '.$this->master_datatype->getId().' "'.$this->master_datatype->getShortName().'" into datatype '.$new_datatype->getId());
                }
                else {
                    // This is one of the child/linked datatypes of the master template...need to
                    //  create a new datatype based off of it

                    /** @var DataType $dt_master */
                    $dt_master = $repo_datatype->find($dt_id);
                    if ( is_null($dt_master) )
                        throw new ODRException('Unable to clone the deleted Datatype '.$dt_id);

                    $this->logger->debug('CloneMasterDatatypeService: attempting to clone master datatype '.$dt_id.' "'.$dt_master->getShortName().'" into new datatype...');
                }

                // Clone the datatype $dt_master into $new_datatype
                self::cloneDatatype($dt_master, $new_datatype, $datatype_prefix, $template_group, $preserve_template_uuids);
            }


            // ----------------------------------------
            // For convenience, define an array where the keys are ids of the master template
            //  datatypes, and the values are the new datatypes cloned from the master template
//            $this->logger->info('----------------------------------------');
            $this->dt_mapping = array($this->original_datatype->getId() => $this->original_datatype);    // TODO - why does $dt_mapping contain this?
            // $this->dt_mapping = array($this->original_datatype->getMasterDataType()->getId() => $this->original_datatype);    // TODO - why does $dt_mapping contain this?

            // This creates the dt_mapping array
            foreach ($this->created_datatypes as $dt)
                $this->dt_mapping[ $dt->getMasterDataType()->getId() ] = $dt;


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
                $this->em->persist($dt);

                $this->logger->info('CloneMasterDatatypeService: correcting ancestors for datatype "'.$dt->getShortName().'"...parent set to dt "'.$corrected_parent->getShortName().'", grandparent set to dt "'.$corrected_grandparent->getShortName().'"');
            }

            // Unable to defer a flush any longer
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
            $this->logger->info('----------------------------------------');
            $this->logger->info('CloneMasterDatatypeService: fixing special fields for all cloned datatypes...');

            // Set the special fields for all the newly cloned datatypes, now that all the source
            //  datatypes/datafields have been cloned.  While the name fields could've technically
            //  been updated earlier, the sort fields couldn't because they could've been from a
            //  linked descendant datatype...which isn't guaranteed to exist until shortly before
            //  this point, basically.
            foreach ($this->dtsf_mapping as $original_dtsf_id => $dtsf) {
                // This entry should have the correct datatype already, but it won't have the
                //  correct datafield
                $derived_dt = $dtsf->getDataType();
                $old_df = $dtsf->getDataField();

                // So if we locate its derived counterpart...
                $derived_df = $this->df_mapping[ $old_df->getId() ];
                // ...then we can set this entry to use it
                $dtsf->setDataField($derived_df);

                $field_purpose = 'UNKNOWN_FIELD_PURPOSE';
                if ( $dtsf->getFieldPurpose() === DataTypeSpecialFields::NAME_FIELD )
                    $field_purpose = 'name_field';
                else if ( $dtsf->getFieldPurpose() === DataTypeSpecialFields::SORT_FIELD )
                    $field_purpose = 'sort_field';
                $this->logger->info('CloneMasterDatatypeService: -- copy of dtsf entry '.$original_dtsf_id.' for derived datatype "'.$derived_dt->getShortName().'" set to use derived df "'.$derived_df->getFieldName().'" (from source datatype '.$derived_df->getDataType()->getId().' "'.$derived_df->getDataType()->getShortName().'") as '.$field_purpose.' '.$dtsf->getDisplayOrder());

                // Don't need to flush right this minute, technically
                $this->em->persist($dtsf);
                // Apparently need to unset this, because cloning a template with a metadata
                //  datatype doesn't?  bleh...
                unset( $this->dtsf_mapping[$original_dtsf_id] );
            }


            // ----------------------------------------
            // Clone all themes for this master template...
            $this->logger->info('----------------------------------------');
            // self::cloneTheme($this->master_datatype);
            $this->clone_master_template_theme_service->cloneTheme(
                $this->user,
                $this->dt_mapping,
                $this->df_mapping,
                $this->associated_datatypes,
                $this->rpi_mapping
            );
            $this->logger->info('CloneMasterTemplateThemeService: all themes cloned');

            // ----------------------------------------
            // Clone Datatree and DatatreeMeta entries
            $this->logger->info('----------------------------------------');
            self::cloneDatatree($this->master_datatype);

            // Flush once all the datatree entries are cloned
            $this->em->flush();

            foreach ($this->created_datatypes as $dt)
                $this->em->refresh($dt);


            // ----------------------------------------
            // Create all of the Group entries required for cloning permissions
            $this->logger->info('----------------------------------------');
            foreach ($this->created_datatypes as $dt)
                self::cloneDatatypeGroups($dt);


            // Flush so groups are available for next portion.
            $this->em->flush();

            // Clone the datatype and datafield permissions for each of the created datatypes
            $this->logger->info('----------------------------------------');
            foreach ($this->created_datatypes as $dt) {
                $this->logger->info('----------------------------------------');
                self::cloneDatatypePermissions($dt);

                // Flush once the GroupDatatypePermissions are cloned
                $this->em->flush();

                /** @var DataFields[] $datafields */
                $datafields = $dt->getDataFields();
                foreach ($datafields as $df)
                    self::cloneDatafieldPermissions($df);

                // Don't need to flush immediately after the GroupDatafieldPermissions are cloned
                // Almost done cloning, and nothing needs them at this time
            }

            // ----------------------------------------
            // The datatypes are now ready for viewing since they have all their datafield, theme,
            //  datatree, and various permission entries
            $this->logger->info('----------------------------------------');
            foreach ($this->created_datatypes as $dt) {
                // If not copying from a "master template", then a pile of entities need to be
                //  modified so they don't think they were derived from other entities
                if (!$preserve_template_uuids) {
                    $this->logger->info('----------------------------------------');
                    $this->logger->info('CloneMasterDatatypeService: setting newly created datatype '.$dt->getId().' "'.$dt->getShortName().'" to no longer consider datatype '.$dt->getMasterDataType()->getId().' as its master template');
                    $dt->setMasterDataType(null);

                    /** @var DataFields[] $datafields */
                    $datafields = $dt->getDataFields();
                    foreach ($datafields as $df) {
                        $this->logger->info('CloneMasterDatatypeService: -- setting newly created datafield '.$df->getId().' "'.$df->getFieldName().'" to no longer consider datafield '.$df->getMasterDataField()->getId().' as its master field');
                        $df->setMasterDataField(null);
                        $df->setTemplateFieldUuid(null);

                        $this->em->persist($df);
                    }
                }

                $dt->setSetupStep(DataType::STATE_OPERATIONAL);
                $this->logger->info('CloneMasterDatatypeService: setting newly created datatype '.$dt->getId().' "'.$dt->getShortName().'" to STATE_OPERATIONAL');

                // These don't need to be immediately flushed...
                $this->em->persist($dt);
            }

            $this->em->flush();


            // ----------------------------------------
            // Delete the cached versions of the top-level datatypes and the datatree array
            $this->cache_service->delete('top_level_datatypes');
            $this->cache_service->delete('cached_datatree_array');

            // Locate which users already are members for this datatype's groups...most likely only
            //  going to be the user creating the datatype, but safer to be thorough
            $user_list = array();
            foreach ($this->created_groups as $created_group) {
                /** @var UserGroup[] $user_groups */
                $user_groups = $created_group->getUserGroups();
                foreach ($user_groups as $ug)
                    $user_list[ $ug->getUser()->getId() ] = 1;
            }

            // Need to separately locate all super_admins, since they're going to need permissions
            //  cleared too
            $query = $this->em->createQuery(
               'SELECT u.id AS user_id
                FROM ODROpenRepositoryUserBundle:User AS u
                WHERE u.roles LIKE :role'
            )->setParameters( array('role' => '%ROLE_SUPER_ADMIN%') );
            $results = $query->getArrayResult();

            foreach ($results as $result)
                $user_list[ $result['user_id'] ] = 1;

            // Delete cached permission entries related to this datatype
            foreach ($user_list as $user_id => $num)
                $this->cache_service->delete('user_'.$user_id.'_permissions');


            // ----------------------------------------
            // Fire off a DatatypeCreated event for each new top-level datatype here
            // ...don't need to use the DatatypeImportedEvent, because these are entirely new datatypes
            try {
                foreach ($this->created_datatypes as $dt) {
                    if ( $dt->getId() === $dt->getGrandparent()->getId() ) {
                        $event = new DatatypeCreatedEvent($datatype, $this->user);
                        $this->event_dispatcher->dispatch(DatatypeCreatedEvent::NAME, $event);
                    }
                }
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }
            $this->logger->info('----------------------------------------');
            $this->logger->info('CloneMasterDatatypeService: cloning of datatype '.$datatype->getId().' is complete');
            $this->logger->info('----------------------------------------');

            return 'complete';
        }
        catch (\Exception $e) {
            // If possible, mark the datatype as failed so the status checker dosen't spin endlessly...
            if ( !is_null($this->original_datatype) ) {
                $dt = $this->original_datatype;
                $dt->setSetupStep(DataType::STATE_CLONE_FAIL);
                $this->em->persist($dt);
                $this->em->flush();
            }

            $this->logger->debug('CLONE DT EXCEPTION ('.$e->getFile().' line '.$e->getLine().'): '.$e->getMessage());
            return $e->getMessage();
        }
    }


    /**
     * Clones the provided $parent_datatype into a new database entry
     *
     * @param DataType $template_datatype A datatype with isMasterType=1 from which the datatype will be cloned.
     * @param DataType|null $new_datatype
     * @param string $datatype_prefix
     * @param string $template_group
     * @param bool $preserve_template_uuids
     */
    private function cloneDatatype($template_datatype, $new_datatype, $datatype_prefix, $template_group, $preserve_template_uuids)
    {
        // If $new_dataype isn't created yet, clone $template_datatype to use as a starting point
        $cloned_new_datatype = false;
        if ( is_null($new_datatype) ) {
/*
            // NOTE - Using php's clone keyword causes doctrine to also load all dataRecord entities
            //  that belong to the template dataType...this isn't a problem when copying a master
            //  template (since it only should have 1 dataRecord), but it is a rather large waste of
            //  memory when copying a "regular" datatype that has tens of thousands of datarecords.

            // While using the clone keyword doesn't seem to currently cause problems related to
            //  datatypes (unlike for datafields, radio options, and tags)...it could still be a
            //  problem in the future, so might as well sidestep it now.
//            $new_datatype = clone $template_datatype;
*/
            $new_datatype = new DataType();
            $new_datatype->setParent($template_datatype->getParent());
            $new_datatype->setGrandparent($template_datatype->getGrandparent());
            $new_datatype->setDatatypeType($template_datatype->getDatatypeType());

            $new_datatype->setRevision(0);

            // All clones from parent need new UUIDs
            $unique_id = $this->uuid_service->generateDatatypeUniqueId();
            $new_datatype->setUniqueId($unique_id);

            $cloned_new_datatype = true;
        }

        if ($new_datatype->getUniqueId() == null) {
            // Set a unique ID if this is a clone - existing DT should already have one.
            $unique_id = $this->uuid_service->generateDatatypeUniqueId();
            $new_datatype->setUniqueId($unique_id);
        }

        // $new_datatype is based off a "master template" datatype
        $new_datatype->setIsMasterType(false);
        $new_datatype->setTemplateGroup($template_group);
        $new_datatype->setMasterDataType($template_datatype);
        $new_datatype->setSetupStep(DataType::STATE_INITIAL);

        self::persistObject($new_datatype, true);    // don't flush immediately...

        array_push($this->created_datatypes, $new_datatype);

        if ($preserve_template_uuids)
            $this->logger->debug('CloneMasterDatatypeService: new datatype is using datatype '.$template_datatype->getId().' as its master template...');
        else
            $this->logger->debug('CloneMasterDatatypeService: new datatype is being copied from datatype '.$template_datatype->getId().'...');

        $template_meta = $template_datatype->getDataTypeMeta();
        // A missing datatypeMeta entry is a fatal error...a datatype can't be cloned in its absence

        $new_meta = null;
        if ( $cloned_new_datatype ) {
            // $new_datatype was cloned at the beginning of the function...it needs a new meta
            //  entry so it doesn't attempt to use the meta entry for $template_datatype
            $new_meta = clone $template_meta;
            $new_meta->setDataType($new_datatype);
        }
        else {
            // $new_datatype was created back in DatatypeController::addAction(), and already has
            //  a meta entry with the name/description
            $new_meta = $new_datatype->getDataTypeMeta();

            // TODO - anything else to set?
            $new_meta->setSearchNotesUpper($template_datatype->getSearchNotesUpper());
            $new_meta->setSearchNotesLower($template_datatype->getSearchNotesLower());
        }

        // These fields should not be set
        $new_meta->setNameField(null);
        $new_meta->setSortField(null);

        // New top-level datatype need search slugs...child datatypes shouldn't, since searching
        //  directly on them is meaningless
        $is_top_level = true;
        if ( $new_datatype->getId() !== $new_datatype->getParent()->getId() )
            $is_top_level = false;

        if ( $is_top_level )
            $new_meta->setSearchSlug($new_datatype->getUniqueId());
        else
            $new_meta->setSearchSlug(null);


        // Use a prefix if short name not equal prefix
        if ($new_meta->getShortName() != $datatype_prefix)
            $new_meta->setLongName($datatype_prefix." - ".$new_meta->getShortName());

        // Track the published version
        $new_meta->setMasterRevision(0);
        $new_meta->setMasterPublishedRevision(0);

        $new_meta->setTrackingMasterRevision($template_meta->getTrackingMasterRevision());

        // TODO - should this flag should always be false for a new datatype?
//        $new_meta->setNewRecordsArePublic(false);

        // TODO - should a datatype's publicDate not be straight-up copied?   cloned public child datatypes end up with a publicDate before their created date...

        // Ensure the "in-memory" version of $new_datatype knows about its meta entry
        $new_datatype->addDataTypeMetum($new_meta);
        self::persistObject($new_meta, true);    // don't flush immediately...
        $this->logger->debug('CloneMasterDatatypeService: meta entry cloned');

        // ----------------------------------------
        // Need to clone the DatatypeSpecialField entries...
        $dtsf_entries = $new_datatype->getMasterDataType()->getDataTypeSpecialFields();
        /** @var DataTypeSpecialFields[] $dtsf_entries */

        foreach ($dtsf_entries as $dtsf) {
            $new_dtsf = clone $dtsf;
            $new_dtsf->setDataType($new_datatype);

            // Don't change the field_purpose or displayOrder properties
            self::persistObject($new_dtsf, true);    // don't flush immediately

            // Since the datafields can't be updated until much later, need to store these for now
            $this->dtsf_mapping[ $dtsf->getId() ] = $new_dtsf;

            if ( $new_dtsf->getFieldPurpose() === DataTypeSpecialFields::NAME_FIELD )
                $this->logger->debug('CloneMasterDatatypeService: cloned datatypeSpecialField entry '.$dtsf->getId().' for name_field '.$new_dtsf->getDisplayOrder().'...');
            else if ( $new_dtsf->getFieldPurpose() === DataTypeSpecialFields::SORT_FIELD )
                $this->logger->debug('CloneMasterDatatypeService: cloned datatypeSpecialField entry '.$dtsf->getId().' for sort_field '.$new_dtsf->getDisplayOrder().'...');
            else
                $this->logger->debug('CloneMasterDatatypeService: cloned datatypeSpecialField entry '.$dtsf->getId().' for UNKNOWN_FIELD_PURPOSE '.$new_dtsf->getDisplayOrder().'...');
        }


        // ----------------------------------------
        // Process data fields so themes and render plugin map can be created
        /** @var DataFields[] $parent_df_array */
        $parent_df_array = $template_datatype->getDataFields();
        foreach ($parent_df_array as $parent_df) {
/*
            // NOTE - Unable to use php's clone keyword since it causes doctrine to also load all
            //  dataRecordField entities related to the parent dataField...which is a complete waste
            //  of memory since they're not getting cloned or even looked at.  While it's not a big
            //  deal when copying from a master template, there can be a LOT of these entities when
            //  copying a "regular" datatype...having to load all those entities tends to eventually
            //  crash mysql, even when memory/execution time is set to unlimited.
            $new_df = clone $parent_df;
*/
            $new_df = new DataFields();
            $new_df->setDataType($new_datatype);
            $new_df->setIsMasterField(false);

            // Assign new uuid
            $new_df->setFieldUuid($this->uuid_service->generateDatafieldUniqueId());

            // Even if not cloning from a "master template", the new datafield needs to know which
            //  field it's getting copied from...
            $new_df->setMasterDataField($parent_df);
            $new_df->setTemplateFieldUuid($parent_df->getFieldUuid());

            // Ensure the "in-memory" version of $new_datatype knows about the new datafield
            $new_datatype->addDataField($new_df);
            self::persistObject($new_df, true);    // don't flush immediately...

            // This is the field map
            $this->df_mapping[ $parent_df->getId() ] = $new_df;

            $this->logger->info('CloneMasterDatatypeService: copied master datafield '.$parent_df->getId().' "'.$parent_df->getFieldName().'" into new datafield');


            $parent_df_meta = $parent_df->getDataFieldMeta();
            // A missing datafieldMeta entry is a fatal error...a datatype can't be cloned in its absence

            $new_df_meta = clone $parent_df_meta;
            $new_df_meta->setDataField($new_df);
            $new_df_meta->setMasterRevision(0);
            $new_df_meta->setMasterPublishedRevision(0);
            $new_df_meta->setTrackingMasterRevision($parent_df_meta->getMasterPublishedRevision());

            // TODO - should this flag should always be false for a new datafield?
//            $new_df_meta->setNewFilesArePublic(false);

            // TODO - should a datafield's publicDate not be straight-up copied?   cloned public child datafields end up with a publicDate before their created date...

            // Ensure the "in-memory" version of $new_df knows about the new meta entry
            $new_df->addDataFieldMetum($new_df_meta);
            self::persistObject($new_df_meta, true);    // don't flush immediately...

            $this->logger->debug('CloneMasterDatatypeService: -- meta entry cloned');


            // If the new datafield is an Image field, ensure it has ImageSize entries...
            if ( $new_df->getFieldType()->getTypeName() === 'Image' ) {
                /** @var ImageSizes[] $image_sizes */
                $image_sizes = $parent_df->getImageSizes();
                foreach ($image_sizes as $image_size) {
                    // ...by cloning each of the master datafield's image size entities
                    $new_image_size = clone $image_size;
                    $new_image_size->setDataField($new_df);

                    // Don't flush immediately...
                    self::persistObject($new_image_size, true);    // don't flush immediately...

                    // NOTE - can't use EntityCreationService::createImageSizes() because that
                    //  function will load $master_df's ImageSize entities instead of realizing that
                    //  $new_df doesn't have any...has to do with doctrine still thinking
                    //  $new_df->getId() === $master_df->getId() prior to the first flush
                }

                $this->logger->info('CloneMasterDatatypeService: >> created ImageSize entries for new datafield "'.$new_df->getFieldName().'"');
            }

            // Need to update the new datatype's meta entry to point to the correct external_id,
            //  name, sort, etc fields
            if ( !is_null($template_meta->getExternalIdField())
                && $parent_df->getId() == $template_meta->getExternalIdField()->getId()
            ) {
                // This is the new external ID field
                $new_meta->setExternalIdField($new_df);
                $this->logger->debug("CloneMasterDatatypeService: -- set this field as the datatype's external_id field");
            }

            if ( !is_null($template_meta->getBackgroundImageField())
                && $parent_df->getId() == $template_meta->getBackgroundImageField()->getId()
            ) {
                // This is the new background image field
                $new_meta->setBackgroundImageField($new_df);
                $this->logger->debug("CloneMasterDatatypeService: -- set this field as the datatype's background image field");
            }


            // Need to process Radio Options...
            /** @var RadioOptions[] $parent_ro_array */
            $parent_ro_array = $parent_df->getRadioOptions();
            if ( count($parent_ro_array) > 0 ) {
                foreach ($parent_ro_array as $parent_ro) {
/*
                    // NOTE - Unable to use php's clone keyword since it causes doctrine to also load
                    //  all radioSelection entities related to the parent radioOption...which is a
                    //  complete waste of memory since they're not getting cloned or even looked at.
                    //  While it's not a big deal when copying from a master template, there can be
                    //  a LOT of these entities when copying a "regular" datatype...having to load
                    //  all those entities tends to eventually crash mysql, even when memory/execution
                    //  time is set to unlimited.
                    $new_ro = clone $parent_ro;
*/
                    $new_ro = new RadioOptions();
                    $new_ro->setDataField($new_df);
                    $new_ro->setOptionName($parent_ro->getOptionName());
                    $new_ro->setRadioOptionUuid($parent_ro->getRadioOptionUuid());

                    // If not cloning from a master template, then generate a new uuid for this
                    //  new radio option
                    if (!$preserve_template_uuids)
                        $new_ro->setRadioOptionUuid($this->uuid_service->generateRadioOptionUniqueId());

                    // Ensure the "in-memory" version of $new_df knows about its new radio option
                    $new_df->addRadioOption($new_ro);
                    self::persistObject($new_ro, true);    // don't flush immediately...

                    // Also clone the radio option's meta entry
                    $parent_ro_meta = $parent_ro->getRadioOptionMeta();
                    $new_ro_meta = clone $parent_ro_meta;
                    $new_ro_meta->setRadioOption($new_ro);

                    // Ensure the "in-memory" version of $new_ro knows about its meta entry
                    $new_ro->addRadioOptionMetum($new_ro_meta);
                    self::persistObject($new_ro_meta, true);    // don't flush immediately...

                    // If the radio option is marked as default, then need to clear a relevant
                    //  cached entry
                    if ( $new_ro->getIsDefault() )
                        $this->cache_service->delete('default_radio_options');

                    if ( $preserve_template_uuids )
                        $this->logger->debug('CloneMasterDatatypeService: -- cloned radio option '.$parent_ro->getRadioOptionUuid().' "'.$new_ro->getOptionName().'" and its meta entry');
                    else
                        $this->logger->debug('CloneMasterDatatypeService: -- cloned radio option "'.$new_ro->getOptionName().'" and its meta entry, new radio option uuid: '.$new_ro->getRadioOptionUuid());
                }
            }

            // Need to process Tags...
            /** @var Tags[] $new_tag_entities */
            $new_tag_entities = array();

            /** @var Tags[] $parent_tag_array */
            $parent_tag_array = $parent_df->getTags();
            if ( count($parent_tag_array) > 0 ) {
                foreach ($parent_tag_array as $parent_tag) {
/*
                    // NOTE - Unable to use php's clone keyword since it causes doctrine to also load
                    //  all tagSelection entities related to the parent tag...which is a complete
                    //  waste of memory since they're not getting cloned or even looked at.  While
                    //  it's not a big deal when copying from a master template, there can be a LOT
                    //  of these entities when copying a "regular" datatype...having to load all
                    //  those entities tends to eventually crash mysql, even when memory/execution
                    //  time is set to unlimited.
                    $new_tag = clone $parent_tag;
*/
                    $new_tag = new Tags();
                    $new_tag->setDataField($new_df);
                    $new_tag->setTagName($parent_tag->getTagName());
                    $new_tag->setTagUuid($parent_tag->getTagUuid());

                    // If not cloning from a master template, then generate a new uuid for this
                    //  new tag
                    if (!$preserve_template_uuids)
                        $new_tag->setTagUuid($this->uuid_service->generateTagUniqueId());

                    // Ensure the "in-memory" version of $new_df knows about its new tag
                    $new_df->addTag($new_tag);
                    self::persistObject($new_tag, true);    // don't flush immediately...

                    // Also clone the tag's meta entry
                    $parent_tag_meta = $parent_tag->getTagMeta();
                    $new_tag_meta = clone $parent_tag_meta;
                    $new_tag_meta->setTag($new_tag);

                    // Ensure the "in-memory" version of $new_tag knows about its meta entry
                    $new_tag->addTagMetum($new_tag_meta);
                    self::persistObject($new_tag_meta, true);    // don't flush immediately...

                    if ($preserve_template_uuids)
                        $this->logger->debug('CloneMasterDatatypeService: -- cloned tag '.$parent_tag->getTagUuid().' "'.$new_tag->getTagName().'" and its meta entry');
                    else
                        $this->logger->debug('CloneMasterDatatypeService: -- cloned tag "'.$new_tag->getTagName().'" and its meta entry, new tag uuid: '.$new_tag->getTagUuid());

                    // It's easier to create new tag tree entries using the newly created tags than
                    //  it is to locate/clone/modify each of the relevant tag tree entries in the
                    //  master template
                    $new_tag_entities[ $parent_tag->getId() ] = $new_tag;
                }
            }

            // Run a query to get all tag tree entities for this datafield...
            $query = $this->em->createQuery(
               'SELECT parent.id AS parent_tag_id, child.id AS child_tag_id
                FROM ODRAdminBundle:TagTree AS tt
                JOIN ODRAdminBundle:Tags AS parent WITH tt.parent = parent
                JOIN ODRAdminBundle:Tags AS child WITH tt.child = child
                WHERE parent.dataField = :df_id OR child.dataField = :df_id
                AND parent.deletedAt IS NULL AND child.deletedAt IS NULL AND tt.deletedAt IS NULL'
            )->setParameters( array('df_id' => $parent_df->getId()) );
            $results = $query->getArrayResult();

            // ...for each tag tree entry found...
            foreach ($results as $result) {
                // ...locate the newly created derived tag corresponding to the parent/child ids
                //  from the template datatype
                $parent_tag_id = $result['parent_tag_id'];
                $child_tag_id = $result['child_tag_id'];

                $derived_parent_tag = $new_tag_entities[$parent_tag_id];
                $derived_child_tag = $new_tag_entities[$child_tag_id];

                // Create and persist a new tag tree entry between those newly created tags
                $tt = new TagTree();
                $tt->setParent($derived_parent_tag);
                $tt->setChild($derived_child_tag);

                self::persistObject($tt, true);    // don't flush immediately...

                $this->logger->debug('CloneMasterDatatypeService: -- created tag tree between parent tag '.$derived_parent_tag->getTagUuid().' "'.$derived_parent_tag->getTagName().'" and child tag '.$derived_child_tag->getTagUuid().' "'.$derived_child_tag->getTagName().'"');
            }


            // Persist the DataType metadata changes (after field remapping fixes)
            self::persistObject($new_meta, true);    // don't flush immediately...

            // Copy any render plugin settings for this datafield from the master template
            self::cloneRenderPlugins(null, $new_df);
        }

        // The datafields are now created...
        // If the parent datatype has a render plugin, copy its settings as well
        self::cloneRenderPlugins($new_datatype, null);
    }


    /**
     * Once the theme stuff from the master template and its children are fully cloned, the
     * datatree entries describing parent/child datatype relations also need to be cloned...
     *
     * @param DataType $parent_datatype
     */
    private function cloneDatatree($parent_datatype)
    {
        $this->logger->info('CloneMasterDatatypeService: attempting to clone datatree entries for datatype '.$parent_datatype->getId().' "'.$parent_datatype->getShortName().'"...');

        /** @var DataTree[] $datatree_array */
        $datatree_array = $this->em->getRepository('ODRAdminBundle:DataTree')
            ->findBy( array('ancestor' => $parent_datatype->getId()) );

        if ( empty($datatree_array) )
            $this->logger->debug('CloneMasterDatatypeService: -- no datatree entries found');

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
                    self::persistObject($new_dt, true);    // don't flush immediately...

                    // Clone the datatree's meta entry
                    $new_meta = clone $datatree->getDataTreeMeta();
                    $new_meta->setDataTree($new_dt);
                    self::persistObject($new_meta, true);    // don't flush immediately...

                    $new_dt->addDataTreeMetum($new_meta);
                    self::persistObject($new_dt, true);    // don't flush immediately...

                    $this->logger->info('CloneMasterDatatypeService: -- created new datatree with datatype '.$current_ancestor->getId().' "'.$current_ancestor->getShortName().'" as ancestor and datatype '.$datatype->getId().' "'.$datatype->getShortName().'" as descendant, is_link = '.$new_meta->getIsLink());
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
        $this->logger->info('CloneMasterDatatypeService: attempting to clone group entries for datatype '.$datatype->getId().' "'.$datatype->getShortName().'" from master datatype '.$master_datatype->getId().'...');

        /** @var Group[] $master_groups */
        $master_groups = $master_datatype->getGroups();
        if ( is_null($master_groups) )
            throw new ODRException('CloneMasterDatatypeService: Master Datatype '.$master_datatype->getId().' has no group entries to clone.');

        // Save New Groups with map for cloning datafields
        $new_groups = array();

        // Clone all of the master datatype's groups
        foreach ($master_groups as $master_group) {
            $new_group = clone $master_group;
            $new_group->setDataType($datatype);

            $new_groups[$master_group->getId()] = $new_group;

            // Ensure the "in-memory" version of $datatype knows about the new group
            $datatype->addGroup($new_group);

            // Store that a group was created
            $this->created_groups[] = $new_group;

            // ...also needs the associated group meta entry
            $parent_group_meta = $master_group->getGroupMeta();
            $new_group_meta = clone $parent_group_meta;
            $new_group_meta->setGroup($new_group);

            // Ensure the "in-memory" version of $new_group knows about its new meta entry
            $new_group->addGroupMetum($new_group_meta);

            self::persistObject($new_group_meta, true);    // don't flush immediately...
            self::persistObject($new_group, true);    // don't flush immediately...

            $this->logger->info('CloneMasterDatatypeService: created new Group from parent "'.$master_group->getPurpose().'" Group '.$master_group->getId().' for datatype '.$datatype->getId());

            // Ensure the user making the clone is added to the admin group of the new datatype,
            //  otherwise they won't be able to see it when the cloning is complete
            if ($new_group->getPurpose() == "admin") {
                if ( !$this->user->hasRole('ROLE_SUPER_ADMIN') ) {
                    // Don't need to do this when the creating user is a super-admin, since they'll
                    //  automatically be able to see the new datatype
                    $this->ec_service->createUserGroup($this->user, $new_group, $this->user, true);    // These don't need to be flushed/refreshed immediately...
                    $this->logger->debug('-- added user '.$this->user->getId().' to admin group');

                    // If the user's cached permissions were deleted here, the user would likely
                    //  get a stale/incomplete version when accessing the datatype later on
                }
            }
        }

        /*
         * TODO Finish this refactor
         *
        // Flush and refresh new groups to get ids
        $this->em->flush();
        foreach($new_groups as $master_group_id => $new_group) {
            $this->em->refresh($new_group);

            // clone all fields for group in single query
            // Insert into datafield_permissions (fields) SELECT fields from datafield_permissions by group
            $db = $this->_em->getConnection();
            $query = "INSERT INTO odr_group_datafield_permissions (
                    myfield
                ) SELECT
                   ogdp.myfield

                   FROM odr_group_datafield_permissions
                   WHERE table1.id < 1000";
            $stmt = $db->prepare($query);
            $params = array();
            $stmt->execute($params);

        }
        */
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
        $this->logger->info('CloneMasterDatatypeService: attempting to clone datatype permission entries for datatype '.$datatype->getId().' "'.$datatype->getShortName().'" from master datatype '.$master_datatype->getId().'...');

        /** @var GroupDatatypePermissions[] $master_gdt_permissions */
        $master_gdt_permissions = $master_datatype->getGroupDatatypePermissions();
        if ( is_null($master_gdt_permissions) )
            throw new ODRException('CloneMasterDatatypeService: Master Datatype '.$master_datatype->getId().' has no permission entries to clone.');


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
            throw new ODRException('CloneMasterDatatypeService: Grandparent Datatype '.$grandparent_datatype_id.' has no group entries');


        // insert into group datafield (fields) select (fields, new_group_id)

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
                    self::persistObject($new_permission, true);    // don't flush immediately...

                    $this->logger->debug('CloneMasterDatatypeService: -- cloned GroupDatatypePermission entry from master template Group '.$master_group->getId().' to Group '.$group->getId().' for new datatype '.$datatype->getId());
                }
            }
        }
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
        $this->logger->debug('CloneMasterDatatypeService: attempting to clone datafield permission entries for datafield '.$datafield->getId().' "'.$datafield->getFieldName().'" from master datafield '.$master_datafield->getId().'...');

        /** @var GroupDatafieldPermissions[] $master_gdf_permissions */
        $master_gdf_permissions = $master_datafield->getGroupDatafieldPermissions();
        if ( is_null($master_gdf_permissions) )
            throw new ODRException('CloneMasterDatatypeService: Master Datafield '.$master_datafield->getId().' has no permission entries to clone.');

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
            throw new ODRException('CloneMasterDatatypeService: Grandparent Datatype '.$grandparent_datatype_id.' has no group entries');


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
                    self::persistObject($new_permission, true);    // don't flush immediately...

                    $this->logger->debug('CloneMasterDatatypeService: -- cloned GroupDatafieldPermission entry from master template Group '.$master_group->getId().' to Group '.$group->getId().' for new datafield '.$datafield->getId());
                }
            }
        }
    }


    /**
     * Given a Datatype or Datafield, completely clone all the relevant information for its
     * render plugin, assuming it's currently using one.
     *
     * @param DataType|null $derived_datatype
     * @param DataFields|null $derived_datafield
     */
    private function cloneRenderPlugins($derived_datatype, $derived_datafield)
    {
        // Need to have either a datatype or a datafield...
        if ( is_null($derived_datatype) && is_null($derived_datafield) )
            throw new ODRException('CloneMasterDatatypeService::cloneRenderPlugins() needs either a null datatype or a null datafield, but was called with both being null');
        if ( !is_null($derived_datatype) && !is_null($derived_datafield) )
            throw new ODRException('CloneMasterDatatypeService::cloneRenderPlugins() needs either a null datatype or a null datafield, but was called with both being non-null');


        if ( !is_null($derived_datatype) ) {
            $master_datatype = $derived_datatype->getMasterDataType();

            // If the master datatype has a render plugin...
            foreach ($master_datatype->getRenderPluginInstances() as $master_rpi) {
                /** @var RenderPluginInstance $master_rpi */
                $this->logger->info('CloneMasterDatatypeService: attempting to clone settings for render plugin '.$master_rpi->getRenderPlugin()->getId().' "'.$master_rpi->getRenderPlugin()->getPluginName().'" in use by master datatype '.$master_datatype->getId());

                $plugin_type = $master_rpi->getRenderPlugin()->getPluginType();
                if ( $plugin_type === RenderPlugin::DATATYPE_PLUGIN || $plugin_type === RenderPlugin::THEME_ELEMENT_PLUGIN || $plugin_type === RenderPlugin::ARRAY_PLUGIN ) {
                    // Clone the renderPluginInstance
                    $new_rpi = clone $master_rpi;
                    $new_rpi->setDataType($derived_datatype);
                    $new_rpi->setDataField(null);

                    self::persistObject($new_rpi, true);    // don't flush immediately...
                    $this->logger->debug('CloneMasterDatatypeService: -- cloned render_plugin_instance '.$master_rpi->getId().', attached to newly cloned datatype "'.$derived_datatype->getShortName().'"');

                    $this->rpi_mapping[ $master_rpi->getId() ] = $new_rpi;

                    // Clone the renderPluginFields and renderPluginOptions mappings
                    self::cloneRenderPluginSettings($master_rpi, $new_rpi, $derived_datatype, null);
                }
                else {
                    $this->logger->debug('CloneMasterDatatypeService: ** skipped render_plugin_instance '.$master_rpi->getId().' because it is a datafield plugin');
                }
            }
        }
        else {
            $master_datafield = $derived_datafield->getMasterDataField();

            // If the master datafield has a render plugin...
            foreach ($master_datafield->getRenderPluginInstances() as $master_rpi) {
                /** @var RenderPluginInstance $master_rpi */
                $this->logger->info('CloneMasterDatatypeService: -- attempting to clone settings for render plugin '.$master_rpi->getRenderPlugin()->getId().' "'.$master_rpi->getRenderPlugin()->getPluginName().'" in use by master datafield '.$master_datafield->getId());

                $plugin_type = $master_rpi->getRenderPlugin()->getPluginType();
                if ( $plugin_type === RenderPlugin::DATATYPE_PLUGIN || $plugin_type === RenderPlugin::THEME_ELEMENT_PLUGIN || $plugin_type === RenderPlugin::ARRAY_PLUGIN ) {
                    $this->logger->debug('CloneMasterDatatypeService: ** skipped render_plugin_instance '.$master_rpi->getId().' because it is not a datafield plugin');
                }
                else {
                    // Clone the renderPluginInstance
                    $new_rpi = clone $master_rpi;
                    $new_rpi->setDataType(null);
                    $new_rpi->setDataField($derived_datafield);

                    self::persistObject($new_rpi, true);    // don't flush immediately...
                    $this->logger->debug('CloneMasterDatatypeService: -- cloned render_plugin_instance '.$master_rpi->getId().', attached to newly cloned datafield "'.$derived_datafield->getFieldName().'"');

                    $this->rpi_mapping[ $master_rpi->getId() ] = $new_rpi;

                    // Clone the renderPluginFields and renderPluginOptions mappings
                    self::cloneRenderPluginSettings($master_rpi, $new_rpi, null, $derived_datafield);
                }
            }
        }
    }


    /**
     * Clones the renderPluginOptionsMap and renderPlugin(Field)Map entries from the given master
     * renderPluginInstance into the given derived renderPluginInstance.
     *
     * @param RenderPluginInstance $master_rpi
     * @param RenderPluginInstance $derived_rpi
     * @param DataType|null $derived_datatype
     * @param DataFields|null $derived_datafield
     */
    private function cloneRenderPluginSettings($master_rpi, $derived_rpi, $derived_datatype, $derived_datafield)
    {
        // Clone each option mapping defined for this renderPluginInstance
        /** @var RenderPluginOptionsMap[] $parent_rpom_array */
        $parent_rpom_array = $master_rpi->getRenderPluginOptionsMap();
        foreach ($parent_rpom_array as $parent_rpom) {
            $new_rpom = clone $parent_rpom;
            $new_rpom->setRenderPluginInstance($derived_rpi);
            self::persistObject($new_rpom, true);    // don't flush immediately...

            $this->logger->debug('CloneMasterDatatypeService: -- cloned render_plugin_option '.$parent_rpom->getId().' "'.$parent_rpom->getRenderPluginOptionsDef()->getDisplayName().'" => "'.$parent_rpom->getValue().'"');
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
                /** @var DataFields $matching_df */
                $matching_df = $this->df_mapping[$parent_rpfm->getDataField()->getId()];
                $new_rpfm->setDataField($matching_df);

                self::persistObject($new_rpfm, true);    // These don't need to be flushed/refreshed immediately...
                $this->logger->debug('CloneMasterDatatypeService: -- cloned render_plugin_map '.$parent_rpfm->getId().' for render_plugin_field "'.$parent_rpfm->getRenderPluginFields()->getFieldName().'", attached to datafield "'.$matching_df->getFieldName().'" of datatype "'.$matching_df->getDataType()->getShortName().'"');
            }
            else {
                $this->logger->debug('CloneMasterDatatypeService: -- cloned render_plugin_map '.$parent_rpfm->getId().' for render_plugin_field "'.$parent_rpfm->getRenderPluginFields()->getFieldName().'", but did not update since it is mapped as unused optional rpf');
            }
        }

        // NOTE: unlike CloneTemplateService, the theme stuff doesn't exist at this point in time...
        // ...so the themeRenderPluginInstance stuff can't be cloned here
    }
}
