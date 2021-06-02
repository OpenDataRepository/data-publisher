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

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

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
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
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
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symphony
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// YAML Parsing
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;


class PluginsController extends ODRCustomController
{

    /**
     * Scans the base directory for the RenderPlugin executable and config files, and returns an
     * array of all available Render Plugins...the array keys are plugin classnames, and the values
     * are arrays of parsed yml data.
     *
     * If a specific plugin classname is passed in, then this function only will validate and return
     * that single plugin.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string $target_plugin_classname
     *
     * @throws ODRException
     *
     * @return array
     */
    private function getAvailablePlugins($em, $target_plugin_classname = '')
    {
        // ----------------------------------------
        // Going to need an array of all valid typeclasses in order to validate the plugin config files
        $query = $em->createQuery(
           'SELECT ft.typeClass AS type_class
            FROM ODRAdminBundle:FieldType AS ft'
        );
        $results = $query->getArrayResult();

        $all_fieldtypes = array();
        foreach ($results as $result)
            $all_fieldtypes[ $result['type_class'] ] = 1;
        $all_fieldtypes = array_keys($all_fieldtypes);


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
            if ( strrpos($plugin_category, '.php', -4) !== false )
                continue;

            $plugin_directory = $plugin_base_dir.'/'.$plugin_category;
            foreach ( scandir($plugin_directory) as $filename ) {
                // TODO - assumes linux?
                if ($filename === '.' || $filename === '..')
                    continue;

                // Only want names of php files...
                if ( strrpos($filename, '.php', -4) !== false ) {
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
            self::validatePluginConfig($plugin_classname, $plugin_config, $all_fieldtypes, $all_events);

        return $available_plugins;
    }


    /**
     * Attempts to ensure the provided array is a valid configuration file for a RenderPlugin
     *
     * @param string $plugin_classname
     * @param array $plugin_config
     * @param array $all_fieldtypes
     * @param array $all_events
     *
     * @throws ODRException
     */
    private function validatePluginConfig($plugin_classname, $plugin_config, $all_fieldtypes, $all_events)
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
            'datatype',
            'render',
            'version',
            'override_fields',
            'override_child',
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
        $is_datatype_plugin = true;
        if ( $plugin_config['datatype'] === '' || $plugin_config['datatype'] === false )
            $is_datatype_plugin = false;

        // Ensure the plugin file implements the correct interface...
        if ( $is_datatype_plugin && !($plugin_service instanceof DatatypePluginInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" claims to be a Datatype plugin, but the RenderPlugin class "'.get_class($plugin_service).'" does not implement DatatypePluginInterface');
        else if ( !$is_datatype_plugin && !($plugin_service instanceof DatafieldPluginInterface) )
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" claims to be a Datafield plugin, but the RenderPlugin class "'.get_class($plugin_service).'" does not implement DatafieldPluginInterface');


        if ( $is_datatype_plugin || $plugin_config['name'] === 'Default Render' ) {
            // A datatype plugin doesn't need to define any required fields, though it's of limited
            //  use in that case
        }
        else if ( !$is_datatype_plugin && count($plugin_config['required_fields']) !== 1 ) {
            // A datafield plugin must define exactly one "required_field"
            throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is a Datafield Plugin and must define exactly one entry in the "required_fields" option');
        }


        // ----------------------------------------
        // If there are entries in the "required_field" key, ensure they are properly formed
        $required_keys = array(
            'name',
            'description',
            'type'
        );

        if ( is_array($plugin_config['required_fields']) ) {
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
                    if ( !in_array($ft, $all_fieldtypes) )
                        throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", required_field "'.$field_id.'" allows invalid fieldtype typeclass "'.$ft.'"');
                }
            }
        }


        // ----------------------------------------
        // If there are entries in the "config_options" key, ensure they are properly formed
        $required_keys = array(
            'name',
//            'type',    // TODO - unused...delete this?
            'default',
//            'choices',    // this is optional
            'description',
//            'applies_to'    // TODO - not implemented...delete this?
        );

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
            }
        }


        // ----------------------------------------
        // If there are entries in the "registered_events" key, ensure they are properly formed
        if ( is_array($plugin_config['registered_events']) ) {
            foreach ($plugin_config['registered_events'] as $event => $callable) {
                // Ensure the events listed in the plugin config exist...
                if ( !isset($all_events[$event]) )
                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" references an invalid ODR Event "'.$event.'"');

                // ...and ensure the callables attached to the events also exist
                if ( !method_exists($plugin_service, $callable) )
                    throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", the Event "'.$event.'" does not reference a callable function');
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

            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // ----------------------------------------

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
            $available_plugins = self::getAvailablePlugins($em);

            // Determine whether any of the installed render plugins differ from their config files
            $plugins_with_updates = self::getPluginDiff($em, $installed_plugins, $available_plugins);

            // Render and return a page displaying the installed/available plugins
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Plugins:list_plugins.html.twig',
                    array(
                        'installed_plugins' => $installed_plugins,
                        'available_plugins' => $available_plugins,

                        'plugins_with_updates' => $plugins_with_updates,
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
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $installed_plugins
     * @param array $available_plugins
     *
     * @throws ODRException
     *
     * @return array
     */
    private function getPluginDiff($em, $installed_plugins, $available_plugins)
    {
        // ----------------------------------------
        // Going to need to be able to convert typeclasses into fieldtype ids
        $query = $em->createQuery(
           'SELECT ft.id AS ft_id, ft.typeClass AS type_class
            FROM ODRAdminBundle:FieldType AS ft'
        );
        $results = $query->getArrayResult();

        $all_fieldtypes = array();
        foreach ($results as $result)
            $all_fieldtypes[ $result['ft_id'] ] = $result['type_class'];


        // ----------------------------------------
        $plugins_needing_updates = array();
        foreach ($installed_plugins as $plugin_classname => $installed_plugin_data) {
            // Complain when an "installed" plugin appears to not be available
            if ( !isset($available_plugins[$plugin_classname]) )
                throw new ODRException('The render plugin "'.$plugin_classname.'" "'.$installed_plugin_data['pluginName'].'" is missing its config file on the server');

            $plugin_config = $available_plugins[$plugin_classname];

            // ----------------------------------------
            // Check the non-array properties of the render plugin...
            $plugins_needing_updates[$plugin_classname] = array();
            if ( $installed_plugin_data['pluginName'] !== $plugin_config['name'] )
                $plugins_needing_updates[$plugin_classname]['properties'][] = 'name';

            if ( $installed_plugin_data['description'] !== $plugin_config['description'] )
                $plugins_needing_updates[$plugin_classname]['properties'][] = 'description';

            if ( $installed_plugin_data['category'] !== $plugin_config['category'] )
                $plugins_needing_updates[$plugin_classname]['properties'][] = 'category';

            if ( $installed_plugin_data['render'] !== $plugin_config['render'] )
                $plugins_needing_updates[$plugin_classname]['properties'][] = 'render';

            if ( $installed_plugin_data['overrideChild'] !== $plugin_config['override_child'] )
                $plugins_needing_updates[$plugin_classname]['properties'][] = 'override_child';

            if ( $installed_plugin_data['overrideFields'] !== $plugin_config['override_fields'] )
                $plugins_needing_updates[$plugin_classname]['properties'][] = 'override_fields';


            // Should doublecheck the plugin type too...
            $plugin_type = null;
            if ( $plugin_config['datatype'] === true )
                $plugin_type = RenderPlugin::DATATYPE_PLUGIN;
            else
                $plugin_type = RenderPlugin::DATAFIELD_PLUGIN;

            if ( $installed_plugin_data['plugin_type'] !== $plugin_type )
                $plugins_needing_updates[$plugin_classname]['properties'][] = 'plugin_type';


            // ----------------------------------------
            // Determine whether any renderPluginFields need to be created/updated/deleted...
            $tmp = array();

            foreach ( $installed_plugin_data['renderPluginFields'] as $num => $rpf) {
                // TODO - is there a way to have a better key than fieldName?
                $allowed_fieldtypes = array();

                $ft_ids = explode(',', $rpf['allowedFieldtypes']);
                foreach ($ft_ids as $ft_id)
                    $allowed_fieldtypes[] = $all_fieldtypes[$ft_id];
                sort($allowed_fieldtypes);

                $tmp[ $rpf['fieldName'] ] = array(
                    'description' => $rpf['description'],
                    'allowed_fieldtypes' => $allowed_fieldtypes,
                );
            }

            if ( is_array($plugin_config['required_fields']) ) {
                foreach ($plugin_config['required_fields'] as $key => $data) {
                    $fieldname = $data['name'];
                    $allowed_fieldtypes = explode('|', $data['type']);

                    if ( !isset($tmp[$fieldname]) ) {
                        // This field doesn't exist in the database, make an entry for it
                        $tmp[$fieldname] = array(
                            'description' => $data['description'],
                            'allowed_fieldtypes' => $allowed_fieldtypes,
                        );
                    }
                    else {
                        // This field exists in the database...
                        $existing_data = $tmp[$fieldname];

                        if ( $existing_data['description'] === $data['description'] )
                            unset( $tmp[$fieldname]['description'] );

                        // Need to use array_diff() both ways in order to catch when the database is
                        //  a subset of the config file, or vice versa
                        $diff_1 = array_diff($tmp[$fieldname]['allowed_fieldtypes'], $allowed_fieldtypes);
                        $diff_2 = array_diff($allowed_fieldtypes, $tmp[$fieldname]['allowed_fieldtypes']);

                        if ( count($diff_1) == 0 && count($diff_2) == 0 )
                            unset( $tmp[$fieldname]['allowed_fieldtypes'] );


                        // If there are no differences, remove the entry
                        if ( count($tmp[$fieldname]) == 0 )
                            unset( $tmp[$fieldname] );
                    }
                }
            }

            // If any entries remain in the temporary array, then the config file does not match
            //  the database
            if ( count($tmp) > 0 )
                $plugins_needing_updates[$plugin_classname]['required_fields'] = $tmp;


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

                // This entry is optional in the config file, so only check it when it's not null
                if ( !is_null($rpo['choices']) )
                    $tmp[ $rpo['name'] ]['choices'] = $rpo['choices'];
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

                        // This entry is optional, so have to check whether it exists first
                        if ( isset($data['choices']) )
                            $tmp[$option_key]['choices'] = $data['choices'];
                    }
                    else {
                        // This option exists in the database...
                        $existing_data = $tmp[$option_key];

                        if ( $existing_data['name'] === $data['name'] )
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
                        else if ( $existing_data['default'] === $data['default'] )
                            unset( $tmp[$option_key]['default'] );

                        if ( isset($data['choices']) ) {
                            if ( $existing_data['choices'] === $data['choices'] )
                                unset( $tmp[$option_key]['choices'] );
                        }

                        if ( $existing_data['description'] === $data['description'] )
                            unset( $tmp[$option_key]['description'] );


                        // If there are no differences, remove the entry
                        if ( count($tmp[$option_key]) == 0 )
                            unset( $tmp[$option_key] );
                    }
                }
            }

            // If any entries remain in the temporary array, then the config file does not match
            //  the database
            if ( count($tmp) > 0 )
                $plugins_needing_updates[$plugin_classname]['config_options'] = $tmp;


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
                    }
                    else {
                        // This event exists in the database...
                        $existing_callable = $tmp[$event_name];

                        // If the callable in the database is the same as the callable in the
                        //  config file, then it doesn't need to be updated
                        if ( $existing_callable === $event_callable )
                            unset( $tmp[$event_name] );
                    }
                }
            }

            // If any entries remain in the temporary array, then the config file does not match
            //  the database
            if ( count($tmp) > 0 )
                $plugins_needing_updates[$plugin_classname]['required_events'] = $tmp;
        }


        // ----------------------------------------
        // If a plugin doesn't actually have any changes, it doesn't need an update...
        foreach ($plugins_needing_updates as $plugin_classname => $data) {
            if ( count($data) == 0 )
                unset( $plugins_needing_updates[$plugin_classname] );
        }
        return $plugins_needing_updates;
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
            $available_plugins = self::getAvailablePlugins($em, $plugin_classname);
            if ( !isset($available_plugins[$plugin_classname]) )
                throw new ODRBadRequestException('Unable to install a non-existant RenderPlugin');


            // ----------------------------------------
            // Pull the plugin's data from the config file
            $plugin_data = $available_plugins[$plugin_classname];

            // Create a new RenderPlugin entry from the config file data
            $render_plugin = new RenderPlugin();
            $render_plugin->setPluginName( $plugin_data['name'] );
            $render_plugin->setDescription( $plugin_data['description'] );
            $render_plugin->setCategory( $plugin_data['category'] );
            $render_plugin->setPluginClassName( $plugin_classname );
            $render_plugin->setActive(true);

            if ( $plugin_data['render'] === false )    // Yaml parser sets this to true/false values
                $render_plugin->setRender(false);
            else
                $render_plugin->setRender(true);

            if ( $plugin_data['datatype'] === false )
                $render_plugin->setPluginType( RenderPlugin::DATAFIELD_PLUGIN );
            else
                $render_plugin->setPluginType( RenderPlugin::DATATYPE_PLUGIN );

            if ( $plugin_data['override_fields'] === false )    // Yaml parser sets these to true/false values
                $render_plugin->setOverrideFields(false);
            else
                $render_plugin->setOverrideFields(true);

            if ( $plugin_data['override_child'] === false )
                $render_plugin->setOverrideChild(false);
            else
                $render_plugin->setOverrideChild(true);

            $render_plugin->setCreatedBy($user);
            $render_plugin->setUpdatedBy($user);

            $em->persist($render_plugin);


            // ----------------------------------------
            // Create RenderPluginField entries from the config file data
            if ( is_array($plugin_data['required_fields']) ) {
                foreach ($plugin_data['required_fields'] as $identifier => $data) {
                    $rpf = new RenderPluginFields();
                    $rpf->setRenderPlugin($render_plugin);
                    $rpf->setFieldName($data['name']);
                    $rpf->setDescription($data['description']);
                    $rpf->setActive(true);

                    $allowed_fieldtypes = array();
                    $typeclasses = explode('|', $data['type']);
                    foreach ($typeclasses as $typeclass) {
                        $query = $em->createQuery(
                           'SELECT ft.id AS fieldtype_id
                            FROM ODRAdminBundle:FieldType AS ft
                            WHERE ft.typeClass = :type_class'
                        )->setParameters(array('type_class' => $typeclass));
                        $sub_result = $query->getArrayResult();

                        $allowed_fieldtypes[] = $sub_result[0]['fieldtype_id'];
                    }
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
                    $rpo->setName($option_key);
                    $rpo->setDisplayName($data['name']);
                    $rpo->setDescription($data['description']);

                    // The "default" key could have boolean values...
                    if ($data['default'] === true)
                        $rpo->setDefaultValue("true");
                    else if ($data['default'] === false)
                        $rpo->setDefaultValue("false");
                    else
                        $rpo->setDefaultValue($data['default']);

                    // The "choices" key is optional
                    if ( isset($data['choices']) )
                        $rpo->setChoices($data['choices']);

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
            $available_plugins = self::getAvailablePlugins($em, $plugin_classname);
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
            )->setParameters(array('render_plugin_id' => $render_plugin->getId()));
            $results = $query->getArrayResult();

            $installed_plugins = array();
            foreach ($results as $result) {
                $plugin_classname = $result['pluginClassName'];

                $installed_plugins[$plugin_classname] = $result;
            }

            $plugins_with_updates = self::getPluginDiff($em, $installed_plugins, $available_plugins);
            if ( !isset($plugins_with_updates[$plugin_classname]) )
                throw new ODRException('This RenderPlugin has no updates');
//            $plugin_changes = $plugins_with_updates[$plugin_classname];


            // ----------------------------------------
            // Update the existing RenderPlugin entry from the config file data
            $render_plugin->setPluginName( $plugin_data['name'] );
            $render_plugin->setDescription( $plugin_data['description'] );
            $render_plugin->setCategory( $plugin_data['category'] );
            $render_plugin->setPluginClassName( $plugin_classname );
            $render_plugin->setActive(true);

            if ( $plugin_data['render'] === false )    // Yaml parser sets this to true/false values
                $render_plugin->setRender(false);
            else
                $render_plugin->setRender(true);

            if ( $plugin_data['datatype'] === false )
                $render_plugin->setPluginType( RenderPlugin::DATAFIELD_PLUGIN );
            else
                $render_plugin->setPluginType( RenderPlugin::DATATYPE_PLUGIN );

            if ( $plugin_data['override_fields'] === false )    // Yaml parser sets these to true/false values
                $render_plugin->setOverrideFields(false);
            else
                $render_plugin->setOverrideFields(true);

            if ( $plugin_data['override_child'] === false )
                $render_plugin->setOverrideChild(false);
            else
                $render_plugin->setOverrideChild(true);

            // Want the render plugin to always get marked as updated, even if it's it's just the
            //  fields/options/events getting changed
            $render_plugin->setUpdatedBy($user);

            $em->persist($render_plugin);


            // ----------------------------------------
            // Determine if any RenderPluginField entries in the database aren't in the config file
            $fields_to_delete = array();
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
                    $fields_to_delete[] = $rpf->getId();
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
                    $rpf->setFieldName( $data['name'] );
                    $rpf->setDescription( $data['description'] );
                    $rpf->setActive(true);

                    $allowed_fieldtypes = array();
                    $typeclasses = explode('|', $data['type']);
                    foreach ($typeclasses as $typeclass) {
                        $query = $em->createQuery(
                           'SELECT ft.id AS fieldtype_id
                            FROM ODRAdminBundle:FieldType AS ft
                            WHERE ft.typeClass = :type_class'
                        )->setParameters(array('type_class' => $typeclass));
                        $sub_result = $query->getArrayResult();

                        $allowed_fieldtypes[] = $sub_result[0]['fieldtype_id'];
                    }
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
                    $rpo->setName($option_key);
                    $rpo->setDisplayName($data['name']);
                    $rpo->setDescription($data['description']);

                    // The "default" key could have boolean values...
                    if ($data['default'] === true)
                        $rpo->setDefaultValue("true");
                    else if ($data['default'] === false)
                        $rpo->setDefaultValue("false");
                    else
                        $rpo->setDefaultValue($data['default']);

                    // The "choices" key is optional
                    if ( isset($data['choices']) )
                        $rpo->setChoices($data['choices']);

                    if ($creating) {
                        $rpo->setCreatedBy($user);
                        $render_plugin->addRenderPluginOptionsDef($rpo);
                    }

                    $rpo->setUpdatedBy($user);

                    $em->persist($rpo);
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

            if ( count($fields_to_delete) > 0 ) {
                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:RenderPluginFields rpf
                    SET rpf.deletedAt = :now
                    WHERE rpf IN (:render_plugin_fields)
                    AND rpf.deletedAt IS NULL'
                )->setParameters(array('now' => new \DateTime(), 'render_plugin_fields' => $fields_to_delete));
                $rowsAffected = $query->execute();

                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:RenderPluginMap rpm
                    SET rpm.deletedAt = :now
                    WHERE rpm.renderPluginFields IN (:render_plugin_fields)
                    AND rpm.deletedAt IS NULL'
                )->setParameters(array('now' => new \DateTime(), 'render_plugin_fields' => $fields_to_delete));
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
                    if ( isset($result['dataField']) )
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
     * Builds and returns a list of available Render Plugins for a DataType or a DataField.
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

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
                // If datafield id isn't defined, this is a render plugin for a datatype

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
//            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
//                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            $is_datatype_admin = $pm_service->isDatatypeAdmin($user, $datatype);
            // --------------------


            $render_plugins = null;
            $render_plugin_instances = null;

            if ($datafield_id == 0) {
                // If datafield id isn't defined, this is a request for a datatype...load all
                //  available datatype render plugins
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
                // ...otherwise, this is a request for a datafield...load all available datafield
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
                else if ( $rpi->getRenderPlugin()->getRender() === true ) {
                    // ...if the datatype/datafield is using more than one plugin, then preferentially
                    //  load the data for the plugin that actually renders something
                    $plugin_to_load = $rp_id;

                    // There should only be one plugin per datatype/datafield that actually renders
                    //  something
                }
            }


            // ----------------------------------------
            // Get Templating Object
            $templating = $this->get('templating');
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Ensure the relevant entities exist
            /** @var DataType $datatype */
            $datatype = null;
            /** @var DataFields|null $datafield */
            $datafield = null;
            /** @var DataFields[]|null $all_datafields */
            $all_datafields = null; // of datatype

            if ($datafield_id == 0) {
                // This is a render plugin for a datatype
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
//            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
//                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            $is_datatype_admin = $pm_service->isDatatypeAdmin($user, $datatype);
            // --------------------


            // ----------------------------------------
            // Going to need an array of all fieldtype entries to perform verification
            $query = $em->createQuery(
               'SELECT ft.id, ft.typeClass
                FROM ODRAdminBundle:FieldType ft'
            );
            $results = $query->getArrayResult();

            $all_fieldtypes = array();
            foreach ($results as $ft)
                $all_fieldtypes[ $ft['id'] ] = $ft['typeClass'];


            // ----------------------------------------
            // Load the details on the requested RenderPlugin
            // TODO - change to RenderPluginOptions
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
                $allowed_fieldtypes[$rpf_id] = array();

                $tmp = explode(',', $rpf['allowedFieldtypes']);
                foreach ($tmp as $ft_id)
                    $allowed_fieldtypes[$rpf_id][] = intval($ft_id);
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


            // ----------------------------------------
            // Need to load the most recent renderPluginInstance for each renderPlugin ever used
            //  by this datatype/datafield...so have to temporarily disable softdeleteable
            $em->getFilters()->disable('softdeleteable');

            $query = null;
            if ( is_null($datafield) ) {
                $query = $em->createQuery(
                   'SELECT rpi, rp
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    JOIN rpi.renderPlugin AS rp
                    WHERE rpi.dataType = :dataType'
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
                    WHERE rpi.dataField = :dataField'
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

            // If the datatype/datafield has (or had) an instance for this render plugin, then load
            //  the renderPluginFieldsMap and renderPluginOptionsMap entries for this instance
            $render_plugin_instance = null;
            if ( !is_null($current_render_plugin_instance) ) {
                // TODO - change to RenderPluginOptions
                $query = $em->createQuery(
                   'SELECT rpi, rp, rpm, partial rpf.{id}, partial rpm_df.{id}, rpom, partial rpo.{id}
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    LEFT JOIN rpi.renderPlugin rp
                    LEFT JOIN rpi.renderPluginMap rpm
                    LEFT JOIN rpm.renderPluginFields rpf
                    LEFT JOIN rpm.dataField rpm_df
                    LEFT JOIN rpi.renderPluginOptionsMap rpom
                    LEFT JOIN rpom.renderPluginOptionsDef rpo
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
            // Should also check whether the datatype/datafield is allowed to use this render plugin
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
                        $illegal_render_plugin_message = 'This Render Plugin is not compatible with a "'.$datafield->getFieldType()->getTypeClass().'" Datafield';
                    }
                }
            }


            // The datatype/datafield can only use one render plugin that "renders" stuff at a time
            $twig_render_plugin_id = null;
            $twig_render_plugin_name = null;
            if ( !empty($all_render_plugin_instances) ) {
                foreach ($all_render_plugin_instances as $rpi) {
                    // $all_render_plugin_instances might have deleted rpi entries in it
                    if ( is_null($rpi['deletedAt']) && $rpi['renderPlugin']['render'] === true ) {
                        $twig_render_plugin_id = $rpi['renderPlugin']['id'];
                        $twig_render_plugin_name = $rpi['renderPlugin']['pluginName'];
                    }
                }
            }

            // So, if the datatype/datafield is using a render plugin that "renders" stuff...
            if ( !is_null($twig_render_plugin_id) ) {
                // ...and the plugin requested by the controller action doesn't match the current
                //  plugin that's "rendering" stuff...
                if ( $target_render_plugin->getId() !== $twig_render_plugin_id && $render_plugin['render'] === true ) {
                    // ...then the datatype/datafield is not allowed to also use this plugin
                    $is_illegal_render_plugin = true;
                    $illegal_render_plugin_message = 'This Render Plugin cannot be used at the same time as the "'.$twig_render_plugin_name.'" Render Plugin';
                }
            }


            // ----------------------------------------
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Plugins:plugin_settings_dialog_form_data.html.twig',
                    array(
                        'all_fieldtypes' => $all_fieldtypes,

                        'local_datatype' => $datatype,
                        'local_datafield' => $datafield,
                        'datafields' => $all_datafields,
                        'is_datatype_admin' => $is_datatype_admin,

                        'render_plugin' => $render_plugin,
                        'allowed_fieldtypes' => $allowed_fieldtypes,
                        'render_plugin_instance' => $render_plugin_instance,

                        'is_illegal_render_plugin' => $is_illegal_render_plugin,
                        'illegal_render_plugin_message' => $illegal_render_plugin_message,
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
     * Detaches a render plugin from the datafield/datatype.
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
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var DatafieldInfoService $dfi_service */
            $dfi_service = $this->container->get('odr.datafield_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Ensure the relevant entities exist
            /** @var DataType $datatype */
            $datatype = null;
            /** @var DataFields|null $datafield */
            $datafield = null;

            if ($datafield_id == 0) {
                // This is a render plugin for a datatype
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


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
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
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
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


            // Remove/detach this plugin instance from the datafield/datatype
            $em->remove($rpi);
            $em->flush();


            // ----------------------------------------
            // Now that all the database changes have been made, wipe the relevant cache entries
            $dbi_service->updateDatatypeCacheEntry($datatype, $user);

            // Changes in render plugin tend to require changes in datafield properties
            $datatype_array = $dbi_service->getDatatypeArray($datatype->getGrandparent()->getId());
            // Don't need to filter here
            $datafield_properties = json_encode($dfi_service->getDatafieldProperties($datatype_array));

            $return['d'] = array(
                'datafield_id' => $datafield_id,     // the entity may be null, so use the param that was passed in
                'datatype_id' => $datatype->getId(), // this entity won't be null

                'datafield_properties' => $datafield_properties,
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

            $plugin_fieldtypes = array();
            if ( isset($post['plugin_fieldtypes']) )
                $plugin_fieldtypes = $post['plugin_fieldtypes'];

            $plugin_map = array();
            if ( isset($post['plugin_map']) )
                $plugin_map = $post['plugin_map'];

            $plugin_options = array();
            if ( isset($post['plugin_options']) )
                $plugin_options = $post['plugin_options'];


            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatafieldInfoService $dfi_service */
            $dfi_service = $this->container->get('odr.datafield_info_service');
            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptionsDef');    // TODO - rename to RenderPluginOptions
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
                // Changing the render plugin for a datatype...
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
            if ( !$pm_service->isDatatypeAdmin($user, $target_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure the user isn't trying to save the wrong type of RenderPlugin
            if ( $changing_datatype_plugin
                && $selected_render_plugin->getPluginType() == RenderPlugin::DATATYPE_PLUGIN
                && is_null($target_datatype)
            ) {
                throw new ODRBadRequestException('Unable to save a Datatype plugin to a Datafield');
            }
            else if ( $changing_datafield_plugin
                && $selected_render_plugin->getPluginType() == RenderPlugin::DATAFIELD_PLUGIN
                && is_null($target_datafield)
            ) {
                throw new ODRBadRequestException('Unable to save a Datafield plugin to a Datatype');
            }


            // If the datatype/datafield is already "rendering" something with a plugin...
            $already_renders = null;
            foreach ($all_render_plugin_instances as $rpi) {
                /** @var RenderPluginInstance $rpi */
                if ( $rpi->getRenderPlugin()->getRender() === true )
                    $already_renders = $rpi;
            }

            if ( !is_null($already_renders) ) {
                // ...then ensure the user didn't just attempt to attach a second plugin that also
                //  "renders" something
                if ( $already_renders->getRenderPlugin()->getId() !== $selected_render_plugin->getId()
                    && $selected_render_plugin->getRender() === true
                ) {
                    throw new ODRBadRequestException('Only allowed to have a single Plugin that actually "renders" at a time');
                }
            }


            // Ensure the plugin map doesn't have the same datafield mapped to multiple renderPluginFields
            $mapped_datafields = array();
            foreach ($plugin_map as $rpf_id => $df_id) {
                if ($df_id != '-1') {
                    if ( isset($mapped_datafields[$df_id]) )
                        throw new ODRBadRequestException('Invalid Form...multiple datafields mapped to the same renderpluginfield');

                    $mapped_datafields[$df_id] = 0;
                }
            }

            // Ensure the datafields listed in the plugin map are of the correct fieldtype, and that
            //  all of the fields required by the plugin are mapped
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
                if ($plugin_map[$rpf_id] == '-1' && !isset($plugin_fieldtypes[$rpf_id]) )
                    throw new ODRBadRequestException('Invalid Form...missing fieldtype mapping');

                if ($plugin_map[$rpf_id] != '-1') {
                    // Ensure all required datafields have a valid fieldtype
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
                }
            }

            // Ensure that the options listed in the post belong to the correct render plugin
            /** @var RenderPluginOptionsDef[] $render_plugin_options */
            $render_plugin_options = $repo_render_plugin_options->findBy( array('renderPlugin' => $selected_render_plugin) );
            if ( count($render_plugin_options) !== count($plugin_options) )
                throw new ODRBadRequestException('Invalid Form...incorrect number of options mapped');

            // Might as well store these in a lookup array at the same time, so we don't have to
            //  keep reloading them
            $rpo_lookup = array();
            foreach ($render_plugin_options as $rpo) {
                $rpo_id = $rpo->getId();
                $rpo_lookup[$rpo_id] = $rpo;

                // Ensure all required options for this RenderPlugin are listed in the $_POST
                if ( !isset($plugin_options[$rpo_id]) )
                    throw new ODRBadRequestException('Invalid Form...missing option mapping');
            }


            $plugin_fields_added = false;
            $plugin_fields_changed = false;
            $plugin_settings_changed = false;
            $reload_datatype = false;


            // ----------------------------------------
            // Create any new datafields required
            $theme = $theme_service->getDatatypeMasterTheme($target_datatype->getId());

            $theme_element = null;
            foreach ($plugin_fieldtypes as $rpf_id => $ft_id) {
                // Since new datafields are being created, instruct ajax success handler in
                //  plugin_settings_dialog.html.twig to call ReloadChild() afterwards
                $reload_datatype = true;

                // Create a single new ThemeElement to store the new datafields in, if necessary
                if ( is_null($theme_element) )
                    $theme_element = $ec_service->createThemeElement($user, $theme, true);

                // Load information for the new datafield
                /** @var FieldType $fieldtype */
                $fieldtype = $repo_fieldtype->find($ft_id);
                if ( is_null($fieldtype) )
                    throw new ODRBadRequestException('Invalid Form');

                /** @var RenderPluginFields $rpf */
                $rpf = $rpf_lookup[$rpf_id];

                // Create the Datafield and set basic properties from the render plugin settings
                $datafield = $ec_service->createDatafield($user, $target_datatype, $fieldtype, true);    // Don't flush immediately...

                $datafield_meta = $datafield->getDataFieldMeta();
                $datafield_meta->setFieldName( $rpf->getFieldName() );
                $datafield_meta->setDescription( $rpf->getDescription() );
                $em->persist($datafield_meta);


                // Attach the new datafield to the previously created theme_element
                $ec_service->createThemeDatafield($user, $theme_element, $datafield);    // need to flush here so $datafield->getID() works later

                // Now that the datafield exists, update the plugin map
                $em->refresh($datafield);
                $plugin_map[$rpf_id] = $datafield->getId();

                if ($fieldtype->getTypeClass() == 'Image')
                    $ec_service->createImageSizes($user, $datafield);    // TODO - test this...no render plugin creates an image at the moment
            }

            // If new datafields created, flush entity manager to save the theme_element and datafield meta entries
            if ($reload_datatype)
                $em->flush();


            // ----------------------------------------
            // If the datatype/datafield isn't already using this render plugin...
            if ( is_null($selected_render_plugin_instance) ) {
                // ...then create a renderPluginInstance entity tying the two together
                $selected_render_plugin_instance = $ec_service->createRenderPluginInstance($user, $selected_render_plugin, $target_datatype, $target_datafield);    // need to flush here
                /** @var RenderPluginInstance $selected_render_plugin_instance */
            }


            // ----------------------------------------
            // Save any changes to the RenderPluginField mapping
            foreach ($plugin_map as $rpf_id => $df_id) {
                // Attempt to locate the mapping for this render plugin field field in this instance
                /** @var RenderPluginMap $render_plugin_map */
                $render_plugin_map = $repo_render_plugin_map->findOneBy(
                    array(
                        'renderPluginInstance' => $selected_render_plugin_instance->getId(),
                        'renderPluginFields' => $rpf_id
                    )
                );

                // If the render plugin map entity doesn't exist, create it
                if ( is_null($render_plugin_map) ) {
                    // Locate the render plugin field object being referenced
                    /** @var RenderPluginFields $render_plugin_field */
                    $render_plugin_field = $rpf_lookup[$rpf_id];

                    // Locate the desired datafield object...already checked for its existence earlier
                    /** @var DataFields $df */
                    $df = $repo_datafield->find($df_id);

                    $ec_service->createRenderPluginMap($user, $selected_render_plugin_instance, $render_plugin_field, $target_datatype, $df, true);    // don't need to flush...
                    $plugin_fields_added = true;
                }
                else {
                    // ...otherwise, update the existing entity
                    $properties = array(
                        'dataField' => $df_id
                    );
                    $changes_made = $emm_service->updateRenderPluginMap($user, $render_plugin_map, $properties, true);    // don't need to flush...

                    if ($changes_made)
                        $plugin_fields_changed = true;
                }
            }

            // Save any changes to the RenderPluginOptions mapping
            foreach ($plugin_options as $rpo_id => $value) {
                // Attempt to locate the existing RenderPluginOptionsMap entity
                /** @var RenderPluginOptionsMap $render_plugin_option_map */
                $render_plugin_option_map = $repo_render_plugin_options_map->findOneBy(
                    array(
                        'renderPluginInstance' => $selected_render_plugin_instance->getId(),
                        'renderPluginOptionsDef' => $rpo_id,
                    )
                );
                // TODO - rename to renderPluginOptions

                // If the RenderPluginOptionsMap entity doesn't exist, create it
                if ( is_null($render_plugin_option_map) ) {
                    // Load the RenderPluginOptions object being referenced
                    /** @var RenderPluginOptionsDef $render_plugin_option */
                    $render_plugin_option = $rpo_lookup[$rpo_id];

                    $ec_service->createRenderPluginOptionsMap($user, $selected_render_plugin_instance, $render_plugin_option, $value, true);    // don't need to flush...
                    $plugin_settings_changed = true;
                }
                else {
                    // ...otherwise, update the existing entity
                    $properties = array(
                        'value' => $value
                    );
                    $changes_made = $emm_service->updateRenderPluginOptionsMap($user, $render_plugin_option_map, $properties, true);    // don't need to flush...

                    if ($changes_made)
                        $plugin_settings_changed = true;
                }
            }


            // ----------------------------------------
            // Should be able to flush here
            $em->flush();

            if ( $plugin_fields_added || $plugin_fields_changed || $plugin_settings_changed ) {
                // Some render plugins need to do stuff when their settings get changed
                // e.g. Graph plugins deleting cached graph images

                // This is wrapped in a try/catch block because any uncaught exceptions thrown
                //  by the event subscribers will prevent further progress...
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new PluginOptionsChangedEvent($selected_render_plugin_instance, $user);
                    $dispatcher->dispatch(PluginOptionsChangedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't particularly want to rethrow the error since it'll interrupt
                    //  everything downstream of the event (such as file encryption...), but
                    //  having the error disappear is less ideal on the dev environment...
                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
                        throw $e;
                }


                // ----------------------------------------
                // Due to changes being made, the cached datatype array needs to be rebuilt
                $dbi_service->updateDatatypeCacheEntry($target_datatype, $user);

                // Only need to update the theme entry if plugin fields were created
                if ( $plugin_fields_added )
                    $theme_service->updateThemeCacheEntry($theme, $user);


                // Also need to ensure that changes to plugin settings update the "master_revision"
                //  property of template datafields/datatypes
                if ($local_datafield_id == 0) {
                    if ($target_datatype->getIsMasterType())
                        $emm_service->incrementDatatypeMasterRevision($user, $target_datatype);
                }
                else {
                    if ($target_datafield->getIsMasterField())
                        $emm_service->incrementDatafieldMasterRevision($user, $target_datafield);
                }
            }


            // ----------------------------------------
            // Ensure datafield properties are up to date
            $datatype_array = $dbi_service->getDatatypeArray($target_datatype->getGrandparent()->getId());
            $datafield_properties = json_encode($dfi_service->getDatafieldProperties($datatype_array));

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
}
