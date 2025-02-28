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


class AMCSDPlugin implements DatatypePluginInterface, DatafieldDerivationInterface, MassEditTriggerEventInterface, TableResultsOverrideInterface
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
        // The render plugin does 5 things...
        // 1) Change the "File Contents" field to a monospace font (in both Display and Edit)
        // 2) Warn when the uploaded "AMC File" or "CIF File" has problems (in both Display and Edit)
        // 3) The upload javascript for the "AMC File" or "CIF File" needs to refresh the page after upload (in Edit)
        // 4) The "fileno" field needs to have autogenerated values (in FakeEdit)
        // 5) Provides the ability to force derivations without uploading the file again (in MassEdit)
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            if ( $context === 'display'
                || $context === 'edit'
                || $context === 'fake_edit'
            ) {
                // ...so execute the render plugin if being called from these contexts
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
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Retrieve mapping between datafields and render plugin fields
            $plugin_fields = array();
            $editable_datafields = array();

            // Want to locate the values for most of the mapped datafields
            $optional_fields = array(
                // ...I don't think any of AMCSD's fields qualify as "optional", actually
            );

            // Would prefer the built-in file renaming feature to not work when the FileRenamer
            //  plugin is active...
            // Thanks to long covid this coupling is the least horrible method I can figure out
            // TODO - fix this somehow, please
            $extra_plugins = array();

            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null) {
                    // Optional fields don't have to exist for this plugin to work
                    if ( isset($optional_fields[$rpf_name]) )
                        continue;

                    // If the datafield doesn't exist in the datatype_array, then either the datafield
                    //  is non-public and the user doesn't have permissions to view it (most likely),
                    //  or the plugin somehow isn't configured correctly

                    // Technically, the plugin isn't really affected when the user can't see a field...
                    if ( !$is_datatype_admin )
                        // ...but there are zero compelling reasons to run the plugin if something is missing
                        return '';
                    else
                        // ...if a datatype admin is seeing this, then they need to fix it
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id.'...check plugin config.');
                }
                else {
                    // The non-optional fields really should all be public...so actually throw an
                    //  error if any of them aren't and the user can do something about it
                    if ( isset($optional_fields[$rpf_name]) )
                        continue;

                    // If the datafield is non-public...
                    $df_public_date = ($df['dataFieldMeta']['publicDate'])->format('Y-m-d H:i:s');
                    if ( $df_public_date == '2200-01-01 00:00:00' ) {
                        if ( !$is_datatype_admin )
                            // ...but the user can't do anything about it, then just refuse to execute
                            return '';
                        else
                            // ...the user can do something about it, so they need to fix it
                            throw new \Exception('The field "'.$rpf_name.'" is not public...all fields which are a part of this render plugin MUST be public.');
                    }
                }

                // Need to tweak display parameters for several of the fields...
                $plugin_fields[$rpf_df_id] = $rpf_df;
                $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;

                // Would prefer the built-in file renaming feature to not work when the FileRenamer
                //  plugin is active...
                // Thanks to long covid this coupling is the least horrible method I can figure out
                // TODO - fix this somehow, please
                foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                    $df_id = $df['id'];
                    $render_plugin_classname = $rpi['renderPlugin']['pluginClassName'];

                    if ( !isset($extra_plugins[$df_id]) )
                        $extra_plugins[$df_id] = array();

                    $extra_plugins[$df_id][$render_plugin_classname] = $rpi;
                }

                // These strings are the "name" entries for each of the required fields
                // So, "database_code_amcsd", not "database code"
                switch ($rpf_name) {
                    case 'fileno':

                    // These three can be edited
//                    case 'amc_file':
//                    case 'cif_file':
//                    case 'dif_file':

                    case 'AMC File Contents':
                    case 'AMC File Contents (short)':
                    case 'database_code_amcsd':
                    case 'Authors':
                    case 'Mineral':
                    case 'a':
                    case 'b':
                    case 'c':
                    case 'alpha':
                    case 'beta':
                    case 'gamma':
                    case 'Crystal System':
                    case 'Point Group':
                    case 'Space Group':
                    case 'Lattice':
                    case 'Pressure':
                    case 'Temperature':
                        // None of these fields can be edited, since they're from the AMC file

                    case 'CIF File Contents':
                    case 'Volume':
                    case 'Chemistry':
                    case 'Chemistry Elements':
                    case 'Locality':
                        // These fields can't be edited, since they're from the CIF file

                    case 'Diffraction Search Values':
                        // This field can't be edited, since it's from the DIF file
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
                // Need to be able to pass this option along if doing edit mode
                $edit_shows_all_fields = $rendering_options['edit_shows_all_fields'];

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
                        'edit_shows_all_fields' => $edit_shows_all_fields,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,

                        'plugin_fields' => $plugin_fields,
                        'extra_plugins' => $extra_plugins,
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
                // Point Groups and Space Groups should be modified with CSS for display mode
                $field_values = array('Point Group' => '', 'Space Group' => '');

                $point_group_df_id = $fields['Point Group']['id'];
                if ( isset($datarecord['dataRecordFields'][$point_group_df_id]['shortVarchar'][0]['value']) ) {
                    $pg = $datarecord['dataRecordFields'][$point_group_df_id]['shortVarchar'][0]['value'];
                    $field_values['Point Group'] = self::applySymmetryCSS($pg);
                }
                $space_group_df_id = $fields['Space Group']['id'];
                if ( isset($datarecord['dataRecordFields'][$space_group_df_id]['shortVarchar'][0]['value']) ) {
                    $sg = $datarecord['dataRecordFields'][$space_group_df_id]['shortVarchar'][0]['value'];
                    $field_values['Space Group'] = self::applySymmetryCSS($sg);
                }

                $record_display_view = 'single';
                if ( isset($rendering_options['record_display_view']) )
                    $record_display_view = $rendering_options['record_display_view'];

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

                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'record_display_view' => $record_display_view,
                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'plugin_fields' => $plugin_fields,
                        'field_values' => $field_values,
                    )
                );
            }

            // If executing from the Display or Edit modes...
            if ( $rendering_options['context'] === 'display' || $rendering_options['context'] === 'edit' ) {
                // ...there's a chance that there are files uploaded to the various file datafields,
                //  but none of the relevant datafields have a value in them

                // Read the cached datarecord to get all current values in it
                $value_mapping = self::getValueMapping($datarecord);

                // Determine if any of the categories of fields have a problem
                $amc_problems = self::amcFileHasProblem($plugin_fields, $value_mapping);
                $cif_problems = self::cifFileHasProblem($plugin_fields, $value_mapping);
                $dif_problems = self::difFileHasProblem($plugin_fields, $value_mapping);
                $symmetry_problems = self::symmetryHasProblem($plugin_fields, $value_mapping);

                if ( !empty($amc_problems) ) {
                    // Determine whether the user can edit the "AMC File" datafield
                    $can_edit_relevant_datafield = false;
                    if ( $is_datatype_admin )
                        $can_edit_relevant_datafield = true;

                    $df_id = array_search('AMC File', $editable_datafields);
                    if ( isset($datafield_permissions[$df_id]['edit']) )
                        $can_edit_relevant_datafield = true;

                    $error_div = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_error.html.twig',
                        array(
                            'rpf_name' => 'AMC File',
                            'problem_fields' => self::formatProblemFields($amc_problems),
                            'can_edit_relevant_datafield' => $can_edit_relevant_datafield,
                        )
                    );

                    $output = $error_div . $output;
                }

                if ( !empty($cif_problems) ) {
                    // Determine whether the user can edit the "CIF File" datafield
                    $can_edit_relevant_datafield = false;
                    if ( $is_datatype_admin )
                        $can_edit_relevant_datafield = true;

                    $df_id = array_search('CIF File', $editable_datafields);
                    if ( isset($datafield_permissions[$df_id]['edit']) )
                        $can_edit_relevant_datafield = true;

                    $error_div = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_error.html.twig',
                        array(
                            'rpf_name' => 'CIF File',
                            'problem_fields' => self::formatProblemFields($cif_problems),
                            'can_edit_relevant_datafield' => $can_edit_relevant_datafield,
                        )
                    );

                    $output = $error_div . $output;
                }

                if ( !empty($dif_problems) ) {
                    // Determine whether the user can edit the "DIF File" datafield
                    $can_edit_relevant_datafield = false;
                    if ( $is_datatype_admin )
                        $can_edit_relevant_datafield = true;

                    $df_id = array_search('DIF File', $editable_datafields);
                    if ( isset($datafield_permissions[$df_id]['edit']) )
                        $can_edit_relevant_datafield = true;

                    $error_div = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_error.html.twig',
                        array(
                            'rpf_name' => 'DIF File',
                            'problem_fields' => self::formatProblemFields($dif_problems),
                            'can_edit_relevant_datafield' => $can_edit_relevant_datafield,
                        )
                    );

                    $output = $error_div . $output;
                }

                // ...there's also a chance that there was a problem deriving the Point Group and/or
                //  Crystal System from the Space group
                if ( !empty($symmetry_problems) ) {
                    // Determine whether the user can edit the "CIF File" datafield
                    $can_edit_relevant_datafield = false;
                    if ( $is_datatype_admin )
                        $can_edit_relevant_datafield = true;

                    $df_id = array_search('CIF File', $editable_datafields);
                    if ( isset($datafield_permissions[$df_id]['edit']) )
                        $can_edit_relevant_datafield = true;

                    $error_div = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_error.html.twig',
                        array(
                            'rpf_name' => 'Space Group',
                            'problem_fields' => self::formatProblemFields($symmetry_problems),
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
                // XYZData fields will have two entries remaining at this time
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
     * Most of the fields defined by this plugin derive their values from a file uploaded into the
     * "AMC File" field...if there's an error where the values can't be derived, then the user needs
     * to be notified...
     *
     * @param array $plugin_fields
     * @param array $value_mapping
     *
     * @return array A list of fields which could not be derived from the AMC File
     */
    private function amcFileHasProblem($plugin_fields, $value_mapping)
    {
        // Need to locate the "AMC File" datafield...
        $df_id = null;
        foreach ($plugin_fields as $num => $rpf_df) {
            // NOTE - technically $num is the datafield_id, but don't want to overwrite $df_id yet
            if ( $rpf_df['rpf_name'] === 'AMC File' ) {
                $df_id = $rpf_df['id'];
                break;
            }
        }
        // The "AMC File" field can't have a problem if there is no file uploaded...
        if ( empty($value_mapping[$df_id]) )
            return array();

        // Otherwise, there's something uploaded to the "AMC File" datafield...therefore, the other
        //  fields defined by this render plugin should all have a value
        $problem_fields = array();

        foreach ($plugin_fields as $df_id => $rpf_df) {
            switch ( $rpf_df['rpf_name'] ) {
                case 'AMC File Contents':
                case 'AMC File Contents (short)':
                case 'database_code_amcsd':
                case 'Authors':
                case 'Mineral':
                case 'a':
                case 'b':
                case 'c':
                case 'alpha':
                case 'beta':
                case 'gamma':
                case 'Space Group':
                    // If the AMC file is valid, then every one of these fields will have a value
                    if ( !isset($value_mapping[$df_id]) || is_null($value_mapping[$df_id]) || $value_mapping[$df_id] === '' )
                        $problem_fields[] = $rpf_df['rpf_name'];

                    // NOTE: the Point Group, Crystal System, and Lattice fields aren't included
                    //  here on purpose...a problem with them is more likely to be ODR's fault than
                    //  the fault of this file
                    break;

                // These two fields are optional...the AMC file may not have them
//                case 'Pressure':
//                case 'Temperature':
//                    break;

                default:
                    // Every other field the plugin specifies doesn't matter when trying to determine
                    //  if a File has problems
                    break;
            }
        }

        // Return which fields (if any) lack a value
        return $problem_fields;
    }


    /**
     * Like the "AMC File" field, the "CIF File" field is used to derive the contents of other
     * fields...but the "CIF File" doesn't need to provide as many values.  Still, if there was an
     * error, then the user needs to be notified...
     *
     * @param array $plugin_fields
     * @param array $value_mapping
     *
     * @return array A list of fields which could not be derived from the CIF File
     */
    private function cifFileHasProblem($plugin_fields, $value_mapping)
    {
        // Need to locate the "CIF File" datafield...
        $df_id = null;
        foreach ($plugin_fields as $num => $rpf_df) {
            // NOTE - technically $num is the datafield_id, but don't want to overwrite $df_id yet
            if ( $rpf_df['rpf_name'] === 'CIF File' ) {
                $df_id = $rpf_df['id'];
                break;
            }
        }
        // The "CIF File" field can't have a problem if there is no file uploaded...
        if ( empty($value_mapping[$df_id]) )
            return array();

        // Otherwise, there's something uploaded to the "CIF File" datafield...therefore, the other
        //  fields defined by this render plugin should all have a value
        $problem_fields = array();

        foreach ($plugin_fields as $df_id => $rpf_df) {
            switch ( $rpf_df['rpf_name'] ) {
                // These fields are derived from the CIF File
                case 'CIF File Contents':
                case 'Volume':
                case 'Chemistry':
                case 'Chemistry Elements':
                    // If the CIF file is valid, then every one of these fields will have a value
                    if ( !isset($value_mapping[$df_id]) || is_null($value_mapping[$df_id]) || $value_mapping[$df_id] === '' )
                        $problem_fields[] = $rpf_df['rpf_name'];
                    break;

                // These fields are optional...the CIF file isn't required to have them
//                case 'Locality':
//                    break;

                default:
                    // Every other field the plugin specifies doesn't matter when trying to determine
                    //  if a File has problems
                    break;
            }
        }

        // Return which fields (if any) lack a value
        return $problem_fields;
    }


    /**
     * Like the "AMC File" field, the "DIF File" field is used to derive the contents of other
     * fields...but the "DIF File" doesn't need to provide as many values.  Still, if there was an
     * error, then the user needs to be notified...
     *
     * @param array $plugin_fields
     * @param array $value_mapping
     *
     * @return array A list of fields which could not be derived from the DIF File
     */
    private function difFileHasProblem($plugin_fields, $value_mapping)
    {
        // Need to locate the "DIF File" datafield...
        $df_id = null;
        foreach ($plugin_fields as $num => $rpf_df) {
            // NOTE - technically $num is the datafield_id, but don't want to overwrite $df_id yet
            if ( $rpf_df['rpf_name'] === 'DIF File' ) {
                $df_id = $rpf_df['id'];
                break;
            }
        }
        // The "DIF File" field can't have a problem if there is no file uploaded...
        if ( empty($value_mapping[$df_id]) )
            return array();

        // Otherwise, there's something uploaded to the "DIF File" datafield...therefore, the other
        //  fields defined by this render plugin should all have a value
        $problem_fields = array();

        foreach ($plugin_fields as $df_id => $rpf_df) {
            switch ( $rpf_df['rpf_name'] ) {
                // These fields are derived from the DIF File
                case 'Diffraction Search Values':
                    // If the DIF file is valid, then every one of these fields will have a value
                    if ( !isset($value_mapping[$df_id]) || is_null($value_mapping[$df_id]) || $value_mapping[$df_id] === '' )
                        $problem_fields[] = $rpf_df['rpf_name'];
                    break;

                default:
                    // Every other field the plugin specifies doesn't matter when trying to determine
                    //  if a File has problems
                    break;
            }
        }

        // Return which fields (if any) lack a value
        return $problem_fields;
    }


    /**
     * The contents of the "Space Group" field gets used to derive the contents for the "Lattice",
     * "Point Group", and "Crystal System" fields...but this isn't necessarily guaranteed to work
     * because of the flexibility permitted in defining "Space Group" fields...
     *
     * This is separate from self::cifFileHasProblems() because a problem here is more lkely to be
     * ODR's fault than the fault of the AMC/CIF files.
     *
     * @param array $plugin_fields
     * @param array $value_mapping
     *
     * @return array A list of fields which could not be derived from the Space Group
     */
    private function symmetryHasProblem($plugin_fields, $value_mapping)
    {
        // Need to locate the "Space Group" datafield...
        $df_id = null;
        foreach ($plugin_fields as $num => $rpf_df) {
            // NOTE - technically $num is the datafield_id, but don't want to overwrite $df_id yet
            if ( $rpf_df['rpf_name'] === 'Space Group' ) {
                $df_id = $rpf_df['id'];
                break;
            }
        }
        // The various symmetry fields can't have a problem if the space group is empty...
        if ( !isset($value_mapping[$df_id]) || $value_mapping[$df_id] === '' )
            return array();

        // Otherwise, there's something uploaded to the "CIF File" datafield...therefore, the other
        //  fields defined by this render plugin should all have a value
        $problem_fields = array();

        foreach ($plugin_fields as $df_id => $rpf_df) {
            switch ( $rpf_df['rpf_name'] ) {
                // If the Space Group is valid, then every one of these fields will have a value
                case 'Point Group':
                case 'Crystal System':
                case 'Lattice':
                    // All of these fields need to have a value for the CIF file to be valid...
                    if ( !isset($value_mapping[$df_id]) || is_null($value_mapping[$df_id]) || $value_mapping[$df_id] === '' )
                        $problem_fields[] = $rpf_df['rpf_name'];
                    break;

                default:
                    // Every other field the plugin specifies doesn't matter when trying to determine
                    //  if a File has problems
                    break;
            }
        }

        // Return which fields (if any) lack a value
        return $problem_fields;
    }


    /**
     * Converts a list of field names into a format suitable for an error message
     *
     * @param array $field_list
     * @return string
     */
    private function formatProblemFields($field_list)
    {
        $str = '';
        $count = count($field_list);

        if ( $count == 1 ) {
            $str = '"'.$field_list[0].'" field';
        }
        else if ( $count == 2 ) {
            $str = '"'.$field_list[0].'" and "'.$field_list[1].'" fields';
        }
        else {
            foreach ($field_list as $num => $fieldname) {
                if ( ($num+1) < $count )
                    $str .= '"'.$fieldname.'", ';
                else
                    $str .= ' and "'.$fieldname.'" fields';
            }
        }

        return $str;
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
        $relevant_rpf_name = false;
        $local_filepath = null;

        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $file = null;
        $datarecord = null;
        $datafield = null;
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
            $relevant_rpf_name = self::isEventRelevant($datafield);
            if ( $relevant_rpf_name ) {
                // ----------------------------------------
                // This file was uploaded to the correct field, so it now needs to be processed
                $this->logger->debug('Attempting to read file '.$file->getId().' "'.$file->getOriginalFileName().'", uploaded to the "'.$relevant_rpf_name.'" field...', array(self::class, 'onFilePreEncrypt()', 'File '.$file->getId()));

                // Since the file hasn't been encrypted yet, it's currently in something of an odd
                //  spot as far as ODR files usually go...getLocalFileName() returns a directory
                //  instead of a full path
                $local_filepath = $file->getLocalFileName().'/'.$file->getOriginalFileName();


                // ----------------------------------------
                // Create as much of the mappings as possible, since they could be needed during
                //  error recovery...

                // Map the field definitions in the render plugin to datafields
                $datafield_mapping = self::getRenderPluginFieldsMapping($datatype);

                // If this is a request off a DIF file field...
                if ( $relevant_rpf_name === 'DIF File' ) {
                    // ...then want $datafield to refer to the XYZData datafield instead of the DIF
                    //  File field ASAP in case of an error
                    $df_id = $datafield_mapping['Diffraction Search Values'];

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
                // The provided file hasn't been encrypted yet, so it should be able to be read
                //  directly
                $handle = fopen($local_filepath, 'r');
                if ($handle === false)
                    throw new \Exception('Unable to open existing file at "'.$local_filepath.'"');

                $value_mapping = array();
                if ( $relevant_rpf_name === 'AMC File' ) {
                    // Extract as many pieces of data from the file as possible
                    $value_mapping = self::readAMCFile($handle);
                }
                else if ( $relevant_rpf_name === 'CIF File' ) {
                    // Extract as many pieces of data from the file as possible
                    $value_mapping = self::readCIFFile($handle);
                }
                else if ( $relevant_rpf_name === 'DIF File' ) {
                    // Extract as many pieces of data from the file as possible
                    $value_mapping = self::readDIFFile($handle);
                }

                // No longer need the file to be open
                fclose($handle);

                // File hasn't been encrypted yet, so DO NOT delete it


                // ----------------------------------------
                if ( $relevant_rpf_name === 'DIF File' ) {
                    // Should only be one piece of data...
                    foreach ($value_mapping as $rpf_name => $value) {
                         $this->xyzdata_helper_service->updateXYZData(
                            $user,
                            $datarecord,
                            $datafield,    // NOTE: this is now the XYZData datafield, not the DIF File datafield
                            new \DateTime(),
                            $value,
                            true    // shouldn't be anything in the field, but replace everything anyways
                        );
                        $this->logger->debug(' -- updating XYZData datafield '.$datafield->getId().' ('.$rpf_name.') to have the value "'.$value.'"', array(self::class, 'onFilePreEncrypt()', 'File '.$file->getId()));
                    }
                }
                else {
                    // Each piece of data from the file is referenced by its RenderPluginField name...
                    foreach ($value_mapping as $rpf_name => $value) {
                        // ...from which the actual datafield id can be located...
                        $df_id = $datafield_mapping[$rpf_name];
                        // ...which gives the hydrated storage entity...
                        $entity = $storage_entities[$df_id];
                        /** @var IntegerValue|DecimalValue|ShortVarchar|MediumVarchar|LongVarchar|LongText $entity */

                        // ...which is saved in the storage entity for the datafield
                        $this->entity_modify_service->updateStorageEntity(
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

                    // Now that all the fields have their correct value, flush all changes
                    $this->em->flush();
                }
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onFilePreEncrypt()', 'File '.$file->getId()));

            if ( $relevant_rpf_name ) {
                // If an error was thrown, attempt to ensure the related AMCSD fields are blank
                self::saveOnError($user, $datarecord, $datafield, $file, $storage_entities);
            }

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( $relevant_rpf_name ) {
                $this->logger->debug('All changes saved from "'.$relevant_rpf_name.'"', array(self::class, 'onFilePreEncrypt()', 'File '.$file->getId()));
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
        $relevant_rpf_name = false;

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
            $relevant_rpf_name = self::isEventRelevant($datafield);
            if ( $relevant_rpf_name ) {
                // This file was deleted from a relevant field, so it now needs to be processed
                $this->logger->debug('Attempting to clear values derived from deleted "'.$relevant_rpf_name.'"...', array(self::class, 'onFileDeleted()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));

                // ----------------------------------------
                // Create as much of the mappings as possible, since they could be needed during
                //  error recovery...

                // Map the field definitions in the render plugin to datafields
                $datafield_mapping = self::getRenderPluginFieldsMapping($datatype);

                // If this is a request off a DIF file field...
                if ( $relevant_rpf_name === 'DIF File' ) {
                    // ...then want $datafield to refer to the XYZData datafield instead of the DIF
                    //  File field ASAP in case of an error
                    $df_id = $datafield_mapping['Diffraction Search Values'];

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
                if ( $relevant_rpf_name === 'DIF File' ) {
                    $this->xyzdata_helper_service->updateXYZData(
                        $user,
                        $datarecord,
                        $datafield,    // NOTE: this is now the XYZData datafield, not the DIF File datafield
                        new \DateTime(),
                        '',
                        true    // ensure everything in the field is deleted
                    );
                    $this->logger->debug(' -- updating XYZData datafield '.$datafield->getId().' (Diffraction Search Values) to have the value ""', array(self::class, 'onFileDeleted()', 'df '.$event->getDatafield()->getId(), 'dr '.$datarecord->getId()));
                }
                else {
                    // Each relevant field required by the render plugin needs to be cleared...
                    foreach ($storage_entities as $df_id => $entity) {
                        $rpf_name = array_search($df_id, $datafield_mapping);

                        /** @var IntegerValue|DecimalValue|ShortVarchar|MediumVarchar|LongVarchar|LongText $entity */
                        $this->entity_modify_service->updateStorageEntity(
                            $user,
                            $entity,
                            array('value' => ''),
                            true,    // don't flush immediately
                            false    // don't fire PostUpdate event...nothing depends on these fields
                        );
                        $this->logger->debug('-- updating datafield '.$df_id.' ('.$rpf_name.') to have the value ""', array(self::class, 'onFileDeleted()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));
                    }

                    // Now that all the fields have their correct value, flush all changes
                    $this->em->flush();
                }

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
            if ( $relevant_rpf_name ) {
                $this->logger->debug('All changes saved from "'.$relevant_rpf_name.'"', array(self::class, 'onFileDeleted()', 'df '.$datafield->getId(), 'dr '.$datarecord->getId()));
                self::clearCacheEntries($datarecord, $user, $storage_entities);
            }
        }
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

                    // If this is a request off a DIF file field...
                    if ( $relevant_rpf_name === 'DIF File' ) {
                        // ...then want $datafield to refer to the XYZData datafield instead of the DIF
                        //  File field ASAP in case of an error
                        $df_id = $datafield_mapping['Diffraction Search Values'];

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
                    if ( $relevant_rpf_name === 'AMC File' ) {
                        // Extract as many pieces of data from the file as possible
                        $value_mapping = self::readAMCFile($handle);
                    }
                    else if ( $relevant_rpf_name === 'CIF File' ) {
                        // Extract as many pieces of data from the file as possible
                        $value_mapping = self::readCIFFile($handle);
                    }
                    else if ( $relevant_rpf_name === 'DIF File' ) {
                        // Extract as many pieces of data from the file as possible
                        $value_mapping = self::readDIFFile($handle);
                    }

                    // No longer need the file to be open
                    fclose($handle);

                    // If the File isn't public, then delete its decrypted version off the server
                    if ( !$file->isPublic() )
                        unlink($local_filepath);


                    // ----------------------------------------
                    if ( $relevant_rpf_name === 'DIF File' ) {
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
                    else {
                        // Each piece of data from the file is referenced by its RenderPluginField name...
                        foreach ($value_mapping as $rpf_name => $value) {
                            // ...from which the actual datafield id can be located...
                            $df_id = $datafield_mapping[$rpf_name];
                            // ...which gives the hydrated storage entity...
                            $entity = $storage_entities[$df_id];
                            /** @var IntegerValue|DecimalValue|ShortVarchar|MediumVarchar|LongVarchar|LongText $entity */

                            // ...which is saved in the storage entity for the datafield
                            $this->entity_modify_service->updateStorageEntity(
                                $user,
                                $entity,
                                array('value' => $value),
                                true,    // don't flush immediately
                                false    // don't fire PostUpdate event...nothing depends on these fields
                            );
                            $this->logger->debug(' -- updating datafield '.$df_id.' ('.$rpf_name.') to have the value "'.$value.'"', array(self::class, 'onMassEditTrigger()', 'File '.$file->getId()));

                            // This only works because the datafields getting updated aren't files/images or
                            //  radio/tag fields
                        }

                        // Now that all the fields have their correct value, flush all changes
                        $this->em->flush();
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
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.amcsd' ) {
                // Datatype is using the correct plugin...
                if ( isset($rpi['renderPluginMap']['AMC File'])
                    && $rpi['renderPluginMap']['AMC File']['id'] === $datafield->getId()
                ) {
                    // ...and the datafield that triggered the event is the "AMC File" datafield
                    return 'AMC File';
                }
                else if ( isset($rpi['renderPluginMap']['CIF File'])
                    && $rpi['renderPluginMap']['CIF File']['id'] === $datafield->getId()
                ) {
                    // ...and the datafield that triggered the event is the "CIF File" datafield
                    return 'CIF File';
                }
                else if ( isset($rpi['renderPluginMap']['DIF File'])
                    && $rpi['renderPluginMap']['DIF File']['id'] === $datafield->getId()
                ) {
                    // ...and the datafield that triggered the event is the "DIF File" datafield
                    return 'DIF File';
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
                case 'AMC File Contents':
                case 'AMC File Contents (short)':
                case 'database_code_amcsd':
                case 'Authors':
                case 'Mineral':
                case 'a':
                case 'b':
                case 'c':
                case 'alpha':
                case 'beta':
                case 'gamma':
                case 'Crystal System':
                case 'Point Group':
                case 'Space Group':
                case 'Lattice':
                case 'Pressure':
                case 'Temperature':

                case 'CIF File Contents':
                case 'Volume':
                case 'Chemistry':
                case 'Chemistry Elements':
                case 'Locality':

                case 'Diffraction Search Values':
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
     * @param string $relevant_rpf_name
     *
     * @throws \Exception
     *
     * @return array
     */
    private function hydrateStorageEntities($datafield_mapping, $user, $datarecord, $relevant_rpf_name)
    {
        // Need to hydrate various datafields, depending on which File field just got something uploaded...
        $df_ids = array();
        foreach ($datafield_mapping as $rpf_name => $df_id) {
            if ( $relevant_rpf_name === 'AMC File' ) {
                switch ($rpf_name) {
                    case 'AMC File Contents':
                    case 'AMC File Contents (short)':
                    case 'database_code_amcsd':
                    case 'Authors':
                    case 'Mineral':
                    case 'a':
                    case 'b':
                    case 'c':
                    case 'alpha':
                    case 'beta':
                    case 'gamma':
                    case 'Crystal System':
                    case 'Point Group':
                    case 'Space Group':
                    case 'Lattice':
                    case 'Pressure':
                    case 'Temperature':
                        // When called on an "AMC File", all of these fields should be hydrated
                        break;

                    default:
                        // ...any other field should not
                        unset( $datafield_mapping[$rpf_name] );
                        break;
                }
            }
            else if ( $relevant_rpf_name === 'CIF File' ) {
                switch ($rpf_name) {
                    case 'CIF File Contents':
                    case 'Volume':
                    case 'Chemistry':
                    case 'Chemistry Elements':
                    case 'Locality':
                        // When called on a "CIF File", all of these fields should be hydrated
                        break;

                    default:
                        // ...any other field should not
                        unset( $datafield_mapping[$rpf_name] );
                        break;
                }
            }
            else if ( $relevant_rpf_name === 'DIF File' ) {
                switch ($rpf_name) {
                    case 'Diffraction Search Values':
                        // When called on a "DIF File", all of these fields should be hydrated
                        break;

                    default:
                        // ...any other field should not
                        unset( $datafield_mapping[$rpf_name] );
                        break;
                }
            }
        }

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
            if ( $rpf_name === 'Diffraction Search Values' ) {
                // Do NOT attempt to run $entity_create_service->createXYZValue()
            }
            else {
                $df = $hydrated_datafields[$df_id];
                $entity = $this->entity_create_service->createStorageEntity($user, $datarecord, $df);

                // Store the (likely newly created) storage entity for the next step
                $storage_entities[$df_id] = $entity;
            }
        }

        // Return the hydrated list of storage entities
        return $storage_entities;
    }


    /**
     * Reads the given AMC file, converting its contents into an array that's indexed by the
     * "name" property of the fields defined in the "required_fields" section of AMCSDPlugin.yml
     *
     * @param resource $handle
     *
     * @return array
     */
    private function readAMCFile($handle)
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

            // First line is supposed to be the mineral name...needs to fit in a MediumVarchar
            if ($line_num == 1) {
                if ( ValidUtility::isValidMediumVarchar($line) )
                    $value_mapping['Mineral'] = $line;
            }
            // Second line is the authors...no length limit
            elseif ($line_num == 2) {
                $value_mapping['Authors'] = $line;
            }
            // Third line is the journal, but it's not useful
//            elseif ($line_num == 3) {
//                $value_mapping['Journal'] = $line;
//            }

            // Next there's usually two (sometimes three) lines of stuff the plugin doesn't care about

            // The line starting with "_database_code_amcsd" is the next important one...
            elseif ( strpos($line, '_database_code_amcsd') === 0 ) {
                $pieces = explode(' ', $line);

                // Need to have 2 values in this line
                if ( count($pieces) === 2 )
                    $value_mapping['database_code_amcsd'] = $pieces[1];

                // Need to save this line number, because the cell params come immediately after
                $database_code_line = $line_num;
            }
            // The line after that contains the a/b/c/alpha/beta/gamma/space group values
            elseif ( ($database_code_line+1) === $line_num ) {
                $line = trim( preg_replace('/\s\s+/', ' ', $line) );
                // The replacement could end up stripping the newline from the end, which is bad
                if ( strpos($line, "\n") === false )
                    $line .= "\n";
                $pieces = explode(' ', $line);

                // Need to have 7 values in this line
                if ( count($pieces) === 7 ) {
                    // The first six need to be valid decimal values
                    if ( ValidUtility::isValidDecimal($pieces[0]) )
                        $value_mapping['a'] = $pieces[0];
                    if ( ValidUtility::isValidDecimal($pieces[1]) )
                        $value_mapping['b'] = $pieces[1];
                    if ( ValidUtility::isValidDecimal($pieces[2]) )
                        $value_mapping['c'] = $pieces[2];
                    if ( ValidUtility::isValidDecimal($pieces[3]) )
                        $value_mapping['alpha'] = $pieces[3];
                    if ( ValidUtility::isValidDecimal($pieces[4]) )
                        $value_mapping['beta'] = $pieces[4];
                    if ( ValidUtility::isValidDecimal($pieces[5]) )
                        $value_mapping['gamma'] = $pieces[5];

                    // Space Group are usually 5-12ish characters long, so they fit inside a ShortVarchar
                    if ( ValidUtility::isValidShortVarchar($pieces[6]) ) {
                        $sg = trim( $pieces[6] );

                        // For historical reasons, AMC files might have a '*' before the space group
                        //  ...this apparently meant that the calculated x/y/z coords of the atoms
                        //  in the file were shifted, compared to would be "expected" based on the
                        //  space group.  This '*' character shouldn't be saved if it exists
                        if ( strpos($sg, '*') === 0 )
                            $sg = substr($sg, 1);

                        // Certain AMC files also apparently have a ':1' or whatever after the Space
                        //  Group, which also provides extra information to other programs...
                        if ( strpos($sg, ':') !== false )
                            $sg = substr($sg, 0, strpos($sg, ':'));

                        // The Lattice, Point Group, and Crystal System are then derived from the Space Group
                        $lattice = substr($sg, 0, 1);
                        $pg = CrystallographyDef::derivePointGroupFromSpaceGroup($sg);
                        $cs = CrystallographyDef::deriveCrystalSystemFromPointGroup($pg);

                        $value_mapping['Crystal System'] = $cs;
                        $value_mapping['Point Group'] = $pg;
                        $value_mapping['Space Group'] = $sg;
                        $value_mapping['Lattice'] = $lattice;
                    }
                }
            }
            else if ( $line_num > 2 && $database_code_line === -999 ) {
                // The pressure/temperature are usually before "_database_code_amcsd" (I think)
                // ...but aren't on a guaranteed line

                // Pressure tends to look like "P = 3 GPa" or "P = 11.1 kbar" or "Pressure = 7.3 GPA"
                //  ...but can also have a tolerance
                $matches = array();
                if ( preg_match('/P(?:ressure)?\s\=\s([0-9\.\(\)]+)\s(\w+)/', $line, $matches) === 1 )
                    $value_mapping['Pressure'] = $matches[1].' '.$matches[2];

                // Temperature tends to look like "T = 200 K" or "T = 359.4K" or "T = 500K"...
                $matches = array();
                if ( preg_match('/T\s\=\s(-?[0-9\.]+)\s?(C|K)/', $line, $matches) === 1)
                    $value_mapping['Temperature'] = $matches[1].' '.$matches[2];
                // ...but can also look like "500 deg C" or "500 degrees C" or "T = 185 degrees C"
                $matches = array();
                if ( preg_match('/(-?[0-9\.]+)\sdeg(?:ree)?(?:s)?\s(C|K)/', $line, $matches) === 1 )
                    $value_mapping['Temperature'] = $matches[1].' '.$matches[2];


                // TODO - I forget whether these values have to be normalized to GPa and K...
            }

            // Save every line from the file, as well
            $all_lines[] = $line;
        }

        // Want all file contents in a single field
        $value_mapping['AMC File Contents'] = implode("", $all_lines);

        // Also want a shorter version of this file's contents without the atom positions...
        $short_lines = array();
        // ...but it's slightly tricky because the line that starts with "atom" isn't guaranteed to
        //  always be two lines after the _database_code_amcsd line
        $short_ending_line = $database_code_line - 1 + 2;  // minus 1 to convert back to 0-based line numbers first
        if ( strpos($all_lines[$short_ending_line], "atom") !== 0 ) {
            // ...if the expected line doesn't start with "atom", then it's the line after that
            $short_ending_line += 1;
        }

        for ($i = 0; $i < $short_ending_line; $i++)
            $short_lines[] = $all_lines[$i];
        $value_mapping['AMC File Contents (short)'] = implode("", $short_lines);

        // Ensure all values are trimmed before they're saved
        foreach ($value_mapping as $rpf_name => $value)
            $value_mapping[$rpf_name] = trim($value);

        // All data gathered, return the mapping array
        return $value_mapping;
    }


    /**
     * Reads the given CIF file, converting its contents into an array that's indexed by the
     * "name" property of the fields defined in the "required_fields" section of AMCSDPlugin.yml
     *
     * @param resource $handle
     *
     * @return array
     */
    private function readCIFFile($handle)
    {
        $value_mapping = array();
        $all_lines = array();

        // Ensure we're at the beginning of the file
        fseek($handle, 0, SEEK_SET);
        while ( !feof($handle) ) {
            $line = fgets($handle);

            if ( strpos($line, '_chemical_formula_sum') === 0 ) {
                // This line needs to have at least two pieces in it
                $pieces = explode(' ', $line);
                if ( count($pieces) >= 2 ) {
                    // This line has the chemical formula of the compound
                    $space = strpos($line, ' ');
                    $formula = trim( substr($line, $space+1) );

                    // The formula *should* have single quotes around it...get rid of them
                    $formula = substr($formula, 1, -1);
                    $value_mapping['Chemistry'] = $formula;

                    // The value for the "Chemistry Elements" field is derived from this field, using
                    //  pretty much the same process as the IMA List
                    $ima_pattern = '/(REE|[A-Z][a-z]?)/';    // Attempt to locate 'REE' first, then fallback to a capital letter followed by an optional lowercase letter
                    $ima_matches = array();
                    preg_match_all($ima_pattern, $formula, $ima_matches);

                    // Create a unique list of tokens from the array of elements
                    $chemistry_elements = array();
                    foreach ($ima_matches[1] as $num => $elem)
                        $chemistry_elements[$elem] = 1;
                    $chemistry_elements = array_keys($chemistry_elements);

                    $value_mapping['Chemistry Elements'] = implode(" ", $chemistry_elements);
                }
            }
            else if ( strpos($line, '_chemical_compound_source') === 0 ) {
                // This (optional) line has either 'Synthetic', or the locality of the physical sample
                $space = strpos($line, ' ');
                $locality = trim( substr($line, $space+1) );

                // The locality *should* have single quotes around it...get rid of them
                $locality = substr($locality, 1, -1);

                // Replace either "smart quote" with the ascii equivalent
                $locality = str_replace(array("‘","’"), "'", $locality);    // U+2018 and U+2019
                $locality = str_replace(array("“","”"), "\"", $locality);    // U+201C and U+201D

                // TODO - ...probably need a real solution to avoid saving non-UTF8 characters, not a bootleg one like this :/

                $value_mapping['Locality'] = $locality;
            }
            else if ( strpos($line, '_cell_volume') === 0 ) {
                // This line should have a decimal value for the volume
                $pieces = explode(' ', $line);
                if ( count($pieces) === 2 )
                    $value_mapping['Volume'] = $pieces[1];
            }

            // Save every line from the file, as well
            $all_lines[] = $line;
        }

        // Want all file contents in a single field
        $value_mapping['CIF File Contents'] = implode("", $all_lines);

        // Ensure the values are trimmed before they're saved
        foreach ($value_mapping as $rpf_name => $value)
            $value_mapping[$rpf_name] = trim($value);

        // All data gathered, return the mapping array
        return $value_mapping;
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
        // The relevant section of the DIF file has seven columns...2-THETA, INTENSITY, D-SPACING,
        //  H, K, L, and Multiplicity...this regex extracts the INTENSITY AND D-SPACING columns,
        // discarding the remainder
        $pattern = '/(?:\s+[\d\.]+\s+)([\d\.]+)(?:\s+)([\d\.]+)(?:[^\n]+)/';
        $all_values = array();

        // Ensure we're at the beginning of the file
        fseek($handle, 0, SEEK_SET);

        $header_line = -999;
        $line_num = 0;
        while ( !feof($handle) ) {
            $line = fgets($handle);
            $line_num++;

            if ( strpos($line, 'INTENSITY') !== false && strpos($line, 'D-SPACING') !== false ) {
                // This line needs to have at least two pieces in it
                $header_line = $line_num;
            }
            else if ( $header_line > 0 ) {
                // Want to stop reading the file when it hits the divider below the table
                if ( strpos($line, '===') !== false )
                    break;

                $matches = array();
                $ret = preg_match($pattern, $line, $matches);
                if ( $ret === false )
                    $a = 1;

                $intensity = $matches[1];
                $d_spacing = $matches[2];

                if ( floatval($intensity) >= 4.0)
                    $all_values[] = '('.$d_spacing.','.$intensity.')';
            }
        }

        // Want all file contents in a single field
        $value_mapping['Diffraction Search Values'] = implode("|", $all_values);

        // All data gathered, return the mapping array
        return $value_mapping;
    }


    /**
     * If some sort of error/exception was thrown, then attempt to blank out all the fields derived
     * from the file being read...this won't stop the file from being encrypted, which will allow
     * the renderplugin to recognize and display that something is wrong with this file.
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param DataFields $datafield
     * @param File $file
     * @param array $storage_entities
     */
    private function saveOnError($user, $datarecord, $datafield, $file, $storage_entities)
    {
        try {
            if ( !is_null($datarecord) && !is_null($datafield) && empty($storage_entities) ) {
                $this->xyzdata_helper_service->updateXYZData(
                    $user,
                    $datarecord,
                    $datafield,
                    new \DateTime(),
                    '',
                    true    // ensure the field contents are blank
                );
                $this->logger->debug('-- (ERROR) updating XYZData datafield '.$datafield->getId().' to have the value ""', array(self::class, 'saveOnError()', 'File '.$file->getId()));
            }
            else if ( !empty($storage_entities) ) {
                foreach ($storage_entities as $df_id => $entity) {
                    /** @var IntegerValue|DecimalValue|ShortVarchar|MediumVarchar|LongVarchar|LongText $entity */
                    if ( $entity instanceof XYZData ) {
                        // Ignore this entity here
                    }
                    else {
                        $this->entity_modify_service->updateStorageEntity(
                            $user,
                            $entity,
                            array('value' => ''),
                            true,    // don't flush immediately
                            false    // don't fire PostUpdate event...nothing depends on these fields
                        );
                        $this->logger->debug('-- (ERROR) updating datafield '.$df_id.' to have the value ""', array(self::class, 'saveOnError()', 'File '.$file->getId()));
                    }
                }

                $this->em->flush();
            }
            else {
                throw new ODRException('Invalid parameters sent to AMCSDPlugin::saveOnError()');
            }
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
        $val = intval( substr($old_value, 0, 5) );
        $val += 1;

        // Convert it back into the expected format so the storage entity can get created
        $new_value = str_pad($val, 5, '0', STR_PAD_LEFT).'.amc';
        $this->entity_create_service->createStorageEntity($user, $datarecord, $datafield, $new_value, false);    // guaranteed to not need a PostUpdate event
        $this->logger->debug('Setting df '.$datafield->getId().' "fileno" of new dr '.$datarecord->getId().' to "'.$new_value.'"...', array(self::class, 'onDatarecordCreate()'));

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
            WHERE e.data_field_id = :datafield AND e.value REGEXP "^[0-9]{5,5}\.amc$"
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
     * @inheritDoc
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
        $amc_file_contents_df_id = $render_plugin_map['AMC File Contents']['id'];
        $amc_file_contents_short_df_id = $render_plugin_map['AMC File Contents (short)']['id'];
        $a_df_id = $render_plugin_map['a']['id'];
        $b_df_id = $render_plugin_map['b']['id'];
        $c_df_id = $render_plugin_map['c']['id'];
        $alpha_df_id = $render_plugin_map['alpha']['id'];
        $beta_df_id = $render_plugin_map['beta']['id'];
        $gamma_df_id = $render_plugin_map['gamma']['id'];
        $crystal_system_df_id = $render_plugin_map['Crystal System']['id'];
        $point_group_df_id = $render_plugin_map['Point Group']['id'];
        $space_group_df_id = $render_plugin_map['Space Group']['id'];
        $lattice_df_id = $render_plugin_map['Lattice']['id'];
        $pressure_df_id = $render_plugin_map['Pressure']['id'];
        $temperature_df_id = $render_plugin_map['Temperature']['id'];

        // ...but there are several fields that are supposed to come from the "CIF File" field
        $cif_file_df_id = $render_plugin_map['CIF File']['id'];
        $cif_file_contents_df_id = $render_plugin_map['CIF File Contents']['id'];
        $volume_df_id = $render_plugin_map['Volume']['id'];
        $chemistry_df_id = $render_plugin_map['Chemistry']['id'];
        $chemistry_elements_df_id = $render_plugin_map['Chemistry Elements']['id'];
        $locality_df_id = $render_plugin_map['Locality']['id'];

        // ...and another that's supposed to come from the "DIF File" field
        $dif_file_df_id = $render_plugin_map['DIF File']['id'];
        $diffraction_search_values_df_id = $render_plugin_map['Diffraction Search Values']['id'];


        // Since a datafield could be derived from multiple datafields, the source datafields need
        //  to be in an array (even though that's not the case for this Plugin)
        return array(
            $mineral_name_df_id => array($amc_file_df_id),
            $authors_df_id => array($amc_file_df_id),
            $database_code_amcsd_df_id => array($amc_file_df_id),
            $amc_file_contents_df_id => array($amc_file_df_id),
            $amc_file_contents_short_df_id => array($amc_file_df_id),
            $a_df_id => array($amc_file_df_id),
            $b_df_id => array($amc_file_df_id),
            $c_df_id => array($amc_file_df_id),
            $alpha_df_id => array($amc_file_df_id),
            $beta_df_id => array($amc_file_df_id),
            $gamma_df_id => array($amc_file_df_id),
            $crystal_system_df_id => array($amc_file_df_id), // crystal system, point group, and lattice are technically derived from the space group...
            $point_group_df_id => array($amc_file_df_id),    // ...but doesn't matter since you can't edit space group directly anyways
            $space_group_df_id => array($amc_file_df_id),
            $lattice_df_id => array($amc_file_df_id),
            $pressure_df_id => array($amc_file_df_id),
            $temperature_df_id => array($amc_file_df_id),

            $cif_file_contents_df_id => array($cif_file_df_id),
            $volume_df_id => array($cif_file_df_id),
            $chemistry_df_id => array($cif_file_df_id),
            $chemistry_elements_df_id => array($cif_file_df_id),
            $locality_df_id => array($cif_file_df_id),

            $diffraction_search_values_df_id => array($dif_file_df_id),
        );
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
            'AMC File' => 1,
            'CIF File' => 1,
            'DIF File' => 1,
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
            'AMC File' => 1,
            'CIF File' => 1,
            'DIF File' => 1,
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
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // This render plugin might need to modify two different fields...
        $values = array();
        $current_values = array();

        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            switch ($rpf_name) {
                case 'Point Group':
                case 'Space Group':
                    // This is a field of interest...
                    $df_id = $rpf['id'];
                    $current_values[$rpf_name] = array(
                        'id' => $df_id,
                        'value' => ''
                    );

                    // Need to look through the datarecord to find the current value...both of these
                    //  are ShortVarchar fields
                    if ( isset($datarecord['dataRecordFields'][$df_id]['shortVarchar'][0]['value']) )
                        $current_values[$rpf_name]['value'] = $datarecord['dataRecordFields'][$df_id]['shortVarchar'][0]['value'];
                    break;
            }
        }

        // The Point Group and the Space Group need to have some CSS applied to them
        if ( $current_values['Point Group']['value'] !== '' ) {
            $point_group_df_id = $current_values['Point Group']['id'];
            $values[$point_group_df_id] = self::applySymmetryCSS( $current_values['Point Group']['value'] );
        }
        if ( $current_values['Space Group']['value'] !== '' ) {
            $space_group_df_id = $current_values['Space Group']['id'];
            $values[$space_group_df_id] = self::applySymmetryCSS( $current_values['Space Group']['value'] );
        }

        // Return all modified values
        return $values;
    }


    /**
     * Applies some CSS to a point group or space group value for Display mode.
     *
     * @param string $value
     *
     * @return string
     */
    private function applySymmetryCSS($value)
    {
        if ( strpos($value, '-') !== false )
            $value = preg_replace('/-(\d)/', '<span class="overbar">$1</span>', $value);
        if ( strpos($value, '_') !== false )
            $value = preg_replace('/_(\d)/', '<sub>$1</sub>', $value);

        return $value;
    }
}
