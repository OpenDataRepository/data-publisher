<?php

/**
 * Open Data Repository Data Publisher
 * Datafield Derivation Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All render plugins that have at least one datafield with a value derived from other datafields
 * must implement this interface.
 *
 * At the moment, this is only used by the FakeEdit Controller so it can correctly enforce uniquness
 * constraints...e.g. "Mineral Name" is supposed to be unique, but it's derived from "Mineral Display Name".
 * Since "Mineral Name" is derived, it's not supposed to have a user-provided value via FakeEdit...
 * so this means "Mineral Display Name" must not be empty, instead.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

interface DatafieldDerivationInterface
{
    /**
     * Returns an array of which datafields are derived from which source datafields, with everything
     * identified by datafield id.
     *
     * @param array $render_plugin_instance
     *
     * @return array
     */
    public function getDerivationMap($render_plugin_instance);
}
