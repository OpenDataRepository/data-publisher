<?php

/**
 * Open Data Repository Data Publisher
 * Post MassEdit Event Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All render plugins that listen to the PostMassEdit Event must implement this interface.
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


interface PostMassEditEventInterface
{

    /**
     * Returns an array of datafields where MassEdit should enable the abiilty to run a background
     * job without actually changing their values.
     *
     * @param array $render_plugin_instance
     * @return array An array where the keys are datafield ids, and the values don't really matter
     */
    public function getMassEditOverrideFields($render_plugin_instance);
}
