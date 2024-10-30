<?php

/**
 * Open Data Repository Data Publisher
 * IMA Status Notes Plugin
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
use ODR\AdminBundle\Entity\DataFields;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
// Exceptions
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class IMAStatusNotesPlugin implements DatatypePluginInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var EntityCreationService
     */
    private $entity_create_service;

    /**
     * @var LockService
     */
    private $lock_service;

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
     * IMAStatusNotesPlugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param EntityCreationService $entity_create_service
     * @param LockService $lock_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        EntityCreationService $entity_create_service,
        LockService $lock_service,
        EventDispatcherInterface $event_dispatcher,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->entity_create_service = $entity_create_service;
        $this->lock_service = $lock_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * @inheritDoc
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        // The render plugin only needs to run in Display mode
        // While it does also do an auto-increment on the "Display Order" field when it's created,
        //  the plugin is meant for a child datatype so it doesn't need FakeEdit files

        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            if ( $context === 'display' )
                return true;
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

            // Want to locate the values for most of the mapped datafields
            $optional_fields = array(
                'Display Order' => 0,    // this one can be non-public
            );

            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null) {
                    // Optional fields don't have to exist for this plugin to work...
                    if ( isset($optional_fields[$rpf_name]) )
                        continue;

                    // ...but the actual Notes field really does need to exist for this plugin to work

                    if ( !$is_datatype_admin )
                        // There are zero compelling reasons to run the plugin if something is missing
                        return '';
                    else
                        // If a datatype admin is seeing this, then they need to fix it
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id.'...check plugin config.');
                }

                // Need to tweak display parameters for several of the fields...
                $plugin_fields[$rpf_df_id] = $rpf_df;
                $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;
            }

            // Need to locate the child/linked descendant that's using the RRUFF Reference plugin...
            $rruff_reference_dt_id = null;
            $rruff_reference_dt = null;
            if ( !empty($datatype['descendants']) ) {
                foreach ($datatype['descendants'] as $descendant_dt_id => $tdt_info) {
                    if ( isset($tdt_info['datatype'][$descendant_dt_id]) ) {
                        $descendant_dt = $tdt_info['datatype'][$descendant_dt_id];
                        foreach ($descendant_dt['renderPluginInstances'] as $rpi_id => $rpi) {
                            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.rruff_references' ) {
                                // ...found it, stop looking
                                $rruff_reference_dt = $descendant_dt;
                                $rruff_reference_dt_id = $descendant_dt_id;
                                break 2;
                            }
                        }
                    }
                }
            }

            // The RRUFF Reference descendant needs to exist for this plugin to work
            if ( is_null($rruff_reference_dt_id) ) {
                if ( !$is_datatype_admin )
                    // there are zero compelling reasons to run the plugin if something is missing
                    return '';
                else
                    // if a datatype admin is seeing this, then they need to fix it
                    throw new \Exception('Unable to locate array entry for the descendant datatype using the "RRUFF References" plugin');
            }


            // Since the rruff reference datatype exists, it also has a theme entry
            $rruff_reference_theme_array = null;
            foreach ($theme_array as $t_id => $t) {
                foreach ($t['themeElements'] as $num => $te) {
                    if ( isset($te['themeDataType']) ) {
                        $tdt = $te['themeDataType'][0];
                        $rruff_reference_theme_array = $tdt['childTheme']['theme'];
                        break 2;
                    }
                }
            }


            // ----------------------------------------
            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            foreach ($theme_array as $t_id => $t)
                $initial_theme_id = $t_id;

            // While plugin errors should only be displayed to datatype admins, datatype editors
            //  should be notified when one of these records isn't linked to a reference
            $can_edit_datarecord = false;
            if ( $is_datatype_admin || isset($datatype_permissions[$initial_datatype_id]['dr_edit']) )
                $can_edit_datarecord = true;


            // Otherwise, output depends on which context the plugin is being executed from
            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:IMAStatusNotes/ima_status_notes_display.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord_array' => $datarecords,
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
//                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'plugin_fields' => $plugin_fields,

                        'can_edit_datarecord' => $can_edit_datarecord,
                        'is_datatype_admin' => $is_datatype_admin,
                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],

                        // To make twig's life easier, directly provide the rruff reference info
                        'rruff_reference_dt_id' => $rruff_reference_dt_id,
                        'rruff_reference_dt' => $rruff_reference_dt,
                        'rruff_reference_theme_array' => $rruff_reference_theme_array,
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
     * Handles when a datarecord is created.
     *
     * @param DatarecordCreatedEvent $event
     */
    public function onDatarecordCreate(DatarecordCreatedEvent $event)
    {
        // Don't run this
        return;

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
                'plugin_classname' => 'odr_plugins.rruff.ima_status_notes',
                'datatype' => $datatype->getId(),
                'field_name' => 'Display Order'
            )
        );
        $results = $query->getResult();
        if ( count($results) !== 1 )
            throw new ODRException('Unable to find the "Display Order" field for the RenderPlugin "IMA Status Notes", attached to Datatype '.$datatype->getId());

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
        $old_value = self::findCurrentValue($datarecord, $datafield);

        // Since the "most recent" mineral id is already an integer, just add 1 to it
        $new_value = $old_value + 1;

        // Create a new storage entity with the new value
        $this->entity_create_service->createStorageEntity($user, $datarecord, $datafield, $new_value, false);    // guaranteed to not need a PostUpdate event
        $this->logger->debug('Setting df '.$datafield->getId().' "Display Order" of new dr '.$datarecord->getId().' to "'.$new_value.'"...', array(self::class, 'onDatarecordCreate()'));

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
     * For this database, the display_order needs to be autogenerated.  The caveat is that this is
     * a child datatype, so display_order should only get incremented within its parent datarecord.
     *
     * Don't particularly like random render plugins finding random stuff from the database, but
     * there's no other way to satisfy the design requirements.
     *
     * @param DataRecord $new_datarecord
     * @param DataFields $datafield
     *
     * @return int
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function findCurrentValue($new_datarecord, $datafield)
    {
        // The event provided the new datarecord...need to check against the parent datarecord
        $parent_datarecord = $new_datarecord->getParent();

        // Going to use native SQL...DQL can't use limit without using querybuilder...
        // NOTE - due to needing the datarecord in the query, this ignores deleted storage entities
        $query =
           'SELECT dr.parent_id AS parent_dr_id, dr.id AS dr_id, e.value AS value
            FROM odr_integer_value e
            LEFT JOIN odr_data_record_fields drf ON e.data_record_fields_id = drf.id
            LEFT JOIN odr_data_record dr ON drf.data_record_id = dr.id
            WHERE e.data_field_id = :datafield AND dr.parent_id = :parent_datarecord
            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL
            ORDER BY e.value DESC
            LIMIT 0,1';
        $params = array(
            'parent_datarecord' => $parent_datarecord->getId(),
            'datafield' => $datafield->getId(),
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        // Should only be one value in the result...
        $current_value = null;
        foreach ($results as $result)
            $current_value = intval( $result['value'] );

        // ...but if there's not for some reason, return zero as the "current".  onDatarecordCreate()
        //  will increment it so that the value one is what will actually get saved.
        if ( is_null($current_value) )
            $current_value = 0;

        return $current_value;
    }

}
