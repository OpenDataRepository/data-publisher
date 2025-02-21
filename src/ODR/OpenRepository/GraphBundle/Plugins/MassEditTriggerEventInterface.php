<?php

/**
 * Open Data Repository Data Publisher
 * MassEditTrigger Event Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All render plugins that listen to the MassEditTrigger Event must implement this interface.
 *
 * While MassEdit typically only creates/runs jobs when users want to change values, there are a
 * handful of instances where it's useful for plugins to run their stuff on large numbers of records
 * without technically changing the underlying values.
 *
 * One such example is the FileRenamer plugin...it provides the ability to update filenames for a
 * single file/image datafield of a single datarecord in Edit mode, but clearly it's handy to also
 * be able to force an update to multiple datarecords and/or datafields at once.
 *
 * Plugins implementing DatafieldDerivationInterface also tend to find it useful to run their
 * derivation routines on a pile of datarecords, though implementing this interface isn't required.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;


interface MassEditTriggerEventInterface
{

    /**
     * Returns an array of datafield ids where MassEdit should enable the abiilty to run a background
     * job without actually changing their values.
     *
     * @param array $render_plugin_instance
     * @return int[] An array where the values are datafield ids
     */
    public function getMassEditOverrideFields($render_plugin_instance);


    /**
     * The MassEdit system generates a checkbox to "activate the RenderPlugin" for each datafield
     * the implementing RenderPlugin returns via self::getMassEditOverrideFields()...but there are
     * cases where certain RenderPlugins may not want or need to activate separately if the user has
     * also entered a value in the relevant field.
     *
     * For each datafield affected by this RenderPlugin, this function returns true if the plugin
     * should always be activated, or false if it should only be activated when the user didn't
     * also enter a value into the field.
     *
     * @param array $render_plugin_instance
     * @return bool[] An array where the keys are datafield ids
     */
    public function getMassEditTriggerFields($render_plugin_instance);
}
