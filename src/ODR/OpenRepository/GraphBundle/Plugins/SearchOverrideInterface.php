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
     * Returns which entries of its entries the plugin wants to override in the search sidebar.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $datafield
     * @param array $rendering_options
     *
     * @return array|bool returns true/false if a datafield plugin, or an array if a datatype plugin
     */
    public function canExecuteSearchPlugin($render_plugin_instance, $datatype, $datafield, $rendering_options);


    /**
     * Returns HTML to override a datafield's entry in the search sidebar.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $datafield
     * @param string|array $preset_value
     * @param array $rendering_options
     *
     * @return string
     */
    public function executeSearchPlugin($render_plugin_instance, $datatype, $datafield, $preset_value, $rendering_options);


    /**
     * Given an array of datafields mapped by this plugin, returns which datafields SearchAPIService
     * should call {@link SearchOverrideInterface::searchOverriddenField()} on instead of running
     * the default searches.
     *
     * @param array $df_list An array where the RenderPluginField names are the keys, and the values are datafield ids
     * @return array An array where the values are datafield ids
     */
    public function getSearchOverrideFields($df_list);


    /**
     * Searches the specified datafield for the specified value, returning an array of datarecord
     * ids that match the search.
     *
     * @param DataFields $datafield
     * @param array $search_term
     * @param array $render_plugin_fields
     * @param array $render_plugin_options
     *
     * @return array
     */
    public function searchOverriddenField($datafield, $search_term, $render_plugin_fields, $render_plugin_options);
}
