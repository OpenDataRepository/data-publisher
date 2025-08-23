<?php

/**
 * Open Data Repository Data Publisher
 * Child RRUFF ID Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Continuing the "honored" tradition of doing horrible things to ODR, this plugin attempts to locate
 * a value from an arbitrary (user-supplied) field and copy it (with an optional append string) into
 * a field of a newly created record.
 *
 * Because this is run as part of the event system, any exceptions thrown as a result of bad configs
 * can't be displayed to the user...all they'll see is a blank value.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class ChildRRUFFIDPlugin implements DatafieldPluginInterface
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
     * Child RRUFF ID Plugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param EntityCreationService $entity_create_service
     * @param LockService $lock_service
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
        LockService $lock_service,
        EventDispatcherInterface $event_dispatcher,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->database_info_service = $database_info_service;
        $this->datarecord_info_service = $datarecord_info_service;
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
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // This Plugin should work in the 'fake_edit' context
            if ( $context === 'fake_edit' )
                return true;
        }

        return false;
    }


    /**
     * Executes the plugin on the provided datafield
     *
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $render_plugin_instance
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $rendering_options)
    {
        try {
            // ----------------------------------------
            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            $dr_id = $datarecord['id'];
            $df_id = $datafield['id'];
            $typeclass = $datafield['dataFieldMeta']['fieldType']['typeClass'];

            // ----------------------------------------
            // Need to generate both regular and special tokens?
            $token_id = $typeclass.'Form_'.$dr_id.'_'.$df_id;
            $token_list[$dr_id][$df_id] = $this->token_manager->getToken($token_id)->getValue();

            // Also need to provide a special token so the "mineral_id" field won't get ignored
            //  by FakeEdit because it prevents user edits...
            $token_id = 'FakeEdit_'.$datarecord['id'].'_'.$df_id.'_autogenerated';
            $token = $this->token_manager->getToken($token_id)->getValue();
            $special_tokens[$df_id] = $token;

            $output = "";
            if ( $rendering_options['context'] === 'fake_edit' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:ChildRRUFFID/childrruffid_fake_edit_datafield.html.twig',
                    array(
                        'datarecord' => $datarecord,
                        'datafield' => $datafield,

                        'is_link' => $rendering_options['is_link'],
                        'token_list' => $token_list,
                        'special_tokens' => $special_tokens,
                        'is_datatype_admin' => $rendering_options['is_datatype_admin'],
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
        // Pull some required data from the event
        $user = $event->getUser();
        $new_datarecord = $event->getDatarecord();
        $datatype = $new_datarecord->getDataType();
        $linked_datarecord_ancestor = $event->getLinkedAncestorDatarecord();

        // Need to locate the "Child RRUFF ID" field for this render plugin...
        $query =
           'SELECT rpi.id AS rpi_id, rpi_df.id AS df_id
            FROM odr_render_plugin rp
            JOIN odr_render_plugin_instance rpi ON rpi.render_plugin_id = rp.id
            JOIN odr_render_plugin_map rpm ON rpm.render_plugin_instance_id = rpi.id
            JOIN odr_render_plugin_fields rpf ON rpm.render_plugin_fields_id = rpf.id
            JOIN odr_data_fields rpi_df ON rpm.data_field_id = rpi_df.id
            WHERE rp.plugin_class_name = :plugin_classname AND rpi_df.data_type_id = :datatype_id
            AND rpf.field_name = :field_name
            AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL
            AND rpf.deletedAt IS NULL AND rpi_df.deletedAt IS NULL';
        $params =  array(
            'plugin_classname' => 'odr_plugins.rruff.child_rruff_id',
            'datatype_id' => $datatype->getId(),
            'field_name' => 'Child RRUFF ID Field'
        );

        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        $df_id = $rpi_id = null;
        foreach ($results as $result) {
            if ( is_null($df_id) ) {
                $df_id = $result['df_id'];
                $rpi_id = $result['rpi_id'];
            }
            else
                throw new ODRException('Unable to find the "Child RRUFF ID" field for the RenderPlugin "Child RRUFF ID", attached to Datatype '.$datatype->getId());
        }
        if ( is_null($df_id) )
            throw new ODRException('Unable to find the "Child RRUFF ID" field for the RenderPlugin "Child RRUFF ID", attached to Datatype '.$datatype->getId());

        // Will only be one result, at this point
        /** @var DataFields $child_rruff_id_df */
        $child_rruff_id_df = $this->em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
        // Already guaranteed to not be deleted


        // ----------------------------------------
        // This field's contents won't be based off the contents of a record in this datatype
        $query =
           'SELECT rpod.name AS option_name, rpom.value AS option_value
            FROM odr_render_plugin_instance rpi
            JOIN odr_render_plugin_options_map rpom ON rpom.render_plugin_instance_id = rpi.id
            JOIN odr_render_plugin_options_def rpod ON rpom.render_plugin_options_id = rpod.id
            WHERE rpi.id = :render_plugin_option_id
            AND rpi.deletedAt IS NULL AND rpom.deletedAt IS NULL AND rpod.deletedAt IS NULL';
        $params =  array(
            'render_plugin_option_id' => $rpi_id
        );
        $results = $conn->executeQuery($query, $params);

        $options = array();
        foreach ($results as $result) {
            $option_name = $result['option_name'];
            $option_value = $result['option_value'];

            $options[$option_name] = $option_value;
        }

        if ( !isset($options['parent_rruff_id_field']) || !is_numeric($options['parent_rruff_id_field']) )
            throw new ODRException('The value for the "Parent RRUFF ID" field is not a datafield id');

        /** @var DataFields $parent_rruff_id_df */
        $parent_rruff_id_df = $this->em->getRepository('ODRAdminBundle:DataFields')->find($options['parent_rruff_id_field']);
        if ($parent_rruff_id_df == null)
            throw new ODRException('The "Parent RRUFF ID" field can not be found');

        // The field needs to be related to this new record...
        self::verifySourceDatatype($parent_rruff_id_df, $new_datarecord, $linked_datarecord_ancestor);
        // ...an exception will be thrown if it's not


        // ----------------------------------------
        // Need to acquire a lock to ensure that there are no duplicate values
        $lockHandler = $this->lock_service->createLock('datatype_'.$datatype->getId().'_autogenerate_id'.'.lock', 15);    // 15 second ttl
        if ( !$lockHandler->acquire() ) {
            // Another process is in the mix...block until it finishes
            $lockHandler->acquire(true);
        }

        // Now that a lock is acquired, need to attempt to find the Parent RRUFF ID for this record
        $parent_rruff_id = '';
        if ( is_null($linked_datarecord_ancestor) )
            $parent_rruff_id = self::findSourceDatarecord($new_datarecord, $parent_rruff_id_df);
        else
            $parent_rruff_id = self::findSourceDatarecord($linked_datarecord_ancestor, $parent_rruff_id_df);

        if ( $parent_rruff_id !== '' ) {
            $child_rruff_id = $parent_rruff_id;
            if ( isset($options['child_rruff_id_postfix']) && $options['child_rruff_id_postfix'] != '' )
                $child_rruff_id .= $options['child_rruff_id_postfix'];

            $this->entity_create_service->createStorageEntity($user, $new_datarecord, $child_rruff_id_df, $child_rruff_id, false);    // guaranteed to not need a PostUpdate event
            $this->logger->debug('Setting df '.$child_rruff_id_df->getId().' "Child RRUFF ID" of new dr '.$new_datarecord->getId().' to "'.$child_rruff_id.'"...', array(self::class, 'onDatarecordCreate()'));
        }

        // No longer need the lock
        $lockHandler->release();


        // ----------------------------------------
        // Fire off events notifying that the modification of the datafield is done
        try {
            $event = new DatafieldModifiedEvent($child_rruff_id_df, $user);
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
     * @param DataFields $parent_rruff_id_df
     * @param DataRecord $new_datarecord
     * @param DataRecord|null $linked_datarecord_ancestor
     * @return void
     */
    private function verifySourceDatatype($parent_rruff_id_df, $new_datarecord, $linked_datarecord_ancestor)
    {
        // This needs to be able to locate the ancestor regardless of whether $new_datarecord is a
        //  child or a linked descendant record
        $new_datarecord_dt_id = $new_datarecord->getDataType()->getId();

        $is_linked = false;
        $grandparent_datatype_id = $new_datarecord->getDataType()->getGrandparent()->getId();
        if ( !is_null($linked_datarecord_ancestor) ) {
            $is_linked = true;
            $grandparent_datatype_id = $linked_datarecord_ancestor->getDataType()->getGrandparent()->getId();
        }

        // Going to use the cached datatype array to verify that $target_datafield is valid for
        //  this plugin...
        $datatype_array = $this->database_info_service->getDatatypeArray($grandparent_datatype_id, false);    // don't want links

        // The datafield must belong to a datatype in this array...
        $target_datatype_id = $parent_rruff_id_df->getDataType()->getId();
        if ( !isset($datatype_array[$target_datatype_id]) )
            throw new ODRBadRequestException('The "Parent RRUFF ID Field" does not belong to a related Datatype');

        // ...and if $target_datafield isn't off in a linked ancestor of the new datarecord...
        if ( !$is_linked ) {
            // ...then $target_datafield also can't belong to the same dataype as the new datarecord
            if ( $target_datatype_id == $new_datarecord_dt_id )
                throw new ODRBadRequestException('The "Parent RRUFF ID Field" cannot belong to the same Datatype as the new Datarecord');
        }

        // These still don't guarantee that it's a valid field though...the datatype the field
        //  belongs to needs to be an ancestor of the new datarecord's datatype
        $datatypes_to_check = array($target_datatype_id);
        while ( !empty($datatypes_to_check) ) {
            $tmp = array();
            foreach ($datatypes_to_check as $num => $dt_id) {
                if ( isset($datatype_array[$dt_id]['descendants']) ) {
                    foreach ($datatype_array[$dt_id]['descendants'] as $child_dt_id => $props)
                        $tmp[] = $child_dt_id;
                }
            }

            if ( in_array($new_datarecord_dt_id, $tmp) ) {
                // The new datarecord is a descendent somehow of the datatype that contains the
                //  Parent RRUFF ID field...don't need to continue looking
                return;
            }

            // Otherwise, check the next set of descendants
            $datatypes_to_check = $tmp;
        }

        // ...if this point is reached, then the Parent RRUFF ID field is not in a datatype that is
        //  a direct ancestor of the new datarecord
        throw new ODRBadRequestException('The "Parent RRUFF ID Field" is not an ancestor of the new Datarecord');
    }


    /**
     * @param DataRecord $datarecord
     * @param DataFields $parent_rruff_id_df
     * @return string
     */
    private function findSourceDatarecord($datarecord, $parent_rruff_id_df)
    {
        // Might as well use the cached datarecord array to actually locate the value for the Parent
        //  RRUFF ID field
        $grandparent_datarecord_id = $datarecord->getGrandparent()->getId();
        $datarecord_array = $this->datarecord_info_service->getDatarecordArray($grandparent_datarecord_id, false);

        // Because self::verifySourceDatatype() has already verified that the Parent RRUFF ID is in
        //  a valid ancestor datatype, logic isn't needed when attempting to find the value
        $df_id = $parent_rruff_id_df->getId();
        foreach ($datarecord_array as $dr_id => $dr) {
            if ( isset($dr['dataRecordFields'][$df_id]) ) {
                $df = $dr['dataRecordFields'][$df_id];
                if ( isset($df['shortVarchar'][0]['value']) )
                    return $df['shortVarchar'][0]['value'];
                else if ( isset($df['mediumVarchar'][0]['value']) )
                    return $df['mediumVarchar'][0]['value'];
            }
        }

        // Otherwise, the ancestor record doesn't have a value for this field
        return '';
    }
}
