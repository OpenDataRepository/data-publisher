<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Pin Data Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This is a quick/dirty implementation of converting the raw data used to define orientation in
 * RRUFF into a slightly better format TODO
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;


class RRUFFPinDataPlugin implements DatatypePluginInterface
{

    /**
     * @var Logger
     */
    private $logger;


    /**
     * RRUFF Pin Data Plugin constructor
     *
     * @param Logger $logger
     */
    public function __construct(
        Logger $logger
    ) {
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

            if ( $context === 'display' ) {
                return true;

                // TODO - need to actually implement this though...
            }
        }

        return false;
    }


    /**
     * Executes the RRUFF Pin Data Plugin on the provided datarecord
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

        try {
            // ----------------------------------------
            // This plugin does nothing
            return '';
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
