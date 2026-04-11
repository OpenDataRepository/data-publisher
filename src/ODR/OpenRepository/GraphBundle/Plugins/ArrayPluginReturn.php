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
    public function __construct(private readonly array $datatype_array, private readonly array $datarecord_array, private readonly array $theme_array)
    {
    }


    /**
     * @return array
     */
    public function getDatatypeArray()
    {
        return $this->datatype_array;
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
