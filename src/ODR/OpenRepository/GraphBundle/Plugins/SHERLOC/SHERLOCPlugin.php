<?php

/**
 * Open Data Repository Data Publisher
 * SHERLOC Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This plugin currently does the backend work so SHERLOC can have an IMA-like chemistry elements search.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\SHERLOC;

// Entities
use ODR\AdminBundle\Entity\Boolean as ODRBoolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\MassEditTriggerEvent;
use ODR\AdminBundle\Component\Event\PostUpdateEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldReloadOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class SHERLOCPlugin implements DatatypePluginInterface, DatafieldDerivationInterface, DatafieldReloadOverrideInterface, MassEditTriggerEventInterface
{

    /**
     * SHERLOCPlugin constructor.
     *
     * @param EntityManager $em
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param EntityCreationService $entity_create_service
     * @param EntityMetaModifyService $entity_modify_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param \Twig\Environment $templating
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityManager $em,
        private readonly DatabaseInfoService $database_info_service,
        private readonly DatarecordInfoService $datarecord_info_service,
        private readonly EntityCreationService $entity_create_service,
        private readonly EntityMetaModifyService $entity_modify_service,
        private readonly EventDispatcherInterface $event_dispatcher,
        private readonly \Twig\Environment $templating,
        private readonly LoggerInterface $logger
    ) {
    }


    /**
     * @inheritDoc
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        // The render plugin does several things...
        // 1) Automatically derives the values for the chemical element fields (in Edit)
        // 2) Warns of instances where derivation has failed (in Display/Edit)
        // 3) Provides the ability to force derivations without changing the values (in MassEdit)
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            if ( $context === 'display'
                || $context === 'edit'
            ) {
                // ...so execute the render plugin when called from these contexts
                return true;
            }
        }

        return false;
    }


    /**
     * @inheritDoc
     */
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = [], $datatype_permissions = [], $datafield_permissions = [], $token_list = [])
    {
        try {
            // ----------------------------------------
            // If no rendering context set, then return nothing so ODR's default templating will
            //  do its job
            if ( !isset($rendering_options['context']) )
                return '';


            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Retrieve mapping between datafields and render plugin fields
            $plugin_fields = [];
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null) {
                    // If the datafield doesn't exist in the datatype_array, then either the datafield
                    //  is non-public and the user doesn't have permissions to view it (most likely),
                    //  or the plugin somehow isn't configured correctly

                    // The SHERLOC Plugin doesn't require the existence of any of its fields to execute
                    //  properly...Display and Edit modes are merely warning when certain fields are
                    //  blank when they shouldn't be.

                    // I can't fathom why you would want to have non-public fields in this datatype...
                    //  but unlike references or cell parameters, it's not objectively wrong to do so.

                    if ( $is_datatype_admin )
                        // If a datatype admin is seeing this, then they need to fix something in
                        //  the plugin's config
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id.'...check plugin config.');
                }

                // Need to tweak display parameters for several of the fields...
                $plugin_fields[$rpf_df_id] = $rpf_df;
                $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;
            }


            // ----------------------------------------
            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            foreach ($theme_array as $t_id => $t)
                $initial_theme_id = $t_id;

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = [];
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;

            $theme = $theme_array[$initial_theme_id];


            // ----------------------------------------
            // Need to check the derived fields so that any problems with them can get displayed
            //  to the user
            $relevant_fields = self::getRelevantFields($datatype, $datarecord);

            $problem_fields = [];
            if ( $rendering_options['context'] === 'display' || $rendering_options['context'] === 'edit' ) {
                $derivation_problems = self::findDerivationProblems($relevant_fields);

                // Can't use array_merge() since that destroys the existing keys
                foreach ($derivation_problems as $df_id => $problem)
                    $problem_fields[$df_id] = $problem;
            }


            // Otherwise, output depends on which context the plugin is being executed from
            $output = '';
            if ( $rendering_options['context'] === 'display' ) {

                $record_display_view = 'single';
                if ( isset($rendering_options['record_display_view']) )
                    $record_display_view = $rendering_options['record_display_view'];

                // TODO - should this also try to take over display mode of Chemistry Plugin?
                $output = $this->templating->render(
                    '@ODROpenRepositoryGraph/SHERLOC/SHERLOC/sherloc_display_fieldarea.html.twig',
                    [
                        'datatype_array' => [$initial_datatype_id => $datatype],
                        'datarecord' => $datarecord,
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'record_display_view' => $record_display_view,
                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'plugin_fields' => $plugin_fields,
                        'problem_fields' => $problem_fields,
                    ]
                );
            }
            else if ( $rendering_options['context'] === 'edit' ) {
                // Need to be able to pass this option along if doing edit mode
                $edit_shows_all_fields = $rendering_options['edit_shows_all_fields'];
                $edit_behavior = $rendering_options['edit_behavior'];

                $output = $this->templating->render(
                    '@ODROpenRepositoryGraph/SHERLOC/SHERLOC/sherloc_edit_fieldarea.html.twig',
                    [
                        'datatype_array' => [$initial_datatype_id => $datatype],
                        'datarecord_array' => [$datarecord['id'] => $datarecord],
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,
                        'edit_shows_all_fields' => $edit_shows_all_fields,
                        'edit_behavior' => $edit_behavior,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,

                        'plugin_fields' => $plugin_fields,
                        'problem_fields' => $problem_fields,
                    ]
                );
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Due to needing to detect two types of problems with the values of the fields in this plugin,
     * it's easier to collect the relevant data separately.
     *
     * @param array $datatype
     * @param array $datarecord
     *
     * @return array
     */
    private function getRelevantFields($datatype, $datarecord)
    {
        $relevant_datafields = [
            'Chemistry Formula' => [],
            'Chemistry Elements' => [],
        ];

        // Locate the relevant render plugin instance
        $rpm_entries = null;
        foreach ($datatype['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.sherloc.sherloc' ) {
                $rpm_entries = $rpi['renderPluginMap'];
                break;
            }
        }

        // Determine the datafield ids of the relevant rpf entries
        foreach ($relevant_datafields as $rpf_name => $tmp) {
            // If any of the rpf entries are missing, that's a problem...
            if ( !isset($rpm_entries[$rpf_name]) )
                throw new ODRException('The renderPluginField "'.$rpf_name.'" is not mapped to the current database');

            // Otherwise, store the datafield id...
            $df_id = $rpm_entries[$rpf_name]['id'];
            $relevant_datafields[$rpf_name]['id'] = $df_id;

            // ...and locate the datafield's value from the datarecord array if it exists
            if ( !isset($datarecord['dataRecordFields'][$df_id]) ) {
                $relevant_datafields[$rpf_name]['value'] = '';
            }
            else {
                $drf = $datarecord['dataRecordFields'][$df_id];

                // Don't know the typeclass, so brute force it
                unset( $drf['id'] );
                unset( $drf['created'] );
                unset( $drf['file'] );
                unset( $drf['image'] );
                unset( $drf['dataField'] );

                // Should only be one entry left at this point
                foreach ($drf as $typeclass => $data) {
                    $relevant_datafields[$rpf_name]['typeclass'] = $typeclass;

                    if ( !isset($data[0]) || !isset($data[0]['value']) )
                        $relevant_datafields[$rpf_name]['value'] = '';
                    else
                        $relevant_datafields[$rpf_name]['value'] = $data[0]['value'];
                }
            }
        }

        return $relevant_datafields;
    }


    /**
     * Need to check for and warn if a derived field is blank when the source field is not.
     *
     * @param array $relevant_datafields {@link self::getRelevantFields()}
     *
     * @return array
     */
    private function findDerivationProblems($relevant_datafields)
    {
        // Only interested in the contents of datafields mapped to these rpf entries
        $derivations = [
            'Chemistry Formula' => 'Chemistry Elements',
        ];

        $problems = [];
        foreach ($derivations as $source_rpf_name => $dest_rpf_name) {
            if ( $relevant_datafields[$source_rpf_name]['value'] !== ''
                && $relevant_datafields[$dest_rpf_name]['value'] === ''
            ) {
                $dest_df_id = $relevant_datafields[$dest_rpf_name]['id'];
                $problems[$dest_df_id] = 'There seems to be a problem with the contents of the "'.$source_rpf_name.'" field.';
            }
        }

        // Return a list of any problems found
        return $problems;
    }


    /**
     * Determines whether the user changed the fields on the left below...and if so, then updates
     * the corresponding field to the right:
     *  - "Chemistry Formula" => "Chemical Elements"
     *
     * @param PostUpdateEvent $event
     *
     * @throws \Exception
     */
    public function onPostUpdate(PostUpdateEvent $event)
    {
        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $datarecord = null;
        $datafield = null;
        $datatype = null;
        $destination_entity = null;
        $user = null;

        try {
            // Get entities related to the file
            $source_entity = $event->getStorageEntity();
            $datarecord = $source_entity->getDataRecord();
            $datafield = $source_entity->getDataField();
            $typeclass = $datafield->getFieldType()->getTypeClass();
            $datatype = $datafield->getDataType();
            $user = $event->getUser();

            // Only care about a change to specific fields of a datatype using this render plugin...
            $rpf_name = self::isEventRelevant($datafield);
            if ( !is_null($rpf_name) ) {
                // ----------------------------------------
                // One of the relevant datafields got changed
                $source_value = $source_entity->getValue();
                if ($typeclass === 'DatetimeValue')
                    $source_value = $source_value->format('Y-m-d H:i:s');
                $this->logger->debug('Attempting to derive a value from dt '.$datatype->getId().', dr '.$datarecord->getId().', df '.$datafield->getId().' ('.$rpf_name.'): "'.$source_value.'"...', [self::class, 'onPostUpdate()']);

                // Store the renderpluginfield name that will be modified
                $dest_rpf_name = null;
                if ($rpf_name === 'Chemistry Formula')
                    $dest_rpf_name = 'Chemistry Elements';

                // Locate the destination entity for the relevant source datafield
                $destination_entity = self::findDestinationEntity($user, $datatype, $datarecord, $dest_rpf_name);

                // Derive the new value for the destination entity
                $derived_value = null;
                if ($rpf_name === 'Chemistry Formula')
                    $derived_value = self::convertChemistryFormula($source_value);

                // ...which is saved in the storage entity for the datafield
                $this->entity_modify_service->updateStorageEntity(
                    $user,
                    $destination_entity,
                    ['value' => $derived_value],
                    false,    // no sense trying to delay flush
                    false    // don't fire PostUpdate event...nothing depends on these fields
                );
                $this->logger->debug(' -- updating datafield '.$destination_entity->getDataField()->getId().' ('.$dest_rpf_name.'), '.$typeclass.' '.$destination_entity->getId().' with the value "'.$derived_value.'"...', [self::class, 'onPostUpdate()']);

                // This only works because the datafields getting updated aren't files/images or
                //  radio/tag fields
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), [self::class, 'onPostUpdate()', 'user '.$user->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()]);

            if ( !is_null($destination_entity) ) {
                // If an error was thrown, attempt to ensure any derived fields are blank
                self::saveOnError($user, $destination_entity);
            }

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( !is_null($destination_entity) ) {
                $this->logger->debug('All changes saved', [self::class, 'onPostUpdate()', 'dt '.$datatype->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()]);
                self::clearCacheEntries($datarecord, $user, $destination_entity);

                // Provide a reference to the entity that got changed
                $event->setDerivedEntity($destination_entity);
                // At the moment, this is effectively only available to the API callers, since the
                //  event kind of vanishes after getting called by the EntityMetaModifyService or
                //  the EntityCreationService...
            }
        }
    }


    /**
     * Determines whether the user changed the fields on the left below via MassEdit...and if so,
     * then updates the corresponding field to the right:
     *  - "Chemistry Formula" => "Chemistry Elements"
     *
     * @param MassEditTriggerEvent $event
     *
     * @throws \Exception
     */
    public function onMassEditTrigger(MassEditTriggerEvent $event)
    {
        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $datarecord = null;
        $datafield = null;
        $datatype = null;
        $destination_entity = null;
        $user = null;

        try {
            // Get entities related to the file
            $drf = $event->getDataRecordFields();
            $datarecord = $drf->getDataRecord();
            $datafield = $drf->getDataField();
            $typeclass = $datafield->getFieldType()->getTypeClass();
            $datatype = $datafield->getDataType();
            $user = $event->getUser();

            // Only care about a change to specific fields of a datatype using this render plugin...
            $rpf_name = self::isEventRelevant($datafield);
            if ( !is_null($rpf_name) ) {
                // ----------------------------------------
                // One of the relevant datafields got changed
                $source_entity = null;
                if ( $typeclass === 'ShortVarchar' )
                    $source_entity = $drf->getShortVarchar();
                else if ( $typeclass === 'MediumVarchar' )
                    $source_entity = $drf->getMediumVarchar();
                else if ( $typeclass === 'LongVarchar' )
                    $source_entity = $drf->getLongVarchar();
                else if ( $typeclass === 'LongText' )
                    $source_entity = $drf->getLongText();
                else if ( $typeclass === 'IntegerValue' )
                    $source_entity = $drf->getIntegerValue();
                else if ( $typeclass === 'DecimalValue' )
                    $source_entity = $drf->getDecimalValue();
                else if ( $typeclass === 'DatetimeValue' )
                    $source_entity = $drf->getDatetimeValue();

                // Only continue when stuff isn't null
                if ( !is_null($source_entity) ) {
                    $source_value = $source_entity->getValue();
                    if ($typeclass === 'DatetimeValue')
                        $source_value = $source_value->format('Y-m-d H:i:s');
                    $this->logger->debug('Attempting to derive a value from dt '.$datatype->getId().', dr '.$datarecord->getId().', df '.$datafield->getId().' ('.$rpf_name.'): "'.$source_value.'"...', [self::class, 'onMassEditTrigger()']);

                    // Store the renderpluginfield name that will be modified
                    $dest_rpf_name = null;
                    if ($rpf_name === 'Chemistry Formula')
                        $dest_rpf_name = 'Chemistry Elements';

                    // Locate the destination entity for the relevant source datafield
                    $destination_entity = self::findDestinationEntity($user, $datatype, $datarecord, $dest_rpf_name);

                    // Derive the new value for the destination entity
                    $derived_value = null;
                    if ($rpf_name === 'Chemistry Formula')
                        $derived_value = self::convertChemistryFormula($source_value);

                    // ...which is saved in the storage entity for the datafield
                    $this->entity_modify_service->updateStorageEntity(
                        $user,
                        $destination_entity,
                        ['value' => $derived_value],
                        false,    // no sense trying to delay flush
                        false    // don't fire PostUpdate event...nothing depends on these fields
                    );
                    $this->logger->debug(' -- updating datafield '.$destination_entity->getDataField()->getId().' ('.$dest_rpf_name.'), '.$typeclass.' '.$destination_entity->getId().' with the value "'.$derived_value.'"...', [self::class, 'onMassEditTrigger()']);

                    // This only works because the datafields getting updated aren't files/images or
                    //  radio/tag fields
                }
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), [self::class, 'onMassEditTrigger()', 'user '.$user->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()]);

            if ( !is_null($destination_entity) ) {
                // If an error was thrown, attempt to ensure any derived fields are blank
                self::saveOnError($user, $destination_entity);
            }

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( !is_null($destination_entity) ) {
                $this->logger->debug('All changes saved', [self::class, 'onMassEditTrigger()', 'dt '.$datatype->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()]);
                self::clearCacheEntries($datarecord, $user, $destination_entity);
            }
        }
    }


    /**
     * Returns the given datafield's renderpluginfield name if it should respond to the PostUpdate
     * or MassEditTrigger events, or null if it shouldn't.
     *
     * @param DataFields $datafield
     *
     * @return null|string
     */
    private function isEventRelevant($datafield)
    {
        // Going to use the cached datatype array to locate the correct datafield...
        $datatype = $datafield->getDataType();
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links
        if ( !isset($dt_array[$datatype->getId()]['renderPluginInstances']) )
            return null;

        // Only interested in changes made to the datafields mapped to these rpf entries
        $relevant_datafields = [
            'Chemistry Formula' => 'Chemistry Elements',
        ];

        foreach ($dt_array[$datatype->getId()]['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.sherloc.sherloc' ) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                    if ( isset($relevant_datafields[$rpf_name]) && $rpf['id'] === $datafield->getId() ) {
                        // The datafield that triggered the event is one of the relevant fields
                        //  ...ensure the destination field exists while we're here
                        $dest_rpf_name = $relevant_datafields[$rpf_name];
                        if ( !isset($rpi['renderPluginMap'][$dest_rpf_name]) ) {
                            // The destination field doesn't exist for some reason
                            return null;
                        }
                        else {
                            // The destination field exists, so the rest of the plugin will work
                            return $rpf_name;
                        }
                    }
                }
            }
        }

        // ...otherwise, this is not a relevant field, or the fields aren't mapped for some reason
        return null;
    }


    /**
     * Returns the storage entity that the PostUpdate or MassEditTrigger events will overwrite.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param DataRecord $datarecord
     * @param string $destination_rpf_name
     *
     * @return ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    private function findDestinationEntity($user, $datatype, $datarecord, $destination_rpf_name)
    {
        // Going to use the cached datatype array to locate the correct datafield...
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links
        foreach ($dt_array[$datatype->getId()]['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.sherloc.sherloc' ) {
                $df_id = $rpi['renderPluginMap'][$destination_rpf_name]['id'];
                break;
            }
        }

        // Hydrate the destination datafield...it's guaranteed to exist
        /** @var DataFields $datafield */
        $datafield = $this->em->getRepository('ODR\AdminBundle\Entity\DataFields')->find($df_id);

        // Return the storage entity for this datarecord/datafield pair
        return $this->entity_create_service->createStorageEntity($user, $datarecord, $datafield);
    }


    /**
     * Extracts the chemical elements from an IMA-like Chemistry Formula.
     *
     * For example, the IMA formula for Gypsum is Ca(SO_4_)·2H_2_O
     * The individual elements in this formula are "Ca", "S", "O", and "H"
     *
     * @param string $chemistry_formula
     *
     * @return string
     */
    private function convertChemistryFormula($chemistry_formula)
    {
        // Locate the elements in the formula
        $pattern = '/(REE|[A-Z][a-z]?)/';    // Attempt to locate 'REE' first, then fallback to a capital letter followed by an optional lowercase letter
        $matches = [];
        preg_match_all($pattern, $chemistry_formula, $matches);

        // Create a unique list of tokens from the array of elements
        $elements = [];
        foreach ($matches[1] as $num => $elem)
            $elements[$elem] = 1;
        $elements = array_keys($elements);

        $chemistry_elements = implode(" ", $elements);
        return $chemistry_elements;
    }


    /**
     * If some sort of error/exception was thrown, then attempt to blank out the given derived
     * field...this doesn't fix the underlying problem, but at least the renderplugin can recognize
     * and display that something is wrong with the source field.
     *
     * @param ODRUser $user
     * @param ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $destination_storage_entity
     */
    private function saveOnError($user, $destination_storage_entity)
    {
        $dr = $destination_storage_entity->getDataRecord();
        $df = $destination_storage_entity->getDataField();

        try {
            if ( !is_null($destination_storage_entity) ) {
                $this->entity_modify_service->updateStorageEntity(
                    $user,
                    $destination_storage_entity,
                    ['value' => ''],
                    false,    // no point delaying flush
                    false    // don't fire PostUpdate event...nothing depends on these fields
                );
                $this->logger->debug('-- -- updating dr '.$dr->getId().', df '.$df->getId().' to have the value ""...', [self::class, 'saveOnError()']);
            }
        }
        catch (\Exception $e) {
            // Some other error...no way to recover from it
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), [self::class, 'saveOnError()', 'user '.$user->getId(), 'dr '.$dr->getId(), 'df '.$df->getId()]);
        }
    }


    /**
     * Wipes or updates relevant cache entries once everything is completed.
     *
     * @param DataRecord $datarecord
     * @param ODRUser $user
     * @param ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $destination_storage_entity
     */
    private function clearCacheEntries($datarecord, $user, $destination_storage_entity)
    {
        // Fire off an event notifying that the modification of the datafield is done
        try {
            $event = new DatafieldModifiedEvent($destination_storage_entity->getDataField(), $user);
            $this->event_dispatcher->dispatch($event, DatafieldModifiedEvent::NAME);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }

        // The datarecord needs to be marked as updated
        try {
            $event = new DatarecordModifiedEvent($datarecord, $user);
            $this->event_dispatcher->dispatch($event, DatarecordModifiedEvent::NAME);
        }
        catch (\Exception) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }
    }


    /**
     * @inheritDoc
     */
    public function getOverrideParameters($rendering_context, $render_plugin_instance, $datafield, $datarecord, $theme, $user, $is_datatype_admin)
    {
        // Only override when called from the 'display' or 'edit' contexts...though at the moment,
        //  ODR only calls this via the 'edit' context
        if ( $rendering_context !== 'edit' && $rendering_context !== 'display' )
            return [];

        // Sanity checks
        if ( $render_plugin_instance->getRenderPlugin()->getPluginClassName() !== 'odr_plugins.sherloc.sherloc' )
            return [];
        $datatype = $datafield->getDataType();
        if ( $datatype->getId() !== $datarecord->getDataType()->getId() )
            return [];
        if ( $render_plugin_instance->getDataType()->getId() !== $datatype->getId() )
            return [];


        // Want the derived fields to complain if they're blank, but their source field isn't
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId());    // need links for Reference A/B
        $dr_array = $this->datarecord_info_service->getDatarecordArray($datarecord->getGrandparent()->getId());

        // Locate any problems with the values
        $relevant_fields = self::getRelevantFields($dt_array[$datatype->getId()], $dr_array[$datarecord->getId()]);

        $relevant_rpf = null;
        foreach ($relevant_fields as $rpf_name => $data) {
            if ( $data['id'] === $datafield->getId() ) {
                $relevant_rpf = $rpf_name;
                break;
            }
        }

        // Only need to check for derivation problems when reloading in edit mode
        if ( $rendering_context === 'edit' ) {
            $derivation_problems = self::findDerivationProblems($relevant_fields);
            if ( isset($derivation_problems[$datafield->getId()]) ) {
                // The derived field does not have a value, but the source field does...render the
                //  plugin's template instead of the default
                return [
                    'token_list' => [],    // so ODRRenderService generates CSRF tokens
                    'template_name' => '@ODROpenRepositoryGraph/SHERLOC/SHERLOC/sherloc_edit_elements_datafield_reload.html.twig',
                    'problem_fields' => $derivation_problems,
                ];
            }
        }

        // Otherwise, don't want to override the default reloading for this field
        return [];
    }


    /**
     * @inheritDoc
     */
    public function getDerivationMap($render_plugin_instance)
    {
        // Don't execute on instances of other render plugins
        if ( $render_plugin_instance['renderPlugin']['pluginClassName'] !== 'odr_plugins.sherloc.sherloc' )
            return [];
        $render_plugin_map = $render_plugin_instance['renderPluginMap'];

        // The SHERLOC plugin has one derived field...
        //  - "Chemistry Elements" is derived from "Chemistry Formula"
        $chemistry_elements_df_id = $render_plugin_map['Chemistry Elements']['id'];
        $chemistry_formula_df_id = $render_plugin_map['Chemistry Formula']['id'];

        // Since a datafield could be derived from multiple datafields, the source datafields need
        //  to be in an array (even though that's not the case for the SHERLOC Plugin)
        return [
            $chemistry_elements_df_id => [$chemistry_formula_df_id],
        ];
    }


    /**
     * @inheritDoc
     */
    public function getMassEditOverrideFields($render_plugin_instance)
    {
        if ( !isset($render_plugin_instance['renderPluginMap']) )
            throw new ODRException('Invalid plugin config');

        // Only interested in overriding datafields mapped to these rpf entries
        $relevant_datafields = [
            'Chemistry Formula' => 1,  // source field for 'Chemistry Elements'
        ];

        $ret = [];
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            if ( isset($relevant_datafields[$rpf_name]) )
                $ret[] = $rpf['id'];
        }

        return $ret;
    }


    /**
     * @inheritDoc
     */
    public function getMassEditTriggerFields($render_plugin_instance)
    {
        // Only interested in overriding datafields mapped to these rpf entries
        $relevant_datafields = [
            'Chemistry Formula' => 1,  // source field for 'Chemistry Elements'
        ];

        $trigger_fields = [];
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            if ( isset($relevant_datafields[$rpf_name]) ) {
                // The relevant fields should only have the MassEditTrigger event activated when the
                //  user didn't also specify a new value
                $trigger_fields[ $rpf['id'] ] = false;
            }
        }

        return $trigger_fields;
    }
}
