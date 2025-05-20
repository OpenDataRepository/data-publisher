<?php

/**
 * Open Data Repository Data Publisher
 * Datafield Reload Override Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Certain render plugins end up completely overriding a rendered datafield and any associated
 * javascript...as such, they also need to override situations where ODR reloads the datafield on
 * the page.
 *
 * This only makes sense for datatype plugins...the reloading process for a regular datafield will
 * automatically call the datafield render plugin if one is attached.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;

interface DatafieldReloadOverrideInterface
{
    /**
     * Gathers parameters so that a datatype plugin can provide an alternate template for reloading
     * a derived datafield.
     *
     * @param string $rendering_context
     * @param RenderPluginInstance $render_plugin_instance
     * @param DataFields $datafield
     * @param DataRecord $datarecord
     * @param Theme $theme
     * @param ODRUser $user
     * @param bool $is_datatype_admin
     *
     * @return array
     */
    public function getOverrideParameters($rendering_context, $render_plugin_instance, $datafield, $datarecord, $theme, $user, $is_datatype_admin);
}
