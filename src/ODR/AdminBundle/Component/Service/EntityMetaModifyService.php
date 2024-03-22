<?php

/**
 * Open Data Repository Data Publisher
 * Entity Meta Modify Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains the functions to update each the meta entities for all of the database entities.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\Boolean as ODRBoolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
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
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\RenderPluginOptionsMap;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\StoredSearchKey;
use ODR\AdminBundle\Entity\TagMeta;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\TagSelection;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Events
use ODR\AdminBundle\Component\Event\PostUpdateEvent;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class EntityMetaModifyService
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
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

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
     * EntityMetaModifyService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatatreeInfoService $datatree_info_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatreeInfoService $datatree_info_service,
        EventDispatcherInterface $event_dispatcher,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->datatree_info_service = $datatree_info_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->logger = $logger;
    }


    /**
     * Increments the "master_revision" property of the given template datafield.  Controller
     * actions already calling updateDatafieldMeta() don't need to call this.
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param bool $delay_flush
     */
    public function incrementDatafieldMasterRevision($user, $datafield, $delay_flush = false)
    {
        if ( !$datafield->getIsMasterField() )
            return;

        // Delaying flushes is usually a good idea, but apparently it can completely backfire when
        //  the functions in this service are repeatedly called to update the same entity.  Since
        //  all flushes are getting delayed, all the subsequent calls to self::createNewMetaEntry()
        //  only check the "original" DatafieldMeta entry, and therefore take one of two paths...

        // The first path is where self::createNewMetaEntry() will repeatedly return false.  In this
        //  case, self::updateDatafieldMeta() will repeatedly update the same DatafieldMeta entry,
        //  repeatedly incrementing the "master_revision" property...for example, inserting a new
        //  radio option and forcing a resort of the 5 existing radio options means that the
        //  datafield's "master_revision" property will end up incremented by like 6 or 7 instead
        //  of just 1.

        // However, the path where self::createNewMetaEntry() will repeatedly return true needs to
        //  be avoided at all costs...self::updateDatafieldMeta() will schedule a new DatafieldMeta
        //  entry every time its called, so when the flush eventually happens there will be multiple
        //  not-deleted DatafieldMeta entries pointing to the same Datafield.  To use the previous
        //  example, inserting a new radio option and forcing a resort of the 5 existing radio
        //  options will result in 6 or 7 new datafieldMeta entries for the datafield...at which
        //  point the database is in a *broken* state since there's only supposed to be one undeleted
        //  datafieldMeta entry active at any given time.

        // NOTE - do not make any changes without testing all code branches that lead to this point
        // NOTE - that means direct inspection of the database to see what gets created in all cases
        $found = false;
        $uow_insertions = $this->em->getUnitOfWork()->getScheduledEntityInsertions();
        foreach ($uow_insertions as $key => $entity) {
            // Check whether this Datafield has a DatafieldMeta entry that's already been
            //  scheduled to be created...
            if ( $entity instanceof DataFieldsMeta && $entity->getDataField()->getId() === $datafield->getId() ) {
                $found = true;
                break;
            }
        }

        // ...if this Datafield is not currently scheduled to receive a new DatafieldMeta entry,
        //  then it's safe to call self::updateDatafieldMeta() to update the "master_revision" property
        if ( !$found) {
            $props = array(
                'master_revision' => $datafield->getMasterRevision() + 1
            );
            self::updateDatafieldMeta($user, $datafield, $props, $delay_flush);
        }
    }


    /**
     * Increments the "master_revision" property of the given template datatype.  Controller
     * actions already calling updateDatatypeMeta() don't need to call this.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param bool $delay_flush
     */
    public function incrementDatatypeMasterRevision($user, $datatype, $delay_flush = false)
    {
        if ( !$datatype->getIsMasterType() )
            return;

        // The reasoning behind this is effectively identical to the reasoning described in
        //  self::incrementDatafieldMasterRevision().

        // NOTE - do not make any changes without testing all code branches that lead to this point
        // NOTE - that means direct inspection of the database to see what gets created in all cases
        $found = false;
        $uow_insertions = $this->em->getUnitOfWork()->getScheduledEntityInsertions();
        foreach ($uow_insertions as $key => $entity) {
            // Check whether this Datatype has a DatatypeMeta entry that's already been scheduled
            //  to be created...
            if ( $entity instanceof DataTypeMeta && $entity->getDataType()->getId() === $datatype->getId() ) {
                $found = true;
                break;
            }
        }

        // ...if this Datafield is not currently scheduled to receive a new DatatypeMeta entry,
        //  then it's safe to call self::updateDatatypeMeta() to update the "master_revision" property
        if ( !$found ) {
            $props = array(
                'master_revision' => $datatype->getMasterRevision() + 1
            );
            self::updateDatatypeMeta($user, $datatype, $props, $delay_flush);
        }
    }


    /**
     * Returns true if caller should create a new meta/storage entity, or false otherwise.
     *
     * Currently, a new meta/storage entity should get created unless $user has made a change to the
     * same entity within the past hour.
     *
     * @param ODRUser $user
     * @param mixed $meta_entry
     * @param \DateTime|null $modification_datetime
     *
     * @return boolean
     */
    private function createNewMetaEntry($user, $meta_entry, $modification_datetime = null)
    {
        if ( is_null($modification_datetime) )
            $modification_datetime = new \DateTime();

        /** @var \DateTime $last_updated */
        $last_updated = $meta_entry->getUpdated();
        /** @var ODRUser $last_updated_by */
        $last_updated_by = $meta_entry->getUpdatedBy();

        // If this change is being made by a different user, create a new meta entity
        if ( $last_updated == null || $last_updated_by == null || $last_updated_by->getId() !== $user->getId() )
            return true;

        $interval = $last_updated->diff($modification_datetime);
        // If the new change is supposed to be made "in the past" due to API shennanigans, then a new
        //  entity should always be created
        if ( $interval->invert === 1 )
            return true;
        // If change was made over an hour ago, create a new meta entity
        if ( $interval->y > 0 || $interval->m > 0 || $interval->d > 0 || $interval->h > 1 )
            return true;

        // Otherwise, update the existing meta entity
        return false;
    }


    // TODO - refactor to use PropertyInfo and PropertyAccessor instead?


    /**
     * Compares the given properties array against the given Datafield's meta entry, and either
     * updates the existing DatafieldMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataFieldsMeta
     */
    public function updateDatafieldMeta($user, $datafield, $properties, $delay_flush = false, $created = null)
    {
        // ----------------------------------------
        // Verify that changes to certain properties are given as Doctrine entities instead of ids
        if ( isset($properties['fieldType']) && !($properties['fieldType'] instanceof FieldType) )
            throw new ODRException('EntityMetaModifyService::updateDatafieldMeta(): $properties["fieldType"] is not an instanceof FieldType', 500, 0x0cbc871c);


        // ----------------------------------------
        // Load the old meta entry
        /** @var DataFieldsMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:DataFieldsMeta')->findOneBy(
            array(
                'dataField' => $datafield->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            // These entities can be set here since they're never null
            'fieldType' => $old_meta_entry->getFieldType(),

            'fieldName' => $old_meta_entry->getFieldName(),
            'description' => $old_meta_entry->getDescription(),
            'xml_fieldName' => $old_meta_entry->getXmlFieldName(),
            'internal_reference_name' => $old_meta_entry->getInternalReferenceName(),
            'markdownText' => $old_meta_entry->getMarkdownText(),
            'regexValidator' => $old_meta_entry->getRegexValidator(),
            'phpValidator' => $old_meta_entry->getPhpValidator(),
            'required' => $old_meta_entry->getRequired(),
            'is_unique' => $old_meta_entry->getIsUnique(),
            'force_numeric_sort' => $old_meta_entry->getForceNumericSort(),
            'prevent_user_edits' => $old_meta_entry->getPreventUserEdits(),
            'allow_multiple_uploads' => $old_meta_entry->getAllowMultipleUploads(),
            'shorten_filename' => $old_meta_entry->getShortenFilename(),
            'newFilesArePublic' => $old_meta_entry->getNewFilesArePublic(),
            'quality_str' => $old_meta_entry->getQualityStr(),
            'children_per_row' => $old_meta_entry->getChildrenPerRow(),
            'radio_option_name_sort' => $old_meta_entry->getRadioOptionNameSort(),
            'radio_option_display_unselected' => $old_meta_entry->getRadioOptionDisplayUnselected(),
            'tags_allow_multiple_levels' => $old_meta_entry->getTagsAllowMultipleLevels(),
            'tags_allow_non_admin_edit' => $old_meta_entry->getTagsAllowNonAdminEdit(),
            'searchable' => $old_meta_entry->getSearchable(),
            'publicDate' => $old_meta_entry->getPublicDate(),
            'master_revision' => $old_meta_entry->getMasterRevision(),
            'tracking_master_revision' => $old_meta_entry->getTrackingMasterRevision(),
            'master_published_revision' => $old_meta_entry->getMasterPublishedRevision(),
        );


        // ----------------------------------------
        // Want to ensure that certain properties aren't "out of sync" with the datafield's fieldtype

        // Use the value from $properties if it exists, otherwise fall back to the datafield's
        //  current value
        $relevant_fieldtype = $datafield->getFieldType();
        if ( isset($properties['fieldType']) )
            $relevant_fieldtype = $properties['fieldType'];

        $relevant_typeclass = $relevant_fieldtype->getTypeClass();    // NOTE - may not actually be different from old typeclass

        // If the datafield is no longer a radio field, then ensure it's not listed in the cached
        //  default radio options
        if ( $datafield->getFieldType()->getTypeClass() === 'Radio' && $relevant_typeclass !== 'Radio' )
            $this->cache_service->delete('default_radio_options');

        // Also need to specifically track changes from multiple radio/select to single radio/select
        $old_fieldtype_typename = $datafield->getFieldType()->getTypeName();
        $new_fieldtype_typename = $relevant_fieldtype->getTypeName();    // NOTE - may not actually be different from old typename

        // Ensure that the searchable property isn't set to something invalid for this fieldtype
        switch ($relevant_typeclass) {
            case 'DecimalValue':
            case 'IntegerValue':
            case 'LongText':
            case 'LongVarchar':
            case 'MediumVarchar':
            case 'ShortVarchar':
            case 'Radio':
            case 'Tag':
                // All of these fieldtypes can have any value for searchable
                break;

            case 'Image':
            case 'File':
            case 'Boolean':
            case 'DatetimeValue':
                // Use the value from $properties if it exists, otherwise fall back to the datafield's
                //  current value
                $relevant_searchable = $datafield->getSearchable();
                if ( isset($properties['searchable']) )
                    $relevant_searchable = $properties['searchable'];

                // General search is meaningless for these fieldtypes, so they can only
                //  be searched via advanced search
                if ($relevant_searchable == DataFields::GENERAL_SEARCH
                    || $relevant_searchable == DataFields::ADVANCED_SEARCH
                ) {
                    $properties['searchable'] = DataFields::ADVANCED_SEARCH_ONLY;
                }
                break;

            default:
                // No other fieldtypes can be searched
                $properties['searchable'] = DataFields::NOT_SEARCHED;
                break;
        }

        // Ensure that certain fieldtypes can't have the property that "prevents user edits"
        switch ($relevant_typeclass) {
            case 'File':
            case 'Image':
            case 'Radio':
            case 'Tag':
            case 'Markdown':
                $properties['prevent_user_edits'] = false;
                break;
        }

        // Reset a datafield's markdown text if it's not longer a markdown field
        if ($relevant_typeclass !== 'Markdown')
            $properties['markdownText'] = '';

        // Clear properties related to files and images if it's not one of those fieldtypes
        if ($relevant_typeclass !== 'File' && $relevant_typeclass !== 'Image') {
            $properties['allow_multiple_uploads'] = false;
            $properties['shorten_filename'] = false;
            $properties['newFilesArePublic'] = false;
            $properties['quality_str'] = '';
        }

        // Clear properties related to radio options and tags if it's not one of those fieldtypes
        if ($relevant_typeclass !== 'Radio' && $relevant_typeclass !== 'Tag') {
            // These properties are shared by radio options and tags
            $properties['radio_option_name_sort'] = false;
            $properties['radio_option_display_unselected'] = false;
        }
        if ($relevant_typeclass !== 'Tag') {
            // These properties are only used by tags
            $properties['tags_allow_multiple_levels'] = false;
            $properties['tags_allow_non_admin_edit'] = false;
        }


        // ----------------------------------------
        // Determine whether any changes need to be made
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value)
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_datafield_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the old DatafieldMeta entry
            $remove_old_entry = true;

            $new_datafield_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $new_datafield_meta->setCreated($created);
            $new_datafield_meta->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_datafield_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['fieldType']) )
            $new_datafield_meta->setFieldType( $properties['fieldType'] );

        if ( isset($properties['fieldName']) )
            $new_datafield_meta->setFieldName( $properties['fieldName'] );
        if ( isset($properties['description']) )
            $new_datafield_meta->setDescription( $properties['description'] );
        if ( isset($properties['xml_fieldName']) )
            $new_datafield_meta->setXmlFieldName( $properties['xml_fieldName'] );
        if ( isset($properties['internal_reference_name']) )
            $new_datafield_meta->setInternalReferenceName( $properties['internal_reference_name'] );
        if ( isset($properties['markdownText']) )
            $new_datafield_meta->setMarkdownText( $properties['markdownText'] );
        if ( isset($properties['regexValidator']) )
            $new_datafield_meta->setRegexValidator( $properties['regexValidator'] );
        if ( isset($properties['phpValidator']) )
            $new_datafield_meta->setPhpValidator( $properties['phpValidator'] );
        if ( isset($properties['required']) )
            $new_datafield_meta->setRequired( $properties['required'] );
        if ( isset($properties['is_unique']) )
            $new_datafield_meta->setIsUnique( $properties['is_unique'] );
        if ( isset($properties['force_numeric_sort']) )
            $new_datafield_meta->setForceNumericSort( $properties['force_numeric_sort'] );
        if ( isset($properties['prevent_user_edits']) )
            $new_datafield_meta->setPreventUserEdits( $properties['prevent_user_edits'] );
        if ( isset($properties['allow_multiple_uploads']) )
            $new_datafield_meta->setAllowMultipleUploads( $properties['allow_multiple_uploads'] );
        if ( isset($properties['newFilesArePublic']) )
            $new_datafield_meta->setNewFilesArePublic( $properties['newFilesArePublic'] );
        if ( isset($properties['shorten_filename']) )
            $new_datafield_meta->setShortenFilename( $properties['shorten_filename'] );
        if ( isset($properties['quality_str']) )
            $new_datafield_meta->setQualityStr( $properties['quality_str'] );
        if ( isset($properties['children_per_row']) )
            $new_datafield_meta->setChildrenPerRow( $properties['children_per_row'] );
        if ( isset($properties['radio_option_name_sort']) )
            $new_datafield_meta->setRadioOptionNameSort( $properties['radio_option_name_sort'] );
        if ( isset($properties['radio_option_display_unselected']) )
            $new_datafield_meta->setRadioOptionDisplayUnselected( $properties['radio_option_display_unselected'] );
        if ( isset($properties['tags_allow_multiple_levels']) )
            $new_datafield_meta->setTagsAllowMultipleLevels( $properties['tags_allow_multiple_levels'] );
        if ( isset($properties['tags_allow_non_admin_edit']) )
            $new_datafield_meta->setTagsAllowNonAdminEdit( $properties['tags_allow_non_admin_edit'] );
        if ( isset($properties['searchable']) )
            $new_datafield_meta->setSearchable( $properties['searchable'] );
        if ( isset($properties['publicDate']) )
            $new_datafield_meta->setPublicDate( $properties['publicDate'] );

        // If the "master_revision" property is set...
        if ( isset($properties['master_revision']) ) {
            // ...then always want to update the datafield with the provided value
            $new_datafield_meta->setMasterRevision( $properties['master_revision'] );
        }
        else if ( $datafield->getIsMasterField() ) {
            // ...otherwise, ensure the "master_revision" property always gets incremented when any
            //  change is made to a template datafield
            $new_datafield_meta->setMasterRevision($new_datafield_meta->getMasterRevision() + 1);
        }

        if ( isset($properties['tracking_master_revision']) )
            $new_datafield_meta->setTrackingMasterRevision( $properties['tracking_master_revision'] );
        if ( isset($properties['master_published_revision']) )
            $new_datafield_meta->setMasterPublishedRevision( $properties['master_published_revision'] );

        $new_datafield_meta->setUpdated($created);
        $new_datafield_meta->setUpdatedBy($user);

        // Delete the old meta entry if it's getting replaced
        if ($remove_old_entry) {
            $datafield->removeDataFieldMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the datafield knows about its new meta entry
            $datafield->addDataFieldMetum($new_datafield_meta);
        }

        //Save the new meta entry
        $this->em->persist($new_datafield_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($datafield);
        }


        // Now that the datafield got saved, check whether the field got changed to only allow a
        //  single radio selection...
        if ( ($old_fieldtype_typename == 'Multiple Radio' || $old_fieldtype_typename == 'Multiple Select')
            && ($new_fieldtype_typename == 'Single Radio' || $new_fieldtype_typename == 'Single Select')
        ) {
            // ...because that means the field needs to have at most a single default radio option
            $query = $this->em->createQuery(
               'SELECT ro
                FROM ODRAdminBundle:RadioOptionsMeta rom
                JOIN ODRAdminBundle:RadioOptions ro WITH rom.radioOption = ro
                WHERE ro.dataField = :datafield_id AND rom.isDefault = 1
                AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL
                ORDER BY rom.displayOrder'
            )->setParameters(array('datafield_id' => $datafield->getId()));
            $results = $query->getResult();

            $count = 0;
            foreach ($results as $ro) {
                /** @var RadioOptions $ro */

                // Ignore the first default radio option
                $count++;
                if ($count == 1)
                    continue;

                // Otherwise, mark the radio option as "not default"
                $props = array(
                    'isDefault' => false
                );
                self::updateRadioOptionsMeta($user, $ro, $props, true, $created);    // don't flush immediately
            }

            // If not delaying flush, then save the changes made to the radio options now
            if ( !$delay_flush )
                $this->em->flush();

            // Faster to just delete the cached list of default radio options, rather than try to
            //  figure out specifics
            $this->cache_service->delete('default_radio_options');
        }


        // Changes to the properties of a template datafield need to also update its datatype's
        //  master_revision property
        if ( $datafield->getIsMasterField() )
            self::incrementDatatypeMasterRevision($user, $datafield->getDataType(), $delay_flush);

        // Return the new entry
        return $new_datafield_meta;
    }


    /**
     * Compares the given properties array against the given Datarecord's meta entry, and either
     * updates the existing DatarecordMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataRecordMeta
     */
    public function updateDatarecordMeta($user, $datarecord, $properties, $delay_flush = false, $created = null)
    {
        // Load the old meta entry
        /** @var DataRecordMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:DataRecordMeta')->findOneBy(
            array(
                'dataRecord' => $datarecord->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'publicDate' => $old_meta_entry->getPublicDate(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_datarecord_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the existing DatarecordMeta entry
            $remove_old_entry = true;

            $new_datarecord_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $new_datarecord_meta->setCreated($created);
            $new_datarecord_meta->setCreatedBy($user);
        }
        else {
            $new_datarecord_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['publicDate']) )
            $new_datarecord_meta->setPublicDate( $properties['publicDate'] );

        $new_datarecord_meta->setUpdated($created);
        $new_datarecord_meta->setUpdatedBy($user);


        // Delete the old meta entry if it's getting replaced
        if ($remove_old_entry) {
            $datarecord->removeDataRecordMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the datarecord knows about its new meta entry
            $datarecord->addDataRecordMetum($new_datarecord_meta);
        }

        // Save the new meta entry
        $this->em->persist($new_datarecord_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($datarecord);
        }

        // Return the new entry
        return $new_datarecord_meta;
    }


    /**
     * Compares the given properties array against the given Datatree's meta entry, and either
     * updates the existing DatatreeMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user
     * @param DataTree $datatree
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataTreeMeta
     */
    public function updateDatatreeMeta($user, $datatree, $properties, $delay_flush = false, $created = null)
    {
        // Load the old meta entry
        /** @var DataTreeMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:DataTreeMeta')->findOneBy(
            array(
                'dataTree' => $datatree->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'multiple_allowed' => $old_meta_entry->getMultipleAllowed(),
            'is_link' => $old_meta_entry->getIsLink(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_datatree_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the old DatatreeMeta entry
            $remove_old_entry = true;

            $new_datatree_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $new_datatree_meta->setCreated($created);
            $new_datatree_meta->setCreatedBy($user);
        }
        else {
            $new_datatree_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['multiple_allowed']) )
            $new_datatree_meta->setMultipleAllowed( $properties['multiple_allowed'] );
        if ( isset($properties['is_link']) )
            $new_datatree_meta->setIsLink( $properties['is_link'] );

        $new_datatree_meta->setUpdated($created);
        $new_datatree_meta->setUpdatedBy($user);


        // Delete the old meta entry if it's getting replaced
        if ($remove_old_entry) {
            $datatree->removeDataTreeMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the datatree knows about its new meta entry
            $datatree->addDataTreeMetum($new_datatree_meta);
        }

        // Save the new meta entry
        $this->em->persist($new_datatree_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($datatree);
        }


        // Changing the "multiple_allowed" property of a child/linked datatype in a template need to
        //  also update the "master_revision" property of the ancestor datatype
        if ( $datatree->getAncestor()->getIsMasterType() )
            self::incrementDatatypeMasterRevision($user, $datatree->getAncestor(), $delay_flush);

        // Return the new entry
        return $new_datatree_meta;
    }


    /**
     * Compares the given properties array against the given Datatype's meta entry, and either updates
     * the existing DatatypeMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataTypeMeta
     */
    public function updateDatatypeMeta($user, $datatype, $properties, $delay_flush = false, $created = null)
    {
        // ----------------------------------------
        // Verify that changes to certain properties are given as Doctrine entities instead of ids
        // These properties are allowed to be null, so need to use array_key_exists() instead of isset()
        if ( array_key_exists('externalIdField', $properties)
            && !is_null($properties['externalIdField'])
            && !($properties['externalIdField'] instanceof DataFields)
        ) {
            throw new ODRException('EntityMetaModifyService::updateDatatypeMeta(): $properties["externalIdField"] is not an instanceof DataFields', 500, 0x7f1efae4);
        }
        if ( array_key_exists('nameField', $properties)
            && !is_null($properties['nameField'])
            && !($properties['nameField'] instanceof DataFields)
        ) {
            throw new ODRException('EntityMetaModifyService::updateDatatypeMeta(): $properties["nameField"] is not an instanceof DataFields', 500, 0x7f1efae4);
        }
        if ( array_key_exists('sortField', $properties)
            && !is_null($properties['sortField'])
            && !($properties['sortField'] instanceof DataFields)
        ) {
            throw new ODRException('EntityMetaModifyService::updateDatatypeMeta(): $properties["sortField"] is not an instanceof DataFields', 500, 0x7f1efae4);
        }
        if ( array_key_exists('backgroundImageField', $properties)
            && !is_null($properties['backgroundImageField'])
            && !($properties['backgroundImageField'] instanceof DataFields)
        ) {
            throw new ODRException('EntityMetaModifyService::updateDatatypeMeta(): $properties["backgroundImageField"] is not an instanceof DataFields', 500, 0x7f1efae4);
        }


        // ----------------------------------------
        // Load the old meta entry
        /** @var DataTypeMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy(
            array(
                'dataType' => $datatype->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'searchSlug' => $old_meta_entry->getSearchSlug(),
            'shortName' => $old_meta_entry->getShortName(),
            'longName' => $old_meta_entry->getLongName(),
            'description' => $old_meta_entry->getDescription(),
            'xml_shortName' => $old_meta_entry->getXmlShortName(),

            'searchNotesUpper' => $old_meta_entry->getSearchNotesUpper(),
            'searchNotesLower' => $old_meta_entry->getSearchNotesLower(),

            'publicDate' => $old_meta_entry->getPublicDate(),

            'newRecordsArePublic' => $old_meta_entry->getNewRecordsArePublic(),

            'master_published_revision' => $old_meta_entry->getMasterPublishedRevision(),
            'master_revision' => $old_meta_entry->getMasterRevision(),
            'tracking_master_revision' => $old_meta_entry->getTrackingMasterRevision(),
        );

        // These datafield entries could be null to begin with
        if ( !is_null($old_meta_entry->getExternalIdField()) )
            $existing_values['externalIdField'] = $old_meta_entry->getExternalIdField();
        if ( !is_null($old_meta_entry->getNameField()) )
            $existing_values['nameField'] = $old_meta_entry->getNameField();
        if ( !is_null($old_meta_entry->getSortField()) )
            $existing_values['sortField'] = $old_meta_entry->getSortField();
        if ( !is_null($old_meta_entry->getBackgroundImageField()) )
            $existing_values['backgroundImageField'] = $old_meta_entry->getBackgroundImageField();


        foreach ($existing_values as $key => $value) {
            // array_key_exists() is used because the datafield entries could legitimately be null
            if ( array_key_exists($key, $properties) && $properties[$key] != $value )
                $changes_made = true;
        }

        // Need to do an additional check incase the name/sort/etc datafields were originally null
        //  and changed to point to a datafield.  Can use isset() here because the value in
        //  $properties won't be null in this case
        if ( !isset($existing_values['externalIdField']) && isset($properties['externalIdField']) )
            $changes_made = true;
        if ( !isset($existing_values['nameField']) && isset($properties['nameField']) )
            $changes_made = true;
        if ( !isset($existing_values['sortField']) && isset($properties['sortField']) )
            $changes_made = true;
        if ( !isset($existing_values['backgroundImageField']) && isset($properties['backgroundImageField']) )
            $changes_made = true;

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_datatype_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the existing DatatypeMeta entry
            $remove_old_entry = true;

            $new_datatype_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $new_datatype_meta->setCreated($created);
            $new_datatype_meta->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_datatype_meta = $old_meta_entry;
        }


        // Set any new properties
        // isset() will return false when ('externalIdField' => null), so need to use
        //  array_key_exists() instead
        if ( array_key_exists('externalIdField', $properties) ) {
            if ( is_null($properties['externalIdField']) )
                $new_datatype_meta->setExternalIdField(null);
            else
                $new_datatype_meta->setExternalIdField( $properties['externalIdField'] );
        }
        if ( array_key_exists('nameField', $properties) ) {
            if ( is_null($properties['nameField']) )
                $new_datatype_meta->setNameField(null);
            else
                $new_datatype_meta->setNameField( $properties['nameField'] );
        }
        if ( array_key_exists('sortField', $properties) ) {
            if ( is_null($properties['sortField']) )
                $new_datatype_meta->setSortField(null);
            else
                $new_datatype_meta->setSortField( $properties['sortField'] );
        }
        if ( array_key_exists('backgroundImageField', $properties) ) {
            if ( is_null($properties['backgroundImageField']) )
                $new_datatype_meta->setBackgroundImageField(null);
            else
                $new_datatype_meta->setBackgroundImageField( $properties['backgroundImageField'] );
        }

        if ( isset($properties['searchSlug']) )
            $new_datatype_meta->setSearchSlug( $properties['searchSlug'] );
        if ( isset($properties['shortName']) )
            $new_datatype_meta->setShortName( $properties['shortName'] );
        if ( isset($properties['longName']) )
            $new_datatype_meta->setLongName( $properties['longName'] );
        if ( isset($properties['description']) )
            $new_datatype_meta->setDescription( $properties['description'] );
        if ( isset($properties['xml_shortName']) )
            $new_datatype_meta->setXmlShortName( $properties['xml_shortName'] );

        if ( isset($properties['searchNotesUpper']) )
            $new_datatype_meta->setSearchNotesUpper( $properties['searchNotesUpper'] );
        if ( isset($properties['searchNotesLower']) )
            $new_datatype_meta->setSearchNotesLower( $properties['searchNotesLower'] );

        if ( isset($properties['publicDate']) )
            $new_datatype_meta->setPublicDate( $properties['publicDate'] );

        if ( isset($properties['newRecordsArePublic']) )
            $new_datatype_meta->setNewRecordsArePublic( $properties['newRecordsArePublic'] );

        // If the "master_revision" property is set...
        if ( isset($properties['master_revision']) ) {
            // ...then always want to update the datatype with the provided value
            $new_datatype_meta->setMasterRevision( $properties['master_revision'] );
        }
        else if ( $datatype->getIsMasterType() ) {
            // ...otherwise, ensure the "master_revision" property always gets incremented when any
            //  change is made to a template datatype
            $new_datatype_meta->setMasterRevision($new_datatype_meta->getMasterRevision() + 1);
        }

        // Also need to update the "master_revision" property of any grandparents/linked ancestors
        //  of this datatype...
        if ($datatype->getIsMasterType()) {
            $grandparent_datatype = $datatype->getGrandparent();
            if ( $datatype->getId() !== $grandparent_datatype->getId() ) {
                // This is currently a child tempate datatype...need to update its grandparent's
                //  "master_revision" value
                self::incrementDatatypeMasterRevision($user, $grandparent_datatype, $delay_flush);

                // Don't need to worry about cache clearing for this logic path
            }
            else {
                // This is currently a top-level template datatype...check whether it's a linked
                //  descendant of other template datatypes...
                $linked_ancestors = $this->datatree_info_service->getLinkedAncestors(array($datatype->getId()));
                if ( !empty($linked_ancestors) ) {
                    // ...because if it is, all linked ancestors of this datatype also need to have
                    //  their "master_revision" value updated
                    foreach ($linked_ancestors as $linked_ancestor_id) {
                        /** @var DataType $linked_ancestor */
                        $linked_ancestor = $this->em->getRepository('ODRAdminBundle:DataType')->find($linked_ancestor_id);
                        self::incrementDatatypeMasterRevision($user, $linked_ancestor, $delay_flush);

                        // Need to independently trigger a cache clear for each of these linked
                        //  ancestor datatypes so they report the correct version number
                        $this->cache_service->delete('cached_datatype_'.$linked_ancestor_id);
                    }
                }
            }
        }

        if ( isset($properties['master_published_revision']) )
            $new_datatype_meta->setMasterPublishedRevision( $properties['master_published_revision'] );
        if ( isset($properties['tracking_master_revision']) )
            $new_datatype_meta->setTrackingMasterRevision( $properties['tracking_master_revision'] );

        $new_datatype_meta->setUpdated($created);
        $new_datatype_meta->setUpdatedBy($user);


        // Delete the old entry if it's getting replaced
        if ($remove_old_entry) {
            $datatype->removeDataTypeMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the datatype knows about its new meta entry
            $datatype->addDataTypeMetum($new_datatype_meta);
        }

        // Save the new meta entry
        $this->em->persist($new_datatype_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($datatype);
        }

        // Return the new entry
        return $new_datatype_meta;
    }


    /**
     * Copies the contents of the given DatatypeSpecialFields entity into a new entity if something
     * was changed.
     *
     * @param ODRUser $user
     * @param DataTypeSpecialFields $dtsf
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return DataTypeSpecialFields
     */
    public function updateDatatypeSpecialField($user, $dtsf, $properties, $delay_flush = false, $created = null)
    {
        // ----------------------------------------
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            // Not allowed to change datatype/datafield, or purpose
            'displayOrder' => $dtsf->getDisplayOrder(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $dtsf;


        // Determine whether to create a new entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_dtsf = null;
        if ( self::createNewMetaEntry($user, $dtsf, $created) ) {
            // Clone the old ThemeDatafield entry
            $remove_old_entry = true;

            $new_dtsf = clone $dtsf;

            // These properties need to be specified in order to be saved properly...
            $new_dtsf->setCreated($created);
            $new_dtsf->setCreatedBy($user);
        }
        else {
            // Update the existing entry
            $new_dtsf = $dtsf;
        }


        // Set any new properties
        if (isset($properties['displayOrder']))
            $new_dtsf->setDisplayOrder( $properties['displayOrder'] );

        $new_dtsf->setUpdated($created);
        $new_dtsf->setUpdatedBy($user);

        // Delete the old meta entry if needed
        if ($remove_old_entry)
            $this->em->remove($dtsf);

        // Save the new meta entry
        $this->em->persist($new_dtsf);
        if ( !$delay_flush )
            $this->em->flush();

        // Return the new entry
        return $new_dtsf;
    }


    /**
     * Compares the given properties array against the given File's meta entry, and either updates
     * the existing FileMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user
     * @param File $file
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return FileMeta
     */
    public function updateFileMeta($user, $file, $properties, $delay_flush = false, $created = null)
    {
        // Load the old meta entry
        /** @var FileMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:FileMeta')->findOneBy(
            array(
                'file' => $file->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'description' => $old_meta_entry->getDescription(),
            'original_filename' => $old_meta_entry->getOriginalFileName(),
            'quality' => $old_meta_entry->getQuality(),
            'external_id' => $old_meta_entry->getExternalId(),
            'publicDate' => $old_meta_entry->getPublicDate(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_file_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the old FileMeta entry
            $remove_old_entry = true;

            $new_file_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $new_file_meta->setCreated($created);
            $new_file_meta->setCreatedBy($user);
        }
        else {
            $new_file_meta = $old_meta_entry;
        }

        // Set any new properties
        if ( isset($properties['description']) )
            $new_file_meta->setDescription( $properties['description'] );
        if ( isset($properties['original_filename']) )
            $new_file_meta->setOriginalFileName( $properties['original_filename'] );
        if ( isset($properties['quality']) )
            $new_file_meta->setQuality( $properties['quality'] );
        if ( isset($properties['external_id']) )
            $new_file_meta->setExternalId( $properties['external_id'] );
        if ( isset($properties['publicDate']) )
            $new_file_meta->setPublicDate( $properties['publicDate'] );

        $new_file_meta->setUpdated($created);
        $new_file_meta->setUpdatedBy($user);


        // Delete the old entry if it's getting replaced
        if ($remove_old_entry) {
            $file->removeFileMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the file knows about its new meta entry
            $file->addFileMetum($new_file_meta);
        }

        // Save the new meta entry
        $this->em->persist($new_file_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($file);
        }

        // Return the new entry
        return $new_file_meta;
    }


    /**
     * Although it doesn't make sense to use previous GroupDatatypePermission entries, changes made
     * are handled the same as other soft-deleteable entities...delete the current one, and make a
     * new one with the changes.
     *
     * @param ODRUser $user
     * @param GroupDatatypePermissions $permission
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return GroupDatatypePermissions
     */
    public function updateGroupDatatypePermission($user, $permission, $properties, $delay_flush = false, $created = null)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'can_view_datatype' => $permission->getCanViewDatatype(),
            'can_view_datarecord' => $permission->getCanViewDatarecord(),
            'can_add_datarecord' => $permission->getCanAddDatarecord(),
            'can_delete_datarecord' => $permission->getCanDeleteDatarecord(),
            'can_change_public_status' => $permission->getCanChangePublicStatus(),
            'can_design_datatype' => $permission->getCanDesignDatatype(),
            'is_datatype_admin' => $permission->getIsDatatypeAdmin(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $permission;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_permission = null;
        if ( self::createNewMetaEntry($user, $permission, $created) ) {
            // Clone the existing GroupDatatypePermissions entry
            $remove_old_entry = true;

            $new_permission = clone $permission;

            // These properties need to be specified in order to be saved properly...
            $new_permission->setCreated($created);
            $new_permission->setCreatedBy($user);
        }
        else {
            $new_permission = $permission;
        }

        // Set any new properties
        if ( isset( $properties['can_view_datatype']) )
            $new_permission->setCanViewDatatype( $properties['can_view_datatype'] );
        if ( isset( $properties['can_view_datarecord']) )
            $new_permission->setCanViewDatarecord( $properties['can_view_datarecord'] );
        if ( isset( $properties['can_add_datarecord']) )
            $new_permission->setCanAddDatarecord( $properties['can_add_datarecord'] );
        if ( isset( $properties['can_delete_datarecord']) )
            $new_permission->setCanDeleteDatarecord( $properties['can_delete_datarecord'] );
        if ( isset( $properties['can_change_public_status']) )
            $new_permission->setCanChangePublicStatus( $properties['can_change_public_status'] );
        if ( isset( $properties['can_design_datatype']) )
            $new_permission->setCanDesignDatatype( $properties['can_design_datatype'] );
        if ( isset( $properties['is_datatype_admin']) )
            $new_permission->setIsDatatypeAdmin( $properties['is_datatype_admin'] );

        $new_permission->setUpdated($created);
        $new_permission->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($permission);

        // Save the new entry
        $this->em->persist($new_permission);
        if ( !$delay_flush )
            $this->em->flush();


        // Return the new entry
        return $new_permission;
    }


    /**
     * Although it doesn't make sense to use previous GroupDatafieldPermission entries, changes made
     * are handled the same as other soft-deleteable entities...delete the current one, and make a
     * new one with the changes.
     *
     * @param ODRUser $user
     * @param GroupDatafieldPermissions $permission
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return GroupDatafieldPermissions
     */
    public function updateGroupDatafieldPermission($user, $permission, $properties, $delay_flush = false, $created = null)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'can_view_datafield' => $permission->getCanViewDatafield(),
            'can_edit_datafield' => $permission->getCanEditDatafield(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $permission;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_permission = null;
        if ( self::createNewMetaEntry($user, $permission, $created) ) {
            // Clone the existing GroupDatafieldPermissions entry
            $remove_old_entry = true;

            $new_permission = clone $permission;

            // These properties need to be specified in order to be saved properly...
            $new_permission->setCreated($created);
            $new_permission->setCreatedBy($user);
        }
        else {
            $new_permission = $permission;
        }

        // Set any new properties
        if ( isset( $properties['can_view_datafield']) )
            $new_permission->setCanViewDatafield( $properties['can_view_datafield'] );
        if ( isset( $properties['can_edit_datafield']) )
            $new_permission->setCanEditDatafield( $properties['can_edit_datafield'] );

        $new_permission->setUpdated($created);
        $new_permission->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($permission);

        // Save the new entry
        $this->em->persist($new_permission);
        if (!$delay_flush)
            $this->em->flush();

        // Return the new entry
        return $new_permission;
    }


    /**
     * Compares the given properties array against the given Group's meta entry, and either updates
     * the existing GroupMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user
     * @param Group $group
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return GroupMeta
     */
    public function updateGroupMeta($user, $group, $properties, $delay_flush = false, $created = null)
    {
        // Load the old meta entry
        /** @var GroupMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:GroupMeta')->findOneBy(
            array(
                'group' => $group->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'groupName' => $old_meta_entry->getGroupName(),
            'groupDescription' => $old_meta_entry->getGroupDescription(),
            'datarecord_restriction' => $old_meta_entry->getDatarecordRestriction(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_group_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the existing GroupMeta entry
            $remove_old_entry = true;

            $new_group_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $new_group_meta->setCreated($created);
            $new_group_meta->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_group_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['groupName']) )
            $new_group_meta->setGroupName( $properties['groupName'] );
        if ( isset($properties['groupDescription']) )
            $new_group_meta->setGroupDescription( $properties['groupDescription'] );
        if ( isset($properties['datarecord_restriction']) )
            $new_group_meta->setDatarecordRestriction( $properties['datarecord_restriction'] );

        $new_group_meta->setUpdated($created);
        $new_group_meta->setUpdatedBy($user);


        // Delete the old entry if it's getting replaced
        if ($remove_old_entry) {
            $group->removeGroupMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the group knows about its new meta entry
            $group->addGroupMetum($new_group_meta);
        }

        // Save the new meta entry
        $this->em->persist($new_group_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($group);
        }

        // Return the new entry
        return $new_group_meta;
    }


    /**
     * Compares the given properties array against the given Image's meta entry, and either updates
     * the existing ImageMeta entry or clones a new one if needed.
     *
     * Resized Images (i.e. thumbnails) do not have their own meta entry, but use their parent's
     * meta entry instead.
     *
     * @param ODRUser $user
     * @param Image $image
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ImageMeta
     */
    public function updateImageMeta($user, $image, $properties, $delay_flush = false, $created = null)
    {
        // Load the old meta entry
        /** @var ImageMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:ImageMeta')->findOneBy(
            array(
                'image' => $image->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'caption' => $old_meta_entry->getCaption(),
            'original_filename' => $old_meta_entry->getOriginalFileName(),
            'quality' => $old_meta_entry->getQuality(),
            'external_id' => $old_meta_entry->getExternalId(),
            'publicDate' => $old_meta_entry->getPublicDate(),
            'display_order' => $old_meta_entry->getDisplayorder()
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value)
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_image_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the old ImageMeta entry
            $remove_old_entry = true;

            $new_image_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $new_image_meta->setCreated($created);
            $new_image_meta->setCreatedBy($user);
        }
        else {
            $new_image_meta = $old_meta_entry;
        }

        // Set any new properties
        if ( isset($properties['caption']) )
            $new_image_meta->setCaption( $properties['caption'] );
        if ( isset($properties['original_filename']) )
            $new_image_meta->setOriginalFileName( $properties['original_filename'] );
        if ( isset($properties['quality']) )
            $new_image_meta->setQuality( $properties['quality'] );
        if ( isset($properties['external_id']) )
            $new_image_meta->setExternalId( $properties['external_id'] );
        if ( isset($properties['publicDate']) )
            $new_image_meta->setPublicDate( $properties['publicDate'] );
        if ( isset($properties['display_order']) )
            $new_image_meta->setDisplayorder( $properties['display_order'] );

        $new_image_meta->setUpdated($created);
        $new_image_meta->setUpdatedBy($user);


        // Delete the old entry if it's getting replaced
        if ($remove_old_entry) {
            $image->removeImageMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the image knows about its new meta entry
            $image->addImageMetum($new_image_meta);
        }

        // Save the new meta entry
        $this->em->persist($new_image_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($image);
        }

        // Return the new entry
        return $new_image_meta;
    }


    /**
     * Compares the given properties array against the given RadioOption's meta entry, and either
     * updates the existing RadioOptionMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user
     * @param RadioOptions $radio_option
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return RadioOptionsMeta
     */
    public function updateRadioOptionsMeta($user, $radio_option, $properties, $delay_flush = false, $created = null)
    {
        // Load the old meta entry
        /** @var RadioOptionsMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:RadioOptionsMeta')->findOneBy(
            array(
                'radioOption' => $radio_option->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'optionName' => $old_meta_entry->getOptionName(),
            'xml_optionName' => $old_meta_entry->getXmlOptionName(),
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
            'isDefault' => $old_meta_entry->getIsDefault(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_radio_option_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the old RadioOptionsMeta entry
            $remove_old_entry = true;

            $new_radio_option_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $new_radio_option_meta->setCreated($created);
            $new_radio_option_meta->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_radio_option_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['optionName']) ) {
            $new_radio_option_meta->setOptionName( $properties['optionName'] );

            // The property in the meta entry should be in sync with the property in the regular entity
            // If it's not, then there can be some weird concurrency issues with CSV/XML importing,
            //  or when creating a bunch of radio options at once
            $radio_option->setOptionName( $properties['optionName'] );
            $this->em->persist($radio_option);
        }
        if ( isset($properties['xml_optionName']) )
            $new_radio_option_meta->setXmlOptionName( $properties['xml_optionName'] );
        if ( isset($properties['displayOrder']) )
            $new_radio_option_meta->setDisplayOrder( $properties['displayOrder'] );
        if ( isset($properties['isDefault']) )
            $new_radio_option_meta->setIsDefault( $properties['isDefault'] );

        $new_radio_option_meta->setUpdated($created);
        $new_radio_option_meta->setUpdatedBy($user);


        // Delete the old entry if it's getting replaced
        if ($remove_old_entry) {
            $radio_option->removeRadioOptionMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the radio option knows about its new meta entry
            $radio_option->addRadioOptionMetum($new_radio_option_meta);
        }

        // Save the new meta entry
        $this->em->persist($new_radio_option_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($radio_option);
        }

        // Changing any property of a template RadioOption should update the "master_revision"
        //  property of its template datafield
        if ( $radio_option->getDataField()->getIsMasterField() )
            self::incrementDatafieldMasterRevision($user, $radio_option->getDataField(), $delay_flush);

        // Return the new entry
        return $new_radio_option_meta;
    }


    /**
     * Modifies a given radio selection entity by copying the old value into a new storage entity,
     * then deleting the old entity.
     *
     * @param ODRUser $user
     * @param RadioSelection $radio_selection
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return RadioSelection
     */
    public function updateRadioSelection($user, $radio_selection, $properties, $delay_flush = false, $created = null)
    {
        // No point making new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'selected' => $radio_selection->getSelected()
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $radio_selection;

        // TODO - should changing radio/tag selections also trigger postUpdate events?  The Event itself isn't set up for it...

        // Determine whether to create a new entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_entity = null;
        if ( self::createNewMetaEntry($user, $radio_selection, $created) ) {
            // Clone the old RadioSelection entry
            $remove_old_entry = true;

            $new_entity = clone $radio_selection;

            // These properties need to be specified in order to be saved properly...
            $new_entity->setCreated($created);
            $new_entity->setCreatedBy($user);
        }
        else {
            $new_entity = $radio_selection;
        }

        // Set any new properties
        if ( isset($properties['selected']) )
            $new_entity->setSelected( $properties['selected'] );

        $new_entity->setUpdated($created);
        $new_entity->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($radio_selection);

        // Save the new meta entry
        $this->em->persist($new_entity);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($radio_selection->getRadioOption());
        }

        return $new_entity;
    }


    /**
     * Copies the contents of the given RenderPluginMap entity into a new RenderPluginMap entity
     * if something was changed.  TODO - this returns a boolean unlike other stuff...refactor?
     *
     * @param ODRUser $user
     * @param RenderPluginMap $render_plugin_map
     * @param array $properties
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return bool
     */
    public function updateRenderPluginMap($user, $render_plugin_map, $properties, $delay_flush = false, $created = null)
    {
        // ----------------------------------------
        // Verify that changes to certain properties are given as Doctrine entities instead of ids
        // These properties are allowed to be null, so need to use array_key_exists() instead of isset()
        if ( array_key_exists('dataField', $properties)
            && !is_null($properties['dataField'])
            && !($properties['dataField'] instanceof DataFields)
        ) {
            throw new ODRException('EntityMetaModifyService::updateRenderPluginMap(): $properties["dataField"] is not an instanceof DataFields', 500, 0xa190c481);
        }


        // ----------------------------------------
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'dataField' => $render_plugin_map->getDataField(),
        );

        // This entry could be null to begin with
        if ( !is_null($render_plugin_map->getDataField()) )
            $existing_values['dataField'] = $render_plugin_map->getDataField();


        foreach ($existing_values as $key => $value) {
            // array_key_exists() is used because the datafield entry could legitimately be null
            if ( array_key_exists($key, $properties) && $properties[$key] != $value )
                $changes_made = true;
        }

        // Need to do an additional check incase the mapped datafield was originally null, but got
        //  changed to point to a datafield.  Can use isset() here because the value in $properties
        //  won't be null in this case
        if ( !isset($existing_values['dataField']) && isset($properties['dataField']) )
            $changes_made = true;

        if (!$changes_made)
//            return $render_plugin_map;
            return false;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_rpm = null;
        if ( self::createNewMetaEntry($user, $render_plugin_map, $created) ) {
            // Clone the old RenderPluginMap entry
            $remove_old_entry = true;

            $new_rpm = clone $render_plugin_map;

            // These properties need to be specified in order to be saved properly...
            $new_rpm->setCreated($created);
            $new_rpm->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_rpm = $render_plugin_map;
        }

        // Set any new properties
        // isset() will return false when ('dataField' => null), so need to use array_key_exists() instead
        if ( array_key_exists('dataField', $properties) ) {
            if ( is_null($properties['dataField']) )
                $new_rpm->setDataField(null);
            else
                $new_rpm->setDataField( $properties['dataField'] );
        }

        $new_rpm->setUpdated($created);
        $new_rpm->setUpdatedBy($user);
        $this->em->persist($new_rpm);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($render_plugin_map);

        // Save the new meta entry
        if ( !$delay_flush )
            $this->em->flush();


        // Return the new entry
//        return $new_rpm;
        return true;
    }


    /**
     * Copies the contents of the given RenderPluginOptionsMap entity into a new RenderPluginOptionsMap
     * entity if something was changed.  TODO - this returns a boolean unlike other stuff...refactor?
     *
     * @param ODRUser $user
     * @param RenderPluginOptionsMap $render_plugin_options_map
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return bool
     */
    public function updateRenderPluginOptionsMap($user, $render_plugin_options_map, $properties, $delay_flush = false, $created = null)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'value' => $render_plugin_options_map->getValue(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
//            return $render_plugin_option;
            return false;

        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_rpom = null;
        if ( self::createNewMetaEntry($user, $render_plugin_options_map, $created) ) {
            // Clone the old RenderPluginOptionsMap entry
            $remove_old_entry = true;

            $new_rpom = clone $render_plugin_options_map;

            // These properties need to be specified in order to be saved properly...
            $new_rpom->setCreated($created);
            $new_rpom->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_rpom = $render_plugin_options_map;
        }


        // Set any new properties
        if (isset($properties['value']))
            $new_rpom->setValue( $properties['value'] );

        $new_rpom->setUpdated($created);
        $new_rpom->setUpdatedBy($user);
        $this->em->persist($new_rpom);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($render_plugin_options_map);

        // Save the new meta entry
        if ( !$delay_flush )
            $this->em->flush();


        // Return the new entry
//        return $new_rpom;
        return true;
    }


    /**
     * Modifies a given storage entity by copying the old value into a new storage entity, then
     * deleting the old entity.
     *
     * @param ODRUser $user
     * @param ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $entity
     * @param array $properties
     * @param bool $delay_flush
     * @param bool $fire_event  If false, then don't fire the PostUpdateEvent
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    public function updateStorageEntity($user, $entity, $properties, $delay_flush = false, $fire_event = true, $created = null)
    {
        // Determine which type of entity to create if needed
        $typeclass = $entity->getDataField()->getFieldType()->getTypeClass();
        $classname = "ODR\\AdminBundle\\Entity\\".$typeclass;

        // No point making new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'value' => $entity->getValue()
        );

        // Change current values stored in IntegerValue or DecimalValue entities to strings...all
        //  values in $properties are already strings, and php does odd compares between strings
        //  and numbers
        if ($typeclass == 'IntegerValue' || $typeclass == 'DecimalValue')
            $existing_values['value'] = strval($existing_values['value']);

        // NOTE - intentionally doesn't prevent overly-long strings from being saved into the
        //  Short/Medium/LongVarchar entities...an exception being thrown is usually desirable

        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] !== $value )
                $changes_made = true;
        }

        if ( !$changes_made ) {
            if ( $fire_event ) {
                // ----------------------------------------
                // This is wrapped in a try/catch block because any uncaught exceptions thrown by the
                //  event subscribers will prevent file encryption otherwise...
                try {
                    $event = new PostUpdateEvent($entity, $user);
                    $this->event_dispatcher->dispatch(PostUpdateEvent::NAME, $event);

                    // TODO - callers of this function can't access $event, so they can't get a reference to any derived storage entity...
                }
                catch (\Exception $e) {
                    // ...the event stuff is likely going to "disappear" any error it encounters, but
                    //  might as well rethrow anything caught here since there shouldn't be a critical
                    //  process downstream anyways
//                    if ( $this->env === 'dev' )
//                        throw $e;
                }
            }

            return $entity;
        }

        // If this is an IntegerValue entity, set the value back to an integer or null so it gets
        //  saved correctly
        if ($typeclass == 'IntegerValue') {
            if ($properties['value'] === '')
                $properties['value'] = null;
            else
                $properties['value'] = intval($properties['value']);
        }
        // Don't need to do the same for DecimalValue here, setValue() will deal with it


        // Determine whether to create a new entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_entity = null;
        if ( self::createNewMetaEntry($user, $entity, $created) ) {
            // Create a new entry and copy the previous one's data over
            $remove_old_entry = true;

            /** @var ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $new_entity */
            $new_entity = new $classname();
            $new_entity->setDataRecord( $entity->getDataRecord() );
            $new_entity->setDataField( $entity->getDataField() );
            $new_entity->setDataRecordFields( $entity->getDataRecordFields() );
            $new_entity->setFieldType( $entity->getFieldType() );

            $new_entity->setValue( $entity->getValue() );
            if ($typeclass == 'DecimalValue')
                $new_entity->setOriginalValue( $entity->getOriginalValue() );
            $new_entity->setConvertedValue( $entity->getConvertedValue() );

            $new_entity->setCreated($created);
            $new_entity->setCreatedBy($user);
        }
        else {
            $new_entity = $entity;
        }

        // Set any new properties...not checking isset() because it couldn't reach this point
        //  without being isset()...also,  isset( array[key] ) == false  when  array(key => null)
        $new_entity->setValue( $properties['value'] );

        $new_entity->setUpdated($created);
        $new_entity->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($entity);

        // Save the new meta entry
        $this->em->persist($new_entity);
        if ( !$delay_flush )
            $this->em->flush();

        if ( $fire_event ) {
            // ----------------------------------------
            // This is wrapped in a try/catch block because any uncaught exceptions thrown by the
            //  event subscribers will prevent file encryption otherwise...
            try {
                $event = new PostUpdateEvent($new_entity, $user);
                $this->event_dispatcher->dispatch(PostUpdateEvent::NAME, $event);

                // TODO - callers of this function can't access $event, so they can't get a reference to any derived storage entity...
            }
            catch (\Exception $e) {
                // ...the event stuff is likely going to "disappear" any error it encounters, but
                //  might as well rethrow anything caught here since there shouldn't be a critical
                //  process downstream anyways
//                if ( $this->env === 'dev' )
//                    throw $e;
            }
        }

        return $new_entity;
    }


    /**
     * Copies the contents of the given StoredSearchKey entity into a new entity if something
     * was changed.
     *
     * @param ODRUser $user
     * @param StoredSearchKey $ssk
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return StoredSearchKey
     */
    public function updateStoredSearchKey($user, $ssk, $properties, $delay_flush = false)
    {
        // ----------------------------------------
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            // Not allowed to change datatype
            'storageLabel' => $ssk->getStorageLabel(),
            'searchKey' => $ssk->getSearchKey(),
            'isDefault' => $ssk->getIsDefault(),
            'isPublic' => $ssk->getIsPublic(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $ssk;


        // Determine whether to create a new entry or modify the previous one
        $remove_old_entry = false;
        $new_ssk = null;
        if ( self::createNewMetaEntry($user, $ssk) ) {
            // Clone the old ThemeDatafield entry
            $remove_old_entry = true;

            $new_ssk = clone $ssk;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_ssk->setCreated(new \DateTime());
            $new_ssk->setUpdated(new \DateTime());
            $new_ssk->setCreatedBy($user);
            $new_ssk->setUpdatedBy($user);
        }
        else {
            // Update the existing entry
            $new_ssk = $ssk;
        }


        // Set any new properties
        if ( isset($properties['storageLabel']) )
            $new_ssk->setStorageLabel( $properties['storageLabel'] );
        if ( isset($properties['searchKey']) )
            $new_ssk->setSearchKey( $properties['searchKey'] );
        if ( isset($properties['isDefault']) )
            $new_ssk->setIsDefault( $properties['isDefault'] );
        if ( isset($properties['isPublic']) )
            $new_ssk->setIsPublic( $properties['isPublic'] );

        $new_ssk->setUpdatedBy($user);

        // Delete the old meta entry if needed
        if ($remove_old_entry)
            $this->em->remove($ssk);

        // Save the new meta entry
        $this->em->persist($new_ssk);
        if ( !$delay_flush )
            $this->em->flush();

        // Return the new entry
        return $new_ssk;
    }


    /**
     * Compares the given properties array against the given Tag's meta entry, and either updates
     * the existing TagMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user
     * @param Tags $tag
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return TagMeta
     */
    public function updateTagMeta($user, $tag, $properties, $delay_flush = false, $created = null)
    {
        // Load the old meta entry
        /** @var TagMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:TagMeta')->findOneBy(
            array(
                'tag' => $tag->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'tagName' => $old_meta_entry->getTagName(),
            'xml_tagName' => $old_meta_entry->getXmlTagName(),
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_tag_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the old TagMeta entry
            $remove_old_entry = true;

            $new_tag_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $new_tag_meta->setCreated($created);
            $new_tag_meta->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_tag_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['tagName']) ) {
            $new_tag_meta->setTagName( $properties['tagName'] );

            // The property in the meta entry should be in sync with the property in the regular entity
            // If it's not, then there can be some weird concurrency issues with CSV/XML importing,
            //  or when creating a bunch of tags at once
            $tag->setTagName( $properties['tagName'] );
            $this->em->persist($tag);
        }
        if ( isset($properties['xml_tagName']) )
            $new_tag_meta->setXmlTagName( $properties['xml_tagName'] );
        if ( isset($properties['displayOrder']) )
            $new_tag_meta->setDisplayOrder( $properties['displayOrder'] );

        $new_tag_meta->setUpdated($created);
        $new_tag_meta->setUpdatedBy($user);


        // Delete the old entry if it's getting replaced
        if ($remove_old_entry) {
            $tag->removeTagMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the tag knows about its new meta entry
            $tag->addTagMetum($new_tag_meta);
        }

        // Save the new meta entry
        $this->em->persist($new_tag_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($tag);
        }

        // Changing any property of a template Tag should update the "master_revision" property of
        //  its template datafield
        if ( $tag->getDataField()->getIsMasterField() )
            self::incrementDatafieldMasterRevision($user, $tag->getDataField(), $delay_flush);

        // Return the new entry
        return $new_tag_meta;
    }


    /**
     * Modifies a given tag selection entity by copying the old value into a new storage entity,
     * then deleting the old entity.
     *
     * @param ODRUser $user
     * @param TagSelection $tag_selection
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return TagSelection
     */
    public function updateTagSelection($user, $tag_selection, $properties, $delay_flush = false, $created = null)
    {
        // No point making new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'selected' => $tag_selection->getSelected()
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $tag_selection;

        // TODO - should changing radio/tag selections also trigger postUpdate events?  The Event itself isn't set up for it...

        // Determine whether to create a new entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_entity = null;
        if ( self::createNewMetaEntry($user, $tag_selection, $created) ) {
            // Clone the old TagSelection entry
            $remove_old_entry = true;

            $new_entity = clone $tag_selection;

            // These properties need to be specified in order to be saved properly...
            $new_entity->setCreated($created);
            $new_entity->setCreatedBy($user);
        }
        else {
            $new_entity = $tag_selection;
        }

        // Set any new properties
        if ( isset($properties['selected']) )
            $new_entity->setSelected( $properties['selected'] );

        $new_entity->setUpdated($created);
        $new_entity->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($tag_selection);

        // Save the new meta entry
        $this->em->persist($new_entity);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($tag_selection->getTag());
        }

        return $new_entity;
    }


    /**
     * Copies the contents of the given ThemeDatafield entity into a new ThemeDatafield entity if
     * something was changed
     *
     * @param ODRUser $user
     * @param ThemeDataField $theme_datafield
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ThemeDataField
     */
    public function updateThemeDatafield($user, $theme_datafield, $properties, $delay_flush = false, $created = null)
    {
        // ----------------------------------------
        // Verify that changes to certain properties are given as Doctrine entities instead of ids
        if ( isset($properties['themeElement']) && !($properties['themeElement'] instanceof ThemeElement) )
            throw new ODRException('EntityMetaModifyService::updateThemeDatafield(): $properties["themeElement"] is not an instanceof ThemeElement', 500, 0xa67d1d77);


        // ----------------------------------------
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            // This entity can be set here since it's never null
            'themeElement' => $theme_datafield->getThemeElement(),

            'displayOrder' => $theme_datafield->getDisplayOrder(),
            'cssWidthMed' => $theme_datafield->getCssWidthMed(),
            'cssWidthXL' => $theme_datafield->getCssWidthXL(),
            'hidden' => $theme_datafield->getHidden(),
            'hideHeader' => $theme_datafield->getHideHeader(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $theme_datafield;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_theme_datafield = null;
        if ( self::createNewMetaEntry($user, $theme_datafield, $created) ) {
            // Clone the old ThemeDatafield entry
            $remove_old_entry = true;

            $new_theme_datafield = clone $theme_datafield;

            // These properties need to be specified in order to be saved properly...
            $new_theme_datafield->setCreated($created);
            $new_theme_datafield->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_theme_datafield = $theme_datafield;
        }


        // Set any new properties
        if (isset($properties['themeElement']))
            $new_theme_datafield->setThemeElement( $properties['themeElement'] );

        if (isset($properties['displayOrder']))
            $new_theme_datafield->setDisplayOrder( $properties['displayOrder'] );
        if (isset($properties['cssWidthMed']))
            $new_theme_datafield->setCssWidthMed( $properties['cssWidthMed'] );
        if (isset($properties['cssWidthXL']))
            $new_theme_datafield->setCssWidthXL( $properties['cssWidthXL'] );
        if (isset($properties['hidden']))
            $new_theme_datafield->setHidden( $properties['hidden'] );
        if (isset($properties['hideHeader']))
            $new_theme_datafield->setHideHeader( $properties['hideHeader'] );

        $new_theme_datafield->setUpdated($created);
        $new_theme_datafield->setUpdatedBy($user);


        // TODO - use $theme_element->add/removeThemeDataField() ?
        // Delete the old meta entry if needed
        if ($remove_old_entry)
            $this->em->remove($theme_datafield);

        // Save the new meta entry
        $this->em->persist($new_theme_datafield);
        if ( !$delay_flush )
            $this->em->flush();

        // Return the new entry
        return $new_theme_datafield;
    }


    /**
     * Copies the contents of the given ThemeDatatype entity into a new ThemeDatatype entity if
     * something was changed
     *
     * @param ODRUser $user
     * @param ThemeDataType $theme_datatype
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ThemeDataType
     */
    public function updateThemeDatatype($user, $theme_datatype, $properties, $delay_flush = false, $created = null)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'display_type' => $theme_datatype->getDisplayType(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $theme_datatype;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_theme_datatype = null;
        if ( self::createNewMetaEntry($user, $theme_datatype, $created) ) {
            // Clone the old ThemeDatatype entry
            $remove_old_entry = true;

            $new_theme_datatype = clone $theme_datatype;

            // These properties need to be specified in order to be saved properly...
            $new_theme_datatype->setCreated($created);
            $new_theme_datatype->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_theme_datatype = $theme_datatype;
        }


        // Set any new properties
        if (isset($properties['display_type']))
            $new_theme_datatype->setDisplayType( $properties['display_type'] );

        $new_theme_datatype->setUpdated($created);
        $new_theme_datatype->setUpdatedBy($user);


        // TODO - use $theme_element->add/removeThemeDataType() ?
        // Delete the old meta entry if needed
        if ($remove_old_entry)
            $this->em->remove($theme_datatype);

        // Save the new meta entry
        $this->em->persist($new_theme_datatype);
        if ( !$delay_flush )
            $this->em->flush();

        // Return the new entry
        return $new_theme_datatype;
    }


    /**
     * Modifies a meta entry for a given ThemeElement entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * @param ODRUser $user
     * @param ThemeElement $theme_element
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ThemeElementMeta
     */
    public function updateThemeElementMeta($user, $theme_element, $properties, $delay_flush = false, $created = null)
    {
        // Load the old meta entry
        /** @var ThemeElementMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:ThemeElementMeta')->findOneBy(
            array(
                'themeElement' => $theme_element->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
            'hidden' => $old_meta_entry->getHidden(),
            'hideBorder' => $old_meta_entry->getHideBorder(),
            'cssWidthMed' => $old_meta_entry->getCssWidthMed(),
            'cssWidthXL' => $old_meta_entry->getCssWidthXL(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $theme_element_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the old ThemeelementMeta entry
            $remove_old_entry = true;

            $theme_element_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $theme_element_meta->setCreated($created);
            $theme_element_meta->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $theme_element_meta = $old_meta_entry;
        }


        // Set any changed properties
        if ( isset($properties['displayOrder']) )
            $theme_element_meta->setDisplayOrder( $properties['displayOrder'] );
        if ( isset($properties['hidden']) )
            $theme_element_meta->setHidden( $properties['hidden'] );
        if ( isset($properties['hideBorder']) )
            $theme_element_meta->setHideBorder( $properties['hideBorder'] );
        if ( isset($properties['cssWidthMed']) )
            $theme_element_meta->setCssWidthMed( $properties['cssWidthMed'] );
        if ( isset($properties['cssWidthXL']) )
            $theme_element_meta->setCssWidthXL( $properties['cssWidthXL'] );

        $theme_element_meta->setUpdated($created);
        $theme_element_meta->setUpdatedBy($user);

        // Delete the old meta entry if needed
        if ($remove_old_entry) {
            $theme_element->removeThemeElementMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the theme element knows about its new meta entry
            $theme_element->addThemeElementMetum($theme_element_meta);
        }

        // Save the new meta entry
        $this->em->persist($theme_element_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($theme_element);
        }

        // Return the meta entry
        return $theme_element_meta;
    }


    /**
     * Compares the given properties array against the given Theme's meta entry, and either updates
     * the existing ThemeMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user
     * @param Theme $theme
     * @param array $properties
     * @param bool $delay_flush
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return ThemeMeta
     */
    public function updateThemeMeta($user, $theme, $properties, $delay_flush = false, $created = null)
    {
        // Load the old meta entry
        /** @var ThemeMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:ThemeMeta')->findOneBy(
            array(
                'theme' => $theme->getId()
            )
        );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'templateName' => $old_meta_entry->getTemplateName(),
            'templateDescription' => $old_meta_entry->getTemplateDescription(),
            'defaultFor' => $old_meta_entry->getDefaultFor(),
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
            'shared' => $old_meta_entry->getShared(),
            'sourceSyncVersion' => $old_meta_entry->getSourceSyncVersion(),
            'isTableTheme' => $old_meta_entry->getIsTableTheme(),
            'displaysAllResults' => $old_meta_entry->getDisplaysAllResults(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        if ( is_null($created) )
            $created = new \DateTime();

        $remove_old_entry = false;
        $new_theme_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry, $created) ) {
            // Clone the old ThemeMeta entry
            $remove_old_entry = true;

            $new_theme_meta = clone $old_meta_entry;

            // These properties need to be specified in order to be saved properly...
            $new_theme_meta->setCreated($created);
            $new_theme_meta->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_theme_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['templateName']) )
            $new_theme_meta->setTemplateName( $properties['templateName'] );
        if ( isset($properties['templateDescription']) )
            $new_theme_meta->setTemplateDescription( $properties['templateDescription'] );
        if ( isset($properties['defaultFor']) )
            $new_theme_meta->setDefaultFor( $properties['defaultFor'] );
        if ( isset($properties['displayOrder']) )
            $new_theme_meta->setDisplayOrder( $properties['displayOrder'] );
        if ( isset($properties['shared']) )
            $new_theme_meta->setShared( $properties['shared'] );
        if ( isset($properties['sourceSyncVersion']) )
            $new_theme_meta->setSourceSyncVersion( $properties['sourceSyncVersion'] );
        if ( isset($properties['isTableTheme']) )
            $new_theme_meta->setIsTableTheme( $properties['isTableTheme'] );
        if ( isset($properties['displaysAllResults']) )
            $new_theme_meta->setDisplaysAllResults( $properties['displaysAllResults'] );

        // Earlier versions of ODR used a combination of theme_type and page_type to control
        //  when and where they were used...in late 2023 this was changed so that any theme
        //  could be used anywhere, and as a result theme_type only because useful to indicate a
        //  datatype's "master" theme
        if ($theme->getThemeType() !== 'master') {
            $theme->setThemeType('custom');
            $this->em->persist($theme);
        }

        $new_theme_meta->setUpdated($created);
        $new_theme_meta->setUpdatedBy($user);

        // Delete the old meta entry if it's getting replaced
        if ($remove_old_entry) {
            $theme->removeThemeMetum($old_meta_entry);
            $this->em->remove($old_meta_entry);

            // Ensure the theme knows about its new meta entry
            $theme->addThemeMetum($new_theme_meta);
        }

        // Save the new meta entry
        $this->em->persist($new_theme_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($theme);
        }

        // Return the new entry
        return $new_theme_meta;
    }
}
