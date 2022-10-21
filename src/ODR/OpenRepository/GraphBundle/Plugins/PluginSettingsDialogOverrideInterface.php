<?php

/**
 * Open Data Repository Data Publisher
 * RenderPlugin Settings Dialog Override Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Certain render plugins options require considerably more than just a label and a dropdown/text field
 * in the renderPluginSettings dialog...
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;

interface PluginSettingsDialogOverrideInterface
{
    /**
     * Returns an array of HTML strings for each RenderPluginOption in this RenderPlugin that needs
     * to use custom HTML in the RenderPlugin settings dialog.
     *
     * @param ODRUser $user                                     The user opening the dialog
     * @param boolean $is_datatype_admin                        Whether the user is able to make changes to this RenderPlugin's config
     * @param RenderPlugin $render_plugin                       The RenderPlugin in question
     * @param DataType $datatype                                The relevant datatype if this is a Datatype Plugin, otherwise the Datatype of the given Datafield
     * @param DataFields|null $datafield                        Will be null unless this is a Datafield Plugin
     * @param RenderPluginInstance|null $render_plugin_instance Will be null if the RenderPlugin isn't in use
     * @return string[]
     */
    public function getRenderPluginOptionsOverride($user, $is_datatype_admin, $render_plugin, $datatype, $datafield = null, $render_plugin_instance = null);
}
