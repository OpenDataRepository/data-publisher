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

// Entities
use ODR\AdminBundle\Entity\DataFields;

interface SortOverrideInterface
{

    /**
     * Sorts the specified datafield TODO
     *
     * @param DataFields $datafield
     *
     * @return array
     */
    public function sortPluginField($datafield);
}
