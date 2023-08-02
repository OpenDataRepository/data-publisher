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

// Entities
use ODR\AdminBundle\Entity\RenderPlugin;
// Interfaces
use ODR\OpenRepository\GraphBundle\Plugins\ArrayPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ArrayPluginReturn;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ThemeElementPluginInterface;

class PlugExtension extends \Twig_Extension
{

    /**
     * @var \Symfony\Component\DependencyInjection\Container
     */
    private $container;

    /**
     * @var array
     */
    private $plugin_types;

    /**
     * PlugExtension constructor.
     *
     * @param \Symfony\Component\DependencyInjection\Container $container
     */
    public function __construct($container)
    {
        $this->container = $container;

        $this->plugin_types = array(
            RenderPlugin::DATATYPE_PLUGIN => 'datatype',
            RenderPlugin::THEME_ELEMENT_PLUGIN => 'themeElement',
            RenderPlugin::DATAFIELD_PLUGIN => 'datafield',
            RenderPlugin::ARRAY_PLUGIN => 'array',
        );
    }


    /**
     * Returns a list of filters to add to the existing list.
     *
     * @return array An array of filters
     */
    public function getFilters()
    {
        return array(
            new \Twig\TwigFilter('can_execute_array_plugin', array($this, 'canExecuteArrayPluginFilter')),
            new \Twig\TwigFilter('can_execute_datatype_plugin', array($this, 'canExecuteDatatypePluginFilter')),
            new \Twig\TwigFilter('can_execute_theme_element_plugin', array($this, 'canExecuteThemeElementPluginFilter')),
            new \Twig\TwigFilter('can_execute_datafield_plugin', array($this, 'canExecuteDatafieldPluginFilter')),
            new \Twig\TwigFilter('array_plugin', array($this, 'arrayPluginFilter')),
            new \Twig\TwigFilter('datatype_plugin', array($this, 'datatypePluginFilter')),
            new \Twig\TwigFilter('theme_element_plugin_placeholder', array($this, 'themeElementPluginPlaceholderFilter')),
            new \Twig\TwigFilter('theme_element_plugin', array($this, 'themeElementPluginFilter')),
            new \Twig\TwigFilter('datafield_plugin', array($this, 'datafieldPluginFilter')),
            new \Twig\TwigFilter('get_mass_edit_override_fields', array($this, 'getMassEditOverideFieldsFilter')),

            new \Twig\TwigFilter('comma', array($this, 'commaFilter')),
            new \Twig\TwigFilter('xml', array($this, 'xmlFilter')),
            new \Twig\TwigFilter('is_public', array($this, 'isPublicFilter')),
            new \Twig\TwigFilter('user_string', array($this, 'userStringFilter')),
            new \Twig\TwigFilter('filesize', array($this, 'filesizeFilter')),
            new \Twig\TwigFilter('is_empty', array($this, 'isEmptyFilter')),
            new \Twig\TwigFilter('is_filtered', array($this, 'isFilteredDivFilter')),
        );
    }


    /**
     * Returns whether the Array RenderPlugin should be run in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool|string
     * @throws \Exception
     */
    public function canExecuteArrayPluginFilter($render_plugin_instance, $datatype, $rendering_options)
    {
        try {
            // Determine whether the render plugin should be run
            $render_plugin = $render_plugin_instance['renderPlugin'];
            if ( $render_plugin['plugin_type'] !== RenderPlugin::ARRAY_PLUGIN )
                return '<div class="ODRPluginErrorDiv">ERROR: Unable to render the '.$this->plugin_types[ $render_plugin['plugin_type'] ].' RenderPlugin "'.$render_plugin['pluginName'].'" attached to Datatype '.$datatype['id'].' as an Array Plugin</div>';

            /** @var ArrayPluginInterface $svc */
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->canExecutePlugin($render_plugin_instance, $datatype, $rendering_options);
        }
        catch (\Exception $e) {
            if ( $this->container->getParameter('kernel.environment') === 'dev' )
                throw $e;
            else
                return '<div class="ODRPluginErrorDiv">Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datatype '.$datatype['id'].': '.$e->getMessage().'</div>';
        }
    }


    /**
     * Returns whether the Datatype RenderPlugin should be run in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool|string
     * @throws \Exception
     */
    public function canExecuteDatatypePluginFilter($render_plugin_instance, $datatype, $rendering_options)
    {
        try {
            // Determine whether the render plugin should be run
            $render_plugin = $render_plugin_instance['renderPlugin'];
            if ( $render_plugin['plugin_type'] !== RenderPlugin::DATATYPE_PLUGIN )
                return '<div class="ODRPluginErrorDiv">ERROR: Unable to render the '.$this->plugin_types[ $render_plugin['plugin_type'] ].' RenderPlugin "'.$render_plugin['pluginName'].'" attached to Datatype '.$datatype['id'].' as a Datatype Plugin</div>';

            /** @var DatatypePluginInterface $svc */
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->canExecutePlugin($render_plugin_instance, $datatype, $rendering_options);
        }
        catch (\Exception $e) {
            if ( $this->container->getParameter('kernel.environment') === 'dev' )
                throw $e;
            else
                return '<div class="ODRPluginErrorDiv">Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datatype '.$datatype['id'].': '.$e->getMessage().'</div>';
        }
    }


    /**
     * Returns whether the ThemeElement RenderPlugin should be run in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool|string
     * @throws \Exception
     */
    public function canExecuteThemeElementPluginFilter($render_plugin_instance, $datatype, $rendering_options)
    {
        try {
            // Determine whether the render plugin should be run
            $render_plugin = $render_plugin_instance['renderPlugin'];
            if ( $render_plugin['plugin_type'] !== RenderPlugin::THEME_ELEMENT_PLUGIN )
                return '<div class="ODRPluginErrorDiv">ERROR: Unable to render the '.$this->plugin_types[ $render_plugin['plugin_type'] ].' RenderPlugin "'.$render_plugin['pluginName'].'" attached to Datatype '.$datatype['id'].' as a ThemeElement Plugin</div>';

            /** @var ThemeElementPluginInterface $svc */
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->canExecutePlugin($render_plugin_instance, $datatype, $rendering_options);
        }
        catch (\Exception $e) {
            if ( $this->container->getParameter('kernel.environment') === 'dev' )
                throw $e;
            else
                return '<div class="ODRPluginErrorDiv">Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datatype '.$datatype['id'].': '.$e->getMessage().'</div>';
        }
    }


    /**
     * Returns whether the Datafield RenderPlugin should be run in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datafield
     * @param array $datarecord
     * @param array $rendering_options
     *
     * @return bool|string
     * @throws \Exception
     */
    public function canExecuteDatafieldPluginFilter($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        try {
            // Determine whether the render plugin should be run
            $render_plugin = $render_plugin_instance['renderPlugin'];
            if ( $render_plugin['plugin_type'] !== RenderPlugin::DATAFIELD_PLUGIN )
                return '<div class="ODRPluginErrorDiv">ERROR: Unable to render the '.$this->plugin_types[ $render_plugin['plugin_type'] ].' RenderPlugin "'.$render_plugin['pluginName'].'" attached to Datafield '.$datafield['id'].' as a Datafield Plugin</div>';

            /** @var DatafieldPluginInterface $svc */
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options);
        }
        catch (\Exception $e) {
            if ( $this->container->getParameter('kernel.environment') === 'dev' )
                throw $e;
            else
                return '<div class="ODRPluginErrorDiv">Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datafield '.$datafield['id'].': '.$e->getMessage().'</div>';
        }
    }


    /**
     * Loads and executes a RenderPlugin for a datatype.
     *
     * @param array $datarecord_array
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     *
     * @return ArrayPluginReturn|string
     * @throws \Exception
     */
    public function arrayPluginFilter($datarecord_array, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array())
    {
        try {
            // Ensure this only is run on a render plugin for a datatype
            $render_plugin = $render_plugin_instance['renderPlugin'];
            if ( $render_plugin['plugin_type'] !== RenderPlugin::ARRAY_PLUGIN )
                return '<div class="ODRPluginErrorDiv">ERROR: Unable to render the '.$this->plugin_types[ $render_plugin['plugin_type'] ].' RenderPlugin "'.$render_plugin['pluginName'].'" attached to Datatype '.$datatype['id'].' as an Array Plugin</div>';

            // Load and execute the render plugin
            /** @var ArrayPluginInterface $svc */
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->execute($datarecord_array, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord);
        }
        catch (\Exception $e) {
            if ( $this->container->getParameter('kernel.environment') === 'dev' )
                throw $e;
            else
                return '<div class="ODRPluginErrorDiv">Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datatype '.$datatype['id'].': '.$e->getMessage().'</div>';
        }
    }


    /**
     * Loads and executes a RenderPlugin for a datatype.
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param array $token_list
     *
     * @return string
     * @throws \Exception
     */
    public function datatypePluginFilter($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {
        try {
            // Ensure this only is run on a render plugin for a datatype
            $render_plugin = $render_plugin_instance['renderPlugin'];
            if ( $render_plugin['plugin_type'] !== RenderPlugin::DATATYPE_PLUGIN )
                return '<div class="ODRPluginErrorDiv">ERROR: Unable to render the '.$this->plugin_types[ $render_plugin['plugin_type'] ].' RenderPlugin "'.$render_plugin['pluginName'].'" attached to Datatype '.$datatype['id'].' as a Datatype Plugin</div>';

            // Load and execute the render plugin
            /** @var DatatypePluginInterface $svc */
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord, $datatype_permissions, $datafield_permissions, $token_list);
        }
        catch (\Exception $e) {
            if ( $this->container->getParameter('kernel.environment') === 'dev' )
                throw $e;
            else
                return '<div class="ODRPluginErrorDiv">Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datatype '.$datatype['id'].': '.$e->getMessage().'</div>';
        }
    }


    /**
     * Loads and executes a RenderPlugin for a themeElement.
     *
     * @param array $datarecord
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function themeElementPluginFilter($datarecord, $datatype, $render_plugin_instance, $theme_array, $rendering_options)
    {
        try {
            // Ensure this only is run on a render plugin for a datatype
            $render_plugin = $render_plugin_instance['renderPlugin'];
            if ( $render_plugin['plugin_type'] !== RenderPlugin::THEME_ELEMENT_PLUGIN )
                return '<div class="ODRPluginErrorDiv">ERROR: Unable to render the '.$this->plugin_types[ $render_plugin['plugin_type'] ].' RenderPlugin "'.$render_plugin['pluginName'].'" attached to Datatype '.$datatype['id'].' as a ThemeElement Plugin</div>';

            // Load and execute the render plugin
            /** @var ThemeElementPluginInterface $svc */
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->execute($datarecord, $datatype, $render_plugin_instance, $theme_array, $rendering_options);
        }
        catch (\Exception $e) {
            if ( $this->container->getParameter('kernel.environment') === 'dev' )
                throw $e;
            else
                return '<div class="ODRPluginErrorDiv">Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datatype '.$datatype['id'].': '.$e->getMessage().'</div>';
        }
    }


    /**
     * Retrieves placeholder text for a themeElement RenderPlugin.
     *
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function themeElementPluginPlaceholderFilter($datatype, $render_plugin_instance, $theme_array, $rendering_options)
    {
        try {
            // Ensure this only is run on a render plugin for a datatype
            $render_plugin = $render_plugin_instance['renderPlugin'];
            if ( $render_plugin['plugin_type'] !== RenderPlugin::THEME_ELEMENT_PLUGIN )
                return '<div class="ODRPluginErrorDiv">ERROR: Unable to render the '.$this->plugin_types[ $render_plugin['plugin_type'] ].' RenderPlugin "'.$render_plugin['pluginName'].'" attached to Datatype '.$datatype['id'].' as a ThemeElement Plugin</div>';

            // Load and execute the render plugin
            /** @var ThemeElementPluginInterface $svc */
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->getPlaceholderHTML($datatype, $render_plugin_instance, $theme_array, $rendering_options);
        }
        catch (\Exception $e) {
            if ( $this->container->getParameter('kernel.environment') === 'dev' )
                throw $e;
            else
                return '<div class="ODRPluginErrorDiv">Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datatype '.$datatype['id'].': '.$e->getMessage().'</div>';
        }
    }


    /**
     * Loads and executes a RenderPlugin for a datafield.
     *
     * @param array $datafield
     * @param array $datarecord
     * @param array $render_plugin_instance
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function datafieldPluginFilter($datafield, $datarecord, $render_plugin_instance, $rendering_options)
    {
        try {
            // Ensure this only is run on a render plugin for a datafield
            $render_plugin = $render_plugin_instance['renderPlugin'];
            if ( $render_plugin['plugin_type'] !== RenderPlugin::DATAFIELD_PLUGIN )
                return '<div class="ODRPluginErrorDiv">ERROR: Unable to render the '.$this->plugin_types[ $render_plugin['plugin_type'] ].' RenderPlugin "'.$render_plugin['pluginName'].'" attached to Datafield '.$datafield['id'].' as a Datafield Plugin</div>';

            // Prune $datarecord so the render plugin service can't get values of other datafields
            foreach ($datarecord['dataRecordFields'] as $df_id => $drf) {
                if ( $datafield['id'] !== $df_id )
                    unset( $datarecord['dataRecordFields'][$df_id] );
            }

            // Load and execute the render plugin
            /** @var DatafieldPluginInterface $svc */
            $svc = $this->container->get($render_plugin['pluginClassName']);
            return $svc->execute($datafield, $datarecord, $render_plugin_instance, $rendering_options);
        }
        catch (\Exception $e) {
            if ( $this->container->getParameter('kernel.environment') === 'dev' )
                throw $e;
            else
                return '<div class="ODRPluginErrorDiv">Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datafield '.$datafield['id'].' Datarecord '.$datarecord['id'].': '.$e->getMessage().'</div>';
        }
    }


    /**
     * Cleans up the json produced by the API export options.
     *
     * @param string $str
     *
     * @return string
     * @throws \Exception
     */
    public function commaFilter($str)
    {
        try {
            // Super hacky - detect pos of last } and remove remainder
            $pos = strripos($str, "}");
            return substr($str, 0, $pos + 1);
        }
        catch (\Exception $e) {
            throw new \Exception("Error executing COMMA filter: [[[" . $str . "]]]");
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
     * For Display or Edit mode, returns whether the provided theme_element should be considered
     *  "empty", and therefore not displayed.
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

            // If the theme element has themeDatafield entries...
            if ( !empty($theme_element['themeDataFields']) ) {

                // ...it is not considered empty if at least one of those datafields has not been filtered out, and is not hidden
                foreach ($theme_element['themeDataFields'] as $num => $tdf) {
                    $df_id = $tdf['dataField']['id'];

                    if ( $tdf['hidden'] == 0 && isset($datatype['dataFields']) && isset($datatype['dataFields'][$df_id]) )
                        return false;
                }
            }

            // If the theme element has a themeDatatype entry...
            if ( !empty($theme_element['themeDataType']) ) {

                // ...and that entry has not been filtered out and is not hidden...
                foreach ($theme_element['themeDataType'] as $num => $tdt) {

                    // Note: Display mode won't pass this check if the child datatype doesn't have any child/linked datarecords for this datatype
                    //  Edit mode will apparently always pass this check
                    if ( isset($tdt['dataType']) && count($tdt['dataType']) > 0 ) {

                        $child_datatype_id = $tdt['dataType']['id'];

                        if ( $tdt['is_link'] == 0 && $mode == 'edit' ) {
                            // In edit mode, a theme element is only considered "empty" if there's no child datatype entry due to filtering
                            if ( isset($datatype['descendants'][$child_datatype_id]) && count($datatype['descendants'][$child_datatype_id]['datatype']) > 0 )
                                return false;
                            else
                                return true;
                        }
                        else {
                            // In display mode, or when displaying linked datatypes in edit mode, the theme element is only considered empty when there are no child/linked datarecords
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
     * Returns true if the given theme_element only contains datafields/datatypes that are filtered
     *  from the user's view because of permissions reasons.  If true, then this theme_element can't
     *  really be manipulated by the user at all, so it should be hidden.
     *
     * @param array $theme_element
     * @param array $datatype
     *
     * @return bool
     * @throws \Exception
     */
    public function isFilteredDivFilter($theme_element, $datatype)
    {
        try {
            if ( !isset($theme_element['themeElementMeta']) )
                throw new \Exception('Array does not describe a theme_element');

            if ( isset($theme_element['themeDataFields']) && count($theme_element['themeDataFields']) > 0 ) {
                // If the theme element has datafield entries...
                foreach ($theme_element['themeDataFields'] as $num => $tdf) {
                    $df_id = $tdf['dataField']['id'];

                    // ...it shouldn't be filtered out if at least one of the datafield entries are viewable
                    if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$df_id]) )
                        return false;
                }

                // All of the datafield entries are filtered...so the theme element should be filtered as well
                return true;
            }
            else if ( isset($theme_element['themeDataType']) && count($theme_element['themeDataType']) > 0 ) {
                // If the theme element has a child/linked datatype entry...
                foreach ($theme_element['themeDataType'] as $num => $tdt) {
                    if ( isset($tdt['dataType']) && count($tdt['dataType']) > 0 ) {

                        $child_datatype_id = $tdt['dataType']['id'];

                        // ...it shouldn't be filtered out if at least one of the datatype entries are viewable
                        if ( isset($datatype['descendants'][$child_datatype_id]) && count($datatype['descendants'][$child_datatype_id]['datatype']) > 0 )
                            return false;
                    }
                }

                // All of the datatype entries are filtered...so the theme element should be filtered as well
                return true;
            }
            else {
                // Otherwise, the theme element is empty, and should be displayed
                return false;
            }
        }
        catch (\Exception $e) {
            throw new \Exception( "Error executing is_empty_theme filter: ".$e->getMessage() );
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
