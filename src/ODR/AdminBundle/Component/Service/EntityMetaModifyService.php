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
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
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
use ODR\AdminBundle\Entity\ShortVarchar;
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
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


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
    private $dti_service;

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
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatreeInfoService $datatree_info_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dti_service = $datatree_info_service;
        $this->logger = $logger;
    }


    /**
     * Returns true if caller should create a new meta entry, or false otherwise.
     * Currently, this decision is based on when the last change was made, and who made the change
     * ...if change was made by a different person, or within the past hour, don't create a new entry
     *
     * @param ODRUser $user
     * @param mixed $meta_entry
     *
     * @return boolean
     */
    private function createNewMetaEntry($user, $meta_entry)
    {
        $current_datetime = new \DateTime();

        /** @var \DateTime $last_updated */
        /** @var ODRUser $last_updated_by */
        $last_updated = $meta_entry->getUpdated();
        $last_updated_by = $meta_entry->getUpdatedBy();

        // If this change is being made by a different user, create a new meta entry
        if ( $last_updated == null || $last_updated_by == null || $last_updated_by->getId() !== $user->getId() )
            return true;

        // If change was made over an hour ago, create a new meta entry
        $interval = $last_updated->diff($current_datetime);
        if ( $interval->y > 0 || $interval->m > 0 || $interval->d > 0 || $interval->h > 1 )
            return true;

        // Otherwise, update the existing meta entry
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
     *
     * @return DataFieldsMeta
     */
    public function updateDatafieldMeta($user, $datafield, $properties, $delay_flush = false)
    {
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
            'fieldType' => $old_meta_entry->getFieldType()->getId(),
            'renderPlugin' => $old_meta_entry->getRenderPlugin()->getId(),

            'fieldName' => $old_meta_entry->getFieldName(),
            'description' => $old_meta_entry->getDescription(),
            'xml_fieldName' => $old_meta_entry->getXmlFieldName(),
            'internal_reference_name' => $old_meta_entry->getInternalReferenceName(),
            'markdownText' => $old_meta_entry->getMarkdownText(),
            'regexValidator' => $old_meta_entry->getRegexValidator(),
            'phpValidator' => $old_meta_entry->getPhpValidator(),
            'required' => $old_meta_entry->getRequired(),
            'is_unique' => $old_meta_entry->getIsUnique(),
            'allow_multiple_uploads' => $old_meta_entry->getAllowMultipleUploads(),
            'shorten_filename' => $old_meta_entry->getShortenFilename(),
            'newFilesArePublic' => $old_meta_entry->getNewFilesArePublic(),
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
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value)
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_datafield_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old DatafieldMeta entry
            $remove_old_entry = true;

            $new_datafield_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_datafield_meta->setCreated(new \DateTime());
            $new_datafield_meta->setUpdated(new \DateTime());
            $new_datafield_meta->setCreatedBy($user);
            $new_datafield_meta->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_datafield_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['fieldType']) )
            $new_datafield_meta->setFieldType( $this->em->getRepository('ODRAdminBundle:FieldType')->find( $properties['fieldType'] ) );
        if ( isset($properties['renderPlugin']) )
            $new_datafield_meta->setRenderPlugin( $this->em->getRepository('ODRAdminBundle:RenderPlugin')->find( $properties['renderPlugin'] ) );

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
        if ( isset($properties['allow_multiple_uploads']) )
            $new_datafield_meta->setAllowMultipleUploads( $properties['allow_multiple_uploads'] );
        if ( isset($properties['newFilesArePublic']) )
            $new_datafield_meta->setNewFilesArePublic( $properties['newFilesArePublic'] );
        if ( isset($properties['shorten_filename']) )
            $new_datafield_meta->setShortenFilename( $properties['shorten_filename'] );
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


        // All metadata changes result in a new Data Field Master Published Revision.  Revision
        // changes are picked up by derivative data types when the parent data type revision is changed.
        if ( $datafield->getIsMasterField() ) {
            $props = array(
                'master_revision' => $datafield->getDataType()->getMasterRevision() + 1
            );
            self::updateDatatypeMeta($user, $datafield->getDataType(), $props, $delay_flush);
        }

        // Return the new entry
        return $new_datafield_meta;
    }


    /**
     * Copies the given DatarecordMeta entry into a new DatarecordMeta entry for the purposes of
     * soft-deletion.
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return DataRecordMeta
     */
    public function updateDatarecordMeta($user, $datarecord, $properties, $delay_flush = false)
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
        $remove_old_entry = false;
        $new_datarecord_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the existing DatarecordMeta entry
            $remove_old_entry = true;

            $new_datarecord_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_datarecord_meta->setCreated(new \DateTime());
            $new_datarecord_meta->setUpdated(new \DateTime());
            $new_datarecord_meta->setCreatedBy($user);
            $new_datarecord_meta->setUpdatedBy($user);
        }
        else {
            $new_datarecord_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['publicDate']) )
            $new_datarecord_meta->setPublicDate( $properties['publicDate'] );

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
     * Copies the given DataTree entry into a new DataTree entry for the purposes of soft-deletion.
     *
     * @param ODRUser $user
     * @param DataTree $datatree
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return DataTreeMeta
     */
    public function updateDatatreeMeta($user, $datatree, $properties, $delay_flush = false)
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
        $remove_old_entry = false;
        $new_datatree_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old DatatreeMeta entry
            $remove_old_entry = true;

            $new_datatree_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_datatree_meta->setCreated(new \DateTime());
            $new_datatree_meta->setUpdated(new \DateTime());
            $new_datatree_meta->setCreatedBy($user);
            $new_datatree_meta->setUpdatedBy($user);
        }
        else {
            $new_datatree_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['multiple_allowed']) )
            $new_datatree_meta->setMultipleAllowed( $properties['multiple_allowed'] );
        if ( isset($properties['is_link']) )
            $new_datatree_meta->setIsLink( $properties['is_link'] );

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
     *
     * @return DataTypeMeta
     */
    public function updateDatatypeMeta($user, $datatype, $properties, $delay_flush = false)
    {
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
            // This entity can be set here since it's never null
            'renderPlugin' => $old_meta_entry->getRenderPlugin()->getId(),

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
        if ( $old_meta_entry->getExternalIdField() !== null )
            $existing_values['externalIdField'] = $old_meta_entry->getExternalIdField()->getId();
        if ( $old_meta_entry->getNameField() !== null )
            $existing_values['nameField'] = $old_meta_entry->getNameField()->getId();
        if ( $old_meta_entry->getSortField() !== null )
            $existing_values['sortField'] = $old_meta_entry->getSortField()->getId();
        if ( $old_meta_entry->getBackgroundImageField() !== null )
            $existing_values['backgroundImageField'] = $old_meta_entry->getBackgroundImageField()->getId();


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
        $remove_old_entry = false;
        $new_datatype_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the existing DatatypeMeta entry
            $remove_old_entry = true;

            $new_datatype_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_datatype_meta->setCreated(new \DateTime());
            $new_datatype_meta->setUpdated(new \DateTime());
            $new_datatype_meta->setCreatedBy($user);
            $new_datatype_meta->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_datatype_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['renderPlugin']) )
            $new_datatype_meta->setRenderPlugin( $this->em->getRepository('ODRAdminBundle:RenderPlugin')->find( $properties['renderPlugin'] ) );

        // isset() will return false when ('externalIdField' => null), so need to use
        //  array_key_exists() instead
        if ( array_key_exists('externalIdField', $properties) ) {
            if ( is_null($properties['externalIdField']) )
                $new_datatype_meta->setExternalIdField(null);
            else
                $new_datatype_meta->setExternalIdField( $this->em->getRepository('ODRAdminBundle:DataFields')->find($properties['externalIdField']) );
        }
        if ( array_key_exists('nameField', $properties) ) {
            if ( is_null($properties['nameField']) )
                $new_datatype_meta->setNameField(null);
            else
                $new_datatype_meta->setNameField( $this->em->getRepository('ODRAdminBundle:DataFields')->find($properties['nameField']) );
        }
        if ( array_key_exists('sortField', $properties) ) {
            if ( is_null($properties['sortField']) )
                $new_datatype_meta->setSortField(null);
            else
                $new_datatype_meta->setSortField( $this->em->getRepository('ODRAdminBundle:DataFields')->find($properties['sortField']) );
        }
        if ( array_key_exists('backgroundImageField', $properties) ) {
            if ( is_null($properties['backgroundImageField']) )
                $new_datatype_meta->setBackgroundImageField(null);
            else
                $new_datatype_meta->setBackgroundImageField( $this->em->getRepository('ODRAdminBundle:DataFields')->find($properties['backgroundImageField']) );
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

        if ( isset($properties['master_revision']) ) {
            $new_datatype_meta->setMasterRevision( $properties['master_revision'] );
        }
        // TODO - why does this not match the logic of self::updateDatafieldMeta()?
        if ($datatype->getIsMasterType()) {
            $grandparent_datatype = $datatype->getGrandparent();
            if ( $datatype->getId() !== $grandparent_datatype->getId() ) {
                // This is currently a child tempate datatype...need to update its grandparent's
                //  "master_revision" value
                $props = array(
                    'master_revision' => $grandparent_datatype->getMasterRevision() + 1
                );
                self::updateDatatypeMeta($user, $grandparent_datatype, $props, true);    // don't flush an update to this datatype immediately...

                // Don't need to worry about cache clearing for this logic path
            }
            else {
                // This is currently a top-level template datatype...check whether it's a linked
                //  descendant of other template datatypes...
                $linked_ancestors = $this->dti_service->getLinkedAncestors(array($datatype->getId()));
                if ( !empty($linked_ancestors) ) {
                    // ...because if it is, all linked ancestors of this datatype also need to have
                    //  their "master_revision" value updated
                    foreach ($linked_ancestors as $linked_ancestor_id) {
                        /** @var DataType $linked_ancestor */
                        $linked_ancestor = $this->em->getRepository('ODRAdminBundle:DataType')->find($linked_ancestor_id);
                        $props = array(
                            'master_revision' => $linked_ancestor->getMasterRevision() + 1
                        );
                        self::updateDatatypeMeta($user, $linked_ancestor, $props, true);    // don't flush updates to these datatypes immediately...

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
     * Modifies a meta entry for a given File entity by copying the old meta entry to a new meta entry,
     * updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * @param ODRUser $user
     * @param File $file
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return FileMeta
     */
    public function updateFileMeta($user, $file, $properties, $delay_flush = false)
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
        $remove_old_entry = false;
        $new_file_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old FileMeta entry
            $remove_old_entry = true;

            $new_file_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_file_meta->setCreated(new \DateTime());
            $new_file_meta->setUpdated(new \DateTime());
            $new_file_meta->setCreatedBy($user);
            $new_file_meta->setUpdatedBy($user);
        }
        else {
            $new_file_meta = $old_meta_entry;
        }

        // Set any new properties
        if ( isset($properties['description']) )
            $new_file_meta->setDescription( $properties['description'] );
        if ( isset($properties['original_filename']) )
            $new_file_meta->setOriginalFileName( $properties['original_filename'] );
        if ( isset($properties['external_id']) )
            $new_file_meta->setExternalId( $properties['external_id'] );
        if ( isset($properties['publicDate']) )
            $new_file_meta->setPublicDate( $properties['publicDate'] );

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
     *
     * @return GroupDatatypePermissions
     */
    public function updateGroupDatatypePermission($user, $permission, $properties, $delay_flush = false)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'can_view_datatype' => $permission->getCanViewDatatype(),
            'can_view_datarecord' => $permission->getCanViewDatarecord(),
            'can_add_datarecord' => $permission->getCanAddDatarecord(),
            'can_delete_datarecord' => $permission->getCanDeleteDatarecord(),
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
        $remove_old_entry = false;
        $new_permission = null;
        if ( self::createNewMetaEntry($user, $permission) ) {
            // Clone the existing GroupDatatypePermissions entry
            $remove_old_entry = true;

            $new_permission = clone $permission;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_permission->setCreated(new \DateTime());
            $new_permission->setUpdated(new \DateTime());
            $new_permission->setCreatedBy($user);
            $new_permission->setUpdatedBy($user);
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
        if ( isset( $properties['can_design_datatype']) )
            $new_permission->setCanDesignDatatype( $properties['can_design_datatype'] );
        if ( isset( $properties['is_datatype_admin']) )
            $new_permission->setIsDatatypeAdmin( $properties['is_datatype_admin'] );

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
     *
     * @return GroupDatafieldPermissions
     */
    public function updateGroupDatafieldPermission($user, $permission, $properties, $delay_flush = false)
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
        $remove_old_entry = false;
        $new_permission = null;
        if ( self::createNewMetaEntry($user, $permission) ) {
            // Clone the existing GroupDatafieldPermissions entry
            $remove_old_entry = true;

            $new_permission = clone $permission;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_permission->setCreated(new \DateTime());
            $new_permission->setUpdated(new \DateTime());
            $new_permission->setCreatedBy($user);
            $new_permission->setUpdatedBy($user);
        }
        else {
            $new_permission = $permission;
        }

        // Set any new properties
        if ( isset( $properties['can_view_datafield']) )
            $new_permission->setCanViewDatafield( $properties['can_view_datafield'] );
        if ( isset( $properties['can_edit_datafield']) )
            $new_permission->setCanEditDatafield( $properties['can_edit_datafield'] );

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
     * Copies the contents of the given GroupMeta entity into a new GroupMeta entity if something
     * was changed
     *
     * @param ODRUser $user
     * @param Group $group
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return GroupMeta
     */
    public function updateGroupMeta($user, $group, $properties, $delay_flush = false)
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
        $remove_old_entry = false;
        $new_group_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the existing GroupMeta entry
            $remove_old_entry = true;

            $new_group_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_group_meta->setCreated(new \DateTime());
            $new_group_meta->setUpdated(new \DateTime());
            $new_group_meta->setCreatedBy($user);
            $new_group_meta->setUpdatedBy($user);
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
     * Modifies a meta entry for a given Image entity by copying the old meta entry to a new meta entry,
     * updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * @param ODRUser $user
     * @param Image $image
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return ImageMeta
     */
    public function updateImageMeta($user, $image, $properties, $delay_flush = false)
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
        $remove_old_entry = false;
        $new_image_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old ImageMeta entry
            $remove_old_entry = true;

            $new_image_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_image_meta->setCreated(new \DateTime());
            $new_image_meta->setUpdated(new \DateTime());
            $new_image_meta->setCreatedBy($user);
            $new_image_meta->setUpdatedBy($user);
        }
        else {
            $new_image_meta = $old_meta_entry;
        }

        // Set any new properties
        if ( isset($properties['caption']) )
            $new_image_meta->setCaption( $properties['caption'] );
        if ( isset($properties['original_filename']) )
            $new_image_meta->setOriginalFileName( $properties['original_filename'] );
        if ( isset($properties['external_id']) )
            $new_image_meta->setExternalId( $properties['external_id'] );
        if ( isset($properties['publicDate']) )
            $new_image_meta->setPublicDate( $properties['publicDate'] );
        if ( isset($properties['display_order']) )
            $new_image_meta->setDisplayorder( $properties['display_order'] );

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
     *
     * @return RadioOptionsMeta
     */
    public function updateRadioOptionsMeta($user, $radio_option, $properties, $delay_flush = false)
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
        $remove_old_entry = false;
        $new_radio_option_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old RadioOptionsMeta entry
            $remove_old_entry = true;

            $new_radio_option_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_radio_option_meta->setCreated(new \DateTime());
            $new_radio_option_meta->setUpdated(new \DateTime());
            $new_radio_option_meta->setCreatedBy($user);
            $new_radio_option_meta->setUpdatedBy($user);
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


        // Master Template Data Fields must increment Master Revision on all change requests.
        if ($radio_option->getDataField()->getIsMasterField()) {
            $props = array(
                'master_revision' => $radio_option->getDataField()->getMasterRevision() + 1
            );
            self::updateDatafieldMeta($user, $radio_option->getDataField(), $props, $delay_flush);
        }

        // Return the new entry
        return $new_radio_option_meta;
    }


    /**
     * Modifies a given radio selection entity by copying the old value into a new storage entity,
     * then deleting the old entity.
     *
     * @param ODRUser $user
     * @param RadioSelection $entity
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return RadioSelection
     */
    public function updateRadioSelection($user, $entity, $properties, $delay_flush = false)
    {
        // No point making new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'selected' => $entity->getSelected()
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $entity;


        // Determine whether to create a new entry or modify the previous one
        $remove_old_entry = false;
        $new_entity = null;
        if ( self::createNewMetaEntry($user, $entity) ) {
            // Clone the old RadioSelection entry
            $remove_old_entry = true;

            $new_entity = clone $entity;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_entity->setCreated(new \DateTime());
            $new_entity->setUpdated(new \DateTime());
            $new_entity->setCreatedBy($user);
            $new_entity->setUpdatedBy($user);
        }
        else {
            $new_entity = $entity;
        }

        // Set any new properties
        if ( isset($properties['selected']) )
            $new_entity->setSelected( $properties['selected'] );

        $new_entity->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($entity);

        // Save the new meta entry
        $this->em->persist($new_entity);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($entity->getRadioOption());
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
     *
     * @return bool
     */
    public function updateRenderPluginMap($user, $render_plugin_map, $properties, $delay_flush = false)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'dataField' => $render_plugin_map->getDataField()->getId(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
//            return $render_plugin_map;
            return false;

        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_rpm = null;
        if ( self::createNewMetaEntry($user, $render_plugin_map) ) {
            // Clone the old RenderPluginMap entry
            $remove_old_entry = true;

            $new_rpm = clone $render_plugin_map;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_rpm->setCreated(new \DateTime());
            $new_rpm->setUpdated(new \DateTime());
            $new_rpm->setCreatedBy($user);
            $new_rpm->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_rpm = $render_plugin_map;
        }

        // Set any new properties
        if (isset($properties['dataField']))
            $new_rpm->setDataField( $this->em->getRepository('ODRAdminBundle:DataFields')->find($properties['dataField']) );

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
     * Copies the contents of the given RenderPluginOptions entity into a new RenderPluginOptions
     * entity if something was changed.  TODO - this returns a boolean unlike other stuff...refactor?
     *
     * @param ODRUser $user
     * @param RenderPluginOptions $render_plugin_option
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return bool
     */
    public function updateRenderPluginOption($user, $render_plugin_option, $properties, $delay_flush = false)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'optionValue' => $render_plugin_option->getOptionValue(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
//            return $render_plugin_option;
            return false;

        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_rpo = null;
        if ( self::createNewMetaEntry($user, $render_plugin_option) ) {
            // Clone the old RenderPluginOptions entry
            $remove_old_entry = true;

            $new_rpo = clone $render_plugin_option;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_rpo->setCreated(new \DateTime());
            $new_rpo->setUpdated(new \DateTime());
            $new_rpo->setCreatedBy($user);
            $new_rpo->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_rpo = $render_plugin_option;
        }


        // Set any new properties
        if (isset($properties['optionValue']))
            $new_rpo->setOptionValue( $properties['optionValue'] );

        $new_rpo->setUpdatedBy($user);
        $this->em->persist($new_rpo);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($render_plugin_option);

        // Save the new meta entry
        if ( !$delay_flush )
            $this->em->flush();


        // Return the new entry
//        return $new_rpo;
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
     *
     * @return ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    public function updateStorageEntity($user, $entity, $properties, $delay_flush = false)
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

        if (!$changes_made)
            return $entity;


        // If this is an IntegerValue entity, set the value back to an integer or null so it gets
        //  saved correctly
        if ($typeclass == 'IntegerValue') {
            if ($properties['value'] === '')
                $properties['value'] = null;
            else
                $properties['value'] = intval($properties['value']);
        }


        // Determine whether to create a new entry or modify the previous one
        $remove_old_entry = false;
        $new_entity = null;
        if ( self::createNewMetaEntry($user, $entity) ) {
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

            $new_entity->setCreatedBy($user);
        }
        else {
            $new_entity = $entity;
        }

        // Set any new properties...not checking isset() because it couldn't reach this point
        //  without being isset()...also,  isset( array[key] ) == false  when  array(key => null)
        $new_entity->setValue( $properties['value'] );

        $new_entity->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($entity);

        // Save the new meta entry
        if (!$delay_flush) {
            $this->em->persist($new_entity);
            $this->em->flush();
        }

        return $new_entity;
    }


    /**
     * Compares the given properties array against the given Tag's meta entry, and either updates
     * the existing TagMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user
     * @param Tags $tag
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return TagMeta
     */
    public function updateTagMeta($user, $tag, $properties, $delay_flush = false)
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
        $remove_old_entry = false;
        $new_tag_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old TagMeta entry
            $remove_old_entry = true;

            $new_tag_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_tag_meta->setCreated(new \DateTime());
            $new_tag_meta->setUpdated(new \DateTime());
            $new_tag_meta->setCreatedBy($user);
            $new_tag_meta->setUpdatedBy($user);
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


        // Master Template Data Fields must increment Master Revision on all change requests.
        if ($tag->getDataField()->getIsMasterField()) {
            $props = array(
                'master_revision' => $tag->getDataField()->getMasterRevision() + 1
            );
            self::updateDatafieldMeta($user, $tag->getDataField(), $props, $delay_flush);
        }

        // Return the new entry
        return $new_tag_meta;
    }


    /**
     * Modifies a given tag selection entity by copying the old value into a new storage entity,
     * then deleting the old entity.
     *
     * @param ODRUser $user
     * @param TagSelection $entity
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return TagSelection
     */
    public function updateTagSelection($user, $entity, $properties, $delay_flush = false)
    {
        // No point making new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'selected' => $entity->getSelected()
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $entity;


        // Determine whether to create a new entry or modify the previous one
        $remove_old_entry = false;
        $new_entity = null;
        if ( self::createNewMetaEntry($user, $entity) ) {
            // Clone the old TagSelection entry
            $remove_old_entry = true;

            $new_entity = clone $entity;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_entity->setCreated(new \DateTime());
            $new_entity->setUpdated(new \DateTime());
            $new_entity->setCreatedBy($user);
            $new_entity->setUpdatedBy($user);
        }
        else {
            $new_entity = $entity;
        }

        // Set any new properties
        if ( isset($properties['selected']) )
            $new_entity->setSelected( $properties['selected'] );

        $new_entity->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($entity);

        // Save the new meta entry
        $this->em->persist($new_entity);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($entity->getTag());
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
     *
     * @return ThemeDataField
     */
    public function updateThemeDatafield($user, $theme_datafield, $properties, $delay_flush = false)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            // This entity can be set here since it's never null
            'themeElement' => $theme_datafield->getThemeElement()->getId(),

            'displayOrder' => $theme_datafield->getDisplayOrder(),
            'cssWidthMed' => $theme_datafield->getCssWidthMed(),
            'cssWidthXL' => $theme_datafield->getCssWidthXL(),
            'hidden' => $theme_datafield->getHidden(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $theme_datafield;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_theme_datafield = null;
        if ( self::createNewMetaEntry($user, $theme_datafield) ) {
            // Clone the old ThemeDatafield entry
            $remove_old_entry = true;

            $new_theme_datafield = clone $theme_datafield;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_theme_datafield->setCreated(new \DateTime());
            $new_theme_datafield->setUpdated(new \DateTime());
            $new_theme_datafield->setCreatedBy($user);
            $new_theme_datafield->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_theme_datafield = $theme_datafield;
        }


        // Set any new properties
        if (isset($properties['themeElement']))
            $new_theme_datafield->setThemeElement( $this->em->getRepository('ODRAdminBundle:ThemeElement')->find($properties['themeElement']) );

        if (isset($properties['displayOrder']))
            $new_theme_datafield->setDisplayOrder( $properties['displayOrder'] );
        if (isset($properties['cssWidthMed']))
            $new_theme_datafield->setCssWidthMed( $properties['cssWidthMed'] );
        if (isset($properties['cssWidthXL']))
            $new_theme_datafield->setCssWidthXL( $properties['cssWidthXL'] );
        if (isset($properties['hidden']))
            $new_theme_datafield->setHidden( $properties['hidden'] );

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
     *
     * @return ThemeDataType
     */
    public function updateThemeDatatype($user, $theme_datatype, $properties, $delay_flush = false)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'display_type' => $theme_datatype->getDisplayType(),
            'hidden' => $theme_datatype->getHidden(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $theme_datatype;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_theme_datatype = null;
        if ( self::createNewMetaEntry($user, $theme_datatype) ) {
            // Clone the old ThemeDatatype entry
            $remove_old_entry = true;

            $new_theme_datatype = clone $theme_datatype;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_theme_datatype->setCreated(new \DateTime());
            $new_theme_datatype->setUpdated(new \DateTime());
            $new_theme_datatype->setCreatedBy($user);
            $new_theme_datatype->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_theme_datatype = $theme_datatype;
        }


        // Set any new properties
        if (isset($properties['display_type']))
            $new_theme_datatype->setDisplayType( $properties['display_type'] );
        if (isset($properties['hidden']))
            $new_theme_datatype->setHidden( $properties['hidden'] );

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
     *
     * @return ThemeElementMeta
     */
    public function updateThemeElementMeta($user, $theme_element, $properties, $delay_flush = false)
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
        $remove_old_entry = false;
        $theme_element_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old ThemeelementMeta entry
            $remove_old_entry = true;

            $theme_element_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $theme_element_meta->setCreated(new \DateTime());
            $theme_element_meta->setUpdated(new \DateTime());
            $theme_element_meta->setCreatedBy($user);
            $theme_element_meta->setUpdatedBy($user);
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
        if ( isset($properties['cssWidthMed']) )
            $theme_element_meta->setCssWidthMed( $properties['cssWidthMed'] );
        if ( isset($properties['cssWidthXL']) )
            $theme_element_meta->setCssWidthXL( $properties['cssWidthXL'] );

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
     *
     * @return ThemeMeta
     */
    public function updateThemeMeta($user, $theme, $properties, $delay_flush = false)
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
            'isDefault' => $old_meta_entry->getIsDefault(),
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
            'shared' => $old_meta_entry->getShared(),
            'sourceSyncVersion' => $old_meta_entry->getSourceSyncVersion(),
            'isTableTheme' => $old_meta_entry->getIsTableTheme(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_theme_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old ThemeMeta entry
            $remove_old_entry = true;

            $new_theme_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_theme_meta->setCreated(new \DateTime());
            $new_theme_meta->setUpdated(new \DateTime());
            $new_theme_meta->setCreatedBy($user);
            $new_theme_meta->setUpdatedBy($user);
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
        if ( isset($properties['isDefault']) )
            $new_theme_meta->setIsDefault( $properties['isDefault'] );
        if ( isset($properties['displayOrder']) )
            $new_theme_meta->setDisplayOrder( $properties['displayOrder'] );
        if ( isset($properties['shared']) )
            $new_theme_meta->setShared( $properties['shared'] );
        if ( isset($properties['sourceSyncVersion']) )
            $new_theme_meta->setSourceSyncVersion( $properties['sourceSyncVersion'] );

        if ( isset($properties['isTableTheme']) ) {
            $new_theme_meta->setIsTableTheme( $properties['isTableTheme'] );

            if ($theme->getThemeType() == 'search_results' && $new_theme_meta->getIsTableTheme()) {
                $theme->setThemeType('table');
                $this->em->persist($theme);
            }
            else if ($theme->getThemeType() == 'table' && !$new_theme_meta->getIsTableTheme()) {
                $theme->setThemeType('search_results');
                $this->em->persist($theme);
            }
        }

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
