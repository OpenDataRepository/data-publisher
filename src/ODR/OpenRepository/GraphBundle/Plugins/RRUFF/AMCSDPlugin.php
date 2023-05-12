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
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\FileDeletedEvent;
use ODR\AdminBundle\Component\Event\FilePreEncryptEvent;
use ODR\AdminBundle\Component\Event\PostMassEditEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
use ODR\OpenRepository\GraphBundle\Plugins\PostMassEditEventInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class AMCSDPlugin implements DatatypePluginInterface, DatafieldDerivationInterface, PostMassEditEventInterface
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
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

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
     * @param EntityCreationService $entity_creation_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param LockService $lock_service
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
        EntityCreationService $entity_creation_service,
        EntityMetaModifyService $entity_meta_modify_service,
        LockService $lock_service,
        EventDispatcherInterface $event_dispatcher,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->crypto_service = $crypto_service;
        $this->dbi_service = $database_info_service;
        $this->dri_service = $datarecord_info_service;
        $this->ec_service = $entity_creation_service;
        $this->emm_service = $entity_meta_modify_service;
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
        // The render plugin does 5 things...
        // 1) Change the "File Contents" field to a monospace font (in both Display and Edit)
        // 2) Warn when the uploaded "AMC File" has problems (in both Display and Edit)
        // 3) The upload javascript for the "AMC File" needs to refresh the page after upload (in Edit)
        // 4) The "fileno" field needs to have autogenerated values (in FakeEdit)
        // 5) Provides the ability to force derivations without uploading the file again (in MassEdit)
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            if ( $context === 'display'
                || $context === 'edit'
                || $context === 'fake_edit'
//                || $context === 'mass_edit'    // TODO - ...don't think i want this firing unless explicitly requested
            ) {
                // ...so execute the render plugin if being called from these contexts
                return true;
            }
        }

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
                $plugin_fields[$rpf_df_id] = $rpf_df;
                $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;

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
            $fileno_field_id = $fields['fileno']['id'];
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
                    if ( !empty($entity) && isset($entity[0]['value']) )
                        $value_mapping[$df_id] = $entity[0]['value'];
                    else
                        $value_mapping[$df_id] = '';
                }
            }
        }

        // If the "AMC file" datafield doesn't have anything uploaded to it, then there can't be
        //  a problem with the field
        $df_id = null;
        foreach ($plugin_fields as $num => $rpf_df) {
            // NOTE - technically $num is the datafield_id, but don't want to overwrite $df_id yet
            if ( $rpf_df['rpf_name'] === 'AMC File' ) {
                $df_id = $rpf_df['id'];
                break;
            }
        }
        // Should never not have an "AMC File" datafield...
        if ( empty($value_mapping[$df_id]) )
            return false;


        // Otherwise, there's something uploaded to the "AMC File" datafield...therefore, the other
        //  fields defined by this render plugin should all have a value
        foreach ($plugin_fields as $df_id => $rpf_df) {
            switch ( $rpf_df['rpf_name'] ) {
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
     * Determines whether the file that just finished getting uploaded belongs to the "AMC File"
     * field of a datatype using the AMCSD render plugin...if so, the file is read, and the values
     * from the file saved into other datafields required by the render plugin.
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
                    $this->emm_service->updateStorageEntity(
                        $user,
                        $entity,
                        array('value' => $value),
                        true,    // don't flush immediately
                        false    // don't fire PostUpdate event...nothing depends on these fields
                    );
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

            if ( $is_event_relevant ) {
                // If an error was thrown, attempt to ensure the related AMCSD fields are blank
                self::saveOnError($user, $file, $storage_entities);
            }

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
            $datarecord = $event->getDatarecord();
            $datafield = $event->getDatafield();
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
                    $this->emm_service->updateStorageEntity(
                        $user,
                        $entity,
                        array('value' => ''),
                        true,    // don't flush immediately
                        false    // don't fire PostUpdate event...nothing depends on these fields
                    );
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
     * Determines whether the "AMC File" field of a datatype using the AMCSD render plugin just got
     * processed by MassEdit...if so, the file is read again, and the values from the file saved
     * into other datafields required by the render plugin.
     *
     * TODO - ...don't think i want this firing during MassEdit
     *
     * @param PostMassEditEvent $event
     *
     * @throws \Exception
     */
    public function onPostMassEdit(PostMassEditEvent $event)
    {
        // This shouldn't be called because the config file doesn't list the event, but make sure
        return;

        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $is_event_relevant = false;

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

            // Only care about a file that get uploaded to the "AMC File" field of a datatype using
            //  the AMCSD render plugin...
            $is_event_relevant = self::isEventRelevant($datafield);
            if ( $is_event_relevant ) {
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
                    $this->logger->debug('Attempting to read file '.$file->getId().' "'.$file->getOriginalFileName().'"...', array(self::class, 'onPostMassEdit()', 'File '.$file->getId()));


                    // ----------------------------------------
                    // Create as much of the mappings as possible, since they could be needed during
                    //  error recovery...

                    // Map the field definitions in the render plugin to datafields
                    $datafield_mapping = self::getRenderPluginFieldsMapping($datatype);

                    // Need to hydrate the storage entities for each datafield so the values from the
                    //  file can get saved into the database
                    $storage_entities = self::hydrateStorageEntities($datafield_mapping, $user, $datarecord);


                    // ----------------------------------------
                    // The provided file may not exist on the server...
                    $local_filepath = $this->crypto_service->decryptFile($file->getId());
                    // ...once it does, open it
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

                    // If the File isn't public, then delete its decrypted version off the server
                    if ( !$file->isPublic() )
                        unlink($local_filepath);


                    // ----------------------------------------
                    // Each piece of data from the file is referenced by its RenderPluginField name...
                    foreach ($value_mapping as $rpf_name => $value) {
                        // ...from which the actual datafield id can be located...
                        $df_id = $datafield_mapping[$rpf_name];
                        // ...which gives the hydrated storage entity...
                        $entity = $storage_entities[$df_id];
                        /** @var IntegerValue|DecimalValue|ShortVarchar|MediumVarchar|LongVarchar|LongText $entity */

                        // ...which is saved in the storage entity for the datafield
                        $this->emm_service->updateStorageEntity(
                            $user,
                            $entity,
                            array('value' => $value),
                            true,    // don't flush immediately
                            false    // don't fire PostUpdate event...nothing depends on these fields
                        );
                        $this->logger->debug(' -- updating datafield '.$df_id.' ('.$rpf_name.') to have the value "'.$value.'"', array(self::class, 'onPostMassEdit()', 'File '.$file->getId()));

                        // This only works because the datafields getting updated aren't files/images or
                        //  radio/tag fields
                    }


                    // ----------------------------------------
                    // Now that all the fields have their correct value, flush all changes
                    $this->em->flush();
                }
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onPostMassEdit()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( $is_event_relevant ) {
                $this->logger->debug('All changes saved', array(self::class, 'onPostMassEdit()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));
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
        // Going to use the cached datatype array to locate the correct datafield...
        $datatype = $datafield->getDataType();
        $dt_array = $this->dbi_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links
        if ( !isset($dt_array[$datatype->getId()]['renderPluginInstances']) )
            return false;

        foreach ($dt_array[$datatype->getId()]['renderPluginInstances'] as $rpi_num => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.amcsd' ) {
                // Datatype is using the correct plugin...
                if ( isset($rpi['renderPluginMap']['AMC File'])
                    && $rpi['renderPluginMap']['AMC File']['id'] === $datafield->getId()
                ) {
                    // ...and the datafield that triggered the onFilePreEncrypt/onFileDeleted event
                    //  is the "AMC File" datafield
                    return true;

                    // TODO - also need to extract the chemistry field from the CIF file...
                }
            }
        }

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
            $entity = $this->ec_service->createStorageEntity($user, $datarecord, $df);

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

            // TODO - these regexes are from /www/html/rruff/mineral_list/functions.php, starting at around line 1508

            // TODO - pressure looks like "P = 3 GPa" or "P = 11.1 kbar" or "Pressure = 7.3 GPA"...can also have a tolerance
            // TODO - currently uses the regex "/P(?:ressure)?\s\=\s([0-9\.\(\)]+)\s(\w+)/"

            // TODO - temp looks like "T = 200 K" or "T = 359.4K" or "T = 500K"
            // TODO - ...it can also look like "500 deg C" or "500 degrees C" or "T = 185 degrees C"
            // TODO - currently uses two regexes... "/T\s\=\s(-?[0-9\.]+)\s?(C|K)/"  and  "/(-?[0-9\.]+)\sdeg(?:ree)?(?:s)?\s(C|K)/"

            // TODO - chemistry comes from the CIF file, and uses the regex "/\_chemical\_formula\_sum\s\'([^\']+)\'/"

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
                $this->emm_service->updateStorageEntity(
                    $user,
                    $entity,
                    array('value' => ''),
                    true,    // don't flush immediately
                    false    // don't fire PostUpdate event...nothing depends on these fields
                );
                $this->logger->debug('-- (ERROR) updating datafield '.$df_id.' to have the value ""', array(self::class, 'saveOnError()', 'File '.$file->getId()));
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
        // Because multiple datafields got updated, multiple cache entries need to be wiped
        foreach ($storage_entities as $df_id => $entity) {
            // Fire off an event notifying that the modification of the datafield is done
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
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
            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
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
     * Handles when a datarecord is created.
     *
     * @param DatarecordCreatedEvent $event
     */
    public function onDatarecordCreate(DatarecordCreatedEvent $event)
    {
        // TODO - disabled for import testing, re-enable this later on
        return;

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
        $this->ec_service->createStorageEntity($user, $datarecord, $datafield, $new_value, false);    // guaranteed to not need a PostUpdate event

        // No longer need the lock
        $lockHandler->release();


        // ----------------------------------------
        // Fire off an event notifying that the modification of the datafield is done
        try {
            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
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
        // TODO - ...this seems to ignore the possibility of deleted entries...is that a problem?
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
        if ( $render_plugin_instance['renderPlugin']['pluginClassName'] !== 'odr_plugins.rruff.amcsd' )
            return array();
        $render_plugin_map = $render_plugin_instance['renderPluginMap'];

        // The AMCSD plugin derives almost all of its fields from the contents of the file uploaded
        //  to the "AMC File" field
        $amc_file_df_id = $render_plugin_map['AMC File']['id'];
        $mineral_name_df_id = $render_plugin_map['Mineral']['id'];
        $authors_df_id = $render_plugin_map['Authors']['id'];
        $database_code_amcsd_df_id = $render_plugin_map['database_code_amcsd']['id'];
        $file_contents_df_id = $render_plugin_map['File Contents']['id'];
        $a_df_id = $render_plugin_map['a']['id'];
        $b_df_id = $render_plugin_map['b']['id'];
        $c_df_id = $render_plugin_map['c']['id'];
        $alpha_df_id = $render_plugin_map['alpha']['id'];
        $beta_df_id = $render_plugin_map['beta']['id'];
        $gamma_df_id = $render_plugin_map['gamma']['id'];
        $space_group_df_id = $render_plugin_map['Space Group']['id'];

        // Since a datafield could be derived from multiple datafields, the source datafields need
        //  to be in an array (even though that's not the case for this Plugin)
        return array(
            $mineral_name_df_id => array($amc_file_df_id),
            $authors_df_id => array($amc_file_df_id),
            $database_code_amcsd_df_id => array($amc_file_df_id),
            $file_contents_df_id => array($amc_file_df_id),
            $a_df_id => array($amc_file_df_id),
            $b_df_id => array($amc_file_df_id),
            $c_df_id => array($amc_file_df_id),
            $alpha_df_id => array($amc_file_df_id),
            $beta_df_id => array($amc_file_df_id),
            $gamma_df_id => array($amc_file_df_id),
            $space_group_df_id => array($amc_file_df_id),
        );
    }


    /**
     * Returns an array of datafields where MassEdit should enable the abiilty to run a background
     * job without actually changing their values.
     *
     * @param array $render_plugin_instance
     * @return array An array where the keys are datafield ids, and the values aren't used
     */
    public function getMassEditOverrideFields($render_plugin_instance)
    {
        // TODO - ...don't think i want this firing unless explicitly requested
        return array();

        if ( !isset($render_plugin_instance['renderPluginMap']) )
            throw new ODRException('Invalid plugin config');

        // Only want to override the "AMC File" field in this plugin...
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            if ( $rpf_name === 'AMC File' ) {
                return array(
                    'label' => $render_plugin_instance['renderPlugin']['pluginName'],
                    'fields' => array($rpf['id'] => 1)
                );
            }
        }

        return array();
    }
}
