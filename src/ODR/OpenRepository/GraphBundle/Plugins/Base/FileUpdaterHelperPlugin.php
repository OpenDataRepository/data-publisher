<?php

/**
 * Open Data Repository Data Publisher
 * File Renamer Helper Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * In situations where a datatype (and/or its descendants) are using the FileRenamer and/or
 * FileHeaderInserter Plugin on multiple fields at the same time, then it can make sense to want to
 * trigger updates for all of these fields at the same time...
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\ThemeElementPluginInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\Routing\Router;


class FileUpdaterHelperPlugin implements ThemeElementPluginInterface
{

    /**
     * @var string
     */
    private $baseurl;

    /**
     * @var string
     */
    private $wordpress_site_baseurl;

    /**
     * @var bool
     */
    private $odr_wordpress_integrated;

    /**
     * @var string
     */
    private $environment;

    /**
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var Router
     */
    private $router;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * File Updater Helper Plugin constructor
     *
     * @param string $baseurl
     * @param string $wordpress_site_baseurl
     * @param bool $odr_wordpress_integrated
     * @param string $environment
     * @param DatabaseInfoService $database_info_service
     * @param Router $router
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        string $baseurl,
        string $wordpress_site_baseurl,
        bool $odr_wordpress_integrated,
        string $environment,
        DatabaseInfoService $database_info_service,
        Router $router,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->baseurl = $baseurl;
        $this->wordpress_site_baseurl = $wordpress_site_baseurl;
        $this->odr_wordpress_integrated = $odr_wordpress_integrated;
        $this->environment = $environment;
        $this->database_info_service = $database_info_service;
        $this->router = $router;
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
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // This render plugin is only allowed to execute in edit mode
            if ( $context === 'edit' )
                return true;

            // Design mode doesn't call this, as it only demands placeholder HTML
        }

        return false;
    }


    /**
     * Executes the File Helper Updater Plugin on the provided datarecord
     *
     * @param array $datarecord
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecord, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $datatype_permissions = array(), $datafield_permissions = array())
    {
        try {
            // ----------------------------------------
            // Shouldn't happen, but make sure this only executes in display mode
            if ( !isset($rendering_options['context']) || $rendering_options['context'] !== 'edit' )
                return '';

            $options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // This plugin has no required fields or options, but does require that this datatype
            //  and its descendants are using the FileHeader/FileHeaderInserter/RRUFFFileHeaderInserter
            //  plugins at least once
            $related_plugins = array(
                'odr_plugins.base.file_renamer' => false,
                'odr_plugins.base.file_header_inserter' => false,
                'odr_plugins.rruff.file_header_inserter' => false,
            );  // TODO - aaaaaaaaaaa hate this aaaaaaaaaaaa

            // Would be somewhat easier if the array wasn't stacked, but there's an argument to be
            //  made for not revealing this if the user can't see the relevant fields/files
            self::findRelevantFields($datatype, $related_plugins);

            // If none of the fields are using the relevant plugins, then don't do anything
            $using_plugins = false;
            foreach ($related_plugins as $plugin_classname => $in_use) {
                if ( $in_use )
                    $using_plugins = true;
            }
            if ( !$using_plugins )
                return '';

            // Generate the three routes
            $file_renamer_url = $this->router->generate(
                'odr_plugin_file_renamer_rebuild_all',
                array(
                    'dr_id' => $datarecord['id'],
                )
            );
            $file_header_inserter_url = $this->router->generate(
                'odr_plugin_file_header_inserter_rebuild_all',
                array(
                    'dr_id' => $datarecord['id'],
                )
            );
            $rruff_file_header_inserter_url = $this->router->generate(
                'odr_plugin_rruff_file_header_inserter_rebuild_all',
                array(
                    'dr_id' => $datarecord['id'],
                )
            );

            // ----------------------------------------
            // Otherwise, render and return a bit of HTML for the themeElement to display
            return $this->templating->render(
                'ODROpenRepositoryGraphBundle:Base:FileUpdaterHelper/file_updater_helper_themeElement.html.twig',
                array(
                    'active_plugins' => $related_plugins,

                    'file_renamer_url' => $file_renamer_url,
                    'file_header_inserter_url' => $file_header_inserter_url,
                    'rruff_file_header_inserter_url' => $rruff_file_header_inserter_url,
                )
            );
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Recursively determines whether any field in this datatype or its descendants is using one of
     * the relevant file manipulation plugins.
     *
     * @param array $datatype_array
     * @param array $related_plugins
     * @return void
     */
    private function findRelevantFields($datatype_array, &$related_plugins)
    {
        foreach ($datatype_array['dataFields'] as $df_id => $df) {
            if ( !empty($df['renderPluginInstances']) ) {
                foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                    $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                    if ( isset($related_plugins[$plugin_classname]) )
                        $related_plugins[$plugin_classname] = true;
                }
            }
        }

        if ( !empty($datatype_array['descendants']) ) {
            foreach ($datatype_array['descendants'] as $dt_id => $ddt_data) {
                $descendant_dt_array = $ddt_data['datatype'][$dt_id];
                self::findRelevantFields($descendant_dt_array, $related_plugins);
            }
        }
    }


    /**
     * {@inheritDoc}
     */
    public function getPlaceholderHTML($datatype, $render_plugin_instance, $theme_array, $rendering_options)
    {
        // Render the placeholder html
        return $this->templating->render(
            'ODROpenRepositoryGraphBundle:Base:FileUpdaterHelper/file_updater_helper_placeholder.html.twig'
        );
    }
}
