<?php

/**
 * Open Data Repository Data Publisher
 * IMA Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This plugin enforces peculiarities of the International Mineralogical Association's (IMA) list
 * of approved minerals.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

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
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
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
use ODR\AdminBundle\Component\Service\LockService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldReloadOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
use ODR\OpenRepository\GraphBundle\Plugins\SearchOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class IMAPlugin implements DatatypePluginInterface, DatafieldDerivationInterface, DatafieldReloadOverrideInterface, MassEditTriggerEventInterface, SearchOverrideInterface, TableResultsOverrideInterface
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
     * @var DatarecordInfoService
     */
    private $datarecord_info_service;

    /**
     * @var EntityCreationService
     */
    private $entity_create_service;

    /**
     * @var EntityMetaModifyService
     */
    private $entity_modify_service;

    /**
     * @var LockService
     */
    private $lock_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode

    /**
     * @var CsrfTokenManager
     */
    private $token_manager;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * IMAPlugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param EntityCreationService $entity_create_service
     * @param EntityMetaModifyService $entity_modify_service
     * @param LockService $lock_service
     * @param SearchService $search_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param CsrfTokenManager $token_manager
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        EntityCreationService $entity_create_service,
        EntityMetaModifyService $entity_modify_service,
        LockService $lock_service,
        SearchService $search_service,
        EventDispatcherInterface $event_dispatcher,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->database_info_service = $database_info_service;
        $this->datarecord_info_service = $datarecord_info_service;
        $this->entity_create_service = $entity_create_service;
        $this->entity_modify_service = $entity_modify_service;
        $this->lock_service = $lock_service;
        $this->search_service = $search_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->token_manager = $token_manager;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * @inheritDoc
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        // The render plugin does several things...
        // 1) The "mineral_id" field needs to have autogenerated values (in FakeEdit)
        // 2) TODO - Generates several links that are identical for every mineral (in Display)
        // 3) Automatically derives the values for the chemical/valence element fields (in Edit)
        // 4) Provides the ability to force derivations without changing the values (in MassEdit)
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            if ( $context === 'display'
                || $context === 'edit'
                || $context === 'fake_edit'
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
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
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
            $plugin_fields = array();
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

                    // The IMA Plugin doesn't require the existence of any of its fields to execute
                    //  properly...Display and Edit modes are merely warning when certain fields are
                    //  blank when they shouldn't be, and FakeEdit can set Mineral ID regardless.

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
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;

            $theme = $theme_array[$initial_theme_id];


            // ----------------------------------------
            // Need to check the derived fields so that any problems with them can get displayed
            //  to the user
            $relevant_fields = self::getRelevantFields($datatype, $datarecord);

            $problem_fields = array();
            if ( $rendering_options['context'] === 'display' || $rendering_options['context'] === 'edit' ) {
                $derivation_problems = self::findDerivationProblems($relevant_fields);

                // Can't use array_merge() since that destroys the existing keys
                foreach ($derivation_problems as $df_id => $problem)
                    $problem_fields[$df_id] = $problem;
            }


            // Otherwise, output depends on which context the plugin is being executed from
            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                // Want to modify how the Valence Elements are displayed...
                $valence_elements_df_id = $relevant_fields['Valence Elements']['id'];

                $valence_elements_df_value = '';
                if ( isset($datarecord['dataRecordFields'][$valence_elements_df_id]['longVarchar'][0]) )
                    $valence_elements_df_value = $datarecord['dataRecordFields'][$valence_elements_df_id]['longVarchar'][0]['value'];

                $valence_elements_df_value = self::applyValenceElementsCSS($valence_elements_df_value);
                $datarecord['dataRecordFields'][$valence_elements_df_id]['longVarchar'][0]['value'] = $valence_elements_df_value;

                $record_display_view = 'single';
                if ( isset($rendering_options['record_display_view']) )
                    $record_display_view = $rendering_options['record_display_view'];

                // TODO - should this also try to take over display mode of Chemistry Plugin?
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:IMA/ima_display_fieldarea.html.twig',
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
                    'ODROpenRepositoryGraphBundle:RRUFF:IMA/ima_edit_fieldarea.html.twig',
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
            else if ( $rendering_options['context'] === 'fake_edit' ) {
                // Also need to provide a special token so the "mineral_id" field won't get ignored
                //  by FakeEdit because it prevents user edits...
                $mineralid_field_id = $fields['Mineral ID']['id'];
                $token_id = 'FakeEdit_'.$datarecord['id'].'_'.$mineralid_field_id.'_autogenerated';
                $token = $this->token_manager->getToken($token_id)->getValue();
                $special_tokens[$mineralid_field_id] = $token;

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:IMA/ima_fakeedit_fieldarea.html.twig',
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

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,
                        'special_tokens' => $special_tokens,

                        'plugin_fields' => $plugin_fields,
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
        $relevant_datafields = array(
            'Mineral ASCII Name' => array(),
            'Mineral Display Name' => array(),
            'Mineral Display Abbreviation' => array(),
            'Chemistry Elements' => array(),
            'IMA Formula' => array(),
            'Valence Elements' => array(),
            'RRUFF Formula' => array(),
        );

        // Locate the relevant render plugin instance
        $rpm_entries = null;
        foreach ($datatype['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.ima' ) {
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
        $derivations = array(
            'IMA Formula' => 'Chemistry Elements',
            'RRUFF Formula' => 'Valence Elements',
        );

        $problems = array();
        foreach ($derivations as $source_rpf_name => $dest_rpf_name) {
            if ( $relevant_datafields[$source_rpf_name]['value'] !== ''
                && $relevant_datafields[$dest_rpf_name]['value'] === ''
            ) {
                $dest_df_id = $relevant_datafields[$dest_rpf_name]['id'];
                $problems[$dest_df_id] = 'There seems to be a problem with the contents of the "'.$source_rpf_name.'" field.';
            }
        }

        // Due to needing the jquery validate plugin, it's better to not actually trigger an entry
        //  in here if the Mineral ASCII Name has a problem...

        // Return a list of any problems found
        return $problems;
    }


    /**
     * Handles when a datarecord is created.
     *
     * @param DatarecordCreatedEvent $event
     */
    public function onDatarecordCreate(DatarecordCreatedEvent $event)
    {
        // Pull some required data from the event
        $user = $event->getUser();
        $datarecord = $event->getDatarecord();
        $datatype = $datarecord->getDataType();

        // Need to locate the "mineral_id" field for this render plugin...
        $query = $this->em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:RenderPlugin rp
            JOIN ODRAdminBundle:RenderPluginInstance rpi WITH rpi.renderPlugin = rp
            JOIN ODRAdminBundle:RenderPluginMap rpm WITH rpm.renderPluginInstance = rpi
            JOIN ODRAdminBundle:DataFields df WITH rpm.dataField = df
            JOIN ODRAdminBundle:RenderPluginFields rpf WITH rpm.renderPluginFields = rpf
            WHERE rp.pluginClassName = :plugin_classname AND rpi.dataType = :datatype
            AND rpf.fieldName = :field_name
            AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL
            AND df.deletedAt IS NULL'
        )->setParameters(
            array(
                'plugin_classname' => 'odr_plugins.rruff.ima',
                'datatype' => $datatype->getId(),
                'field_name' => 'Mineral ID'
            )
        );
        $results = $query->getResult();
        if ( count($results) !== 1 )
            throw new ODRException('Unable to find the "Mineral ID" field for the RenderPlugin "IMA", attached to Datatype '.$datatype->getId());

        // Will only be one result, at this point
        $datafield = $results[0];
        /** @var DataFields $datafield */


        // ----------------------------------------
        // Need to acquire a lock to ensure that there are no duplicate values
        $lockHandler = $this->lock_service->createLock('datatype_'.$datatype->getId().'_autogenerate_id'.'.lock', 15);    // 15 second ttl
        if ( !$lockHandler->acquire() ) {
            // Another process is in the mix...block until it finishes
            $lockHandler->acquire(true);
        }

        // Now that a lock is acquired, need to find the "most recent" value for the field that is
        //  getting incremented...
        $old_value = self::findCurrentValue($datafield->getId());

        // Since the "most recent" mineral id is already an integer, just add 1 to it
        $new_value = $old_value + 1;

        // Create a new storage entity with the new value
        $this->entity_create_service->createStorageEntity($user, $datarecord, $datafield, $new_value, false);    // guaranteed to not need a PostUpdate event
        $this->logger->debug('Setting df '.$datafield->getId().' "Mineral ID" of new dr '.$datarecord->getId().' to "'.$new_value.'"...', array(self::class, 'onDatarecordCreate()'));

        // No longer need the lock
        $lockHandler->release();


        // ----------------------------------------
        // Fire off an event notifying that the modification of the datafield is done
        try {
            $event = new DatafieldModifiedEvent($datafield, $user);
            $this->event_dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }
    }


    /**
     * For this database, the mineral_id needs to be autogenerated.
     *
     * Don't particularly like random render plugins finding random stuff from the database, but
     * there's no other way to satisfy the design requirements.
     *
     * @param int $datafield_id
     *
     * @return int
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function findCurrentValue($datafield_id)
    {
        // Going to use native SQL...DQL can't use limit without using querybuilder...
        // NOTE - deleting a record doesn't delete the related storage entities, so deleted minerals
        //  are still considered in this query
        $query =
           'SELECT e.value
            FROM odr_integer_value e
            WHERE e.data_field_id = :datafield AND e.deletedAt IS NULL
            ORDER BY e.value DESC
            LIMIT 0,1';
        $params = array(
            'datafield' => $datafield_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        // Should only be one value in the result...
        $current_value = null;
        foreach ($results as $result)
            $current_value = intval( $result['value'] );

        // ...but if there's not for some reason, return zero as the "current".  onDatarecordCreate()
        //  will increment it so that the value one is what will actually get saved.
        // NOTE - this shouldn't happen for the existing IMA list
        if ( is_null($current_value) )
            $current_value = 0;

        return $current_value;
    }


    /**
     * Determines whether the user changed the fields on the left below...and if so, then updates
     * the corresponding field to the right:
     *  - "IMA Formula" => "Chemical Elements"
     *  - "RRUFF Formula" => "Valence Elements"
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

            // Only care about a change to specific fields of a datatype using the IMA render plugin...
            $rpf_name = self::isEventRelevant($datafield);
            if ( !is_null($rpf_name) ) {
                // ----------------------------------------
                // One of the relevant datafields got changed
                $source_value = $source_entity->getValue();
                if ($typeclass === 'DatetimeValue')
                    $source_value = $source_value->format('Y-m-d H:i:s');
                $this->logger->debug('Attempting to derive a value from dt '.$datatype->getId().', dr '.$datarecord->getId().', df '.$datafield->getId().' ('.$rpf_name.'): "'.$source_value.'"...', array(self::class, 'onPostUpdate()'));

                // Store the renderpluginfield name that will be modified
                $dest_rpf_name = null;
                if ($rpf_name === 'IMA Formula')
                    $dest_rpf_name = 'Chemistry Elements';
                else if ($rpf_name === 'RRUFF Formula')
                    $dest_rpf_name = 'Valence Elements';

                // Locate the destination entity for the relevant source datafield
                $destination_entity = self::findDestinationEntity($user, $datatype, $datarecord, $dest_rpf_name);

                // Derive the new value for the destination entity
                $derived_value = null;
                if ($rpf_name === 'IMA Formula')
                    $derived_value = self::convertIMAFormula($source_value);
                else if ($rpf_name === 'RRUFF Formula')
                    $derived_value = self::convertRRUFFFormula($source_value);

                // ...which is saved in the storage entity for the datafield
                $this->entity_modify_service->updateStorageEntity(
                    $user,
                    $destination_entity,
                    array('value' => $derived_value),
                    false,    // no sense trying to delay flush
                    false    // don't fire PostUpdate event...nothing depends on these fields
                );
                $this->logger->debug(' -- updating datafield '.$destination_entity->getDataField()->getId().' ('.$dest_rpf_name.'), '.$typeclass.' '.$destination_entity->getId().' with the value "'.$derived_value.'"...', array(self::class, 'onPostUpdate()'));

                // This only works because the datafields getting updated aren't files/images or
                //  radio/tag fields
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onPostUpdate()', 'user '.$user->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));

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
                $this->logger->debug('All changes saved', array(self::class, 'onPostUpdate()', 'dt '.$datatype->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));
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
     *  - "IMA Formula" => "Chemical Elements"
     *  - "RRUFF Formula" => "Valence Elements"
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

            // Only care about a change to specific fields of a datatype using the IMA render plugin...
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
                    $this->logger->debug('Attempting to derive a value from dt '.$datatype->getId().', dr '.$datarecord->getId().', df '.$datafield->getId().' ('.$rpf_name.'): "'.$source_value.'"...', array(self::class, 'onMassEditTrigger()'));

                    // Store the renderpluginfield name that will be modified
                    $dest_rpf_name = null;
                    if ($rpf_name === 'IMA Formula')
                        $dest_rpf_name = 'Chemistry Elements';
                    else if ($rpf_name === 'RRUFF Formula')
                        $dest_rpf_name = 'Valence Elements';

                    // Locate the destination entity for the relevant source datafield
                    $destination_entity = self::findDestinationEntity($user, $datatype, $datarecord, $dest_rpf_name);

                    // Derive the new value for the destination entity
                    $derived_value = null;
                    if ($rpf_name === 'IMA Formula')
                        $derived_value = self::convertIMAFormula($source_value);
                    else if ($rpf_name === 'RRUFF Formula')
                        $derived_value = self::convertRRUFFFormula($source_value);

                    // ...which is saved in the storage entity for the datafield
                    $this->entity_modify_service->updateStorageEntity(
                        $user,
                        $destination_entity,
                        array('value' => $derived_value),
                        false,    // no sense trying to delay flush
                        false    // don't fire PostUpdate event...nothing depends on these fields
                    );
                    $this->logger->debug(' -- updating datafield '.$destination_entity->getDataField()->getId().' ('.$dest_rpf_name.'), '.$typeclass.' '.$destination_entity->getId().' with the value "'.$derived_value.'"...', array(self::class, 'onMassEditTrigger()'));

                    // This only works because the datafields getting updated aren't files/images or
                    //  radio/tag fields
                }
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onMassEditTrigger()', 'user '.$user->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));

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
                $this->logger->debug('All changes saved', array(self::class, 'onMassEditTrigger()', 'dt '.$datatype->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));
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
        $relevant_datafields = array(
            'IMA Formula' => 'Chemistry Elements',
            'RRUFF Formula' => 'Valence Elements',
        );

        foreach ($dt_array[$datatype->getId()]['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.ima' ) {
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
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.ima' ) {
                $df_id = $rpi['renderPluginMap'][$destination_rpf_name]['id'];
                break;
            }
        }

        // Hydrate the destination datafield...it's guaranteed to exist
        /** @var DataFields $datafield */
        $datafield = $this->em->getRepository('ODRAdminBundle:DataFields')->find($df_id);

        // Return the storage entity for this datarecord/datafield pair
        return $this->entity_create_service->createStorageEntity($user, $datarecord, $datafield);
    }


    /**
     * Extracts the chemical elements from the official IMA chemical formula.
     *
     * For example, the IMA formula for Gypsum is Ca(SO_4_)·2H_2_O
     * The individual elements in this formula are "Ca", "S", "O", and "H"
     *
     * @param string $ima_formula
     *
     * @return string
     */
    private function convertIMAFormula($ima_formula)
    {
        // Locate the elements in the IMA formula
        $ima_pattern = '/(REE|[A-Z][a-z]?)/';    // Attempt to locate 'REE' first, then fallback to a capital letter followed by an optional lowercase letter
        $ima_matches = array();
        preg_match_all($ima_pattern, $ima_formula, $ima_matches);

        // Create a unique list of tokens from the array of elements
        $ima_elements = array();
        foreach ($ima_matches[1] as $num => $elem)
            $ima_elements[$elem] = 1;
        $ima_elements = array_keys($ima_elements);

        $chemistry_elements = implode(" ", $ima_elements);
        return $chemistry_elements;
    }


    /**
     * Extracts the chemical elements (and associated valence states) from the RRUFF chemical formula.
     *
     * For example, the RRUFF formula for Gypsum is Ca(S^6+^O_4_)·2H_2_O
     * The valence elements from this formula are "Ca", "S^6+", "O", and "H"
     *
     * NOTE - The valence indicator is only valid when immediately preceeded by an element.
     * For example, the RRUFF formula for Alloclasite is Co^3+^(AsS)^3-^
     * The correct valence elements from this formula are "Co^3+", "As", and "S".  Since the "^3-^"
     *  affects a grouping of elements in the formula, it gets ignored.
     *
     * @param string $rruff_formula
     *
     * @return string
     */
    private function convertRRUFFFormula($rruff_formula)
    {
        // Locate the elements and the valences in the RRUFF formula...valences look like "^6+^" or "^2-^"
        $rruff_pattern = '/(REE|[A-Z][a-z]?|\^\d[\+\-]\^)/';    // Attempt to locate 'REE' first, then fallback to a capital letter followed by an optional lowercase letter, then fallback to a valence indicator
        $rruff_matches = array();
        preg_match_all($rruff_pattern, $rruff_formula, $rruff_matches, PREG_OFFSET_CAPTURE);

        // There are mineral formulas that have stuff like  (AsS)^3-^  or  (C_2_)^6+^O_4_)_2_
        // Valence state tokens should be
        foreach ($rruff_matches[1] as $num => $elem_pos) {
            $elem = $elem_pos[0];
            $pos = $elem_pos[1];

            if ( strpos($elem, '^') !== false ) {
                $previous_char = $rruff_formula[$pos-1];

                // Recombine valence states with the immediately preceeding element, if it exists
                if ( preg_match('/[a-zA-Z]/', $previous_char) === 1 ) {
                    $previous_elem = $rruff_matches[1][$num-1][0];
                    $new_elem = $previous_elem.'^'.substr($elem, 1, 2);

                    // Overwrite the preceeding element
                    $rruff_matches[1][$num-1][0] = $new_elem;
                }

                // Regardless of whether it matched an element or not, never save the valence token
                unset( $rruff_matches[1][$num] );
            }
        }

        // Create a unique list of tokens from the modified array
        $rruff_elements = array();
        foreach ($rruff_matches[1] as $num => $elem_pos) {
            $elem = $elem_pos[0];
            $rruff_elements[$elem] = 1;
        }
        $rruff_elements = array_keys($rruff_elements);

        $valence_elements = implode(" ", $rruff_elements);
        return $valence_elements;
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
                    array('value' => ''),
                    false,    // no point delaying flush
                    false    // don't fire PostUpdate event...nothing depends on these fields
                );
                $this->logger->debug('-- -- updating dr '.$dr->getId().', df '.$df->getId().' to have the value ""...', array(self::class, 'saveOnError()'));
            }
        }
        catch (\Exception $e) {
            // Some other error...no way to recover from it
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'saveOnError()', 'user '.$user->getId(), 'dr '.$dr->getId(), 'df '.$df->getId()));
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
            $this->event_dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
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
    public function getOverrideParameters($rendering_context, $render_plugin_instance, $datafield, $datarecord, $theme, $user, $is_datatype_admin)
    {
        // Only override when called from the 'display' or 'edit' contexts...though at the moment,
        //  ODR only calls this via the 'edit' context
        if ( $rendering_context !== 'edit' && $rendering_context !== 'display' )
            return array();

        // Sanity checks
        if ( $render_plugin_instance->getRenderPlugin()->getPluginClassName() !== 'odr_plugins.rruff.ima' )
            return array();
        $datatype = $datafield->getDataType();
        if ( $datatype->getId() !== $datarecord->getDataType()->getId() )
            return array();
        if ( $render_plugin_instance->getDataType()->getId() !== $datatype->getId() )
            return array();


        // Want the derived fields in IMA to complain if they're blank, but their source field isn't
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
                return array(
                    'token_list' => array(),    // so ODRRenderService generates CSRF tokens
                    'template_name' => 'ODROpenRepositoryGraphBundle:RRUFF:IMA/ima_edit_datafield_reload.html.twig',
                    'problem_fields' => $derivation_problems,
                );
            }
        }

        // Otherwise, don't want to override the default reloading for this field
        return array();
    }


    /**
     * @inheritDoc
     */
    public function getDerivationMap($render_plugin_instance)
    {
        // Don't execute on instances of other render plugins
        if ( $render_plugin_instance['renderPlugin']['pluginClassName'] !== 'odr_plugins.rruff.ima' )
            return array();
        $render_plugin_map = $render_plugin_instance['renderPluginMap'];

        // The IMA plugin has two derived fields...
        //  - "Chemistry Elements" is derived from "IMA Formula"
        $chemistry_elements_df_id = $render_plugin_map['Chemistry Elements']['id'];
        $ima_formula_df_id = $render_plugin_map['IMA Formula']['id'];

        //  - "Valence Elements" is derived from "RRUFF Formula"
        $valence_elements_df_id = $render_plugin_map['Valence Elements']['id'];
        $rruff_formula_df_id = $render_plugin_map['RRUFF Formula']['id'];

        // Since a datafield could be derived from multiple datafields, the source datafields need
        //  to be in an array (even though that's not the case for the IMA Plugin)
        return array(
            $chemistry_elements_df_id => array($ima_formula_df_id),
            $valence_elements_df_id => array($rruff_formula_df_id),
        );
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
            'IMA Formula' => 'Chemistry Elements',
            'RRUFF Formula' => 'Valence Elements',
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
        // Only interested in overriding datafields mapped to these rpf entries
        $relevant_datafields = array(
            'IMA Formula' => 'Chemistry Elements',
            'RRUFF Formula' => 'Valence Elements',
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


    /**
     * @inheritDoc
     */
    public function canExecuteSearchPlugin($render_plugin_instance, $datatype, $datafield, $rendering_options)
    {
        // Don't want to override any part of the search sidebar specifically
        return array();
    }


    /**
     * @inheritDoc
     */
    public function executeSearchPlugin($render_plugin_instance, $datatype, $datafield, $preset_value, $rendering_options)
    {
        // Don't want to override any part of the search sidebar specifically
        return '';
    }


    /**
     * @inheritDoc
     */
    public function getSearchOverrideFields($df_list)
    {
        // The array entry for 'Mineral Display Name' will only exist if the search system needs to
        //  run a search on the field
        if ( isset($df_list['Mineral Display Name']) )
            return array( 'Mineral Display Name' => $df_list['Mineral Display Name'] );
        else
            return array();
    }


    /**
     * @inheritDoc
     */
    public function searchOverriddenField($mineral_name_df, $search_term, $render_plugin_fields, $render_plugin_options)
    {
        // This currently should only be called with the 'Mineral Name' field...any search term should
        //  simultaneously be used on the contents of the 'Mineral Aliases' field
        $mineral_aliases_df_id = $render_plugin_fields['Mineral Aliases'];


        // ----------------------------------------
        // Not going to fundamentally change how the searches are done...
        $search_value = $search_term['value'];
        $mineral_name_search_results = $this->search_service->searchTextOrNumberDatafield($mineral_name_df, $search_value);
        $involves_empty_string = $mineral_name_search_results['guard'];

        $mineral_aliases_search_results = array('records' => array());
        if ( $search_value !== "\"\"" ) {
            // ...but should only search the 'Mineral Aliases' field when not searching on the empty string
            /** @var DataFields $mineral_aliases_df */
            $mineral_aliases_df = $this->em->getRepository('ODRAdminBundle:DataFields')->find($mineral_aliases_df_id);
            $mineral_aliases_search_results = $this->search_service->searchTextOrNumberDatafield($mineral_aliases_df, $search_value);
        }


        // ----------------------------------------
        // These two sets of results need to be OR'ed together...
        $final_dr_list = $mineral_name_search_results['records'];
        foreach ($mineral_aliases_search_results['records'] as $dr_id => $num)
            $final_dr_list[$dr_id] = 1;

        // ...and then returned as if it was any other search result
        return array(
            'dt_id' => $mineral_name_search_results['dt_id'],
            'records' => $final_dr_list,
            'guard' => $involves_empty_string,
        );
    }


    /**
     * Inserts HTML into the Valence Elements field so it looks nicer
     *
     * e.g. "Pb^2+ Sn^4+ In^3+ Bi^3+ S^2-" gets turned into
     * "Pb<sup>2+</sup> Sn<sup>4+</sup> In<sup>3+</sup> Bi<sup>3+</sup> S<sup>2-</sup>"
     *
     * @param string $valence_elements_df_value
     * @return string
     */
    private function applyValenceElementsCSS($valence_elements_df_value)
    {
        $pattern = '/\^([0-9][\+\-])/';
        $replacement = '<sup>$1</sup>';
        return preg_replace($pattern, $replacement, $valence_elements_df_value);
    }


    /**
     * @inheritDoc
     */
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // Want to override the Valence Elements field
        $valence_elements_df_id = $render_plugin_instance['renderPluginMap']['Valence Elements']['id'];

        // Valence elements is easy
        $valence_elements_df_value = '';
        if ( isset($datarecord['dataRecordFields'][$valence_elements_df_id]['longVarchar'][0]) )
            $valence_elements_df_value = $datarecord['dataRecordFields'][$valence_elements_df_id]['longVarchar'][0]['value'];
        $valence_elements_df_value = self::applyValenceElementsCSS($valence_elements_df_value);


        // Only need to return values for the datafields getting overridden
        return array(
            $valence_elements_df_id => $valence_elements_df_value,
        );
    }
}
