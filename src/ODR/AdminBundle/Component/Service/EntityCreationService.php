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
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\GroupMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\ImageSizes;
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
use ODR\AdminBundle\Entity\RenderPluginOptionsDef;
use ODR\AdminBundle\Entity\RenderPluginOptionsMap;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\SidebarLayout;
use ODR\AdminBundle\Entity\SidebarLayoutMap;
use ODR\AdminBundle\Entity\SidebarLayoutMeta;
use ODR\AdminBundle\Entity\StoredSearchKey;
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
use ODR\AdminBundle\Entity\ThemeRenderPluginInstance;
use ODR\AdminBundle\Entity\UserGroup;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Events
use ODR\AdminBundle\Component\Event\PostUpdateEvent;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;


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
     * @var LockService
     */
    private $lock_service;

    /**
     * @var UUIDService
     */
    private $uuid_service;

    /**
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

    // NOTE - $event_dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode

    /**
     * @var Logger
     */
    private $logger;


    /**
     * EntityCreationService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param LockService $lock_service
     * @param UUIDService $uuid_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        LockService $lock_service,
        UUIDService $uuid_service,
        EventDispatcherInterface $event_dispatcher,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->lock_service = $lock_service;
        $this->uuid_service = $uuid_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->logger = $logger;
    }


    /**
     * Creates and persists a new Datafield and DatafieldMeta entity, and creates groups for it as
     * well
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param FieldType $fieldtype
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataFields
     */
    public function createDatafield($user, $datatype, $fieldtype, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        // Populate new DataFields form
        $datafield = new DataFields();
        $datafield->setDataType($datatype);

        $datafield->setCreated($created);
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
        $datafield_meta->setForceNumericSort(false);
        $datafield_meta->setRequired(false);
        $datafield_meta->setPreventUserEdits(false);
        $datafield_meta->setSearchable(DataFields::NOT_SEARCHABLE);
        $datafield_meta->setPublicDate( new \DateTime('2200-01-01 00:00:00') );

        $datafield_meta->setChildrenPerRow(1);
        $datafield_meta->setRadioOptionNameSort(false);
        $datafield_meta->setRadioOptionDisplayUnselected(false);
        $datafield_meta->setMergeByAND(false);
        $datafield_meta->setSearchCanRequestBothMerges(false);
        $datafield_meta->setTagsAllowNonAdminEdit(false);
        $datafield_meta->setTagsAllowMultipleLevels(false);
        if ( $fieldtype->getTypeClass() === 'File' || $fieldtype->getTypeClass() === 'Image' ) {
            $datafield_meta->setAllowMultipleUploads(true);
            $datafield_meta->setShortenFilename(true);
        }
        else {
            $datafield_meta->setAllowMultipleUploads(false);
            $datafield_meta->setShortenFilename(false);
        }
        $datafield_meta->setNewFilesArePublic(false);    // Newly uploaded files/images default to non-public
        $datafield_meta->setQualityStr('');

        $datafield_meta->setCreated($created);
        $datafield_meta->setUpdated($created);
        $datafield_meta->setCreatedBy($user);
        $datafield_meta->setUpdatedBy($user);

        // Ensure the datafield knows about its meta entry
        $datafield->addDataFieldMetum($datafield_meta);
        $this->em->persist($datafield_meta);

        if ( !$delay_flush )
            $this->em->flush();


        // Add the datafield to all groups for this datatype
        self::createGroupsForDatafield($user, $datafield, $delay_flush, $created);

        return $datafield;
    }


    /**
     * Creates and persists a new Datarecord and a new DatarecordMeta entity.  The user will need
     * to set the provisioned property back to false eventually.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param bool $select_default_radio_options If true, then relevant default radio options are automatically located and marked as selected
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataRecord
     */
    public function createDatarecord($user, $datatype, $delay_flush = false, $select_default_radio_options = true, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        // Initial create
        $datarecord = new DataRecord();
        $datarecord->setDataType($datatype);

        $datarecord->setCreated($created);
        $datarecord->setUpdated($created);
        $datarecord->setCreatedBy($user);
        $datarecord->setUpdatedBy($user);

        // Default to assuming this is a top-level datarecord
        $datarecord->setParent($datarecord);
        $datarecord->setGrandparent($datarecord);

        // TODO - the part about "most areas" is not correct, it's currently only checked in EditController::editAction()
        // TODO - ...does it actually need to be checked in more places, though?
        $datarecord->setProvisioned(true);  // Prevent most areas of the site from doing anything with this datarecord...whatever created this datarecord needs to eventually set this to false
        $datarecord->setUniqueId( $this->uuid_service->generateDatarecordUniqueId() );

        $this->em->persist($datarecord);

        $datarecord_meta = new DataRecordMeta();
        $datarecord_meta->setDataRecord($datarecord);

        if ( $datatype->getNewRecordsArePublic() )
            $datarecord_meta->setPublicDate(new \DateTime());   // public
        else
            $datarecord_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // not public

        $datarecord_meta->setCreated($created);
        $datarecord_meta->setUpdated($created);
        $datarecord_meta->setCreatedBy($user);
        $datarecord_meta->setUpdatedBy($user);

        // Ensure the datarecord knows about its meta entry
        $datarecord->addDataRecordMetum($datarecord_meta);
        $this->em->persist($datarecord_meta);

        // Set up default radio options if required
        // NOTE - the only reason this function works properly is because it gets called before a
        //  flush happens
        if ($select_default_radio_options)
            self::selectDefaultRadioOptions($user, $datarecord, $created);

        if ( !$delay_flush )
            $this->em->flush();

        return $datarecord;
    }


    /**
     * Determines whether a brand-new datarecord needs to have radio options selected by default.
     *
     * NOTE - the only reason this function works properly is because it gets called before a
     * flush happens in self::createDatarecord().
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     */
    private function selectDefaultRadioOptions($user, $datarecord, $created = null)
    {
        // Load the list of radio options marked as default, if possible
        $default_radio_options = $this->cache_service->get('default_radio_options');
        if ($default_radio_options === false) {
            // Cache entry doesn't exist...need to create it
            // NOTE - changing a datafield's fieldtype away from "Radio" does not delete the radio
            //  options...so need to also check current fieldtype in here
            $query = $this->em->createQuery(
               'SELECT ro.id AS ro_id, df.id AS df_id, dt.id AS dt_id
                FROM ODRAdminBundle:RadioOptionsMeta rom
                JOIN ODRAdminBundle:RadioOptions ro WITH rom.radioOption = ro
                JOIN ODRAdminBundle:DataFields df WITH ro.dataField = df
                JOIN ODRAdminBundle:DataFieldsMeta dfm WITH dfm.dataField = df
                JOIN ODRAdminBundle:FieldType ft WITH dfm.fieldType = ft
                JOIN ODRAdminBundle:DataType dt WITH df.dataType = dt
                JOIN ODRAdminBundle:DataType gdt WITH dt.grandparent = gdt
                WHERE rom.isDefault = 1 AND ft.typeClass = :typeclass
                AND rom.deletedAt IS NULL AND ro.deletedAt IS NULL
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL
                AND dt.deletedAt IS NULL AND gdt.deletedAt IS NULL'
            )->setParameters( array('typeclass' => 'Radio') );
            $results = $query->getArrayResult();

            $default_radio_options = array();
            foreach ($results as $result) {
                $ro_id = $result['ro_id'];
                $df_id = $result['df_id'];
                $dt_id = $result['dt_id'];

                if ( !isset($default_radio_options[$dt_id]) )
                    $default_radio_options[$dt_id] = array();
                if ( !isset($default_radio_options[$dt_id][$df_id]) )
                    $default_radio_options[$dt_id][$df_id] = array();
                $default_radio_options[$dt_id][$df_id][] = $ro_id;
            }

            // Save the list back into the cache
            $this->cache_service->set('default_radio_options', $default_radio_options);
        }

        // If the datarecord doesn't belong to a datatype that has a default radio option, then
        //  it makes no sense to keep looking
        $dt_id = $datarecord->getDataType()->getId();
        if ( !isset($default_radio_options[$dt_id]) )
            return;

        // Otherwise, going to need to hydrate all affected datafields and all radio options
        $datafields_to_hydrate = array();
        $radio_options_to_hydrate = array();
        foreach ($default_radio_options[$dt_id] as $df_id => $radio_options) {
            $datafields_to_hydrate[] = $df_id;
            foreach ($radio_options as $num => $ro_id)
                $radio_options_to_hydrate[] = $ro_id;
        }

        $query = $this->em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:DataFields df
            WHERE df.id IN (:datafield_ids)'
        )->setParameters( array('datafield_ids' => $datafields_to_hydrate) );
        $results = $query->getResult();

        $datafields = array();
        foreach ($results as $df) {
            /** @var DataFields $df */
            $datafields[ $df->getId() ] = $df;
        }

        $query = $this->em->createQuery(
           'SELECT ro
            FROM ODRAdminBundle:RadioOptions ro
            WHERE ro.id IN (:radio_option_ids)'
        )->setParameters( array('radio_option_ids' => $radio_options_to_hydrate) );
        $results = $query->getResult();

        $radio_options = array();
        foreach ($results as $ro) {
            /** @var RadioOptions $ro */
            $df_id = $ro->getDataField()->getId();
            if ( !isset($radio_options[$df_id]) )
                $radio_options[$df_id] = array();

            $radio_options[$df_id][ $ro->getId() ] = $ro;
        }

        if ( is_null($created) )
            $created = new \DateTime();

        // Now that the entities are hydrated, create a DataRecordField entry for each datafield
        // IMPORTANT - this method ONLY works because the Datarecord doesn't actually "exist"...
        // It exists only in Doctrine's persist buffer (or whatever), so there's no way for another
        //  ODR process to try to create drf entities for it...which is what the main function
        //  self::createDatarecordField() has to prevent by using locking techniques
        foreach ($datafields as $df_id => $df) {
            $drf = new DataRecordFields();
            $drf->setDataRecord($datarecord);
            $drf->setDataField($df);

            $drf->setCreated($created);
            $drf->setCreatedBy($user);

            // Do not flush the entity here
            $this->em->persist($drf);

            foreach ($radio_options[$df_id] as $ro_id => $ro) {
                // Creating the RadioSelection entities has the same restrictions as the drf entities
                // ...and the usual restrictions can be sidestepped here for the same reasons.
                $rs = new RadioSelection();
                $rs->setDataRecord($datarecord);
                $rs->setDataRecordFields($drf);
                $rs->setRadioOption($ro);

                $rs->setCreated($created);
                $rs->setUpdated($created);
                $rs->setCreatedBy($user);
                $rs->setUpdatedBy($user);

                // Mark this option as selected
                $rs->setSelected(1);

                // Do not flush the entity here
                $this->em->persist($rs);
            }
        }

        // Don't flush here...let the caller take care of it
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
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataRecordFields
     */
    public function createDatarecordField($user, $datarecord, $datafield, $created = null)
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
            //  pair, so use a locking service to prevent that...
            $lockHandler = $this->lock_service->createLock('drf_'.$datarecord->getId().'_'.$datafield->getId().'.lock');
            if ( !$lockHandler->acquire() ) {
                // Another process is attempting to create this drf entry...block until it finishes...
                $lockHandler->acquire(true);

                // ...then reload and return the drf that the other process created
                /** @var DataRecordFields $drf */
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

                if ( is_null($created) )
                    $created = new \DateTime();

                $drf->setCreated($created);
                $drf->setCreatedBy($user);

                $this->em->persist($drf);
                $this->em->flush();
                $this->em->refresh($drf);

                // Now that the drf has been created, release the lock on it
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
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return LinkedDataTree
     */
    public function createDatarecordLink($user, $ancestor_datarecord, $descendant_datarecord, $created = null)
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

            // Use a locking service to ensure there's at most one (ancestor, descendant) pair...
            $lockHandler = $this->lock_service->createLock('ldt_'.$ancestor_datarecord->getId().'_'.$descendant_datarecord->getId().'.lock');
            if ( !$lockHandler->acquire() ) {
                // Another process is attempting to create this entity...wait for it to finish...
                $lockHandler->acquire(true);

                // ...then reload and return the linked_datatree entry that got created
                /** @var LinkedDataTree $linked_datatree */
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

                if ( is_null($created) )
                    $created = new \DateTime();

                $linked_datatree->setCreated($created);
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
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataTree
     */
    public function createDatatree($user, $ancestor, $descendant, $is_link, $multiple_allowed, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        $datatree = new DataTree();
        $datatree->setAncestor($ancestor);
        $datatree->setDescendant($descendant);

        $datatree->setCreated($created);
        $datatree->setCreatedBy($user);

        $this->em->persist($datatree);

        $datatree_meta = new DataTreeMeta();
        $datatree_meta->setDataTree($datatree);
        $datatree_meta->setIsLink($is_link);
        $datatree_meta->setMultipleAllowed($multiple_allowed);

        $datatree_meta->setCreated($created);
        $datatree_meta->setUpdated($created);
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
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataType
     */
    public function createDatatype($user, $datatype_name, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

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

        $datatype->setCreated($created);
        $datatype->setUpdated($created);
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

        $datatype_meta->setNewRecordsArePublic(false);    // newly created datarecords default to not-public

        $datatype_meta->setMasterPublishedRevision(0);
        $datatype_meta->setMasterRevision(0);
        $datatype_meta->setTrackingMasterRevision(0);

        // These would be null by default, but are specified here for completeness
        $datatype_meta->setExternalIdField(null);
        $datatype_meta->setNameField(null);
        $datatype_meta->setSortField(null);
        $datatype_meta->setBackgroundImageField(null);

        $datatype_meta->setCreated($created);
        $datatype_meta->setUpdated($created);
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
     * Creates and persists a new DatatypeSpecialFields entity, used for specifying the "name" or
     * "sort" fields of a Datatype, and what order to read them in.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param DataFields $datafield
     * @param int $field_purpose {@link DataTypeSpecialFields::NAME_FIELD}, {@link DataTypeSpecialFields::SORT_FIELD}
     * @param int $display_order
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataTypeSpecialFields
     */
    public function createDatatypeSpecialField($user, $datatype, $datafield, $field_purpose, $display_order = 999, $delay_flush = false, $created = null)
    {
        // ----------------------------------------
        // Have some verification to do first...
        if ( $field_purpose !== DataTypeSpecialFields::NAME_FIELD && $field_purpose !== DataTypeSpecialFields::SORT_FIELD )
            throw new ODRBadRequestException('Invalid field_purpose for special Datatype field');

        if ( is_null($created) )
            $created = new \DateTime();

        // ----------------------------------------
        // Initial create
        $dtsf = new DataTypeSpecialFields();
        $dtsf->setDataType($datatype);
        $dtsf->setDataField($datafield);
        $dtsf->setFieldPurpose($field_purpose);
        $dtsf->setDisplayOrder($display_order);

        $dtsf->setCreated($created);
        $dtsf->setUpdated($created);
        $dtsf->setCreatedBy($user);
        $dtsf->setUpdatedBy($user);

        $datatype->addDataTypeSpecialField($dtsf);
        $datafield->addDataTypeSpecialField($dtsf);
        $this->em->persist($dtsf);

        if ( !$delay_flush )
            $this->em->flush();

        return $dtsf;
    }


    /**
     * Create a new Group for users of the given datatype.  Does NOT guard against creating
     * duplicates of the default groups (i.e. "admin", "edit_all", "view_all", or "view_only")
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param string $initial_purpose One of 'admin', 'edit_all', 'view_all', 'view_only', or ''
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return Group
     */
    public function createGroup($user, $datatype, $initial_purpose = '', $delay_flush = false, $created = null)
    {
        // ----------------------------------------
        // Groups should only be attached to top-level datatypes...child datatypes inherit groups
        //  from their parent
        if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
            throw new ODRBadRequestException('Child Datatypes are not allowed to have groups of their own.');

        if ( is_null($created) )
            $created = new \DateTime();

        // ----------------------------------------
        // Create the Group entity
        $group = new Group();
        $group->setDataType($datatype);
        $group->setPurpose($initial_purpose);

        $group->setCreated($created);
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
            $group_meta->setGroupDescription('Users in this default Group are always allowed to view, edit, and change public status of Datarecords.');
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

        $group_meta->setCreated($created);
        $group_meta->setUpdated($created);
        $group_meta->setCreatedBy($user);
        $group_meta->setUpdatedBy($user);

        // Ensure the "in-memory" version of the new group knows about its meta entry
        $group->addGroupMetum($group_meta);
        $this->em->persist($group_meta);


        // ----------------------------------------
        // Now that the group is persisted, the grandparent datatype and all of its children each
        //  need a GroupDatatypePermission entry to tie them to this new group
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

            // Can't use self::createGroupsForDatatatype() for this...that function assumes the
            //  group already exists in the database, which is not the case at this point in time
            $gdtp = new GroupDatatypePermissions();
            $gdtp->setGroup($group);
            $gdtp->setDataType($dt);

            $gdtp->setCreated($created);
            $gdtp->setUpdated($created);
            $gdtp->setCreatedBy($user);
            $gdtp->setUpdatedBy($user);

            // Default all permissions to false...
            $gdtp->setIsDatatypeAdmin(false);
            $gdtp->setCanDesignDatatype(false);
            $gdtp->setCanChangePublicStatus(false);
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
                    $gdtp->setCanChangePublicStatus(true);
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
                    // This is apparently a new "custom" group...can't assume anything about which
                    //  permissions it will eventually have
                    break;
            }

            // A GroupDatatypePermission entry for a top-level datatype needs to always have the
            //  "can_view_datatype" permission so members of the group can see the datatype when
            //  it's non-public
            if ( $dt->getId() === $dt->getGrandparent()->getId() )
                $gdtp->setCanViewDatatype(true);

            // Intentionally don't flush here...
            $this->em->persist($gdtp);
        }


        // ----------------------------------------
        // The new group also needs to have a GroupDatafieldPermission entry for each datafield
        //  that belongs to the grandparent datatype
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

            // Can't use self::createGroupsForDatafield() for this...that function assumes the
            //  group already exists in the database, which is not the case at this point in time
            $gdfp = new GroupDatafieldPermissions();
            $gdfp->setGroup($group);
            $gdfp->setDataField($df);
            $gdfp->setDeletedAt( $df->getDeletedAt() );    // need to copy the datafield's deletedAt incase it gets undeleted later...

            $gdfp->setCreated($created);
            $gdfp->setUpdated($created);
            $gdfp->setCreatedBy($user);
            $gdfp->setUpdatedBy($user);

            $initial_purpose = $group->getPurpose();
            if ($initial_purpose == 'admin' || $initial_purpose == 'edit_all') {
                // "admin" and "edit_all" groups can both view and edit this new datafield by default
                $gdfp->setCanViewDatafield(1);
                $gdfp->setCanEditDatafield(1);
            }
            else if ($initial_purpose == 'view_all') {
                // The "view_all" group defaults to being able to view, but not edit, this datafield
                $gdfp->setCanViewDatafield(1);
                $gdfp->setCanEditDatafield(0);
            }
            else {
                // All other groups default to not being able to view or edit this datafield
                $gdfp->setCanViewDatafield(0);
                $gdfp->setCanEditDatafield(0);
            }

            // Intentionally don't flush here...
            $this->em->persist($gdfp);
        }

        // Flush here unless otherwise required
        if ( !$delay_flush ) {
            $this->em->flush();
            $this->em->refresh($group);
        }


        // ----------------------------------------
        // Strangely enough, don't need to do any permission clearing here...either this is
        // 1) a new "custom" group, and therefore nobody is a member of it yet
        // OR
        // 2) This was called as part of createGroupsForDatatype(), in which case that function needs
        //  to do additional cache clearing anyways

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
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     */
    public function createGroupsForDatatype($user, $datatype, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        // ----------------------------------------
        // Store whether this is a top-level datatype or not
        $datatype_id = $datatype->getId();
        $grandparent_datatype_id = $datatype->getGrandparent()->getId();

        $is_top_level = true;
        if ($datatype_id != $grandparent_datatype_id)
            $is_top_level = false;


        // ----------------------------------------
        // Locate all groups for this datatype's grandparent
        $repo_group = $this->em->getRepository('ODRAdminBundle:Group');

        /** @var Group[] $groups */
        if ($is_top_level) {
            // Create any default groups the top-level datatype is currently missing...
            $admin_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'admin') );
            if ($admin_group == null)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'admin', true, $created);    // don't flush immediately...

            $edit_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'edit_all') );
            if ($edit_group == null)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'edit_all', true, $created);    // don't flush immediately...

            $view_all_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'view_all') );
            if ($view_all_group == null)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'view_all', true, $created);    // don't flush immediately...

            $view_only_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'view_only') );
            if ($view_only_group == null)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'view_only', true, $created);    // don't flush immediately

            // By definition, a brand new top-level datatype can't already have a custom group...it
            //  can only have the default groups

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
                else if ($group->getPurpose() == 'edit_all')
                    $has_edit = true;
                else if ($group->getPurpose() == 'view_all')
                    $has_view_all = true;
                else if ($group->getPurpose() == 'view_only')
                    $has_view_only = true;
            }

            if (!$has_admin || !$has_edit || !$has_view_all || !$has_view_only)
                throw new ODRException('createGroupsForDatatype(): grandparent datatype '.$grandparent_datatype_id.' is missing a default group for child datatype '.$datatype->getId().' to copy from.');


            // ----------------------------------------
            // This new child datatype needs to have a GroupDatatypePermission entry for each group
            //  that its grandparent datatype already has, both "default" and "custom" groups
            foreach ($groups as $group) {
                // Default permissions depend on the original purpose of this group...
                $initial_purpose = $group->getPurpose();

                $gdtp = new GroupDatatypePermissions();
                $gdtp->setGroup($group);
                $gdtp->setDataType($datatype);

                $gdtp->setCreated($created);
                $gdtp->setUpdated($created);
                $gdtp->setCreatedBy($user);
                $gdtp->setUpdatedBy($user);

                // Default all permissions to false...
                $gdtp->setIsDatatypeAdmin(false);
                $gdtp->setCanDesignDatatype(false);
                $gdtp->setCanChangePublicStatus(false);
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
                        $gdtp->setCanChangePublicStatus(true);
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
                        // This is one of the grandparent datatype's "custom" groups...can't assume
                        //  anything about which permissions this new child datatype will get
                        break;
                }

                // Initial setup complete, persist the changes
                $this->em->persist($gdtp);
            }

            // Flush now that all the datatype permission entries have been created
            $this->em->flush();
        }


        // ----------------------------------------
        // Need to determine which users are already members of the groups for this datatype,
        //  since they're going to need their permission arrays cleared
        $query = $this->em->createQuery(
           'SELECT DISTINCT(u.id) AS user_id
            FROM ODRAdminBundle:Group AS g
            LEFT JOIN ODRAdminBundle:UserGroup AS ug WITH ug.group = g
            LEFT JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
            WHERE g.dataType = :grandparent_datatype_id
            AND g.deletedAt IS NULL'
        )->setParameters( array('grandparent_datatype_id' => $grandparent_datatype_id) );
        $results = $query->getArrayResult();

        $user_list = array();
        foreach ($results as $result) {
            // Groups may not have any members, so null user ids are a possibility
            if ( !is_null($result['user_id']) )
                $user_list[ $result['user_id'] ] = 1;
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

        // Delete the cached permissions for each affected user
        foreach ($user_list as $user_id => $num)
            $this->cache_service->delete('user_'.$user_id.'_permissions');

    }


    /**
     * Creates GroupDatafieldPermissions for all groups when a new datafield is created, and updates
     * existing cache entries for groups and users with the new datafield.
     *
     * Should NOT be called as part of creating a new Group.
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     */
    public function createGroupsForDatafield($user, $datafield, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

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
        // Need to determine which users are already members of the groups for this datatype, since
        //  they're going to need their cached permission arrays cleared
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
            $user_list[ $result['user_id'] ] = 1;

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


        // ----------------------------------------
        // Every group for this datatype is going to need a GroupDatafieldPermission entry for this
        //  new datafield...
        foreach ($groups as $group) {
            $gdfp = new GroupDatafieldPermissions();

            $gdfp->setGroup($group);
            $gdfp->setDataField($datafield);

            $gdfp->setCreated($created);
            $gdfp->setUpdated($created);
            $gdfp->setCreatedBy($user);
            $gdfp->setUpdatedBy($user);

            $initial_purpose = $group->getPurpose();
            if ($initial_purpose == 'admin' || $initial_purpose == 'edit_all') {
                // "admin" and "edit_all" groups can both view and edit this new datafield by default
                $gdfp->setCanViewDatafield(1);
                $gdfp->setCanEditDatafield(1);
            }
            else if ($initial_purpose == 'view_all') {
                // The "view_all" group defaults to being able to view, but not edit, this datafield
                $gdfp->setCanViewDatafield(1);
                $gdfp->setCanEditDatafield(0);
            }
            else {
                // All other groups, including "custom" groups, default to not being able to view
                //  or edit this datafield
                $gdfp->setCanViewDatafield(0);
                $gdfp->setCanEditDatafield(0);
            }

            $this->em->persist($gdfp);
        }


        // ----------------------------------------
        // Now that the database entries have been created, delete the cached permission entries for
        //  the affected users
        foreach ($user_list as $user_id => $num)
            $this->cache_service->delete('user_'.$user_id.'_permissions');

        // Flush changes, unless otherwise requested
        if ( !$delay_flush )
            $this->em->flush();
    }


    /**
     * Creates entries in the database for a File that has not been encrypted yet.
     *
     * IMPORTANT: The localFilename, encryptKey, and originalChecksum properties will NOT be
     *  correctly set in the returned File entity.  The encryption processes will need to be run
     *  in order to set them correctly.
     *
     * @param ODRUser $user
     * @param DataRecordFields $drf
     * @param string $filepath The path to the unencrypted file on the server
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     * @param \DateTime|null $public_date If provided, then the public date is set to this
     * @param int $quality If provided, then the quality property is set to this
     *
     * @return File
     */
    public function createFile($user, $drf, $filepath, $created = null, $public_date = null, $quality = null)
    {
        // Ensure a file exists at the given path
        if ( !file_exists($filepath) )
            throw new ODRNotFoundException('Uploaded File');

        // ----------------------------------------
        // Set optional properties for the new file
        if ( is_null($created) )
            $created = new \DateTime();

        if ( is_null($public_date) ) {
            if ( $drf->getDataField()->getNewFilesArePublic() )
                $public_date = new \DateTime();
            else
                $public_date = new \DateTime('2200-01-01 00:00:00');
        }

        if ( is_null($quality) )
            $quality = 0;


        // ----------------------------------------
        // Determine several properties of the file before it gets encrypted
        $uploaded_file = new SymfonyFile($filepath);
        $extension = $uploaded_file->guessExtension();    // TODO - ...shouldn't this be based on the filename itself?
        $filesize = $uploaded_file->getSize();

        // Use PHP to split the path info
        $dirname = pathinfo($filepath, PATHINFO_DIRNAME);
        $original_filename = pathinfo($filepath, PATHINFO_BASENAME);


        // ----------------------------------------
        // Fill out most of the database entry for this file...
        $file = new File();
        $file->setDataRecordFields($drf);
        $file->setDataRecord($drf->getDataRecord());
        $file->setDataField($drf->getDataField());
        $file->setFieldType($drf->getDataField()->getFieldType());

        $file->setProvisioned(false);
        $file->setUniqueId( $this->uuid_service->generateFileUniqueId() );

        $file->setCreated($created);
        $file->setCreatedBy($user);

        // ...these properties can be set immediately...
        $file->setExt($extension);
        $file->setFilesize($filesize);

        // The local_filename property will get changed to the web-accessible directory later
        $file->setLocalFileName($dirname);
        // The encrypt_key property is left blank, because the encryption process will set it later
        $file->setEncryptKey('');
        // The original_checksum property is also left blank...it'll be set after encryption, to let
        //  the rest of ODR know it can start using the file
        $file->setOriginalChecksum('');

        // Done with the file entity, for now
        $this->em->persist($file);


        // ----------------------------------------
        // Also need to create a FileMeta entry for this new File
        $file_meta = new FileMeta();
        $file_meta->setFile($file);

        $file_meta->setOriginalFileName($original_filename);
        $file_meta->setQuality($quality);
        $file_meta->setDescription(null);    // TODO
        $file_meta->setExternalId('');

        $file_meta->setPublicDate($public_date);

        $file_meta->setCreated($created);
        $file_meta->setUpdated($created);
        $file_meta->setCreatedBy($user);
        $file_meta->setUpdatedBy($user);

        // Done with the FileMeta entity
        $this->em->persist($file_meta);


        // ----------------------------------------
        // Flush changes and return the new File entity
        $this->em->flush();
        $this->em->refresh($file);
        return $file;
    }


    /**
     * Creates entries in the database for an Image that has not been encrypted yet.
     *
     * IMPORTANT: The localFilename, encryptKey, and originalChecksum properties will NOT be
     *  correctly set in the returned Image.  The encryption processes will need to be run in order
     *  to set them correctly.
     *
     * @param ODRUser $user
     * @param DataRecordFields $drf
     * @param string $filepath The path to the unencrypted image on the server
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     * @param \DateTime|null $public_date If provided, then the public date is set to this
     * @param int|null $display_order If provided, then the display_order is set to this
     * @param int $quality If provided, then the quality property is set to this
     *
     * @return Image
     */
    public function createImage($user, $drf, $filepath, $created = null, $public_date = null, $display_order = null, $quality = null)
    {
        // Ensure a file exists at the given path
        if ( !file_exists($filepath) )
            throw new ODRNotFoundException('Uploaded Image');

        // ----------------------------------------
        // Set optional properties for the new image
        if ( is_null($created) )
            $created = new \DateTime();

        if ( is_null($public_date) ) {
            if ( $drf->getDataField()->getNewFilesArePublic() )
                $public_date = new \DateTime();
            else
                $public_date = new \DateTime('2200-01-01 00:00:00');
        }

        if ( is_null($display_order) )
            $display_order = 0;

        if ( is_null($quality) )
            $quality = 0;


        // ----------------------------------------
        // Determine several properties of the image before it gets encrypted
        $uploaded_file = new SymfonyFile($filepath);
        $extension = $uploaded_file->guessExtension();

        $sizes = getimagesize($filepath);
        $image_width = $sizes[0];
        $image_height = $sizes[1];

        // Also need to locate/set the ImageSize property
        $original_image_size = null;
        /** @var ImageSizes[] $image_sizes */
        $image_sizes = $drf->getDataField()->getImageSizes();
        foreach ($image_sizes as $image_size) {
            if ( $image_size->getOriginal() ) {
                $original_image_size = $image_size;
                break;
            }
        }

        // Use PHP to split the path info
        $dirname = pathinfo($filepath, PATHINFO_DIRNAME);
        $original_filename = pathinfo($filepath, PATHINFO_BASENAME);


        // ----------------------------------------
        // Fill out most of the database entry for this image...
        $image = new Image();
        $image->setDataRecordFields($drf);
        $image->setDataRecord($drf->getDataRecord());
        $image->setDataField($drf->getDataField());
        $image->setFieldType($drf->getDataField()->getFieldType());

        $image->setOriginal(true);
        $image->setImageSize($original_image_size);
        $image->setUniqueId( $this->uuid_service->generateImageUniqueId() );

        // ...these properties can be set immediately...
        $image->setExt($extension);
        $image->setImageWidth($image_width);
        $image->setImageHeight($image_height);

        // The local_filename property will get changed to the web-accessible directory later
        $image->setLocalFileName($dirname);
        // The encrypt_key property is left blank, because the encryption process will set it later
        $image->setEncryptKey('');
        // The original_checksum property is also left blank...it'll be set after encryption, to let
        //  the rest of ODR know it can start using the image
        $image->setOriginalChecksum('');

        $image->setCreated($created);
        $image->setCreatedBy($user);

        // Done with the file entity, for now
        $this->em->persist($image);


        // ----------------------------------------
        // Also need to create an ImageMeta entry for this image
        $image_meta = new ImageMeta();
        $image_meta->setImage($image);
        $image_meta->setDisplayorder($display_order);

        $image_meta->setOriginalFileName($original_filename);
        $image_meta->setQuality($quality);
        $image_meta->setCaption(null);    // TODO
        $image_meta->setExternalId('');

        $image_meta->setPublicDate($public_date);

        $image_meta->setCreated($created);
        $image_meta->setUpdated($created);
        $image_meta->setCreatedBy($user);
        $image_meta->setUpdatedBy($user);

        // Done with the ImageMeta entity
        $this->em->persist($image_meta);


        // ----------------------------------------
        // Flush changes and return the new Image entity
        $this->em->flush();
        $this->em->refresh($image);
        return $image;
    }


    /**
     * Creates resized versions of the given Image.
     *
     * IMPORTANT: The localFilename, encryptKey, and originalChecksum properties will NOT be
     *  correctly set in the returned Image.  The encryption processes will need to be run in order
     *  to set them correctly.
     *
     * @param Image $original_image
     * @param string $filepath The path to the unencrypted original (source) image on the server
     * @param bool $overwrite_existing If true, locate and overwrite $original_image's existing
     *                                 resized Image entities, instead of creating new ones
     *
     * @return Image[]
     */
    public function createResizedImages($original_image, $filepath, $overwrite_existing = false)
    {
        // Ensure a file exists at the given path
        if ( !file_exists($filepath) )
            throw new ODRNotFoundException('Uploaded Image');

        // Load existing resizes of the original image if required
        $existing_resizes = array();
        if ($overwrite_existing) {
            /** @var Image[] $images */
            $images = $this->em->getRepository('ODRAdminBundle:Image')->findBy(
                array(
                    'parent' => $original_image->getId()
                )
            );
            foreach ($images as $i)
                $existing_resizes[ $i->getImageSize()->getId() ] = $i;
        }


        // ----------------------------------------
        // Need to create/overwrite one Image per ImageSize entity for this Datafield
        /** @var ImageSizes[] $image_sizes */
        $image_sizes = $original_image->getDataField()->getImageSizes();
        foreach ($image_sizes as $image_size) {
            if ( $image_size->getOriginal() ) {
                /* do nothing */
            }
            else {
                // Get the resized Image, or create one if it doesn't exist
                $resized_image = null;
                if ( isset($existing_resizes[$image_size->getId()]) ) {
                    $resized_image = $existing_resizes[$image_size->getId()];
                }
                else {
                    // Make a clone of the database entries for the original image
                    $resized_image = clone $original_image;

                    // ...update the properties that are different from the original image...
                    $resized_image->setParent($original_image);
                    $resized_image->setImageSize($image_size);
                    $resized_image->setOriginal(false);
                    $resized_image->setUniqueId( $this->uuid_service->generateImageUniqueId() );

                    // ...and reset the properties that will change once the original image gets resized
                    $resized_image->setLocalFileName('');
                    $resized_image->setEncryptKey('');
                    $resized_image->setOriginalChecksum('');

                    $resized_image->setImageWidth(0);
                    $resized_image->setImageHeight(0);

                    // Persist and flush the new image, since the database ID will be needed
                    $this->em->persist($resized_image);
                    $this->em->flush();
                }

                // Create a copy of the original image and ensure it's named in "Image_<id>.<ext>"
                //  format, so that the crypto bundle uses that same format inside the encryption
                //  directory
                $dirname = pathinfo($filepath, PATHINFO_DIRNAME);
                $new_filename = 'Image_'.$resized_image->getId().'.'.$resized_image->getExt();
                copy($filepath, $dirname.'/'.$new_filename);

                // TODO - ?
                $proportional = false;
                if ($image_size->getSizeConstraint() == "width"
                    || $image_size->getSizeConstraint() == "height"
                    || $image_size->getSizeConstraint() == "both"
                ) {
                    $proportional = true;
                }

                // Resize the image
                self::smart_resize_image(
                    $dirname.'/'.$new_filename,
                    $image_size->getWidth(),
                    $image_size->getHeight(),
                    $proportional,
                    'file',
                    false,
                    false
                );

                // Store the new width/height of the resized image
                $sizes = getimagesize($dirname.'/'.$new_filename);
                $image_width = $sizes[0];
                $image_height = $sizes[1];

                $resized_image->setImageWidth($image_width);
                $resized_image->setImageHeight($image_height);
                $this->em->persist($resized_image);
                $this->em->flush();

                // NOTE - at this time, the encryptKey and the localFilename properties of the
                //  resized image are still blank

                // Store the newly resized image in order to return it later
                $existing_resizes[ $image_size->getId() ] = $resized_image;
            }
        }

        return $existing_resizes;
    }


    /**
     * Does the actual work of resizing an image to some arbitrary dimension.
     * TODO - need source for this...pretty sure it's copy/pasted from somewhere
     *
     * @param string $file                Should be a path to the file
     * @param integer $width              Desired width for the resulting thumbnail
     * @param integer $height             Desired height for the resulting thumbnail
     * @param boolean $proportional       Whether to preserve aspect ratio while resizing
     * @param string $output              'browser', 'file', or 'return'
     * @param boolean $delete_original    Whether to delete the original file or not after resizing
     * @param boolean $use_linux_commands If true, use linux commands to delete the original file, otherwise use windows commands
     *
     * @return array Contains height/width after resizing
     */
    private function smart_resize_image(
        $file,
        $width              = 0,
        $height             = 0,
        $proportional       = false,
        $output             = 'file',
        $delete_original    = true,
        $use_linux_commands = false
    ) {

        if ( $height <= 0 && $width <= 0 ) return false;

        # Setting defaults and meta
        $info                         = getimagesize($file);
        $image                        = '';
        $final_width                  = 0;
        $final_height                 = 0;

        list($width_old, $height_old) = $info;

        # Calculating proportionality
        if ($proportional) {
            if      ($width  == 0)  $factor = $height/$height_old;
            elseif  ($height == 0)  $factor = $width/$width_old;
            else                    $factor = min( $width / $width_old, $height / $height_old );

            $final_width  = round( $width_old * $factor );
            $final_height = round( $height_old * $factor );
        }
        else {
            $final_width = ( $width <= 0 ) ? $width_old : $width;
            $final_height = ( $height <= 0 ) ? $height_old : $height;
        }

        # Loading image to memory according to type
        switch ( $info[2] ) {
            case IMAGETYPE_GIF:   $image = imagecreatefromgif($file);   break;
            case IMAGETYPE_JPEG:  $image = imagecreatefromjpeg($file);  break;
            case IMAGETYPE_PNG:   $image = imagecreatefrompng($file);   break;
            case IMAGETYPE_WBMP:   $image = imagecreatefromwbmp($file);   break;
            default: return false;
        }

        # This is the resizing/resampling/transparency-preserving magic
        $image_resized = imagecreatetruecolor( $final_width, $final_height );
        if ( ($info[2] == IMAGETYPE_GIF) || ($info[2] == IMAGETYPE_PNG) ) {
            $transparency = imagecolortransparent($image);

            if ($transparency >= 0) {
                // TODO figure out what trnprt_index is used for.
                $trnprt_indx = null;
                $transparent_color  = imagecolorsforindex($image, $trnprt_indx);
                $transparency       = imagecolorallocate($image_resized, $transparent_color['red'], $transparent_color['green'], $transparent_color['blue']);
                imagefill($image_resized, 0, 0, $transparency);
                imagecolortransparent($image_resized, $transparency);
            }
            elseif ($info[2] == IMAGETYPE_PNG) {
                imagealphablending($image_resized, false);
                $color = imagecolorallocatealpha($image_resized, 0, 0, 0, 127);
                imagefill($image_resized, 0, 0, $color);
                imagesavealpha($image_resized, true);
            }
        }
        imagecopyresampled($image_resized, $image, 0, 0, 0, 0, $final_width, $final_height, $width_old, $height_old);

        # Taking care of original, if needed
        if ( $delete_original ) {
            if ( $use_linux_commands ) exec('rm '.$file);
            else @unlink($file);
        }

        # Preparing a method of providing result
        switch ( strtolower($output) ) {
            case 'browser':
                $mime = image_type_to_mime_type($info[2]);
                header("Content-type: $mime");
                $output = NULL;
                break;
            case 'file':
                $output = $file;
                break;
            case 'return':
                return $image_resized;
                break;
            default:
                break;
        }

        # Writing image according to type to the output destination
        switch ( $info[2] ) {
            case IMAGETYPE_GIF:   imagegif($image_resized, $output );    break;
            case IMAGETYPE_JPEG:  imagejpeg($image_resized, $output, '90');   break;
            case IMAGETYPE_PNG:   imagepng($image_resized, $output, '2');    break;
            default: return false;
        }

        $stats = array($final_height, $final_width);
        return $stats;
    }


    /**
     * Ensures an image datafield has its ImageSize entities.
     * TODO - acquire locks before creating anything?
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     */
    public function createImageSizes($user, $datafield, $delay_flush = false, $created = null)
    {
        // Don't run this on a datafield that isn't already an image
        if ( $datafield->getFieldType()->getTypeName() !== 'Image' )
            return;

        if ( is_null($created) )
            $created = new \DateTime();


        // ----------------------------------------
        // Attempt to load ImageSize entities from the database to determine if any are missing
        $has_original = false;
        $has_thumbnail = false;

        /** @var ImageSizes[] $image_sizes */
        $image_sizes = $datafield->getImageSizes();
        foreach ($image_sizes as $image_size) {
            if ( $image_size->getOriginal() == true )
                $has_original = true;
            if ( $image_size->getOriginal() == false && $image_size->getImagetype() == 'thumbnail' )
                $has_thumbnail = true;
        }

        // TODO - generalize to more than just two sizes?
        // TODO - dynamic addition of new image sizes?
        // TODO - modification of image sizes?
        if ( !$has_original ) {
            // The "original" size doesn't exist...
            $original = new ImageSizes();
            $original->setDataField($datafield);
            $original->setWidth(0);
            $original->setHeight(0);
            $original->setMinWidth(1024);
            $original->setMinHeight(768);
            $original->setMaxWidth(0);
            $original->setMaxHeight(0);
            $original->setSizeConstraint('none');
            $original->setOriginal(1);
            $original->setImagetype(null);

            $original->setCreated($created);
            $original->setUpdated($created);
            $original->setCreatedBy($user);
            $original->setUpdatedBy($user);

            $this->em->persist($original);
        }

        if ( !$has_thumbnail ) {
            // The "thumbnail" size doesn't exist...
            $thumbnail = new ImageSizes();
            $thumbnail->setDataField($datafield);
            $thumbnail->setWidth(500);
            $thumbnail->setHeight(375);
            $thumbnail->setMinWidth(500);
            $thumbnail->setMinHeight(375);
            $thumbnail->setMaxWidth(500);
            $thumbnail->setMaxHeight(375);
            $thumbnail->setSizeConstraint('both');
            $thumbnail->setOriginal(0);
            $thumbnail->setImagetype('thumbnail');

            $thumbnail->setCreated($created);
            $thumbnail->setUpdated($created);
            $thumbnail->setCreatedBy($user);
            $thumbnail->setUpdatedBy($user);

            $this->em->persist($thumbnail);
        }

        // Only flush when this function did something...
        if ( !$delay_flush && (!$has_original || !$has_thumbnail) )
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
     * @param bool $delay_uuid If true, don't automatically create a uuid for this radio option...the
     *                          caller will need to take care of it.
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return RadioOptions
     */
    public function createRadioOption($user, $datafield, $force_create, $option_name, $delay_uuid = false, $created = null)
    {
        $radio_option = null;
        if ($force_create) {
            // We're being forced to create a new radio option...
            $radio_option = self::createRadioOptionEntity($user, $datafield, $option_name, $delay_uuid, $created);
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
            $lockHandler = $this->lock_service->createLock('ro_'.$datafield->getId().'_'.$option_name.'.lock');
            if ( !$lockHandler->acquire() ) {
                // Another process is attempting to create this radio option...block until it finishes...
                $lockHandler->acquire(true);

                // ...then reload and return what the other process created
                /** @var RadioOptions $radio_option */
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
                $radio_option = self::createRadioOptionEntity($user, $datafield, $option_name, $delay_uuid, $created);

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
     * @param bool $delay_uuid If true, don't automatically create a uuid for this radio option...the
     *                          caller will need to take care of it
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return RadioOptions
     */
    private function createRadioOptionEntity($user, $datafield, $option_name, $delay_uuid, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        /** @var RadioOptions $radio_option */
        $radio_option = new RadioOptions();
        $radio_option->setDataField($datafield);
        $radio_option->setOptionName($option_name);     // exists to prevent potential concurrency issues, see below

        if ( !$delay_uuid )
            $radio_option->setRadioOptionUuid( $this->uuid_service->generateRadioOptionUniqueId() );

        $radio_option->setCreated($created);
        $radio_option->setCreatedBy($user);

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

        $radio_option_meta->setCreated($created);
        $radio_option_meta->setUpdated($created);
        $radio_option_meta->setCreatedBy($user);
        $radio_option_meta->setUpdatedBy($user);

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
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return RadioSelection
     */
    public function createRadioSelection($user, $radio_option, $drf, $created = null)
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
            //  radioOption/drf pair, so use a locking service to prevent that...
            $lockHandler = $this->lock_service->createLock('rs_'.$radio_option->getId().'_'.$drf->getId().'.lock');
            if ( !$lockHandler->acquire() ) {
                // Another process is attempting to create this entity...wait for it to finish...
                $lockHandler->acquire(true);

                // ...then reload and return the radio selection that the other process created
                /** @var RadioSelection $radio_selection */
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
                $radio_selection->setDataRecord($drf->getDataRecord());
                $radio_selection->setDataRecordFields($drf);

                $radio_selection->setSelected(0);    // defaults to not selected

                if ( is_null($created) )
                    $created = new \DateTime();

                $radio_selection->setCreated($created);
                $radio_selection->setUpdated($created);
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
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return RenderPluginInstance
     */
    public function createRenderPluginInstance($user, $render_plugin, $datatype, $datafield, $delay_flush = false, $created = null)
    {
        // Ensure a RenderPlugin for a Datatype plugin doesn't get assigned to a Datafield, or a RenderPlugin for a Datafield doesn't get assigned to a Datatype
        if ( $render_plugin->getPluginType() == RenderPlugin::DATATYPE_PLUGIN && is_null($datatype) )
            throw new \Exception('Unable to create an instance of the RenderPlugin "'.$render_plugin->getPluginName().'" for a null Datatype');
        else if ( $render_plugin->getPluginType() == RenderPlugin::DATAFIELD_PLUGIN && is_null($datafield) )
            throw new \Exception('Unable to create an instance of the RenderPlugin "'.$render_plugin->getPluginName().'" for a null Datafield');

        // Ensure a RenderPlugin for a Datatype doesn't mention a Datafield, and vice versa
        if ( $render_plugin->getPluginType() == RenderPlugin::DATAFIELD_PLUGIN )
            $datatype = null;
        else    // Datatype, Array, and ThemeElement plugins
            $datafield = null;

        if ( is_null($created) )
            $created = new \DateTime();

        // Create the new RenderPluginInstance
        $rpi = new RenderPluginInstance();
        $rpi->setRenderPlugin($render_plugin);
        $rpi->setDataType($datatype);
        $rpi->setDataField($datafield);

        $rpi->setActive(true);

        $rpi->setCreated($created);
        $rpi->setUpdated($created);
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
     * @param DataFields|null $df
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return RenderPluginMap
     */
    public function createRenderPluginMap($user, $rpi, $rpf, $dt, $df, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        $rpm = new RenderPluginMap();
        $rpm->setRenderPluginInstance($rpi);
        $rpm->setRenderPluginFields($rpf);

        $rpm->setDataType($dt);
        $rpm->setDataField($df);

        $rpm->setCreated($created);
        $rpm->setUpdated($created);
        $rpm->setCreatedBy($user);
        $rpm->setUpdatedBy($user);

        $this->em->persist($rpm);

        if ( !$delay_flush )
            $this->em->flush();

        return $rpm;
    }


    /**
     * Creates and persists a new RenderPluginOptionsMap entity.
     *
     * @param ODRUser $user
     * @param RenderPluginInstance $render_plugin_instance
     * @param RenderPluginOptionsDef $render_plugin_option TODO - rename to RenderPluginOptions
     * @param string $option_value
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return RenderPluginOptionsMap
     */
    public function createRenderPluginOptionsMap($user, $render_plugin_instance, $render_plugin_option, $option_value, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        $rpom = new RenderPluginOptionsMap();
        $rpom->setRenderPluginInstance($render_plugin_instance);
        $rpom->setRenderPluginOptionsDef($render_plugin_option);
        $rpom->setValue($option_value);

        $rpom->setCreated($created);
        $rpom->setUpdated($created);
        $rpom->setCreatedBy($user);
        $rpom->setUpdatedBy($user);

        $this->em->persist($rpom);

        if ( !$delay_flush )
            $this->em->flush();

        return $rpom;
    }


    /**
     * Creates and returns a new SidebarLayout entity.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return SidebarLayout
     */
    public function createSidebarLayout($user, $datatype, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        $sidebar_layout = new SidebarLayout();
        $sidebar_layout->setDataType($datatype);

        $sidebar_layout->setCreated($created);
        $sidebar_layout->setUpdated($created);
        $sidebar_layout->setCreatedBy($user);
        $sidebar_layout->setUpdatedBy($user);

        $this->em->persist($sidebar_layout);

        $sidebar_layout_meta = new SidebarLayoutMeta();
        $sidebar_layout_meta->setSidebarLayout($sidebar_layout);
        $sidebar_layout_meta->setLayoutName('');
        $sidebar_layout_meta->setLayoutDescription('');
        $sidebar_layout_meta->setShared(false);

        // Currently unused...
        $sidebar_layout_meta->setDefaultFor(0);
        $sidebar_layout_meta->setDisplayOrder(0);

        $sidebar_layout_meta->setCreated($created);
        $sidebar_layout_meta->setUpdated($created);
        $sidebar_layout_meta->setCreatedBy($user);
        $sidebar_layout_meta->setUpdatedBy($user);

        $this->em->persist($sidebar_layout_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $sidebar_layout;
    }


    /**
     * Creates and returns an entity tying a datafield to one of a datatype's sidebar layouts
     *
     * @param ODRUser $user
     * @param SidebarLayout $sidebar_layout
     * @param DataFields|null $datafield If null, then this will be the placeholder for the "general search" input
     * @param DataType $datatype The datatype of $datafield (which isn't necessarily the datatype of $sidebar_layout)
     * @param integer $category {@link SidebarLayoutMap::ALWAYS_DISPLAY}, {@link SidebarLayoutMap::EXTENDED_DISPLAY}
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return SidebarLayoutMap
     */
    public function createSidebarLayoutMap($user, $sidebar_layout, $datafield, $datatype, $category, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        $sidebar_layout_map = new SidebarLayoutMap();
        $sidebar_layout_map->setSidebarLayout($sidebar_layout);
        $sidebar_layout_map->setDataType($datatype);
        $sidebar_layout_map->setDataField($datafield);

        $sidebar_layout_map->setCategory($category);
        $sidebar_layout_map->setDisplayOrder(0);

        $sidebar_layout_map->setCreated($created);
        $sidebar_layout_map->setUpdated($created);
        $sidebar_layout_map->setCreatedBy($user);
        $sidebar_layout_map->setUpdatedBy($user);

        $this->em->persist($sidebar_layout_map);

        if ( !$delay_flush )
            $this->em->flush();

        return $sidebar_layout_map;
    }


    /**
     * Creates, persists, and flushes a new storage entity.
     *
     * This function doesn't permit delaying flushes, because it's impossible to lock properly.
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param DataFields $datafield
     * @param boolean|integer|string|\DateTime $initial_value If provided, then the newly created entity will have this value
     * @param boolean $fire_event If false, then don't fire the PostUpdateEvent
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    public function createStorageEntity($user, $datarecord, $datafield, $initial_value = null, $fire_event = true, $created = null)
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
            case 'IntegerValue':
            case 'DecimalValue':
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
                throw new \Exception('createStorageEntity() called on invalid fieldtype "'.$typeclass.'"');
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
            //  datarecord/datafield/fieldtype tuple, so use a locking service to prevent that...
            $lockHandler = $this->lock_service->createLock('storage_'.$datarecord->getId().'_'.$datafield->getId().'.lock');
            if ( !$lockHandler->acquire() ) {
                // Another process is attempting to create this entity...wait for it to finish...
                $lockHandler->acquire(true);

                // ...then reload and return the storage entity that got created
                /** @var ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $storage_entity */
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
                $drf = self::createDatarecordField($user, $datarecord, $datafield, $created);

                // Determine which value to use for the default value
                $insert_value = null;
                if ( !is_null($initial_value) )
                    $insert_value = $initial_value;
                else
                    $insert_value = $default_value;

                if ( is_null($created) )
                    $created = new \DateTime();

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

                if ($typeclass === 'ShortVarchar')
                    $storage_entity->setConvertedValue('');

                $storage_entity->setCreated($created);
                $storage_entity->setUpdated($created);
                $storage_entity->setCreatedBy($user);
                $storage_entity->setUpdatedBy($user);

                $this->em->persist($storage_entity);
                $this->em->flush();
                $this->em->refresh($storage_entity);

                // Now that the storage entity is created, release the lock on it
                $lockHandler->release();

                // Only want to fire this event when the storage entity gets created
                if ($fire_event) {
                    try {
                        $event = new PostUpdateEvent($storage_entity, $user);
                        $this->event_dispatcher->dispatch(PostUpdateEvent::NAME, $event);

                        // TODO - callers of this function can't access $event, so they can't get a reference to any derived storage entity...
                    }
                    catch (\Exception $e) {
//                        if ( $this->env === 'dev' )
//                            throw $e;
                    }
                }
            }
        }

        return $storage_entity;
    }


    /**
     * Creates and returns a new StoredSearchKey entity.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param string $search_key
     * @param string $label
     * @param bool $delay_flush If true, then don't flush prior to returning
     *
     * @return StoredSearchKey
     */
    public function createStoredSearchKey($user, $datatype, $search_key, $label, $delay_flush = false)
    {
        /** @var StoredSearchKey $stored_search_key */
        $stored_search_key = new StoredSearchKey();
        $stored_search_key->setDataType($datatype);
        $stored_search_key->setSearchKey($search_key);
        $stored_search_key->setStorageLabel($label);

        $stored_search_key->setIsDefault(false);
        $stored_search_key->setIsPublic(false);

        $stored_search_key->setCreatedBy($user);
        $stored_search_key->setUpdatedBy($user);

        $this->em->persist($stored_search_key);

        if ( !$delay_flush )
            $this->em->flush();

        return $stored_search_key;
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
     *                         will need to take care of it.
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return Tags
     */
    public function createTag($user, $datafield, $force_create, $tag_name, $delay_uuid = false, $created = null)
    {
        $tag = null;
        if ($force_create) {
            // We're being forced to create a new top-level tag...
            $tag = self::createTagEntity($user, $datafield, $tag_name, $delay_uuid, $created);
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
            $lockHandler = $this->lock_service->createLock('tag_'.$datafield->getId().'_'.$tag_name.'.lock');
            if ( !$lockHandler->acquire() ) {
                // Another process is attempting to create this entity...wait for it to finish...
                $lockHandler->acquire(true);

                // ...then reload and return the tag the other process created
                /** @var Tags $tag */
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
                $tag = self::createTagEntity($user, $datafield, $tag_name, $delay_uuid, $created);

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
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return Tags
     */
    private function createTagEntity($user, $datafield, $tag_name, $delay_uuid, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        /** @var Tags $tag */
        $tag = new Tags();
        $tag->setDataField($datafield);
        $tag->setTagName($tag_name);     // exists to prevent potential concurrency issues, see below

        if (!$delay_uuid)
            $tag->setTagUuid( $this->uuid_service->generateTagUniqueId() );

        $tag->setCreated($created);
        $tag->setCreatedBy($user);

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

        $tag_meta->setCreated($created);
        $tag_meta->setUpdated($created);
        $tag_meta->setCreatedBy($user);
        $tag_meta->setUpdatedBy($user);

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
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return TagTree
     */
    public function createTagTree($user, $parent_tag, $child_tag, $created = null)
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
            // Use a locking service to ensure there's at most one (ancestor, descendant) pair
            $lockHandler = $this->lock_service->createLock('tt_'.$parent_tag->getId().'_'.$child_tag->getId().'.lock');
            if ( !$lockHandler->acquire() ) {
                // Another process is attempting to create this entity...wait for it to finish...
                $lockHandler->acquire(true);

                // ...then reload and return the tag tree entity that got created
                /** @var TagTree $tag_tree */
                $tag_tree = $this->em->getRepository('ODRAdminBundle:TagTree')->findOneBy(
                    array(
                        'parent' => $parent_tag,
                        'child' => $child_tag,
                    )
                );
                return $tag_tree;
            }
            else {
                if ( is_null($created) )
                    $created = new \DateTime();

                // No link exists, create a new entity
                $tag_tree = new TagTree();
                $tag_tree->setParent($parent_tag);
                $tag_tree->setChild($child_tag);

                $tag_tree->setCreated($created);
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
     * IMPORTANT: You really should be using {@link TagHelperService::updateSelectedTags()} instead of this.
     * If you do use this, then you MUST perform your own locking outside this function in order to
     * ensure there's at most one tag selection entity per (tag,drf) pair.
     *
     * @param ODRUser $user
     * @param Tags $tag
     * @param DataRecordFields $drf
     * @param int $initial_value
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return TagSelection
     */
    public function createTagSelection($user, $tag, $drf, $initial_value = 1, $delay_flush = false, $created = null)
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
            if ( is_null($created) )
                $created = new \DateTime();

            if ( $initial_value !== 0 )
                $initial_value = 1;

            $tag_selection = new TagSelection();
            $tag_selection->setTag($tag);

            $tag_selection->setDataRecord($drf->getDataRecord());
            $tag_selection->setDataRecordFields($drf);

            $tag_selection->setSelected($initial_value);

            $tag_selection->setCreated($created);
            $tag_selection->setUpdated($created);
            $tag_selection->setCreatedBy($user);
            $tag_selection->setUpdatedBy($user);

            $this->em->persist($tag_selection);
            if ( !$delay_flush ) {
                $this->em->flush();
                $this->em->refresh($tag_selection);
            }
        }

        return $tag_selection;
    }


    /**
     * Creates and persists a new Theme and its ThemeMeta entry.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return Theme
     */
    public function createTheme($user, $datatype, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        // Initial create
        $theme = new Theme();
        $theme->setDataType($datatype);

        // Assume top-level master theme
        $theme->setThemeType('master');
        $theme->setParentTheme($theme);
        $theme->setSourceTheme($theme);

        $theme->setCreated($created);
        $theme->setUpdated($created);
        $theme->setCreatedBy($user);
        $theme->setUpdatedBy($user);

        $datatype->addTheme($theme);
        $this->em->persist($theme);

        $theme_meta = new ThemeMeta();
        $theme_meta->setTheme($theme);
        $theme_meta->setTemplateName('');
        $theme_meta->setTemplateDescription('');

        $theme_meta->setDefaultFor(0);
        $theme_meta->setShared(false);
        $theme_meta->setDisableSearchSidebar(false);
        $theme_meta->setThemeVisibility(0);
        $theme_meta->setIsTableTheme(false);
        $theme_meta->setDisplaysAllResults(false);
        $theme_meta->setEnableHorizontalScrolling(false);

        $theme_meta->setSourceSyncVersion(1);

        // Currently unused...
        $theme_meta->setDisplayOrder(null);

        $theme_meta->setCreated($created);
        $theme_meta->setUpdated($created);
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
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ThemeDataField
     */
    public function createThemeDatafield($user, $theme_element, $datafield, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        // Create theme entry
        $theme_datafield = new ThemeDataField();
        $theme_datafield->setDataField($datafield);
        $theme_datafield->setThemeElement($theme_element);

        $theme_datafield->setDisplayOrder(999);
        $theme_datafield->setCssWidthMed('1-3');
        $theme_datafield->setCssWidthXL('1-3');
        $theme_datafield->setHidden(0);
        $theme_datafield->setHideHeader(false);

        $theme_datafield->setCreated($created);
        $theme_datafield->setUpdated($created);
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
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ThemeDataType
     */
    public function createThemeDatatype($user, $theme_element, $datatype, $child_theme, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        // Create theme entry
        $theme_datatype = new ThemeDataType();
        $theme_datatype->setDataType($datatype);
        $theme_datatype->setThemeElement($theme_element);
        $theme_datatype->setChildTheme($child_theme);

        $theme_datatype->setHidden(0);
        $theme_datatype->setDisplayType(ThemeDataType::ACCORDION_HEADER);

        $theme_datatype->setCreated($created);
        $theme_datatype->setUpdated($created);
        $theme_datatype->setCreatedBy($user);
        $theme_datatype->setUpdatedBy($user);

        $theme_element->addThemeDataType($theme_datatype);
        $this->em->persist($theme_datatype);

        if ( !$delay_flush )
            $this->em->flush();

        return $theme_datatype;
    }


    /**
     * Creates and persists a new ThemeRenderPluginInstance entry.
     *
     * @param ODRUser $user
     * @param ThemeElement $theme_element
     * @param RenderPluginInstance $render_plugin_instance
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ThemeRenderPluginInstance
     */
    public function createThemeRenderPluginInstance($user, $theme_element, $render_plugin_instance, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        // Initial create
        $trpi = new ThemeRenderPluginInstance();

        $trpi->setThemeElement($theme_element);
        $trpi->setRenderPluginInstance($render_plugin_instance);

        $trpi->setCreated($created);
        $trpi->setUpdated($created);
        $trpi->setCreatedBy($user);
        $trpi->setUpdatedBy($user);

        $theme_element->addThemeRenderPluginInstance($trpi);
        $this->em->persist($trpi);

        if ( !$delay_flush )
            $this->em->flush();

        return $trpi;
    }


    /**
     * Creates and persists a new ThemeElement and its ThemeElementMeta entry.
     *
     * @param ODRUser $user
     * @param Theme $theme
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ThemeElement
     */
    public function createThemeElement($user, $theme, $delay_flush = false, $created = null)
    {
        if ( is_null($created) )
            $created = new \DateTime();

        // Initial create
        $theme_element = new ThemeElement();
        $theme_element->setTheme($theme);

        $theme_element->setCreated($created);
        $theme_element->setCreatedBy($user);

        $theme->addThemeElement($theme_element);
        $this->em->persist($theme_element);

        $theme_element_meta = new ThemeElementMeta();
        $theme_element_meta->setThemeElement($theme_element);

        $theme_element_meta->setDisplayOrder(-1);
        $theme_element_meta->setHidden(0);
        $theme_element_meta->setHideBorder(false);
        $theme_element_meta->setCssWidthMed('1-1');
        $theme_element_meta->setCssWidthXL('1-1');

        $theme_element_meta->setCreated($created);
        $theme_element_meta->setUpdated($created);
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
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return UserGroup
     */
    public function createUserGroup($user, $group, $admin_user, $delay_flush = false, $created = null)
    {
        // Check to see if the User already belongs to this Group
        $query = $this->em->createQuery(
           'SELECT ug
            FROM ODRAdminBundle:UserGroup AS ug
            WHERE ug.user = :user_id AND ug.group = :group_id
            AND ug.deletedAt IS NULL'
        )->setParameters( array('user_id' => $user->getId(), 'group_id' => $group->getId()) );

        /** @var UserGroup[] $results */
        $results = $query->getResult();

        if ( count($results) > 0 ) {
            // If the User is already in this Group, then return it and don't create a duplicate
            foreach ($results as $num => $ug)
                return $ug;
        }

        if ( is_null($created) )
            $created = new \DateTime();

        // ...otherwise, create a new UserGroup entity
        $user_group = new UserGroup();
        $user_group->setUser($user);
        $user_group->setGroup($group);

        $user_group->setCreated($created);
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
