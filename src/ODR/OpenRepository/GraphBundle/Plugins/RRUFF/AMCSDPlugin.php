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
 * they're technically derived from the contents of the AMC file.
 *
 * The actual derivation is performed by AMCSDFileEncryptedSubscriber.php.
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
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Event\FileDeletedEvent;
use ODR\AdminBundle\Component\Event\FilePreEncryptEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class AMCSDPlugin implements DatatypePluginInterface
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
     * @var LockService
     */
    private $lock_service;

    /**
     * @var SearchCacheService
     */
    private $search_cache_service;

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
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param EntityCreationService $entity_creation_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param LockService $lock_service
     * @param SearchCacheService $search_cache_service
     * @param CsrfTokenManager $token_manager
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        EntityCreationService $entity_creation_service,
        EntityMetaModifyService $entity_meta_modify_service,
        LockService $lock_service,
        SearchCacheService $search_cache_service,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dbi_service = $database_info_service;
        $this->dri_service = $datarecord_info_service;
        $this->ec_service = $entity_creation_service;
        $this->emm_service = $entity_meta_modify_service;
        $this->lock_service = $lock_service;
        $this->search_cache_service = $search_cache_service;
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
        // The render plugin does 4 things...
        // 1) Change the "File Contents" field to a monospace font (in both Display and Edit)
        // 2) Warn when the uploaded "AMC File" has problems (in both Display and Edit)
        // 3) The upload javascript for the "AMC File" needs to refresh the page after upload (in Edit)
        // 4) the "fileno" field needs to have autogenerated values (in FakeEdit)
        if ( isset($rendering_options['context']) ) {
            if ( $rendering_options['context'] === 'display'
                || $rendering_options['context'] === 'edit'
                || $rendering_options['context'] === 'fake_edit'
            ) {
                // ...so execute the render plugin if being called from Display, Edit, or FakeEdit
                return true;
            }
        }

        // Render plugins aren't called outside of the above contexts, so this will never be run
        // ...for now
        return false;
    }


    /**
     * Executes the AMCSD Plugin on the provided datarecords
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
            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Retrieve mapping between datafields and render plugin fields
            $plugin_fields = array();
            $editable_datafields = array();
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                // Need to tweak display parameters for several of the fields...
                $plugin_fields[$rpf_df_id] = $rpf_name;

                // These strings are the "name" entries for each of the required fields
                // So, "database_code_amcsd", not "database code"
                switch ($rpf_name) {
                    case 'fileno':
                    case 'database_code_amcsd':
                    case 'Authors':
                    case 'File Contents':

                    // These three can be edited
//                    case 'amc_file':
//                    case 'cif_file':
//                    case 'dif_file':

                    case 'Mineral':
                    case 'a':
                    case 'b':
                    case 'c':
                    case 'alpha':
                    case 'beta':
                    case 'gamma':
                    case 'Space Group':
                        // None of these fields can be edited, since they come straight from the AMC file
                        break;

                    default:
                        $editable_datafields[$rpf_df_id] = $rpf_name;
                        break;
                }
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

            // Also need to provide a special token so the "fileno" field won't get ignored
            //  by FakeEdit because it prevents user edits...
            $fileno_field_id = array_search("fileno", $plugin_fields);
            $token_id = 'FakeEdit_'.$datarecord['id'].'_'.$fileno_field_id.'_autogenerated';
            $token = $this->token_manager->getToken($token_id)->getValue();
            $special_tokens[$fileno_field_id] = $token;


            // ----------------------------------------
            // If no rendering context set, then return nothing so ODR's default templating will
            //  do its job
            if ( !isset($rendering_options['context']) )
                return '';

            // Otherwise, output depends on which context the plugin is being executed from
            $output = '';
            if ( $rendering_options['context'] === 'edit' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_edit_fieldarea.html.twig',
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
                    )
                );
            }
            else if ( $rendering_options['context'] === 'fake_edit' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_fakeedit_fieldarea.html.twig',
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
            else if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_display_fieldarea.html.twig',
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

                        'plugin_fields' => $plugin_fields,
                    )
                );
            }

            // If executing from the Display or Edit modes...
            if ( $rendering_options['context'] === 'display' || $rendering_options['context'] === 'edit' ) {
                // ...need to check for whether there's a file uploaded to the "AMC File" field, but
                //  none of the other datafields have a value in them...if so, then there was a
                //  problem, and an additional themeElement should be inserted to complain
                if ( self::fileHasProblem($plugin_fields, $datarecord) ) {
                    // Determine whether the user can edit the "AMC File" datafield
                    $can_edit_relevant_datafield = false;
                    $df_id = array_search('AMC File', $editable_datafields);
                    if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['edit']) )
                        $can_edit_relevant_datafield = true;

                    $error_div = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_error.html.twig',
                        array(
                            'can_edit_relevant_datafield' => $can_edit_relevant_datafield,
                        )
                    );

                    $output = $error_div . $output;
                }
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * The file uploaded into the "AMC File" field could have a problem...if it does, the event
     * subscriber should've caught it, and forced all (or hopefully most) of the other plugin fields
     * to be blank...
     *
     * @param array $plugin_fields
     * @param array $datarecord
     *
     * @return bool
     */
    private function fileHasProblem($plugin_fields, $datarecord)
    {
        $value_mapping = array();
        foreach ($datarecord['dataRecordFields'] as $df_id => $drf) {
            // Don't want to have to locate typeclass...
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
            else {
                // Not a file datafield, but don't want to have to locate typeclass, so...
                unset( $drf['file'] );
                foreach ($drf as $typeclass => $entity) {
                    // Should only be one entry left in typeclass
                    if ( !empty($entity) )
                        $value_mapping[$df_id] = $entity[0]['value'];
                    else
                        $value_mapping[$df_id] = '';
                }
            }
        }

        // If the "AMC file" datafield doesn't have anything uploaded to it, then there can't be
        //  a problem with the field
        $df_id = array_search('AMC File', $plugin_fields);
        if ( empty($value_mapping[$df_id]) )
            return false;


        // Otherwise, there's something uploaded to the "AMC File" datafield...determine whether
        //  all of the other fields derived from the AMC file have a value
        foreach ($plugin_fields as $df_id => $rpf_name) {
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
                    // All of these fields need to have a value for the AMC file to be valid...if
                    //  they don't, then the file has a problem
                    if ( !isset($value_mapping[$df_id]) || is_null($value_mapping[$df_id]) || $value_mapping[$df_id] === '' )
                        return true;
                    break;

//                case 'fileno':
//                case 'amc_file':
//                case 'cif_file':
//                case 'dif_file':
                default:
                    // These fields, or another other field in the datatype, don't matter for the
                    //  purposes of determining whether the amc file had problems
                    break;
            }
        }

        // Otherwise, all required fields have a value, so there's no problem with the AMC file
        return false;
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
                //  spot as far as ODR files usually go...getLocalFileName() returns a directory
                //  instead of a full path
                $local_filepath = $file->getLocalFileName().'/'.$file->getOriginalFileName();


                // ----------------------------------------
                // Create as much of the mappings as possible, since they could be needed during
                //  error recovery...

                // Map the field definitions in the render plugin to datafields
                $datafield_mapping = self::getRenderPluginFieldsMapping($datatype);

                // Need to hydrate the storage entities for each datafield so the values from the
                //  file can get saved into the database
                $storage_entities = self::hydrateStorageEntities($datafield_mapping, $user, $datarecord);


                // ----------------------------------------
                // The provided file hasn't been encrypted yet, so it should be able to be read
                //  directly
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
        $datafield = null;
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

        // Sanity check for whether the datatype is currently using the AMCSD render plugin...
        $rp = null;
        foreach ($dt->getRenderPluginInstances() as $rpi) {
            /** @var RenderPluginInstance $rpi */
            if ( $rpi->getRenderPlugin()->getPluginClassName() === 'odr_plugins.rruff.amcsd' ) {
                $rp = $rpi->getRenderPlugin();
                break;
            }
        }
        if ( is_null($rp) )
            return false;

        // The datafield the field got uploaded to must be mapped to the "AMC File" field
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

        // The datatype could be using multiple render plugins, so need to find the mapping specifically
        //  for the AMCSD plugin...it's already verified to exist due to self::isEventRelevant()
        $renderPluginMap = null;
        foreach( $dt['renderPluginInstances'] as $rpi_num => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.amcsd' ) {
                $renderPluginMap = $rpi['renderPluginMap'];
                break;
            }
        }

        $datafield_mapping = array();
        foreach ($renderPluginMap as $rpf_name => $rpf_df) {
            $rpf_df_id = $rpf_df['id'];

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
                    $datafield_mapping[$rpf_name] = $rpf_df_id;
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
        $value_mapping['File Contents'] = implode("", $all_lines);

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

        // Need to locate the "fileno" field for this render plugin...
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
                'plugin_classname' => 'odr_plugins.rruff.amcsd',
                'datatype' => $datatype->getId(),
                'field_name' => 'fileno'
            )
        );
        $results = $query->getResult();
        if ( count($results) !== 1 )
            throw new ODRException('Unable to find the "fileno" field for the RenderPlugin "AMCSD", attached to Datatype '.$datatype->getId());

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

        // Extract the numeric part of the "most recent" value, and add 1 to it
        $val = intval( substr($old_value, 2) );
        $val += 1;

        // Convert it back into the expected format so the storage entity can get created
        $new_value = str_pad($val, 5, '0', STR_PAD_LEFT);
        $this->ec_service->createStorageEntity($user, $datarecord, $datafield, $new_value);

        // No longer need the lock
        $lockHandler->release();


        // ----------------------------------------
        // Not going to mark the datarecord as updated, but still need to do some other cache
        //  maintenance because a datafield value got changed...

        // If the datafield that got changed was the datatype's sort datafield, delete the cached datarecord order
        if ( $datatype->getSortField() != null && $datatype->getSortField()->getId() == $datafield->getId() )
            $this->dbi_service->resetDatatypeSortOrder($datatype->getId());

        // Delete any cached search results involving this datafield
        $this->search_cache_service->onDatafieldModify($datafield);
    }


    /**
     * For this database, it is technically possible to use SortService::sortDatarecordsByDatafield()
     * However, there could be values that don't match the correct format /^[0-9]{5,5}$/, so it's
     * safer to specifically find the values that match the correct format.
     *
     * Don't particularly like random render plugins finding random stuff from the database, but
     * there's no other way to satisfy the design requirements.
     *
     * @param int $datafield_id
     * @return string
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function findCurrentValue($datafield_id)
    {
        // Going to use native SQL...DQL can't use limit without using querybuilder...
        $query =
           'SELECT e.value
            FROM odr_short_varchar e
            WHERE e.data_field_id = :datafield AND e.value REGEXP "^[0-9]{5,5}$"
            AND e.deletedAt IS NULL
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
            $current_value = $result['value'];
        // ...but if there's not for some reason, use this value as the "current".  onDatarecordCreate()
        //  will increment it so that the value "00001" is what will actually get saved.
        if ( is_null($current_value) )
            $current_value = '00000';

        return $current_value;
    }
}
