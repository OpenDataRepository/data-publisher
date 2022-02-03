<?php

/**
 * Open Data Repository Data Publisher
 * Datafield Derivation Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All render plugins that have at least one datafield with a value derived from other datafields
 * via the onPostUpdate event must implement this interface.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\RenderPluginInstance;

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

    /**
     * Gathers parameters so that a datatype plugin can provide an alternate template for reloading
     * a derived datafield.
     *
     * @param string $rendering_context
     * @param RenderPluginInstance $render_plugin_instance
     * @param DataFields $datafield
     * @param DataRecord $datarecord
     *
     * @return array
     */
    public function getOverrideParameters($rendering_context, $render_plugin_instance, $datafield, $datarecord);
}
