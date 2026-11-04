<?php

/**
 * Open Data Repository Data Publisher
 * Array Plugin Return
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This is a shim class of sorts, so that the Array-type RenderPlugins can be guaranteed to return
 * the correct type of data.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;


class ArrayPluginReturn
{

    /**
     * @param array $datatype_array
     * @param array $datarecord_array
     * @param array $theme_array
     */
    public function __construct(
        private readonly int $datatype_id,
        private readonly array $datatype_array,
        private readonly array $datarecord_array,
        private readonly array $theme_array
    ) {

    }


    /**
     * @return array
     */
    public function getDatatypeArray()
    {
        // NOTE: this is different from the other two arrays because the other two arrays are "wrapped"
        //  with their respective ids when they're passed to the execute() function of an ArrayPlugin
        // The datatype array is not, but twig needs it to also be "wrapped" so it can actually save
        //  any modifications the plugin made
        return [$this->datatype_id => $this->datatype_array];
    }

    /**
     * @return array
     */
    public function getDatarecordArray()
    {
        return $this->datarecord_array;
    }

    /**
     * @return array
     */
    public function getThemeArray()
    {
        return $this->theme_array;
    }
}
