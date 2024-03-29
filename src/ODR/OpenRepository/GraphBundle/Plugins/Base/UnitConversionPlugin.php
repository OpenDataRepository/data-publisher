<?php

/**
 * Open Data Repository Data Publisher
 * Unit Conversion Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * While you (almost) always want to preserve the original data in the form it was collected/given,
 * there are instances where it's more useful to display or otherwise use the data after it's been
 * converted into other units.  This isn't exactly trivial to pull off, but if we simplify the problem
 * somewhat by assuming that you're not going to mix different types of units in the same field
 * (e.g. Pressure and Temperature in the same field), then it becomes barely feasible to have a plugin
 * perform these automatic conversions.  "Barely", because apparently people tend to pick and choose
 * which rules they follow...
 *
 * This plugin works by taking advantage of an otherwise unused column in several of the entity
 * storage tables..."converted_value"...and gives users the ability to decide where they want to
 * display/use the "converted_value", instead of ODR displaying/using the "value" by itself.  There
 * are four areas where this distinction matters...in Display/Search results, in Searching, in Sorting,
 * and in Exporting.  Editing will always display the "original_value", though it also shows the
 * "converted_value" so users don't have to keep switching to Display mode.
 *
 * Searching and Exporting also have the ability be locally overriden, so you can search/export
 * either/both values, regardless of the default behavior selected by the plugin options.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginOptionsDef;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\PluginOptionsChangedEvent;
use ODR\AdminBundle\Component\Event\PluginPreRemoveEvent;
use ODR\AdminBundle\Component\Event\PostUpdateEvent;
use ODR\AdminBundle\Component\Event\MassEditTriggerEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Interfaces
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ExportOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
use ODR\OpenRepository\GraphBundle\Plugins\PluginSettingsDialogOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\SearchOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\SortOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\UnitConversionsDef;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchQueryService;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class UnitConversionPlugin implements DatafieldPluginInterface, ExportOverrideInterface, MassEditTriggerEventInterface, PluginSettingsDialogOverrideInterface, SearchOverrideInterface, SortOverrideInterface, TableResultsOverrideInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var SearchQueryService
     */
    private $search_query_service;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * UnitConversion Plugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param EventDispatcherInterface $event_dispatcher
     * @param CacheService $cache_service
     * @param DatabaseInfoService $database_info_service
     * @param SearchService $search_service
     * @param SearchQueryService $search_query_service
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        EventDispatcherInterface $event_dispatcher,
        CacheService $cache_service,
        DatabaseInfoService $database_info_service,
        SearchService $search_service,
        SearchQueryService $search_query_service,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->event_dispatcher = $event_dispatcher;
        $this->cache_service = $cache_service;
        $this->database_info_service = $database_info_service;
        $this->search_service = $search_service;
        $this->search_query_service = $search_query_service;
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

            if ( $context === 'display'
                || $context === 'edit'
                || $context === 'mass_edit'
//                || $context === 'csv_export'    // TODO - need nate to be done first...
//                || $context === 'api_export'    // TODO - implement this
            ) {
                // Execute the render plugin when called from these contexts
                return true;
            }
        }

        return false;
    }


    /**
     * Returns whether the plugin wants to override its entry in the search sidebar.
     *
     * @param array $render_plugin_instance
     * @param array $datafield
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecuteSearchPlugin($render_plugin_instance, $datafield, $rendering_options)
    {
        // Don't need any of the provided parameters to make a decision
        return true;
    }


    /**
     * Executes the RenderPlugin on the provided datafield
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
            $context = $rendering_options['context'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // Locate value of datafield
            $original_value = $converted_value = '';
            if ( is_null($datarecord) ) {
                // $datarecord will be null if called from CSVExport context...don't need any
                //  values there, so don't try to find any values here
            }
            else if ( isset($datarecord['dataRecordFields'][ $datafield['id'] ]) ) {
                $drf = $datarecord['dataRecordFields'][ $datafield['id'] ];
                $entity = null;
                switch ( $datafield['dataFieldMeta']['fieldType']['typeClass'] ) {
                    case 'ShortVarchar':
                        $entity = $drf['shortVarchar'][0];
                        break;
                    // TODO - implement this...
//                    case 'DecimalValue':
//                        $entity = $drf['decimalValue'][0];
//                        break;

                    default:
                        throw new \Exception('Invalid Fieldtype');
                }
                $original_value = trim( $entity['value'] );
                $converted_value = trim( $entity['converted_value'] );
            }
            else {
                // No datarecordfield entry for this datarecord/datafield pair...because of the
                //  allowed fieldtypes, the plugin can just use the empty string in this case
            }


            // ----------------------------------------
            $output = "";
            if ( $context === 'text' ) {
                // Need to decide here what to return...
                if ( $options['display_converted'] )
                    return $converted_value;
                else
                    return $original_value;
            }
            else if ( $context === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:UnitConversion/unit_conversion_display_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'plugin_options' => $options,
                        'original_value' => $original_value,
                        'converted_value' => $converted_value,
                    )
                );
            }
            else if ( $context === 'edit' ) {
                // This may not be set...
                $is_datatype_admin = false;
                if ( isset($rendering_options['is_datatype_admin']) )
                    $is_datatype_admin = $rendering_options['is_datatype_admin'];

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:UnitConversion/unit_conversion_edit_datafield.html.twig',
                    array(
                        'is_datatype_admin' => $is_datatype_admin,

                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'plugin_options' => $options,
                        'original_value' => $original_value,
                        'converted_value' => $converted_value,
                    )
                );
            }
            else if ( $context === 'csv_export' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:UnitConversion/unit_conversion_csvexport_datafield.html.twig',
                    array(
                        'datafield' => $datafield,

                        'plugin_options' => $options,
                    )
                );
            }
            else if ( $context === 'api_export' ) {
                // TODO
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Executes the plugin on the given datafield.
     *
     * @param array $datafield
     * @param array $render_plugin_instance
     * @param int $datatype_id
     * @param string|array $preset_value
     * @param array $rendering_options
     *
     * @return string
     */
    public function executeSearchPlugin($datafield, $render_plugin_instance, $datatype_id, $preset_value, $rendering_options)
    {
        // Apparently can't use javascript to determine the preset values for the text inputs, as
        //  the sidebar won't collapse properly...
        $preset_value_main = $preset_value_alt = '';

        $plugin_options = $render_plugin_instance['renderPluginOptionsMap'];

        if ( $preset_value !== '' ) {
            if ( strpos($preset_value, ':') === false ) {
                // If the delimiter isn't in a given preset value, then the preset value always goes
                //  into the "main" input...whether that's actually to search on the "original" or
                //  the "converted" value depends on how the plugin was configured
                $preset_value_main = $preset_value;
            }
            else {
                // If the delimiter does exist, then split the preset value apart
                $preset_values = explode(':', $preset_value);

                // The first value always goes into the "main" input, while the second always goes
                //  into the "alt" input...which of these is the "original" or the "converted" value
                //  depends on how the plugin was configured
                $preset_value_main = $preset_values[0];
                $preset_value_alt = $preset_values[1];
            }
        }

        // Render the datafield for the search sidebar
        $output = $this->templating->render(
            'ODROpenRepositoryGraphBundle:Base:UnitConversion/unit_conversion_search_datafield.html.twig',
            array(
                'datatype_id' => $datatype_id,
                'datafield' => $datafield,

                'preset_value' => $preset_value,
                'preset_value_main' => $preset_value_main,
                'preset_value_alt' => $preset_value_alt,

                'plugin_options' => $plugin_options,
            )
        );

        return $output;
    }


    /**
     * Returns an array of datafield values that TableThemeHelperService should display, instead of
     * using the values in the datarecord.
     *
     * @param array $render_plugin_instance
     * @param array $datarecord
     * @param array|null $datafield Shouldn't be null here, since this is a datafield plugin
     *
     * @return string[] An array where the keys are datafield ids, and the values are the strings to display
     */
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // Since this is a datafield plugin, there should always be a single field here...don't
        //  need to dig through the renderPluginInstance array for it
        $df_id = $datafield['id'];
        $typeclass = lcfirst( $datafield['dataFieldMeta']['fieldType']['typeClass'] );

        // Need to extract both the original and the converted values from the datarecord array
        $original_value = $converted_value = '';
        if ( isset($datarecord['dataRecordFields'][$df_id]) ) {
            $drf = $datarecord['dataRecordFields'][$df_id];
            if ( isset( $drf[$typeclass][0] ) ) {
                $storage_entity = $drf[$typeclass][0];

                $original_value = $storage_entity['value'];
                $converted_value = $storage_entity['converted_value'];

                // Shouldn't fall back to original_value when converted_value is blank...that's how
                //  the plugin indicates something is wrong
            }
        }

        // The plugin has a config option for whether to display the converted value or not
        $values = array();
        $options = $render_plugin_instance['renderPluginOptionsMap'];
        if ( $options['display_converted'] === 'yes' )
            $values[$df_id] = $converted_value;
        else
            $values[$df_id] = $original_value;

        // Return the value the table should display
        return $values;
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
        // Should only be one datafield in here, and it should always have the trigger available
        $ret = array();
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf)
            $ret[] = $rpf['id'];

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
        $trigger_fields = array();
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            // The field should only activate the MassEditTrigger event when the user didn't also
            //  specify a new value
            $trigger_fields[ $rpf['id'] ] = false;
        }

        return $trigger_fields;
    }


    /**
     * Returns data from UnitConversionDef in a format that's more suitable for display as a
     * custom renderPluginOption.
     *
     * @return array
     */
    private function getAvailableConversions()
    {
        $tmp = array();
        foreach (UnitConversionsDef::$conversions as $category => $conversions) {
            // Need to preserve the category, since units are technically not unique across all
            //  applications...
            $tmp[$category] = array();
            foreach ($conversions as $unit => $factor) {
                // The conversions array contains almost everything that is going to be in the
                //  <select> element, but it lacks a convenient long-form label...
                $label = '';
                foreach (UnitConversionsDef::$aliases[$category] as $key => $value) {
                    // ...the easiest way to find this convenient long-form label is to use the first
                    //  entry in the aliases array that refers to this unit, for now
                    if ( $unit === $value ) {
                        $label = $key;
                        break;
                    }
                }

                $tmp[$category][$label] = $unit;
            }
        }

        return $tmp;
    }


    /**
     * Returns the current configuration of the plugin.
     *
     * @param DataFields $datafield
     * @return array
     */
    private function getCurrentPluginConfig($datafield)
    {
        $config = array();

        // Events don't have access to the renderPluginInstance, so might as well just always get
        //  the data from the cached datatype array
        $datatype = $datafield->getDataType();
        $datatype_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want linked datatypes
        $dt = $datatype_array[$datatype->getId()];

        // The datafield entry in this array is guaranteed to exist...
        $df = $dt['dataFields'][$datafield->getId()];
        // ...but the renderPluginInstance won't be, if the renderPlugin hasn't been attached to the
        //  datafield in question
        if ( !empty($df['renderPluginInstances']) ) {
            // The datafield could have more than one renderPluginInstance
            foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.unit_conversion' ) {
                    // Don't care about the standard renderPluginOptions, just the one that requires
                    //  custom rendering
                    $conversion_data = trim( $rpi['renderPluginOptionsMap']['conversion_type'] );

                    // If this option is empty, then the plugin isn't properly configured
                    if ( $conversion_data === '' )
                        return array();


                    // ----------------------------------------
                    // The conversion data is somewhat encoded...
                    $conversion_data = explode(':', $conversion_data);
                    if ( count($conversion_data) !== 3 )
                        return array();

                    $conversion_type = $conversion_data[0];
                    $target_units = $conversion_data[1];
                    $original_precision_type = $conversion_data[2];

                    // If the requested conversion type isn't defined, then the plugin isn't
                    //  properly configured
                    if ( !isset(UnitConversionsDef::$conversions[$conversion_type][$target_units]) )
                        return array();


                    // The precision type needs to be one of several values...
                    $precision_type = $original_precision_type;
                    if ( $original_precision_type === '' )
                        $precision_type = '';
                    else if ( strpos($original_precision_type, 'decimal') !== false )
                        $precision_type = 'decimal';    // Don't care how many decimal places at the moment

                    switch ($precision_type) {
                        case 'none':
                        case 'greedy':
                        case 'precise':
                        case 'decimal':
                            break;

                        default:
                            // ...if it's not one of the above, then the plugin isn't properly configured
                            return array();
                    }

                    $config = array(
                        'conversion_type' => $conversion_type,
                        'target_units' => $target_units,

                        'precision_type' => $original_precision_type,
                    );
                }
            }
        }

        // Otherwise, attempt to return the plugin's config
        return $config;
    }


    /**
     * Returns an array of HTML strings for each RenderPluginOption in this RenderPlugin that needs
     * to use custom HTML in the RenderPlugin settings dialog.
     *
     * @param ODRUser $user The user opening the dialog
     * @param boolean $is_datatype_admin Whether the user is able to make changes to this RenderPlugin's config
     * @param RenderPlugin $render_plugin The RenderPlugin in question
     * @param DataType $datatype The relevant datatype if this is a Datatype Plugin, otherwise the Datatype of the given Datafield
     * @param DataFields|null $datafield Will be null unless this is a Datafield Plugin
     * @param RenderPluginInstance|null $render_plugin_instance Will be null if the RenderPlugin isn't in use
     * @return string[]
     */
    public function getRenderPluginOptionsOverride($user, $is_datatype_admin, $render_plugin, $datatype, $datafield = null, $render_plugin_instance = null)
    {
        $custom_rpo_html = array();
        foreach ($render_plugin->getRenderPluginOptionsDef() as $rpo) {
            // Only the "conversion_type" option needs to use a custom render for the dialog...
            /** @var RenderPluginOptionsDef $rpo */
            if ( $rpo->getUsesCustomRender() ) {
                $available_conversions = self::getAvailableConversions();
                $current_plugin_config = self::getCurrentPluginConfig($datafield);

                // ...which allows a template to be rendered
                $custom_rpo_html[$rpo->getId()] = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:UnitConversion/plugin_settings_dialog_field_list_override.html.twig',
                    array(
                        'rpo_id' => $rpo->getId(),

                        'available_conversions' => $available_conversions,
                        'current_plugin_config' => $current_plugin_config,
                    )
                );
            }
        }

        // As a side note, the plugin settings dialog does no logic to determine which options should
        //  have custom rendering...it's solely determined by the contents of the array returned by
        //  this function.  As such, there's no validation whatsoever.
        return $custom_rpo_html;
    }


    /**
     * Searches the specified datafield for the specified value, returning an array of datarecord
     * ids that match the search.
     *
     * @param DataFields $datafield
     * @param array $search_term
     * @param array $render_plugin_options
     *
     * @return array
     */
    public function searchPluginField($datafield, $search_term, $render_plugin_options)
    {
        // ----------------------------------------
        // Don't continue if somehow called on the wrong type of datafield
        $allowed_typeclasses = array(
//            'DecimalValue',    // TODO - implement this
            'ShortVarchar',
        );
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ( !in_array($typeclass, $allowed_typeclasses) )
            throw new ODRBadRequestException('UnitConversionPlugin::searchPluginField() called with '.$typeclass.' datafield', 0x7a7d1906);


        // ----------------------------------------
        // See if this search result is already cached...
        $cached_searches = $this->cache_service->get('cached_search_df_'.$datafield->getId());
        if ( !$cached_searches )
            $cached_searches = array();

        // Since MYSQL's collation is case-insensitive, the php caching should treat it the same
        $value = $search_term['value'];
        $cache_key = mb_strtolower($value);
        if ( isset($cached_searches[$cache_key]) )
            return $cached_searches[$cache_key];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...determine whether the plugin is
        //  configured to search on the "value" or the "converted_value"
        $search_converted = false;
        if ( $render_plugin_options['search_converted'] === 'yes' )
            $search_converted = true;

        // How to recache the search depends on what the user searched for...
        $end_result = array();
        if ( strpos($value, ':') === false ) {
            // If the user is only searching with the "main" input, then only need to run one query
            $result = $this->search_query_service->searchTextOrNumberDatafield(
                $datafield->getDataType()->getId(),
                $datafield->getId(),
                $typeclass,
                $value,
                $search_converted    // get whichever value the user wants by default
            );

            $end_result = array(
                'dt_id' => $datafield->getDataType()->getId(),
                'records' => $result
            );
        }
        else {
            // If the user is not searching with just  the "alt" input, then need to get a bit fancier
            // The search term is given in "<'main' input value>:<'alt' input value>", where either
            //  input value might be blank (but theoretically not both)
            $values = explode(':', $value);

            $original_value = $converted_value = '';
            if ( $search_converted ) {
                // User wants to search on converted values by default, so the first entry is the
                //  converted value
                $converted_value = $values[0];
                $original_value = $values[1];
            }
            else {
                // User wants to search on original values by default, so the first entry is the
                //  original value
                $original_value = $values[0];
                $converted_value = $values[1];
            }

            // Run the required searches
            $original_result = $converted_result = null;
            if ( $original_value !== '' ) {
                $original_result = $this->search_query_service->searchTextOrNumberDatafield(
                    $datafield->getDataType()->getId(),
                    $datafield->getId(),
                    $typeclass,
                    $original_value,
                    false    // get the original value stored in this field
                );
            }

            if ( $converted_value !== '' ) {
                $converted_result = $this->search_query_service->searchTextOrNumberDatafield(
                    $datafield->getDataType()->getId(),
                    $datafield->getId(),
                    $typeclass,
                    $converted_value,
                    true    // get the converted value stored in this field
                );
            }

            // Need to combine the two results together
            $result = array();
            if ( is_null($original_result) && is_null($converted_result) ) {
                // If neither search was run, then nothing can be matched
                $result = array();
            }
            else if ( !is_null($original_result) && is_null($converted_result) ) {
                // Only searched on the original value
                $result = $original_result;
            }
            else if ( is_null($original_result) && !is_null($converted_result) ) {
                // Only searched on the converted value
                $result = $converted_result;
            }
            else {
                // Searched on both...take the intersection of both arrays
                $result = array_intersect_key($original_result, $converted_result);
            }

            $end_result = array(
                'dt_id' => $datafield->getDataType()->getId(),
                'records' => $result
            );
        }


        // ----------------------------------------
        // Recache the search result...
        $cached_searches[$cache_key] = $end_result;
        $this->cache_service->set('cached_search_df_'.$datafield->getId(), $cached_searches);

        // ...then return it
        return $end_result;
    }


    /**
     * Returns whether SortService should use the "value" or the "converted_value" to sort the
     * given datafield.
     *
     * @param array $render_plugin_options
     *
     * @return boolean
     */
    public function useConvertedValue($render_plugin_options)
    {
        if ( isset($render_plugin_options['sort_converted']) && $render_plugin_options['sort_converted'] === 'yes')
            return true;
        else
            return false;
    }


    /**
     * Called when a user changes RenderPluginOptions or RenderPluginMaps entries for this plugin.
     *
     * @param PluginOptionsChangedEvent $event
     */
    public function onPluginOptionsChanged(PluginOptionsChangedEvent $event)
    {
        $datafield = $event->getRenderPluginInstance()->getDataField();

        // Multiple options might have changed, and don't want to waste time clearing cache entries
        //  more than once
        $deleted_cached_datarecords = false;
        $deleted_api_datarecords = false;

        $changed_options = $event->getChangedOptions();
        foreach ($changed_options as $option_name) {
            if ( $option_name === 'conversion_type' ) {
                // Only want to delete the converted values when the conversion type is changed
                self::deleteConvertedValues(
                    $datafield,
                    $event->getUser()
                );

                // Need to each type of affected cache entries here
                if ( !$deleted_cached_datarecords ) {
                    self::clearCachedDatarecords($datafield);
                    $deleted_cached_datarecords = true;
                }
                if ( !$deleted_api_datarecords ) {
                    self::clearAPIDatarecords($datafield);
                    $deleted_api_datarecords = true;
                }
                $this->cache_service->delete('cached_search_df_'.$datafield->getId());
                $this->cache_service->delete('cached_search_df_'.$datafield->getId().'_ordering');
            }
            else if ( $option_name === 'display_converted' ) {
                // Only need to delete the cache entries that work for Display and Table modes
                if ( !$deleted_cached_datarecords ) {
                    self::clearCachedDatarecords($datafield);
                    $deleted_cached_datarecords = true;
                }
            }
            else if ( $option_name === 'export_converted' ) {
                // Only need to delete the cache entries that work for API mode
                if ( !$deleted_api_datarecords ) {
                    self::clearAPIDatarecords($datafield);
                    $deleted_api_datarecords = true;
                }
            }
            else if ( $option_name === 'search_converted' ) {
                // Need to delete any cached search entries for the datafield
                $this->cache_service->delete('cached_search_df_'.$datafield->getId());
            }
            else if ( $option_name === 'sort_converted' ) {
                // Need to delete any cached sort orders that relies on the datafield
                $this->cache_service->delete('cached_search_df_'.$datafield->getId().'_ordering');
            }
        }
    }


    /**
     * Called when a user removes this render plugin from a datafield.
     *
     * @param PluginPreRemoveEvent $event
     */
    public function onPluginPreRemove(PluginPreRemoveEvent $event)
    {
        $datafield = $event->getRenderPluginInstance()->getDataField();

        // Always want to delete the converted values when the plugin is removed
        self::deleteConvertedValues(
            $datafield,
            $event->getUser()
        );

        // Ensure the relevant cache entries are also cleared
        self::clearCachedDatarecords($datafield);
        self::clearAPIDatarecords($datafield);
        $this->cache_service->delete('cached_search_df_'.$datafield->getId());
        $this->cache_service->delete('cached_search_df_'.$datafield->getId().'_ordering');
    }


    /**
     * Deletes all converted_values for the given datafield.
     *
     * @param DataFields $datafield
     * @param ODRUser $user
     */
    private function deleteConvertedValues($datafield, $user)
    {
        $typeclass = $datafield->getFieldType()->getTypeClass();

        // Don't want deleted entities to have the converted value either
        $this->em->getFilters()->disable('softdeleteable');

        $query = $this->em->createQuery(
           'UPDATE ODRAdminBundle:'.$typeclass.' AS e
            SET e.converted_value = :new_value, e.updated = :now, e.updatedBy = :user_id
            WHERE e.dataField = :datafield_id'
        )->setParameters(
            array(
                'new_value' => '',
                'datafield_id' => $datafield->getId(),
                'now' => new \DateTime(),
                'user_id' => $user->getId()
            )
        );
        $rows = $query->execute();

        // Ensure the filter is enabled again
        $this->em->getFilters()->enable('softdeleteable');
    }


    /**
     * Deletes all cached datarecord entries that refer to this datafield.
     *
     * @param DataFields $datafield
     */
    private function clearCachedDatarecords($datafield)
    {
        $grandparent_datatype_id = $datafield->getDataType()->getGrandparent()->getId();
        $dr_list = $this->search_service->getCachedSearchDatarecordList($grandparent_datatype_id);
        foreach ($dr_list as $dr_id => $parent_dr_id) {
            $this->cache_service->delete('cached_datarecord_'.$dr_id);
            $this->cache_service->delete('cached_table_data_'.$dr_id);
        }
    }


    /**
     * Deletes all cached API datarecord entries that refer to this datafield.
     *
     * @param DataFields $datafield
     */
    private function clearAPIDatarecords($datafield)
    {
        $grandparent_datatype_id = $datafield->getDataType()->getGrandparent()->getId();
        $dr_list = $this->search_service->getCachedDatarecordUUIDList($grandparent_datatype_id);
        foreach ($dr_list as $dr_id => $dr_uuid)
            $this->cache_service->delete('json_record_'.$dr_uuid);
    }


    /**
     * Changes made to the value of this field should also trigger a conversion of that value...
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

            // Only care about a change to this field...
            if ( self::isEventRelevant($datafield) ) {
                // ----------------------------------------
                // One of the relevant datafields got changed
                $entity = null;
                if ( $typeclass === 'ShortVarchar' )    // TODO - implement DecimalValue
                    $entity = $source_entity;

                // Only continue when stuff isn't null
                if ( !is_null($entity) ) {
                    $source_value = $entity->getValue();
                    $this->logger->debug('Attempting to convert a value from dt '.$datatype->getId().', dr '.$datarecord->getId().', df '.$datafield->getId().' ('.$typeclass.'): "'.$source_value.'"...', array(self::class, 'onPostUpdate()'));

                    // Determine what the value should be converted into
                    $current_config = self::getCurrentPluginConfig($datafield);
                    if ( empty($current_config) )
                        throw new ODRBadRequestException('The UnitConvesion plugin is not properly configured', 0x12504512);

                    $conversion_type = $current_config['conversion_type'];
                    $target_units = $current_config['target_units'];
                    $precision_type = $current_config['precision_type'];

                    // Perform the conversion, and save the result
                    $converted_value = UnitConversionsDef::performConversion($source_value, $conversion_type, $target_units, $precision_type);
                    $entity->setConvertedValue($converted_value);

                    $this->em->persist($entity);
                    $this->em->flush();
                    $this->logger->debug(' -- updating converted_value to "'.$converted_value.'"...', array(self::class, 'onPostUpdate()'));
                }
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
     * Changes made via MassEdit should also trigger a conversion of that value...
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
            // Get entities related to the event
            $drf = $event->getDataRecordFields();
            $datarecord = $drf->getDataRecord();
            $datafield = $drf->getDataField();
            $typeclass = $datafield->getFieldType()->getTypeClass();
            $datatype = $datafield->getDataType();
            $user = $event->getUser();

            // Only care about a change to this field...
            if ( self::isEventRelevant($datafield) ) {
                // ----------------------------------------
                // One of the relevant datafields got changed
                $entity = null;
                if ( $typeclass === 'ShortVarchar' )    // TODO - implement DecimalValue
                    $entity = $drf->getShortVarchar();

                // Only continue when stuff isn't null
                if ( !is_null($entity) ) {
                    $source_value = $entity->getValue();
                    $this->logger->debug('Attempting to convert a value from dt '.$datatype->getId().', dr '.$datarecord->getId().', df '.$datafield->getId().' ('.$typeclass.'): "'.$source_value.'"...', array(self::class, 'onMassEditTrigger()'));

                    // Determine what the value should be converted into
                    $current_config = self::getCurrentPluginConfig($datafield);
                    if ( empty($current_config) )
                        throw new ODRBadRequestException('The UnitConvesion plugin is not properly configured', 0x12504512);

                    $conversion_type = $current_config['conversion_type'];
                    $target_units = $current_config['target_units'];
                    $precision_type = $current_config['precision_type'];

                    // Perform the conversion, and save the result
                    $converted_value = UnitConversionsDef::performConversion($source_value, $conversion_type, $target_units, $precision_type);
                    $entity->setConvertedValue($converted_value);

                    $entity->setConvertedValue($converted_value);
                    $this->em->persist($entity);
                    $this->em->flush();
                    $this->logger->debug(' -- updating converted_value to "'.$converted_value.'"...', array(self::class, 'onMassEditTrigger()'));
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
     * Returns true if the given datafield should respond to the PostUpdate or MassEditTrigger
     * events, or false if it shouldn't.
     *
     * @param DataFields $datafield
     *
     * @return bool
     */
    private function isEventRelevant($datafield)
    {
        // Going to use the cached datatype array to locate the correct datafield...
        $rpi_entries = $datafield->getRenderPluginInstances();

        foreach ($rpi_entries as $rpi_id => $rpi) {
            /** @var RenderPluginInstance $rpi */
            if ( $rpi->getRenderPlugin()->getPluginClassName() === 'odr_plugins.base.unit_conversion' )
                return true;
        }

        // ...otherwise, something is wrong somehow
        return false;
    }


    /**
     * If some sort of error/exception was thrown, then attempt to blank out the "converted_value"
     * entry of the relevant storage entity.
     *
     * @param ODRUser $user
     * @param ShortVarchar $destination_storage_entity
     */
    private function saveOnError($user, $destination_storage_entity)
    {
        $dr = $destination_storage_entity->getDataRecord();
        $df = $destination_storage_entity->getDataField();

        try {
            $destination_storage_entity->setConvertedValue('');
            $this->em->persist($destination_storage_entity);
            $this->em->flush();

            $this->logger->debug('-- -- updating dr '.$dr->getId().', df '.$df->getId().' to have the converted_value ""...', array(self::class, 'saveOnError()'));
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
     * @param ShortVarchar $destination_storage_entity
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
}
