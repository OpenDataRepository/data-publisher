<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Sample Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Due to design decisions, "auto-incrementing" of ID fields for databases is going to be handled
 * via render plugins.  As such, a plugin is needed to hold the logic for generating an ID once a
 * datarecord has been created.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Entities
use ODR\AdminBundle\Entity\DataFields;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class RRUFFSamplePlugin implements DatatypePluginInterface
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
     * RRUFF Sample Plugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param EntityCreationService $entity_create_service
     * @param LockService $lock_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param CsrfTokenManager $token_manager
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        EntityCreationService $entity_create_service,
        LockService $lock_service,
        EventDispatcherInterface $event_dispatcher,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->entity_create_service = $entity_create_service;
        $this->lock_service = $lock_service;
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
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // This render plugin is only allowed to work when in fake_edit mode
            if ( $context === 'fake_edit' )
                return true;
        }

        return false;
    }


    /**
     * Executes the RRUFF Sample Plugin on the provided datarecords
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
            $rpf_df_id = null;
            $plugin_fields = array();
            $fields_to_autogenerate = array();
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                // Autogenerated fields will continue to work even if the user can't see them,
                //  but probably should still complain about plugin mapping errors to datatype admins
                if ($df == null && $is_datatype_admin)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                // Need to tweak display parameters for several of the fields...
                $plugin_fields[$rpf_df_id] = $rpf_df;
                $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;

                // Save which fields need to have special tokens for FakeEdit
                if ( isset($rpf_df['properties']['autogenerate_values']) && $rpf_df['properties']['autogenerate_values'] === 1 )
                    $fields_to_autogenerate[] = $rpf_df_id;
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

            // Also need to provide a special token so the "Database ID" field won't get ignored
            //  by FakeEdit because it prevents user edits...
            $special_tokens = array();
            foreach ($fields_to_autogenerate as $num => $df_id) {
                $token_id = 'FakeEdit_'.$datarecord['id'].'_'.$df_id.'_autogenerated';
                $token = $this->token_manager->getToken($token_id)->getValue();
                $special_tokens[$df_id] = $token;
            }


            // ----------------------------------------
            if ( $rendering_options['context'] === 'fake_edit' ) {
                // When in fake_edit mode, use the plugin's override
                return $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFSample/rruff_sample_fakeedit_fieldarea.html.twig',
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
            else {
                // Otherwise, if this gets called for some reason, just return empty string so ODR's
                //  regular templating takes over
                return '';
            }
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
        // Pull some required data from the event
        $user = $event->getUser();
        $datarecord = $event->getDatarecord();
        $datatype = $datarecord->getDataType();

        // Need to locate the "sample_id" and "rruff_id" fields for this render plugin...
        $query = $this->em->createQuery(
           'SELECT rpf.fieldName, df
            FROM ODRAdminBundle:RenderPlugin rp
            JOIN ODRAdminBundle:RenderPluginInstance rpi WITH rpi.renderPlugin = rp
            JOIN ODRAdminBundle:RenderPluginMap rpm WITH rpm.renderPluginInstance = rpi
            JOIN ODRAdminBundle:DataFields df WITH rpm.dataField = df
            JOIN ODRAdminBundle:RenderPluginFields rpf WITH rpm.renderPluginFields = rpf
            WHERE rp.pluginClassName = :plugin_classname AND rpi.dataType = :datatype
            AND rpf.fieldName IN (:field_names)
            AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL
            AND df.deletedAt IS NULL'
        )->setParameters(
            array(
                'plugin_classname' => 'odr_plugins.rruff.rruff_sample',
                'datatype' => $datatype->getId(),
                'field_names' => array('Sample ID', 'RRUFF ID'),
            )
        );
        $results = $query->getResult();

        $fields = array();
        foreach ($results as $result) {
            $df = $result[0];
            $rpf_name = $result['fieldName'];

            $fields[$rpf_name] = $df;
        }
        if ( !isset($fields['Sample ID']) )
            throw new ODRException('Unable to find the "Sample ID" field for the RenderPlugin "RRUFF Sample", attached to Datatype '.$datatype->getId());
        if ( !isset($fields['RRUFF ID']) )
            throw new ODRException('Unable to find the "RRUFF ID" field for the RenderPlugin "RRUFF Sample", attached to Datatype '.$datatype->getId());
        /** @var DataFields[] $fields */

        // ----------------------------------------
        // Need to run another query to figure out what the "prefix" for the RRUFF ID should be...
        $query = $this->em->createQuery(
           'SELECT rpod.name AS option_name, rpom.value AS option_value
            FROM ODRAdminBundle:RenderPlugin rp
            JOIN ODRAdminBundle:RenderPluginInstance rpi WITH rpi.renderPlugin = rp
            JOIN ODRAdminBundle:RenderPluginOptionsMap rpom WITH rpom.renderPluginInstance = rpi
            JOIN ODRAdminBundle:RenderPluginOptionsDef rpod WITH rpom.renderPluginOptionsDef = rpod
            WHERE rp.pluginClassName = :plugin_classname AND rpi.dataType = :datatype
            AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL
            AND rpom.deletedAt IS NULL AND rpod.deletedAt IS NULL'
        )->setParameters(
            array(
                'plugin_classname' => 'odr_plugins.rruff.rruff_sample',
                'datatype' => $datatype->getId(),
            )
        );
        $results = $query->getResult();

        $options = array();
        foreach ($results as $result) {
            $option_name = $result['option_name'];
            $option_value = $result['option_value'];

            $options[$option_name] = $option_value;
        }


        // ----------------------------------------
        // Need to acquire a lock to ensure that there are no duplicate values
        $lockHandler = $this->lock_service->createLock('datatype_'.$datatype->getId().'_autogenerate_id'.'.lock', 15);    // 15 second ttl
        if ( !$lockHandler->acquire() ) {
            // Another process is in the mix...block until it finishes
            $lockHandler->acquire(true);
        }

        // Now that a lock is acquired, need to find the "most recent" value for the fields that are
        //  getting incremented...
        foreach ($fields as $rpf_name => $df) {
            $new_value = '';
            if ( $rpf_name === 'Sample ID' )
                $new_value = self::getNewSampleIDValue($df->getId());
            else if ( $rpf_name === 'RRUFF ID' )
                $new_value = self::getNewRRUFFIDValue($df->getId(), $options);

            // Create a new storage entity with the new value
            $this->entity_create_service->createStorageEntity($user, $datarecord, $df, $new_value, false);    // guaranteed to not need a PostUpdate event
            $this->logger->debug('Setting df '.$df->getId().' "Sample ID" of new dr '.$datarecord->getId().' to "'.$new_value.'"...', array(self::class, 'onDatarecordCreate()'));
        }

        // No longer need the lock
        $lockHandler->release();


        // ----------------------------------------
        // Fire off events notifying that the modification of the datafield is done
        foreach ($fields as $rpf_name => $df) {
            try {
                $event = new DatafieldModifiedEvent($df, $user);
                $this->event_dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }
        }
    }


    /**
     * Don't particularly like random render plugins finding random stuff from the database, but
     * there's no other way to satisfy the design requirements...
     *
     * @param int $datafield_id
     *
     * @return int
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function getNewSampleIDValue($datafield_id)
    {
        // Going to use native SQL...DQL can't use limit without using querybuilder...
        // NOTE - deleting a record doesn't delete the related storage entities, so deleted samples
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

        // ...but if there's not for some reason, then use zero as the "current"
        if ( is_null($current_value) )
            $current_value = 0;

        $new_value = $current_value + 1;
        return $new_value;
    }


    /**
     * Don't particularly like random render plugins finding random stuff from the database, but
     * there's no other way to satisfy the design requirements...
     *
     * @param int $datafield_id
     * @param array $options
     *
     * @return string
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function getNewRRUFFIDValue($datafield_id, $options)
    {
        $prefix = $options['rruff_id_prefix'];
        if ( $prefix === '' )
            return '';

        // Going to use native SQL...DQL can't use limit without using querybuilder...
        // NOTE - deleting a record doesn't delete the related storage entities, so deleted samples
        //  are still considered in this query
        $query =
           'SELECT e.value
            FROM odr_short_varchar e
            WHERE e.data_field_id = :datafield AND e.value LIKE :prefix
            AND e.deletedAt IS NULL
            ORDER BY e.value DESC
            LIMIT 0,1';
        $params = array(
            'datafield' => $datafield_id,
            'prefix' => $prefix.'%'
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        // Should only be one value in the result...
        $current_value = null;
        foreach ($results as $result)
            $current_value = $result['value'];

        if ( is_null($current_value) ) {
            // If there's no value, then start incrementing from zero
            $current_value = 0;
        }
        else {
            // If there is a value...
            $num = substr($current_value, 4);
            $current_value = intval($num);
        }

        // The integer needs to be incremented by one...
        $new_value = strval($current_value + 1);
        $new_value = str_pad($new_value, 4, '0', STR_PAD_LEFT);

        // ...and then spliced into a string that includes the prefix and the year
        $now = new \DateTime();
        $year = $now->format('y');

        $str = $prefix.$year.$new_value;
        return $str;
    }
}
