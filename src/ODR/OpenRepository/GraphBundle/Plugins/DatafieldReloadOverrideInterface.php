<?php

/**
 * Open Data Repository Data Publisher
 * Datafield Reload Override Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Datatype plugins that need to override reloading of datafields must implement this interface.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\RenderPluginInstance;

interface DatafieldReloadOverrideInterface
{

    /**
     * Gathers parameters so that a datatype plugin can provide an alternate template for reloading
     * one of its required datafields.
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
