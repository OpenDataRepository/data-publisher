<?php

/**
 * Open Data Repository Data Publisher
 * Search Override Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All render plugins that override how a datafield is searched must implement this interface.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Entities
use ODR\AdminBundle\Entity\DataFields;

interface SearchOverrideInterface
{

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
    public function searchPluginField($datafield, $search_term, $render_plugin_options);


    /**
     * Returns whether the plugin wants to override its entry in the search sidebar.
     *
     * @param array $render_plugin_instance
     * @param array $datafield
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecuteSearchPlugin($render_plugin_instance, $datafield, $rendering_options);


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
    public function executeSearchPlugin($datafield, $render_plugin_instance, $datatype_id, $preset_value, $rendering_options);
}
