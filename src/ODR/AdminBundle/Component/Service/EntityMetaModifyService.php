<?php

/**
 * Open Data Repository Data Publisher
 * Entity Meta Modify Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO -
 *
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\Theme;
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
     * @var Logger
     */
    private $logger;


    /**
     * EntityMetaModifyService constructor.
     *
     * @param EntityManager $entityManager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entityManager,
        Logger $logger
    ) {
        $this->em = $entityManager;
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


    // TODO - could just copy all the relevant stuff from ODRCustomController into here, but that doesn't solve the "needs to be kept up to date" problem...
    // TODO - refactor to use PropertyInfo and PropertyAccessor instead?


    /**
     * Compares the given properties array against the given Datafield's meta entry, and either
     * updates the existing DatafieldMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user The user requesting the modification of this meta entry.
     * @param DataFields $datafield The DataField entity of the meta entry being modified
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
            'children_per_row' => $old_meta_entry->getChildrenPerRow(),
            'radio_option_name_sort' => $old_meta_entry->getRadioOptionNameSort(),
            'radio_option_display_unselected' => $old_meta_entry->getRadioOptionDisplayUnselected(),
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
        if ( isset($properties['shorten_filename']) )
            $new_datafield_meta->setShortenFilename( $properties['shorten_filename'] );
        if ( isset($properties['children_per_row']) )
            $new_datafield_meta->setChildrenPerRow( $properties['children_per_row'] );
        if ( isset($properties['radio_option_name_sort']) )
            $new_datafield_meta->setRadioOptionNameSort( $properties['radio_option_name_sort'] );
        if ( isset($properties['radio_option_display_unselected']) )
            $new_datafield_meta->setRadioOptionDisplayUnselected( $properties['radio_option_display_unselected'] );
        if ( isset($properties['searchable']) )
            $new_datafield_meta->setSearchable( $properties['searchable'] );
        if ( isset($properties['publicDate']) )
            $new_datafield_meta->setPublicDate( $properties['publicDate'] );
        if ( isset($properties['master_revision']) ) {
            $new_datafield_meta->setMasterRevision( $properties['master_revision'] );
        }
        // Check in case master revision needs to be updated.
        else if($datafield->getIsMasterField() > 0) {
            // We always increment the Master Revision for master data fields
            $new_datafield_meta->setMasterRevision($new_datafield_meta->getMasterRevision() + 1);
        }

        if ( isset($properties['tracking_master_revision']) )
            $new_datafield_meta->setTrackingMasterRevision( $properties['tracking_master_revision'] );
        if ( isset($properties['master_published_revision']) )
            $new_datafield_meta->setMasterPublishedRevision( $properties['master_published_revision'] );

        $new_datafield_meta->setUpdatedBy($user);

        // Delete the old meta entry if necessary
        if ($remove_old_entry)
            $this->em->remove($old_meta_entry);

        //Save the new meta entry
        $this->em->persist($new_datafield_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($datafield);
        }


        // All metadata changes result in a new Data Field Master Published Revision.  Revision
        // changes are picked up by derivative data types when the parent data type revision is changed.
        if ($datafield->getIsMasterField() > 0) {
            $datatype = $datafield->getDataType();
            $properties['master_revision'] = $datatype->getDataTypeMeta()->getMasterRevision() + 1;
            self::updateDatatypeMeta($user, $datatype, $properties, $delay_flush);
        }

        // Return the new entry
        return $new_datafield_meta;
    }


    /**
     * Compares the given properties array against the given Datatype's meta entry, and either updates
     * the existing DatatypeMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user The user requesting the modification of this meta entry.
     * @param DataType $datatype The Datatype entity of the meta entry being modified
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

        if ( isset($properties['master_revision']) )
            $new_datatype_meta->setMasterRevision( $properties['master_revision'] );
        if ( isset($properties['master_published_revision']) )
            $new_datatype_meta->setMasterPublishedRevision( $properties['master_published_revision'] );
        if ( isset($properties['master_published_revision']) )
            $new_datatype_meta->setTrackingMasterRevision( $properties['tracking_master_revision'] );

        $new_datatype_meta->setUpdatedBy($user);

        if ($datatype->getIsMasterType()) {
            // Update grandparent master revision
            if ($datatype->getGrandparent()->getId() != $datatype->getId()) {
                $grandparent_datatype = $datatype->getGrandparent();

                $gp_properties['master_revision'] = $grandparent_datatype->getMasterRevision() + 1;
                self::updateDatatypeMeta($user, $grandparent_datatype, $gp_properties, $delay_flush);
            }
        }

        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($old_meta_entry);

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
     * Compares the given properties array against the given Theme's meta entry, and either updates
     * the existing ThemeMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user The user requesting the modification of this meta entry.
     * @param Theme $theme The Theme entity being modified
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


        // Delete the old meta entry if needed
        if ($remove_old_entry)
            $this->em->remove($old_meta_entry);

        // Save the new meta entry
        $this->em->persist($new_theme_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($theme);
        }

        // Return the new entry
        return $new_theme_meta;
    }


    /**
     * Compares the given properties array against the given RadioOption's meta entry, and either
     * updates the existing RadioOptionMeta entry or clones a new one if needed.
     *
     * @param ODRUser $user The user requesting the modification of this meta entry.
     * @param RadioOptions $radio_option The RadioOption entity of the meta entry being modified
     * @param array $properties
     * @param bool $delay_flush
     *
     * @return RadioOptionsMeta
     */
    public function updateRadioOptionsMeta($user, $radio_option, $properties, $delay_flush = false)
    {
        // Load the old meta entry
        /** @var RadioOptionsMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:RadioOptionsMeta')->findOneBy( array('radioOption' => $radio_option->getId()) );

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


        // Delete the old entry if needed
        if ($remove_old_entry)
            $this->em->remove($old_meta_entry);

        // Save the new meta entry
        $this->em->persist($new_radio_option_meta);
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($radio_option);
        }

        // Master Template Data Fields must increment Master Revision
        // on all change requests.
        if ($radio_option->getDataField()->getIsMasterField()) {
            $datafield = $radio_option->getDataField();
            $dfm_properties['master_revision'] = $datafield->getMasterRevision() + 1;
            self::updateDatafieldMeta($user, $datafield, $dfm_properties, $delay_flush);
        }

        // Return the new entry
        return $new_radio_option_meta;
    }
}
