<?php

/**
 * Open Data Repository Data Publisher
 * FileRenamer Plugin Interface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Multiple plugins may want to rename files/images according to their own individual rules...
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;


use ODR\AdminBundle\Entity\DataRecordFields;

interface FileRenamerPluginInterface
{

    /**
     * Returns an array of changes to make to files/images, or a single attempting to indicate why
     * the "correct" names can't be determined.
     *
     * The array has the following format:
     * <pre>
     * array(
     *     <file/image_id> => array(
     *         'new_filename' => <string>,
     *         ['new_ext'] => [<string>]
     *     ),
     *     ...
     * )
     * </pre>
     *
     * @param DataRecordFields $drf
     * @return array|string
     */
    public function getNewFilenames($drf);
}
