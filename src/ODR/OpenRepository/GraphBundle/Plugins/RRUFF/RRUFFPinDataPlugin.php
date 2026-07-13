<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Pin Data Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This is a quick/dirty implementation of converting the raw data used to define orientation in
 * RRUFF into a slightly better format.  Additionally, this orientation is saved in a field so it
 * can be used elsewhere.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Exception\ODRException;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\MassEditTriggerEvent;
use ODR\AdminBundle\Component\Event\RadioPostUpdateEvent;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldReloadOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class RRUFFPinDataPlugin implements DatatypePluginInterface, DatafieldDerivationInterface, DatafieldReloadOverrideInterface, MassEditTriggerEventInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var EntityCreationService
     */
    private $entity_create_service;

    /**
     * @var EntityMetaModifyService
     */
    private $entity_modify_service;

    /**
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * RRUFF Pin Data Plugin constructor
     *
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param EntityCreationService $entity_create_service
     * @param EntityMetaModifyService $entity_modify_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        EntityCreationService $entity_create_service,
        EntityMetaModifyService $entity_modify_service,
        EventDispatcherInterface $event_dispatcher,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->database_info_service = $database_info_service;
        $this->entity_create_service = $entity_create_service;
        $this->entity_modify_service = $entity_modify_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        // This plugin needs to execute in the Display context so the bold/italic tags get rendered
        //  properly, and the Edit mode so the related field reloads
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            if ( $context === 'display' || $context === 'edit' )
                return true;
        }

        return false;
    }


    /**
     * Executes the RRUFF Pin Data Plugin on the provided datarecord
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param array $token_list
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {
        try {
            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
//            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            foreach ($theme_array as $t_id => $t)
                $initial_theme_id = $t_id;

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;

            // ----------------------------------------
            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            $plugin_fields = array();
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null) {
                    // If the datafield doesn't exist in the datatype_array, then either the datafield
                    //  is non-public and the user doesn't have permissions to view it (most likely),
                    //  or the plugin somehow isn't configured correctly

                    // The plugin can't continue executing in either case...
                    if ( !$is_datatype_admin )
                        // ...regardless of what actually caused the issue, the plugin shouldn't execute
                        return '';
                    else
                        // ...but if a datatype admin is seeing this, then they probably should fix it
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id.'...check plugin config.');
                }

                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];

                if ( !isset($datarecord['dataRecordFields'][$rpf_df_id]) ) {
                    // If a drf entry doesn't exist, then use the empty string instead
                    $datafield_mapping[$rpf_name] = '';
                }
                else {
                    // Otherwise, attempt to extract the value from the drf entry
                    $drf = $datarecord['dataRecordFields'][$rpf_df_id];
                    switch ($typeclass) {
                        case 'ShortVarchar':
                        case 'LongVarchar':
                            $data = $drf[ lcfirst($typeclass) ];
                            if ( !isset($data[0]) || !isset($data[0]['value']) )
                                $datafield_mapping[$rpf_name] = '';
                            else
                                $datafield_mapping[$rpf_name] = $data[0]['value'];
                            break;

                        case 'Radio':
                            foreach ($drf['radioSelection'] as $ro_id => $rs) {
                                if ( $rs['selected'] === 1 )
                                    $datafield_mapping[$rpf_name] = $rs['radioOption']['optionName'];
                            }
                            break;

                        default:
                            throw new ODRException('Unexpected fieldtype');
                    }
                }

                $plugin_fields[$rpf_df_id] = $rpf_df;
                $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;
            }


            // ----------------------------------------
            // Detect if the dropdowns have values and the orientation field doesn't
            $problem_fields = array();
            if ( $rendering_options['context'] === 'display' || $rendering_options['context'] === 'edit' ) {
                $has_vectors = $has_orientation = $has_direction = $has_polarization = false;
                foreach ($datafield_mapping as $rpf_name => $value) {
                    if ( $rpf_name === 'Pin Label' )
                        continue;
                    else if ( $rpf_name === 'Orientation' && $value !== '' )
                        $has_orientation = true;
                    else if ( $rpf_name === 'Laser Direction' && $value !== '' )
                        $has_direction = true;
                    else if ( $rpf_name === 'Laser Polarization' && $value !== '' )
                        $has_polarization = true;
                    else if ( $value !== '' )
                        $has_vectors = true;
                }

                if ( $has_vectors ) {
                    foreach ($plugin_fields as $df_id => $df) {
                        if ( ( !$has_orientation && $df['rpf_name'] === 'Orientation' )
                            || ( !$has_direction && $df['rpf_name'] === 'Laser Direction' )
                            || ( !$has_polarization && $df['rpf_name'] === 'Laser Polarization' )
                        ) {
                            $problem_fields[$df_id] = 'There seems to be a problem with the contents of the "'.$df['rpf_name'].'" field.';
                        }

                    }
                }
            }


            // ----------------------------------------
            $record_display_view = 'single';
            if ( isset($rendering_options['record_display_view']) )
                $record_display_view = $rendering_options['record_display_view'];

            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFPinData/pindata_display_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
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
                    )
                );
            }
            else if ( $rendering_options['context'] === 'edit' ) {
                // Need to be able to pass this option along if doing edit mode
                $edit_shows_all_fields = $rendering_options['edit_shows_all_fields'];
                $edit_behavior = $rendering_options['edit_behavior'];

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFPinData/pindata_edit_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord_array' => array($datarecord['id'] => $datarecord),
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
                    )
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
     * Returns the given datafield's renderpluginfield name if it should respond to the RadioPostUpdate
     * or MassEditTrigger events, or null if it shouldn't.
     *
     * @param DataFields $datafield
     * @param bool $is_mass_edit_trigger
     *
     * @return null|array
     */
    private function isEventRelevant($datafield, $is_mass_edit_trigger)
    {
        // Going to use the cached datatype array to locate the correct datafield...
        $datatype = $datafield->getDataType();
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links
        if ( !isset($dt_array[$datatype->getId()]['renderPluginInstances']) )
            return null;

        // If this is a MassEditTrigger event, then only activate when triggered directly on the
        //  non-vector fields
        $triggering_datafields = array(
//            'Vector Parallel X' => 'Orientation',
//            'Vector Parallel Y' => 'Orientation',
//            'Vector Parallel Z' => 'Orientation',
//            'Vector Parallel Reference Space' => 'Orientation',
//
//            'Vector Perpendicular X' => 'Orientation',
//            'Vector Perpendicular Y' => 'Orientation',
//            'Vector Perpendicular Z' => 'Orientation',
//            'Vector Perpendicular Reference Space' => 'Orientation',

            'Orientation' => 1,
//            'Laser Direction' => 1,
//            'Laser Polarization' => 1,
        );
        if ( !$is_mass_edit_trigger ) {
            // If this is a RadioPostUpdate event, then only want to activate when the vector fields
            //  got changed
            $triggering_datafields = array(
                'Vector Parallel X' => 1,
                'Vector Parallel Y' => 1,
                'Vector Parallel Z' => 1,
                'Vector Parallel Reference Space' => 1,

                'Vector Perpendicular X' => 1,
                'Vector Perpendicular Y' => 1,
                'Vector Perpendicular Z' => 1,
                'Vector Perpendicular Reference Space' => 1,

//                'Orientation' => 1,
//                'Laser Direction' => 1,
//                'Laser Polarization' => 1,
            );
        }

        // The destination datafields remain the same regardless of the triggering fields
        $destination_datafields = array(
            'Orientation' => 1,
            'Laser Direction' => 1,
            'Laser Polarization' => 1,
        );

        foreach ($dt_array[$datatype->getId()]['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.pin_data' ) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                    if ( isset($triggering_datafields[$rpf_name]) && $rpf['id'] === $datafield->getId() ) {
                        // The datafield that triggered the event is one of the relevant fields
                        //  ...ensure the destination fields exist while we're here
                        foreach ($destination_datafields as $dest_rpf_name => $num) {
                            if ( !isset($rpi['renderPluginMap'][$dest_rpf_name]) ) {
                                // If any of the three destination fields don't exist for some reason,
                                //  then just return null
                                return null;
                            }
                        }

                        // Otherwise, the rest of the plugin will work
                        return $destination_datafields;
                    }
                }
            }
        }

        // ...otherwise, this is not a relevant field, or the fields aren't mapped for some reason
        return null;
    }


    /**
     * Returns a storage entity that the PostUpdate or MassEditTrigger events will overwrite.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param DataRecord $datarecord
     * @param string $destination_rpf_name
     *
     * @return ShortVarchar|LongVarchar
     */
    private function findDestinationEntity($user, $datatype, $datarecord, $destination_rpf_name)
    {
        // Going to use the cached datatype array to locate the correct datafield...
        $df_id = null;
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links
        foreach ($dt_array[$datatype->getId()]['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.pin_data' ) {
                $df_id = $rpi['renderPluginMap'][$destination_rpf_name]['id'];
                break;
            }
        }
        if ($df_id == null)
            throw new ODRException('Unable to locate "'.$destination_rpf_name.'" field');

        // Hydrate the destination datafield...it's guaranteed to exist
        /** @var DataFields $datafield */
        $datafield = $this->em->getRepository('ODRAdminBundle:DataFields')->find($df_id);

        // Return the storage entity for this datarecord/datafield pair
        return $this->entity_create_service->createStorageEntity($user, $datarecord, $datafield);
    }


    /**
     * Determines whether the user changed any of Radio fields required by this plugin...if so, then
     * update the derived fields.
     *
     * @param RadioPostUpdateEvent $event
     *
     * @throws \Exception
     */
    public function onRadioPostUpdate(RadioPostUpdateEvent $event)
    {
        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $datarecord = null;
        $datafield = null;
        $datatype = null;
        $destination_rpf_names = null;
        $user = null;

        try {
            // Get entities related to the file
            $drf = $event->getRadioDataRecordField();
            $datarecord = $drf->getDataRecord();
            $datafield = $drf->getDataField();
            $typeclass = $datafield->getFieldType()->getTypeClass();
            $datatype = $datafield->getDataType();
            $user = $event->getUser();

            $derived_values = self::getFullVectorStrs($datatype, $datarecord);

            // Only care about a change to specific fields of this datatype...
            $destination_rpf_names = self::isEventRelevant($datafield, false);
            if ( !is_null($destination_rpf_names) ) {
                // One of the relevant datafields got changed...locate all three relevant destination
                //  datafields
                foreach ($destination_rpf_names as $dest_rpf_name => $num)
                    $destination_rpf_names[$dest_rpf_name] = self::findDestinationEntity($user, $datatype, $datarecord, $dest_rpf_name);

                // Derive the new values for the destination entities...
                foreach ($destination_rpf_names as $dest_rpf_name => $dest_entity) {
                    // ...so they can get updated
                    $derived_value = $derived_values[$dest_rpf_name];
                    $this->entity_modify_service->updateStorageEntity(
                        $user,
                        $dest_entity,
                        array('value' => $derived_value),
                        false,    // no sense trying to delay flush
                        false    // don't fire PostUpdate event...nothing depends on these fields
                    );
                    $this->logger->debug(' -- updating datafield '.$dest_entity->getDataField()->getId().' ('.$dest_rpf_name.'), '.$typeclass.' '.$dest_entity->getId().' with the value "'.$derived_value.'"...', array(self::class, 'onRadioPostUpdate()'));
                }

                // This only works because the datafields getting updated aren't files/images or
                //  radio/tag fields
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onRadioPostUpdate()', 'user '.$user->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));

            if ( !is_null($destination_rpf_names) ) {
                // If an error was thrown, attempt to ensure any derived fields are blank
                self::saveOnError($user, $destination_rpf_names);
            }

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( !is_null($destination_rpf_names) ) {
                $this->logger->debug('All changes saved', array(self::class, 'onRadioPostUpdate()', 'dt '.$datatype->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));
                self::clearCacheEntries($datarecord, $user, $destination_rpf_names);
            }
        }
    }


    /**
     * Determines whether the user changed any of the Radio fields used by this plugin.
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
        $destination_rpf_names = null;
        $user = null;

        try {
            // Get entities related to the file
            $drf = $event->getDataRecordFields();
            $datarecord = $drf->getDataRecord();
            $datafield = $drf->getDataField();
            $typeclass = $datafield->getFieldType()->getTypeClass();
            $datatype = $datafield->getDataType();
            $user = $event->getUser();

            $derived_values = self::getFullVectorStrs($datatype, $datarecord);

            // Only care about a change to specific fields of this datatype...
            $destination_rpf_names = self::isEventRelevant($datafield, true);
            if ( !is_null($destination_rpf_names) ) {
                // One of the relevant datafields got changed...locate all three relevant destination
                //  datafields
                foreach ($destination_rpf_names as $dest_rpf_name => $num)
                    $destination_rpf_names[$dest_rpf_name] = self::findDestinationEntity($user, $datatype, $datarecord, $dest_rpf_name);

                // Derive the new values for the destination entities...
                foreach ($destination_rpf_names as $dest_rpf_name => $dest_entity) {
                    // ...so they can get updated
                    $derived_value = $derived_values[$dest_rpf_name];
                    $this->entity_modify_service->updateStorageEntity(
                        $user,
                        $dest_entity,
                        array('value' => $derived_value),
                        false,    // no sense trying to delay flush
                        false    // don't fire PostUpdate event...nothing depends on these fields
                    );
                    $this->logger->debug(' -- updating datafield '.$dest_entity->getDataField()->getId().' ('.$dest_rpf_name.'), '.$typeclass.' '.$dest_entity->getId().' with the value "'.$derived_value.'"...', array(self::class, 'onMassEditTrigger()'));
                }

                // This only works because the datafields getting updated aren't files/images or
                //  radio/tag fields
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onMassEditTrigger()', 'user '.$user->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));

            if ( !is_null($destination_rpf_names) ) {
                // If an error was thrown, attempt to ensure any derived fields are blank
                self::saveOnError($user, $destination_rpf_names);
            }

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( !is_null($destination_rpf_names) ) {
                $this->logger->debug('All changes saved', array(self::class, 'onMassEditTrigger()', 'dt '.$datatype->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));
                self::clearCacheEntries($datarecord, $user, $destination_rpf_names);
            }
        }
    }


    /**
     * Returns a string description of how the eight vector dropdown fields are selected.
     *
     * @param DataType $datatype
     * @param DataRecord $datarecord
     * @return string[]
     */
    private function getFullVectorStrs($datatype, $datarecord)
    {
        // Going to dig through cached arrays for this
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);  // don't need linked data
        $dt = $dt_array[$datatype->getId()];

        $df_mapping = array();
        foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.pin_data' ) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf_df) {
                    switch ($rpf_name) {
                        case 'Vector Parallel X':
                        case 'Vector Parallel Y':
                        case 'Vector Parallel Z':
                        case 'Vector Parallel Reference Space':
                        case 'Vector Perpendicular X':
                        case 'Vector Perpendicular Y':
                        case 'Vector Perpendicular Z':
                        case 'Vector Perpendicular Reference Space':
                            $df_id = $rpf_df['id'];
                            $df_mapping[$rpf_name] = $df_id;
                            break;
                    }
                }
            }
        }
        if ( count($df_mapping) !== 8 )
            throw new ODRException('Plugin config does not have all eight required vector fields');

        // Determine the values for each possible radio option
        $ro_mapping = array();
        foreach ($df_mapping as $rpf_name => $df_id) {
            if ( !isset($dt['dataFields'][$df_id]['radioOptions']) )
                throw new ODRException('Plugin config does not have the correct types of fields');

            foreach ($dt['dataFields'][$df_id]['radioOptions'] as $display_order => $ro) {
                $ro_id = $ro['id'];
                $ro_name = $ro['optionName'];

                $ro_mapping[$ro_id] = $ro_name;
            }
        }
        if ( count($ro_mapping) !== 22 )
            throw new ODRException('Plugin config does not have all 22 required options for the vector fields');


        // ----------------------------------------
        // Determine which radio options are selected for each field
        // NOTE: using a database query instead of the cached datarecord array, otherwise it's almost
        //  impossible for CSVImport and MassEdit to work with the event
        $query = $this->em->createQuery(
           'SELECT df.id AS df_id, ro.optionName
            FROM ODRAdminBundle:DataRecordFields drf
            LEFT JOIN ODRAdminBundle:DataFields df WITH drf.dataField = df
            LEFT JOIN ODRAdminBundle:RadioSelection rs WITH rs.dataRecordFields = drf
            LEFT JOIN ODRAdminBundle:RadioOptions ro WITH rs.radioOption = ro
            WHERE drf.dataRecord = :datarecord_id AND drf.dataField IN (:datafield_ids)
            AND rs.selected = 1
            AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL'
        )->setParameters(
            array(
                'datarecord_id' => $datarecord->getId(),
                'datafield_ids' => $df_mapping,
            )
        );
        $results = $query->getArrayResult();

        $drf_mapping = array();
        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $option_name = $result['optionName'];

            $drf_mapping[$df_id] = $option_name;
        }


        // ----------------------------------------
        // The entries may technically not exist
        $parallel_x = $parallel_y = $parallel_z = $parallel_ref = '0';
        $perpendicular_x = $perpendicular_y = $perpendicular_z = $perpendicular_ref = '0';

        if ( isset($drf_mapping[ $df_mapping['Vector Parallel X'] ]) )
            $parallel_x = $drf_mapping[ $df_mapping['Vector Parallel X'] ];
        if ( isset($drf_mapping[ $df_mapping['Vector Parallel Y'] ]) )
            $parallel_y = $drf_mapping[ $df_mapping['Vector Parallel Y'] ];
        if ( isset($drf_mapping[ $df_mapping['Vector Parallel Z'] ]) )
            $parallel_z = $drf_mapping[ $df_mapping['Vector Parallel Z'] ];
        if ( isset($drf_mapping[ $df_mapping['Vector Parallel Reference Space'] ]) )
            $parallel_ref = $drf_mapping[ $df_mapping['Vector Parallel Reference Space'] ];

        if ( isset($drf_mapping[ $df_mapping['Vector Perpendicular X'] ]) )
            $perpendicular_x = $drf_mapping[ $df_mapping['Vector Perpendicular X'] ];
        if ( isset($drf_mapping[ $df_mapping['Vector Perpendicular Y'] ]) )
            $perpendicular_y = $drf_mapping[ $df_mapping['Vector Perpendicular Y'] ];
        if ( isset($drf_mapping[ $df_mapping['Vector Perpendicular Z'] ]) )
            $perpendicular_z = $drf_mapping[ $df_mapping['Vector Perpendicular Z'] ];
        if ( isset($drf_mapping[ $df_mapping['Vector Perpendicular Reference Space'] ]) )
            $perpendicular_ref = $drf_mapping[ $df_mapping['Vector Perpendicular Reference Space'] ];

        // Attempt to build the vector strings from what does exist
        $parallel_strs = self::getVectorStr($parallel_x, $parallel_y, $parallel_z, $parallel_ref);
        $perpendicular_strs = self::getVectorStr($perpendicular_x, $perpendicular_y, $perpendicular_z, $perpendicular_ref);

        $str = '';
        if ( $parallel_strs['long'] !== '' )
            $str .= 'Laser parallel to '.$parallel_strs['long'].'&nbsp;&nbsp;';
        if ( $perpendicular_strs['long'] !== '' )
            $str .= 'Fiducial mark perpendicular to laser is parallel to '.$perpendicular_strs['long'];

        return array(
            'Orientation' => $str,
            'Laser Direction' => $parallel_strs['short'],
            'Laser Polarization' => $perpendicular_strs['short'],
        );
    }


    /**
     * Converts the selected pin data into a single string.
     *
     * @param string $x
     * @param string $y
     * @param string $z
     * @param string $ref
     * @return string[]
     */
    private function getVectorStr($x, $y, $z, $ref)
    {
        $ret = array('long' => '', 'short' => '');
        $vector_str = $x.' '.$y.' '.$z;

        $dir = '';
        switch ($vector_str) {
            // Aligned to a single direction
            case '-1 0 0':
                $dir = '-a';
                break;
            case '1 0 0':
                $dir = 'a';
                break;
            case '0 -1 0':
                $dir = '-b';
                break;
            case '0 1 0':
                $dir = 'b';
                break;
            case '0 0 -1':
                $dir = '-c';
                break;
            case '0 0 1':
                $dir = 'c';
                break;

            // Aligned to more than one direction
            case '0 1 1':
            case '1 0 1':
            case '1 1 0':
            case '1 1 1':
                $dir = '';
                break;

            // Nothing else is allowed
            default:
                $vector_str = '';
                break;
        }

        if ( $vector_str === '' ) {
            // Not a valid alignment, return empty strings
            return $ret;
        }
        else {
            if ( $ref === 'direct' ) {
                $ret['short'] = $ret['long'] = '['.$vector_str.']';

                if ( $dir !== '' )
                    $ret['long'] = '<b>'.$dir.'</b>&nbsp;&nbsp;&nbsp;'.$ret['long'];
                return $ret;
            }
            else if ( $ref === 'reciprocal' ) {
                $ret['short'] = $ret['long'] = '('.$vector_str.')';

                if ( $dir !== '' )
                    $ret['long'] = '<b>'.$dir.'*</b>&nbsp;&nbsp;&nbsp;'.$ret['long'];
                return $ret;
            }
            else {
                // Some other problem, return empty strings
                return $ret;
            }
        }
    }


    /**
     * If some sort of error/exception was thrown, then attempt to blank out the given derived
     * fields...this doesn't fix the underlying problem, but at least the renderplugin can recognize
     * and display that something is wrong with the source field.
     *
     * @param ODRUser $user
     * @param array $destination_storage_entities
     */
    private function saveOnError($user, $destination_storage_entities)
    {
        if ( !empty($destination_storage_entities) ) {
            foreach ($destination_storage_entities as $dest_rpf_name => $dest_entity) {
                $dr = $dest_entity->getDataRecord();
                $df = $dest_entity->getDataField();

                try {
                    $this->entity_modify_service->updateStorageEntity(
                        $user,
                        $dest_entity,
                        array('value' => ''),
                        false,    // no point delaying flush
                        false    // don't fire PostUpdate event...nothing depends on these fields
                    );
                    $this->logger->debug('-- -- updating dr '.$dr->getId().', df '.$df->getId().' ('.$dest_rpf_name.') to have the value ""...', array(self::class, 'saveOnError()'));
                }
                catch (\Exception $e) {
                    // Some other error...no way to recover from it
                    $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'saveOnError()', 'user '.$user->getId(), 'dr '.$dr->getId(), 'df '.$df->getId()));
                }
            }
        }
    }


    /**
     * Wipes or updates relevant cache entries once everything is completed.
     *
     * @param DataRecord $datarecord
     * @param ODRUser $user
     * @param array $destination_storage_entities
     */
    private function clearCacheEntries($datarecord, $user, $destination_storage_entities)
    {
        // Fire off events notifying that the modification of each datafield is done
        try {
            foreach ($destination_storage_entities as $dest_rpf_name => $dest_entity) {
                $event = new DatafieldModifiedEvent($dest_entity->getDataField(), $user);
                $this->event_dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
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
            $this->event_dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }
    }


    /**
     * @inheritDoc
     */
    public function getDerivationMap($render_plugin_instance)
    {
        // Don't execute on instances of other render plugins
        if ( $render_plugin_instance['renderPlugin']['pluginClassName'] !== 'odr_plugins.rruff.pin_data' )
            return array();
        $render_plugin_map = $render_plugin_instance['renderPluginMap'];

        // All the fields in this plugin are involved with derivation...
        $vector_parallel_x_df_id = $render_plugin_map['Vector Parallel X']['id'];
        $vector_parallel_y_df_id = $render_plugin_map['Vector Parallel Y']['id'];
        $vector_parallel_z_df_id = $render_plugin_map['Vector Parallel Z']['id'];
        $vector_parallel_ref_df_id = $render_plugin_map['Vector Parallel Reference Space']['id'];

        $vector_perpendicular_x_df_id = $render_plugin_map['Vector Perpendicular X']['id'];
        $vector_perpendicular_y_df_id = $render_plugin_map['Vector Perpendicular Y']['id'];
        $vector_perpendicular_z_df_id = $render_plugin_map['Vector Perpendicular Z']['id'];
        $vector_perpendicular_ref_df_id = $render_plugin_map['Vector Perpendicular Reference Space']['id'];

        $orientation_df_id = $render_plugin_map['Orientation']['id'];
        $laser_direction_df_id = $render_plugin_map['Laser Direction']['id'];
        $laser_polarization_df_id = $render_plugin_map['Laser Polarization']['id'];

        // Since a datafield could be derived from multiple datafields, the source datafields need
        //  to be in an array (even though that's not the case for the IMA Plugin)
        return array(
            $orientation_df_id => array(
                $vector_parallel_x_df_id,
                $vector_parallel_y_df_id,
                $vector_parallel_z_df_id,
                $vector_parallel_ref_df_id,

                $vector_perpendicular_x_df_id,
                $vector_perpendicular_y_df_id,
                $vector_perpendicular_z_df_id,
                $vector_perpendicular_ref_df_id,
            ),
            $laser_direction_df_id => array(
                $vector_parallel_x_df_id,
                $vector_parallel_y_df_id,
                $vector_parallel_z_df_id,
                $vector_parallel_ref_df_id,

                $vector_perpendicular_x_df_id,
                $vector_perpendicular_y_df_id,
                $vector_perpendicular_z_df_id,
                $vector_perpendicular_ref_df_id,
            ),
            $laser_polarization_df_id => array(
                $vector_parallel_x_df_id,
                $vector_parallel_y_df_id,
                $vector_parallel_z_df_id,
                $vector_parallel_ref_df_id,

                $vector_perpendicular_x_df_id,
                $vector_perpendicular_y_df_id,
                $vector_perpendicular_z_df_id,
                $vector_perpendicular_ref_df_id,
            ),
        );
    }


    /**
     * @inheritDoc
     */
    public function getOverrideParameters($rendering_context, $render_plugin_instance, $datafield, $datarecord, $theme, $user, $is_datatype_admin)
    {
        // Only override when called from the 'display' or 'edit' contexts...though at the moment,
        //  ODR only calls this via the 'edit' context
        if ( $rendering_context !== 'edit' && $rendering_context !== 'display' )
            return array();

        // Sanity checks
        if ( $render_plugin_instance->getRenderPlugin()->getPluginClassName() !== 'odr_plugins.rruff.pin_data' )
            return array();
        $datatype = $datafield->getDataType();
        if ( $datatype->getId() !== $datarecord->getDataType()->getId() )
            return array();
        if ( $render_plugin_instance->getDataType()->getId() !== $datatype->getId() )
            return array();

        // Only need to do something when in edit mode...
        if ( $rendering_context === 'edit' ) {
            // Need to figure out which field is getting reloaded...
            $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);  // don't want links
            $dt = $dt_array[$datatype->getId()];

            $vector_df_ids = array();
            $orientation_df_id = $laser_direction_df_id = $laser_polarization_df_id = 0;
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.pin_data' ) {
                    foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf_df) {
                        $df_id = $rpf_df['id'];

                        switch ($rpf_name) {
                            case 'Vector Parallel X':
                            case 'Vector Parallel Y':
                            case 'Vector Parallel Z':
                            case 'Vector Parallel Reference Space':
                            case 'Vector Perpendicular X':
                            case 'Vector Perpendicular Y':
                            case 'Vector Perpendicular Z':
                            case 'Vector Perpendicular Reference Space':
                                $vector_df_ids[$df_id] = 1;
                                break;

                            case 'Orientation':
                                $orientation_df_id = $df_id;
                                break;
                            case 'Laser Direction':
                                $laser_direction_df_id = $df_id;
                                break;
                            case 'Laser Polarization':
                                $laser_polarization_df_id = $df_id;
                                break;
                        }
                    }
                }
            }

            if ( isset($vector_df_ids[$datafield->getId()]) ) {
                // This is a reload request for one of the vector datafields
                return array(
                    'token_list' => array(),    // so ODRRenderService generates CSRF tokens
                    'template_name' => 'ODROpenRepositoryGraphBundle:RRUFF:RRUFFPinData/pindata_edit_vector_datafield.html.twig',

                    'orientation_datafield_id' => $orientation_df_id,
                    'direction_datafield_id' => $laser_direction_df_id,
                    'polarization_datafield_id' => $laser_polarization_df_id,
                );
            }
            else if ( $orientation_df_id === $datafield->getId()
                || $laser_direction_df_id === $datafield->getId()
                || $laser_polarization_df_id === $datafield->getId()
            ) {
                // This is a reload request for the derived datafields...use the display version override
                return array(
                    'token_list' => array(),    // so ODRRenderService generates CSRF tokens
                    'template_name' => 'ODROpenRepositoryGraphBundle:RRUFF:RRUFFPinData/pindata_display_orientation_datafield.html.twig',
                );
            }
        }

        // Otherwise, don't want to override the default reloading for this field
        return array();
    }


    /**
     * @inheritDoc
     */
    public function getMassEditOverrideFields($render_plugin_instance)
    {
        if ( !isset($render_plugin_instance['renderPluginMap']) )
            throw new ODRException('Invalid plugin config');

        // Only interested in overriding datafields mapped to these rpf entries
        $relevant_datafields = array(
            // Due to requiring values from eight different fields, it's better to have the checkbox
            //  on the derived fields...
            'Orientation' => 1,
//            'Laser Direction' => 1,
//            'Laser Polarization' => 1,
        );

        $ret = array();
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
        $relevant_datafields = array(
            // Due to requiring values from eight different fields, it's better to have the checkbox
            //  on the derived fields...
            'Orientation' => 1,
//            'Laser Direction' => 1,
//            'Laser Polarization' => 1,
        );

        $trigger_fields = array();
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
