<?php

/**
 * Open Data Repository Data Publisher
 * Plugins Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The plugins controller holds the actions for listing, installing, and updating Render Plugins. It
 * also holds the actions used by the master theme designer for changing which Render Plugin a
 * datatype uses, or changing options of that Render Plugin.
 *
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginEvents;
use ODR\AdminBundle\Entity\RenderPluginFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptionsDef;
use ODR\AdminBundle\Entity\RenderPluginOptionsMap;
use ODR\AdminBundle\Entity\RenderPluginThemeOptionsMap;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeRenderPluginInstance;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldCreatedEvent;
use ODR\AdminBundle\Component\Event\DatatypeModifiedEvent;
use ODR\AdminBundle\Component\Event\PluginAttachEvent;
use ODR\AdminBundle\Component\Event\PluginOptionsChangedEvent;
use ODR\AdminBundle\Component\Event\PluginPreRemoveEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatafieldInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Plugin Interfaces
use ODR\OpenRepository\GraphBundle\Plugins\ArrayPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldReloadOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ExportOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
use ODR\OpenRepository\GraphBundle\Plugins\PluginSettingsDialogOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\SearchOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\SortOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ThemeElementPluginInterface;
// Symphony
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;
// YAML Parsing
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;


class PluginsController extends ODRCustomController
{

    /**
     * Enough places use data from the fieldtype table that it makes sense to have it all in one
     * function...
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @return array
     */
    private function getFieldtypeData($em)
    {
        $query = $em->createQuery(
           'SELECT ft.id AS ft_id, ft.typeClass, ft.typeName, ft.canBeUnique
            FROM ODRAdminBundle:FieldType AS ft
            WHERE ft.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $rpf_typeclasses = array();
        $rpf_typenames = array();
        $single_select_fieldtypes = array();
        $multiple_select_fieldtypes = array();
        $unique_fieldtypes = array();

        foreach ($results as $result) {
            $ft_id = $result['ft_id'];
            $typeclass = $result['typeClass'];
            $typename = $result['typeName'];
            $can_be_unique = $result['canBeUnique'];

            $rpf_typeclasses[$typeclass] = $ft_id;
            $rpf_typenames[$typename] = $ft_id;

            // If this is a "radio" fieldtype...
            if ($typeclass === 'Radio') {
                // ...then also need to consider "Single Radio" as equivalent to "Single Select",
                //  and "Multiple Radio" as equivalent to "Multiple Select"
                if ( strpos($typename, 'Single') !== false )
                    $single_select_fieldtypes[$ft_id] = $typename;
                else
                    $multiple_select_fieldtypes[$ft_id] = $typename;
            }

            if ( $can_be_unique )
                $unique_fieldtypes[$typeclass] = 1;
        }

        // The plugin system always wants to differentiate between the Radio typeclasses
        unset( $rpf_typeclasses['Radio'] );
        $rpf_typeclasses['Single Radio'] = $rpf_typenames['Single Radio'];
        $rpf_typeclasses['Single Select'] = $rpf_typenames['Single Select'];
        $rpf_typeclasses['Multiple Radio'] = $rpf_typenames['Multiple Radio'];
        $rpf_typeclasses['Multiple Select'] = $rpf_typenames['Multiple Select'];

        return array(
            'rpf_typeclasses' => $rpf_typeclasses,
            'rpf_typenames' => $rpf_typenames,
            'unique_fieldtypes' => $unique_fieldtypes,
            'single_select_fieldtypes' => $single_select_fieldtypes,
            'multiple_select_fieldtypes' => $multiple_select_fieldtypes,
        );
    }


    /**
     * Scans the base directory for the RenderPlugin executable and config files, and returns an
     * array of all available Render Plugins...the array keys are plugin classnames, and the values
     * are arrays of parsed yml data.
     *
     * If a specific plugin classname is passed in, then this function only will validate and return
     * that single plugin.
     *
     * @param array $fieldtype_data {@link self::getFieldtypeData()}
     * @param string $target_plugin_classname
     *
     * @throws ODRException
     *
     * @return array
     */
    private function getAvailablePlugins($fieldtype_data, $target_plugin_classname = '')
    {
        // ----------------------------------------
        // Going to need several arrays of typeclasses in order to validate the plugin config files
        $rpf_typeclasses = $fieldtype_data['rpf_typeclasses'];
        $unique_fieldtypes = $fieldtype_data['unique_fieldtypes'];


        // ----------------------------------------
        // Also going to need a list of events defined by ODR that can be used by render plugins
        $all_events = array();
        $events_base_dir = $this->container->getParameter('odr_events_directory');
        foreach ( scandir($events_base_dir) as $filename ) {
            // TODO - assumes linux?
            if ($filename === '.' || $filename === '..')
                continue;

            // Only want the actual event classes in the directory...
            if ( strrpos($filename, 'Event.php', -9) === false )
                continue;

            $filename = substr($filename, 0, -4);
            $all_events[$filename] = 1;
        }


        // ----------------------------------------
        $available_plugins = array();
        $plugin_base_dir = $this->container->getParameter('odr_plugin_basedir');
        // Plugins are organized by category inside the plugin base directory...
        foreach ( scandir($plugin_base_dir) as $plugin_category ) {
            // TODO - assumes linux?
            if ($plugin_category === '.' || $plugin_category === '..')
                continue;

            // Don't want the interfaces in the directory...
            if ( substr($plugin_category, -4) === '.php' )
                continue;

            $plugin_directory = $plugin_base_dir.'/'.$plugin_category;
            foreach ( scandir($plugin_directory) as $filename ) {
                // TODO - assumes linux?
                if ($filename === '.' || $filename === '..')
                    continue;

                // Only want names of php files...
                if ( substr($filename, -4) === '.php' ) {
                    // ...in order to get to their yml config files...
                    $stub = substr($filename, 0, -4);
                    $config_filename = $stub.'.yml';
                    $abbreviated_config_path = $plugin_category.'/'.$config_filename;

                    if ( !file_exists($plugin_directory.'/'.$config_filename) )
                        throw new ODRException('Could not find the RenderPlugin config file at "'.$abbreviated_config_path.'"');

                    // Attempt to parse the configuration file
                    try {
                        $yaml = Yaml::parse(file_get_contents($plugin_directory.'/'.$config_filename));

                        foreach ($yaml as $plugin_classname => $plugin_config) {
                            // If the caller specified a specific plugin, then ignore the other plugins
                            if ( $target_plugin_classname !== '' && $target_plugin_classname !== $plugin_classname )
                                continue;

                            // Ignore plugin files that aren't being loaded as symfony services
                            if ( !$this->container->has($plugin_classname) )
                                continue;

                            // Don't allow duplicate plugin definitions
                            if ( isset($available_plugins[$plugin_classname] ) )
                                throw new ODRException('RenderPlugin config file "'.$abbreviated_config_path.'" attempts to redefine the RenderPlugin "'.$plugin_classname.'", previously defined in "'.$available_plugins[$plugin_classname]['filepath'].'"');

                            $available_plugins[$plugin_classname] = $plugin_config;

                            // Store the filepath in case of later duplicates
                            $available_plugins[$plugin_classname]['filepath'] = $abbreviated_config_path;
                        }
                    }
                    catch (ParseException $e) {
                        // Apparently the default exception doesn't specify which file it occurs in...
                        throw new ODRException('Parse error in "'.$abbreviated_config_path.'": '.$e->getMessage());
                    }
                    catch (ODRException $e) {
                        // Catch and rethrow any other exceptions...
                        throw $e;
                    }
                }
            }
        }


        // ----------------------------------------
        // Ensure each of the plugins have a valid configuration file
        foreach ($available_plugins as $plugin_classname => $plugin_config)
            self::validatePluginConfig($plugin_classname, $plugin_config, $rpf_typeclasses, $unique_fieldtypes, $all_events);

        return $available_plugins;
    }


    /**
     * Attempts to ensure the provided array is a valid configuration file for a RenderPlugin
     *
     * @param string $plugin_classname
     * @param array $plugin_config
     * @param array $rpf_typeclasses {@link self::getFieldtypeData()}
     * @param array $unique_fieldtypes {@link self::getFieldtypeData()}
     * @param array $all_events
     *
     * @throws ODRException
     */
    private function validatePluginConfig($plugin_classname, $plugin_config, $rpf_typeclasses, $unique_fieldtypes, $all_events)
    {
        // ----------------------------------------
        // Need to load the plugin file to be able to check implemented interfaces, events, and
        //  callables
        if ( !$this->container->has($plugin_classname) )
            throw new ODRException('RenderPlugin service "'.$plugin_classname.'" is not defined');
        $plugin_service = $this->container->get($plugin_classname);


        // ----------------------------------------
        // All plugins must define these configuration options
        $required_keys = array(
            'name',
            'category',
            'plugin_type',
            'render',
            'version',

            'override_fields',
            'override_field_reload',
            'override_child',
            'override_table_fields',
            'override_export',
            'override_search',
            'override_sort',

            'suppress_no_fields_note',

            'description',
            'registered_events',
            'required_fields',
            'config_options',
        );

        foreach ($required_keys as $key) {
            if ( !array_key_exists($key, $plugin_config) )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is missing the required key "'.$key.'"');
        }


        // ----------------------------------------
        // Datatype and Datafield plugins have different configuration requirements...
        $is_datatype_plugin = $is_theme_element_plugin = $is_datafield_plugin = $is_array_plugin = false;
        $plugin_type = strtolower($plugin_config['plugin_type']);

        if ($plugin_type === 'datatype')
            $is_datatype_plugin = true;
        else if ($plugin_type === 'themeelement')
            $is_theme_element_plugin = true;
        else if ($plugin_type === 'datafield')
            $is_datafield_plugin = true;
        else if ($plugin_type === 'array')
            $is_array_plugin = true;
        else
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" has an invalid entry for the "plugin_type" key');


        // Ensure the plugin file implements the correct interface...
        if ( $is_datatype_plugin && !($plugin_service instanceof DatatypePluginInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" claims to be a Datatype plugin, but the RenderPlugin class "'.get_class($plugin_service).'" does not implement DatatypePluginInterface');
        else if ( $is_theme_element_plugin && !($plugin_service instanceof ThemeElementPluginInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" claims to be a ThemeElement plugin, but the RenderPlugin class "'.get_class($plugin_service).'" does not implement ThemeElementPluginInterface');
        else if ( $is_datafield_plugin && !($plugin_service instanceof DatafieldPluginInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" claims to be a Datafield plugin, but the RenderPlugin class "'.get_class($plugin_service).'" does not implement DatafieldPluginInterface');
        else if ( $is_array_plugin && !($plugin_service instanceof ArrayPluginInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" claims to be a Array plugin, but the RenderPlugin class "'.get_class($plugin_service).'" does not implement ArrayPluginInterface');


        if ( $is_datatype_plugin ) {
            // A datatype plugin technically doesn't need to define anything else, though it's of
            //  limited use in that case...
        }
        else if ( $is_theme_element_plugin ) {
            // A themeElement plugin must define how many themeElements it wants
            if ( !isset($plugin_config['required_theme_elements']) )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is a ThemeElement Plugin and must define a "required_theme_elements" key');
            if ( !is_numeric($plugin_config['required_theme_elements']) )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" must provide a numeric value for "required_theme_elements"');

            // For the moment, only permit a limited number of themeElements
            $limit = 1;
            if ( $plugin_config['required_theme_elements'] < 0 )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is not allowed to require less than zero themeElements');
            if ( $plugin_config['required_theme_elements'] > $limit )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is not allowed to require more than '.$limit.' themeElements');
        }
        else if ( $is_datafield_plugin ) {
            // A datafield plugin must define exactly one "required_field"
            if ( !is_array($plugin_config['required_fields']) || count($plugin_config['required_fields']) !== 1 )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is a Datafield Plugin and must define exactly one entry in the "required_fields" option');
        }
        else if ( $is_array_plugin ) {
            // An array plugin doesn't have to define anything
        }

        // The ThemeElement plugin is the only one allowed to have the 'required_theme_elements' key
        if ( isset($plugin_config['required_theme_elements']) && !$is_theme_element_plugin )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is a not a ThemeElement Plugin, and is therefore not allowed to have the "required_theme_elements" key');


        // Plugins must implement TableResultsOverrideInterface if and only if they override table fields
        if ( $plugin_config['override_table_fields'] === true && !($plugin_service instanceof TableResultsOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" must implement TableResultsOverrideInterface');
        else if ( $plugin_config['override_table_fields'] === false && ($plugin_service instanceof TableResultsOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" must not implement TableResultsOverrideInterface');

        // Plugins must implement ExportOverrideInterface if and only if they override CSV/API exporting
        if ( $plugin_config['override_export'] === true && !($plugin_service instanceof ExportOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" must implement ExportOverrideInterface');
        else if ( $plugin_config['override_export'] === false && ($plugin_service instanceof ExportOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" must not implement ExportOverrideInterface');

        // Plugins must implement SearchOverrideInterface if and only if they override searching
        if ( $plugin_config['override_search'] === true && !($plugin_service instanceof SearchOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" must implement SearchOverrideInterface');
        else if ( $plugin_config['override_search'] === false && ($plugin_service instanceof SearchOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" must not implement SearchOverrideInterface');

        // Plugins must implement SortOverrideInterface if and only if they override sorting
        if ( $plugin_config['override_sort'] === true && !($plugin_service instanceof SortOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" must implement SortOverrideInterface');
        else if ( $plugin_config['override_sort'] === false && ($plugin_service instanceof SortOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" must not implement SortOverrideInterface');

        // For the moment, Datafield plugins are the only ones allowed to implement Sort overriding
        if ( $plugin_config['override_sort'] === true ) {
            if ( !$is_datafield_plugin )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is not a Datafield plugin, and therefore is not allowed to override Sorting');
        }
        // ...had to implement Export/Search overriding for Datatype plugins though
        if ( $plugin_config['override_export'] === true || $plugin_config['override_search'] === true ) {
            if ( $is_array_plugin || $is_theme_element_plugin )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is not a Datafield or Datatype plugin, and therefore is not allowed to override Exporting or Searching');
        }


        // The "render" key isn't allowed to have a value of 'true' anymore
        if ( $plugin_config['render'] === true )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is not allowed to have a value of "true" for the "render" key');
        // ...also not allowed to have numeric values
        if ( is_numeric($plugin_config['render']) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is not allowed to have a numeric value for the "render" key');

        // ThemeElement and Array plugins aren't allowed to have a non-false value for the "render" key
        if ( $is_theme_element_plugin && $plugin_config['render'] !== false )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is a ThemeElement Plugin, and must have a value of false for the "render" key');
        if ( $is_array_plugin && $plugin_config['render'] !== false )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is an Array Plugin, and must have a value of false for the "render" key');

        // TODO - technically, ThemeElement plugins currently behave as if override_fields is true
        // TODO - ...but enforcing them as they currently behave is problematic
        // TODO - once the "final solution" for plugin compatibility is implemented, they will behave as the config params imply

        // Any override...child, fields, fieldReload, tableField, search, sort...also needs to have
        //  the "render" key set to something, since none of these play nicely with each other
        if ( $plugin_config['override_fields']
            || $plugin_config['override_field_reload']
            || $plugin_config['override_child']
            || $plugin_config['override_table_fields']
            || $plugin_config['override_export']
            || $plugin_config['override_search']
            || $plugin_config['override_sort']
        ) {
            if ( $plugin_config['render'] === false )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" overrides something, so its "render" attribute must not be false');
        }


        // ----------------------------------------
        // If there are entries in the "required_field" key, ensure they are properly formed
        $required_keys = array(
            'name',
            'description',
            'type',
//            'display_order',    // this is optional
        );

        // Fields in render plugins may need to have certain properties enforced to work correctly
        $allowed_properties = array(
            'autogenerate_values',
            'must_be_unique',
            'no_user_edits',
            'single_uploads_only',
            'is_derived',
            'is_optional',
        );

        // Some additional validation is needed when the plugin has a derived field
        $has_derived_field = false;

        if ( is_array($plugin_config['required_fields']) ) {
            // No sense suppressing the "no datafields" note in the plugin config dialog if the
            //  plugin actually has fields to map
            if ( $plugin_config['suppress_no_fields_note'] === true && !empty($plugin_config['required_fields']) )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", suppress_no_fields_note is true, but plugin has fields defined');


            // Need to ensure that no RenderPluginField entries share a name, since that is used
            //  as a unique key during install/update...
            $field_names = array();

            foreach ($plugin_config['required_fields'] as $field_id => $field_data) {
                foreach ($required_keys as $key) {
                    if ( !array_key_exists($key, $field_data) )
                        throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", required_field "'.$field_id.'" is missing the key "'.$key.'"');
                }

                $fieldName = $field_data['name'];
                if ( isset($field_names[$fieldName]) )
                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", required_field "'.$field_id.'" has a duplicate name "'.$fieldName.'"');
                else
                    $field_names[$fieldName] = 1;

                $allowed_fieldtypes = explode('|', $field_data['type']);
                foreach ($allowed_fieldtypes as $ft) {
                    if ( !isset($rpf_typeclasses[$ft]) )
                        throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", required_field "'.$field_id.'" allows invalid fieldtype typeclass "'.$ft.'"');
                }

                if ( isset($field_data['properties']) ) {
                    foreach ($field_data['properties'] as $key) {
                        if ( !in_array($key, $allowed_properties) )
                            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", required_field "'.$field_id.'" has an invalid property "'.$key.'"');

                        if ( $key === 'autogenerate_values' ) {
                            // File/Image fields can't get autogenerated values
                            foreach ($allowed_fieldtypes as $num => $typeclass) {
                                // NOTE: these are also set in FakeEditController::savefakerecordAction()
                                //  and FakeEditController::reloadchildAction()
                                switch ($typeclass) {
                                    case 'File':
                                    case 'Image':
                                    case 'Markdown':
                                    case 'XYZData':
                                       throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'"...required_field "'.$field_id.'" has the "autogenerate_values" property, which is not allowed on the "'.$typeclass.'" typeclass');
                                }
                            }
                        }
                        else if ( $key === 'must_be_unique' ) {
                            // No sense requiring a field to be unique if the underlying fieldtype
                            //  can't handle it
                            foreach ($allowed_fieldtypes as $num => $typeclass) {
                                if ( !isset($unique_fieldtypes[$typeclass]) )
                                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'"...required_field "'.$field_id.'" has the "must_be_unique" property, which is not allowed on the "'.$typeclass.'" typeclass');
                            }
                        }
                        else if ( $key === 'single_uploads_only' ) {
                            // This property only makes sense for File/Image fields
                            foreach ($allowed_fieldtypes as $num => $typeclass) {
                                if ( $typeclass !== 'File' && $typeclass !== 'Image' )
                                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'"...required_field "'.$field_id.'" has the "single_uploads_only" property, which is not allowed on the "'.$typeclass.'" typeclass');
                            }
                        }
                        else if ( $key === 'is_derived' ) {
                            // Have to do some additional validation on the php class now
                            $has_derived_field = true;

                            // File/Image fields can't be derived from some other field
                            foreach ($allowed_fieldtypes as $num => $typeclass) {
                                if ( $typeclass === 'File' || $typeclass === 'Image' )
                                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'"...required_field "'.$field_id.'" has the "is_derived" property, which is not allowed on the "'.$typeclass.'" typeclass');
                            }

                            // TODO - relax this?
                            // Deriving a field's contents only makes sense when the user can't edit the field
                            if ( !in_array('no_user_edits', $field_data['properties']) )
                                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'"...required_field "'.$field_id.'" has the "is_derived" property, but does not also have the "no_user_edits" property');
                        }
                        else if ( $key === 'is_optional' ) {
                            // Fields which are autogenerated, unique, or derived can't be optional
//                            if ( in_array('autogenerate_values', $field_data['properties']) )
//                                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'"...required_field "'.$field_id.'" has the "is_optional" property, which is not allowed with the "autogenerate_values" property');
//                            if ( in_array('must_be_unique', $field_data['properties']) )
//                                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'"...required_field "'.$field_id.'" has the "is_optional" property, which is not allowed with the "must_be_unique" property');

                            // TODO - these two are commented out to allow RRUFF X-ray Diffraction to reuse the RRUFFCellParameters plugin
                            // TODO - ...the autogenerate portion of the plugin can't be split into its own plugin until the "final solution" for plugin compatibility is implemented

                            if ( in_array('is_derived', $field_data['properties']) )
                                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'"...required_field "'.$field_id.'" has the "is_optional" property, which is not allowed with the "is_derived" property');
                        }
                        else if ( $key === 'no_user_edits' ) {
                            switch ($field_data['type']) {
                                case 'Boolean':
                                case 'DatetimeValue':
                                case 'IntegerValue':
                                case 'DecimalValue':
                                case 'ShortVarchar':
                                case 'MediumVarchar':
                                case 'LongVarchar':
                                case 'LongText':
                                case 'XYZData':
                                    // These fieldtypes can have the 'no_user_edits' property
                                    break;

                                default:
                                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'"...required_field "'.$field_id.'" has the "no_user_edits" property, which is not allowed on the "'.$field_data['type'].'" typeclass');
                            }
                        }
                    }
                }
            }
        }


        // Array plugins are't allowed to implement most of the other utility interfaces
        if ( $is_array_plugin ) {
            $type = 'Array';

            if ( $plugin_service instanceof DatafieldDerivationInterface )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the '.$type.' plugin "'.get_class($plugin_service).'" is not allowed to implement DatafieldDerivationInterface');
            if ( $plugin_service instanceof DatafieldReloadOverrideInterface )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the '.$type.' plugin "'.get_class($plugin_service).'" is not allowed to implement DatafieldReloadOverrideInterface');
            if ( $plugin_service instanceof MassEditTriggerEventInterface )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the '.$type.' plugin "'.get_class($plugin_service).'" is not allowed to implement MassEditTriggerEventInterface');
            if ( $plugin_service instanceof TableResultsOverrideInterface )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the '.$type.' plugin "'.get_class($plugin_service).'" is not allowed to implement TableResultsOverrideInterface');

            // They are allowed to implement PluginSettingsDialogOverrideInterface
//            if ( $plugin_service instanceof PluginSettingsDialogOverrideInterface )
//                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the '.$type.' plugin "'.get_class($plugin_service).'" is not allowed to implement PluginSettingsDialogOverrideInterface');
        }

        // Datafield plugins can't implement DatafieldDerivationInterface...they only reference a
        //  single datafield, so there's no way to determine what to derive from
        if ( $is_datafield_plugin && ($plugin_service instanceof DatafieldDerivationInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the Datafield plugin "'.get_class($plugin_service).'" is not allowed to implement DatafieldDerivationInterface');
        // Datafield plugins also don't need to implement DatafieldReloadOverrideInterface...the
        //  render plugin will be executed as part of the regular datafield reloading in Edit mode,
        //  assuming the plugin is allowed to execute there
        if ( $is_datafield_plugin && $plugin_config['override_field_reload'] )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the Datafield plugin "'.get_class($plugin_service).'" is not allowed to have override_field_reload set to true');
        if ( $is_datafield_plugin && ($plugin_service instanceof DatafieldReloadOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the Datafield plugin "'.get_class($plugin_service).'" is not allowed to implement DatafieldReloadOverrideInterface');

        // A plugin must implement DatafieldDerivationInterface if and only if it has at least
        //  one derived field
        if ( $has_derived_field && !($plugin_service instanceof DatafieldDerivationInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the plugin must implement DatafieldDerivationInterface since it has at least one derived field');
        if ( !$has_derived_field && ($plugin_service instanceof DatafieldDerivationInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the plugin must not implement DatafieldDerivationInterface since it has no derived fields');

        // A plugin must implement DatafieldReloadOverrideInterface if and only if it claims to
        //  override datafield reloading
        if ( $plugin_config['override_field_reload'] && !($plugin_service instanceof DatafieldReloadOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the plugin must implement DatafieldReloadOverrideInterface to match its config file');
        if ( !$plugin_config['override_field_reload'] && ($plugin_service instanceof DatafieldReloadOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the plugin must not implement DatafieldReloadOverrideInterface to match its config file');


        // ----------------------------------------
        // If there are entries in the "config_options" key, ensure they are properly formed
        $required_keys = array(
            'name',
//            'type',    // TODO - unused...delete this?
            'default',
//            'choices',    // this is optional
            'description',
//            'display_order',    // this is optional

//            'uses_custom_render',    // this is optional
//            'uses_layout_settings',    // this is optional
        );

        $uses_custom_render = false;
        $uses_layout_settings = false;
        if ( is_array($plugin_config['config_options']) ) {
            // Need to ensure that no RenderPluginOption entries share a name, since that is used
            //  as a unique key during install/update...
            $option_names = array();

            foreach ($plugin_config['config_options'] as $option_key => $option_data) {
                foreach ($required_keys as $key) {
                    if ( !array_key_exists($key, $option_data) )
                        throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", config_option "'.$option_key.'" is missing the key "'.$key.'"');
                }

                $option_displayname = $option_data['name'];
                if ( isset($option_names[$option_displayname]) )
                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", config_option "'.$option_key.'" has a duplicate name "'.$option_displayname.'"');
                else
                    $option_names[$option_displayname] = 1;

                // TODO - validate the optional 'choices' config value?

                if ( array_key_exists('uses_custom_render', $option_data) )
                    $uses_custom_render = true;
                if ( array_key_exists('uses_layout_settings', $option_data) )
                    $uses_layout_settings = true;
            }
        }

        // A plugin must implement PluginSettingsDialogOverrideInterface if and only if at least one of
        //  its options needs to override the renderPluginSettings dialog
        if ( $uses_custom_render && !($plugin_service instanceof PluginSettingsDialogOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the plugin must implement PluginSettingsDialogOverrideInterface to match its config file');
        if ( !$uses_custom_render && ($plugin_service instanceof PluginSettingsDialogOverrideInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the plugin must not implement PluginSettingsDialogOverrideInterface to match its config file');


        // ----------------------------------------
        // Don't necessarily want render plugins to attach to every single possible event...
        $illegal_events = array(
            'DatatypeCreatedEvent' => 0,
            'DatatypeModifiedEvent' => 0,
            'DatatypeDeletedEvent' => 0,
            'DatatypePublicStatusChangedEvent' => 0,
            'DatatypeImportedEvent' => 0,
            'DataTypeLinkStatusChangedEvent' => 0,

//            'DatarecordCreatedEvent' => 0,    // Render Plugins need this event
            'DatarecordModifiedEvent' => 0,
            'DatarecordDeletedEvent' => 0,
            'DatarecordPublicStatusChangedEvent' => 0,
            'DatarecordLinkStatusChangedEvent' => 0,

            'DatafieldCreatedEvent' => 0,
            'DatafieldModifiedEvent' => 0,
            'DatafieldDeletedEvent' => 0,

            // Render Plugins need these remaining events
//            'FilePreEncryptEvent' => 0,
//            'FilePostEncryptEvent' => 0,
//            'FilePublicStatusChangedEvent' => 0,
//            'FileDeletedEvent' => 0,

//            'MassEditTriggerEvent' => 0,

//            'PluginAttachEvent' => 0,
//            'PluginOptionsChangedEvent' => 0,
//            'PluginPreRemoveEvent' => 0,

//            'PostUpdateEvent' => 0,
        );
        // ...though this is more due to a great reluctance to test that a render plugin will properly
        //  work in all situations the event can be triggered in, rather than some structural reason
        // e.g. DatarecordModifiedEvent can be fired multiple times in rapid succession when the
        //  record is being modified by a user, or through MassEdit...

        // If there are entries in the "registered_events" key, ensure they are properly formed
        if ( is_array($plugin_config['registered_events']) ) {
            foreach ($plugin_config['registered_events'] as $event => $callable) {
                // Ensure the event isn't illegal for a render plugin to listen to
                if ( isset($illegal_events[$event]) )
                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is not allowed to listen to the ODR Event "'.$event.'"');

                // Ensure the events listed in the plugin config exist...
                if ( !isset($all_events[$event]) )
                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" references an invalid ODR Event "'.$event.'"');

                // ...and ensure the callables attached to the events also exist
                if ( !method_exists($plugin_service, $callable) )
                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the Event "'.$event.'" does not reference a callable function');
            }

            // The use of the "MassEditTriggerEvent" requires an additional interface
            if ( isset($plugin_config['registered_events']['MassEditTriggerEvent']) ) {
                if ( !($plugin_service instanceof MassEditTriggerEventInterface) )
                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" must implement MassEditTriggerEventInterface to be able to use the MassEditTrigger Event');
            }
        }
    }


    /**
     * Renders a list of the available render plugins on the server.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function pluginlistAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // Going to need fieldtype information to validate plugins
            $fieldtype_data = self::getFieldtypeData($em);

            // Get all render plugins listed in the database
            // TODO - rename renderPluginOptionsDef to renderPluginOptions
            $query = $em->createQuery(
               'SELECT rp, rpe, rpf, rpi, rpo
                FROM ODRAdminBundle:RenderPlugin AS rp
                LEFT JOIN rp.renderPluginEvents AS rpe
                LEFT JOIN rp.renderPluginFields AS rpf
                LEFT JOIN rp.renderPluginInstance AS rpi
                LEFT JOIN rp.renderPluginOptionsDef AS rpo'
            );
            $results = $query->getArrayResult();

            $installed_plugins = array();
            foreach ($results as $result) {
                $plugin_classname = $result['pluginClassName'];

                $installed_plugins[$plugin_classname] = $result;
            }

            // Get all render plugins on the server
            $available_plugins = self::getAvailablePlugins($fieldtype_data);

            // Determine whether any of the installed render plugins differ from their config files
            $updates = self::getPluginDiff($fieldtype_data, $installed_plugins, $available_plugins);
            $plugins_with_updates = $updates['raw'];
            $readable_plugin_updates = $updates['readable'];

            // Determine whether any of the plugins that need updates are going to be problematic
            $plugin_update_problems = self::getPluginUpdateProblems($em, $plugins_with_updates);

            // Render and return a page displaying the installed/available plugins
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Plugins:list_plugins.html.twig',
                    array(
                        'installed_plugins' => $installed_plugins,
                        'available_plugins' => $available_plugins,

                        'plugins_with_updates' => $plugins_with_updates,
                        'readable_plugin_updates' => $readable_plugin_updates,
                        'plugin_update_problems' => $plugin_update_problems,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xbbdd3fb7;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Checks the installed render plugins' database configs against their config files, and returns
     * which render plugins can be updated.
     *
     * @param array $fieldtype_data {@link self::getFieldtypeData()}
     * @param array $installed_plugins
     * @param array $available_plugins
     *
     * @throws ODRException
     *
     * @return array
     */
    private function getPluginDiff($fieldtype_data, $installed_plugins, $available_plugins)
    {
        // ----------------------------------------
        // RenderPluginField entries in the database store allowed fieldtypes by it, but the plugin
        //  config yml files use strings...need to be able to convert id into typeclass
        $rpf_typeclasses = array_flip( $fieldtype_data['rpf_typeclasses'] );
        // Also need to handle radio typeclass equivalences
        $single_select_fieldtypes = $fieldtype_data['single_select_fieldtypes'];
        $multiple_select_fieldtypes = $fieldtype_data['multiple_select_fieldtypes'];


        // ----------------------------------------
        $plugins_needing_updates = array();
        $readable_plugin_updates = array();
        foreach ($installed_plugins as $plugin_classname => $installed_plugin_data) {
            // Complain when an "installed" plugin appears to not be available
            if ( !isset($available_plugins[$plugin_classname]) )
                throw new ODRException('The render plugin "'.$plugin_classname.'" "'.$installed_plugin_data['pluginName'].'" is missing its config file on the server');

            $plugin_config = $available_plugins[$plugin_classname];

            // ----------------------------------------
            // Check the non-array properties of the render plugin...
            $plugins_needing_updates[$plugin_classname] = array();

            // NOTE: using '==' because yaml files are going to be empty string, while
            //  database could be null instead
            if ( $installed_plugin_data['pluginName'] != $plugin_config['name'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'name';
                $readable_plugin_updates[$plugin_classname][] = 'name changed to '.$plugin_config['name'];
            }

            if ( $installed_plugin_data['description'] != $plugin_config['description'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'description';
                $readable_plugin_updates[$plugin_classname][] = 'description changed';
            }

            if ( $installed_plugin_data['category'] != $plugin_config['category'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'category';
                $readable_plugin_updates[$plugin_classname][] = 'category changed to '.$plugin_config['category'];
            }

            // YAML converts stuff into boolean values if possible
            if ( $plugin_config['render'] === false )
                $plugin_config['render'] = 'false';

            if ( $installed_plugin_data['render'] != $plugin_config['render'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'render';
                $readable_plugin_updates[$plugin_classname][] = 'render changed to '.$plugin_config['render'];
            }

            if ( $installed_plugin_data['overrideChild'] != $plugin_config['override_child'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'override_child';
                $readable_plugin_updates[$plugin_classname][] = 'override_child changed to '.$plugin_config['override_child'];
            }

            if ( $installed_plugin_data['overrideFields'] != $plugin_config['override_fields'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'override_fields';
                $readable_plugin_updates[$plugin_classname][] = 'override_fields changed to '.$plugin_config['override_fields'];
            }

            if ( $installed_plugin_data['overrideFieldReload'] != $plugin_config['override_field_reload'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'override_field_reload';
                $readable_plugin_updates[$plugin_classname][] = 'override_field_reload changed to '.$plugin_config['override_field_reload'];
            }

            if ( $installed_plugin_data['overrideTableFields'] != $plugin_config['override_table_fields'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'override_table_fields';
                $readable_plugin_updates[$plugin_classname][] = 'override_table_fields changed to '.$plugin_config['override_table_fields'];
            }

            if ( $installed_plugin_data['overrideExport'] != $plugin_config['override_export'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'override_export';
                $readable_plugin_updates[$plugin_classname][] = 'override_export changed to '.$plugin_config['override_export'];
            }

            if ( $installed_plugin_data['overrideSearch'] != $plugin_config['override_search'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'override_search';
                $readable_plugin_updates[$plugin_classname][] = 'override_search changed to '.$plugin_config['override_search'];
            }

            if ( $installed_plugin_data['overrideSort'] != $plugin_config['override_sort'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'override_sort';
                $readable_plugin_updates[$plugin_classname][] = 'override_sort changed to '.$plugin_config['override_sort'];
            }

            if ( $installed_plugin_data['suppressNoFieldsNote'] != $plugin_config['suppress_no_fields_note'] ) {
                $plugins_needing_updates[$plugin_classname]['meta'][] = 'suppress_no_fields_note';
                $readable_plugin_updates[$plugin_classname][] = 'suppress_no_fields_note changed to '.$plugin_config['suppress_no_fields_note'];
            }


            // Need to verify the plugin type too...
            $plugin_type = strtolower( $plugin_config['plugin_type'] );
            if ( $plugin_type === 'datatype' )
                $plugin_type = RenderPlugin::DATATYPE_PLUGIN;
            else if ( $plugin_type === 'themeelement' )
                $plugin_type = RenderPlugin::THEME_ELEMENT_PLUGIN;
            else if ( $plugin_type === 'datafield' )
                $plugin_type = RenderPlugin::DATAFIELD_PLUGIN;
            else if ( $plugin_type === 'array' )
                $plugin_type = RenderPlugin::ARRAY_PLUGIN;

            if ( $installed_plugin_data['plugin_type'] !== $plugin_type ) {
                $plugins_needing_updates[$plugin_classname]['plugin_type'][] = $plugin_type;
                $readable_plugin_updates[$plugin_classname][] = 'plugin_type changed to '.$plugin_config['plugin_type'];
            }


            // ----------------------------------------
            // Determine whether the required_theme_elements parameter changed...
            if ( $plugin_type === RenderPlugin::THEME_ELEMENT_PLUGIN ) {
                if ( $installed_plugin_data['requiredThemeElements'] !== $plugin_config['required_theme_elements'] ) {
                    $plugins_needing_updates[$plugin_classname]['required_theme_elements'][] = $plugin_type;
                    $readable_plugin_updates[$plugin_classname][] = 'required_theme_elements changed to '.$plugin_config['required_theme_elements'];
                }
            }


            // ----------------------------------------
            // Determine whether any renderPluginFields need to be created/updated/deleted...
            $tmp = array();

            foreach ( $installed_plugin_data['renderPluginFields'] as $num => $rpf) {
                // TODO - is there a way to have a better key than fieldName?
                $allowed_fieldtypes = array();
                $properties = array();

                // allowedFieldtypes are stored as a comma-separated list in the database
                $ft_ids = explode(',', $rpf['allowedFieldtypes']);
                foreach ($ft_ids as $ft_id) {
                    if ( isset($single_select_fieldtypes[$ft_id]) ) {
                        foreach ($single_select_fieldtypes as $num => $typename)
                            $allowed_fieldtypes[$typename] = 1;
                    }
                    else if ( isset($multiple_select_fieldtypes[$ft_id]) ) {
                        foreach ($multiple_select_fieldtypes as $num => $typename)
                            $allowed_fieldtypes[$typename] = 1;
                    }
                    else {
                        $allowed_fieldtypes[ $rpf_typeclasses[$ft_id] ] = 1;
                    }
                }
                $allowed_fieldtypes = array_keys($allowed_fieldtypes);

                // properties are stored in individual database fields, but need to be in an array
                //  for the diff check to work
                if ( $rpf['must_be_unique'] )
                    $properties[] = 'must_be_unique';
                if ( $rpf['single_uploads_only'] )
                    $properties[] = 'single_uploads_only';
                if ( $rpf['no_user_edits'] )
                    $properties[] = 'no_user_edits';
                if ( $rpf['autogenerate_values'] )
                    $properties[] = 'autogenerate_values';
                if ( $rpf['is_derived'] )
                    $properties[] = 'is_derived';
                if ( $rpf['is_optional'] )
                    $properties[] = 'is_optional';
                sort($properties);

                $tmp[ $rpf['fieldName'] ] = array(
                    'description' => $rpf['description'],
                    'allowed_fieldtypes' => $allowed_fieldtypes,
                    'properties' => $properties,

                    // The display_order property doesn't have to be defined in the yml file, but
                    //  should exist here since this is loaded from the database
                    'display_order' => $rpf['display_order'],
                );
            }

            if ( is_array($plugin_config['required_fields']) ) {
                foreach ($plugin_config['required_fields'] as $key => $data) {
                    $fieldname = $data['name'];
                    $config_fieldtypes = explode('|', $data['type']);

                    // Need to ensure that "Single Radio" also means "Single Select", and vice versa
                    // ...and the same for the "Multiple" flavors of those fieldtypes
                    $config_fieldtypes = array_flip($config_fieldtypes);
                    if ( isset($config_fieldtypes['Single Radio']) || isset($config_fieldtypes['Single Select']) ) {
                        $config_fieldtypes['Single Radio'] = 1;
                        $config_fieldtypes['Single Select'] = 1;
                    }
                    else if ( isset($config_fieldtypes['Multiple Radio']) || isset($config_fieldtypes['Multiple Select']) ) {
                        $config_fieldtypes['Multiple Radio'] = 1;
                        $config_fieldtypes['Multiple Select'] = 1;
                    }
                    $config_fieldtypes = array_keys($config_fieldtypes);

                    // rpf entries aren't required to have display_order or these additional restrictions
                    $display_order = 0;
                    if ( isset($data['display_order']) )
                        $display_order = $data['display_order'];
                    $config_properties = array();
                    if ( isset($data['properties']) )
                        $config_properties = $data['properties'];

                    if ( !isset($tmp[$fieldname]) ) {
                        // This field doesn't exist in the database, make an entry for it
                        $tmp[$fieldname] = array(
                            'description' => $data['description'],
                            'allowed_fieldtypes' => $config_fieldtypes,
                            'properties' => $config_properties,
                            'display_order' => $display_order,
                        );

                        $readable_plugin_updates[$plugin_classname][] = 'new required_field: '.$fieldname;
                    }
                    else {
                        // This field exists in the database...
                        $existing_data = $tmp[$fieldname];

                        // NOTE: using '==' because yaml files are going to be empty string, while
                        //  database could be null instead
                        if ( $existing_data['description'] == $data['description'] )
                            unset( $tmp[$fieldname]['description'] );

                        // Need to use array_diff() both ways in order to catch when the database has
                        //  a subset of the allowed_fieldtypes that are in the config file, or vice versa
                        $diff_1 = array_diff($tmp[$fieldname]['allowed_fieldtypes'], $config_fieldtypes);
                        $diff_2 = array_diff($config_fieldtypes, $tmp[$fieldname]['allowed_fieldtypes']);

                        if ( count($diff_1) == 0 && count($diff_2) == 0 ) {
                            // If there's no difference in the allowed_fieldtypes, then delete the
                            //  key so later sections don't think something has changed
                            unset( $tmp[$fieldname]['allowed_fieldtypes'] );
                        }
                        else {
                            // If there is a difference, then it's more useful to have the new
                            //  allowed_fieldtypes in the $plugins_needing_updates array
                            $tmp[$fieldname]['allowed_fieldtypes'] = $config_fieldtypes;
                        }

                        // Same theory for the additional restrictions of the rpf entry
                        $diff_1 = array_diff($tmp[$fieldname]['properties'], $config_properties);
                        $diff_2 = array_diff($config_properties, $tmp[$fieldname]['properties']);

                        if ( count($diff_1) == 0 && count($diff_2) == 0 ) {
                            // If there's no difference in the field's properties, then delete the
                            //  key so later sections don't think something has changed
                            unset( $tmp[$fieldname]['properties'] );
                        }
                        else {
                            // If there is a difference, then it's more useful to have the new
                            //  field properties in the $plugins_needing_updates array
                            $tmp[$fieldname]['properties'] = $config_properties;
                        }

                        // If the plugin config doesn't define a display_order, or the defined value
                        //  matches the database...
                        if ( !isset($data['display_order']) || $existing_data['display_order'] == $data['display_order'] ) {
                            // ...then there's no change to be made
                            unset( $tmp[$fieldname]['display_order'] );
                        }

                        // If there are no differences, remove the entry
                        if ( count($tmp[$fieldname]) == 0 )
                            unset( $tmp[$fieldname] );
                        else
                            $readable_plugin_updates[$plugin_classname][] = 'updated required_field: '.$fieldname;
                    }
                }
            }

            // If any entries remain in the temporary array, then the config file does not match
            //  the database
            if ( count($tmp) > 0 )
                $plugins_needing_updates[$plugin_classname]['required_fields'] = $tmp;

            // Need one final check to determine whether any fields got deleted from the config file
            $installed_fields = array();
            $config_fields = array();
            if ( isset($installed_plugins[$plugin_classname]['renderPluginFields']) ) {
                foreach ($installed_plugins[$plugin_classname]['renderPluginFields'] as $num => $rpf)
                    $installed_fields[ $rpf['fieldName'] ] = 1;
            }
            if ( isset($available_plugins[$plugin_classname]['required_fields']) ) {
                foreach ($available_plugins[$plugin_classname]['required_fields'] as $key => $data)
                    $config_fields[ $data['name'] ] = 1;
            }
            foreach ($installed_fields as $rpf_name => $num) {
                if ( !isset($config_fields[$rpf_name]) )
                    $readable_plugin_updates[$plugin_classname][] = 'deleted required_field: '.$rpf_name;
            }


            // ----------------------------------------
            // Determine whether any renderPluginOptions need to be created/updated/deleted...
            $tmp = array();

            // TODO - rename renderPluginOptionsDef to renderPluginOptions
            foreach ( $installed_plugin_data['renderPluginOptionsDef'] as $num => $rpo) {
                // TODO - is there a way to have a better key than optionName?
                $tmp[ $rpo['name'] ] = array(
                    'name' => $rpo['displayName'],
                    'default' => $rpo['defaultValue'],
                    'description' => $rpo['description']
                );

                // These entries are optional in the config file, so ignore unless they're not null
                if ( !is_null($rpo['choices']) )
                    $tmp[ $rpo['name'] ]['choices'] = $rpo['choices'];
                if ( !is_null($rpo['display_order']) )
                    $tmp[ $rpo['name'] ]['display_order'] = $rpo['display_order'];
                if ( !is_null($rpo['uses_custom_render']) )
                    $tmp[ $rpo['name'] ]['uses_custom_render'] = $rpo['uses_custom_render'];
                if ( !is_null($rpo['uses_layout_settings']) )
                    $tmp[ $rpo['name'] ]['uses_layout_settings'] = $rpo['uses_layout_settings'];
            }

            if ( is_array($plugin_config['config_options']) ) {
                foreach ($plugin_config['config_options'] as $option_key => $data) {

                    if ( !isset($tmp[$option_key]) ) {
                        // This option doesn't exist in the database, make an entry for it
                        $tmp[$option_key] = array(
                            'name' => $data['name'],
                            'default' => $data['default'],
                            'description' => $data['description'],
                        );

                        // These entries are optional, so have to check whether they exists first
                        if ( isset($data['choices']) )
                            $tmp[$option_key]['choices'] = $data['choices'];
                        if ( isset($data['display_order']) )
                            $tmp[$option_key]['display_order'] = $data['display_order'];
                        if ( isset($data['uses_custom_render']) )
                            $tmp[$option_key]['uses_custom_render'] = $data['uses_custom_render'];
                        if ( isset($data['uses_layout_settings']) )
                            $tmp[$option_key]['uses_layout_settings'] = $data['uses_layout_settings'];

                        $readable_plugin_updates[$plugin_classname][] = 'new config_option: '.$option_key;
                    }
                    else {
                        // This option exists in the database...
                        $existing_data = $tmp[$option_key];

                        // NOTE: using '==' because yaml files are going to be empty string, while
                        //  database could be null instead
                        if ( $existing_data['name'] == $data['name'] )
                            unset( $tmp[$option_key]['name'] );

                        // The YAML parser converts numeric/boolean values away from strings, whereas
                        //  the database stores this field as a string...so conversion might be necessary
                        if ( is_int($data['default']) && intval($existing_data['default']) === $data['default'])
                            unset( $tmp[$option_key]['default'] );
                        else if ( is_float($data['default']) && floatval($existing_data['default']) === $data['default'])
                            unset( $tmp[$option_key]['default'] );
                        else if ( is_bool($data['default']) ) {
                            if ( $data['default'] && $existing_data['default'] === "true"
                                || !$data['default'] && $existing_data['default'] === "false"
                            ) {
                                unset( $tmp[$option_key]['default'] );
                            }
                        }
                        else if ( $existing_data['default'] == $data['default'] )
                            unset( $tmp[$option_key]['default'] );

                        if ( $existing_data['description'] == $data['description'] )
                            unset( $tmp[$option_key]['description'] );

                        if ( isset($data['choices']) ) {
                            if ( $existing_data['choices'] == $data['choices'] )
                                unset( $tmp[$option_key]['choices'] );
                        }

                        // Since "display_order" is never null in the database, it'll always exist
                        //  in $tmp...but since it probably doesn't exist in the plugin config, the
                        //  diff checker will repeatedly flag the plugin as needing an update...to
                        //  fix this, just pretend the plugin's config had the default value
                        if ( !isset($data['display_order']) )
                            $data['display_order'] = 0;

                        if ( isset($data['display_order']) ) {
                            if ( $existing_data['display_order'] == $data['display_order'] )
                                unset( $tmp[$option_key]['display_order'] );
                        }

                        // Same thing for the "uses_custom_render" and "uses_layout_settings" keys
                        if ( !isset($data['uses_custom_render']) )
                            $data['uses_custom_render'] = false;

                        if ( isset($data['uses_custom_render']) ) {
                            if ( $existing_data['uses_custom_render'] == $data['uses_custom_render'] )
                                unset( $tmp[$option_key]['uses_custom_render'] );
                        }

                        if ( !isset($data['uses_layout_settings']) )
                            $data['uses_layout_settings'] = false;

                        if ( isset($data['uses_layout_settings']) ) {
                            if ( $existing_data['uses_layout_settings'] == $data['uses_layout_settings'] )
                                unset( $tmp[$option_key]['uses_layout_settings'] );
                        }

                        // If there are no differences, remove the entry
                        if ( count($tmp[$option_key]) == 0 )
                            unset( $tmp[$option_key] );
                        else
                            $readable_plugin_updates[$plugin_classname][] = 'updated config_option: '.$option_key;
                    }
                }
            }

            // If any entries remain in the temporary array, then the config file does not match
            //  the database
            if ( count($tmp) > 0 )
                $plugins_needing_updates[$plugin_classname]['config_options'] = $tmp;

            // Need one final check to determine whether any options got deleted from the config file
            $installed_options = array();
            $config_options = array();
            if ( isset($installed_plugins[$plugin_classname]['renderPluginOptionsDef']) ) {
                foreach ($installed_plugins[$plugin_classname]['renderPluginOptionsDef'] as $num => $rpod)
                    $installed_options[ $rpod['name'] ] = 1;
            }
            if ( isset($available_plugins[$plugin_classname]['config_options']) ) {
                foreach ($available_plugins[$plugin_classname]['config_options'] as $key => $data)
                    $config_options[ $key ] = 1;
            }
            foreach ($installed_options as $rpod_name => $num) {
                if ( !isset($config_options[$rpod_name]) )
                    $readable_plugin_updates[$plugin_classname][] = 'deleted config_option: '.$rpod_name;
            }


            // ----------------------------------------
            // Determine whether any renderPluginEvents need to be created/updated/deleted...
            $tmp = array();

            foreach ( $installed_plugin_data['renderPluginEvents'] as $num => $rpe) {
                // TODO - is there a way to have a better key than eventName?
                $tmp[ $rpe['eventName'] ] = $rpe['eventCallable'];
            }

            if ( is_array($plugin_config['registered_events']) ) {
                foreach ($plugin_config['registered_events'] as $event_name => $event_callable) {

                    if ( !isset($tmp[$event_name]) ) {
                        // This event doesn't exist in the database, make an entry for it
                        $tmp[$event_name] = $event_callable;

                        $readable_plugin_updates[$plugin_classname][] = 'new registered_event: '.$event_name;
                    }
                    else {
                        // This event exists in the database...
                        $existing_callable = $tmp[$event_name];

                        // If the callable in the database is the same as the callable in the
                        //  config file, then it doesn't need to be updated
                        if ( $existing_callable === $event_callable )
                            unset( $tmp[$event_name] );
                        else
                            $readable_plugin_updates[$plugin_classname][] = 'changed registered_event callable: '.$event_name;
                    }
                }
            }

            // If any entries remain in the temporary array, then the config file does not match
            //  the database
            if ( count($tmp) > 0 )
                $plugins_needing_updates[$plugin_classname]['required_events'] = $tmp;

            // Need one final check to determine whether any events got deleted from the config file
            $installed_events = array();
            $config_events = array();
            if ( isset($installed_plugins[$plugin_classname]['renderPluginEvents']) ) {
                foreach ($installed_plugins[$plugin_classname]['renderPluginEvents'] as $num => $rpe)
                    $installed_events[ $rpe['eventName'] ] = 1;
            }
            if ( isset($available_plugins[$plugin_classname]['registered_events']) ) {
                foreach ($available_plugins[$plugin_classname]['registered_events'] as $name => $callable)
                    $config_events[ $name ] = 1;
            }
            foreach ($installed_events as $rpe_name => $num) {
                if ( !isset($config_events[$rpe_name]) )
                    $readable_plugin_updates[$plugin_classname][] = 'deleted registered_event: '.$rpe_name;
            }
        }


        // ----------------------------------------
        // If a plugin doesn't actually have any changes, it doesn't need an update...
        foreach ($plugins_needing_updates as $plugin_classname => $data) {
            if ( count($data) == 0 ) {
                unset( $plugins_needing_updates[$plugin_classname] );
                unset( $readable_plugin_updates[$plugin_classname] );
            }
        }

        return array(
            'raw' => $plugins_needing_updates,
            'readable' => $readable_plugin_updates,
        );
    }


    /**
     * Determines which render plugins have a "problematic" update pending, such as...
     *
     * 1) changing to or from a datafield plugin...the mapping for a datafield plugin differs enough
     *      that it'll break if migrated
     * 2) changing the permitted fieldtypes so that current datafields no longer match...e.g. a
     *      renderplugin_field used to only allow shortvarchar, but now only allows mediumvarchar
     * 3) adding the "must_be_unique" property
     * 4) adding the "single_uploads_only" property
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $plugins_with_updates
     *
     * @return array
     */
    private function getPluginUpdateProblems($em, $plugins_with_updates)
    {
        // Going to return an array of any problems that would be created by updating a plugin
        $plugin_update_problems = array();

        // Extract the plugin classnames from the list of plugins that can be updated
        $plugin_classnames = array_keys($plugins_with_updates);

        // If the render plugin isn't in use, then none of these updates will cause a problem
        // NOTE: the query below doesn't use rpm.dataType because that entry isn't guaranteed to be
        //  non-null in earlier versions of ODR...
        $query = $em->createQuery(
           'SELECT partial rp.{id, pluginName, pluginClassName, plugin_type},
                partial rpi.{id}, partial rpm.{id}, partial rpf.{id, fieldName},
                partial df.{id}, partial dfm.{id, fieldName, is_unique, allow_multiple_uploads},
                partial ft.{id, typeClass, typeName},
                partial dt.{id}, partial dtm.{id, shortName}, partial gdt.{id}

            FROM ODRAdminBundle:RenderPlugin rp
            JOIN rp.renderPluginInstance AS rpi
            JOIN rpi.renderPluginMap AS rpm
            JOIN rpm.renderPluginFields AS rpf

            JOIN rpm.dataField AS df
            JOIN df.dataFieldMeta AS dfm
            JOIN dfm.fieldType AS ft

            JOIN df.dataType AS dt
            JOIN dt.dataTypeMeta AS dtm
            JOIN dt.grandparent AS gdt

            WHERE rp.pluginClassName IN (:plugin_classnames)
            AND (rpi.dataType IS NOT NULL OR rpi.dataField IS NOT NULL)
            AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL
            AND rpm.deletedAt IS NULL AND rpf.deletedAt IS NULL
            AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL AND ft.deletedAt IS NULL
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND gdt.deletedAt IS NULL'
        )->setParameters(
            array(
                'plugin_classnames' => $plugin_classnames
            )
        );
        $results = $query->getArrayResult();

        // So, for each render plugin that needs an update...
        foreach ($results as $rp_num => $rp) {
            $plugin_name = $rp['pluginName'];
            $plugin_classname = $rp['pluginClassName'];

            // ...if it's in use...
            if ( !empty($rp['renderPluginInstance']) ) {
                // ----------------------------------------
                // The datatype, themeElement, and array plugins are based off datatypes, while the
                //  datafield and search plugins are based off datafields...the two types store the
                //  relevant renderPluginMap and renderPluginInstance data differently, so they can't
                //  be converted to a plugin of the other type
                if ( isset($plugins_with_updates[$plugin_classname]['plugin_type']) ) {
                    $current_plugin_type = $rp['plugin_type'];
                    if ( $current_plugin_type === RenderPlugin::DATATYPE_PLUGIN )
                        $current_plugin_type = 'database';
                    else if ( $current_plugin_type === RenderPlugin::THEME_ELEMENT_PLUGIN )
                        $current_plugin_type = 'themeElement';
                    else if ( $current_plugin_type === RenderPlugin::DATAFIELD_PLUGIN )
                        $current_plugin_type = 'datafield';
                    else if ( $current_plugin_type === RenderPlugin::ARRAY_PLUGIN )
                        $current_plugin_type = 'array';

                    $new_plugin_type = $plugins_with_updates[$plugin_classname]['plugin_type'][0];
                    if ( $new_plugin_type === RenderPlugin::DATATYPE_PLUGIN )
                        $new_plugin_type = 'database';
                    else if ( $new_plugin_type === RenderPlugin::THEME_ELEMENT_PLUGIN )
                        $new_plugin_type = 'themeElement';
                    else if ( $new_plugin_type === RenderPlugin::DATAFIELD_PLUGIN )
                        $new_plugin_type = 'datafield';
                    else if ( $new_plugin_type === RenderPlugin::ARRAY_PLUGIN )
                        $new_plugin_type = 'array';


                    $converting_to_datafield_mapping = $converting_from_datafield_mapping = false;
                    if (
                        ($current_plugin_type === 'database' || $current_plugin_type === 'themeElement' || $current_plugin_type === 'array')
                        &&
                        ($new_plugin_type === 'datafield')    // NOTE: leaving it like this incase another plugin type is added that's thematically similar to a datafield plugin
                    ) {
                        $converting_to_datafield_mapping = true;
                    }
                    if (
                        ($current_plugin_type === 'datafield')
                        &&
                        ($new_plugin_type === 'database' || $new_plugin_type === 'themeElement' || $new_plugin_type === 'array')
                    ) {
                        $converting_from_datafield_mapping = true;
                    }


                    if ($converting_to_datafield_mapping) {
                        // Database currently lists this as a datatype/themeElement/array plugin, but
                        //  this entry in $plugins_needing_updates means it wants to change to a
                        //  datafield plugin
                        foreach ($rp['renderPluginInstance'] as $rpi_num => $rpi) {
                            // Extract the datatype's name from the first mapped field
                            $df = $rpi['renderPluginMap'][0]['dataField'];
                            $dt = $df['dataType'];
                            $dt_name = $dt['dataTypeMeta'][0]['shortName'];
                            $grandparent_dt_id = $dt['grandparent']['id'];

                            self::insertPluginUpdateError(
                                $plugin_update_problems,
                                $plugin_classname,
                                $grandparent_dt_id,
                                $dt_name,
                                '',    // empty datafield name
                                'Converting this '.$current_plugin_type.' plugin into a '.$new_plugin_type.' plugin would break the existing renderPluginMap entries'
                            );
                        }
                    }
                    else if ($converting_from_datafield_mapping) {
                        // Database currently lists this as a datafield plugin, but this entry in
                        //  $plugins_needing_updates means it wants to change to a
                        //  datatype/themeElement/array plugin
                        foreach ($rp['renderPluginInstance'] as $rpi_num => $rpi) {
                            // Extract the name for the datafield and the datatype
                            $df = $rpi['renderPluginMap'][0]['dataField'];
                            $df_name = $df['dataFieldMeta'][0]['fieldName'];

                            $dt = $df['dataType'];
                            $dt_name = $dt['dataTypeMeta'][0]['shortName'];
                            $grandparent_dt_id = $dt['grandparent']['id'];

                            self::insertPluginUpdateError(
                                $plugin_update_problems,
                                $plugin_classname,
                                $grandparent_dt_id,
                                $dt_name,
                                $df_name,
                                'Converting this '.$current_plugin_type.' plugin into a '.$new_plugin_type.' plugin would break the existing renderPluginMap entries'
                            );
                        }
                    }
                }


                // ----------------------------------------
                // Complain when the config wants to change some property of a renderPluginField,
                //  but this change would cause at least one currently mapped datafield to no longer
                //  match the config file
                if ( isset($plugins_with_updates[$plugin_classname]['required_fields']) ) {
                    foreach ($plugins_with_updates[$plugin_classname]['required_fields'] as $rpf_name => $rpf_changes) {
                        // Going to need the relevant info from the currently mapped datafields
                        $mapped_datafields = self::getMappedDatafields($rp, $rpf_name);

                        if ( isset($rpf_changes['allowed_fieldtypes']) ) {
                            // There's been a change to the allowed fieldtypes for this rpf...
                            $allowed_fieldtypes = array_flip($rpf_changes['allowed_fieldtypes']);

                            foreach ($mapped_datafields as $df_id => $df) {
                                // ...complain if the existing mapped datafields don't match the
                                //  config file's newly allowed_fieldtypes
                                if ( !isset($allowed_fieldtypes[ $df['typeClass'] ]) ) {
                                    self::insertPluginUpdateError(
                                        $plugin_update_problems,
                                        $plugin_classname,
                                        $df['grandparent_datatype_id'],
                                        $df['datatype_name'],
                                        $df['fieldName'],
                                        'The datafield is currently a "'.$df['typeClass'].'" typeclass, but the new plugin config only allows the '.implode('|', $rpf_changes['allowed_fieldtypes']).' typeclasses'
                                    );
                                }
                            }
                        }

                        if ( isset($rpf_changes['properties']) ) {
                            // There's been a change to the required properties for this rpf
                            $required_properties = array_flip($rpf_changes['properties']);

                            foreach ($mapped_datafields as $df_id => $df) {
                                // ...complain if the existing mapped datafields don't match the
                                // "must_be_unique" or "single_uploads_only" properties
                                if ( isset($required_properties['must_be_unique']) && !$df['is_unique'] ) {
                                    self::insertPluginUpdateError(
                                        $plugin_update_problems,
                                        $plugin_classname,
                                        $df['grandparent_datatype_id'],
                                        $df['datatype_name'],
                                        $df['fieldName'],
                                        'The new plugin config requires this datafield to be unique'
                                    );
                                }

                                if ( isset($required_properties['single_uploads_only']) && $df['allow_multiple_uploads'] ) {
                                    self::insertPluginUpdateError(
                                        $plugin_update_problems,
                                        $plugin_classname,
                                        $df['grandparent_datatype_id'],
                                        $df['datatype_name'],
                                        $df['fieldName'],
                                        'The new plugin config requires this datafield to not allow multiple file/image uploads'
                                    );
                                }
                            }
                        }
                    }
                }
            }

            // Don't report the plugin as having problems if there aren't any
            if ( empty($plugin_update_problems[$plugin_classname]) )
                unset( $plugin_update_problems[$plugin_classname] );
        }

        return $plugin_update_problems;
    }


    /**
     * Due to there being entirely too many sublevels of arrays required for accurate reporting of
     * errors with plugin updates, it's easier to use a separate function...
     *
     * @param array $plugin_update_problems
     * @param string $plugin_classname
     * @param int $grandparent_datatype_id
     * @param string $datatype_name
     * @param string $datafield_name
     * @param string $message
     */
    private function insertPluginUpdateError(&$plugin_update_problems, $plugin_classname, $grandparent_datatype_id, $datatype_name, $datafield_name, $message)
    {
        if ( !isset($plugin_update_problems[$plugin_classname]) )
            $plugin_update_problems[$plugin_classname] = array();

        if ( !isset($plugin_update_problems[$plugin_classname][$grandparent_datatype_id]) )
            $plugin_update_problems[$plugin_classname][$grandparent_datatype_id] = array();

        if ( !isset($plugin_update_problems[$plugin_classname][$grandparent_datatype_id][$datatype_name]) )
            $plugin_update_problems[$plugin_classname][$grandparent_datatype_id][$datatype_name] = array();

        if ( !isset($plugin_update_problems[$plugin_classname][$grandparent_datatype_id][$datatype_name][$datafield_name]) )
            $plugin_update_problems[$plugin_classname][$grandparent_datatype_id][$datatype_name][$datafield_name] = array();

        $plugin_update_problems[$plugin_classname][$grandparent_datatype_id][$datatype_name][$datafield_name][] = $message;
    }


    /**
     * Locates the datafield entries for every datafield mapped to a given renderPluginField entry
     * from an array of renderPlugin data
     *
     * @param array $rp
     * @param string $rpf_name
     *
     * @return array
     * @see self::getPluginUpdateProblems()
     */
    private function getMappedDatafields($rp, $rpf_name)
    {
        $mapped_datafields = array();
        foreach ($rp['renderPluginInstance'] as $rpi_num => $rpi) {
            foreach ($rpi['renderPluginMap'] as $rpm_num => $rpm) {
                if ( $rpm['renderPluginFields']['fieldName'] === $rpf_name ) {
                    // Recreate the provided mapped datafield as a more useful format

                    // Don't need to worry about the possibility of "optional" fields not being
                    //  mapped...if it's not mapped, then it doesn't matter at this moment
                    $df = $rpm['dataField'];
                    $df_id = $df['id'];
                    $dfm = $df['dataFieldMeta'][0];
                    $dt = $df['dataType'];
                    $dtm = $dt['dataTypeMeta'][0];

                    $mapped_datafields[$df_id] = array(
                        'grandparent_datatype_id' => $dt['grandparent']['id'],
                        'datatype_name' => $dtm['shortName'],

                        'fieldName' => $dfm['fieldName'],
                        'is_unique' => $dfm['is_unique'],
                        'allow_multiple_uploads' => $dfm['allow_multiple_uploads'],
                    );

                    // Need to differentiate between the four different types of Radio fields...
                    if ( $dfm['fieldType']['typeClass'] === 'Radio' )
                        $mapped_datafields[$df_id]['typeClass'] = $dfm['fieldType']['typeName'];
                    else
                        $mapped_datafields[$df_id]['typeClass'] = $dfm['fieldType']['typeClass'];
                }
            }
        }

        return $mapped_datafields;
    }


    /**
     * Creates the database entries for a specified render plugin file, effectively "installing" it
     * on the server and making it available for use.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function installpluginAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab the data from the POST request
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['plugin_classname']) )
                throw new ODRBadRequestException('Invalid Form');

            $plugin_classname = $post['plugin_classname'];

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // Going to need fieldtype information to validate plugins
            $fieldtype_data = self::getFieldtypeData($em);

            $rpf_typeclasses = $fieldtype_data['rpf_typeclasses'];
            $single_select_fieldtypes = $fieldtype_data['single_select_fieldtypes'];
            $multiple_select_fieldtypes = $fieldtype_data['multiple_select_fieldtypes'];

            // Ensure the requested plugin isn't already installed
            $query = $em->createQuery(
               'SELECT rp 
                FROM ODRAdminBundle:RenderPlugin AS rp
                WHERE rp.pluginClassName = :plugin_classname'
            )->setParameters( array('plugin_classname' => $plugin_classname) );
            $results = $query->getArrayResult();

            if ( count($results) > 0 )
                throw new ODRException('This RenderPlugin is already installed');

            // Load the configuration file for the requested render plugin
            $available_plugins = self::getAvailablePlugins($fieldtype_data, $plugin_classname);
            if ( !isset($available_plugins[$plugin_classname]) )
                throw new ODRBadRequestException('Unable to install a non-existant RenderPlugin');


            // ----------------------------------------
            // Pull the plugin's data from the config file
            $plugin_data = $available_plugins[$plugin_classname];

            // Create a new RenderPlugin entry from the config file data
            $render_plugin = new RenderPlugin();
            $render_plugin->setPluginName( mb_scrub($plugin_data['name']) );
            $render_plugin->setDescription( mb_scrub($plugin_data['description']) );
            $render_plugin->setCategory( mb_scrub($plugin_data['category']) );
            $render_plugin->setPluginClassName( mb_scrub($plugin_classname) );
            $render_plugin->setActive(true);

            if ( $plugin_data['render'] === false )    // Yaml parser reads 'false' as a boolean
                $render_plugin->setRender('false');
            else
                $render_plugin->setRender( mb_scrub($plugin_data['render']) );

            $plugin_type = strtolower( $plugin_data['plugin_type'] );
            if ( $plugin_type === 'datatype' )
                $render_plugin->setPluginType( RenderPlugin::DATATYPE_PLUGIN );
            else if ( $plugin_type === 'themeelement' )
                $render_plugin->setPluginType( RenderPlugin::THEME_ELEMENT_PLUGIN );
            else if ( $plugin_type === 'datafield' )
                $render_plugin->setPluginType( RenderPlugin::DATAFIELD_PLUGIN );
            else if ( $plugin_type === 'array' )
                $render_plugin->setPluginType( RenderPlugin::ARRAY_PLUGIN );

            if ( $plugin_data['override_fields'] === false )    // Yaml parser sets these to true/false values
                $render_plugin->setOverrideFields(false);
            else
                $render_plugin->setOverrideFields(true);

            if ( $plugin_data['override_field_reload'] === false )
                $render_plugin->setOverrideFieldReload(false);
            else
                $render_plugin->setOverrideFieldReload(true);

            if ( $plugin_data['override_child'] === false )
                $render_plugin->setOverrideChild(false);
            else
                $render_plugin->setOverrideChild(true);

            if ( $plugin_data['override_table_fields'] === false )
                $render_plugin->setOverrideTableFields(false);
            else
                $render_plugin->setOverrideTableFields(true);

            if ( $plugin_data['override_export'] === false )
                $render_plugin->setOverrideExport(false);
            else
                $render_plugin->setOverrideExport(true);

            if ( $plugin_data['override_search'] === false )
                $render_plugin->setOverrideSearch(false);
            else
                $render_plugin->setOverrideSearch(true);

            if ( $plugin_data['override_sort'] === false )
                $render_plugin->setOverrideSort(false);
            else
                $render_plugin->setOverrideSort(true);

            if ( $plugin_data['suppress_no_fields_note'] === false )
                $render_plugin->setSuppressNoFieldsNote(false);
            else
                $render_plugin->setSuppressNoFieldsNote(true);


            if ( !isset($plugin_data['required_theme_elements']) )
                $render_plugin->setRequiredThemeElements(0);
            else
                $render_plugin->setRequiredThemeElements( $plugin_data['required_theme_elements'] );

            $render_plugin->setCreatedBy($user);
            $render_plugin->setUpdatedBy($user);

            $em->persist($render_plugin);


            // ----------------------------------------
            // Create RenderPluginField entries from the config file data
            if ( is_array($plugin_data['required_fields']) ) {
                foreach ($plugin_data['required_fields'] as $identifier => $data) {
                    $rpf = new RenderPluginFields();
                    $rpf->setRenderPlugin($render_plugin);
                    $rpf->setFieldName( mb_scrub($data['name']) );
                    $rpf->setDescription( mb_scrub($data['description']) );
                    $rpf->setActive(true);

                    // Display order defaults to 0, but should match the config if it's defined
                    $rpf->setDisplayOrder(0);
                    if ( isset($data['display_order']) )
                        $rpf->setDisplayOrder( $data['display_order'] );

                    // These properties are false by default...
                    $rpf->setAutogenerateValues(false);
                    $rpf->setMustBeUnique(false);
                    $rpf->setNoUserEdits(false);
                    $rpf->setSingleUploadsOnly(false);
                    $rpf->setIsDerived(false);
                    $rpf->setIsOptional(false);

                    // ...but should be set to true if the config mentions them
                    if ( isset($data['properties']) ) {
                        if ( in_array('autogenerate_values', $data['properties']) )
                            $rpf->setAutogenerateValues(true);
                        if ( in_array('must_be_unique', $data['properties']) )
                            $rpf->setMustBeUnique(true);
                        if ( in_array('no_user_edits', $data['properties']) )
                            $rpf->setNoUserEdits(true);
                        if ( in_array('single_uploads_only', $data['properties']) )
                            $rpf->setSingleUploadsOnly(true);
                        if ( in_array('is_derived', $data['properties']) )
                            $rpf->setIsDerived(true);
                        if ( in_array('is_optional', $data['properties']) )
                            $rpf->setIsOptional(true);
                    }

                    // Convert the fieldtypes listed in the plugin's config file into fieldtype ids
                    $config_fieldtypes = explode('|', $data['type']);

                    $allowed_fieldtypes = array();
                    foreach ($config_fieldtypes as $config_fieldtype) {
                        $ft_id = $rpf_typeclasses[$config_fieldtype];

                        if ( isset($single_select_fieldtypes[$ft_id]) ) {
                            // Need to ensure "Single Radio" === "Single Select"
                            foreach ($single_select_fieldtypes as $id => $typename)
                                $allowed_fieldtypes[$id] = 1;
                        }
                        else if ( isset($multiple_select_fieldtypes[$ft_id]) ) {
                            // Need to ensure "Multiple Radio" === "Multiple Select"
                            foreach ($multiple_select_fieldtypes as $id => $typename)
                                $allowed_fieldtypes[$id] = 1;
                        }
                        else {
                            // All other entries in the config file only apply to a single fieldtype
                            $allowed_fieldtypes[$ft_id] = 1;
                        }
                    }
                    $allowed_fieldtypes = array_keys($allowed_fieldtypes);
                    $rpf->setAllowedFieldtypes( implode(',', $allowed_fieldtypes) );

                    $render_plugin->addRenderPluginField($rpf);

                    $rpf->setCreatedBy($user);
                    $rpf->setUpdatedBy($user);

                    $em->persist($rpf);
                }
            }


            // ----------------------------------------
            // Create RenderPluginOption entries from the config file data
            if ( is_array($plugin_data['config_options']) ) {
                foreach ($plugin_data['config_options'] as $option_key => $data) {
                    // TODO - rename to RenderPluginOptions
                    $rpo = new RenderPluginOptionsDef();
                    $rpo->setRenderPlugin($render_plugin);
                    $rpo->setName( mb_scrub($option_key) );
                    $rpo->setDisplayName( mb_scrub($data['name']) );
                    $rpo->setDescription( mb_scrub($data['description']) );

                    // The "default" key could have boolean values...
                    if ($data['default'] === true)
                        $rpo->setDefaultValue("true");
                    else if ($data['default'] === false)
                        $rpo->setDefaultValue("false");
                    else
                        $rpo->setDefaultValue( mb_scrub($data['default']) );

                    // The "choices" and "display_order" keys are optional
                    if ( isset($data['choices']) )
                        $rpo->setChoices( mb_scrub($data['choices']) );

                    // ...still need to provide a default of 0 so doctrine doesn't complain apparently
                    $rpo->setDisplayOrder(0);
                    if ( isset($data['display_order']) )
                        $rpo->setDisplayOrder( $data['display_order'] );

                    // ...same deal with the "uses_custom_render" and "uses_layout_settings" keys
                    //  needing to be set to false
                    $rpo->setUsesCustomRender(false);
                    if ( isset($data['uses_custom_render']) )
                        $rpo->setUsesCustomRender( $data['uses_custom_render'] );
                    $rpo->setUsesLayoutSettings(false);
                    if ( isset($data['uses_layout_settings']) )
                        $rpo->setUsesLayoutSettings( $data['uses_layout_settings'] );

                    $rpo->setCreatedBy($user);
                    $rpo->setUpdatedBy($user);

                    $em->persist($rpo);
                }
            }


            // ----------------------------------------
            // Create RenderPluginEvent entries from the config file data
            if ( is_array($plugin_data['registered_events']) ) {
                foreach ($plugin_data['registered_events'] as $event_name => $event_callable) {
                    $rpe = new RenderPluginEvents();
                    $rpe->setRenderPlugin($render_plugin);
                    $rpe->setEventName($event_name);
                    $rpe->setEventCallable($event_callable);

                    $rpe->setCreatedBy($user);
                    $rpe->setUpdatedBy($user);

                    $em->persist($rpe);
                }
            }


            // ----------------------------------------
            // Flush now that all entities are created
            $em->flush();

            // Don't need to update any cache entries
        }
        catch (\Exception $e) {
            $source = 0x6f1b3a98;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns an HTML table containing a list of problems that would be caused by updating the
     * given plugin.
     *
     * @param string $plugin_classname
     * @param Request $request
     *
     * @return Response
     */
    public function pluginproblemsAction($plugin_classname, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // Going to need fieldtype information to validate plugins
            $fieldtype_data = self::getFieldtypeData($em);

            // Ensure the requested plugin is already installed
            $query = $em->createQuery(
               'SELECT rp
                FROM ODRAdminBundle:RenderPlugin AS rp
                WHERE rp.pluginClassName = :plugin_classname'
            )->setParameters( array('plugin_classname' => $plugin_classname) );
            $results = $query->getResult();

            if ( count($results) == 0 )
                throw new ODRException('Unable to update a non-existant RenderPlugin');

            /** @var RenderPlugin $render_plugin */
            $render_plugin = $results[0];

            // Load the configuration file for the requested render plugin
            $available_plugins = self::getAvailablePlugins($fieldtype_data, $plugin_classname);
            if ( !isset($available_plugins[$plugin_classname]) )
                throw new ODRBadRequestException('Unable to update a non-existant RenderPlugin');

            // Ensure the database version of the render plugin in question differs from its config file
            // TODO - rename renderPluginOptionsDef to renderPluginOptions
            $query = $em->createQuery(
               'SELECT rp, rpe, rpf, rpi, rpo
                FROM ODRAdminBundle:RenderPlugin AS rp
                LEFT JOIN rp.renderPluginEvents AS rpe
                LEFT JOIN rp.renderPluginFields AS rpf
                LEFT JOIN rp.renderPluginInstance AS rpi
                LEFT JOIN rp.renderPluginOptionsDef AS rpo
                WHERE rp.id = :render_plugin_id'
            )->setParameters( array('render_plugin_id' => $render_plugin->getId()) );
            $results = $query->getArrayResult();

            $installed_plugins = array();
            foreach ($results as $result) {
                $plugin_classname = $result['pluginClassName'];

                $installed_plugins[$plugin_classname] = $result;
            }

            // Determine whether any of the installed render plugins differ from their config files
            $plugins_with_updates = self::getPluginDiff($fieldtype_data, $installed_plugins, $available_plugins);
            if ( !isset($plugins_with_updates['raw'][$plugin_classname]) )
                throw new ODRException('This RenderPlugin has no updates');

            // Determine whether any of the plugins that need updates are going to be problematic
            $plugins_with_updates = $plugins_with_updates['raw'];
            $plugin_update_problems = self::getPluginUpdateProblems($em, $plugins_with_updates);
            if ( !isset($plugin_update_problems[$plugin_classname]) )
                throw new ODRException('This RenderPlugin has no problems with updating');

            $plugin_update_problems = $plugin_update_problems[$plugin_classname];

            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Plugins:plugin_problems.html.twig',
                    array(
                        'plugin_update_problems' => $plugin_update_problems,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x84b5fe5d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Updates the database entries for a specified plugin to match the config file.
     * TODO - versioning?
     * TODO - disabling an active plugin?
     *
     * TODO - notifying user about changes?
     *
     * @param Request $request
     *
     * @return Response
     */
    public function updatepluginAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $conn = null;

        try {
            // Grab the data from the POST request
            $post = $request->request->all();
//print_r($post);  exit();

            if (!isset($post['plugin_classname']))
                throw new ODRBadRequestException('Invalid Form');

            $plugin_classname = $post['plugin_classname'];

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptionsDef');    // TODO - rename to RenderPluginOptions
            $repo_render_plugin_events = $em->getRepository('ODRAdminBundle:RenderPluginEvents');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // Going to need fieldtype information to validate plugins
            $fieldtype_data = self::getFieldtypeData($em);

            $rpf_typeclasses = $fieldtype_data['rpf_typeclasses'];
            $single_select_fieldtypes = $fieldtype_data['single_select_fieldtypes'];
            $multiple_select_fieldtypes = $fieldtype_data['multiple_select_fieldtypes'];

            // Ensure the requested plugin is already installed
            $query = $em->createQuery(
               'SELECT rp
                FROM ODRAdminBundle:RenderPlugin AS rp
                WHERE rp.pluginClassName = :plugin_classname'
            )->setParameters( array('plugin_classname' => $plugin_classname) );
            $results = $query->getResult();

            if ( count($results) == 0 )
                throw new ODRException('Unable to update a non-existant RenderPlugin');

            /** @var RenderPlugin $render_plugin */
            $render_plugin = $results[0];

            // Load the configuration file for the requested render plugin
            $available_plugins = self::getAvailablePlugins($fieldtype_data, $plugin_classname);
            if ( !isset($available_plugins[$plugin_classname]) )
                throw new ODRBadRequestException('Unable to update a non-existant RenderPlugin');
            $plugin_data = $available_plugins[$plugin_classname];


            // ----------------------------------------
            // Ensure the database version of the render plugin in question differs from its config file
            // TODO - rename renderPluginOptionsDef to renderPluginOptions
            $query = $em->createQuery(
               'SELECT rp, rpe, rpf, rpi, rpo
                FROM ODRAdminBundle:RenderPlugin AS rp
                LEFT JOIN rp.renderPluginEvents AS rpe
                LEFT JOIN rp.renderPluginFields AS rpf
                LEFT JOIN rp.renderPluginInstance AS rpi
                LEFT JOIN rp.renderPluginOptionsDef AS rpo
                WHERE rp.id = :render_plugin_id'
            )->setParameters( array('render_plugin_id' => $render_plugin->getId()) );
            $results = $query->getArrayResult();

            $installed_plugins = array();
            foreach ($results as $result) {
                $plugin_classname = $result['pluginClassName'];

                $installed_plugins[$plugin_classname] = $result;
            }

            // Only update a plugin if it actually has changes
            $updates = self::getPluginDiff($fieldtype_data, $installed_plugins, $available_plugins);
            $plugins_with_updates = $updates['raw'];
            if ( !isset($plugins_with_updates[$plugin_classname]) )
                throw new ODRException('This RenderPlugin has no updates');
//            $plugin_changes = $plugins_with_updates[$plugin_classname];


            // If there are any errors that would crop up when updating the plugin, refuse to continue
            // TODO - some of the updates could technically be performed automatically...
            $plugin_update_problems = self::getPluginUpdateProblems($em, $plugins_with_updates);
            if ( !empty($plugin_update_problems) )
                throw new ODRException('This RenderPlugin would create problems if it was updated');


            // ----------------------------------------
            // Update the existing RenderPlugin entry from the config file data
            $render_plugin->setPluginName( mb_scrub($plugin_data['name']) );
            $render_plugin->setDescription( mb_scrub($plugin_data['description']) );
            $render_plugin->setCategory( mb_scrub($plugin_data['category']) );
            $render_plugin->setPluginClassName( mb_scrub($plugin_classname) );
            $render_plugin->setActive(true);

            if ( $plugin_data['render'] === false )    // Yaml parser reads 'false' as a boolean
                $render_plugin->setRender('false');
            else
                $render_plugin->setRender( mb_scrub($plugin_data['render']) );

            $plugin_type = strtolower( $plugin_data['plugin_type'] );
            if ( $plugin_type === 'datatype' )
                $render_plugin->setPluginType( RenderPlugin::DATATYPE_PLUGIN );
            else if ( $plugin_type === 'themeelement' )
                $render_plugin->setPluginType( RenderPlugin::THEME_ELEMENT_PLUGIN );
            else if ( $plugin_type === 'datafield' )
                $render_plugin->setPluginType( RenderPlugin::DATAFIELD_PLUGIN );
            else if ( $plugin_type === 'array' )
                $render_plugin->setPluginType( RenderPlugin::ARRAY_PLUGIN );

            if ( $plugin_data['override_fields'] === false )
                $render_plugin->setOverrideFields(false);
            else
                $render_plugin->setOverrideFields(true);

            if ( $plugin_data['override_field_reload'] === false )
                $render_plugin->setOverrideFieldReload(false);
            else
                $render_plugin->setOverrideFieldReload(true);

            if ( $plugin_data['override_child'] === false )
                $render_plugin->setOverrideChild(false);
            else
                $render_plugin->setOverrideChild(true);

            if ( $plugin_data['override_table_fields'] === false )
                $render_plugin->setOverrideTableFields(false);
            else
                $render_plugin->setOverrideTableFields(true);

            if ( $plugin_data['override_export'] === false )
                $render_plugin->setOverrideExport(false);
            else
                $render_plugin->setOverrideExport(true);

            if ( $plugin_data['override_search'] === false )
                $render_plugin->setOverrideSearch(false);
            else
                $render_plugin->setOverrideSearch(true);

            if ( $plugin_data['override_sort'] === false )
                $render_plugin->setOverrideSort(false);
            else
                $render_plugin->setOverrideSort(true);

            if ( $plugin_data['suppress_no_fields_note'] === false )
                $render_plugin->setSuppressNoFieldsNote(false);
            else
                $render_plugin->setSuppressNoFieldsNote(true);


            if ( !isset($plugin_data['required_theme_elements']) )
                $render_plugin->setRequiredThemeElements(0);
            else
                $render_plugin->setRequiredThemeElements( $plugin_data['required_theme_elements'] );

            // Want the render plugin to always get marked as updated, even if it's just the
            //  fields/options/events getting changed
            $render_plugin->setUpdatedBy($user);

            $em->persist($render_plugin);


            // ----------------------------------------
            // Determine if any RenderPluginField entries in the database aren't in the config file
            $rpfs_to_delete = array();
            /** @var RenderPluginFields $rpf */
            foreach ($render_plugin->getRenderPluginFields() as $rpf) {
                $found = false;
                if ( is_array($plugin_data['required_fields']) ) {
                    foreach ($plugin_data['required_fields'] as $key => $tmp) {
                        // TODO - is there a way to have a better key than fieldName?
                        if ( $rpf->getFieldName() === $tmp['name'] )
                            $found = true;
                    }
                }

                if (!$found) {
                    // This field wasn't in the config file...mark it for deletion from the database
                    $rpfs_to_delete[] = $rpf->getId();
                }
            }

            // Create/update any RenderPluginField entries that aren't in the database
            if ( is_array($plugin_data['required_fields']) ) {
                foreach ($plugin_data['required_fields'] as $identifier => $data) {
                    $rpf = null;
                    $creating = false;

                    /** @var RenderPluginFields $rpf */
                    $rpf = $repo_render_plugin_fields->findOneBy(
                        array(
                            'fieldName' => $data['name'],
                            'renderPlugin' => $render_plugin->getId(),
                        )
                    );

                    if ( is_null($rpf) ) {
                        $rpf = new RenderPluginFields();
                        $creating = true;
                    }

                    $rpf->setRenderPlugin($render_plugin);
                    $rpf->setFieldName( mb_scrub($data['name']) );
                    $rpf->setDescription( mb_scrub($data['description']) );
                    $rpf->setActive(true);

                    // Display order defaults to 0, but should match the config if it's defined
                    $rpf->setDisplayOrder(0);
                    if ( isset($data['display_order']) )
                        $rpf->setDisplayOrder( $data['display_order'] );

                    // These properties are false by default...
                    $rpf->setAutogenerateValues(false);
                    $rpf->setMustBeUnique(false);
                    $rpf->setNoUserEdits(false);
                    $rpf->setSingleUploadsOnly(false);
                    $rpf->setIsDerived(false);
                    $rpf->setIsOptional(false);

                    // ...but should be set to true if the config mentions them
                    if ( isset($data['properties']) ) {
                        if ( in_array('autogenerate_values', $data['properties']) )
                            $rpf->setAutogenerateValues(true);
                        if ( in_array('must_be_unique', $data['properties']) )
                            $rpf->setMustBeUnique(true);
                        if ( in_array('no_user_edits', $data['properties']) )
                            $rpf->setNoUserEdits(true);
                        if ( in_array('single_uploads_only', $data['properties']) )
                            $rpf->setSingleUploadsOnly(true);
                        if ( in_array('is_derived', $data['properties']) )
                            $rpf->setIsDerived(true);
                        if ( in_array('is_optional', $data['properties']) )
                            $rpf->setIsOptional(true);
                    }

                    // Convert the fieldtypes listed in the plugin's config file into fieldtype ids
                    $config_fieldtypes = explode('|', $data['type']);

                    $allowed_fieldtypes = array();
                    foreach ($config_fieldtypes as $config_fieldtype) {
                        $ft_id = $rpf_typeclasses[$config_fieldtype];

                        if ( isset($single_select_fieldtypes[$ft_id]) ) {
                            // Need to ensure "Single Radio" === "Single Select"
                            foreach ($single_select_fieldtypes as $id => $typename)
                                $allowed_fieldtypes[$id] = 1;
                        }
                        else if ( isset($multiple_select_fieldtypes[$ft_id]) ) {
                            // Need to ensure "Multiple Radio" === "Multiple Select"
                            foreach ($multiple_select_fieldtypes as $id => $typename)
                                $allowed_fieldtypes[$id] = 1;
                        }
                        else {
                            // All other entries in the config file only apply to a single fieldtype
                            $allowed_fieldtypes[$ft_id] = 1;
                        }
                    }
                    $allowed_fieldtypes = array_keys($allowed_fieldtypes);
                    $rpf->setAllowedFieldtypes( implode(',', $allowed_fieldtypes) );

                    // TODO - how to handle changes to allowed_fieldtypes (and other options) when the plugin is already in use?
                    // TODO - automatically forcing changes could be dangerous...
                    // TODO    - fieldtypes aren't guaranteed to be transferrable, and it's trivially easy to lose data if done unexpectedly
                    // TODO    - a field that is (forced to be) unique but wasn't originally intended to be unique will interfere with edits across the entire datatype
                    // TODO    - changing the "allow multiple uploads" or "prevent user edits" options will also be irritating if it goes against the user's original intent

                    // TODO - also, lack of versioning and difficulty of reverting amplify any problems encountered
                    // TODO - ...maybe some kind of problem locator utility?  and/or a modal to list the changes being made

                    if ($creating) {
                        $rpf->setCreatedBy($user);
                        $render_plugin->addRenderPluginField($rpf);
                    }

                    $rpf->setUpdatedBy($user);

                    $em->persist($rpf);
                }
            }


            // ----------------------------------------
            // Determine if any RenderPluginOptions entries in the database aren't in the config file
            // TODO - rename to RenderPluginOptions
            $options_to_delete = array();
            /** @var RenderPluginOptionsDef $rpo */
            foreach ($render_plugin->getRenderPluginOptionsDef() as $rpo) {
                $found = false;
                if ( is_array($plugin_data['config_options']) ) {
                    foreach ($plugin_data['config_options'] as $key => $tmp) {
                        // TODO - is there a way to have a better key than optionName?
                        if ( $rpo->getName() === $key )
                            $found = true;
                    }
                }

                if (!$found) {
                    // This option wasn't in the config file...mark it for deletion from the database
                    $options_to_delete[] = $rpo->getId();
                }
            }

            // Create/update any RenderPluginOptions entries that aren't in the database
            if ( is_array($plugin_data['config_options']) ) {
                foreach ($plugin_data['config_options'] as $option_key => $data) {
                    $rpo = null;
                    $creating = false;

                    /** @var RenderPluginOptionsDef $rpo */
                    $rpo = $repo_render_plugin_options->findOneBy(
                        array(
                            'name' => $option_key,
                            'renderPlugin' => $render_plugin->getId(),
                        )
                    );

                    if ( is_null($rpo) ) {
                        $rpo = new RenderPluginOptionsDef();
                        $creating = true;
                    }


                    $rpo->setRenderPlugin($render_plugin);
                    $rpo->setName( mb_scrub($option_key) );
                    $rpo->setDisplayName( mb_scrub($data['name']) );
                    $rpo->setDescription( mb_scrub($data['description']) );

                    // The "default" key could have boolean values...
                    if ($data['default'] === true)
                        $rpo->setDefaultValue("true");
                    else if ($data['default'] === false)
                        $rpo->setDefaultValue("false");
                    else
                        $rpo->setDefaultValue( mb_scrub($data['default']) );

                    // The "choices" and "display_order" keys are optional
                    if ( isset($data['choices']) )
                        $rpo->setChoices( mb_scrub($data['choices']) );

                    // ...still need to provide a default of 0 so doctrine doesn't complain apparently
                    $rpo->setDisplayOrder(0);
                    if ( isset($data['display_order']) )
                        $rpo->setDisplayOrder( $data['display_order'] );

                    // ...same deal with the "uses_custom_render" and "uses_layout_settings" keys
                    //  needing to be set to false
                    $rpo->setUsesCustomRender(false);
                    if ( isset($data['uses_custom_render']) )
                        $rpo->setUsesCustomRender( $data['uses_custom_render'] );

                    $rpo->setUsesLayoutSettings(false);
                    if ( isset($data['uses_layout_settings']) )
                        $rpo->setUsesLayoutSettings( $data['uses_layout_settings'] );

                    if ($creating) {
                        $rpo->setCreatedBy($user);
                        $render_plugin->addRenderPluginOptionsDef($rpo);
                    }

                    $rpo->setUpdatedBy($user);
                    $em->persist($rpo);

                    // If this is a new option...
                    if ($creating) {
                        // ...then unfortunately need to ensure the defaults mapping values exist,
                        //  otherwise the plugin will likely throw errors

                        // TODO - if doing it here, would need to locate all active RenderPluginInstance entities for this plugin...
                        // TODO - ...then create a RenderPluginOptionMap for the current option for all the rpi entities

                        // TODO - later on, need to run the PluginOptionsChangedEvent on all the RenderPluginInstances that were modified...
                        // TODO - ...can't do it here because the database needs to be flushed first for that to work properly
                        // TODO - might also need to do that when an option is deleted


                        // TODO - bigger problem is that this only fixes half the issue...the plugin will likely throw errors if an update adds required fields...
                        // TODO - ...and RenderPluginFields can't have defaults, so the datatype admins still have to manually update

                        // TODO - so is it worthwhile to automatically assign default values to new options, even though new fields can't be fixed in a similar fashion?
                        // TODO - ...or is it better to make some kind of "active issues" todo-list type of page for super admins to see issues at a glance?
                    }
                }
            }


            // ----------------------------------------
            // Determine if any RenderPluginEvents entries in the database aren't in the config file
            $events_to_delete = array();
            /** @var RenderPluginEvents $rpe */
            foreach ($render_plugin->getRenderPluginEvents() as $rpe) {
                $found = false;
                if ( is_array($plugin_data['registered_events']) ) {
                    foreach ($plugin_data['registered_events'] as $eventName => $eventCallable) {
                        // TODO - is there a way to have a better key than eventName?
                        if ( $rpe->getEventName() === $eventName )
                            $found = true;
                    }
                }

                if (!$found) {
                    // This event wasn't in the config file...mark it for deletion from the database
                    $events_to_delete[] = $rpe->getId();
                }
            }

            // Create/update any RenderPluginEvent entries that aren't in the database
            if ( is_array($plugin_data['registered_events']) ) {
                foreach ($plugin_data['registered_events'] as $eventName => $eventCallable) {
                    $rpe = null;
                    $creating = false;

                    /** @var RenderPluginEvents $rpe */
                    $rpe = $repo_render_plugin_events->findOneBy(
                        array(
                            'eventName' => $eventName,
                            'renderPlugin' => $render_plugin->getId(),
                        )
                    );

                    if ( is_null($rpe) ) {
                        $rpe = new RenderPluginEvents();
                        $creating = true;
                    }

                    $rpe->setRenderPlugin($render_plugin);
                    $rpe->setEventName($eventName);
                    $rpe->setEventCallable($eventCallable);

                    if ($creating) {
                        $rpe->setCreatedBy($user);
                        $render_plugin->addRenderPluginEvent($rpe);
                    }

                    $rpe->setUpdatedBy($user);

                    $em->persist($rpe);
                }
            }


            // ----------------------------------------
            // Wrap all the field/option/event deletion in a transaction...
            $conn = $em->getConnection();
            $conn->beginTransaction();

            if ( count($rpfs_to_delete) > 0 ) {
                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:RenderPluginFields rpf
                    SET rpf.deletedAt = :now
                    WHERE rpf IN (:render_plugin_fields)
                    AND rpf.deletedAt IS NULL'
                )->setParameters(array('now' => new \DateTime(), 'render_plugin_fields' => $rpfs_to_delete));
                $rowsAffected = $query->execute();

                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:RenderPluginMap rpm
                    SET rpm.deletedAt = :now
                    WHERE rpm.renderPluginFields IN (:render_plugin_fields)
                    AND rpm.deletedAt IS NULL'
                )->setParameters(array('now' => new \DateTime(), 'render_plugin_fields' => $rpfs_to_delete));
                $rowsAffected = $query->execute();
            }

            if ( count($options_to_delete) > 0 ) {
                // TODO - rename to RenderPluginOptions
                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:RenderPluginOptionsDef rpo
                    SET rpo.deletedAt = :now
                    WHERE rpo IN (:render_plugin_options)
                    AND rpo.deletedAt IS NULL'
                )->setParameters(array('now' => new \DateTime(), 'render_plugin_options' => $options_to_delete));
                $rowsAffected = $query->execute();

                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:RenderPluginOptionsMap rpom
                    SET rpom.deletedAt = :now
                    WHERE rpom.renderPluginOptionsDef IN (:render_plugin_options)
                    AND rpom.deletedAt IS NULL'
                )->setParameters(array('now' => new \DateTime(), 'render_plugin_options' => $options_to_delete));
                $rowsAffected = $query->execute();

                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:RenderPluginThemeOptionsMap rptom
                    SET rptom.deletedAt = :now
                    WHERE rptom.renderPluginOptionsDef IN (:render_plugin_options)
                    AND rptom.deletedAt IS NULL'
                )->setParameters(array('now' => new \DateTime(), 'render_plugin_options' => $options_to_delete));
                $rowsAffected = $query->execute();
            }

            if ( count($events_to_delete) > 0 ) {
                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:RenderPluginEvents rpe
                    SET rpe.deletedAt = :now
                    WHERE rpe IN (:render_plugin_events)
                    AND rpe.deletedAt IS NULL'
                )->setParameters(array('now' => new \DateTime(), 'render_plugin_events' => $events_to_delete));
                $rowsAffected = $query->execute();
            }


            // ----------------------------------------
            // No error encountered, commit changes
            $conn->commit();

            // Only flush after the transaction is finished
            $em->flush();


            // ----------------------------------------
            // Delete any cached entries related to the affected render plugin
            $query = $em->createQuery(
               'SELECT partial rpi.{id}, partial dt.{id}, partial gp_dt.{id},
                    partial df.{id}, partial df_dt.{id}, partial df_gp_dt.{id}
                FROM ODRAdminBundle:RenderPluginInstance AS rpi
                LEFT JOIN rpi.dataType AS dt
                LEFT JOIN dt.grandparent AS gp_dt
                LEFT JOIN rpi.dataField AS df
                LEFT JOIN df.dataType AS df_dt
                LEFT JOIN df_dt.grandparent AS df_gp_dt
                WHERE rpi.renderPlugin = :render_plugin_id
                AND rpi.deletedAt IS NULL AND dt.deletedAt IS NULL AND gp_dt.deletedAt IS NULL
                AND df.deletedAt IS NULL AND df_dt.deletedAt IS NULL AND df_gp_dt.deletedAt IS NULL'
            )->setParameters( array('render_plugin_id' => $render_plugin->getId()) );
            $results = $query->getArrayResult();

            $datatype_ids = array();
            if ( is_array($results) ) {
                foreach ($results as $result) {
                    // Store the grandparent datatype id, if it exists...
                    if ( isset($result['dataType']) )
                        $datatype_ids[] = $result['dataType']['grandparent']['id'];

                    // Store the grandparent datatype id of the datafield, if it exists...
                    if ( isset($result['dataField']['dataType']) )
                        $datatype_ids[] = $result['dataField']['dataType']['grandparent']['id'];
                }
            }

            $datatype_ids = array_unique($datatype_ids);
            foreach ($datatype_ids as $dt_id) {
                if ($dt_id !== '')
                    $cache_service->delete('cached_datatype_'.$dt_id);
            }
        }
        catch (\Exception $e) {
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0xd7b0e453;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds and returns a list of available Render Plugins for a Datatype or a Datafield.
     * ThemeElement plugins are treated as a Datatype plugin for the purposes of the dialog.
     *
     * @param integer $datatype_id  The id of the Datatype that might be having its RenderPlugin changed
     * @param integer $datafield_id The id of the Datafield that might be having its RenderPlugin changed
     * @param Request $request
     *
     * @return Response
     */
    public function renderplugindialogAction($datatype_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // Grab necessary objects
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');


            // Need to specify either a datafield or a datatype...
            $datatype = null;
            $datafield = null;
            if ($datafield_id == 0 && $datatype_id == 0)
                throw new ODRBadRequestException();


            if ($datafield_id == 0) {
                // If datafield id isn't defined, this is a render plugin for a datatype/themeElement/array

                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                if ( is_null($datatype) )
                    throw new ODRNotFoundException('Datatype');
            }
            else {
                // ...otherwise, this is a render plugin for a datafield

                /** @var DataFields $datafield */
                $datafield = $repo_datafield->find($datafield_id);
                if ( is_null($datafield) )
                    throw new ODRNotFoundException('Datafield');

                $datatype = $datafield->getDataType();
                if ( $datatype->getDeletedAt() != null )
                    throw new ODRNotFoundException('Datatype');
            }

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
//            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
//                throw new ODRForbiddenException();
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            $is_datatype_admin = $permissions_service->isDatatypeAdmin($user, $datatype);
            // --------------------


            $render_plugins = null;
            $render_plugin_instances = null;

            if ($datafield_id == 0) {
                // If datafield id isn't defined, then load all available datatype/themeElement/array
                //  render plugins...
                $query = $em->createQuery(
                   'SELECT rp
                    FROM ODRAdminBundle:RenderPlugin AS rp
                    WHERE rp.plugin_type IN (:plugin_types)
                    AND rp.deletedAt IS NULL
                    ORDER BY rp.category, rp.pluginName'
                )->setParameters(
                    array(
                        'plugin_types' => array(
                            RenderPlugin::DATATYPE_PLUGIN,
                            RenderPlugin::THEME_ELEMENT_PLUGIN,
                            RenderPlugin::ARRAY_PLUGIN,
                        )
                    )
                );
                /** @var RenderPlugin[] $render_plugins */
                $render_plugins = $query->getResult();

                // Load the currently active plugin instances for this datatype
                /** @var RenderPluginInstance[]|null $render_plugin_instances */
                $render_plugin_instances = $repo_render_plugin_instance->findBy(
                    array(
                        'dataType' => $datatype
                    )
                );
            }
            else {
                // Otherwise, this is a request for a datafield...load all available datafield
                //  render plugins
                $query = $em->createQuery(
                   'SELECT rp
                    FROM ODRAdminBundle:RenderPlugin AS rp
                    WHERE rp.plugin_type IN (:plugin_types)
                    AND rp.deletedAt IS NULL
                    ORDER BY rp.category, rp.pluginName'
                )->setParameters(
                    array(
                        'plugin_types' => array(
                            RenderPlugin::DATAFIELD_PLUGIN,
                        )
                    )
                );
                /** @var RenderPlugin[] $render_plugins */
                $render_plugins = $query->getResult();

                // Load the currently active plugin instances for this datafield
                /** @var RenderPluginInstance[]|null $render_plugin_instances */
                $render_plugin_instances = $repo_render_plugin_instance->findBy(
                    array(
                        'dataField' => $datafield
                    )
                );
            }


            // ----------------------------------------
            // Cleaner to determine these variables in here, instead of in twig
            $attached_render_plugins = array();
            $plugin_to_load = null;
            foreach ($render_plugin_instances as $rpi) {
                $rp_id = $rpi->getRenderPlugin()->getId();
                $attached_render_plugins[$rp_id] = 1;

                // If this datatype/datafield is using a plugin...
                if ( is_null($plugin_to_load) ) {
                    // ...then get the javascript load its description, fields, and options after
                    //  the dialog opens
                    $plugin_to_load = $rp_id;
                }
                else if ( $rpi->getRenderPlugin()->getRender() !== 'false' ) {
                    // ...if the datatype/datafield is using more than one plugin, then preferentially
                    //  load the data for the plugin that actually renders something
                    $plugin_to_load = $rp_id;

                    // Because plugins have few limits on changing the page's HTML, they really don't
                    //  play nice with each other...typically there will only be one plugin per
                    //  datatype/datafield that actually does this.  In the very rare situations that
                    //  they can work together, then it doesn't really matter which one is selected
                    //  for the purposes of this dialog.

                    // ThemeElement plugins receive their own unique location to render stuff in,
                    //  which prevents them from clobbering datatype/datafield plugins.

                    // Array plugins don't render anything, so they can always get executed if needed
                }
            }


            // ----------------------------------------
            // The render plugin may require certain fields to be unique or to only allow single
            //  uploads...unfortunately, determining whether datafields currently match either of
            //  these criteria is too time-consuming on datatypes with lots of eligible fields...

            // So, have to settle for displaying warnings on fields which don't currently fulfill
            //  the requirements
            $current_unique_fields = array();
            $current_single_upload_fields = array();

            if ( $datafield_id == 0 ) {
                // Need to check each datafield in this datatype...
                foreach ($datatype->getDataFields() as $df) {
                    /** @var DataFields $df */
                    if ( $df->getIsUnique() )
                        $current_unique_fields[] = $df->getId();

                    $typeclass = $df->getFieldType()->getTypeClass();
                    if ( $typeclass === 'File' || $typeclass === 'Image' ) {
                        if ( !$df->getAllowMultipleUploads() )
                            $current_single_upload_fields[] = $df->getId();
                    }
                }
            }
            else {
                // Only need to check this datafield...
                if ( $datafield->getIsUnique() )
                    $current_unique_fields[] = $datafield->getId();

                $typeclass = $datafield->getFieldType()->getTypeClass();
                if ( $typeclass === 'File' || $typeclass === 'Image' ) {
                    if ( !$datafield->getAllowMultipleUploads() )
                        $current_single_upload_fields[] = $datafield->getId();
                }
            }


            // ----------------------------------------
            // Get Templating Object
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Plugins:plugin_settings_dialog_form.html.twig',
                    array(
                        'local_datatype' => $datatype,
                        'local_datafield' => $datafield,
                        'is_datatype_admin' => $is_datatype_admin,

                        'all_render_plugins' => $render_plugins,
                        'plugin_to_load' => $plugin_to_load,

                        'attached_render_plugins' => $attached_render_plugins,
                        'render_plugin_instances' => $render_plugin_instances,

                        'current_unique_fields' => $current_unique_fields,
                        'current_single_upload_fields' => $current_single_upload_fields,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x9a07165b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads and renders required DataFields and plugin options for the selected Render Plugin.
     * TODO - display events?
     *
     * @param integer|null $datatype_id  The id of the Datatype that might be having its RenderPlugin changed
     * @param integer|null $datafield_id The id of the Datafield that might be having its RenderPlugin changed
     * @param integer $render_plugin_id  The database id of the RenderPlugin to look up.
     * @param Request $request
     *
     * @return Response
     */
    public function renderpluginsettingsAction($datatype_id, $datafield_id, $render_plugin_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // Ensure the relevant entities exist
            /** @var DataType $datatype */
            $datatype = null;
            /** @var DataFields|null $datafield */
            $datafield = null;
            /** @var DataFields[]|null $all_datafields */
            $all_datafields = null; // of datatype

            if ($datafield_id == 0) {
                // This is a render plugin for a datatype/themeElement/array
                $datatype = $repo_datatype->find($datatype_id);
                if ( is_null($datatype) )
                    throw new ODRNotFoundException('Datatype');

                $all_datafields = $repo_datafield->findBy(array('dataType' => $datatype));
            }
            else {
                // This is a render plugin for a datafield
                $datafield = $repo_datafield->find($datafield_id);
                if ( is_null($datafield) )
                    throw new ODRNotFoundException('Datafield');

                $datatype = $datafield->getDataType();
                if ( !is_null($datatype->getDeletedAt()) )
                    throw new ODRNotFoundException('Datatype');
            }

            /** @var RenderPlugin $target_render_plugin */
            $target_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find($render_plugin_id);
            if ( is_null($target_render_plugin) )
                throw new ODRNotFoundException('RenderPlugin');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
//            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
//                throw new ODRForbiddenException();
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            $is_datatype_admin = $permissions_service->isDatatypeAdmin($user, $datatype);
            // --------------------


            // ----------------------------------------
            // Going to need fieldtype information to validate plugins
            $fieldtype_data = self::getFieldtypeData($em);

            $rpf_typenames = $fieldtype_data['rpf_typenames'];
            $single_select_fieldtypes = $fieldtype_data['single_select_fieldtypes'];
            $multiple_select_fieldtypes = $fieldtype_data['multiple_select_fieldtypes'];


            // ----------------------------------------
            // Load the description and the available fields/options of the requested RenderPlugin
            // TODO - change renderPluginOptionsDef to RenderPluginOptions?
            $query = $em->createQuery(
               'SELECT rp, rpf, rpo
                FROM ODRAdminBundle:RenderPlugin rp
                LEFT JOIN rp.renderPluginFields rpf
                LEFT JOIN rp.renderPluginOptionsDef rpo
                WHERE rp.id = :render_plugin_id
                AND rp.deletedAt IS NULL AND rpf.deletedAt IS NULL AND rpo.deletedAt IS NULL'
            )->setParameters( array('render_plugin_id' => $target_render_plugin->getId()) );
            $results = $query->getArrayResult();

            // Only going to be one result in here
            $render_plugin = $results[0];

            // Rekey the RenderPluginFields and the RenderPluginOptions arrays to use their respective
            //  ids, so it'll be easier for the RenderPluginInstance to look up values when needed
            $tmp = array();
            foreach ($render_plugin['renderPluginFields'] as $num => $rpf)
                $tmp[ $rpf['id'] ] = $rpf;
            $render_plugin['renderPluginFields'] = $tmp;

            $tmp = array();
            foreach ($render_plugin['renderPluginOptionsDef'] as $num => $rpo)
                $tmp[ $rpo['id'] ] = $rpo;
            unset( $render_plugin['renderPluginOptionsDef'] );
            $render_plugin['renderPluginOptions'] = $tmp;


            // Make a list of which fieldtypes each renderPluginField entry is allowed to have
            $allowed_fieldtypes = array();
            foreach ($render_plugin['renderPluginFields'] as $rpf_id => $rpf) {
                $config_fieldtypes = explode(',', $rpf['allowedFieldtypes']);

                $tmp = array();
                foreach ($config_fieldtypes as $config_fieldtype) {
                    if ( isset($single_select_fieldtypes[$config_fieldtype]) ) {
                        // Need to ensure "Single Radio" === "Single Select"
                        foreach ($single_select_fieldtypes as $ft_id => $typename)
                            $tmp[$ft_id] = 1;
                    }
                    else if ( isset($multiple_select_fieldtypes[$config_fieldtype]) ) {
                        // Need to ensure "Multiple Radio" === "Multiple Select"
                        foreach ($multiple_select_fieldtypes as $ft_id => $typename)
                            $tmp[$ft_id] = 1;
                    }
                    else {
                        // All other entries in the config file only apply to a single fieldtype
                        $tmp[$config_fieldtype] = 1;
                    }
                }
                $allowed_fieldtypes[$rpf_id] = array_keys($tmp);
            }

            // Convert the renderPluginOption choices from a string into an array
            foreach ($render_plugin['renderPluginOptions'] as $rpo_id => $rpo) {
                $choices = array();
                if ( isset($rpo['choices']) ) {
                    if ( strpos($rpo['choices'], ',') === false ) {
                        // This is a set of "simple" choices...the display values work as HTML keys
                        $options = explode('||', $rpo['choices']);
                        foreach ($options as $option)
                            $choices[$option] = $option;
                    }
                    else {
                        // ...most choices have display values that don't work as HTML keys though
                        $options = explode(',', $rpo['choices']);
                        foreach ($options as $option) {
                            $divider = strpos($option, '||');
                            $key = substr($option, 0, $divider);
                            $value = substr($option, $divider+2);

                            $choices[$key] = $value;
                        }
                    }
                }
                $render_plugin['renderPluginOptions'][$rpo_id]['choices'] = $choices;
            }

            // Order both the fields and the options by their relevant display_order property...it's
            //  not always defined in either case though
            uasort($render_plugin['renderPluginFields'], function($a, $b) {
                if ( $a['display_order'] <= $b['display_order'] )
                    return -1;
                else
                    return 1;
            });

            uasort($render_plugin['renderPluginOptions'], function($a, $b) {
                if ( $a['display_order'] <= $b['display_order'] )
                    return -1;
                else
                    return 1;
            });


            // ----------------------------------------
            // The previous block loaded the available fields/options for a plugin...now attempt to
            //  load the most recent selections for the fields/options for this plugin instance

            // If the user selected a plugin that this datafield/datatype has never used, then there
            //  will be no results
            // If they selected a plugin that is currently being used by this datafield/datatype, then
            //  this will provide the current selections...and due to disabling softdeleteable, this
            //  will also get the most recent set of selections if they selected a plugin that was
            //  used in the past
            $em->getFilters()->disable('softdeleteable');

            $query = null;
            if ( is_null($datafield) ) {
                $query = $em->createQuery(
                   'SELECT rpi, rp
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    JOIN rpi.renderPlugin AS rp
                    WHERE rpi.dataType = :dataType
                    ORDER BY rpi.id ASC'
                )->setParameters(
                    array(
                        'dataType' => $datatype
                    )
                );
            }
            else {
                $query = $em->createQuery(
                   'SELECT rpi, rp
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    JOIN rpi.renderPlugin AS rp
                    WHERE rpi.dataField = :dataField
                    ORDER BY rpi.id ASC'
                )->setParameters(
                    array(
                        'dataField' => $datafield
                    )
                );
            }
            $all_render_plugin_instances = $query->getArrayResult();

            $current_render_plugin_instance = null;
            if ( !empty($all_render_plugin_instances) ) {
                // The most recent RenderPluginInstance will be the last one in the results array
                foreach ($all_render_plugin_instances as $result) {
                    if ( $result['renderPlugin']['id'] === $target_render_plugin->getId() )
                        $current_render_plugin_instance = $result;
                }
            }
            // NOTE: "current" doesn't necessarily imply the plugin is currently attached to the
            //  datatype/datafield...it could refer to the "most recent" instance of the plugin
            //  that was attached

            // If the datatype/datafield has (or had) an instance for this render plugin, then load
            //  the renderPluginFieldsMap and renderPluginOptionsMap entries for this instance
            $render_plugin_instance = null;
            if ( !is_null($current_render_plugin_instance) ) {
                // TODO - change renderPluginOptionsDef to RenderPluginOptions?
                $query = $em->createQuery(
                   'SELECT rpi, rp, rpm, partial rpf.{id}, partial rpm_df.{id}, rpom, partial rpo.{id}, partial trpi.{id}
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    LEFT JOIN rpi.renderPlugin rp
                    LEFT JOIN rpi.renderPluginMap rpm
                    LEFT JOIN rpm.renderPluginFields rpf
                    LEFT JOIN rpm.dataField rpm_df
                    LEFT JOIN rpi.renderPluginOptionsMap rpom
                    LEFT JOIN rpom.renderPluginOptionsDef rpo
                    LEFT JOIN rpi.themeRenderPluginInstance trpi
                    WHERE rpi.id = :render_plugin_instance_id'
                )->setParameters(
                    array(
                        'render_plugin_instance_id' => $current_render_plugin_instance['id']
                    )
                );
                $results = $query->getArrayResult();

                // Should only have one result
                $render_plugin_instance = $results[0];

                // Rekey the RenderPluginOptionsMap array to use the RenderPluginOptions id, so it's
                //  easier to lookup values if needed
                $tmp = array();
                foreach ($render_plugin_instance['renderPluginOptionsMap'] as $num => $rpom) {
                    $rpo_id = $rpom['renderPluginOptionsDef']['id'];
                    unset( $rpom['renderPluginOptionsDef'] );
                    // The values of the more recent RenderPluginOptionMap entries will end up
                    //  overwriting the older RenderPluginOptionMap entries
                    $tmp[$rpo_id] = $rpom;
                }
                $render_plugin_instance['renderPluginOptionsMap'] = $tmp;
            }

            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter


            // ----------------------------------------
            // If this is a datafield plugin, then should check whether it can run on the current
            //  datafield's fieldtype
            $is_illegal_render_plugin = false;
            $illegal_render_plugin_message = '';

            if ( !is_null($datafield) ) {
                // Need to verify whether the datafield's fieldtype is allowed by the target render
                //  plugin...
                $ft_id = $datafield->getFieldType()->getId();
                // There should only be one renderPluginField in the renderPlugin array...
                foreach ($render_plugin['renderPluginFields'] as $rpf_id => $rpf) {
                    if ( !in_array($ft_id, $allowed_fieldtypes[$rpf_id]) ) {
                        $is_illegal_render_plugin = true;

                        $illegal_render_plugin_message = self::getAllowedFieldtypesString($allowed_fieldtypes, $rpf_typenames);
                        $illegal_render_plugin_message .= '  It is not compatible with "'.$datafield->getFieldType()->getTypeName().'" Datafields.';
                    }
                }
            }

            // Don't override the previous check for datafield's fieldtype
            if ( $illegal_render_plugin_message === '' )
                $illegal_render_plugin_message = self::validatePluginCompatibility($em, $datatype, $datafield, $target_render_plugin);

            if ( $illegal_render_plugin_message !== '' )
                $is_illegal_render_plugin = true;


            // ----------------------------------------
            // Determine whether any of the options in the render plugin need custom HTML...
            $uses_custom_render = false;
            foreach ($render_plugin['renderPluginOptions'] as $rpo_id => $rpo) {
                if ( $rpo['uses_custom_render'] ) {
                    $uses_custom_render = true;
                    break;
                }
            }

            $custom_render_plugin_options_html = array();
            if ( $uses_custom_render ) {
                // If any of them do, then call the relevant function defined in the render plugin
                /** @var PluginSettingsDialogOverrideInterface $plugin */
                $plugin = $this->container->get( $target_render_plugin->getPluginClassName() );

                $custom_render_plugin_options_html =
                    $plugin->getRenderPluginOptionsOverride(
                        $user,
                        $is_datatype_admin,
                        $target_render_plugin,
                        $datatype,
                        $datafield,
                        $render_plugin_instance
                    );
            }


            // ----------------------------------------
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Plugins:plugin_settings_dialog_form_data.html.twig',
                    array(
                        'rpf_typenames' => $rpf_typenames,

                        'local_datatype' => $datatype,
                        'local_datafield' => $datafield,
                        'datafields' => $all_datafields,
                        'is_datatype_admin' => $is_datatype_admin,

                        'render_plugin' => $render_plugin,
                        'allowed_fieldtypes' => $allowed_fieldtypes,
                        'render_plugin_instance' => $render_plugin_instance,

                        'is_illegal_render_plugin' => $is_illegal_render_plugin,
                        'illegal_render_plugin_message' => $illegal_render_plugin_message,

                        'custom_render_plugin_options_html' => $custom_render_plugin_options_html,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x001bf2cc;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Ensures the given target render plugin doesn't conflict with any other plugins currently
     * attached to this datafield/datatype.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataType|null $datatype
     * @param DataFields|null $datafield
     * @param RenderPlugin $target_render_plugin
     *
     * @return string empty string when no conflicts, an error string otherwise
     */
    private function validatePluginCompatibility($em, $datatype, $datafield, $target_render_plugin)
    {
        $query = null;
        if ( is_null($datafield) ) {
            $query = $em->createQuery(
                'SELECT rpi, rp
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    JOIN rpi.renderPlugin AS rp
                    WHERE rpi.dataType = :dataType
                    ORDER BY rpi.id ASC'
            )->setParameters(
                array(
                    'dataType' => $datatype
                )
            );
        }
        else {
            $query = $em->createQuery(
                'SELECT rpi, rp
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    JOIN rpi.renderPlugin AS rp
                    WHERE rpi.dataField = :dataField
                    ORDER BY rpi.id ASC'
            )->setParameters(
                array(
                    'dataField' => $datafield
                )
            );
        }
        $all_render_plugin_instances = $query->getArrayResult();

        // If there's no other plugins attached to the datatype/datafield, then there's nothing to
        //  conflict with
        if ( empty($all_render_plugin_instances) )
            return '';

        // If the target render plugin is already attached, then there wasn't a problem before
        foreach ($all_render_plugin_instances as $num => $rpi) {
            if ( $rpi['renderPlugin']['id'] === $target_render_plugin->getId() )
                return '';
        }

        // The rest of the compatibility checks depend on the type of plugin...
        if ( !is_null($datafield) ) {
            // Datafield plugins currently can't have multiple values that aren't "false" for the
            //  "render" parameter
            $prev_rp = null;
            foreach ($all_render_plugin_instances as $num => $rpi) {
                $rp = $rpi['renderPlugin'];
                $val = $rp['render'];
                if ( $val === false ) {
                    // Ignore plugins that don't actually "render" anything...though there shouldn't
                    //  really be one of these, since a datafield plugin that doesn't render anything
                    //  is effectively useless...
                    continue;
                }
                else if ( is_null($prev_rp) ) {
                    // If this is the first attached plugin that renders something, make a note of it
                    $prev_rp = $rp;
                }
                else if ( $prev_rp['render'] !== $val ) {
                    // If this is the second attached plugin that renders something, then something
                    //  has gone wrong?
                    return 'Datafield '.$datafield->getId().' is already in an invalid state due to simultaneously using the "'.$prev_rp['pluginName'].'" and the "'.$rp['pluginName'].'" Render Plugins??';
                }
            }

            // Now that the currently attached render plugins have been checked...
            if ( is_null($prev_rp) || $target_render_plugin->getRender() === 'false' ) {
                // If neither the target render plugin nor any of the currently attached render
                //  plugins actually "render" anything, then there can't be a compatibility problem
                return '';
            }
            else if ( $prev_rp['render'] !== $target_render_plugin->getRender() ) {
                // If the target render plugin has a different "render" value than the currently
                //  attached render plugins, then there's a compatibility problem
                return 'This Render Plugin cannot be used at the same time as the "'.$prev_rp['pluginName'].'" Render Plugin';
            }

            // There's no problem otherwise
            return '';

            // TODO - in the future, they'll have to "claim" a field in a context, just like datatype plugins will
            // TODO - e.g. CurrencyPlugin claims its field in the Display context...meaning no other plugin can claim the same field in the same context
        }
        else {
            // Datatype plugins can (currently) only have at most one plugin where override_child
            //  is true, and at most one plugin where override_fields is true
            $prev_override_child_rp = null;
            $prev_override_fields_rp = null;
            foreach ($all_render_plugin_instances as $num => $rpi) {
                $rp = $rpi['renderPlugin'];

                if ( $rp['overrideChild'] === true ) {
                    if ( is_null($prev_override_child_rp) ) {
                        // If this is the first attached plugin that overrides the childtype, then
                        //  make a note of it
                        $prev_override_child_rp = $rp;
                    }
                    else {
                        // If this is the second attached plugin that overrides the childtype, then
                        //  something has gone wrong?
                        return 'Datatype '.$datatype->getId().' is already in an invalid state due to simultaneously using the "'.$prev_override_child_rp['pluginName'].'" and the "'.$rp['pluginName'].'" Render Plugins??';
                    }
                }

                if ( $rp['overrideFields'] === true ) {
                    if ( is_null($prev_override_fields_rp) ) {
                        // If this is the first attached plugin that overrides the fieldarea, then
                        //  make a note of it
                        $prev_override_fields_rp = $rp;
                    }
                    else {
                        // If this is the second attached plugin that overrides the fieldarea, then
                        //  something has gone wrong?
                        return 'Datatype '.$datatype->getId().' is already in an invalid state due to simultaneously using the "'.$prev_override_fields_rp['pluginName'].'" and the "'.$rp['pluginName'].'" Render Plugins??';
                    }
                }

                // Don't care about plugins when they don't override the childtype or fieldarea
            }

            // Now that the currently attached render plugins have been checked...
            if ( !is_null($prev_override_child_rp) && $target_render_plugin->getOverrideChild() !== false ) {
                // If a currently attached plugin and the target render plugin both want to override
                //  the childtype at the same time, then that's a compatibility problem
                return 'This Render Plugin cannot be used at the same time as the "'.$prev_override_child_rp['pluginName'].'" Render Plugin';
            }
            if ( !is_null($prev_override_fields_rp) && $target_render_plugin->getOverrideFields() !== false ) {
                // If a currently attached plugin and the target render plugin both want to override
                //  the fieldarea at the same time, then that's a compatibility problem
                return 'This Render Plugin cannot be used at the same time as the "'.$prev_override_fields_rp['pluginName'].'" Render Plugin';
            }

            // There's no problem otherwise
            return '';

            // NOTE: the "render" attribute is effectively useless for a datatype plugin now...it
            //  should probably be a part of the required_fields definition, but I can't do that
            //  right now...

            // TODO - in the future, datatype plugins will have to "claim" fields in contexts
            // TODO -  e.g. a Cellparameter would claim the symmetry fields in Display/Edit/FakeEdit contexts
            // TODO -       an autogenerate plugin would claim its field in the FakeEdit context
            // TODO - only one plugin would be allowed to claim a field in a context at a time
            // TODO - ...this would also take the "claims" by the Datafield plugins into consideration

            // TODO - this also means that theme_element plugins no longer have override_fields set to true
        }
    }


    /**
     * Returns a formatted list of which fieldtypes the datafield plugin is allowed to use.
     *
     * @param array $allowed_fieldtypes
     * @param array $rpf_typenames {@link self::getFieldtypeData()}
     * @return string
     */
    private function getAllowedFieldtypesString($allowed_fieldtypes, $rpf_typenames)
    {
        $ft_array = array();
        foreach ($allowed_fieldtypes as $rpf_id => $ft_list) {
            foreach ($ft_list as $num => $ft_id)
                $ft_array[] = '"'.array_search($ft_id, $rpf_typenames).'"';
        }

        $str = '';
        if ( count($ft_array) == 1 ) {
            $str = 'This Render Plugin can only be used on '.implode('', $ft_array).' fields.';
        }
        else if ( count($ft_array) == 2 ) {
            $str = 'This Render Plugin can only be used on '.implode(' or ', $ft_array).' fields.';
        }
        else {
            $str = 'This Render Plugin can only be used on ';
            $count = count($ft_array);
            $ft = '';
            for ($i = 0; $i < $count; $i++) {
                $ft = $ft_array[$i];
                if ( $i < ($count-1) )
                    $str .= $ft.', ';
                else
                    break;
            }
            $str .= ' or '.$ft.' fields.';
        }

        return $str;
    }

    /**
     * Detaches a render plugin from the datafield/datatype.
     * ThemeElement plugins are treated as a Datatype plugin for the purposes of the dialog.
     *
     * @param $datatype_id
     * @param $datafield_id
     * @param $render_plugin_id
     * @param Request $request
     *
     * @return Response
     */
    public function detachrenderpluginAction($datatype_id, $datafield_id, $render_plugin_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatafieldInfoService $datafield_info_service */
            $datafield_info_service = $this->container->get('odr.datafield_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            // Ensure the relevant entities exist
            /** @var DataType $datatype */
            $datatype = null;
            /** @var DataFields|null $datafield */
            $datafield = null;

            if ($datafield_id == 0) {
                // This is a render plugin for a datatype/themeElement/array
                $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
                if ( is_null($datatype) )
                    throw new ODRNotFoundException('Datatype');
            }
            else {
                // This is a render plugin for a datafield
                $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
                if ( is_null($datafield) )
                    throw new ODRNotFoundException('Datafield');

                $datatype = $datafield->getDataType();
                if ( !is_null($datatype->getDeletedAt()) )
                    throw new ODRNotFoundException('Datatype');
            }

            /** @var RenderPlugin $target_render_plugin */
            $target_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find($render_plugin_id);
            if ( is_null($target_render_plugin) )
                throw new ODRNotFoundException('RenderPlugin');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // There should be a RenderPluginInstance for this RenderPlugin and Datatype/Datafield
            $rpi_array = null;
            if ( is_null($datafield) ) {
                $rpi_array = $em->getRepository('ODRAdminBundle:RenderPluginInstance')->findBy(
                    array(
                        'dataType' => $datatype->getId(),
                        'renderPlugin' => $target_render_plugin->getId()
                    )
                );
            }
            else {
                $rpi_array = $em->getRepository('ODRAdminBundle:RenderPluginInstance')->findBy(
                    array(
                        'dataField' => $datafield->getId(),
                        'renderPlugin' => $target_render_plugin->getId()
                    )
                );
            }

            // If there's no renderPluginInstance entity, then the datafield/datatype isn't using
            //  this render plugin
            if ( is_null($rpi_array) )
                throw new ODRNotFoundException('RenderPluginInstance');

            // If there's more than one renderPluginInstance, something went wrong somewhere?
            if ( count($rpi_array) > 1 ) {
                if ( is_null($datafield) )
                    throw new ODRException('The RenderPlugin "'.$target_render_plugin->getPluginClassName().'" is attached to Datatype '.$datatype_id.' more than once??');
                else
                    throw new ODRException('The RenderPlugin "'.$target_render_plugin->getPluginClassName().'" is attached to Datafield '.$datafield_id.' more than once??');
            }

            // So, should only be one renderPluginInstance in here
            /** @var RenderPluginInstance $rpi */
            $rpi = $rpi_array[0];


            // ----------------------------------------
            // Some render plugins need to do stuff when they're no longer active
            // e.g. Graph plugins deleting cached graph images

            // This is wrapped in a try/catch block because any uncaught exceptions thrown
            //  by the event subscribers will prevent further progress...
            try {
                $event = new PluginPreRemoveEvent($rpi, $user);
                $dispatcher->dispatch(PluginPreRemoveEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't particularly want to rethrow the error since it'll interrupt
                //  everything downstream of the event (such as file encryption...), but
                //  having the error disappear is less ideal on the dev environment...
                if ( $this->container->getParameter('kernel.environment') === 'dev' )
                    throw $e;
            }


            // Most plugins just need to delete the RenderPluginInstance, but ThemeElement-type
            //  plugins need to also delete all ThemeRenderPluginInstance entities that reference
            //  this RenderPluginInstance...
            // The ThemeElement is left alone to match what happens when deleting a child/linked
            //  datatype
            $affected_themes = array();
            $affected_theme_elements = array();
            if ( $target_render_plugin->getPluginType() === RenderPlugin::THEME_ELEMENT_PLUGIN ) {
                $query = $em->createQuery(
                   'SELECT trpi
                    FROM ODRAdminBundle:ThemeRenderPluginInstance trpi
                    WHERE trpi.renderPluginInstance = :rpi_id
                    AND trpi.deletedAt IS NULL'
                )->setParameters(
                    array('rpi_id' => $rpi->getId())
                );
                $results = $query->getResult();
                /** @var ThemeRenderPluginInstance[] $results */

                foreach ($results as $trpi) {
                    // Not going to delete the themeElement...
                    $theme_element = $trpi->getThemeElement();
                    $affected_theme_elements[ $theme_element->getId() ] = 1;

                    // ...but do need to recache the theme the themeElement belongs to
                    $theme = $theme_element->getTheme();
                    $affected_themes[ $theme->getId() ] = $theme;

                    // No longer need this ThemeRenderPluginInstance
                    $trpi->setDeletedBy($user);
                    $trpi->setDeletedAt(new \DateTime());
                    $em->persist($trpi);
                }

                // Mark each of the affected themes as updated
                foreach ($affected_themes as $t_id => $t)
                    $theme_info_service->updateThemeCacheEntry($t, $user);

                // Convert the list of themeElements so javascript has a little easier time
                $affected_theme_elements = array_keys($affected_theme_elements);
            }

            // Remove/detach this plugin instance from the datafield/datatype
            $em->remove($rpi);
            $em->flush();

            // Both the "global" and the "theme-specific" option maps are intentionally ignored


            // ----------------------------------------
            // Now that all the database changes have been made, wipe the relevant cache entries
            try {
                $event = new DatatypeModifiedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Changes in render plugin tend to require changes in datafield properties
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId());
            // Don't need to filter here
            $datafield_properties = json_encode($datafield_info_service->getDatafieldProperties($datatype_array));

            $return['d'] = array(
                'datafield_id' => $datafield_id,     // the entity may be null, so use the param that was passed in
                'datatype_id' => $datatype->getId(), // this entity won't be null

                'datafield_properties' => $datafield_properties,
                'affected_theme_elements' => $affected_theme_elements,
            );
        }
        catch (\Exception $e) {
            $source = 0x2680077f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves changes to the fields or options of a RenderPlugin currently in use by a Datatype or a
     * Datafield.  Also handles attaching RenderPlugins to Datatypes/Datafields.
     * ThemeElement and Array plugins are treated as a Datatype plugin for the purposes of the dialog.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function saverenderpluginsettingsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab the data from the POST request
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['local_datafield_id'])
                || !isset($post['local_datatype_id'])
                || !isset($post['selected_render_plugin'])
            ) {
                throw new ODRBadRequestException('Invalid Form');
            }

            $local_datatype_id = $post['local_datatype_id'];
            $local_datafield_id = $post['local_datafield_id'];
            $selected_plugin_id = $post['selected_render_plugin'];

            $new_df_fieldtypes = array();
            if ( isset($post['new_df_fieldtypes']) )
                $new_df_fieldtypes = $post['new_df_fieldtypes'];

            $plugin_map = array();
            if ( isset($post['plugin_map']) )
                $plugin_map = $post['plugin_map'];

            $plugin_options = array();
            if ( isset($post['plugin_options']) )
                $plugin_options = $post['plugin_options'];

            // Need to unescape these values if they're coming from a wordpress install...
            $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
            if ( $is_wordpress_integrated ) {
                foreach ($plugin_options as $rpo_id => $value)
                    $plugin_options[$rpo_id] = stripslashes($value);
            }


            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var DatafieldInfoService $datafield_info_service */
            $datafield_info_service = $this->container->get('odr.datafield_info_service');
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptionsDef');    // TODO - rename RenderPluginOptionsDef to RenderPluginOptions?
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');
            $repo_render_plugin_options_map = $em->getRepository('ODRAdminBundle:RenderPluginOptionsMap');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');

            /** @var DataType|null $target_datatype */
            $target_datatype = null;    // the datatype that is getting its render plugin modified, or the datatype of the datafield getting its render plugin modified
            /** @var DataFields|null $target_datafield */
            $target_datafield = null;   // the datafield that is getting its render plugin modified
            /** @var RenderPluginInstance[] $all_render_plugin_instances */
            $all_render_plugin_instances = array();

            $changing_datatype_plugin = false;
            $changing_datafield_plugin = false;

            if ($local_datafield_id == 0) {
                // Changing the render plugin for a datatype/themeElement/array...
                $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($local_datatype_id);
                if ( is_null($target_datatype) )
                    throw new ODRNotFoundException('Datatype');

                $all_render_plugin_instances = $repo_render_plugin_instance->findBy(
                    array(
                        'dataType' => $target_datatype->getId()
                    )
                );

                $changing_datatype_plugin = true;
            }
            else {
                // Changing the render plugin for a datafield...
                $target_datafield = $repo_datafield->find($local_datafield_id);
                if ( is_null($target_datafield) )
                    throw new ODRNotFoundException('Datafield');

                $target_datatype = $target_datafield->getDataType();
                if ( !is_null($target_datatype->getDeletedAt()) )
                    throw new ODRNotFoundException('Datatype');

                $all_render_plugin_instances = $repo_render_plugin_instance->findBy(
                    array(
                        'dataField' => $target_datafield->getId()
                    )
                );

                $changing_datafield_plugin = true;
            }

            /** @var RenderPlugin $selected_render_plugin */
            $selected_render_plugin = $repo_render_plugin->find($selected_plugin_id);
            if ( is_null($selected_render_plugin) )
                throw new ODRNotFoundException('RenderPlugin');

            // Need to know whether the user just saved changes to a plugin the datatype/datafield
            //  is already using...
            $selected_render_plugin_instance = null;
            foreach ($all_render_plugin_instances as $rpi) {
                /** @var RenderPluginInstance $rpi */
                if ( $rpi->getRenderPlugin()->getId() === $selected_render_plugin->getId() )
                    $selected_render_plugin_instance = $rpi;
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $target_datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Need the master theme, in case datafields or themeElements need to be created
            $master_theme = $theme_info_service->getDatatypeMasterTheme($target_datatype->getId());


            // ----------------------------------------
            // Ensure the user isn't trying to save the wrong type of RenderPlugin
            $is_datatype_plugin = false;
            if ( $selected_render_plugin->getPluginType() == RenderPlugin::DATATYPE_PLUGIN
                || $selected_render_plugin->getPluginType() == RenderPlugin::THEME_ELEMENT_PLUGIN
                || $selected_render_plugin->getPluginType() === RenderPlugin::ARRAY_PLUGIN
            ) {
                $is_datatype_plugin = true;
            }

            $is_datafield_plugin = false;
            if ( $selected_render_plugin->getPluginType() == RenderPlugin::DATAFIELD_PLUGIN )
                $is_datafield_plugin = true;

            if ( $changing_datatype_plugin && $is_datafield_plugin)
                throw new ODRBadRequestException('Unable to save a Datafield plugin to a Datatype');
            else if ( $changing_datafield_plugin && $is_datatype_plugin )
                throw new ODRBadRequestException('Unable to save a Datatype plugin to a Datafield');


            // Ensure the selected render plugin doesn't conflict with a plugin that is already
            //  attached
            $illegal_render_plugin_message = self::validatePluginCompatibility($em, $target_datatype, $target_datafield, $selected_render_plugin);
            if ( $illegal_render_plugin_message !== '' )
                throw new ODRBadRequestException($illegal_render_plugin_message);


            // Ensure the plugin map doesn't have the same datafield mapped to multiple renderPluginFields
            $mapped_datafields = array();
            foreach ($plugin_map as $rpf_id => $df_id) {
                if ( $df_id != '-1' && $df_id != '-2' ) {
                    if ( isset($mapped_datafields[$df_id]) )
                        throw new ODRBadRequestException('Invalid Form...multiple datafields mapped to the same renderpluginfield');

                    $mapped_datafields[$df_id] = 0;
                }
            }

            // Ensure the datafields listed in the plugin map are of the correct fieldtype, and that
            //  every field required by the plugin is mapped
            /** @var RenderPluginFields[] $render_plugin_fields */
            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $selected_render_plugin) );
            if ( count($render_plugin_fields) !== count($plugin_map) )
                throw new ODRBadRequestException('Invalid Form...incorrect number of datafields mapped');

            // Might as well store these in a lookup array at the same time, so we don't have to
            //  keep reloading them
            $rpf_lookup = array();
            foreach ($render_plugin_fields as $rpf) {
                $rpf_id = $rpf->getId();
                $rpf_lookup[$rpf_id] = $rpf;

                // Ensure all required datafields for this RenderPlugin are listed in the $_POST
                if ( !isset($plugin_map[$rpf_id]) )
                    throw new ODRBadRequestException('Invalid Form...missing datafield mapping');
                // Ensure that all datafields marked as "new" have a fieldtype mapping
                if ( $plugin_map[$rpf_id] == '-1' && !isset($new_df_fieldtypes[$rpf_id]) )
                    throw new ODRBadRequestException('Invalid Form...missing fieldtype mapping');

                if ( $plugin_map[$rpf_id] != '-1' && $plugin_map[$rpf_id] != '-2' ) {
                    // If mapping to an actual field, ensure it has a valid fieldtype
                    $allowed_fieldtypes = $rpf->getAllowedFieldtypes();
                    $allowed_fieldtypes = explode(',', $allowed_fieldtypes);

                    // Ensure referenced datafields exist
                    /** @var DataFields $df */
                    $df = $repo_datafield->find( $plugin_map[$rpf_id] );
                    if ( is_null($df) )
                        throw new ODRNotFoundException('Invalid Form...datafield does not exist');

                    // Ensure referenced datafields have a valid fieldtype for this renderpluginfield
                    $ft_id = $df->getFieldType()->getId();
                    if ( !in_array($ft_id, $allowed_fieldtypes) )
                        throw new ODRBadRequestException('Invalid Form...attempting to map renderpluginfield to invalid fieldtype');


                    // Ensure referenced datafields can have any required properties forced on them
                    if ( $rpf->getMustBeUnique() ) {
                        if ( !$datafield_info_service->canDatafieldBeUnique($df) )
                            throw new ODRBadRequestException('The render plugin requires the field mapped to "'.$rpf->getFieldName().'" to be unique, but the datafield "'.$df->getFieldName().'" can not be unique');
                    }
                    if ( $rpf->getSingleUploadsOnly() ) {
                        if ( $datafield_info_service->hasMultipleUploads($df) )
                            throw new ODRBadRequestException('The render plugin requires the field mapped to "'.$rpf->getFieldName().'" to only allow single uploads, but the datafield "'.$df->getFieldName().'" already has multiple uploaded files/images');
                    }
                }
            }

            // Ensure that the options listed in the post belong to the correct render plugin
            /** @var RenderPluginOptionsDef[] $render_plugin_options */
            $render_plugin_options = $repo_render_plugin_options->findBy( array('renderPlugin' => $selected_render_plugin) );

            $rpo_lookup = array();
            foreach ($render_plugin_options as $num => $rpo) {
                // Layout-specific options shouldn't be submitted as part of this form
                if ( !$rpo->getUsesLayoutSettings() ) {
                    $rpo_id = $rpo->getId();
                    $rpo_lookup[$rpo_id] = $rpo;

                    // Ensure all required options for this RenderPlugin are listed in the $_POST
                    if ( !isset($plugin_options[$rpo_id]) )
                        throw new ODRBadRequestException('Invalid Form...missing option mapping');
                }
            }
            if ( count($rpo_lookup) !== count($plugin_options) )
                throw new ODRBadRequestException('Invalid Form...incorrect number of options mapped');

            $plugin_attached = false;
            $plugin_theme_elements_added = false;
            $plugin_fields_added = array();
            $plugin_fields_changed = array();
            $plugin_settings_changed = array();
            $reload_datatype = false;


            // ----------------------------------------
            // Create any new datafields required
            $theme_element = null;
            $new_datafields = array();
            foreach ($new_df_fieldtypes as $rpf_id => $ft_id) {
                // Only attempt to create new datafields if they're being requested
                if ( $plugin_map[$rpf_id] != '-1' )
                    continue;

                // Since new datafields are being created, instruct ajax success handler in
                //  plugin_settings_dialog.html.twig to call ReloadChild() afterwards
                $reload_datatype = true;

                // Don't need to update $plugin_fields_added here, since a new datafield also means
                //  a new renderPluginMap entry later on

                // Create a single new ThemeElement to store the new datafields in, if necessary
                if ( is_null($theme_element) )
                    $theme_element = $entity_create_service->createThemeElement($user, $master_theme, true);

                // Load information for the new datafield
                /** @var FieldType $fieldtype */
                $fieldtype = $repo_fieldtype->find($ft_id);
                if ( is_null($fieldtype) )
                    throw new ODRBadRequestException('Invalid Form');

                /** @var RenderPluginFields $rpf */
                $rpf = $rpf_lookup[$rpf_id];

                // Create the Datafield and set basic properties from the render plugin settings
                $datafield = $entity_create_service->createDatafield($user, $target_datatype, $fieldtype, true);    // Don't flush immediately...

                $datafield_meta = $datafield->getDataFieldMeta();
                $datafield_meta->setFieldName( $rpf->getFieldName() );
                $datafield_meta->setDescription( $rpf->getDescription() );
                $em->persist($datafield_meta);


                // Attach the new datafield to the previously created theme_element
                $entity_create_service->createThemeDatafield($user, $theme_element, $datafield);    // need to flush here so $datafield->getID() works later

                // Now that the datafield exists, update the plugin map
                $em->refresh($datafield);
                $plugin_map[$rpf_id] = $datafield->getId();
                $new_datafields[] = $datafield;

                if ($fieldtype->getTypeClass() == 'Image')
                    $entity_create_service->createImageSizes($user, $datafield);    // TODO - test this...no render plugin creates an image at the moment
            }

            // If new datafields created, flush entity manager to save the theme_element and datafield meta entries
            if ($reload_datatype) {
                $em->flush();

                // Now that flushing has happened, should fire off events notifying of each new datafield
                foreach ($new_datafields as $df) {
                    try {
                        $event = new DatafieldCreatedEvent($df, $user);
                        $dispatcher->dispatch(DatafieldCreatedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }
            }


            // ----------------------------------------
            // If the datatype/datafield isn't already using this render plugin...
            if ( is_null($selected_render_plugin_instance) ) {
                // ...then create a renderPluginInstance entity tying the two together
                $selected_render_plugin_instance = $entity_create_service->createRenderPluginInstance($user, $selected_render_plugin, $target_datatype, $target_datafield);    // need to flush here
                /** @var RenderPluginInstance $selected_render_plugin_instance */

                $plugin_attached = true;
            }


            // ----------------------------------------
            // If the render plugin requires themeElements...
            if ( $selected_render_plugin->getRequiredThemeElements() > 0 ) {
                // TODO - allow render plugins to require more than one themeElement?
                $theme_element = null;

                // ...and there are no themeElements attached to this instance of the render plugin...
                if ( $selected_render_plugin_instance->getThemeRenderPluginInstance()->count() === 0 ) {
                    // ...then create a new themeElement...
                    $plugin_theme_elements_added = true;
                    $theme_element = $entity_create_service->createThemeElement($user, $master_theme, true);    // can delay flush for a moment...

                    // ...so it can be used to hold whatever the render plugin wants
                    $trpi = $entity_create_service->createThemeRenderPluginInstance($user, $theme_element, $selected_render_plugin_instance);

                    // This call to createThemeRenderPluginInstance() flushes, so don't need to do
                    //  it here

                    // Since a themeElement is being created, instruct ajax success handler in
                    //  plugin_settings_dialog.html.twig to call ReloadChild() afterwards
                    $reload_datatype = true;
                }
            }


            // ----------------------------------------
            // Save any changes to the RenderPluginField mapping
            foreach ($plugin_map as $rpf_id => $df_id) {
                // Attempt to locate the mapping for this render plugin field in this instance
                /** @var RenderPluginMap $rpm */
                $rpm = $repo_render_plugin_map->findOneBy(
                    array(
                        'renderPluginInstance' => $selected_render_plugin_instance->getId(),
                        'renderPluginFields' => $rpf_id
                    )
                );
                // Locate the render plugin field object being referenced
                /** @var RenderPluginFields $rpf */
                $rpf = $rpf_lookup[$rpf_id];

                // Due to allowing "optional" fields, the datafield here could legitimately be null
                $df = null;
                if ( $df_id != '-2' ) {
                    // Locate the desired datafield object...already checked for its existence earlier
                    /** @var DataFields $df */
                    $df = $repo_datafield->find($df_id);
                }

                // If the render plugin map entity doesn't exist, create it
                if ( is_null($rpm) ) {
                    $entity_create_service->createRenderPluginMap($user, $selected_render_plugin_instance, $rpf, $target_datatype, $df, true);    // don't need to flush...
                    $plugin_fields_added[] = $rpf->getFieldName();
                }
                else {
                    // ...otherwise, update the existing entity
                    $props = array(
                        'dataField' => $df
                    );

                    // If the field used to be null but isn't anymore, then it was technically added
                    if ( is_null($rpm->getDataField()) && !is_null($df) )
                        $plugin_fields_added[] = $rpf->getFieldName();

                    $changes_made = $entity_modify_service->updateRenderPluginMap($user, $rpm, $props, true);    // don't need to flush...
                    if ($changes_made)
                        $plugin_fields_changed[] = $rpf->getFieldName();
                }

                if ( !is_null($df) ) {
                    // The datafield may need to have these properties set due to the render plugin
                    $props = array();
                    if ( $rpf->getMustBeUnique() )
                        $props['is_unique'] = true;
                    if ( $rpf->getSingleUploadsOnly() )
                        $props['allow_multiple_uploads'] = false;
                    if ( $rpf->getNoUserEdits() )
                        $props['prevent_user_edits'] = true;

                    if ( !empty($props) )
                        $entity_modify_service->updateDatafieldMeta($user, $df, $props, true);    // don't need to flush...
                }
            }

            // Save any changes to the RenderPluginOptions mapping
            foreach ($plugin_options as $rpo_id => $value) {
                // Attempt to locate the existing RenderPluginOptionsMap entity
                /** @var RenderPluginOptionsMap $render_plugin_option_map */
                $render_plugin_option_map = $repo_render_plugin_options_map->findOneBy(
                    array(
                        'renderPluginInstance' => $selected_render_plugin_instance->getId(),
                        'renderPluginOptionsDef' => $rpo_id,    // TODO - rename to renderPluginOptions
                    )
                );

                // If the RenderPluginOptionsMap entity doesn't exist, create it
                if ( is_null($render_plugin_option_map) ) {
                    // Load the RenderPluginOptions object being referenced
                    /** @var RenderPluginOptionsDef $render_plugin_option */
                    $render_plugin_option = $rpo_lookup[$rpo_id];

                    $entity_create_service->createRenderPluginOptionsMap($user, $selected_render_plugin_instance, $render_plugin_option, $value, true);    // don't need to flush...
                    $plugin_settings_changed[] = $render_plugin_option->getName();
                }
                else {
                    // ...otherwise, update the existing entity
                    $properties = array(
                        'value' => $value
                    );
                    $changes_made = $entity_modify_service->updateRenderPluginOptionsMap($user, $render_plugin_option_map, $properties, true);    // don't need to flush...

                    if ($changes_made)
                        $plugin_settings_changed[] = $render_plugin_option_map->getRenderPluginOptionsDef()->getName();
                }
            }

            // RenderPluginThemeOptionsMap entries are not handled here, this is only for setting
            //  "global" options


            // ----------------------------------------
            // Should be able to flush here
            $em->flush();

            if ( $plugin_attached ) {
                // Some render plugins need to do stuff when they get added to a datafield/datatype
                // e.g. Currency plugins deleting cached table entries

                // This is wrapped in a try/catch block because any uncaught exceptions thrown
                //  by the event subscribers will prevent further progress...
                try {
                    $event = new PluginAttachEvent($selected_render_plugin_instance, $user);
                    $dispatcher->dispatch(PluginAttachEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't particularly want to rethrow the error since it'll interrupt
                    //  everything downstream of the event (such as file encryption...), but
                    //  having the error disappear is less ideal on the dev environment...
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }

            // Only need to update the theme entry if plugin fields were created
            if ( !empty($new_datafields) || !empty($plugin_fields_added) || $plugin_theme_elements_added )
                $theme_info_service->updateThemeCacheEntry($master_theme, $user);

            if ( !empty($plugin_fields_added) || !empty($plugin_fields_changed) || !empty($plugin_settings_changed) ) {
                // Some render plugins need to do stuff when their settings get changed
                // e.g. Graph plugins deleting cached graph images
                $changed_fields = array_merge($plugin_fields_added, $plugin_fields_changed);

                // This is wrapped in a try/catch block because any uncaught exceptions thrown
                //  by the event subscribers will prevent further progress...
                try {
                    $event = new PluginOptionsChangedEvent($selected_render_plugin_instance, $user, $changed_fields, $plugin_settings_changed);
                    $dispatcher->dispatch(PluginOptionsChangedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't particularly want to rethrow the error since it'll interrupt
                    //  everything downstream of the event (such as file encryption...), but
                    //  having the error disappear is less ideal on the dev environment...
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }

            if ( $plugin_attached || !empty($plugin_fields_added) || $plugin_theme_elements_added || !empty($plugin_fields_changed) || !empty($plugin_settings_changed) ) {
                // Also need to ensure that changes to plugin settings update the "master_revision"
                //  property of template datafields/datatypes
                if ($local_datafield_id == 0) {
                    if ($target_datatype->getIsMasterType())
                        $entity_modify_service->incrementDatatypeMasterRevision($user, $target_datatype);
                }
                else {
                    if ($target_datafield->getIsMasterField())
                        $entity_modify_service->incrementDatafieldMasterRevision($user, $target_datafield);
                }

                // Mark the datatype as updated
                try {
                    $event = new DatatypeModifiedEvent($target_datatype, $user);
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }


            // ----------------------------------------
            // Ensure datafield properties are up to date
            $datatype_array = $database_info_service->getDatatypeArray($target_datatype->getGrandparent()->getId());
            $datafield_properties = json_encode($datafield_info_service->getDatafieldProperties($datatype_array));

            $return['d'] = array(
                'datafield_id' => $local_datafield_id,
                'datatype_id' => $local_datatype_id,

                'datafield_properties' => $datafield_properties,
                'reload_datatype' => $reload_datatype,
            );
        }
        catch (\Exception $e) {
            $source = 0x75fbef09;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Vaguely similar to {@link self::renderplugindialogAction()}, but instead handles layout-specific
     * settings
     *
     * @param integer $theme_id
     * @param integer $datatype_id  The id of the Datatype that might be having its RenderPlugin changed
     * @param integer $datafield_id The id of the Datafield that might be having its RenderPlugin changed
     * @param Request $request
     *
     * @return Response
     */
    public function pluginlayoutsettingsdialogAction($theme_id, $datatype_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');

            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            // Need to specify either a datafield or a datatype...
            $datatype = null;
            $datafield = null;
            if ($datafield_id == 0 && $datatype_id == 0)
                throw new ODRBadRequestException();

            if ($datafield_id == 0) {
                // If datafield id isn't defined, this is a render plugin for a datatype/themeElement/array

                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                if ( is_null($datatype) )
                    throw new ODRNotFoundException('Datatype');
            }
            else {
                // ...otherwise, this is a render plugin for a datafield

                /** @var DataFields $datafield */
                $datafield = $repo_datafield->find($datafield_id);
                if ( is_null($datafield) )
                    throw new ODRNotFoundException('Datafield');

                $datatype = $datafield->getDataType();
                if ( $datatype->getDeletedAt() != null )
                    throw new ODRNotFoundException('Datatype');
            }

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
//            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
//                throw new ODRForbiddenException();
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            $is_datatype_admin = $permissions_service->isDatatypeAdmin($user, $datatype);
            // --------------------

            // Unlike the other dialog action, this dialog only wants plugins that:
            // 1) have layout-specific settings, and
            // 2) are attached to this datatype/datafield
            /** @var RenderPlugin[] $render_plugins */
            $render_plugins = null;
            /** @var RenderPluginInstance[] $render_plugin_instances */
            $render_plugin_instances = null;

            if ($datafield_id == 0) {
                // If datafield id isn't defined, then load all available datatype/themeElement/array
                //  render plugins...
                $query = $em->createQuery(
                   'SELECT rpi
                    FROM ODRAdminBundle:RenderPluginInstance AS rpi
                    LEFT JOIN ODRAdminBundle:RenderPlugin AS rp WITH rpi.renderPlugin = rp
                    LEFT JOIN ODRAdminBundle:RenderPluginOptionsDef AS rpod WITH rpod.renderPlugin = rp
                    WHERE rp.plugin_type IN (:plugin_types) AND rpod.uses_layout_settings = :uses_layout_settings
                    AND rpi.dataType = :datatype
                    AND rp.deletedAt IS NULL AND rpod.deletedAt IS NULL AND rpi.deletedAt IS NULL
                    ORDER BY rp.category, rp.pluginName'
                )->setParameters(
                    array(
                        'plugin_types' => array(
                            RenderPlugin::DATATYPE_PLUGIN,
                            RenderPlugin::THEME_ELEMENT_PLUGIN,
                            RenderPlugin::ARRAY_PLUGIN,
                        ),
                        'uses_layout_settings' => true,
                        'datatype' => $datatype_id,
                    )
                );
                /** @var RenderPluginInstance[] $results */
                $results = $query->getResult();

                foreach ($results as $num => $rpi) {
                    $render_plugin_instances[$num] = $rpi;
                    $render_plugins[$num] = $rpi->getRenderPlugin();
                }
            }
            else {
                // Otherwise, this is a request for a datafield...load all available datafield
                //  render plugins
                $query = $em->createQuery(
                   'SELECT rpi
                    FROM ODRAdminBundle:RenderPluginInstance AS rpi
                    LEFT JOIN ODRAdminBundle:RenderPlugin AS rp WITH rpi.renderPlugin = rp
                    LEFT JOIN ODRAdminBundle:RenderPluginOptionsDef AS rpod WITH rpod.renderPlugin = rp
                    WHERE rp.plugin_type IN (:plugin_types) AND rpod.uses_layout_settings = :uses_layout_settings
                    AND rpi.dataField = :datafield
                    AND rp.deletedAt IS NULL AND rpod.deletedAt IS NULL AND rpi.deletedAt IS NULL
                    ORDER BY rp.category, rp.pluginName'
                )->setParameters(
                    array(
                        'plugin_types' => array(
                            RenderPlugin::DATAFIELD_PLUGIN,
                        ),
                        'uses_layout_settings' => true,
                        'datafield' => $datafield_id,
                    )
                );
                /** @var RenderPluginInstance[] $results */
                $results = $query->getResult();

                foreach ($results as $num => $rpi) {
                    $render_plugin_instances[$num] = $rpi;
                    $render_plugins[$num] = $rpi->getRenderPlugin();
                }
            }

            if ( empty($render_plugin_instances) )
                throw new ODRBadRequestException('None of the plugins currently attached to this entity have layout-specific settings');


            // ----------------------------------------
            // Cleaner to determine which plugin to load in here, instead of in twig
            $attached_render_plugins = array();
            $plugin_to_load = null;
            foreach ($render_plugin_instances as $rpi) {
                $rp_id = $rpi->getRenderPlugin()->getId();
                $attached_render_plugins[$rp_id] = 1;

                // If this datatype/datafield is using a plugin...
                if ( is_null($plugin_to_load) ) {
                    // ...then get the javascript load its description, fields, and options after
                    //  the dialog opens
                    $plugin_to_load = $rp_id;
                }
                else if ( $rpi->getRenderPlugin()->getRender() !== 'false' ) {
                    // ...if the datatype/datafield is using more than one plugin, then preferentially
                    //  load the data for the plugin that actually renders something
                    $plugin_to_load = $rp_id;
                }
            }
            // ...it's not critical for this to be super-accurate


            // ----------------------------------------
            // Get Templating Object
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Plugins:plugin_layout_settings_dialog_form.html.twig',
                    array(
                        'theme_id' => $theme_id,
                        'local_datatype' => $datatype,
                        'local_datafield' => $datafield,
                        'is_datatype_admin' => $is_datatype_admin,

                        'all_render_plugins' => $render_plugins,
                        'plugin_to_load' => $plugin_to_load,

                        'render_plugin_instances' => $render_plugin_instances,
                        'attached_render_plugins' => $attached_render_plugins,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x5520cdd5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads and renders the data required for layout-specific settings for the given entities
     *
     * @param integer $theme_id
     * @param integer|null $datatype_id  The id of the Datatype that might be having its RenderPlugin changed
     * @param integer|null $datafield_id The id of the Datafield that might be having its RenderPlugin changed
     * @param integer $render_plugin_id  The database id of the RenderPlugin to look up.
     * @param Request $request
     *
     * @return Response
     */
    public function renderpluginlayoutsettingsAction($theme_id, $datatype_id, $datafield_id, $render_plugin_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // Ensure the relevant entities exist
            /** @var DataType $datatype */
            $datatype = null;
            /** @var DataFields|null $datafield */
            $datafield = null;

            if ($datafield_id == 0) {
                // This is a render plugin for a datatype/themeElement/array
                $datatype = $repo_datatype->find($datatype_id);
                if ( is_null($datatype) )
                    throw new ODRNotFoundException('Datatype');
            }
            else {
                // This is a render plugin for a datafield
                $datafield = $repo_datafield->find($datafield_id);
                if ( is_null($datafield) )
                    throw new ODRNotFoundException('Datafield');

                $datatype = $datafield->getDataType();
                if ( !is_null($datatype->getDeletedAt()) )
                    throw new ODRNotFoundException('Datatype');
            }

            /** @var RenderPlugin $target_render_plugin */
            $target_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find($render_plugin_id);
            if ( is_null($target_render_plugin) )
                throw new ODRNotFoundException('RenderPlugin');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
//            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
//                throw new ODRForbiddenException();
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            $is_datatype_admin = $permissions_service->isDatatypeAdmin($user, $datatype);
            // --------------------


            // ----------------------------------------
            // Load the description and the available options of the requested RenderPlugin
            // TODO - change renderPluginOptionsDef to RenderPluginOptions?
            $query = $em->createQuery(
               'SELECT rp, rpo
                FROM ODRAdminBundle:RenderPlugin rp
                LEFT JOIN rp.renderPluginOptionsDef rpo
                WHERE rp.id = :render_plugin_id
                AND rp.deletedAt IS NULL AND rpo.deletedAt IS NULL'
            )->setParameters( array('render_plugin_id' => $target_render_plugin->getId()) );
            $results = $query->getArrayResult();

            // Only going to be one result in here
            $render_plugin = $results[0];

            // Rekey the RenderPluginOptions array to use its respective ids, so it'll be easier
            //  for the RenderPluginInstance to look up values when needed
            $tmp = array();
            foreach ($render_plugin['renderPluginOptionsDef'] as $num => $rpo)
                $tmp[ $rpo['id'] ] = $rpo;
            unset( $render_plugin['renderPluginOptionsDef'] );
            $render_plugin['renderPluginOptions'] = $tmp;

            // Convert the renderPluginOption choices from a string into an array
            foreach ($render_plugin['renderPluginOptions'] as $rpo_id => $rpo) {
                $choices = array();
                if ( isset($rpo['choices']) ) {
                    if ( strpos($rpo['choices'], ',') === false ) {
                        // This is a set of "simple" choices...the display values work as HTML keys
                        $options = explode('||', $rpo['choices']);
                        foreach ($options as $option)
                            $choices[$option] = $option;
                    }
                    else {
                        // ...most choices have display values that don't work as HTML keys though
                        $options = explode(',', $rpo['choices']);
                        foreach ($options as $option) {
                            $divider = strpos($option, '||');
                            $key = substr($option, 0, $divider);
                            $value = substr($option, $divider+2);

                            $choices[$key] = $value;
                        }
                    }
                }
                $render_plugin['renderPluginOptions'][$rpo_id]['choices'] = $choices;
            }

            // Order both the fields and the options by their relevant display_order property...it's
            //  not always defined in either case though
            uasort($render_plugin['renderPluginOptions'], function($a, $b) {
                if ( $a['display_order'] <= $b['display_order'] )
                    return -1;
                else
                    return 1;
            });


            // ----------------------------------------
            // The previous block loaded the available options for a plugin...now attempt to load
            //  the most recent selections for the options for this theme/renderPluginInstance combo
            $query = null;
            if ( is_null($datafield) ) {
                $query = $em->createQuery(
                   'SELECT rpi, rp
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    JOIN rpi.renderPlugin AS rp
                    WHERE rpi.dataType = :dataType
                    ORDER BY rpi.id ASC'
                )->setParameters(
                    array(
                        'dataType' => $datatype
                    )
                );
            }
            else {
                $query = $em->createQuery(
                   'SELECT rpi, rp
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    JOIN rpi.renderPlugin AS rp
                    WHERE rpi.dataField = :dataField
                    ORDER BY rpi.id ASC'
                )->setParameters(
                    array(
                        'dataField' => $datafield
                    )
                );
            }
            $all_render_plugin_instances = $query->getArrayResult();

            $current_render_plugin_instance = null;
            if ( !empty($all_render_plugin_instances) ) {
                // The most recent RenderPluginInstance will be the last one in the results array
                foreach ($all_render_plugin_instances as $result) {
                    if ( $result['renderPlugin']['id'] === $target_render_plugin->getId() )
                        $current_render_plugin_instance = $result;
                }
            }
            // NOTE: "current" doesn't necessarily imply the plugin is currently attached to the
            //  datatype/datafield...it could refer to the "most recent" instance of the plugin
            //  that was attached

            // If the datatype/datafield has (or had) an instance for this render plugin, then load
            //  the renderPluginFieldsMap and renderPluginOptionsMap entries for this instance
            $render_plugin_instance = null;
            if ( !is_null($current_render_plugin_instance) ) {
                // TODO - change renderPluginOptionsDef to RenderPluginOptions?
                $query = $em->createQuery(
                   'SELECT rpi, rptom, partial rpo.{id}, partial t.{id}
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    LEFT JOIN rpi.renderPluginThemeOptionsMap rptom
                    LEFT JOIN rptom.renderPluginOptionsDef rpo
                    LEFT JOIN rptom.theme t
                    WHERE rpi.id = :render_plugin_instance_id'
                )->setParameters(
                    array(
                        'render_plugin_instance_id' => $current_render_plugin_instance['id']
                    )
                );
                $results = $query->getArrayResult();

                // Should only have one result
                $render_plugin_instance = $results[0];

                // Rekey the RenderPluginOptionsMap array to use the RenderPluginOptions id, so it's
                //  easier to lookup values if needed
                $tmp = array();
                foreach ($render_plugin_instance['renderPluginThemeOptionsMap'] as $num => $rptom) {
                    if ( $rptom['theme']['id'] == $theme->getId() ) {
                        $rpo_id = $rptom['renderPluginOptionsDef']['id'];
                        unset( $rptom['renderPluginOptionsDef'] );
                        // The values of the more recent RenderPluginOptionMap entries will end up
                        //  overwriting the older RenderPluginOptionMap entries
                        $tmp[$rpo_id] = $rptom;
                    }
                }
                $render_plugin_instance['renderPluginThemeOptionsMap'] = $tmp;
            }


            // ----------------------------------------
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Plugins:plugin_layout_settings_dialog_form_data.html.twig',
                    array(
                        'theme_id' => $theme_id,
                        'local_datatype' => $datatype,
                        'local_datafield' => $datafield,
                        'is_datatype_admin' => $is_datatype_admin,

                        'render_plugin' => $render_plugin,
                        'render_plugin_instance' => $render_plugin_instance,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x7590e380;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves changes to the layout-specific settings for a datatype/datafield plugin.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function saverenderpluginlayoutsettingsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab the data from the POST request
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['local_datafield_id'])
                || !isset($post['local_datatype_id'])
                || !isset($post['selected_render_plugin'])
                || !isset($post['selected_theme'])
            ) {
                throw new ODRBadRequestException('Invalid Form');
            }

            $local_datatype_id = intval($post['local_datatype_id']);
            $local_datafield_id = intval($post['local_datafield_id']);
            $selected_plugin_id = intval($post['selected_render_plugin']);
            $current_theme_id = intval($post['selected_theme']);

            $plugin_options = array();
            if ( isset($post['plugin_options']) )
                $plugin_options = $post['plugin_options'];

            if ( empty($plugin_options) )
                throw new ODRBadRequestException('Invalid Form');

            // Need to unescape these values if they're coming from a wordpress install...
            $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
            if ( $is_wordpress_integrated ) {
                foreach ($plugin_options as $rpo_id => $value)
                    $plugin_options[$rpo_id] = stripslashes($value);
            }


            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptionsDef');    // TODO - rename RenderPluginOptionsDef to RenderPluginOptions?
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');
            $repo_render_plugin_theme_options_map = $em->getRepository('ODRAdminBundle:RenderPluginThemeOptionsMap');
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

            /** @var DataType|null $target_datatype */
            $target_datatype = null;    // the datatype that is getting its render plugin modified, or the datatype of the datafield getting its render plugin modified
            /** @var DataFields|null $target_datafield */
            $target_datafield = null;   // the datafield that is getting its render plugin modified
            /** @var RenderPluginInstance[] $all_render_plugin_instances */
            $all_render_plugin_instances = array();

            /** @var Theme $theme */
            $theme = $repo_theme->find($current_theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $changing_datatype_plugin = false;
            $changing_datafield_plugin = false;

            if ($local_datafield_id == 0) {
                // Changing the render plugin for a datatype/themeElement/array...
                $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($local_datatype_id);
                if ( is_null($target_datatype) )
                    throw new ODRNotFoundException('Datatype');

                if ( $theme->getDataType()->getId() !== $local_datatype_id )
                    throw new ODRBadRequestException('Invalid Theme');

                $all_render_plugin_instances = $repo_render_plugin_instance->findBy(
                    array(
                        'dataType' => $target_datatype->getId()
                    )
                );

                $changing_datatype_plugin = true;
            }
            else {
                // Changing the render plugin for a datafield...
                $target_datafield = $repo_datafield->find($local_datafield_id);
                if ( is_null($target_datafield) )
                    throw new ODRNotFoundException('Datafield');

                $target_datatype = $target_datafield->getDataType();
                if ( !is_null($target_datatype->getDeletedAt()) )
                    throw new ODRNotFoundException('Datatype');

                if ( $theme->getDataType()->getId() !== $target_datatype->getId() )
                    throw new ODRBadRequestException('Invalid Theme');

                $all_render_plugin_instances = $repo_render_plugin_instance->findBy(
                    array(
                        'dataField' => $target_datafield->getId()
                    )
                );

                $changing_datafield_plugin = true;
            }

            /** @var RenderPlugin $selected_render_plugin */
            $selected_render_plugin = $repo_render_plugin->find($selected_plugin_id);
            if ( is_null($selected_render_plugin) )
                throw new ODRNotFoundException('RenderPlugin');

            // Need to know whether the user just saved changes to a plugin the datatype/datafield
            //  is already using...
            $selected_render_plugin_instance = null;
            foreach ($all_render_plugin_instances as $rpi) {
                /** @var RenderPluginInstance $rpi */
                if ( $rpi->getRenderPlugin()->getId() === $selected_render_plugin->getId() )
                    $selected_render_plugin_instance = $rpi;
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $target_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure the user isn't trying to save the wrong type of RenderPlugin
            $is_datatype_plugin = false;
            if ( $selected_render_plugin->getPluginType() == RenderPlugin::DATATYPE_PLUGIN
                || $selected_render_plugin->getPluginType() == RenderPlugin::THEME_ELEMENT_PLUGIN
                || $selected_render_plugin->getPluginType() === RenderPlugin::ARRAY_PLUGIN
            ) {
                $is_datatype_plugin = true;
            }

            $is_datafield_plugin = false;
            if ( $selected_render_plugin->getPluginType() == RenderPlugin::DATAFIELD_PLUGIN )
                $is_datafield_plugin = true;

            if ( $changing_datatype_plugin && $is_datafield_plugin)
                throw new ODRBadRequestException('Unable to save a Datafield plugin to a Datatype');
            else if ( $changing_datafield_plugin && $is_datatype_plugin )
                throw new ODRBadRequestException('Unable to save a Datatype plugin to a Datafield');


            // Ensure that the options listed in the post belong to the correct render plugin
            /** @var RenderPluginOptionsDef[] $render_plugin_options */
            $render_plugin_options = $repo_render_plugin_options->findBy( array('renderPlugin' => $selected_render_plugin) );

            $rpo_lookup = array();
            foreach ($render_plugin_options as $num => $rpo) {
                // Layout-specific options should be the only options submitted as part of this form
                if ( $rpo->getUsesLayoutSettings() ) {
                    $rpo_id = $rpo->getId();
                    $rpo_lookup[$rpo_id] = $rpo;

                    // Ensure all required options for this RenderPlugin are listed in the $_POST
                    if ( !isset($plugin_options[$rpo_id]) )
                        throw new ODRBadRequestException('Invalid Form...missing option mapping');
                }
            }
            if ( count($rpo_lookup) !== count($plugin_options) )
                throw new ODRBadRequestException('Invalid Form...incorrect number of options mapped');


            // ----------------------------------------
            // Save any changes to the RenderPluginOptions mapping
            $plugin_settings_changed = array();

            foreach ($plugin_options as $rpo_id => $value) {
                // Attempt to locate the existing RenderPluginThemeOptionsMap entity
                /** @var RenderPluginThemeOptionsMap $render_plugin_theme_option_map */
                $render_plugin_theme_option_map = $repo_render_plugin_theme_options_map->findOneBy(
                    array(
                        'renderPluginInstance' => $selected_render_plugin_instance->getId(),
                        'renderPluginOptionsDef' => $rpo_id,    // TODO - rename to renderPluginOptions
                        'theme' => $theme->getId(),
                    )
                );

                // If the RenderPluginOptionsMap entity doesn't exist, create it
                if ( is_null($render_plugin_theme_option_map) ) {
                    // Load the RenderPluginOptions object being referenced
                    /** @var RenderPluginOptionsDef $render_plugin_option */
                    $render_plugin_option = $rpo_lookup[$rpo_id];

                    $entity_create_service->createRenderPluginThemeOptionsMap($user, $selected_render_plugin_instance, $render_plugin_option, $theme, $value, true);    // don't need to flush...
                    $plugin_settings_changed[] = $render_plugin_option->getName();
                }
                else {
                    // ...otherwise, update the existing entity
                    $properties = array(
                        'value' => $value
                    );
                    $changes_made = $entity_modify_service->updateRenderPluginThemeOptionsMap($user, $render_plugin_theme_option_map, $properties, true);    // don't need to flush...

                    if ($changes_made)
                        $plugin_settings_changed[] = $render_plugin_theme_option_map->getRenderPluginOptionsDef()->getName();
                }
            }

            // RenderPluginThemeOptionsMap entries are not handled here, this is only for setting
            //  "global" options


            // ----------------------------------------
            // Should be able to flush here
            $em->flush();

            if ( !empty($plugin_settings_changed) ) {
                // Need to always update this entry, since these settings are stored in the theme
                $theme_info_service->updateThemeCacheEntry($theme, $user);

                // Some render plugins need to do stuff when their settings get changed
                // e.g. Graph plugins deleting cached graph images
//                $changed_fields = array_merge($plugin_fields_added, $plugin_fields_changed);
//
//                // This is wrapped in a try/catch block because any uncaught exceptions thrown
//                //  by the event subscribers will prevent further progress...
//                try {
//                    $event = new PluginOptionsChangedEvent($selected_render_plugin_instance, $user, $changed_fields, $plugin_settings_changed);
//                    $dispatcher->dispatch(PluginOptionsChangedEvent::NAME, $event);
//                }
//                catch (\Exception $e) {
//                    // ...don't particularly want to rethrow the error since it'll interrupt
//                    //  everything downstream of the event (such as file encryption...), but
//                    //  having the error disappear is less ideal on the dev environment...
////                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
////                        throw $e;
//                }
            }

            if ( !empty($plugin_settings_changed) ) {
                // Also need to ensure that changes to plugin settings update the "master_revision"
                //  property of template datafields/datatypes
                if ($local_datafield_id == 0) {
                    if ($target_datatype->getIsMasterType())
                        $entity_modify_service->incrementDatatypeMasterRevision($user, $target_datatype);
                }
                else {
                    if ($target_datafield->getIsMasterField())
                        $entity_modify_service->incrementDatafieldMasterRevision($user, $target_datafield);
                }

                // Mark the datatype as updated
                try {
                    $event = new DatatypeModifiedEvent($target_datatype, $user);
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }


            // ----------------------------------------
            // Ensure datafield properties are up to date
//            $datatype_array = $database_info_service->getDatatypeArray($target_datatype->getGrandparent()->getId());
//            $datafield_properties = json_encode($datafield_info_service->getDatafieldProperties($datatype_array));

            $return['d'] = array(
                'datafield_id' => $local_datafield_id,
                'datatype_id' => $local_datatype_id,

//                'datafield_properties' => $datafield_properties,
            );
        }
        catch (\Exception $e) {
            $source = 0xce09564a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
