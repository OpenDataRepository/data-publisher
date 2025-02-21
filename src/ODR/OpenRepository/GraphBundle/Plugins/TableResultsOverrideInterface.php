<?php

/**
 * Open Data Repository Data Publisher
 * Table Results Override Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Render Plugins also sometimes need to override values displayed in the table layouts for search
 * results...but since those layouts aren't rendered directly through twig, the TableThemeHelperService
 * needs to call arbitrary render plugins to pull this off.
 *
 * Originally, this interface wasn't needed because that service would only call datafield plugins,
 * but that was no longer feasible once a datatype plugin came along that needed to calculate a value
 * from multiple datafields.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;


interface TableResultsOverrideInterface
{
    /**
     * Returns an array of datafield values that TableThemeHelperService should display, instead of
     * the raw values available in the cached datarecord array.
     *
     * @param array $render_plugin_instance
     * @param array $datarecord
     * @param array|null $datafield
     *
     * @return string[] An array where the keys are datafield ids, and the values are the strings to display
     */
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null);
}
