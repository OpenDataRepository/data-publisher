<?php

/**
* Open Data Repository Data Publisher
* Plugin Extension
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* Custom Twig extensions required for the project...
*/

//  ODR/AdminBundle/Twig/Extension.php;
namespace ODR\AdminBundle\Twig;

class PlugExtension extends \Twig_Extension
{

    /**
     * TODO: short description.
     * 
     * @param mixed $container 
     */
    public function __construct($container)
    {
        $this->container = $container;
/*
        if ($this->container->isScopeActive('request')) {
            $this->request = $this->container->get('request');
        }
*/
    }

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getFilters()
    {  
        return array(
            new \Twig_SimpleFilter('plug', array($this, 'plugFilter')),
            new \Twig_SimpleFilter('xml', array($this, 'xmlFilter')),
        );
    }

    /**
     * TODO 
     * 
     * @return TODO
     */
    public function getFunctions()
    {
        return array(
//            new \Twig_SimpleFunction('radiooptionlist', 'buildRadioOptionArrayFunction'),
//            new \Twig_SimpleFunction('radiooptionlist', 'PlugExtension::buildRadioOptionArrayFunction'),
            new \Twig_SimpleFunction('radiooptionlist', function($radio_options, $radio_selections)
                {
                    // For some reason, this function has to be declared in-line...I couldn't get it to work otherwise
                    $option_list = array();

                    //
                    try {
                        // No point if there's no radio options
                        if ($radio_options == null)
                            return $option_list;

                        // 
                        foreach ($radio_options as $radio_option)
                            $option_list[ $radio_option->getId() ] = 0;

                        // Ensure there are actually radio selections prior to attempting to determine if an option is selected or not
                        if ( $radio_selections !== null ) {
                            foreach ($radio_selections as $radio_selection) {
                                if ($radio_selection->getSelected() == true)
                                    $option_list[ $radio_selection->getRadioOption()->getId() ] = 1;
                            }
                        }
                    }
                    catch (\Exception $e) {
                        throw new \Exception("error in Twig function: radiooptionlist");
                    }
    
                    return $option_list;
                }
            ),
        );
    }


    /**
     * TODO: short description.
     * TODO - I believe $mytheme is unused...
     *
     * @param DataRecordFields $obj   
     * @param RenderPlugin $render_plugin 
     * @param string? $mytheme 
     * @param string $render_type
     * 
     * @return TODO
     */
    public function plugFilter($obj, $render_plugin, $mytheme = "", $render_type = "default") 
    {
        try {
            if(is_object($render_plugin)) {
                $svc = $this->container->get($render_plugin->getPluginClassName());
                return $svc->execute($obj, $render_plugin, $mytheme, $render_type);
            }
            else if(is_object($obj)) {
                return $obj->getDataFields()->getFieldName();
            }
            else {
                return "";
            }    
        }
        catch (\Exception $e) {
            throw new \Exception("Error loading RenderPlugin \'".$render_plugin->getPluginName()."\' for obj ".$obj->getId());
        }
    }

    /**
     * Converts invalid XML characters to their XML-safe forms.
     * 
     * @param string $str
     * 
     * @return string
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
     * TODO: delete this function?
     * 
     * @param array $radio_options
     * @param array $radio_selections
     * 
     * @return TODO
     */
/*
    public function buildRadioOptionArrayFunction($radio_options, $radio_selections, $selected_only = true)
    {

        $option_list = array();

        //
        try {
            if (!$selected_only) {
                foreach ($radio_options as $radio_option) 
                    $option_list[ $radio_option->getId() ] = 0;
            }

            foreach ($radio_selections as $radio_selection) {
                if ($radio_selection->getSelected() == true)
                    $option_list[ $radio_selection->getRadioOption()->getId() ] = 1;
            }
        }
        catch (\Exception $e) {
            throw new \Exception("error in Twig function: buildRadioOptionArrayFunction()");
        }

        return $option_list;

    }
*/

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getName()
    {  
        return 'plug_extension';
    }
}
