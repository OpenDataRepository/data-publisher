<?php

/**
 * Open Data Repository Data Publisher
 * Sample Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The organization plugin requires the datatype to have a number of datafields for storing metadata
 * about a sample.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\AHED;

// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
class SamplePlugin implements DatatypePluginInterface
{

    /**
     * SamplePlugin constructor.
     *
     * @param \Twig\Environment $templating
     */
    public function __construct(private readonly \Twig\Environment $templating)
    {
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
        // This render plugin doesn't actually do anything when rendering
        return false;
    }


    /**
     * Executes the Sample Plugin on the provided datarecords
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
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = [], $datatype_permissions = [], $datafield_permissions = [], $token_list = [])
    {
        // This render plugin does not override any part of the rendering, and therefore this function will never be called.
        return '';
    }
}
