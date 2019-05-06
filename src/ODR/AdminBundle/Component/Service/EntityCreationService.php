<?php

/**
 * Open Data Repository Data Publisher
 * Entity Creation Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Stores the code to create a default version of just about every ODR entity.  Users are handled
 * inside ODRCustomController, and files/images are still handled inside ODRCustomController.
 *
 * TODO - tracked job stuff?
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\Boolean as ODRBoolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\GroupMeta;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\TagMeta;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\TagSelection;
use ODR\AdminBundle\Entity\TagTree;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\UserGroup;
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\LockHandler;


class EntityCreationService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var UUIDService
     */
    private $uuid_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * EntityCreationService constructor.
     *
     * @param EntityManager $entityManager
     * @param CacheService $cacheService
     * @param UUIDService $UUIDService
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entityManager,
        CacheService $cacheService,
        UUIDService $UUIDService,
        Logger $logger
    ) {
        $this->em = $entityManager;
        $this->cache_service = $cacheService;
        $this->uuid_service = $UUIDService;

        $this->logger = $logger;
    }


    /**
     * Creates and persists a new Datafield and DatafieldMeta entity, and creates groups for it as
     * well
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param FieldType $fieldtype
     * @param RenderPlugin $render_plugin
     * @param bool $delay_flush
     *
     * @return DataFields
     */
    public function createDatafield($user, $datatype, $fieldtype, $render_plugin, $delay_flush = false)
    {
        // Poplulate new DataFields form
        $datafield = new DataFields();
        $datafield->setDataType($datatype);
        $datafield->setCreatedBy($user);

        // This will always be zero unless created from a Master Template data field.
        // $datafield->setMasterDataField(0);

        // Set UUID
        $datafield->setFieldUuid( $this->uuid_service->generateDatafieldUniqueId() );

        // Add master flags
        $datafield->setIsMasterField(false);
        if ($datatype->getIsMasterType() == true)
            $datafield->setIsMasterField(true);

        $this->em->persist($datafield);

        $datafield_meta = new DataFieldsMeta();
        $datafield_meta->setDataField($datafield);
        $datafield_meta->setFieldType($fieldtype);
        $datafield_meta->setRenderPlugin($render_plugin);

        // Master Revision defaults to zero.  When created from a Master Template field, this will
        //  track the data field Master Published Revision.
        $datafield_meta->setMasterRevision(0);
        if ( $datatype->getIsMasterType() > 0 )
            $datafield_meta->setMasterRevision(1);

        // Will need to set the tracking revision if created from master template field.
        $datafield_meta->setTrackingMasterRevision(0);
        $datafield_meta->setMasterPublishedRevision(0);

        $datafield_meta->setFieldName('New Field');
        $datafield_meta->setDescription('Field description.');
        $datafield_meta->setXmlFieldName('');
        $datafield_meta->setInternalReferenceName('');
        $datafield_meta->setRegexValidator('');
        $datafield_meta->setPhpValidator('');

        $datafield_meta->setMarkdownText('');
        $datafield_meta->setIsUnique(false);
        $datafield_meta->setRequired(false);
        $datafield_meta->setSearchable(0);
        $datafield_meta->setPublicDate( new \DateTime('2200-01-01 00:00:00') );

        $datafield_meta->setChildrenPerRow(1);
        $datafield_meta->setRadioOptionNameSort(0);
        $datafield_meta->setRadioOptionDisplayUnselected(0);
        $datafield_meta->setTagsAllowNonAdminEdit(0);
        $datafield_meta->setTagsAllowMultipleLevels(0);
        if ( $fieldtype->getTypeClass() === 'File' || $fieldtype->getTypeClass() === 'Image' ) {
            $datafield_meta->setAllowMultipleUploads(1);
            $datafield_meta->setShortenFilename(1);
        }
        else {
            $datafield_meta->setAllowMultipleUploads(0);
            $datafield_meta->setShortenFilename(0);
        }
        $datafield_meta->setCreatedBy($user);
        $datafield_meta->setUpdatedBy($user);

        // Ensure the datafield knows about its meta entry
        $datafield->addDataFieldMetum($datafield_meta);
        $this->em->persist($datafield_meta);

        if ( !$delay_flush )
            $this->em->flush();


        // Add the datafield to all groups for this datatype
        self::createGroupsForDatafield($user, $datafield, $delay_flush);

        return $datafield;
    }


    /**
     * Creates and persists a new Datarecord and a new DatarecordMeta entity.  The user will need
     * to set the provisioned property back to false eventually.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param bool $delay_flush
     *
     * @return DataRecord
     */
    public function createDatarecord($user, $datatype, $delay_flush = false)
    {
        // Initial create
        $datarecord = new DataRecord();

        $datarecord->setDataType($datatype);
        $datarecord->setCreatedBy($user);
        $datarecord->setUpdatedBy($user);

        // Default to assuming this is a top-level datarecord
        $datarecord->setParent($datarecord);
        $datarecord->setGrandparent($datarecord);

        $datarecord->setProvisioned(true);  // Prevent most areas of the site from doing anything with this datarecord...whatever created this datarecord needs to eventually set this to false
        $datarecord->setUniqueId( $this->uuid_service->generateDatarecordUniqueId() );

        $this->em->persist($datarecord);

        $datarecord_meta = new DataRecordMeta();
        $datarecord_meta->setDataRecord($datarecord);
        $datarecord_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

        $datarecord_meta->setCreatedBy($user);
        $datarecord_meta->setUpdatedBy($user);

        // Ensure the datarecord knows about its meta entry
        $datarecord->addDataRecordMetum($datarecord_meta);
        $this->em->persist($datarecord_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $datarecord;
    }


    /**
     * Creates and persists a new DataRecordField entity, if one does not already exist for the
     * given (DataRecord, DataField) pair.
     *
     * This function doesn't permit delaying flushes, because it's impossible to lock properly.
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param DataFields $datafield
     *
     * @return DataRecordFields
     */
    public function createDatarecordField($user, $datarecord, $datafield)
    {
        /** @var DataRecordFields $drf */
        $drf = $this->em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
            array(
                'dataRecord' => $datarecord->getId(),
                'dataField' => $datafield->getId(),
            )
        );
        if ($drf == null) {
            // Need to create a new datarecordfield entry...

            // Bad Things (tm) happen if there's more than one drf entry for this datarecord/datafield
            //  pair, so use Symfony's LockHandler component to prevent that...
            $lockHandler = new LockHandler('drf_'.$datarecord->getId().'_'.$datafield->getId().'.lock');
            if (!$lockHandler->lock()) {
                // Another process is attempting to create this drf entry...block until it finishes...
                $lockHandler->lock(true);

                // ...then reload and return the drf that the other process created
                $drf = $this->em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                    array(
                        'dataRecord' => $datarecord->getId(),
                        'dataField' => $datafield->getId(),
                    )
                );
                return $drf;
            }
            else {
                // Got the lock, create the drf entry
                $drf = new DataRecordFields();
                $drf->setDataRecord($datarecord);
                $drf->setDataField($datafield);

                $drf->setCreated(new \DateTime());
                $drf->setCreatedBy($user);

                $this->em->persist($drf);
                $this->em->flush();
                $this->em->refresh($drf);

                // Now that the drf is is created, release the lock on it
                $lockHandler->release();
            }
        }

        return $drf;
    }


    /**
     * Create a datarecord link from $ancestor_datarecord to $descendant_datarecord.
     *
     * This function doesn't permit delaying flushes, because it's impossible to lock properly.
     *
     * @param ODRUser $user
     * @param DataRecord $ancestor_datarecord
     * @param DataRecord $descendant_datarecord
     *
     * @return LinkedDataTree
     */
    public function createDatarecordLink($user, $ancestor_datarecord, $descendant_datarecord)
    {
        // Check to see if the two datarecords are already linked
        /** @var LinkedDataTree $linked_datatree */
        $linked_datatree = $this->em->getRepository('ODRAdminBundle:LinkedDataTree')->findOneBy(
            array(
                'ancestor' => $ancestor_datarecord,
                'descendant' => $descendant_datarecord,
            )
        );

        if ($linked_datatree == null) {
            // Use Symfony's file locking to ensure there's at most one (ancestor, descendant) pair
            $lockHandler = new LockHandler('ldt_'.$ancestor_datarecord->getId().'_'.$descendant_datarecord->getId().'.lock');
            if ( !$lockHandler->lock() ) {
                // Another process is attempting to create this entity...wait for it to finish...
                $lockHandler->lock(true);

                // ...then reload and return the drf that got created
                $linked_datatree = $this->em->getRepository('ODRAdminBundle:LinkedDataTree')->findOneBy(
                    array(
                        'ancestor' => $ancestor_datarecord,
                        'descendant' => $descendant_datarecord,
                    )
                );
                return $linked_datatree;
            }
            else {
                // No link exists, create a new entity
                $linked_datatree = new LinkedDataTree();
                $linked_datatree->setAncestor($ancestor_datarecord);
                $linked_datatree->setDescendant($descendant_datarecord);

                $linked_datatree->setCreatedBy($user);

                $this->em->persist($linked_datatree);
                $this->em->flush();

                // Now that the entity exists, release the lock
                $lockHandler->release();
            }
        }

        // A link either already exists, or was just created...return the entity
        return $linked_datatree;
    }


    /**
     * Creates and persists a new Datatree and a new DatatreeMeta entry.
     *
     * @param ODRUser $user
     * @param DataType $ancestor The parent datatype (or the datatpe linking to something) in this relationship
     * @param DataType $descendant The child datatype (or the datatype getting linked to) in this relationship
     * @param bool $is_link
     * @param bool $multiple_allowed If true, this relationship permits more than one child/linked datarecord
     * @param bool $delay_flush
     *
     * @return DataTree
     */
    public function createDatatree($user, $ancestor, $descendant, $is_link, $multiple_allowed, $delay_flush = false)
    {
        $datatree = new DataTree();
        $datatree->setAncestor($ancestor);
        $datatree->setDescendant($descendant);

        $datatree->setCreatedBy($user);

        $this->em->persist($datatree);

        $datatree_meta = new DataTreeMeta();
        $datatree_meta->setDataTree($datatree);
        $datatree_meta->setIsLink($is_link);
        $datatree_meta->setMultipleAllowed($multiple_allowed);

        $datatree_meta->setCreatedBy($user);
        $datatree_meta->setUpdatedBy($user);

        $datatree->addDataTreeMetum($datatree_meta);
        $this->em->persist($datatree_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $datatree;
    }


    /**
     * Creates and persists a new Datatype and DatatypeMeta entity. The caller MUST also create a
     * master theme and call the odr.permissions_management_service to create groups for the new
     * datatype.  After that, they need to set the 'setup_step' property to 'operational'.
     *
     * @param ODRUser $user
     * @param string $datatype_name
     * @param bool $delay_flush
     *
     * @return DataType
     */
    public function createDatatype($user, $datatype_name, $delay_flush = false)
    {
        // Initial create
        $datatype = new DataType();
        $datatype->setSetupStep(DataType::STATE_INITIAL);
        $datatype->setRevision(0);
        $datatype->setIsMasterType(false);
        $datatype->setMasterDataType(null);

        // TODO - what is this supposed to be used for?
        $datatype->setDatatypeType(null);

        // Assume top-level datatype
        $datatype->setParent($datatype);
        $datatype->setGrandparent($datatype);

        $unique_id = $this->uuid_service->generateDatatypeUniqueId();
        $datatype->setUniqueId($unique_id);
        $datatype->setTemplateGroup($unique_id);

        $datatype->setCreatedBy($user);
        $datatype->setUpdatedBy($user);

        $this->em->persist($datatype);


        $datatype_meta = new DataTypeMeta();
        $datatype_meta->setDataType($datatype);
        $datatype_meta->setShortName($datatype_name);
        $datatype_meta->setLongName($datatype_name);
        $datatype_meta->setDescription('');
        $datatype_meta->setXmlShortName('');

        $datatype_meta->setSearchSlug(null);
        $datatype_meta->setSearchNotesUpper(null);
        $datatype_meta->setSearchNotesLower(null);

        // Default to "not-public"
        $datatype_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));

        $datatype_meta->setMasterPublishedRevision(0);
        $datatype_meta->setMasterRevision(0);
        $datatype_meta->setTrackingMasterRevision(0);

        // These would be null by default, but are specified here for completeness
        $datatype_meta->setExternalIdField(null);
        $datatype_meta->setNameField(null);
        $datatype_meta->setSortField(null);
        $datatype_meta->setBackgroundImageField(null);

        /** @var RenderPlugin $default_render_plugin */
        $default_render_plugin = $this->em->getRepository('ODRAdminBundle:RenderPlugin')->findOneBy(
            array('pluginClassName' => 'odr_plugins.base.default')
        );
        $datatype_meta->setRenderPlugin($default_render_plugin);

        $datatype_meta->setCreatedBy($user);
        $datatype_meta->setUpdatedBy($user);

        // Ensure the datatype knows about its meta entry
        $datatype->addDataTypeMetum($datatype_meta);
        $this->em->persist($datatype_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $datatype;
    }


    /**
     * Create a new Group for users of the given datatype.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param string $initial_purpose One of 'admin', 'edit_all', 'view_all', 'view_only', or ''
     * @param bool $delay_flush
     *
     * @return Group
     */
    public function createGroup($user, $datatype, $initial_purpose = '', $delay_flush = false)
    {
        // ----------------------------------------
        // Groups should only be attached to top-level datatypes...child datatypes inherit groups
        //  from their parent
        if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
            throw new ODRBadRequestException('Child Datatypes are not allowed to have groups of their own.');


        // ----------------------------------------
        // Create the Group entity
        $group = new Group();
        $group->setDataType($datatype);
        $group->setPurpose($initial_purpose);
        $group->setCreatedBy($user);

        // Ensure the "in-memory" version of $datatype knows about the new group
        $datatype->addGroup($group);
        $this->em->persist($group);


        // Create the GroupMeta entity
        $group_meta = new GroupMeta();
        $group_meta->setGroup($group);
        if ($initial_purpose == 'admin') {
            $group_meta->setGroupName('Default Group - Admin');
            $group_meta->setGroupDescription('Users in this default Group are always allowed to view and edit all Datarecords, modify all layouts, and change permissions of any User with regards to this Datatype.');
        }
        else if ($initial_purpose == 'edit_all') {
            $group_meta->setGroupName('Default Group - Editor');
            $group_meta->setGroupDescription('Users in this default Group can always both view and edit all Datarecords and Datafields of this Datatype.');
        }
        else if ($initial_purpose == 'view_all') {
            $group_meta->setGroupName('Default Group - View All');
            $group_meta->setGroupDescription('Users in this default Group always have the ability to see non-public Datarecords and Datafields of this Datatype, but cannot make any changes.');
        }
        else if ($initial_purpose == 'view_only') {
            $group_meta->setGroupName('Default Group - View');
            $group_meta->setGroupDescription('Users in this default Group are always able to see public Datarecords and Datafields of this Datatype, though they cannot make any changes.  If the Datatype is public, then adding Users to this Group is meaningless.');
        }
        else {
            $group_meta->setGroupName('New user group for '.$datatype->getShortName());
            $group_meta->setGroupDescription('');
        }

        $group_meta->setCreatedBy($user);
        $group_meta->setUpdatedBy($user);

        // Ensure the "in-memory" version of the new group knows about its meta entry
        $group->addGroupMetum($group_meta);
        $this->em->persist($group_meta);


        // ----------------------------------------
        // Locate the ids of all children of this top-level datatype
        $query = $this->em->createQuery(
           'SELECT dt
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.grandparent = :datatype_id
            AND dt.deletedAt IS NULL'    // TODO - if datatypes are eventually going to be undeleteable, then this needs to also return deleted child datatypes
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getResult();

        $dt_ids = array();
        foreach ($results as $dt) {
            /** @var DataType $dt */
            $dt_ids[] = $dt->getId();

            // Can't use createGroupsForDatatatype for this, it creates permissions for a single
            //  datatype across all groups...this function instead needs to create permissions for
            //  all datatypes for a single group
            $gdtp = new GroupDatatypePermissions();
            $gdtp->setGroup($group);
            $gdtp->setDataType($dt);

            $gdtp->setCreated(new \DateTime());
            $gdtp->setCreated(new \DateTime());
            $gdtp->setCreatedBy($user);
            $gdtp->setUpdatedBy($user);

            // Default all permissions to false...
            $gdtp->setIsDatatypeAdmin(false);
            $gdtp->setCanDesignDatatype(false);
            $gdtp->setCanDeleteDatarecord(false);
            $gdtp->setCanAddDatarecord(false);
            $gdtp->setCanViewDatarecord(false);
            $gdtp->setCanViewDatatype(false);

            switch ($initial_purpose) {
                case 'admin':
                    // the 'admin' group gets all permissions below this line
                    $gdtp->setIsDatatypeAdmin(true);
                    $gdtp->setCanDesignDatatype(true);
                case 'edit_all':
                    // the 'edit_all' group gets all permissions below this line
                    $gdtp->setCanDeleteDatarecord(true);
                    $gdtp->setCanAddDatarecord(true);
                case 'view_all':
                    // the 'view_all' group gets all permissions below this line
                    $gdtp->setCanViewDatarecord(true);
                case 'view_only':
                    // the 'view_only' group gets all permissions below this line
                    $gdtp->setCanViewDatatype(true);
                    break;

                default:
                    break;
            }

            // The group specifically for the top-level datatype needs to have the can_view_datatype
            //  permission, because it makes zero sense for members of a group to not be able to see
            //  the datatype it's meant for
            if ( $dt->getId() === $dt->getGrandparent()->getId() )
                $gdtp->setCanViewDatatype(true);

            // Intentionally don't flush here...
            $this->em->persist($gdtp);
        }


        // ----------------------------------------
        // Create the initial datafield permission entries
        $this->em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted datafields
        $query = $this->em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
            WHERE dt.id IN (:datatype_ids)'
        )->setParameters( array('datatype_ids' => $dt_ids) );
        $results = $query->getResult();
        $this->em->getFilters()->enable('softdeleteable');

        foreach ($results as $df) {
            /** @var DataFields $df */

            // Can't use createGroupsForDatafield for this, it creates permissions for a single
            //  datafield across all groups...this function instead needs to create permissions for
            //  all datafields for a single group
            $gdfp = new GroupDatafieldPermissions();
            $gdfp->setGroup($group);
            $gdfp->setDataField($df);
            $gdfp->setDeletedAt( $df->getDeletedAt() );    // need to copy the datafield's deletedAt incase it gets undeleted later...

            $gdfp->setCreated(new \DateTime());
            $gdfp->setCreated(new \DateTime());
            $gdfp->setCreatedBy($user);
            $gdfp->setUpdatedBy($user);

            $initial_purpose = $group->getPurpose();
            if ($initial_purpose == 'admin' || $initial_purpose == 'edit_all') {
                // "admin" and "edit_all" groups can both see and edit this new datafield by default
                $gdfp->setCanViewDatafield(1);
                $gdfp->setCanEditDatafield(1);
            }
            else if ($initial_purpose == 'view_all') {
                // The "view_all" group defaults to being able to see, but not edit, this datafield
                $gdfp->setCanViewDatafield(1);
                $gdfp->setCanEditDatafield(0);
            }
            else {
                // All other groups default to not being able to do anything to this datafield
                $gdfp->setCanViewDatafield(0);
                $gdfp->setCanEditDatafield(0);
            }

            // Intentionally don't flush here...
            $this->em->persist($gdfp);
        }


        // ----------------------------------------
        // Automatically add super-admin users to new default "admin" groups
        if ($initial_purpose == 'admin') {
            $query = $this->em->createQuery(
               'SELECT u
                FROM ODROpenRepositoryUserBundle:User AS u
                WHERE u.enabled = 1'
            );
            $user_list = $query->getResult();
            /** @var ODRUser[] $user_list */

            // Locate those with super-admin permissions...
            foreach ($user_list as $u) {
                if ( $u->hasRole('ROLE_SUPER_ADMIN') ) {
                    // ...add the super admin to this new admin group
                    self::createUserGroup($u, $group, $user, true);    // don't flush immediately...

                    // ...delete the cached list of permissions for each super-admin belongs to
                    $this->cache_service->delete('user_'.$u->getId().'_permissions');
                }
            }
        }

        // Flush here unless otherwise required
        if ( !$delay_flush ) {
            $this->em->flush();
            $this->em->refresh($group);
        }

        return $group;
    }


    /**
     * Creates GroupDatatypePermissions for all groups when a new datatype is created.  Should NOT
     * be called as part of creating a new Group.
     *
     * This function doesn't permit delaying flushes, because it's impossible to lock properly.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     */
    public function createGroupsForDatatype($user, $datatype)
    {
        // Store whether this is a top-level datatype or not
        $datatype_id = $datatype->getId();
        $grandparent_datatype_id = $datatype->getGrandparent()->getId();

        $is_top_level = true;
        if ($datatype_id != $grandparent_datatype_id)
            $is_top_level = false;

        // Locate all groups for this datatype's grandparent
        $repo_group = $this->em->getRepository('ODRAdminBundle:Group');

        /** @var Group[] $groups */
        if ($is_top_level) {
            // Create any default groups the top-level datatype is currently missing...
            $admin_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'admin') );
            if ($admin_group == null)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'admin', true);    // don't flush immediately...

            $edit_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'edit_all') );
            if ($edit_group == null)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'edit_all', true);    // don't flush immediately...

            $view_all_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'view_all') );
            if ($view_all_group == null)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'view_all', true);    // don't flush immediately...

            $view_only_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'view_only') );
            if ($view_only_group == null)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'view_only', true);    // don't flush immediately

            // Flush once all groups are created
            $this->em->flush();
        }
        else {
            // Load all groups belonging to the grandparent datatype
            $groups = $repo_group->findBy( array('dataType' => $grandparent_datatype_id) );
            if ($groups == false)
                throw new ODRException('createGroupsForDatatype(): grandparent datatype '.$grandparent_datatype_id.' has no groups for child datatype '.$datatype->getId().' to copy from.');

            // Ensure the grandparent datatype has all of its default groups
            $has_admin = $has_edit = $has_view_all = $has_view_only = false;
            foreach ($groups as $group) {
                if ($group->getPurpose() == 'admin')
                    $has_admin = true;
                if ($group->getPurpose() == 'edit_all')
                    $has_edit = true;
                if ($group->getPurpose() == 'view_all')
                    $has_view_all = true;
                if ($group->getPurpose() == 'view_only')
                    $has_view_only = true;
            }

            if (!$has_admin || !$has_edit || !$has_view_all || !$has_view_only)
                throw new ODRException('createGroupsForDatatype(): grandparent datatype '.$grandparent_datatype_id.' is missing a default group for child datatype '.$datatype->getId().' to copy from.');


            // Load the list of groups and users this will affect
            $group_list = array();
            foreach ($groups as $group)
                $group_list[] = $group->getId();

            $query = $this->em->createQuery(
               'SELECT DISTINCT(u.id) AS user_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE ug.group IN (:groups)
                AND ug.deletedAt IS NULL'
            )->setParameters( array('groups' => $group_list) );
            $results = $query->getArrayResult();

            $user_list = array();
            foreach ($results as $result)
                $user_list[] = $result['user_id'];


            // ----------------------------------------
            // Need to create a GroupDatatypePermission for each group in this datatype...
            foreach ($groups as $group) {
                // Default permissions depend on the original purpose of this group...
                $initial_purpose = $group->getPurpose();

                $gdtp = new GroupDatatypePermissions();
                $gdtp->setGroup($group);
                $gdtp->setDataType($datatype);

                $gdtp->setCreated(new \DateTime());
                $gdtp->setCreated(new \DateTime());
                $gdtp->setCreatedBy($user);
                $gdtp->setUpdatedBy($user);

                // Default all permissions to false...
                $gdtp->setIsDatatypeAdmin(false);
                $gdtp->setCanDesignDatatype(false);
                $gdtp->setCanDeleteDatarecord(false);
                $gdtp->setCanAddDatarecord(false);
                $gdtp->setCanViewDatarecord(false);
                $gdtp->setCanViewDatatype(false);

                switch ($initial_purpose) {
                    case 'admin':
                        // the 'admin' group gets all permissions below this line
                        $gdtp->setIsDatatypeAdmin(true);
                        $gdtp->setCanDesignDatatype(true);
                    case 'edit_all':
                        // the 'edit_all' group gets all permissions below this line
                        $gdtp->setCanDeleteDatarecord(true);
                        $gdtp->setCanAddDatarecord(true);
                    case 'view_all':
                        // the 'view_all' group gets all permissions below this line
                        $gdtp->setCanViewDatarecord(true);
                    case 'view_only':
                        // the 'view_only' group gets all permissions below this line
                        $gdtp->setCanViewDatatype(true);
                        break;

                    default:
                        break;
                }

                // Groups for child datatypes don't get the can_view_datatype permission by default
                $this->em->persist($gdtp);
            }

            // Flush now that all the datatype permission entries have been created
            $this->em->flush();


            // ----------------------------------------
            // Delete all cached versions of the groups that got modified
            foreach ($groups as $group)
                $this->cache_service->delete('group_'.$group->getId().'_permissions');

            // Delete all permission entries for each affected user
            foreach ($user_list as $user_id)
                $this->cache_service->delete('user_'.$user_id.'_permissions');
        }
    }


    /**
     * Creates GroupDatafieldPermissions for all groups when a new datafield is created, and updates
     * existing cache entries for groups and users with the new datafield.
     *
     * Should NOT be called as part of creating a new Group.
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param bool $delay_flush
     */
    public function createGroupsForDatafield($user, $datafield, $delay_flush = false)
    {
        // ----------------------------------------
        // Locate this datafield's datatype's grandparent
        $grandparent_datatype_id = $datafield->getDataType()->getGrandparent()->getId();

        // Locate all groups for this datatype's grandparent
        /** @var Group[] $groups */
        $groups = $this->em->getRepository('ODRAdminBundle:Group')->findBy( array('dataType' => $grandparent_datatype_id) );
        // Unless something has gone wrong previously, there should always be results in this
        if ($groups == false)
            throw new ODRException('createGroupsForDatatype(): grandparent datatype '.$grandparent_datatype_id.' has no groups for datafield '.$datafield->getId().' to copy from.');


        // ----------------------------------------
        // Load the list of groups and users this INSERT INTO query will affect
        $group_list = array();
        foreach ($groups as $group)
            $group_list[] = $group->getId();

        $query = $this->em->createQuery(
           'SELECT DISTINCT(u.id) AS user_id
            FROM ODRAdminBundle:UserGroup AS ug
            JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
            WHERE ug.group IN (:groups)
            AND ug.deletedAt IS NULL'
        )->setParameters( array('groups' => $group_list) );
        $results = $query->getArrayResult();

        $user_list = array();
        foreach ($results as $result)
            $user_list[] = $result['user_id'];


        // ----------------------------------------
        // Every group for this datatype is going to need a GroupDatafieldPermission for this new
        //  datafield...
        foreach ($groups as $group) {
            $gdfp = new GroupDatafieldPermissions();

            $gdfp->setGroup($group);
            $gdfp->setDataField($datafield);

            $gdfp->setCreated(new \DateTime());
            $gdfp->setUpdated(new \DateTime());
            $gdfp->setCreatedBy($user);
            $gdfp->setUpdatedBy($user);

            $initial_purpose = $group->getPurpose();
            if ($initial_purpose == 'admin' || $initial_purpose == 'edit_all') {
                // "admin" and "edit_all" groups can both see and edit this new datafield by default
                $gdfp->setCanViewDatafield(1);
                $gdfp->setCanEditDatafield(1);
            }
            else if ($initial_purpose == 'view_all') {
                // The "view_all" group defaults to being able to see, but not edit, this datafield
                $gdfp->setCanViewDatafield(1);
                $gdfp->setCanEditDatafield(0);
            }
            else {
                // All other groups default to not being able to do anything to this datafield
                $gdfp->setCanViewDatafield(0);
                $gdfp->setCanEditDatafield(0);
            }

            $this->em->persist($gdfp);

            // Delete this group's cached permissions
            $this->cache_service->delete('group_'.$group->getId().'_permissions');
        }

        // Also delete all permission entries for each affected user
        foreach ($user_list as $user_id)
            $this->cache_service->delete('user_'.$user_id.'_permissions');


        if ( !$delay_flush )
            $this->em->flush();
    }


    /**
     * Creates a new RadioOption entity.  If a new radio option is being forcibly created, this
     * function does not automatically flush.  Otherwise, a lock is used to ensure no duplicates
     * are created, and the result is immediately flushed.
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param boolean $force_create If true, always create a new RadioOption...otherwise attempt to
     *                              find and return the existing RadioOption with the given $datafield
     *                              and $option_name first
     * @param string $option_name
     *
     * @return RadioOptions
     */
    public function createRadioOption($user, $datafield, $force_create, $option_name)
    {
        $radio_option = null;
        if ($force_create) {
            // We're being forced to create a new radio option...
            $radio_option = self::createRadioOptionEntity($user, $datafield, $option_name);
        }
        else {
            // Otherwise, see if a radio option with this name for this datafield already exists
            /** @var RadioOptions $radio_option */
            $radio_option = $this->em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                array(
                    'optionName' => $option_name,
                    'dataField' => $datafield->getId()
                )
            );

            // If it does, then return that one...
            if ( $radio_option != null )
                return $radio_option;

            // ...if not, then acquire a lock
            // TODO - symfony seems to just drop characters out of $option_name that are illegal for filenames?  can't use hashes, those aren't guaranteed to be unique either...
            $lockHandler = new LockHandler('ro_'.$datafield->getId().'_'.$option_name.'.lock');
            if (!$lockHandler->lock()) {
                // Another process is attempting to create this radio option...block until it finishes...
                $lockHandler->lock(true);

                // ...then reload and return what the other process created
                $radio_option = $this->em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                    array(
                        'optionName' => $option_name,
                        'dataField' => $datafield->getId()
                    )
                );
                return $radio_option;
            }
            else {
                // Got the lock, create the radio option entry
                $radio_option = self::createRadioOptionEntity($user, $datafield, $option_name);

                $this->em->persist($radio_option);
                $this->em->flush();
                $this->em->refresh($radio_option);

                // Now that the radio option is created, release the lock on it
                $lockHandler->release();
            }
        }

        return $radio_option;
    }


    /**
     * Split out from self::createRadioOption() for readability
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param string $option_name
     *
     * @return RadioOptions
     */
    private function createRadioOptionEntity($user, $datafield, $option_name)
    {
        /** @var RadioOptions $radio_option */
        $radio_option = new RadioOptions();
        $radio_option->setDataField($datafield);
        $radio_option->setOptionName($option_name);     // exists to prevent potential concurrency issues, see below

        // All new fields require a radio option UUID
        $radio_option->setRadioOptionUuid( $this->uuid_service->generateRadioOptionUniqueId() );
        $radio_option->setCreatedBy($user);
        $radio_option->setCreated(new \DateTime());

        // Ensure the "in-memory" version of the datafield knows about the new radio option
        $datafield->addRadioOption($radio_option);
        $this->em->persist($radio_option);

        // Create a new RadioOptionMeta entity
        /** @var RadioOptionsMeta $radio_option_meta */
        $radio_option_meta = new RadioOptionsMeta();
        $radio_option_meta->setRadioOption($radio_option);
        $radio_option_meta->setOptionName($option_name);
        $radio_option_meta->setXmlOptionName('');
        $radio_option_meta->setDisplayOrder(0);
        $radio_option_meta->setIsDefault(false);

        $radio_option_meta->setCreatedBy($user);
        $radio_option_meta->setCreated( new \DateTime() );

        // Ensure the "in-memory" version of the new radio option knows about its meta entry
        $radio_option->addRadioOptionMetum($radio_option_meta);
        $this->em->persist($radio_option_meta);

        return $radio_option;
    }


    /**
     * Creates a new RadioSelection entity for the specified RadioOption/Datarecordfield pair if one
     * doesn't already exist.
     *
     * This function doesn't permit delaying flushes, because it's impossible to lock properly.
     *
     * @param ODRUser $user
     * @param RadioOptions $radio_option
     * @param DataRecordFields $drf
     *
     * @return RadioSelection
     */
    public function createRadioSelection($user, $radio_option, $drf)
    {
        /** @var RadioSelection $radio_selection */
        $radio_selection = $this->em->getRepository('ODRAdminBundle:RadioSelection')->findOneBy(
            array(
                'dataRecordFields' => $drf->getId(),
                'radioOption' => $radio_option->getId()
            )
        );
        if ($radio_selection == null) {
            // Need to create a new radio selection entry...

            // Bad Things (tm) happen if there's more than one radio selection entry for this
            //  radioOption/drf pair, so use Symfony's LockHandler component to prevent that...
            $lockHandler = new LockHandler('rs_'.$radio_option->getId().'_'.$drf->getId().'.lock');
            if (!$lockHandler->lock()) {
                // Another process is attempting to create this entry...block until it finishes...
                $lockHandler->lock(true);

                // ...then reload and return the drf that the other process created
                $radio_selection = $this->em->getRepository('ODRAdminBundle:RadioSelection')->findOneBy(
                    array(
                        'dataRecordFields' => $drf->getId(),
                        'radioOption' => $radio_option->getId()
                    )
                );
                return $radio_selection;
            }
            else {
                // Got the lock, create the radio selection
                $radio_selection = new RadioSelection();
                $radio_selection->setRadioOption($radio_option);
                $radio_selection->setDataRecordFields($drf);

                $radio_selection->setSelected(0);    // defaults to not selected

                $radio_selection->setCreated(new \DateTime());
                $radio_selection->setUpdated(new \DateTime());
                $radio_selection->setCreatedBy($user);
                $radio_selection->setUpdatedBy($user);

                $this->em->persist($radio_selection);
                $this->em->flush();
                $this->em->refresh($radio_selection);

                // Now that the radio selection is is created, release the lock on it
                $lockHandler->release();
            }
        }

        return $radio_selection;
    }


    /**
     * Creates, persists, and flushes a new RenderPluginInstance entity.
     *
     * @param ODRUser $user
     * @param RenderPlugin $render_plugin
     * @param DataType|null $datatype
     * @param DataFields|null $datafield
     * @param bool $delay_flush
     *
     * @return RenderPluginInstance
     */
    public function createRenderPluginInstance($user, $render_plugin, $datatype, $datafield, $delay_flush = false)
    {
        // Ensure a RenderPlugin for a Datatype plugin doesn't get assigned to a Datafield, or a RenderPlugin for a Datafield doesn't get assigned to a Datatype
        if ( $render_plugin->getPluginType() == RenderPlugin::DATATYPE_PLUGIN && is_null($datatype) )
            throw new \Exception('Unable to create an instance of the RenderPlugin "'.$render_plugin->getPluginName().'" for a null Datatype');
        else if ( $render_plugin->getPluginType() == RenderPlugin::DATAFIELD_PLUGIN && is_null($datafield) )
            throw new \Exception('Unable to create an instance of the RenderPlugin "'.$render_plugin->getPluginName().'" for a null Datafield');

        // Create the new RenderPluginInstance
        $rpi = new RenderPluginInstance();
        $rpi->setRenderPlugin($render_plugin);
        $rpi->setDataType($datatype);
        $rpi->setDataField($datafield);

        $rpi->setActive(true);

        $rpi->setCreatedBy($user);
        $rpi->setUpdatedBy($user);

        $this->em->persist($rpi);

        if ( !$delay_flush ) {
            $this->em->flush();
            $this->em->refresh($rpi);
        }

        return $rpi;
    }


    /**
     * Creates and persists a new RenderPluginMap entity.
     *
     * @param ODRUser $user
     * @param RenderPluginInstance $rpi
     * @param RenderPluginFields $rpf
     * @param DataType|null $dt
     * @param DataFields $df
     * @param bool $delay_flush
     *
     * @return RenderPluginMap
     */
    public function createRenderPluginMap($user, $rpi, $rpf, $dt, $df, $delay_flush = false)
    {
        $rpm = new RenderPluginMap();
        $rpm->setRenderPluginInstance($rpi);
        $rpm->setRenderPluginFields($rpf);

        $rpm->setDataType($dt);
        $rpm->setDataField($df);

        $rpm->setCreatedBy($user);
        $rpm->setUpdatedBy($user);

        $this->em->persist($rpm);

        if ( !$delay_flush )
            $this->em->flush();

        return $rpm;
    }


    /**
     * Creates and persists a new RenderPluginOption entity.
     *
     * @param ODRUser $user
     * @param RenderPluginInstance $render_plugin_instance
     * @param string $option_name
     * @param string $option_value
     * @param bool $delay_flush
     *
     * @return RenderPluginOptions
     */
    public function createRenderPluginOption($user, $render_plugin_instance, $option_name, $option_value, $delay_flush = false)
    {
        $rpo = new RenderPluginOptions();
        $rpo->setRenderPluginInstance($render_plugin_instance);
        $rpo->setOptionName($option_name);
        $rpo->setOptionValue($option_value);

        $rpo->setActive(true);

        $rpo->setCreatedBy($user);
        $rpo->setUpdatedBy($user);

        $this->em->persist($rpo);

        if ( !$delay_flush )
            $this->em->flush();

        return $rpo;
    }


    /**
     * Creates, persists, and flushes a new storage entity.
     *
     * This function doesn't permit delaying flushes, because it's impossible to lock properly.
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param DataFields $datafield
     * @param boolean|integer|string|\DateTime $initial_value
     *
     * @return ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    public function createStorageEntity($user, $datarecord, $datafield, $initial_value = null)
    {
        // Locate the table name that will be inserted into if the storage entity doesn't exist
        $fieldtype = $datafield->getFieldType();
        $typeclass = $fieldtype->getTypeClass();

        $default_value = '';
        switch ($typeclass) {
            case 'Boolean':
                $default_value = false;
                break;
            case 'DatetimeValue':
                $default_value = '9999-12-31 00:00:00';
                break;

            // Both of these use null as their default value
            case 'DecimalValue':
                $default_value = null;
                break;
            case 'IntegerValue':
                $default_value = null;
                break;

            // The rest of these use the empty string as their default value
            case 'LongText':    // paragraph text
            case 'LongVarchar':
            case 'MediumVarchar':
            case 'ShortVarchar':
                break;

            case 'File':
            case 'Image':
            case 'Radio':
            case 'Tag':
            case 'Markdown':
            default:
                throw new \Exception('ODR_addStorageEntity() called on invalid fieldtype "'.$typeclass.'"');
                break;
        }


        // Return the storage entity if it already exists
        /** @var ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $storage_entity */
        $storage_entity = $this->em->getRepository('ODRAdminBundle:'.$typeclass)->findOneBy(
            array(
                'dataRecord' => $datarecord->getId(),
                'dataField' => $datafield->getId()
            )
        );
        if ( $storage_entity == null ) {
            // Bad Things (tm) happen if there's more than one storage entity for this
            //  datarecord/datafield/fieldtype tuple, so use Symfony's LockHandler component to
            //  prevent that...
            $lockHandler = new LockHandler('storage_'.$datarecord->getId().'_'.$datafield->getId().'.lock');
            if (!$lockHandler->lock()) {
                // Another process is attempting to create this storage entity...wait for it to finish...
                $lockHandler->lock(true);

                // ...then reload and return the drf that got created
                $storage_entity = $this->em->getRepository('ODRAdminBundle:'.$typeclass)->findOneBy(
                    array(
                        'dataRecord' => $datarecord->getId(),
                        'dataField' => $datafield->getId()
                    )
                );
                return $storage_entity;
            }
            else {
                // Got the lock, locate/create the datarecordfield entity for this
                $drf = self::createDatarecordField($user, $datarecord, $datafield);

                // Determine which value to use for the default value
                $insert_value = null;
                if ( !is_null($initial_value) )
                    $insert_value = $initial_value;
                else
                    $insert_value = $default_value;

                // Create the storage entity
                $class = "ODR\\AdminBundle\\Entity\\".$typeclass;
                $storage_entity = new $class();
                $storage_entity->setDataRecord($datarecord);
                $storage_entity->setDataField($datafield);
                $storage_entity->setDataRecordFields($drf);
                $storage_entity->setFieldType($fieldtype);

                if ($typeclass !== 'DatetimeValue')
                    $storage_entity->setValue($insert_value);
                else
                    $storage_entity->setValue( new \DateTime($insert_value) );

                if ($typeclass === 'DecimalValue')
                    $storage_entity->setOriginalValue($insert_value);

                $storage_entity->setCreated(new \DateTime());
                $storage_entity->setUpdated(new \DateTime());
                $storage_entity->setCreatedBy($user);
                $storage_entity->setUpdatedBy($user);

                $this->em->persist($storage_entity);
                $this->em->flush();
                $this->em->refresh($storage_entity);

                // Now that the storage entity is is created, release the lock on it
                $lockHandler->release();
            }
        }

        return $storage_entity;
    }


    /**
     * Creates a new Tag entity.  If a new tag is being forcibly created, this function does not
     * automatically flush.  Otherwise, a lock is used to ensure no duplicates are created, and the
     * result is immediately flushed.
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param boolean $force_create If true, always create a new Tag...otherwise attempt to find
     *                              and return the existing Tag with the given $datafield and
     *                              $tag_name first
     * @param string $tag_name
     * @param bool $delay_uuid If true, don't automatically create a uuid for this tag...the caller
     *                         will need to take care of it.  Only really needs to be true during
     *                         mass tag imports.
     *
     * @return Tags
     */
    public function createTag($user, $datafield, $force_create, $tag_name, $delay_uuid = false)
    {

        $tag = null;
        if ($force_create) {
            // We're being forced to create a new top-level tag...
            $tag = self::createTagEntity($user, $datafield, $tag_name, $delay_uuid);
        }
        else {
            // Otherwise, see if a tag with this name for this datafield already exists
            /** @var Tags $tag */
            $tag = $this->em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                array(
                    'tagName' => $tag_name,    // TODO - ...this isn't guaranteed to be the correct tag when a hierarchy is involved...
                    'dataField' => $datafield->getId()
                )
            );

            // If it does, then return that one...
            if ( $tag != null )
                return $tag;

            // ...if not, then acquire a lock
            // TODO - symfony seems to just drop characters out of $option_name that are illegal for filenames?  can't use hashes, those aren't guaranteed to be unique either...
            $lockHandler = new LockHandler('tag_'.$datafield->getId().'_'.$tag_name.'.lock');
            if (!$lockHandler->lock()) {
                // Another process is attempting to create this tag...block until it finishes...
                $lockHandler->lock(true);

                // ...then reload and return what the other process created
                $tag = $this->em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                    array(
                        'tagName' => $tag_name,
                        'dataField' => $datafield->getId()
                    )
                );
                return $tag;
            }
            else {
                // Got the lock, create the tag entry
                $tag = self::createTagEntity($user, $datafield, $tag_name, $delay_uuid);

                $this->em->persist($tag);
                $this->em->flush();
                $this->em->refresh($tag);

                // Now that the tag is created, release the lock on it
                $lockHandler->release();
            }
        }

        return $tag;
    }


    /**
     * Split out from self::createTag() for readability
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param string $tag_name
     * @param bool $delay_uuid If true, don't automatically create a uuid for this tag...the caller
     *                         will need to take care of it
     *
     * @return Tags
     */
    private function createTagEntity($user, $datafield, $tag_name, $delay_uuid)
    {
        /** @var Tags $tag */
        $tag = new Tags();
        $tag->setDataField($datafield);
        $tag->setTagName($tag_name);     // exists to prevent potential concurrency issues, see below

        if (!$delay_uuid)
            $tag->setTagUuid( $this->uuid_service->generateTagUniqueId() );

        $tag->setCreatedBy($user);
        $tag->setCreated(new \DateTime());

        // Ensure the "in-memory" version of the datafield knows about the new tag
        $datafield->addTag($tag);
        $this->em->persist($tag);

        // Create a new TagMeta entity
        /** @var TagMeta $tag_meta */
        $tag_meta = new TagMeta();
        $tag_meta->setTag($tag);
        $tag_meta->setTagName($tag_name);
        $tag_meta->setXmlTagName('');
        $tag_meta->setDisplayOrder(9999);    // append new tags to the end

        $tag_meta->setCreatedBy($user);
        $tag_meta->setCreated( new \DateTime() );

        // Ensure the "in-memory" version of the new tag knows about its meta entry
        $tag->addTagMetum($tag_meta);
        $this->em->persist($tag_meta);

        return $tag;
    }


    /**
     * Create a tag link from $ancestor_tag to $descendant_tag.
     *
     * This function doesn't permit delaying flushes, because it's impossible to lock properly.
     * TODO - this idea of locking at the entity level only partially solves the problem...
     * TODO - ...it technically prevents duplicates, but it's completely incompatible with delaying flushes
     * TODO - also, this function basically requires both parent and child tag to be flushed before it'll work...
     *
     * @param ODRUser $user
     * @param Tags $parent_tag
     * @param Tags $child_tag
     *
     * @return TagTree
     */
    public function createTagTree($user, $parent_tag, $child_tag)
    {
        // Check to see if the two tags are already linked
        /** @var TagTree $tag_tree */
        $tag_tree = $this->em->getRepository('ODRAdminBundle:TagTree')->findOneBy(
            array(
                'parent' => $parent_tag,
                'child' => $child_tag,
            )
        );

        if ($tag_tree == null) {
            // Use Symfony's file locking to ensure there's at most one (ancestor, descendant) pair
            $lockHandler = new LockHandler('tt_'.$parent_tag->getId().'_'.$child_tag->getId().'.lock');
            if ( !$lockHandler->lock() ) {
                // Another process is attempting to create this entity...wait for it to finish...
                $lockHandler->lock(true);

                // ...then reload and return the tag tree that got created
                $tag_tree = $this->em->getRepository('ODRAdminBundle:TagTree')->findOneBy(
                    array(
                        'parent' => $parent_tag,
                        'child' => $child_tag,
                    )
                );
                return $tag_tree;
            }
            else {
                // No link exists, create a new entity
                $tag_tree = new TagTree();
                $tag_tree->setParent($parent_tag);
                $tag_tree->setChild($child_tag);

                $tag_tree->setCreatedBy($user);

                $this->em->persist($tag_tree);
                $this->em->flush();

                // Now that the entity exists, release the lock
                $lockHandler->release();
            }
        }

        // A link either already exists, or was just created...return the entity
        return $tag_tree;
    }


    /**
     * Creates a new TagSelection entity for the specified Tag/Datarecordfield pair if one doesn't
     * already exist.
     *
     * This function doesn't permit delaying flushes, because it's impossible to lock properly.
     *
     * @param ODRUser $user
     * @param Tags $tag
     * @param DataRecordFields $drf
     *
     * @return TagSelection
     */
    public function createTagSelection($user, $tag, $drf)
    {
        /** @var TagSelection $tag_selection */
        $tag_selection = $this->em->getRepository('ODRAdminBundle:TagSelection')->findOneBy(
            array(
                'dataRecordFields' => $drf->getId(),
                'tag' => $tag->getId()
            )
        );
        if ($tag_selection == null) {
            // Need to create a new tag selection entry...

            // Bad Things (tm) happen if there's more than one tag selection entry for this
            //  tag/drf pair, so use Symfony's LockHandler component to prevent that...
            $lockHandler = new LockHandler('tag_'.$tag->getId().'_'.$drf->getId().'.lock');
            if (!$lockHandler->lock()) {
                // Another process is attempting to create this entry...block until it finishes...
                $lockHandler->lock(true);

                // ...then reload and return the tag selection that the other process created
                $tag_selection = $this->em->getRepository('ODRAdminBundle:TagSelection')->findOneBy(
                    array(
                        'dataRecordFields' => $drf->getId(),
                        'tag' => $tag->getId()
                    )
                );
                return $tag_selection;
            }
            else {
                // Got the lock, create the tag selection
                $tag_selection = new TagSelection();
                $tag_selection->setTag($tag);
                $tag_selection->setDataRecordFields($drf);

                $tag_selection->setSelected(0);    // defaults to not selected

                $tag_selection->setCreated(new \DateTime());
                $tag_selection->setUpdated(new \DateTime());
                $tag_selection->setCreatedBy($user);
                $tag_selection->setUpdatedBy($user);

                $this->em->persist($tag_selection);
                $this->em->flush();
                $this->em->refresh($tag_selection);

                // Now that the tag selection is is created, release the lock on it
                $lockHandler->release();
            }
        }

        return $tag_selection;
    }


    /**
     * Creates and persists a new Theme and its ThemeMeta entry.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param bool $delay_flush
     *
     * @return Theme
     */
    public function createTheme($user, $datatype, $delay_flush = false)
    {
        // Initial create
        $theme = new Theme();
        $theme->setDataType($datatype);

        // Assume top-level master theme
        $theme->setThemeType('master');
        $theme->setParentTheme($theme);
        $theme->setSourceTheme($theme);

        $theme->setCreatedBy($user);
        $theme->setUpdatedBy($user);

        $datatype->addTheme($theme);
        $this->em->persist($theme);

        $theme_meta = new ThemeMeta();
        $theme_meta->setTheme($theme);
        $theme_meta->setTemplateName('');
        $theme_meta->setTemplateDescription('');

        $theme_meta->setIsDefault(false);
        $theme_meta->setShared(false);
        $theme_meta->setIsTableTheme(false);

        $theme_meta->setSourceSyncVersion(1);

        // Currently unused...
        $theme_meta->setDisplayOrder(null);

        $theme_meta->setCreatedBy($user);
        $theme_meta->setUpdatedBy($user);

        // Ensure the new theme knows about its meta entry
        $theme->addThemeMetum($theme_meta);
        $this->em->persist($theme_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $theme;
    }


    /**
     * Creates and persists a new ThemeDataField entity.
     *
     * Despite technically not allowing duplicate (theme_element/datafield) pairs, it doesn't make
     * sense to lock this...the functions calling this require delayed flushes, and should already
     * ensure duplicates aren't created.
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param ThemeElement $theme_element
     *
     * @return ThemeDataField
     */
    public function createThemeDatafield($user, $theme_element, $datafield, $delay_flush = false)
    {
        // Create theme entry
        $theme_datafield = new ThemeDataField();
        $theme_datafield->setDataField($datafield);
        $theme_datafield->setThemeElement($theme_element);

        $theme_datafield->setDisplayOrder(999);
        $theme_datafield->setCssWidthMed('1-3');
        $theme_datafield->setCssWidthXL('1-3');
        $theme_datafield->setHidden(0);

        $theme_datafield->setCreatedBy($user);
        $theme_datafield->setUpdatedBy($user);

        $theme_element->addThemeDataField($theme_datafield);
        $this->em->persist($theme_datafield);

        if ( !$delay_flush )
            $this->em->flush();

        return $theme_datafield;
    }


    /**
     * Creates and persists a new ThemeDataType entity.
     *
     * Despite technically not allowing duplicate (theme_element/datatype) pairs, it doesn't make
     * sense to lock this...the functions calling this require delayed flushes, and should already
     * ensure duplicates aren't created.
     *
     * @param ODRUser $user
     * @param ThemeElement $theme_element
     * @param DataType $datatype
     * @param Theme $child_theme
     * @param bool $delay_flush
     *
     * @return ThemeDataType
     */
    public function createThemeDatatype($user, $theme_element, $datatype, $child_theme, $delay_flush = false)
    {
        // Create theme entry
        $theme_datatype = new ThemeDataType();
        $theme_datatype->setDataType($datatype);
        $theme_datatype->setThemeElement($theme_element);
        $theme_datatype->setChildTheme($child_theme);

        $theme_datatype->setDisplayType(0);     // 0 is accordion, 1 is tabbed, 2 is dropdown, 3 is list
        $theme_datatype->setHidden(0);

        $theme_datatype->setCreatedBy($user);
        $theme_datatype->setUpdatedBy($user);

        $theme_element->addThemeDataType($theme_datatype);
        $this->em->persist($theme_datatype);

        if ( !$delay_flush )
            $this->em->flush();

        return $theme_datatype;
    }


    /**
     * Creates and persists a new ThemeElement and its ThemeElementMeta entry.
     *
     * @param ODRUser $user
     * @param Theme $theme
     * @param bool $delay_flush
     *
     * @return ThemeElement
     */
    public function createThemeElement($user, $theme, $delay_flush = false)
    {
        // Initial create
        $theme_element = new ThemeElement();

        $theme_element->setTheme($theme);
        $theme_element->setCreatedBy($user);

        $theme->addThemeElement($theme_element);
        $this->em->persist($theme_element);

        $theme_element_meta = new ThemeElementMeta();
        $theme_element_meta->setThemeElement($theme_element);

        $theme_element_meta->setDisplayOrder(-1);
        $theme_element_meta->setHidden(0);
        $theme_element_meta->setCssWidthMed('1-1');
        $theme_element_meta->setCssWidthXL('1-1');

        $theme_element_meta->setCreatedBy($user);
        $theme_element_meta->setUpdatedBy($user);

        // Ensure the new theme element knows about its meta entry
        $theme_element->addThemeElementMetum($theme_element_meta);
        $this->em->persist($theme_element_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $theme_element;
    }


    /**
     * Ensures the given user is in the given group.
     *
     * @param ODRUser $user
     * @param Group $group
     * @param ODRUser $admin_user
     * @param bool $delay_flush
     * @param bool $check_group
     *
     * @return UserGroup
     */
    public function createUserGroup($user, $group, $admin_user, $delay_flush = false, $check_group = true)
    {
        // Check to see if the User already belongs to this Group
        // This will be bypassed in the case of newly created groups.
        if ($check_group) {
            $query = $this->em->createQuery(
               'SELECT ug
                FROM ODRAdminBundle:UserGroup AS ug
                WHERE ug.user = :user_id AND ug.group = :group_id
                AND ug.deletedAt IS NULL'
            )->setParameters( array('user_id' => $user->getId(), 'group_id' => $group->getId()) );

            /** @var UserGroup[] $results */
            $results = $query->getResult();

            $user_group = null;
            if ( count($results) > 0 ) {
                // If an existing UserGroup entity was found, return it and don't do anything else
                // TODO This works but is strange....
                foreach ($results as $num => $ug)
                    return $ug;
            }
        }

        // ...otherwise, create a new UserGroup entity
        $user_group = new UserGroup();
        $user_group->setUser($user);
        $user_group->setGroup($group);
        $user_group->setCreatedBy($admin_user);

        // Ensure the "in-memory" versions of both the User and Group entities know about the new UserGroup entity
        $group->addUserGroup($user_group);
        $user->addUserGroup($user_group);

        // Save all changes
        $this->em->persist($user_group);
        if ( !$delay_flush )
            $this->em->flush();

        return $user_group;
    }
}
