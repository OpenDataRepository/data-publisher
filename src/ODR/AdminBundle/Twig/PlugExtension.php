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
        );
    }


    /**
     * Loads and executes a RenderPlugin for a datatype.
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin
     * @param array $theme
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function datatypePluginFilter($datarecords, $datatype, $render_plugin, $theme, $rendering_options)
    {
        try {
            // Re-organize list of datarecords into
            $datarecord_array = array();
            foreach ($datarecords as $num => $dr)
                $datarecord_array[ $dr['id'] ] = $dr;

            // Load and execute the render plugin
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->execute($datarecord_array, $datatype, $render_plugin, $theme, $rendering_options);
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

            // Several other parts of the arrays should be pruned to avoid duplicate/exccessive data
            if ( isset($datafield['dataFieldMeta']) && isset($datafield['dataFieldMeta']['renderPlugin']) )
                unset( $datafield['dataFieldMeta']['renderPlugin'] );

            if ( isset($datarecord['dataType']) )
                unset( $datarecord['dataType'] );


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
     * Returns the name of the extension.
     *
     * @return string The extension name
     */
    public function getName()
    {  
        return 'plug_extension';
    }
}
