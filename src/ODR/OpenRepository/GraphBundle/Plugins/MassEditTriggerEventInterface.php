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
     * Returns an array of datafield ids where MassEdit should enable a checkbox to "Activate the
     * RenderPlugin on this field".  This is typically used to update the contents of a derived
     * field without having to also change the values of any of the related source fields.
     *
     * @param array $render_plugin_instance
     * @return int[] An array where the values are datafield ids
     */
    public function getMassEditOverrideFields($render_plugin_instance);


    /**
     * The checkbox added by {@link MassEditTriggerEventInterface::getMassEditOverrideFields()} is
     * in addition to the default MassEdit input, so there's a possibility for both the user to both
     * enter a regular value (unless it's a readonly field) and activate the extra checkbox.  This
     * could dispatch more than one event for the same field, so this function allows the plugin
     * to control whether that can happen or not.
     *
     * The datafields here should really be a subset of those returned by the other function...a
     * value of false for that datafield means the MassEditTrigger event should only be fired when
     * the field's value isn't changed...a value of true means the MassEditTrigger event should be
     * fired regardless.
     *
     * Typically, plugins set this value to false for text/number fields, and true for file fields.
     * The plugins that deal with text/number fields almost always also listen to the PostUpdate
     * event, and don't need to derive the value twice...however, the ones dealing with file fields
     * need the MassEditTrigger event to do their derivations, since it makes little sense to try
     * to listen to a FilePublicStatusChanged event...
     *
     * @param array $render_plugin_instance
     * @return bool[] An array where the keys are datafield ids
     */
    public function getMassEditTriggerFields($render_plugin_instance);
}
