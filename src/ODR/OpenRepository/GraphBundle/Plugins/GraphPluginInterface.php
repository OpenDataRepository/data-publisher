<?php

/**
 * Open Data Repository Data Publisher
 * Graph Plugin Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Plugins that read files and cache some visualization of it typically need some mechanism to know
 * when the files get deleted or replaced, since that typically means they need to rebuild those
 * cache entries.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

use ODR\AdminBundle\Entity\DataFields;


interface GraphPluginInterface
{

    /**
     * Called when a file used by this render plugin is replaced or deleted.
     *
     * This might change in the future, but at the moment...the only relevant render plugin uses
     * the file id as part of the cache entry filename.
     *
     * @param DataFields $datafield
     * @param int $file_id
     */
    public function onFileChange($datafield, $file_id);
}