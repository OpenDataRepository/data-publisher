<?php

/**
 * Open Data Repository Data Publisher
 * AMCSD Plugin
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
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Theme;
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
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldReloadOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class IMAPlugin implements DatatypePluginInterface, DatafieldDerivationInterface, DatafieldReloadOverrideInterface, MassEditTriggerEventInterface
{

    /**
     * @var ContainerInterface
     */
    private $container;

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
     * @var ThemeInfoService
     */
    private $theme_info_service;

    /**
     * @var EntityCreationService
     */
    private $entity_create_service;

    /**
     * @var EntityMetaModifyService
     */
    private $entity_modify_service;

    /**
     * @var PermissionsManagementService
     */
    private $permissions_service;

    /**
     * @var LockService
     */
    private $lock_service;

    /**
     * @var SortService
     */
    private $sort_service;

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
     * @param ContainerInterface $container
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param ThemeInfoService $theme_info_service
     * @param EntityCreationService $entity_create_service
     * @param EntityMetaModifyService $entity_modify_service
     * @param PermissionsManagementService $permissions_service
     * @param LockService $lock_service
     * @param SortService $sort_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param CsrfTokenManager $token_manager
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        ContainerInterface $container,
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        ThemeInfoService $theme_info_service,
        EntityCreationService $entity_create_service,
        EntityMetaModifyService $entity_modify_service,
        PermissionsManagementService $permissions_service,
        LockService $lock_service,
        SortService $sort_service,
        EventDispatcherInterface $event_dispatcher,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->container = $container;
        $this->em = $entity_manager;
        $this->database_info_service = $database_info_service;
        $this->datarecord_info_service = $datarecord_info_service;
        $this->theme_info_service = $theme_info_service;
        $this->entity_create_service = $entity_create_service;
        $this->entity_modify_service = $entity_modify_service;
        $this->permissions_service = $permissions_service;
        $this->lock_service = $lock_service;
        $this->sort_service = $sort_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->token_manager = $token_manager;
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
     * Executes the IMA Plugin on the provided datarecords
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
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
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
            // Going to gather and render RRUFF References, and also likely change the contents
            //  of the Status Notes field
            $related_reference_info = array();
            $status_notes_info = array();


            // Need to check the derived fields so that any problems with them can get displayed
            //  to the user
            $relevant_fields = self::getRelevantFields($datatype, $datarecord);

            $problem_fields = array();
            if ( $rendering_options['context'] === 'display' || $rendering_options['context'] === 'edit' ) {
                $derivation_problems = self::findDerivationProblems($relevant_fields);

                // Can't use array_merge() since that destroys the existing keys
                $problem_fields = array();
                foreach ($derivation_problems as $df_id => $problem)
                    $problem_fields[$df_id] = $problem;


                // Locate the info required to run the RRUFF Reference plugin, if possible
                $related_reference_info = self::getRelatedReferenceInfo($datatype, $datarecord, $theme, $relevant_fields, $rendering_options['context']);

                // Now that the reference info has been gathered, it makes more sense to render them
                //  here instead of having twig do it...there's likely going to be overlap between
                //  the Reference A/B and the Status Notes fields
                $reference_rendering_options = array(
                    'is_top_level' => false,
                    'is_link' => true,
                    'is_datatype_admin' => $is_datatype_admin,
                    'context' => 'text'    // don't want the HTML wrappers around each reference
                );

                self::prerenderReferences(
                    $related_reference_info['prerendered_references'],
                    $related_reference_info,
                    $datarecord,
                    $datatype_permissions,
                    $datafield_permissions,
                    $token_list,
                    $reference_rendering_options,
                );

                // Now that the references have been rendered, substitute them into the Status Notes
                //  field...or create warnings specific to this field
                $status_notes_info = self::getStatusNotesInfo($relevant_fields, $related_reference_info);


                // Need to determine whether the status notes field uses the chemistry plugin...
                $status_notes_df_id = $relevant_fields['Status Notes']['id'];
                $status_notes_df = $datatype['dataFields'][$status_notes_df_id];
                foreach ($status_notes_df['renderPluginInstances'] as $rpi_id => $rpi) {
                    $rp_classname = $rpi['renderPlugin']['pluginClassName'];
                    if ( $rp_classname === 'odr_plugins.base.chemistry' ) {
                        // Fortunately, the chemistry plugin is primarily javascript, so it can more
                        //  or less co-exist with a plugin that primarily changes HTML...
                        $status_notes_info['chemistry_plugin_rpi'] = $rpi;
                        break;
                    }
                }
            }


            // Otherwise, output depends on which context the plugin is being executed from
            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                // Because the Status Notes field needs to run plugins of its own, .modify the
                //  datarecord array so twig thinks the version with the pre-rendered references
                //  is the original value of this field
                $status_notes_df_id = $relevant_fields['Status Notes']['id'];
                $datarecord['dataRecordFields'][$status_notes_df_id]['longText'][0]['value'] = $status_notes_info['value'];

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

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'is_datatype_admin' => $is_datatype_admin,

                        'plugin_fields' => $plugin_fields,
                        'problem_fields' => $problem_fields,

                        'related_reference_info' => $related_reference_info,
                        'status_notes_info' => $status_notes_info,
                    )
                );
            }
            else if ( $rendering_options['context'] === 'edit' ) {
                // TODO - the Status Notes field can run plugins in Display mode because its HTML doesn't need to be modified there...
                // TODO - ...but Edit mode requires an HTML change, so most datafield plugins wouldn't work
                // TODO - the Chemistry Plugin is the only one that's allowed to run, and only because it's hardcoded in...

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

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,

                        'plugin_fields' => $plugin_fields,
                        'problem_fields' => $problem_fields,

                        'related_reference_info' => $related_reference_info,
                        'status_notes_info' => $status_notes_info,
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
            'Mineral Display Name' => array(),
            'Mineral Display Abbreviation' => array(),
            'Chemistry Elements' => array(),
            'IMA Formula' => array(),
            'Valence Elements' => array(),
            'RRUFF Formula' => array(),

            'Reference A' => array(),
            'Reference B' => array(),
            'Status Notes' => array(),
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
     * @param array $relevant_datafields @see self::getRelevantFields()
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
        foreach ($derivations as $source_rpf => $dest_rpf) {
            if ( $relevant_datafields[$source_rpf]['value'] !== ''
                && $relevant_datafields[$dest_rpf]['value'] === ''
            ) {
                $dest_df_id = $relevant_datafields[$dest_rpf]['id'];
                $problems[$dest_df_id] = 'There seems to be a problem with the contents of the "'.$source_rpf.'" field.';
            }
        }

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
     * The derived fields for the IMA plugin (chemical elements and valence elements) need to use a
     * different template when they're reloaded in edit mode.
     *
     * @param string $rendering_context
     * @param RenderPluginInstance $render_plugin_instance
     * @param DataFields $datafield
     * @param DataRecord $datarecord
     * @param Theme $theme
     * @param ODRUser $user
     *
     * @return array
     */
    public function getOverrideParameters($rendering_context, $render_plugin_instance, $datafield, $datarecord, $theme, $user)
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

        if ( $relevant_rpf === 'Reference A' || $relevant_rpf === 'Reference B' ) {
            // Unlike the derived/unique fields, all reloads of the Reference A/B fields need to
            //  get overridden

            // Going to also need the theme array so the RRUFF Reference plugin can get rendered
            $theme_array = $this->theme_info_service->getThemeArray($theme->getParentTheme()->getId());

            // Need to filter the cached arrays so the plugin doesn't reveal non-public references
            $user_permissions = $this->permissions_service->getUserPermissionsArray($user);
            $this->permissions_service->filterByGroupPermissions($dt_array, $dr_array, $user_permissions);
            // Then need to stack the cached arrays so getRelatedReferenceInfo() can determine whether
            //  the database with the IMA plugin links to the database with the RRUFF Reference plugin
            $dt_array = $this->database_info_service->stackDatatypeArray($dt_array, $datatype->getId());
            $dr_array = $this->datarecord_info_service->stackDatarecordArray($dr_array, $datarecord->getId());
            $theme_array = $this->theme_info_service->stackThemeArray($theme_array, $theme->getId());

            $related_reference_info = self::getRelatedReferenceInfo($dt_array, $dr_array, $theme_array, $relevant_fields, $rendering_context);

            $template_name = 'ODROpenRepositoryGraphBundle:RRUFF:IMA/ima_edit_reference_datafield.html.twig';
            if ( $rendering_context === 'display' )
                $template_name = 'ODROpenRepositoryGraphBundle:RRUFF:IMA/ima_display_reference_datafield.html.twig';

            return array(
                'token_list' => array(),    // so ODRRenderService generates CSRF tokens
                'template_name' => $template_name,

                'datatype' => $dt_array,
                'rpf_name' => $relevant_rpf,
                'related_reference_info' => $related_reference_info,
            );
        }

        // Otherwise, don't want to override the default reloading for this field
        return array();
    }


    /**
     * Returns an array of which datafields are derived from which source datafields, with everything
     * identified by datafield id.
     *
     * @param array $render_plugin_instance
     *
     * @return array
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
     * Given the cached datatype/datarecord/theme arrays for a datarecord using the IMA Plugin, this
     * function attempts to locate the datarecords pointed to by the Reference A/B datafields in a
     * linked RRUFF Reference database.
     *
     * The arrays are expected to already be filtered by user permissions.
     *
     * @param array $datatype_array
     * @param array $datarecord_array
     * @param array $theme_array
     * @param array $relevant_fields
     * @param string $context
     *
     * @return array
     */
    private function getRelatedReferenceInfo($datatype_array, $datarecord_array, $theme_array, $relevant_fields, $context)
    {
        // Need to determine whether the database using this IMA plugin links to a database that
        //  uses the RRUFF Reference plugin...
        $rruff_reference_dt = null;
        $rruff_reference_dt_id = null;
        $rruff_reference_rpi = null;
        $rruff_reference_id_field = null;
        $rruff_reference_theme = null;

        if ( isset($datatype_array['descendants']) ) {
            foreach ($datatype_array['descendants'] as $dt_id => $tmp) {
                $dt = $tmp['datatype'][$dt_id];
                foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                    $rp = $rpi['renderPlugin'];
                    if ( $rp['pluginClassName'] === 'odr_plugins.rruff.rruff_references' ) {
                        // ...it does, so save some useful pieces of data
                        $rruff_reference_dt = $dt;
                        $rruff_reference_dt_id = $dt['id'];
                        $rruff_reference_rpi = $rpi;
                        $rruff_reference_id_field = $rruff_reference_rpi['renderPluginMap']['Reference ID']['id'];
                    }
                }
            }
        }

        // Since this database is properly linked to a RRUFF Reference database, the values in the
        //  'Reference A', 'Reference B', and 'Status Notes' fields of the IMA database hopefully
        //  point to specific records from the RRUFF Reference database
        $reference_mapping = array();

        // However, it's not guaranteed the the references have been linked to...
        $invalid_references = array();
        // ...or if they have been linked to, it's not guaranteed the user can see them
        $can_view_references = array();

        // If the reference exists and can be seen, then this plugin should execute the RRUFF
        //  References plugin itself, so that twig doesn't have to render the same reference more
        //  than once
        $prerendered_references = array();


        if ( !is_null($rruff_reference_rpi) ) {
            // In order to allow the plugin to tell users that "you can't see this related reference",
            //  it needs to look for the related reference in an unfiltered version of the datarecord
            $ima_dr_id = $datarecord_array['id'];
            $unfiltered_datarecord_array = $this->datarecord_info_service->getDatarecordArray($ima_dr_id);    // do want links here

            // If the IMA record links to at least one RRUFF Reference...
            if ( isset($unfiltered_datarecord_array[$ima_dr_id]['children'][$rruff_reference_dt_id]) ) {
                // ...then stack the unfiltered datarecord array to make it easier to use
                $unfiltered_datarecord_array = $this->datarecord_info_service->stackDatarecordArray($unfiltered_datarecord_array, $ima_dr_id);

                foreach ($unfiltered_datarecord_array['children'][$rruff_reference_dt_id] as $dr_id => $dr) {
                    // Each of these RRUFF Reference records should have a Reference ID field...
                    if ( isset($dr['dataRecordFields'][$rruff_reference_id_field]) ) {
                        // ...so extract the Reference ID value from the relevant field...
                        $df_ref_id = $dr['dataRecordFields'][$rruff_reference_id_field];
                        $ref_id = $df_ref_id['integerValue'][0]['value'];

                        // ...and save it for later processing
                        $reference_mapping[$ref_id] = $dr_id;
                        $can_view_references[$ref_id] = false;
                    }
                }
            }

            // Determine which references the IMA record refers to...the Reference A/B fields are
            //  supposed to point to references...
            $reference_a_value = $relevant_fields['Reference A']['value'];
            $reference_b_value = $relevant_fields['Reference B']['value'];

            if ( !is_null($reference_a_value) && $reference_a_value !== 0 )
                $prerendered_references[$reference_a_value] = 0;
            if ( !is_null($reference_b_value) && $reference_b_value !== 0 )
                $prerendered_references[$reference_b_value] = 0;

            // ...and the Status Notes field is supposed to be able to support them as well
            $status_notes_value = $relevant_fields['Status Notes']['value'];
            $status_notes_references = self::parseStatusNotesFieldValue($status_notes_value);
            foreach ($status_notes_references as $ref_id => $num)
                $prerendered_references[$ref_id] = 0;

            // Determine whether the desired RRUFF References are in $unfiltered_datarecord_array...
            foreach ($prerendered_references as $ref_id => $num) {
                if ( !isset($reference_mapping[$ref_id]) ) {
                    // ...if not, then the user should be notified of this problem...
                    $invalid_references[$ref_id] = true;
                    // ...and the IMA Plugin shouldn't attempt to render it
                    unset( $prerendered_references[$ref_id] );
                }
            }


            // The RRUFF References are supposed to be in the 'children' section of the IMA record,
            //  but the Linked Descendent Merger plugin might have "moved" them somewhere else...
            //  if so, then it will have left a copy behind under 'original_children'
            $key = 'children';
            if ( isset($datarecord_array['original_children']) )
                $key = 'original_children';

            // Determine which RRUFF References the user is allowed to view by cross-referencing
            //  the unfiltered and filtered datarecord lists...a reference they're not allowed to
            //  view will be in the unfiltered datarecord list, but not the filtered list
            if ( isset($datarecord_array[$key][$rruff_reference_dt_id]) ) {
                foreach ($datarecord_array[$key][$rruff_reference_dt_id] as $dr_id => $dr) {
                    // Each of these RRUFF Reference records should have a Reference ID field...
                    if ( isset($dr['dataRecordFields'][$rruff_reference_id_field]) ) {
                        // ...so extract the Reference ID value from the relevant field
                        $df_ref_id = $dr['dataRecordFields'][$rruff_reference_id_field];
                        $ref_id = $df_ref_id['integerValue'][0]['value'];

                        // Since the reference exists in the already-filtered $datarecord_array, the
                        //  user can see this record
                        $can_view_references[$ref_id] = true;

                        // If the IMA plugin is being called from the Edit context, then the plugin
                        //  should also render all the references this IMA record links to
                        if ( $context === 'edit' )
                            $prerendered_references[$ref_id] = 0;
                    }
                }
            }

            // Also need to extract the theme for the RRUFF Reference datatype so that the render
            //  plugin for that datatype can be executed correctly
            foreach ($theme_array['themeElements'] as $num => $te) {
                if ( isset($te['themeDataType']) ) {
                    $tdt = $te['themeDataType'][0];
                    if ( $tdt['dataType']['id'] === $rruff_reference_dt_id )
                        $rruff_reference_theme = $tdt['childTheme']['theme'];
                }
            }
        }

        return array(
            'datatype' => $rruff_reference_dt,
            'id_field' => $rruff_reference_id_field,
            'theme' => $rruff_reference_theme,
            'renderPluginInstance' => $rruff_reference_rpi,

            'reference_mapping' => $reference_mapping,
            'invalid_references' => $invalid_references,
            'can_view_references' => $can_view_references,
            'prerendered_references' => $prerendered_references,
        );
    }


    /**
     * Extracts reference IDs from the value of the Status Notes field.
     *
     * @param string $value
     * @return array reference_ids are keys
     */
    private function parseStatusNotesFieldValue($value)
    {
        $reference_ids = array();

        // TODO - turn the placeholder '?:' into a renderPluginOption?
        $pattern = '/\?:([\d]+)/';
        $matches = array();
        if ( preg_match_all($pattern, $value, $matches) !== false ) {
            foreach ($matches[1] as $num => $reference_id)
                $reference_ids[$reference_id] = 0;
        }

        return $reference_ids;
    }


    /**
     * Renders a subset of the RRUFF Reference records that the given IMA datarecord links to, so
     * that twig doesn't have to potentially render the same reference more than once.
     *
     * @param array $prerendered_references
     * @param array $related_reference_info @see self::getRelatedReferenceInfo()
     * @param array $ima_datarecord
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param array $token_list
     * @param array $rendering_options
     */
    private function prerenderReferences(&$prerendered_references, $related_reference_info, $ima_datarecord, $datatype_permissions, $datafield_permissions, $token_list, $rendering_options)
    {
        // The RRUFF References are supposed to be in the 'children' section of the IMA record,
        //  but the Linked Descendent Merger plugin might have "moved" them somewhere else...
        //  if so, then it will have left a copy behind under 'original_children'
        $key = 'children';
        if ( isset($ima_datarecord['original_children']) )
            $key = 'original_children';

        // The IMA record may not link to any RRUFF References...
        $rruff_reference_dt_id = $related_reference_info['datatype']['id'];
        if ( !isset($ima_datarecord[$key][$rruff_reference_dt_id]) ) {
            // ...if it doesn't, then there's nothing to pre-render
            return;
        }

        // ...but if it does, then going to attempt to pre-render the references
        $can_view_references = $related_reference_info['can_view_references'];
        $rruff_reference_records = $ima_datarecord[$key][$rruff_reference_dt_id];

        foreach ($prerendered_references as $ref_id => $num) {
            // Determine which ODR record this reference ID is referring to
            $reference_dr_id = $related_reference_info['reference_mapping'][$ref_id];

            // If the user can view the requested reference...
            if ( $can_view_references[$ref_id] ) {
                // ...then render it...
                $reference_dr = $rruff_reference_records[$reference_dr_id];

                /** @var DatatypePluginInterface $rruff_reference_plugin */
                $rruff_reference_plugin = $this->container->get('odr_plugins.rruff.rruff_references');
                $content = $rruff_reference_plugin->execute(
                    array($reference_dr_id => $reference_dr),
                    $related_reference_info['datatype'],
                    $related_reference_info['renderPluginInstance'],
                    $related_reference_info['theme'],
                    $rendering_options,
                    $ima_datarecord,
                    $datatype_permissions,
                    $datafield_permissions,
                    $token_list
                );

                // ...and store it for later use
                $prerendered_references[$ref_id] = $content;
            }
            else {
                // ...if the user can't view the requested reference, then get rid of the array entry
                //  so twig can inform the user of their lack of permissions
                unset( $prerendered_references[$ref_id] );
            }
        }
    }


    /**
     * Attempts to substitute any reference placeholders in the Status Notes value with the
     * rendered form of the RRUFF Reference, and creates warning blurbs if it can't.
     *
     * @param array $relevant_fields @see self::getRelevantFields()
     * @param array $related_reference_info @see self::getRelatedReferenceInfo()
     *
     * @return array
     */
    private function getStatusNotesInfo($relevant_fields, $related_reference_info)
    {
        // The value of the status notes field has already been found...
        $status_notes = $relevant_fields['Status Notes'];
        $status_notes_value = $status_notes['value'];
        $status_notes_warnings = array();

        // Locate any reference ids in the status notes
        $reference_ids = self::parseStatusNotesFieldValue($status_notes_value);

        // The field may not have any references in it
        if ( !empty($reference_ids) ) {
            // ...but if it does, then they need to be replaced with the pre-rendered reference text
            $prerendered_references = $related_reference_info['prerendered_references'];

            // Sort the reference ids in descending order to ensure a placeholder like '?:1' doesn't
            //  clobber a placeholder like '?:100'
            krsort($reference_ids);
            foreach ($reference_ids as $ref_id => $num) {
                // The pre-rendered references aren't guaranteed to have this reference...the user
                //  might not be able to see this reference, or they could've entered an id for a
                //  reference that the IMA record doesn't link to
                if ( isset($prerendered_references[$ref_id]) ) {
                    // If they can see the reference, then replace the placeholder in the Status
                    //  Notes field with the pre-rendered reference
                    // TODO - turn the placeholder '?:' into a renderPluginOption?
                    $placeholder = '?:'.$ref_id;
                    $status_notes_value = str_replace($placeholder, $prerendered_references[$ref_id], $status_notes_value);
                }
                else {
                    // ...otherwise, generate a warning depending on whether the reference is missing
                    //  due to not being linked, or "missing" because they can't see it
                    if ( isset($related_reference_info['invalid_references'][$ref_id]) )
                        $status_notes_warnings[] = 'The RRUFF Reference with the ID '.$ref_id.' is not linked to this IMA Record.';
                    else
                        $status_notes_warnings[] = 'You are not permitted to view the RRUFF Reference with the ID '.$ref_id.'.';
                }
            }
        }

        return array(
            'value' => $status_notes_value,
            'warnings' => $status_notes_warnings,
        );
    }


    /**
     * Returns an array of datafields where MassEdit should enable the abiilty to run a background
     * job without actually changing their values.
     *
     * @param array $render_plugin_instance
     * @return array An array where the values are datafield ids
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
     * The MassEdit system generates a checkbox for each RenderPlugin that returns something from
     * self::getMassEditOverrideFields()...if the user selects the checkbox, then certain RenderPlugins
     * may not want to activate if the user has also entered a value in the relevant field.
     *
     * For each datafield affected by this RenderPlugin, this function returns true if the plugin
     * should always be activated, or false if it should only be activated when the user didn't
     * also enter a value into the field.
     *
     * @param array $render_plugin_instance
     * @return array
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
}
