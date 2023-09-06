<?php

/**
 * Open Data Repository Data Publisher
 * Array Plugin Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All render plugins that operate on the stacked datatype/datarecord/theme arrays must implement
 * this interface.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;


interface ArrayPluginInterface
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
     * Executes this RenderPlugin on the provided datarecords
     *
     * @param array $datarecord_array
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     *
     * @return ArrayPluginReturn|null
     * @throws \Exception
     */
    public function execute($datarecord_array, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array());
}
