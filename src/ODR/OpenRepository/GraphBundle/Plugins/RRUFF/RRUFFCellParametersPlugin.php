<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Cell Parameters Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This stuff is an implementation of crystallographic space groups, which has been a "solved"
 * problem since the 1890's.  Roughly speaking, it's a hierarchy where space group implies a point
 * group, which implies a crystal system.  You can specify a crystal system without the other two,
 * and can also specify a point group without a space group (very rare)...but it doesn't work in
 * the other direction.
 *
 * The trick is that there is usually more than one way to "denote" a space group, depending on which
 * of the crystal's axes you choose for a/b/c, and by extension alpha/beta/gamma. For instance,
 * "P1", "A1", "B1", "C1", "I1", and "F1" all denote space group #1...but "P2" is the only valid
 * way to denote space group #3.
 *
 * While this could be accomplished via ODR's tag system, doing so would be quite irritating.
 * RRUFF had a system where selecting a crystal system hid all point/space groups that didn't belong
 * to said crystal system, and selecting a point group also hid all space groups that didn't belong
 * to said point group.  This effectively eliminated the possibility of entering invalid data for
 * those three fields, while also making it easier to find what you wanted.
 *
 *
 * Additionally, the references these values were pulled from on RRUFF weren't guaranteed to specify
 * the volume parameter...don't know whether it was out of laziness or an inability to calculate.
 * Regardless, the volume is pretty useful, so RRUFF (and this plugin) calculate the volume from
 * the a/b/c/α/β/γ values if needed.  It's only an approximation, however, because significant
 * figures are ignored...
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
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldReloadOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\CrystallographyDef;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class RRUFFCellParametersPlugin implements DatatypePluginInterface, DatafieldDerivationInterface, DatafieldReloadOverrideInterface, MassEditTriggerEventInterface, TableResultsOverrideInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

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
     * RRUFF Cell Parameters Plugin constructor
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param EntityCreationService $entity_create_service
     * @param EntityMetaModifyService $entity_modify_service
     * @param LockService $lock_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param CsrfTokenManager $token_manager
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        EntityCreationService $entity_create_service,
        EntityMetaModifyService $entity_modify_service,
        LockService $lock_service,
        EventDispatcherInterface $event_dispatcher,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->database_info_service = $database_info_service;
        $this->datarecord_info_service = $datarecord_info_service;
        $this->entity_create_service = $entity_create_service;
        $this->entity_modify_service = $entity_modify_service;
        $this->lock_service = $lock_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->token_manager = $token_manager;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * {@inheritDoc}
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        // The render plugin overrides how the user enters the crystal system, point group, and
        //  space group values...it also derives the value for the lattice field, and attempts to
        //  calculate volume if needed...
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            if ( $context === 'fake_edit'
                || $context === 'display'
                || $context === 'edit'
            ) {
                return true;
            }
        }

        return false;
    }


    /**
     * {@inheritDoc}
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


            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // Retrieve mapping between datafields and render plugin fields
            $plugin_data = self::getPluginData($datatype, $datarecord, $is_datatype_admin);
            // If the previous function decided there was a plugin config issue, then don't execute
            //  the plugin
            if ( is_null($plugin_data) )
                return '';

            // Otherwise, get the returned data
            $plugin_fields = $plugin_data['fields'];
            $field_values = $plugin_data['values'];


            // ----------------------------------------
            // Need to check for derivation problems before rendering...
            $problem_fields = array();
            if ( $rendering_options['context'] === 'display' || $rendering_options['context'] === 'edit' ) {
                $derivation_problems = self::findDerivationProblems($plugin_data);

                // Can't use array_merge() since that destroys the existing keys
                foreach ($derivation_problems as $df_id => $problem)
                    $problem_fields[$df_id] = $problem;
            }

            // If all six cellparameter enties exist...
            $calculated_volume = '';
            if ( !($field_values['a'] === '' || $field_values['b'] === '' || $field_values['c'] === ''
                || $field_values['alpha'] === '' || $field_values['beta'] === '' || $field_values['gamma'] === '')
            ) {
                // ...then calculate (an approximation of) the volume
                $calculated_volume = self::calculateVolume($field_values['a'], $field_values['b'], $field_values['c'], $field_values['alpha'], $field_values['beta'], $field_values['gamma']);
            }


            // ----------------------------------------
            // Going to need several field identifiers, so various fields can be saved/reloaded at
            //  the same time
            $field_identifiers = array(
                'Crystal System' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$fields['Crystal System']['id'],
                'Point Group' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$fields['Point Group']['id'],
                'Space Group' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$fields['Space Group']['id'],
                'Lattice' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$fields['Lattice']['id'],

                // Need this one for the a/b/c/alpha/beta/gamma fields
                'Volume' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$fields['Volume']['id'],
            );

            // Output depends on which context the plugin is being executed from
            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                // Point Groups and Space Groups should be modified with CSS for display mode
                if ( isset($field_values['Point Group']) )
                    $field_values['Point Group'] = self::applySymmetryCSS($field_values['Point Group']);
                if ( isset($field_values['Space Group']) )
                    $field_values['Space Group'] = self::applySymmetryCSS($field_values['Space Group']);

                // If the user hasn't entered a value for volume...
                if ( !isset($field_values['Volume']) || $field_values['Volume'] === '' ) {
                    // ...then use the calculated volume directly
                    $field_values['Volume'] = '<i>'.$calculated_volume.'</i>';
                }

                $record_display_view = 'single';
                if ( isset($rendering_options['record_display_view']) )
                    $record_display_view = $rendering_options['record_display_view'];

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_display_fieldarea.html.twig',
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

                        'field_values' => $field_values,
                        'calculated_volume' => $calculated_volume,
                    )
                );
            }
            else if ( $rendering_options['context'] === 'edit' ) {
                // Tweak the point group and space group arrays so that they're in order...they're
                //  not defined in order, because it's considerably easier to fix any problems with
                //  them when they're arranged by category instead of by name
                $point_groups = self::sortPointGroups();
                $space_groups = self::sortSpaceGroups();

                // Also going to need a token for the custom form submission, since it uses its
                //  own controller action...
                $token_id = 'RRUFFCellParams_'.$initial_datatype_id.'_'.$datarecord['id'];
                $token_id .= '_'.$fields['Crystal System']['id'];
                $token_id .= '_'.$fields['Point Group']['id'];
                $token_id .= '_'.$fields['Space Group']['id'];
                $token_id .= '_Form';
                $form_token = $this->token_manager->getToken($token_id)->getValue();

                // Need to be able to pass this option along if doing edit mode
                $edit_shows_all_fields = $rendering_options['edit_shows_all_fields'];
                $edit_behavior = $rendering_options['edit_behavior'];

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_edit_fieldarea.html.twig',
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

                        'field_identifiers' => $field_identifiers,
                        'form_token' => $form_token,

                        'calculated_volume' => $calculated_volume,
                        'crystal_systems' => CrystallographyDef::$crystal_systems,
                        'point_groups' => $point_groups,
                        'space_groups' => $space_groups,
                    )
                );
            }
            else if ( $rendering_options['context'] === 'fake_edit' ) {

                $prevent_user_edit_df_ids = array(
                    $fields['Crystal System']['id'],
                    $fields['Point Group']['id'],
                    $fields['Space Group']['id'],
                    $fields['Lattice']['id'],
                );

                // This field is optional to allow RRUFF X-Ray Diffraction to use this plugin
                if ( isset($fields['Cell Parameter ID']['id']) )
                    $prevent_user_edit_df_ids[] = $fields['Cell Parameter ID']['id'];

                // Each of these fields need to provide a special token to FakeEditController, because
                //  each of them doesn't allow direct user edits...
                foreach ($prevent_user_edit_df_ids as $df_id) {
                    $token_id = 'FakeEdit_'.$datarecord['id'].'_'.$df_id.'_autogenerated';
                    $token = $this->token_manager->getToken($token_id)->getValue();

                    $special_tokens[$df_id] = $token;
                }

                // Tweak the point group and space group arrays so that they're in alphabetical order
                // They're defined by category because it's considerably easier to fix any problems
                //  with them when they're arranged by category instead of by name
                $point_groups = self::sortPointGroups();
                $space_groups = self::sortSpaceGroups();

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_fakeedit_fieldarea.html.twig',
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
                        'form_token' => '',

                        'plugin_fields' => $plugin_fields,
                        'field_identifiers' => $field_identifiers,

                        'crystal_systems' => CrystallographyDef::$crystal_systems,
                        'point_groups' => $point_groups,
                        'space_groups' => $space_groups,
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
     * Need two different groupings of the data in order to execute the plugin for both regular
     * uses and for reloads...
     *
     * @param array $datatype
     * @param array $datarecord
     * @param bool $is_datatype_admin
     * @return null|array
     */
    private function getPluginData($datatype, $datarecord, $is_datatype_admin)
    {
        $plugin_fields = array();
        $field_values = array();

        // Locate the relevant render plugin instance
        $rpm_entries = null;
        foreach ($datatype['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.cell_parameters' ) {
                $rpm_entries = $rpi['renderPluginMap'];
                break;
            }
        }

        // Want to locate the values for most of the mapped datafields
        $optional_fields = array(
            'Cell Parameter ID' => 0,    // this one can be non-public

            // These four only exist to make the data easier to import
            'Chemistry' => 0,
            'Pressure' => 0,
            'Temperature' => 0,
            'Notes' => 0
        );

        foreach ($rpm_entries as $rpf_name => $rpf_df) {
            // Need to find the real datafield entry in the primary datatype array
            $rpf_df_id = $rpf_df['id'];

            // Need to tweak display parameters for several of the fields...
            $plugin_fields[$rpf_df_id] = $rpf_df;
            $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;

            $df = null;
            if ( isset($datatype['dataFields'][$rpf_df_id]) )
                $df = $datatype['dataFields'][$rpf_df_id];

            if ($df == null) {
                // Optional fields don't have to exist for this plugin to work
                if ( !isset($optional_fields[$rpf_name]) ) {
                    // If the datafield doesn't exist in the datatype_array, then either the datafield
                    //  is non-public and the user doesn't have permissions to view it (most likely),
                    //  or the plugin somehow isn't configured correctly

                    // Technically, the only time when the plugin shouldn't execute is when any of the
                    //  Crystal System/Point Group/Space Group fields don't exist, and the user is
                    //  in Edit mode...and that's already handled by RRUFFCellparamsController...

                    if ( !$is_datatype_admin )
                        // ...but there are zero compelling reasons to run the plugin if something
                        //  is missing
                        return null;
                    else
                        // ...if a datatype admin is seeing this, then they need to fix it
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id.'...check plugin config.');
                }
            }
            else {
                // The non-optional fields really should all be public...
                if ( !isset($optional_fields[$rpf_name]) ) {
                    $df_public_date = ($df['dataFieldMeta']['publicDate'])->format('Y-m-d H:i:s');
                    if ( $df_public_date == '2200-01-01 00:00:00' ) {
                        if ( !$is_datatype_admin )
                            // ...but the user can't do anything about it, so just refuse to execute
                            return null;
                        else
                            // ...the user can do something about it, so they need to fix it
                            throw new \Exception('The field "'.$rpf_name.'" is not public...all fields which are a part of this render plugin MUST be public.');
                    }
                }
            }

            // Might need to reference the values of each of these fields
            if ( !is_null($df) ) {
                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                switch ($rpf_name) {
//                case 'Cell Parameter ID':
                    case 'Crystal System':
                    case 'Point Group':
                    case 'Space Group':
                    case 'Lattice':
                    case 'a':
                    case 'b':
                    case 'c':
                    case 'alpha':
                    case 'beta':
                    case 'gamma':
                    case 'Volume':
//                case 'Chemistry':
//                case 'Pressure':
//                case 'Temperature':
//                case 'Notes':

                        $value = '';
                        if ($typeclass === 'ShortVarchar' &&
                            isset($datarecord['dataRecordFields'][$rpf_df_id]['shortVarchar'][0]['value'])
                        ) {
                            $value = $datarecord['dataRecordFields'][$rpf_df_id]['shortVarchar'][0]['value'];
                        }
                        else if ($typeclass === 'DecimalValue' &&
                            isset($datarecord['dataRecordFields'][$rpf_df_id]['decimalValue'][0]['value'])
                        ) {
                            $value = $datarecord['dataRecordFields'][$rpf_df_id]['decimalValue'][0]['value'];
                        }

                        $field_values[$rpf_name] = $value;
                        break;
                }
            }
        }

        return array(
            'fields' => $plugin_fields,
            'values' => $field_values,
        );
    }


    /**
     * Need to check for and warn if a derived field is blank when the source field is not.
     *
     * @param array $field_values {@link self::getPluginData()}
     *
     * @return array
     */
    private function findDerivationProblems($plugin_data)
    {
        $fields = $plugin_data['fields'];
        $values = $plugin_data['values'];

        // Only interested in the contents of datafields mapped to these rpf entries
        $derivations = array(
            'Space Group' => 'Lattice',
        );

        $problems = array();
        foreach ($derivations as $source_rpf_name => $dest_rpf_name) {
            if ( (isset($values[$source_rpf_name]) && $values[$source_rpf_name] !== '')
                && (!isset($values[$dest_rpf_name]) || $values[$dest_rpf_name] === '')
            ) {
                // If the source field has a value and the destination field does not, then need to
                //  locate the id of the destination field
                foreach ($fields as $df_id => $df_data) {
                    if ( $df_data['rpf_name'] === $dest_rpf_name ) {
                        $problems[$df_id] = 'There seems to be a problem with the contents of the "'.$source_rpf_name.'" field.';
                    }
                }
            }
        }

        // Return a list of any problems found
        return $problems;
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


    /**
     * Converts a non-empty string of a cellparameter value into a float.
     *
     * @param string $str
     * @return float
     */
    private function getBaseValue($str)
    {
        // Some of these values have tildes in them
        $str = str_replace('~', '', $str);

        // Extract the part of the string before the tolerance, if it exists
        $paren = strpos($str, '(');
        if ( $paren !== false )
            $str = substr($str, 0, $paren);

        // Convert the string into a float value
        return floatval($str);
    }


    /**
     * The references that this database is built from don't always specify the volume, but it's
     * quite useful to always have the volume.  Which means there needs to be a calculation method...
     *
     * @param string $a
     * @param string $b
     * @param string $c
     * @param string $alpha
     * @param string $beta
     * @param string $gamma
     * @return float
     */
    private function calculateVolume($a, $b, $c, $alpha, $beta, $gamma)
    {
        // Ensure that the given values don't have any tolerances on them
        $a = self::getBaseValue($a);
        $b = self::getBaseValue($b);
        $c = self::getBaseValue($c);
        $alpha = self::getBaseValue($alpha);
        $beta = self::getBaseValue($beta);
        $gamma = self::getBaseValue($gamma);

        // The angles need to be converted into radians first...
        $alpha = $alpha * M_PI / 180.0;
        $beta = $beta * M_PI / 180.0;
        $gamma = $gamma * M_PI / 180.0;

        return CrystallographyDef::calculateVolume($a, $b, $c, $alpha, $beta, $gamma);
    }


    /**
     * It's easier to find point groups in a dropdown if they're ordered by name...
     *
     * @return array
     */
    private function sortPointGroups()
    {
        // Probably an over-optimization, but...
        $tmp = $this->cache_service->get('cached_point_groups');
        if ( is_array($tmp) )
            return $tmp;

        // Convert the array of point groups into more of a lookup table...
        $tmp = array();
        foreach (CrystallographyDef::$point_groups as $crystal_system => $pgs) {
            foreach ($pgs as $pg)
                $tmp[ strval($pg) ] = $crystal_system;
        }

        // Sort the point groups by key...
        uksort($tmp, function($a, $b) {
            // ...ignoring a leading hyphen character if it exists
            if ( is_numeric($a) )
                $a = strval($a);
            if ( is_numeric($b) )
                $b = strval($b);

            if ( $a[0] === '-' )
                $a = substr($a, 1);
            if ( $b[0] === '-' )
                $b = substr($b, 1);

            return strcmp($a, $b);
        });

        $this->cache_service->set('cached_point_groups', $tmp);
        return $tmp;
    }


    /**
     * It's considerably easier to find space groups in a dropdown if they're ordered by name...
     *
     * @return array
     */
    private function sortSpaceGroups()
    {
        // Probably an over-optimization, but...
        $tmp = $this->cache_service->get('cached_space_groups');
        if ( is_array($tmp) )
            return $tmp;

        // Convert the array of space groups into more of a lookup table...
        $tmp = array();
        foreach (CrystallographyDef::$space_group_mapping as $point_group => $sg_num_list) {
            foreach ($sg_num_list as $sg_num) {
                foreach (CrystallographyDef::$space_groups[$sg_num] as $space_group)
                    $tmp[$space_group] = str_replace('/', 's', $point_group);
            }
        }

        // ...then sort it by space group...don't need to worry about leading hypens
        ksort($tmp);

        $this->cache_service->set('cached_space_groups', $tmp);
        return $tmp;
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

        // Need to locate the "cellparam_id" field for this render plugin...
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
                'plugin_classname' => 'odr_plugins.rruff.cell_parameters',
                'datatype' => $datatype->getId(),
                'field_name' => 'Cell Parameter ID'
            )
        );
        $results = $query->getResult();
        if ( count($results) !== 1 ) {
//            throw new ODRException('Unable to find the "Cell Parameter ID" field for the "RRUFF Cell Parameters" RenderPlugin, attached to Datatype '.$datatype->getId());

            // Since this field is now optional, in order to allow RRUFF X-Ray Diffraction to use
            //  the plugin...there might legitimately be no result for this query here
            return;
        }


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
        $this->logger->debug('Setting df '.$datafield->getId().' "Cell Parameter ID" of new dr '.$datarecord->getId().' to "'.$new_value.'"...', array(self::class, 'onDatarecordCreate()'));

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
     * For this database, the cell_param_id needs to be autogenerated.
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
     *  - "Space Group" => "Lattice"
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

            // Only care about a change to specific fields of a datatype using this render plugin...
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
                if ($rpf_name === 'Space Group')
                    $dest_rpf_name = 'Lattice';

                // Locate the destination entity for the relevant source datafield
                $destination_entity = self::findDestinationEntity($user, $datatype, $datarecord, $dest_rpf_name);

                // Derive the new value for the destination entity
                $derived_value = null;
                if ($rpf_name === 'Space Group')
                    $derived_value = substr($source_value, 0, 1);

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
     *  - "Space Group" => "Lattice"
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

            // Only care about a change to specific fields of a datatype using this render plugin...
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
                    if ($rpf_name === 'Space Group')
                        $dest_rpf_name = 'Lattice';

                    // Locate the destination entity for the relevant source datafield
                    $destination_entity = self::findDestinationEntity($user, $datatype, $datarecord, $dest_rpf_name);

                    // Derive the new value for the destination entity
                    $derived_value = null;
                    if ($rpf_name === 'Space Group')
                        $derived_value = substr($source_value, 0, 1);

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
            'Space Group' => 'Lattice',
        );

        foreach ($dt_array[$datatype->getId()]['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.cell_parameters' ) {
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
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.cell_parameters' ) {
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
     * {@inheritDoc}
     */
    public function getDerivationMap($render_plugin_instance)
    {
        // Don't execute on instances of other render plugins
        if ( $render_plugin_instance['renderPlugin']['pluginClassName'] !== 'odr_plugins.rruff.cell_parameters' )
            return array();
        $render_plugin_map = $render_plugin_instance['renderPluginMap'];

        // This plugin has one derived field...
        //  - "Lattice" is derived from "Space Group"
        $lattice_df_id = $render_plugin_map['Lattice']['id'];
        $space_group_df_id = $render_plugin_map['Space Group']['id'];

        // Since a datafield could be derived from multiple datafields, the source datafields need
        //  to be in an array (even though that's not the case here)
        return array(
            $lattice_df_id => array($space_group_df_id),
        );
    }


    /**
     * If some sort of error/exception was thrown, then attempt to blank out all the fields derived
     * from the file being read...this won't stop the file from being encrypted, which will allow
     * the renderplugin to recognize and display that something is wrong with this file.
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
     * {@inheritDoc}
     */
    public function getOverrideParameters($rendering_context, $render_plugin_instance, $datafield, $datarecord, $theme, $user, $is_datatype_admin)
    {
        // Only override when called from the 'edit' context...the 'display' context might be a
        //  possibility in the future, but this plugin doesn't need to override there
        if ( $rendering_context !== 'edit' )
            return array();

        // Sanity checks
        if ( $render_plugin_instance->getRenderPlugin()->getPluginClassName() !== 'odr_plugins.rruff.cell_parameters' )
            return array();
        $datatype = $datafield->getDataType();
        if ( $datatype->getId() !== $datarecord->getDataType()->getId() )
            return array();
        if ( $render_plugin_instance->getDataType()->getId() !== $datatype->getId() )
            return array();


        // Need to check some things with the data...
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links
        $dr_array = $this->datarecord_info_service->getDatarecordArray($datarecord->getGrandparent()->getId(), false);

        $dt_entry = $dt_array[$datatype->getId()];
        $dr_entry = $dr_array[$datarecord->getId()];
        $plugin_data = self::getPluginData($dt_entry, $dr_entry, $is_datatype_admin);
        // If the previous function decided there was a plugin config issue, then don't execute
        //  the plugin
        if ( is_null($plugin_data) )
            return array();

        // Only need to check for derivation/uniqueness problems when reloading in edit mode
        if ( $rendering_context === 'edit' ) {
            // Can't have uniqueness problems if there are derivation problems...
            $derivation_problems = self::findDerivationProblems($plugin_data);
            if ( isset($derivation_problems[$datafield->getId()]) ) {
                // The derived field does not have a value, but the source field does...render the
                //  plugin's template instead of the default
                return array(
                    'token_list' => array(),    // so ODRRenderService generates CSRF tokens
                    'template_name' => 'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_edit_datafield_reload.html.twig',
                    'problem_fields' => $derivation_problems,
                );
            }
        }


        // ----------------------------------------
        // Otherwise, get the returned data
        $plugin_fields = $plugin_data['fields'];
        $field_values = $plugin_data['values'];

        // Attempt to locate the name of the field being reloaded
        $rpf_name = '';
        if ( isset($plugin_fields[$datafield->getId()]['rpf_name']) )
            $rpf_name = $plugin_fields[$datafield->getId()]['rpf_name'];
        if ( $rpf_name === '' )
            return array();

        // Going to need several field identifiers, so various fields can be saved/reloaded at the
        //  same time
        $df_lookup = array();
        foreach ($plugin_fields as $df_id => $df)
            $df_lookup[ $df['rpf_name'] ] = $df_id;

        $field_identifiers = array(
            'Crystal System' => 'ShortVarcharForm_'.$datarecord->getId().'_'.$df_lookup['Crystal System'],
            'Point Group' => 'ShortVarcharForm_'.$datarecord->getId().'_'.$df_lookup['Point Group'],
            'Space Group' => 'ShortVarcharForm_'.$datarecord->getId().'_'.$df_lookup['Space Group'],
            'Lattice' => 'ShortVarcharForm_'.$datarecord->getId().'_'.$df_lookup['Lattice'],

            // Need this one for the a/b/c/alpha/beta/gamma fields
            'Volume' => 'ShortVarcharForm_'.$datarecord->getId().'_'.$df_lookup['Volume'],
        );

        if ( $rpf_name === 'Crystal System'
            || $rpf_name === 'Point Group'
            || $rpf_name === 'Space Group'
        ) {
            // All reloads of these fields need to be overridden, to show the popup trigger button

            // Tweak the point group and space group arrays so that they're in order...they're
            //  not defined in order, because it's considerably easier to fix any problems with
            //  them when they're arranged by category instead of by name
            $point_groups = self::sortPointGroups();
            $space_groups = self::sortSpaceGroups();

            // Also going to need a token for the custom form submission...
            $token_id = 'RRUFFCellParams_'.$datafield->getDataType()->getId().'_'.$datarecord->getId();
            $token_id .= '_'.$df_lookup['Crystal System'];
            $token_id .= '_'.$df_lookup['Point Group'];
            $token_id .= '_'.$df_lookup['Space Group'];
            $token_id .= '_Form';
            $form_token = $this->token_manager->getToken($token_id)->getValue();

            return array(
                'token_list' => array(),    // so ODRRenderService generates CSRF tokens
                'template_name' => 'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_edit_symmetry_datafield.html.twig',

                'field_identifiers' => $field_identifiers,
                'form_token' => $form_token,

                'crystal_systems' => CrystallographyDef::$crystal_systems,
                'point_groups' => $point_groups,
                'space_groups' => $space_groups,
            );
        }
        else if (
            $rpf_name === 'a' || $rpf_name === 'b' || $rpf_name === 'c'
            || $rpf_name === 'alpha' || $rpf_name === 'beta' || $rpf_name === 'gamma'
            || $rpf_name === 'Volume'
        ) {
            $calculated_volume = '';
            if ( !($field_values['a'] === '' || $field_values['b'] === '' || $field_values['c'] === ''
                || $field_values['alpha'] === '' || $field_values['beta'] === '' || $field_values['gamma'] === '')
            ) {
                // ...then calculate (an approximation of) the volume
                $calculated_volume = self::calculateVolume($field_values['a'], $field_values['b'], $field_values['c'], $field_values['alpha'], $field_values['beta'], $field_values['gamma']);
            }

            return array(
                'token_list' => array(),    // so ODRRenderService generates CSRF tokens
                'template_name' => 'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_edit_dimension_datafield.html.twig',

                'plugin_fields' => $plugin_fields,
                'calculated_volume' => $calculated_volume,
            );
        }

        // Otherwise, don't want to override the default reloading for this field
        return array();
    }


    /**
     * {@inheritDoc}
     */
    public function getMassEditOverrideFields($render_plugin_instance)
    {
        if ( !isset($render_plugin_instance['renderPluginMap']) )
            throw new ODRException('Invalid plugin config');

        // Only interested in overriding datafields mapped to these rpf entries
        $relevant_datafields = array(
            'Space Group' => 'Lattice',
        );

        $ret = array();
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            if ( isset($relevant_datafields[$rpf_name]) )
                $ret[] = $rpf['id'];
        }

        return $ret;
    }


    /**
     * {@inheritDoc}
     */
    public function getMassEditTriggerFields($render_plugin_instance)
    {
        // Only interested in overriding datafields mapped to these rpf entries
        $relevant_datafields = array(
            'Space Group' => 'Lattice',
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
     * {@inheritDoc}
     */
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // This render plugin might need to modify three different fields...
        $values = array();

        // ...it's easier if all relevant values are found first
        $current_values = array();
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            switch ($rpf_name) {
                case 'Point Group':
                case 'Space Group':
                case 'a':
                case 'b':
                case 'c':
                case 'alpha':
                case 'beta':
                case 'gamma':
                case 'Volume':
                    // This is a field of interest...
                    $df_id = $rpf['id'];
                    $current_values[$rpf_name] = array(
                        'id' => $df_id,
                        'value' => ''
                    );

                    // Need to look through the datarecord to find the current value...all of these
                    //  fields are guaranteed to be ShortVarchar fields
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

        // The Volume needs to be calculated if it does not already exist...
        if ( $current_values['Volume']['value'] === '' ) {
            $volume_df_id = $current_values['Volume']['id'];

            // ...but only if the six cellparameter values exist
            $a = $current_values['a']['value'];
            $b = $current_values['b']['value'];
            $c = $current_values['c']['value'];
            $alpha = $current_values['alpha']['value'];
            $beta = $current_values['beta']['value'];
            $gamma = $current_values['gamma']['value'];

            if ( $a !== '' && $b !== '' && $c !== '' && $alpha !== '' && $beta !== '' && $gamma !== '')
                $values[$volume_df_id] = '<i>'.self::calculateVolume($a, $b, $c, $alpha, $beta, $gamma).'</i>';
        }

        // Return all modified values
        return $values;
    }
}
