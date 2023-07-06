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
     * @var array
     */
    private $datatype_array;

    /**
     * @var array
     */
    private $datarecord_array;

    /**
     * @var array
     */
    private $theme_array;


    /**
     * @param array $datatype_array
     * @param array $datarecord_array
     * @param array $theme_array
     */
    public function __construct(
        array $datatype_array,
        array $datarecord_array,
        array $theme_array
    ) {
        $this->datatype_array = $datatype_array;
        $this->datarecord_array = $datarecord_array;
        $this->theme_array = $theme_array;
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
