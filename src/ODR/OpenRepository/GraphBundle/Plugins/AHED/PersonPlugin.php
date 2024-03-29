<?php

/**
 * Open Data Repository Data Publisher
 * Person Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The person plugin requires the datatype to have a number of datafields for storing metadata
 * about a person.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\AHED;

// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class PersonPlugin implements DatatypePluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * PersonPlugin constructor.
     *
     * @param EngineInterface $templating
     */
    public function __construct(EngineInterface $templating) {
        $this->templating = $templating;
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
     * Executes the Person Plugin on the provided datarecords
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
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {
        // This render plugin does not override any part of the rendering, and therefore this function will never be called.
        return '';
    }
}
