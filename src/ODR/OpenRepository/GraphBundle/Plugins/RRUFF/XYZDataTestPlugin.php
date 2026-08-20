<?php

/**
 * Open Data Repository Data Publisher
 * AMCSD Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This plugin attempts to mimic the original behavior of the American Mineralogy Crystal Structure
 * Database (AMCSD).  The plugin itself just blocks editing of most of its required fields, since
 * they're technically derived from the contents of the AMC/CIF files.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// ODR
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\XYZData;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\FileDeletedEvent;
use ODR\AdminBundle\Component\Event\FilePreEncryptEvent;
use ODR\AdminBundle\Component\Event\MassEditTriggerEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\AdminBundle\Component\Service\XYZDataHelperService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\GraphBundle\Plugins\CrystallographyDef;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class XYZDataTestPlugin implements DatatypePluginInterface, MassEditTriggerEventInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CryptoService
     */
    private $crypto_service;

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
     * @var XYZDataHelperService
     */
    private $xyzdata_helper_service;

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
     * AMCSDPlugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param CryptoService $crypto_service
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param EntityCreationService $entity_create_service
     * @param EntityMetaModifyService $entity_modify_service
     * @param LockService $lock_service
     * @param XYZDataHelperService $xyzdata_helper_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param CsrfTokenManager $token_manager
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CryptoService $crypto_service,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        EntityCreationService $entity_create_service,
        EntityMetaModifyService $entity_modify_service,
        LockService $lock_service,
        XYZDataHelperService $xyzdata_helper_service,
        EventDispatcherInterface $event_dispatcher,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->crypto_service = $crypto_service;
        $this->database_info_service = $database_info_service;
        $this->datarecord_info_service = $datarecord_info_service;
        $this->entity_create_service = $entity_create_service;
        $this->entity_modify_service = $entity_modify_service;
        $this->lock_service = $lock_service;
        $this->xyzdata_helper_service = $xyzdata_helper_service;
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
        // don't need to actually execute this plugin outside of the massedit context
        return false;
    }


    /**
     * @inheritDoc
     */
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {
        try {
            // not overriding rendering anything
            return '';
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Due to need to check the existing data a couple different ways, it makes more sense to get
     * all the data in its own function.
     *
     * @param array $datarecord
     * @return array
     */
    private function getValueMapping($datarecord)
    {
        $value_mapping = array();
        foreach ($datarecord['dataRecordFields'] as $df_id => $drf) {
            // Don't want to have to locate typeclass...
            unset( $drf['dataField'] );
            unset( $drf['id'] );
            unset( $drf['created'] );
            unset( $drf['image'] );

            if ( count($drf) === 1 ) {
                // This is a file datafield...
                if ( !empty($drf['file']) )
                    // ...has something uploaded
                    $value_mapping[$df_id] = $drf['file'][0];
                else
                    // ...doesn't have anything uploaded
                    $value_mapping[$df_id] = array();
            }
            else if ( isset($drf['xyzData']) ) {
                // XYZData Fields will have two entries remaining at this time
                if ( count($drf['xyzData']) > 0 ) {
                    // Don't want to actually parse this stuff...just give it a non-empty value so
                    //  that self::difFileHasProblem() doesn't complain
                    $value_mapping[$df_id] = '1';
                }
                else {
                    $value_mapping[$df_id] = '';
                }
            }
            else {
                // Not a file datafield, but don't want to have to locate typeclass, so...
                unset( $drf['file'] );
                foreach ($drf as $typeclass => $entity) {
                    // Should only be one entry left in typeclass
                    if ( !empty($entity) && isset($entity[0]['value']) )
                        $value_mapping[$df_id] = $entity[0]['value'];
                    else
                        $value_mapping[$df_id] = '';
                }
            }
        }

        return $value_mapping;
    }


    /**
     * Determines whether the "AMC File" field of a datatype using the AMCSD render plugin just got
     * processed by MassEdit...if so, the file is read again, and the values from the file saved
     * into other datafields required by the render plugin.
     *
     * @param MassEditTriggerEvent $event
     *
     * @throws \Exception
     */
    public function onMassEditTrigger(MassEditTriggerEvent $event)
    {
        // Listening to this event is only useful because of the possibility of plugin changes
        // Generally, re-reading a file doesn't really do anything of value
//        return;

        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $relevant_rpf_name = false;

        $user = null;
        $datafield = null;
        $datarecord = null;
        $storage_entities = array();

        try {
            // Get entities related to the file
            $drf = $event->getDataRecordFields();
            $datarecord = $drf->getDataRecord();
            $datafield = $drf->getDataField();
            $datatype = $datafield->getDataType();
            $user = $event->getUser();

            // Only care about the various file fields of a datatype using the AMCSD render plugin...
            $relevant_rpf_name = self::isEventRelevant($datafield);
            if ( $relevant_rpf_name ) {
                // ----------------------------------------
                // This file was uploaded to the correct field, so it now needs to be processed

                // Since this is guaranteed to be a file field that only allows a single upload,
                //  the query below should get it...
                $query = $this->em->createQuery(
                   'SELECT f
                    FROM ODRAdminBundle:File f
                    WHERE f.dataRecordFields = :drf
                    AND f.deletedAt IS NULL'
                )->setParameters( array('drf' => $drf->getId()) );
                $tmp = $query->getResult();

                // ...only continue if there is a file uploaded to this field
                if ( !empty($tmp) ) {
                    $file = $tmp[0];
                    /** @var File $file */
                    $this->logger->debug('Attempting to read file '.$file->getId().' "'.$file->getOriginalFileName().'" from the "'.$relevant_rpf_name.'"...', array(self::class, 'onMassEditTrigger()', 'File '.$file->getId()));


                    // ----------------------------------------
                    // Create as much of the mappings as possible, since they could be needed during
                    //  error recovery...

                    // Map the field definitions in the render plugin to datafields
                    $datafield_mapping = self::getRenderPluginFieldsMapping($datatype);

                    if ( $relevant_rpf_name === 'File' ) {
                        // ...then want $datafield to refer to the XYZData datafield instead of the DIF
                        //  File field ASAP in case of an error
                        $df_id = $datafield_mapping['XYZData Field'];

                        /** @var DataFields $xyz_df */
                        $xyz_df = $this->em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
                        if ( $xyz_df == null )
                            throw new ODRNotFoundException('Datafield');

                        $datafield = $xyz_df;
                    }

                    // Need to hydrate the storage entities for each datafield so the values from the
                    //  file can get saved into the database
                    $storage_entities = self::hydrateStorageEntities($datafield_mapping, $user, $datarecord, $relevant_rpf_name);


                    // ----------------------------------------
                    // The provided file may not exist on the server...
                    $local_filepath = $this->crypto_service->decryptFile($file->getId());
                    // ...once it does, open it
                    $handle = fopen($local_filepath, 'r');
                    if ($handle === false)
                        throw new \Exception('Unable to open existing file at "'.$local_filepath.'"');

                    $value_mapping = array();
                    if ( $relevant_rpf_name === 'File' ) {
                        // Extract as many pieces of data from the file as possible
                        $value_mapping = self::readDIFFile($handle);
                    }

                    // No longer need the file to be open
                    fclose($handle);

                    // If the File isn't public, then delete its decrypted version off the server
                    if ( !$file->isPublic() )
                        unlink($local_filepath);


                    // ----------------------------------------
                    if ( $relevant_rpf_name === 'File' ) {
                        // Should only be one piece of data...
                        foreach ($value_mapping as $rpf_name => $value) {
                            $this->xyzdata_helper_service->updateXYZData(
                                $user,
                                $datarecord,
                                $datafield,    // NOTE: this is now the XYZData datafield, not the DIF File datafield
                                new \DateTime(),
                                $value,
                                true    // Ensure the field's contents are completely replaced
                            );
                            $this->logger->debug(' -- updating XYZData datafield '.$datafield->getId().' ('.$rpf_name.') to have the value "'.$value.'"', array(self::class, 'onMassEditTrigger()', 'File '.$file->getId()));
                        }
                    }
                }
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onMassEditTrigger()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( $relevant_rpf_name ) {
                $this->logger->debug('All changes saved from "'.$relevant_rpf_name.'"', array(self::class, 'onMassEditTrigger()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));
                self::clearCacheEntries($datarecord, $user, $storage_entities);
            }
        }
    }


    /**
     * Returns whether the given datafield is one of the file datafields of a datatype that's using
     * the AMCSD render plugin.
     *
     * @param DataFields $datafield
     *
     * @return string|bool
     */
    private function isEventRelevant($datafield)
    {
        // Going to use the cached datatype array to locate the correct datafield...
        $datatype = $datafield->getDataType();
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links
        if ( !isset($dt_array[$datatype->getId()]['renderPluginInstances']) )
            return false;

        foreach ($dt_array[$datatype->getId()]['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.xyzdata_test' ) {
                // Datatype is using the correct plugin...
                if ( isset($rpi['renderPluginMap']['File'])
                    && $rpi['renderPluginMap']['File']['id'] === $datafield->getId()
                ) {
                    // ...and the datafield that triggered the event is the "File" datafield
                    return 'File';
                }
            }
        }

        // Otherwise, the event is on some other field...the plugin can ignore it
        return false;
    }


    /**
     * Uses the cached datatype array to create a mapping from the "name" property of the fields
     * defined in the "required_fields" section of AMCSDPlugin.yml to the datafield id that is
     * mapped to that specific renderpluginfield.
     *
     * @param DataType $datatype
     *
     * @return array
     */
    private function getRenderPluginFieldsMapping($datatype)
    {
        // Going to use the cached datatype array for this
        $datatype_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want linked datatypes
        $dt = $datatype_array[$datatype->getId()];

        // The datatype could be using multiple render plugins, so need to find the mapping specifically
        //  for the AMCSD plugin...it's already verified to exist due to self::isEventRelevant()
        $renderPluginMap = null;
        foreach( $dt['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.xyzdata_test' ) {
                $renderPluginMap = $rpi['renderPluginMap'];
                break;
            }
        }

        $datafield_mapping = array();
        foreach ($renderPluginMap as $rpf_name => $rpf_df) {
            $rpf_df_id = $rpf_df['id'];

            switch ($rpf_name) {
                case 'File':
                case 'XYZData Field':
                    $datafield_mapping[$rpf_name] = $rpf_df_id;
                    break;

                default:
                    break;
            }
        }

        return $datafield_mapping;
    }


    /**
     * Using the mapping generated by self::getRenderPluginFieldsMapping(), ensures that a storage
     * entity exists for each mapped datafield.
     *
     * @param array $datafield_mapping
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param string $relevant_rpf_name
     *
     * @throws \Exception
     *
     * @return array
     */
    private function hydrateStorageEntities($datafield_mapping, $user, $datarecord, $relevant_rpf_name)
    {
        // Need to hydrate various datafields, depending on which File field just got something uploaded...
        $df_ids = array_values($datafield_mapping);
        $query = $this->em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:Datafields AS df
            WHERE df IN (:datafield_ids)
            AND df.deletedAt IS NULL'
        )->setParameter('datafield_ids', $df_ids, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);
        $results = $query->getResult();

        // Organize the hydrated datafields by id
        $hydrated_datafields = array();
        foreach ($results as $df) {
            /** @var DataFields $df */
            $hydrated_datafields[ $df->getId() ] = $df;
        }

        // Need to sure that a storage entity exists for each of these datafields...it's highly
        //  likely they don't, and $entity_modify_service->updateStorageEntity() requires one
        $storage_entities = array();
        foreach ($datafield_mapping as $rpf_name => $df_id) {
            if ( $rpf_name === 'XYZData Field' ) {
                // Do NOT attempt to run $entity_create_service->createXYZValue()
            }
        }

        // Return the hydrated list of storage entities
        return $storage_entities;
    }


    /**
     * Reads the given DIF file, converting its contents into an array of values for an XYZData
     * field so it can be searched.
     *
     * @param resource $handle
     *
     * @return array
     */
    private function readDIFFile($handle)
    {
        $pattern = '/([\d\.]+)(?:[^\d]+)([\d\.]+)/';
        $all_values = array();

        // Ensure we're at the beginning of the file
        fseek($handle, 0, SEEK_SET);

        $header_line = -999;
        $line_num = 0;
        while ( !feof($handle) ) {
            $line = fgets($handle);
            $line_num++;

            if ( strpos($line, '#') === false ) {
                $matches = array();
                $ret = preg_match($pattern, $line, $matches);
                if ( $ret === 1 ) {
                    $x = $matches[1];
                    $y = $matches[2];
                    
                    $all_values[] = '('.$x.','.$y.')';
                }
            }
        }

        // Want all file contents in a single field
        $value_mapping['XYZData Field'] = implode("|", $all_values);

        // All data gathered, return the mapping array
        return $value_mapping;
    }


    /**
     * Wipes or updates relevant cache entries once everything is completed.
     *
     * @param DataRecord $datarecord
     * @param ODRUser $user
     * @param array $storage_entities
     */
    private function clearCacheEntries($datarecord, $user, $storage_entities)
    {
        // Because multiple datafields got updated, multiple cache entries need to be wiped
        foreach ($storage_entities as $df_id => $entity) {
            // Fire off an event notifying that the modification of the datafield is done
            try {
                $event = new DatafieldModifiedEvent($entity->getDataField(), $user);
                $this->event_dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
            }
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
    public function getMassEditOverrideFields($render_plugin_instance)
    {
        // Listening to this event is only useful because of the possibility of plugin changes
        // Generally, re-reading a file doesn't really do anything of value
//        return array();

        if ( !isset($render_plugin_instance['renderPluginMap']) )
            throw new ODRException('Invalid plugin config');

        // Only interested in overriding datafields mapped to these rpf entries
        $relevant_datafields = array(
            'File' => 1,
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
        // Listening to this event is only useful because of the possibility of plugin changes
        // Generally, re-reading a file doesn't really do anything of value
//        return array();

        // Only interested in overriding datafields mapped to these rpf entries
        $relevant_datafields = array(
            'File' => 1,
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
