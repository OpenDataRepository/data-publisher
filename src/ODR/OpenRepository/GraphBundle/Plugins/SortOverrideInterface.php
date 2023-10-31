<?php

/**
 * Open Data Repository Data Publisher
 * Sort Override Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All render plugins that override how a datafield is sorted must implement this interface.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

interface SortOverrideInterface
{

    /**
     * Returns whether SortService should use the "value" or the "converted_value" to sort the
     * given datafield.
     *
     * @param array $render_plugin_options
     *
     * @return boolean
     */
    public function useConvertedValue($render_plugin_options);
}
