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
use ODR\AdminBundle\Entity\RenderPluginFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatafieldInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symphony
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
     * @param \Doctrine\ORM\EntityManager $em
     * @param bool $validate
     *
     * @throws ODRException
     *
     * @return array
     */
    private function getAvailablePlugins($em, $validate = true)
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
        $available_plugins = array();
        $plugin_base_dir = $this->container->getParameter('odr_plugin_basedir');
        foreach ( scandir($plugin_base_dir) as $directory_name ) {
            // TODO - assumes linux?
            if ($directory_name === '.' || $directory_name === '..')
                continue;

            // Don't want the interfaces in the directory...
            if ( strrpos($directory_name, '.php', -4) !== false )
                continue;

            $plugin_directory = $plugin_base_dir.'/'.$directory_name;
            $abbreviated_directory = substr($plugin_directory, strpos($plugin_directory, 'src'));   // TODO - this makes assumptions about the directory path...
            foreach ( scandir($plugin_directory) as $filename ) {
                // TODO - assumes linux?
                if ($filename === '.' || $filename === '..')
                    continue;

                // Only want names of php files...
                if ( strrpos($filename, '.php', -4) !== false ) {
                    // ...in order to get to their yml config files...
                    $stub = substr($filename, 0, -4);
                    $config_filename = $stub.'.yml';
                    $abbreviated_config_path = $abbreviated_directory.'/'.$config_filename;

                    if ( !file_exists($plugin_directory.'/'.$config_filename) )
                        throw new ODRException('Could not find the RenderPlugin config file at "'.$abbreviated_config_path.'"');

                    // Attempt to parse the configuration file
                    try {
                        $yaml = Yaml::parse(file_get_contents($plugin_directory.'/'.$config_filename));

                        foreach ($yaml as $plugin_classname => $plugin_config) {
                            if ( isset($available_plugins[$plugin_classname] ) )
                                throw new ODRException('RenderPlugin config file "'.$abbreviated_config_path.'" attempts to redefine the RenderPlugin "'.$plugin_classname.'", previously defined in "'.$available_plugins[$plugin_classname]['filepath'].'"');

                            $available_plugins[$plugin_classname] = $plugin_config;

                            // Store the filepath in case of later duplicates
                            $available_plugins[$plugin_classname]['filepath'] = $abbreviated_config_path;

                            // Store a group name for the plugin to assist in sorting
                            $tmp = explode('.', $plugin_classname);
                            $available_plugins[$plugin_classname]['group'] = ucfirst( $tmp[1] );
                        }
                    }
                    catch (ParseException $e) {
                        // Apparently the default exception doesn't specify which file it occurs in...
                        throw new ODRException('Parse error in "'.$abbreviated_directory.'/'.$config_filename.'": '.$e->getMessage());
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
        if ($validate) {
            foreach ($available_plugins as $plugin_classname => $plugin_config)
                self::validatePluginConfig($plugin_config, $all_fieldtypes);
        }

        return $available_plugins;
    }


    /**
     * Attempts to ensure the provided array is a valid configuration file for a RenderPlugin
     *
     * @param array $plugin_config
     * @param array $all_fieldtypes
     *
     * @throws ODRException
     */
    private function validatePluginConfig($plugin_config, $all_fieldtypes)
    {
        // ----------------------------------------
        // All plugins must define these configuration options
        $required_keys = array(
            'name',
            'datatype',
            'version',
            'override_fields',
            'override_child',
            'description',
            'required_fields',
            'config_options',
        );

        foreach ($required_keys as $key) {
            if ( !array_key_exists($key, $plugin_config) )
                throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'" is missing the required key "'.$key.'"');
        }


        // ----------------------------------------
        // If this is a datafield plugin, then the config file must list exactly one "required_field"
        // A datatype plugin technically doesn't need any required fields, though it's of limited
        //  use in that case
        $is_datatype_plugin = true;
        if ( $plugin_config['datatype'] === '' )
            $is_datatype_plugin = false;

        if ( $plugin_config['name'] === 'Default Render' ) {
            /* do nothing */
        }
        else if ( !$is_datatype_plugin && count($plugin_config['required_fields']) !== 1 ) {
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
            foreach ($plugin_config['required_fields'] as $field_id => $field_data) {
                foreach ($required_keys as $key) {
                    if ( !array_key_exists($key, $field_data) )
                        throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", required_field "'.$field_id.'" is missing the key "'.$key.'"');
                }

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
            'type',
            'default',
            'description'
        );

        if ( is_array($plugin_config['config_options']) ) {
            foreach ($plugin_config['config_options'] as $config_id => $option_data) {
                foreach ($required_keys as $key) {
                    if ( !array_key_exists($key, $option_data) )
                        throw new ODRException('RenderPlugin config file "'.$plugin_config['filepath'].'", config_option "'.$config_id.'" is missing the key "'.$key.'"');
                }

                // TODO - validate the optional 'choices' config value?
            }
        }
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
        // Going to need an array of all valid typeclasses in order to validate the plugin config files
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
        foreach ($installed_plugins as $plugin_classname => $plugin_data) {
            if ( !isset($available_plugins[$plugin_classname]) )
                throw new ODRException('The render plugin "'.$plugin_classname.'" "'.$plugin_data['pluginName'].'" is missing its config file on the server');

            $plugin_config = $available_plugins[$plugin_classname];

            // ----------------------------------------
            // Check properties specific to the render plugin...
            $plugins_needing_updates[$plugin_classname] = array();
            if ( $plugin_data['pluginName'] !== $plugin_config['name'] )
                $plugins_needing_updates[$plugin_classname][] = 'name';

            if ( $plugin_data['description'] !== $plugin_config['description'] )
                $plugins_needing_updates[$plugin_classname][] = 'description';

            if ( $plugin_data['overrideChild'] !== $plugin_config['override_child'] )
                $plugins_needing_updates[$plugin_classname][] = 'override_child';

            if ( $plugin_data['overrideFields'] !== $plugin_config['override_fields'] )
                $plugins_needing_updates[$plugin_classname][] = 'override_fields';

            // TODO - other properties?  render plugin meta?


            // ----------------------------------------
            // Determine whether any renderPluginFields entries need to be created/updated/deleted
            $tmp = array();

            foreach ( $plugin_data['renderPluginFields'] as $num => $rpf) {
                // TODO - need a better key than field name...

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
                        // This field doesn't exist in the database, make an entry for it so it can get created
                        $tmp[$fieldname] = array(
                            'description' => $data['description'],
                            'allowed_fieldtypes' => $allowed_fieldtypes,
                        );
                    }
                    else {
                        // This field exists in the database...
                        $existing_data = $tmp[$fieldname];

                        // Determine whether this field's description changed...
                        if ( $existing_data['description'] === $data['description'] )
                            unset( $tmp[$fieldname]['description'] );

                        // Determine whether this field's allowed fieldtypes changed...
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

            if ( count($tmp) > 0 )
                $plugins_needing_updates[$plugin_classname][] = $tmp;
        }


        // ----------------------------------------
        // TODO - check plugin options as well...$installed_plugins doesn't have that data right now

        // TODO - there's only one table that stores (instance_id, option_name, option_value)
        // TODO - there needs to be one table to store (option_id, option_name, option_type, option_default, ...)
        // TODO -  and another to store (instance_id, option_id, option_value)


        // ----------------------------------------
        // If a plugin doesn't actually have any changes, it doesn't need an update...
        foreach ($plugins_needing_updates as $plugin_classname => $data) {
            if ( count($data) == 0 )
                unset( $plugins_needing_updates[$plugin_classname] );
        }
        return $plugins_needing_updates;
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
            // TODO - this needs to eventually load the renderPluginOptions...see self::getPluginDiff()
            $query = $em->createQuery(
               'SELECT rp, rpf, rpi
                FROM ODRAdminBundle:RenderPlugin AS rp
                LEFT JOIN rp.renderPluginFields AS rpf
                LEFT JOIN rp.renderPluginInstance AS rpi'
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

            // Get all render plugins on the server
            $available_plugins = self::getAvailablePlugins($em);
            if ( !isset($available_plugins[$plugin_classname]) )
                throw new ODRBadRequestException('Unable to install a non-existant RenderPlugin');


            // ----------------------------------------
            // Pull the plugin's data from the config file
            $plugin_data = $available_plugins[$plugin_classname];

            // Create a new RenderPlugin entry from the config file data
            $render_plugin = new RenderPlugin();
            $render_plugin->setPluginName( $plugin_data['name'] );
            $render_plugin->setDescription( $plugin_data['description'] );
            $render_plugin->setPluginClassName( $plugin_classname );
            $render_plugin->setActive(true);

            if ( $plugin_classname === 'odr_plugins.base.default' )
                $render_plugin->setPluginType( RenderPlugin::DEFAULT_PLUGIN );
            else if ( $plugin_data['datatype'] === false )
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
            $em->flush();
            $em->refresh($render_plugin);


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
                    $rpf->setAllowedFieldtypes(implode(',', $allowed_fieldtypes));

                    $render_plugin->addRenderPluginField($rpf);

                    $rpf->setCreatedBy($user);
                    $rpf->setUpdatedBy($user);

                    $em->persist($rpf);
                }
            }

            // TODO - refactor render plugins to also "install" RenderPluginOptions?

            $em->flush();
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

            // Get all render plugins on the server
            $available_plugins = self::getAvailablePlugins($em);
            if ( !isset($available_plugins[$plugin_classname]) )
                throw new ODRBadRequestException('Unable to update a non-existant RenderPlugin');


            // ----------------------------------------
            // Pull the plugin's data from the config file
            $plugin_data = $available_plugins[$plugin_classname];

            // Update the existing RenderPlugin entry from the config file data
            $render_plugin->setPluginName( $plugin_data['name'] );
            $render_plugin->setDescription( $plugin_data['description'] );
            $render_plugin->setPluginClassName( $plugin_classname );
            $render_plugin->setActive(true);

            if ( $plugin_data['override_fields'] === false )    // Yaml parser sets these to true/false values
                $render_plugin->setOverrideFields(false);
            else
                $render_plugin->setOverrideFields(true);

            if ( $plugin_data['override_child'] === false )
                $render_plugin->setOverrideChild(false);
            else
                $render_plugin->setOverrideChild(true);

            $render_plugin->setUpdatedBy($user);

            $em->persist($render_plugin);
            $em->flush();
            $em->refresh($render_plugin);


            // ----------------------------------------
            // Delete any non-existent RenderPluginField entries
            $fields_to_delete = array();
            /** @var RenderPluginFields $rpf */
            foreach ($render_plugin->getRenderPluginFields() as $rpf) {
                // TODO - fieldname shouldn't be the identifying key in the long run...
                $fieldname = $rpf->getFieldName();

                $found = false;
                if ( is_array($plugin_data['required_fields']) ) {
                    foreach ($plugin_data['required_fields'] as $key => $tmp) {
                        if ( $fieldname === $tmp['name'] )
                            $found = true;
                    }
                }

                if (!$found) {
                    // Either config file has no fields listed, or field wasn't in config file...
                    // ...mark field for deletion
                    $fields_to_delete[] = $rpf->getId();
                }
            }

            if ( count($fields_to_delete) > 0 ) {

                // Going to use multiple mass updates since DQL doesn't do multi-table updates...
                $conn = $em->getConnection();
                $conn->beginTransaction();

                // Delete renderPluginField entries
                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:RenderPluginFields AS rpf
                    SET rpf.deletedAt = :now
                    WHERE rpf.id IN (:fields_to_delete)'
                )->setParameters(
                    array(
                        'now' => new \DateTime(),
                        'fields_to_delete' => $fields_to_delete
                    )
                );
                $query->execute();

                // Delete renderPluginMap entries
                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:RenderPluginMap AS rpm
                    SET rpm.deletedAt = :now
                    WHERE rpm.renderPluginFields IN (:fields_to_delete)'
                )->setParameters(
                    array(
                        'now' => new \DateTime(),
                        'fields_to_delete' => $fields_to_delete
                    )
                );
                $query->execute();

                $conn->commit();
            }


            // ----------------------------------------
            // Create/update any missing RenderPluginField entries from the config file data
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
                    $rpf->setAllowedFieldtypes(implode(',', $allowed_fieldtypes));

                    if ($creating) {
                        $rpf->setCreatedBy($user);
                        $render_plugin->addRenderPluginField($rpf);
                    }

                    $rpf->setUpdatedBy($user);

                    $em->persist($rpf);
                }
            }

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
                // TODO - modify stuff so deleting datatypes also deletes rpi entries?  and deletes datafields at the same time?
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
            if( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            $current_render_plugin = null;
            $render_plugins = null;
            $render_plugin_instance = null;

            if ($datafield_id == 0) {
                // If datafield id isn't defined, this is a render plugin for a datatype
                $current_render_plugin = $datatype->getRenderPlugin();

                // Load all available render plugins for this datatype
                $query = $em->createQuery(
                   'SELECT rp
                    FROM ODRAdminBundle:RenderPlugin AS rp
                    WHERE rp.plugin_type IN (:plugin_types)
                    AND rp.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'plugin_types' => array(
                            RenderPlugin::DEFAULT_PLUGIN,
                            RenderPlugin::DATATYPE_PLUGIN,
                        )
                    )
                );
                /** @var RenderPlugin[] $render_plugins */
                $render_plugins = $query->getResult();

                // Attempt to grab the field mapping between this render plugin and this datatype
                /** @var RenderPluginInstance|null $render_plugin_instance */
                $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $current_render_plugin, 'dataType' => $datatype) );
            }
            else {
                // ...otherwise, this is a render plugin for a datafield
                $current_render_plugin = $datafield->getRenderPlugin();

                // Load all available render plugins for this datafield
                $query = $em->createQuery(
                   'SELECT rp
                    FROM ODRAdminBundle:RenderPlugin AS rp
                    WHERE rp.plugin_type IN (:plugin_types)
                    AND rp.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'plugin_types' => array(
                            RenderPlugin::DEFAULT_PLUGIN,
                            RenderPlugin::DATAFIELD_PLUGIN,
                        )
                    )
                );
                /** @var RenderPlugin[] $render_plugins */
                $render_plugins = $query->getResult();

                /** @var RenderPluginInstance|null $render_plugin_instance */
                $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $current_render_plugin, 'dataField' => $datafield) );
            }


            // Get Templating Object
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Plugins:plugin_settings_dialog_form.html.twig',
                    array(
                        'local_datatype' => $datatype,
                        'local_datafield' => $datafield,
                        'render_plugins' => $render_plugins,
                        'render_plugin_instance' => $render_plugin_instance
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Grab necessary objects
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');

            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dt_admin' ])) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
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


            /** @var RenderPlugin $current_render_plugin */
            $current_render_plugin = $repo_render_plugin->find($render_plugin_id);
            if ( is_null($current_render_plugin) )
                throw new ODRNotFoundException('RenderPlugin');

            $all_fieldtypes = array();
            /** @var FieldType[] $tmp */
            $tmp = $repo_fieldtype->findAll();
            foreach ($tmp as $fieldtype)
                $all_fieldtypes[ $fieldtype->getId() ] = $fieldtype;


            // ----------------------------------------
            // Attempt to load the field mapping between this render plugin and this datatype/datafield
            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows, because we want to display old selected mappings/options

            $query = null;
            if ( is_null($datafield) ) {
                $query = $em->createQuery(
                   'SELECT rpi
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    WHERE rpi.renderPlugin = :renderPlugin AND rpi.dataType = :dataType'
                )->setParameters( array('renderPlugin' => $current_render_plugin, 'dataType' => $datatype) );
            }
            else {
                $query = $em->createQuery(
                   'SELECT rpi
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    WHERE rpi.renderPlugin = :renderPlugin AND rpi.dataField = :dataField'
                )->setParameters( array('renderPlugin' => $current_render_plugin, 'dataField' => $datafield) );
            }

            $results = $query->getResult();
            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            $render_plugin_instance = null;
            if ( count($results) > 0 ) {
                // Only want the most recent RenderPluginInstance
                foreach ($results as $result)
                    $render_plugin_instance = $result;
            }
            /** @var RenderPluginInstance|null $render_plugin_instance */


            // ----------------------------------------
            // If this datatype is currently using the render plugin, load the mapping between the
            //  datatype's fields and the render plugin's fields
            $render_plugin_map = null;
            if ( !is_null($render_plugin_instance) )
                $render_plugin_map = $repo_render_plugin_map->findBy( array('renderPluginInstance' => $render_plugin_instance) );
            /** @var RenderPluginMap|null $render_plugin_map */


            // ----------------------------------------
            // Load the base information about the fields the render plugin requires
            /** @var RenderPluginFields[]|null $render_plugin_fields */
            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $current_render_plugin) );

            // Make a list of which fieldtypes each renderPluginField entry is allowed to have
            $allowed_fieldtypes = array();
            if ( is_array($render_plugin_fields) ) {
                foreach ($render_plugin_fields as $rpf) {
                    $allowed_fieldtypes[ $rpf->getId() ] = array();

                    $tmp = explode(',', $rpf->getAllowedFieldtypes());
                    foreach ($tmp as $ft_id)
                        $allowed_fieldtypes[ $rpf->getId() ][] = $ft_id;
                }
            }


            // ----------------------------------------
            // Load the current batch of settings for this instance of the render plugin
            /** @var RenderPluginOptions[] $current_plugin_options */
            $current_plugin_options = $repo_render_plugin_options->findBy( array('renderPluginInstance' => $render_plugin_instance) );


            // TODO - modify the database so $available_options is loaded from it instead of the config file?
            // TODO - ...would likely require an "odr_render_plugin_option_map" table, and rename the existing "odr_render_plugin_map" table to "odr_render_plugin_field_map"...
            $available_plugins = self::getAvailablePlugins($em, false);
            $plugin_options = $available_plugins[ $current_render_plugin->getPluginClassName() ]['config_options'];

            $available_options = array(
                $current_render_plugin->getId() => $plugin_options
            );
            if ( $plugin_options == '' )
                $available_options = null;


            // ----------------------------------------
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Plugins:plugin_settings_dialog_form_data.html.twig',
                    array(
                        'local_datatype' => $datatype,
                        'local_datafield' => $datafield,
                        'datafields' => $all_datafields,

                        'plugin' => $current_render_plugin,
                        'render_plugin_fields' => $render_plugin_fields,
                        'allowed_fieldtypes' => $allowed_fieldtypes,
                        'all_fieldtypes' => $all_fieldtypes,

                        'available_options' => $available_options,
                        'current_plugin_options' => $current_plugin_options,

                        'render_plugin_instance' => $render_plugin_instance,
                        'render_plugin_map' => $render_plugin_map
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
     * Saves settings changes made to a RenderPlugin for a DataType
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
                || !isset($post['render_plugin_instance_id'])
                || !isset($post['previous_render_plugin'])
                || !isset($post['selected_render_plugin'])
            ) {
                throw new ODRBadRequestException('Invalid Form');
            }

            $local_datatype_id = $post['local_datatype_id'];
            $local_datafield_id = $post['local_datafield_id'];
            $render_plugin_instance_id = $post['render_plugin_instance_id'];
            $previous_plugin_id = $post['previous_render_plugin'];
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
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
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
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $local_datatype_id ]) && isset($datatype_permissions[ $local_datatype_id ][ 'dt_admin' ])) )
                throw new ODRForbiddenException();
            // --------------------


            /** @var DataType|null $target_datatype */
            $target_datatype = null;    // the datatype that is getting its render plugin modified, or the datatype of the datafield getting its render plugin modified
            /** @var DataFields|null $target_datafield */
            $target_datafield = null;   // the datafield that is getting its render plugin modified

            $plugin_settings_changed = false;
            $reload_datatype = false;

            $changing_datatype_plugin = false;
            $changing_datafield_plugin = false;

            if ($local_datafield_id == 0) {
                // Changing the render plugin for a datatype...
                $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($local_datatype_id);
                if ( is_null($target_datatype) )
                    throw new ODRNotFoundException('Datatype');

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

                $changing_datafield_plugin = true;
            }


            /** @var RenderPlugin $selected_render_plugin */
            $selected_render_plugin = $repo_render_plugin->find($selected_plugin_id);
            if ( is_null($selected_render_plugin) )
                throw new ODRNotFoundException('RenderPlugin');

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
            else if ( is_null($target_datatype)
                && is_null($target_datafield)
                && $selected_render_plugin->getPluginType() == RenderPlugin::DEFAULT_PLUGIN
            ) {
                throw new ODRBadRequestException('No target specified for the Render Plugin');
            }


            // ----------------------------------------
            // Ensure the plugin map doesn't have the same datafield mapped to multiple renderPluginFields
            $mapped_datafields = array();
            foreach ($plugin_map as $rpf_id => $df_id) {
                if ($df_id != '-1') {
                    if ( isset($mapped_datafields[$df_id]) )
                        throw new ODRBadRequestException('Invalid Form...multiple datafields mapped to the same renderpluginfield');

                    $mapped_datafields[$df_id] = 0;
                }
            }

            // Ensure the datafields in the plugin map are the correct fieldtype, and that none of the fields required for the plugin are missing
            /** @var RenderPluginFields[] $render_plugin_fields */
            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $selected_render_plugin) );
            foreach ($render_plugin_fields as $rpf) {
                $rpf_id = $rpf->getId();

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

            // TODO - ensure plugin options are valid?


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
                /** @var RenderPlugin $default_render_plugin */
                $default_render_plugin = $repo_render_plugin->findOneBy(
                    array(
                        'pluginClassName' => 'odr_plugins.base.default'
                    )
                );

                /** @var FieldType $fieldtype */
                $fieldtype = $repo_fieldtype->find($ft_id);
                if ( is_null($fieldtype) )
                    throw new ODRBadRequestException('Invalid Form');

                /** @var RenderPluginFields $rpf */
                $rpf = $repo_render_plugin_fields->find($rpf_id);


                // Create the Datafield and set basic properties from the render plugin settings
                $datafield = $ec_service->createDatafield($user, $target_datatype, $fieldtype, $default_render_plugin, true);    // Don't flush immediately...

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
            // Mark the Datafield/Datatype as using the selected RenderPlugin
            if ($changing_datatype_plugin) {
                $properties = array(
                    'renderPlugin' => $selected_render_plugin->getId()
                );
                $emm_service->updateDatatypeMeta($user, $target_datatype, $properties);
            }
            else if ($changing_datafield_plugin) {
                $properties = array(
                    'renderPlugin' => $selected_render_plugin->getId()
                );
                $emm_service->updateDatafieldMeta($user, $target_datafield, $properties);
            }


            // ...delete the old render plugin instance object if the user changed render plugins
            $render_plugin_instance = null;
            if ($render_plugin_instance_id != '') {
                /** @var RenderPluginInstance|null $render_plugin_instance */
                $render_plugin_instance = $repo_render_plugin_instance->find($render_plugin_instance_id);

                if ( intval($previous_plugin_id) != intval($selected_plugin_id) && !is_null($render_plugin_instance) ) {
                    // ----------------------------------------
                    // Need to execute the onRemoval() function of this render plugin...
                    $removed_plugin_classname = $render_plugin_instance->getRenderPlugin()->getPluginClassName();

                    /** @var DatafieldPluginInterface|DatatypePluginInterface $plugin_service */
                    $plugin_service = $this->container->get($removed_plugin_classname);
                    $plugin_service->onRemoval($render_plugin_instance);


                    // ----------------------------------------
                    // Remove/detach this plugin instance from the datafield/datatype
                    $em->remove($render_plugin_instance);
                    $em->detach($render_plugin_instance);
                    $render_plugin_instance = null;
                }
            }


            // ----------------------------------------
            // If the datatype/datafield is no longer using the default render plugin...
            if ($selected_render_plugin->getPluginClassName() !== 'odr_plugins.base.default') {
                // ...figure out whether to create a new RenderPluginInstance
                if ( is_null($render_plugin_instance) )
                    $render_plugin_instance = $ec_service->createRenderPluginInstance($user, $selected_render_plugin, $target_datatype, $target_datafield);    // need to flush here
                /** @var RenderPluginInstance $render_plugin_instance */

                // Save the field mapping
                foreach ($plugin_map as $rpf_id => $df_id) {
                    // Attempt to locate the mapping for this render plugin field field in this instance
                    /** @var RenderPluginMap $render_plugin_map */
                    $render_plugin_map = $repo_render_plugin_map->findOneBy(
                        array(
                            'renderPluginInstance' => $render_plugin_instance->getId(),
                            'renderPluginFields' => $rpf_id
                        )
                    );


                    // If the render plugin map entity doesn't exist, create it
                    if ( is_null($render_plugin_map) ) {
                        // Locate the render plugin field object being referenced
                        /** @var RenderPluginFields $render_plugin_field */
                        $render_plugin_field = $repo_render_plugin_fields->find($rpf_id);

                        // Locate the desired datafield object...already checked for its existence earlier
                        /** @var DataFields $df */
                        $df = $repo_datafield->find($df_id);

                        $ec_service->createRenderPluginMap($user, $render_plugin_instance, $render_plugin_field, $target_datatype, $df, true);    // don't need to flush...
                        $plugin_settings_changed = true;
                    }
                    else {
                        // ...otherwise, update the existing entity
                        $properties = array(
                            'dataField' => $df_id
                        );
                        $changes_made = $emm_service->updateRenderPluginMap($user, $render_plugin_map, $properties, true);    // don't need to flush...

                        if ($changes_made)
                            $plugin_settings_changed = true;
                    }
                }

                // Save the plugin options
                foreach ($plugin_options as $option_name => $option_value) {
                    // Attempt to locate this particular render plugin option in this instance
                    /** @var RenderPluginOptions $render_plugin_option */
                    $render_plugin_option = $repo_render_plugin_options->findOneBy(
                        array(
                            'renderPluginInstance' => $render_plugin_instance->getId(),
                            'optionName' => $option_name
                        )
                    );

                    // If the render plugin option entity doesn't exist, create it
                    if ( is_null($render_plugin_option) ) {
                        $ec_service->createRenderPluginOption($user, $render_plugin_instance, $option_name, $option_value, true);    // don't need to flush...
                        $plugin_settings_changed = true;
                    }
                    else {
                        // ...otherwise, update the existing entity
                        $properties = array(
                            'optionValue' => $option_value
                        );
                        $changes_made = $emm_service->updateRenderPluginOption($user, $render_plugin_option, $properties, true);    // don't need to flush...

                        if ($changes_made)
                            $plugin_settings_changed = true;
                    }
                }

                // Should be able to flush here
                $em->flush();

                if ($plugin_settings_changed) {
                    $plugin_classname = $render_plugin_instance->getRenderPlugin()->getPluginClassName();

                    /** @var DatafieldPluginInterface|DatatypePluginInterface $plugin_service */
                    $plugin_service = $this->container->get($plugin_classname);
                    $plugin_service->onSettingsChange($render_plugin_instance);   // TODO - specify which field mappings or options got changed?
                }
            }


            // Deal with updating field & datatype
            if ($local_datafield_id == 0) {
                // Master Template Data Types must increment Master Revision on all change requests.
                if ($target_datatype->getIsMasterType()) {
                    $dtm_properties['master_revision'] = $target_datatype->getMasterRevision() + 1;
                    $emm_service->updateDatatypeMeta($user, $target_datatype, $dtm_properties);
                }
            }
            else {
                // Master Template Data Types must increment Master Revision on all change requests.
                if ($target_datafield->getIsMasterField()) {
                    $dfm_properties['master_revision'] = $target_datafield->getMasterRevision() + 1;
                    $emm_service->updateDatafieldMeta($user, $target_datafield, $dfm_properties);
                }
            }

            // ----------------------------------------
            // Now that all the database changes have been made, wipe the relevant cache entries
            $dti_service->updateDatatypeCacheEntry($target_datatype, $user);
            $theme_service->updateThemeCacheEntry($theme, $user);

            // Changes in render plugin tend to require changes in datafield properties
            $datatype_array = $dti_service->getDatatypeArray($target_datatype->getGrandparent()->getId());
            // Don't need to filter here
            $datafield_properties = json_encode($dfi_service->getDatafieldProperties($datatype_array));

            $return['d'] = array(
                'datafield_id' => $local_datafield_id,
                'datatype_id' => $local_datatype_id,
                'render_plugin_id' => $selected_render_plugin->getId(),
                'render_plugin_name' => $selected_render_plugin->getPluginName(),
                'render_plugin_classname' => $selected_render_plugin->getPluginClassName(),
                'html' => '',

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
