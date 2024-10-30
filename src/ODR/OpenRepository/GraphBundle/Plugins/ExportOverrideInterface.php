<?php

/**
 * Open Data Repository Data Publisher
 * Export Override Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All render plugins that override how a datafield is exported must implement this interface.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;


interface ExportOverrideInterface
{

    // TODO - this works for individual fields, but probably should have an option to make it work for entire datatypes

    /**
     * Returns an array of datafields where CSVExport needs to call this plugin instead of just
     * using the value from the database.
     *
     * @param array $render_plugin_instance
     * @return array An array where the values are datafield ids
     */
    public function getExportOverrideFields($render_plugin_instance);


    /**
     * Returns an array of values that CSVExport should use for the requested datafields.
     *
     * @param array $datafield_ids Which datafields the plugin should return values for
     * @param array $render_plugin_instance
     * @param array $datatype_array A stacked datatype array
     * @param array $datarecord_array A stacked datarecord array
     * @param array $user_permissions
     *
     * @return array An array of (datafield_id => export_value) pairs
     */
    public function getExportOverrideValues($datafield_ids, $render_plugin_instance, $datatype_array, $datarecord_array, $user_permissions);
}
