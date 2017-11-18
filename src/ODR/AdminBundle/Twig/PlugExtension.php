<?php

/**
 * Open Data Repository Data Publisher
 * Twig Plugin Extension
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Defines several custom twig filters required by ODR.
 *
 */

namespace ODR\AdminBundle\Twig;

class PlugExtension extends \Twig_Extension
{

    /**
     * @var \Symfony\Component\DependencyInjection\Container
     */
    private $container;


    /**
     * PlugExtension constructor.
     *
     * @param \Symfony\Component\DependencyInjection\Container $container
     */
    public function __construct($container)
    {
        $this->container = $container;
    }


    /**
     * Returns a list of filters to add to the existing list.
     *
     * @return array An array of filters
     */
    public function getFilters()
    {  
        return array(
            new \Twig_SimpleFilter('datafield_plugin', array($this, 'datafieldPluginFilter')),
            new \Twig_SimpleFilter('datatype_plugin', array($this, 'datatypePluginFilter')),
            new \Twig_SimpleFilter('xml', array($this, 'xmlFilter')),
            new \Twig_SimpleFilter('is_public', array($this, 'isPublicFilter')),
            new \Twig_SimpleFilter('user_string', array($this, 'userStringFilter')),
            new \Twig_SimpleFilter('filesize', array($this, 'filesizeFilter')),
            new \Twig_SimpleFilter('is_empty', array($this, 'isEmptyFilter')),
        );
    }


    /**
     * Loads and executes a RenderPlugin for a datatype.
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin
     * @param array $theme_array
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function datatypePluginFilter($datarecords, $datatype, $render_plugin, $theme_array, $rendering_options)
    {
        try {
            // Load and execute the render plugin
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->execute($datarecords, $datatype, $render_plugin, $theme_array, $rendering_options);
        }
        catch (\Exception $e) {
            return 'Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datatype '.$datatype['id'].': '.$e->getMessage();
        }
    }


    /**
     * Loads and executes a RenderPlugin for a datafield.
     *
     * @param array $datafield
     * @param array $datarecord
     * @param array $render_plugin
     *
     * @return string
     * @throws \Exception
     */
    public function datafieldPluginFilter($datafield, $datarecord, $render_plugin, $themeType = 'master')
    {
        try {
            // Prune $datarecord so the render plugin service can't get values of other datafields
            foreach ($datarecord['dataRecordFields'] as $df_id => $drf) {
                if ( $datafield['id'] !== $df_id )
                    unset( $datarecord['dataRecordFields'][$df_id] );
            }

            // Several other parts of the arrays should be pruned to avoid duplicate/excessive data
            // TODO This is totally wrong - the plugin data is not duplicative.
            /*
            if ( isset($datafield['dataFieldMeta']) && isset($datafield['dataFieldMeta']['renderPlugin']) )
                unset( $datafield['dataFieldMeta']['renderPlugin'] );

            if ( isset($datarecord['dataType']) )
                unset( $datarecord['dataType'] );
            */


            // Load and execute the render plugin
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->execute($datafield, $datarecord, $render_plugin, $themeType);
        }
        catch (\Exception $e) {
            return 'Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datafield '.$datafield['id'].' Datarecord '.$datarecord['id'].': '.$e->getMessage();
        }
    }


    /**
     * Converts invalid XML characters to their XML-safe format.
     * 
     * @param string $str
     * 
     * @return string
     * @throws \Exception
     */
    public function xmlFilter($str)
    {
        try {
            $str = str_replace( array('&'), array('&amp;'), $str);
            $str = str_replace( array('>', '<', '"'), array('&gt;', '&lt;', '&quot;') , $str);

            return $str;
        }
        catch (\Exception $e) {
            throw new \Exception("Error executing XML filter");
        }
    }


    /**
     * Returns whether the given DateTime object is considered by ODR to represent a "public" date or not
     *
     * @param \DateTime $obj
     *
     * @return bool
     * @throws \Exception
     */
    public function isPublicFilter($obj)
    {
        try {
            if ( $obj instanceof \DateTime ) {
                if ($obj->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
                    return false;
                else
                    return true;
            }
            else {
                throw new \Exception();
            }
        }
        catch (\Exception $e) {
            throw new \Exception("Error executing is_public filter");
        }
    }


    /**
     * Given an array representation of ODR's user object, return a string representation of that user's name
     *
     * @param array $obj
     *
     * @return string
     * @throws \Exception
     */
    public function userStringFilter($obj)
    {
        try {
            if ( !is_array($obj) )
                throw new \Exception('Did not receive array');

            if ( isset($obj['firstName']) && isset($obj['lastName']) && $obj['firstName'] !== '')
                return $obj['firstName'].' '.$obj['lastName'];
            else if ( isset($obj['email']) )
                return $obj['email'];
            else
                throw new \Exception('Malformed array');
        }
        catch (\Exception $e) {
            throw new \Exception( "Error executing user_string filter: ".$e->getMessage() );
        }
    }


    /**
     * Converts a filesize in bytes to a more human-friendly format.
     *
     * @param string $obj
     *
     * @return string
     * @throws \Exception
     */
    public function filesizeFilter($obj)
    {
        try {
            if ( !is_numeric($obj) )
                throw new \Exception('Did not receive a numeric value');

            $filesize = floatval($obj);
            $ext = '';

            if ($filesize < 1024) {
                $ext = ' B';
            }
            else if ($filesize < 1048576) {
                $filesize = floor(($filesize / 1024.0) * 10.0) / 10.0;
                $ext = ' Kb';
            }
            else if ($filesize < 1073741824) {
                $filesize = floor(($filesize / 1024.0 / 1024.0) * 100.0) / 100.0;
                $ext = ' Mb';
            }
            else if ($filesize < 1099511627776) {
                $filesize = floor(($filesize / 1024.0 / 1024.0 / 1024.0) * 100.0) / 100.0;
                $ext = ' Gb';
            }
            else {
                $filesize = floor(($filesize / 1024.0 / 1024.0 / 1024.0 / 1024.0) * 100.0) / 100.0;
                $ext = ' Tb';
            }

            return strval($filesize).$ext;
        }
        catch (\Exception $e) {
            throw new \Exception( "Error executing filesize filter: ".$e->getMessage() );
        }
    }


    /**
     * Returns whether the provided theme_element should be considered "empty", and therefore not displayed.
     *
     * @param array $theme_element
     * @param array $datarecord
     * @param array $datatype
     * @param string $mode  'display' or 'edit'
     *
     * @return bool
     * @throws \Exception
     */
    public function isEmptyFilter($theme_element, $datarecord, $datatype, $mode)
    {
        try {
            if ( !isset($theme_element['themeElementMeta']) )
                throw new \Exception('Array does not describe a theme_element');

            // If the theme element itself is marked has "hidden", then don't display regardless
            //  of contents
            if ( $theme_element['themeElementMeta']['hidden'] == 1 )
                return true;


            // If the theme element has datafield entries...
            if ( isset($theme_element['themeDataFields']) && count($theme_element['themeDataFields']) > 0 ) {

                // ...it is not considered empty if at least one of those datafields has not been filtered out, and is not hidden
                foreach ($theme_element['themeDataFields'] as $num => $tdf) {
                    $df_id = $tdf['dataField']['id'];

                    if ( $tdf['hidden'] == 0 && isset($datatype['dataFields']) && isset($datatype['dataFields'][$df_id]) )
                        return false;
                }
            }

            // If the theme element has a child datatype entry...
            if ( isset($theme_element['themeDataType']) && count($theme_element['themeDataType']) > 0 ) {

                // ...and that entry has not been filtered out and is not hidden...
                foreach ($theme_element['themeDataType'] as $num => $tdt) {

                    // Note: Display mode won't pass this check if the child datatype doesn't have any child/linked datarecords for this datatype
                    //  Edit mode will apparently always pass this check
                    if ( $tdt['hidden'] == 0 && isset($tdt['dataType']) && count($tdt['dataType']) > 0 ) {

                        if ( $tdt['is_link'] == 0 && $mode == 'edit' ) {
                            // This theme element contains a child datatype, and is therefore never considered "empty" when in Edit mode
                            return false;
                        }
                        else {
                            // This theme element contains a linked datatype...
                            $child_datatype_id = $tdt['dataType']['id'];

                            // ...it's only considered empty when there are no linked datarecords of this linked datatype
                            if ( isset($datarecord['children']) && isset($datarecord['children'][$child_datatype_id]) && count($datarecord['children'][$child_datatype_id]) > 0 )
                                return false;
                            else
                                return true;
                        }
                    }
                }
            }

            // Otherwise, the theme element is empty and should not be displayed
            return true;
        }
        catch (\Exception $e) {
            throw new \Exception( "Error executing is_empty filter: ".$e->getMessage() );
        }
    }


    /**
     * Returns the name of the extension.
     *
     * @return string The extension name
     */
    public function getName()
    {  
        return 'plug_extension';
    }
}
