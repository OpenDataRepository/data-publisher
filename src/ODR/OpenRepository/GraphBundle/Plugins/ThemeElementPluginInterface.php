<?php

/**
 * Open Data Repository Data Publisher
 * ThemeElement Plugin Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All render plugins that operate on a themeElement must implement this interface.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;


interface ThemeElementPluginInterface
{

    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options);

    /**
     * Executes this RenderPlugin on the provided datarecord
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
    public function execute($datarecord, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $datatype_permissions = array(), $datafield_permissions = array());

    /**
     * Returns placeholder HTML for a themeElement RenderPlugin for design mode.
     *
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function getPlaceholderHTML($datatype, $render_plugin_instance, $theme_array, $rendering_options);
}
