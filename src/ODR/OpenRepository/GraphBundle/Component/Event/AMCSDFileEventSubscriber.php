<?php

/**
 * Open Data Repository Data Publisher
 * AMCSD File Event Subscriber
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * An event subscriber listening to the FilePreEncypt Event that is dispatched right before an ODR
 * file gets put into the background process queue for encrypting.
 *
 * If the file was uploaded into a datafield that is mapped to the "AMC File" field for the AMCSD
 * render plugin, this subscriber reads the contents of the file that just got uploaded and updates
 * the other fields required by the AMCSD render plugin with values read from that file.
 */

namespace ODR\OpenRepository\GraphBundle\Component\Event;

// Entities
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
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
// Events
use ODR\AdminBundle\Component\Event\FileDeletedEvent;
use ODR\AdminBundle\Component\Event\FilePreEncryptEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;


class AMCSDFileEventSubscriber implements EventSubscriberInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatabaseInfoService
     */
    private $dbi_service;

    /**
     * @var DatarecordInfoService
     */
    private $dri_service;

    /**
     * @var EntityCreationService
     */
    private $ec_service;

    /**
     * @var EntityMetaModifyService
     */
    private $emm_service;

    /**
     * @var SearchCacheService
     */
    private $search_cache_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * AMCSDFileEventSubscriber constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param EntityCreationService $entity_creation_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param SearchCacheService $search_cache_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        EntityCreationService $entity_creation_service,
        EntityMetaModifyService $entity_meta_modify_service,
        SearchCacheService $search_cache_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dbi_service = $database_info_service;
        $this->dri_service = $datarecord_info_service;
        $this->ec_service = $entity_creation_service;
        $this->emm_service = $entity_meta_modify_service;
        $this->search_cache_service = $search_cache_service;
        $this->logger = $logger;
    }


    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            FilePreEncryptEvent::NAME => 'onFilePreEncrypt',
            FileDeletedEvent::NAME => 'onFileDeleted',
        );
    }


    /**
     * Determines whether the file that just finished getting encrypted got uploaded into the
     * "AMC File" field of a datatype using the AMCSD render plugin...if so, the file is read, and
     * the values from the file saved into other datafields required by the render plugin.
     *
     * @param FilePreEncryptEvent $event
     *
     * @throws \Exception
     */
    public function onFilePreEncrypt(FilePreEncryptEvent $event)
    {
        $is_event_relevant = false;
        $local_filepath = null;

        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $file = null;
        $datarecord = null;
        $user = null;
        $storage_entities = array();

        try {
            // Get entities related to the file
            $file = $event->getFile();
            $datarecord = $file->getDataRecord();
            $datafield = $file->getDataField();
            $datatype = $datafield->getDataType();
            $user = $file->getCreatedBy();

            // Only care about a file that get uploaded to the "AMC File" field of a datatype using
            //  the AMCSD render plugin...
            $is_event_relevant = self::isEventRelevant($datafield);
            if ( $is_event_relevant ) {
                // ----------------------------------------
                // This file was uploaded to the correct field, so it now needs to be processed
                $this->logger->debug('Attempting to read file '.$file->getId().' "'.$file->getOriginalFileName().'"...', array(self::class, 'onFilePreEncrypt()', 'File '.$file->getId()));

                // Since the file hasn't been encrypted yet, it's currently in something of an odd
                //  spot as far as ODR files usually go
                $local_filepath = $file->getLocalFileName().$file->getOriginalFileName();


                // ----------------------------------------
                // Create as much of the mappings as possible, since they could be needed during
                //  error recovery...

                // Map the field definitions in the render plugin to datafields
                $datafield_mapping = self::getRenderPluginFieldsMapping($datatype);

                // Need to hydrate the storage entities for each datafield so the values from the
                //  file can get saved into the database
                $storage_entities = self::hydrateStorageEntities($datafield_mapping, $user, $datarecord);


                // ----------------------------------------
                // Ensure the file can be opened...
                $handle = fopen($local_filepath, 'r');
                if ($handle === false)
                    throw new \Exception('Unable to open existing file at "'.$local_filepath.'"');

                // Attempt to verify that the file at least looks like an AMC file before trying
                //  to extract data from it
                self::checkFile($handle);

                // Extract each piece of data from the file contents
                $value_mapping = self::readFile($handle);

                // No longer need the file to be open
                fclose($handle);

                // File hasn't been encrypted yet, so DO NOT delete it


                // ----------------------------------------
                // Each piece of data from the file is referenced by its RenderPluginField name...
                foreach ($value_mapping as $rpf_name => $value) {
                    // ...from which the actual datafield id can be located...
                    $df_id = $datafield_mapping[$rpf_name];
                    // ...which gives the hydrated storage entity...
                    $entity = $storage_entities[$df_id];
                    /** @var IntegerValue|DecimalValue|ShortVarchar|MediumVarchar|LongVarchar|LongText $entity */

                    // ...which is saved in the storage entity for the datafield
                    $this->emm_service->updateStorageEntity($user, $entity, array('value' => $value), true);    // don't flush immediately
                    $this->logger->debug(' -- updating datafield '.$df_id.' ('.$rpf_name.') to have the value "'.$value.'"', array(self::class, 'onFilePreEncrypt()', 'File '.$file->getId()));

                    // This only works because the datafields getting updated aren't files/images or
                    //  radio/tag fields
                }


                // ----------------------------------------
                // Now that all the fields have their correct value, flush all changes
                $this->em->flush();
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onFilePreEncrypt()', 'File '.$file->getId()));

            // If an error was thrown, attempt to ensure the related AMCSD fields are blank
            self::saveOnError($user, $file, $storage_entities);

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( $is_event_relevant ) {
                $this->logger->debug('All changes saved', array(self::class, 'onFilePreEncrypt()', 'File '.$file->getId()));
                self::clearCacheEntries($datarecord, $user, $storage_entities);
            }
        }
    }


    /**
     * Determines whether the file that just got deleted belonged to the "AMC File" field of a
     * datatype using the AMCSD render plugin...if so, the values in the fields that depend on the
     * AMC file are cleared.
     *
     * @param FileDeletedEvent $event
     *
     * @throws \Exception
     */
    public function onFileDeleted(FileDeletedEvent $event)
    {
        $is_event_relevant = false;

        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $user = null;
        $datarecord = null;
        $storage_entities = array();

        try {
            // Get entities related to the file that just got deleted
            $datarecord = $event->getDataRecord();
            $datafield = $event->getDataField();
            $datatype = $datafield->getDataType();
            $user = $event->getUser();

            // Only care about a file that get uploaded to the "AMC File" field of a datatype using
            //  the AMCSD render plugin...
            $is_event_relevant = self::isEventRelevant($datafield);
            if ( $is_event_relevant ) {
                // This file was deleted from the correct field, so it now needs to be processed
                $this->logger->debug('Attempting to clear values derived from deleted file...', array(self::class, 'onFileDeleted()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));

                // ----------------------------------------
                // Create as much of the mappings as possible, since they could be needed during
                //  error recovery...

                // Map the field definitions in the render plugin to datafields
                $datafield_mapping = self::getRenderPluginFieldsMapping($datatype);

                // Need to hydrate the storage entities for each datafield so the values from the
                //  file can get saved into the database
                $storage_entities = self::hydrateStorageEntities($datafield_mapping, $user, $datarecord);


                // ----------------------------------------
                // Each relevant field required by the render plugin needs to be cleared...
                foreach ($storage_entities as $df_id => $entity) {
                    /** @var IntegerValue|DecimalValue|ShortVarchar|MediumVarchar|LongVarchar|LongText $entity */
                    $this->emm_service->updateStorageEntity($user, $entity, array('value' => ''), true);    // don't flush immediately
                    $this->logger->debug('-- updating datafield '.$df_id.' to have the value ""', array(self::class, 'onFileDeleted()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));
                }


                // ----------------------------------------
                // Now that all the fields have their correct value, flush all changes
                $this->em->flush();

                // The HTML on the page has already been set up to do a complete/partial reload so
                //  the changes to the rest of the fields get displayed properly
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onFileDeleted()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( $is_event_relevant ) {
                $this->logger->debug('All changes saved', array(self::class, 'onFileDeleted()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));
                self::clearCacheEntries($datarecord, $user, $storage_entities);
            }
        }
    }


    /**
     * Returns whether the given datafield is the "AMC File" datafield of a datatype that's using
     * the AMCSD render plugin.
     *
     * @param DataFields $datafield
     *
     * @return bool
     */
    private function isEventRelevant($datafield)
    {
        $dt = $datafield->getDataType();
        $rp = $dt->getRenderPlugin();

        // The datatype must be currently using the AMCSD render plugin...
        if ( $rp->getPluginClassName() !== 'odr_plugins.rruff.amcsd' )
            return false;

        // ...and the datafield the field got uploaded to must be mapped to the "AMC File" field
        $query = $this->em->createQuery(
           'SELECT rpf.fieldName
            FROM ODRAdminBundle:RenderPluginInstance AS rpi
            JOIN ODRAdminBundle:RenderPluginMap AS rpm WITH rpm.renderPluginInstance = rpi
            JOIN ODRAdminBundle:RenderPluginFields AS rpf WITH rpm.renderPluginFields = rpf
            WHERE rpi.renderPlugin = :render_plugin_id AND rpi.dataType = :datatype_id
            AND rpm.dataField = :datafield_id AND rpf.fieldName = :render_plugin_field_name
            AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL AND rpf.deletedAt IS NULL'
        )->setParameters(
            array(
                'render_plugin_id' => $rp->getId(),
                'datatype_id' => $dt->getId(),
                'datafield_id' => $datafield->getId(),
                'render_plugin_field_name' => 'AMC File',    // the "name" property of the "amc_file" field, defined in the "required_fields" section of AMCSDPlugin.yml
            )
        );
        $results = $query->getArrayResult();

        // If the array isn't empty, then the file did get uploaded to the "AMC File" field
        if ( !empty($results) )
            return true;

        // Otherwise, the file got uploaded to some other field (CIF/DIF/etc)...the event needs to
        //  be ignored
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
        $datatype_array = $this->dbi_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want linked datatypes
        $dt = $datatype_array[$datatype->getId()];

        // Already verified that the datatype is using the AMCSD plugin in self::isEventRelevant(),
        //  so diving this deep into the array should work
        $renderPluginMap = $dt['dataTypeMeta']['renderPlugin']['renderPluginInstance'][0]['renderPluginMap'];

        $datafield_mapping = array();
        foreach ($renderPluginMap as $num => $rpm) {
            $rpf_name = $rpm['renderPluginFields']['fieldName'];
            $df_id = $rpm['dataField']['id'];

            // Only want a subset of the fields required by the AMCSD plugin in the final array
            switch ($rpf_name) {
                case 'database_code_amcsd':
                case 'Authors':
                case 'File Contents':

                case 'Mineral':
                case 'a':
                case 'b':
                case 'c':
                case 'alpha':
                case 'beta':
                case 'gamma':
                case 'Space Group':
                    $datafield_mapping[$rpf_name] = $df_id;
                    break;

                // Don't want any of these fields, or any other field, in the final array
//                case 'fileno':
//                case 'AMC File':
//                case 'CIF File':
//                case 'DIF File':
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
     *
     * @throws \Exception
     *
     * @return array
     */
    private function hydrateStorageEntities($datafield_mapping, $user, $datarecord)
    {
        // Need hydrated versions of all of these datafields...might as well get them all at once
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
        //  likely they don't, and $emm_service->updateStorageEntity() requires one
        $storage_entities = array();
        foreach ($datafield_mapping as $rpf_name => $df_id) {
            $df = $hydrated_datafields[$df_id];
            $entity = $this->ec_service->createStorageEntity($user, $datarecord, $df);    // NOTE - this function can't have its flushes delayed

            // Store they (likely newly created) storage entity for the next step
            $storage_entities[$df_id] = $entity;
        }

        // Return the hydrated list of storage entities
        return $storage_entities;
    }


    /**
     * Attempts to verify that the given AMC file is at least readable by self::readFile().
     *
     * Verifying with 100% accuracy that it is indeed an AMC file is impossible because of boring
     * computer science reasons.
     *
     * @param resource $handle
     *
     * @throws \Exception
     */
    private function checkFile($handle)
    {
        // Ensure we're at the beginning of the file
        fseek($handle, 0, SEEK_SET);

        $has_database_code = false;
        $has_cellparams = false;

        $database_code_line = -999;
        $line_num = 0;
        while ( !feof($handle) ) {
            $line_num++;
            $line = fgets($handle);

            // First line is supposed to be the mineral name...needs to fit in a MediumVarchar
            if ($line_num == 1) {
                if ( !ValidUtility::isValidMediumVarchar($line) )
                    throw new \Exception("Mineral name is too long");
            }
            if ( strpos($line, '_database_code_amcsd') === 0 ) {
                $pieces = explode(' ', $line);

                // Need to have 2 values in this line
                if ( count($pieces) !== 2 )
                    throw new \Exception("Invalid line starting with '_database_code_amcsd'");

                // Need to save this line number, because the cell params should come immediately after
                $has_database_code = true;
                $database_code_line = $line_num;
            }
            // The line after that contains the a/b/c/alpha/beta/gamma/space group values
            elseif ( ($database_code_line+1) === $line_num ) {
                $pieces = explode(' ', $line);

                // Need to have 7 values in this line
                if ( count($pieces) !== 7 )
                    throw new \Exception("Invalid number of cellparameters");

                // a/b/c/alpha/beta/gamma values need to be valid decimal values
                for ($i = 0; $i < 6; $i++) {
                    if ( !ValidUtility::isValidDecimal($pieces[$i]) )
                        throw new \Exception("Cellparameter at position ".$i." is not numeric");
                }

                // Space Group should only have like 10 characters max (though variants can go up to
                //  like 14 characters apparently), so it needs to fit inside a ShortVarchar
                // Note that underscores here only refer to the character immediately following them
                //  and are not paired, unlike underscores in mineral formulas
                if ( !ValidUtility::isValidShortVarchar($pieces[6]) )
                    throw new \Exception("Space Group is too long");

                // Otherwise, this line could technically a set of cell parameters
                $has_cellparams = true;
            }
        }

        // The file needs to be at least 6 lines long...mineral name, authors, journal, database_code,
        //  cellparams, and at least one atom position...if not, it can't be valid
        if ( $line_num < 6 )
            throw new \Exception("AMC File is too short");

        // If it didn't find the database_code or cellparams lines, then it can't be valid
        if ( !$has_database_code || !$has_cellparams )
            throw new \Exception("Couldn't find _database_code_amcsd or cellparameters in AMC File");

        // Can't technically tell if the file is a valid AMC file or not, but at least it's close
        //  enough that self::readFile() shouldn't throw an error
    }


    /**
     * Reads the given opened file, converting its contents into an array that's indexed by the
     * "name" property of the fields defined in the "required_fields" section of AMCSDPlugin.yml
     *
     * @param resource $handle
     *
     * @return array
     */
    private function readFile($handle)
    {
        $value_mapping = array();
        $all_lines = array();

        // Ensure we're at the beginning of the file
        fseek($handle, 0, SEEK_SET);

        $database_code_line = -999;
        $line_num = 0;
        while ( !feof($handle) ) {
            $line_num++;
            $line = fgets($handle);

            // First line is the mineral name
            if ($line_num == 1) {
                $value_mapping['Mineral'] = $line;
            }
            // Second line is the authors
            elseif ($line_num == 2) {
                $value_mapping['Authors'] = $line;
            }
            // Third line is the journal
//            elseif ($line_num == 3)
//                $value_mapping['Journal'] = $line;

            // Next there's usually two (sometimes three) lines of stuff the plugin doesn't care about

            // The line starting with "_database_code_amcsd" is the next important one...
            elseif ( strpos($line, '_database_code_amcsd') === 0 ) {
                $pieces = explode(' ', $line);
                $value_mapping['database_code_amcsd'] = $pieces[1];

                // Need to save this line number, because the cell params come immediately after
                $database_code_line = $line_num;
            }
            // The line after that contains the a/b/c/alpha/beta/gamma/space group values
            elseif ( ($database_code_line+1) === $line_num ) {
                $pieces = explode(' ', $line);
                $value_mapping['a'] = $pieces[0];
                $value_mapping['b'] = $pieces[1];
                $value_mapping['c'] = $pieces[2];
                $value_mapping['alpha'] = $pieces[3];
                $value_mapping['beta'] = $pieces[4];
                $value_mapping['gamma'] = $pieces[5];
                $value_mapping['Space Group'] = $pieces[6];
            }

            // Save every line from the file, as well
            $all_lines[] = $line;
        }

        // Want all file contents in a single field
        $value_mapping['File Contents'] = implode($all_lines);

        // Ensure the values are trimmed before they're saved
        foreach ($value_mapping as $rpf_name => $value)
            $value_mapping[$rpf_name] = trim($value);

        // All data gathered, return the mapping array
        return $value_mapping;
    }


    /**
     * If some sort of error/exception was thrown, then attempt to blank out all the fields derived
     * from the file being read...this won't stop the file from being encrypted, which will allow
     * the renderplugin to recognize and display that something is wrong with this file.
     *
     * @param ODRUser $user
     * @param File $file
     * @param array $storage_entities
     */
    private function saveOnError($user, $file, $storage_entities)
    {
        try {
            foreach ($storage_entities as $df_id => $entity) {
                /** @var IntegerValue|DecimalValue|ShortVarchar|MediumVarchar|LongVarchar|LongText $entity */
                $this->emm_service->updateStorageEntity($user, $entity, array('value' => ''), true);    // don't flush immediately
                $this->logger->debug('-- (ERROR) updating datafield '.$df_id.' to have the value ""', array(self::class, 'onFilePreEncrypt()', 'File '.$file->getId()));
            }

            $this->em->flush();
        }
        catch (\Exception $e) {
            // Some other error...no way to recover from it
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'saveOnError()', 'User '.$user->getId(), 'File '.$file->getId()));
        }
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
        // The datarecord needs to be marked as updated
        $this->dri_service->updateDatarecordCacheEntry($datarecord, $user);

        // Because multiple datafields got updated, multiple cache entries need to be wiped
        foreach ($storage_entities as $df_id => $entity)
            $this->search_cache_service->onDatafieldModify($entity->getDataField());
        $this->search_cache_service->onDatarecordModify($datarecord);
    }
}
