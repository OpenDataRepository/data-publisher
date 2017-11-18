<?php 

/**
 * Open Data Repository Data Publisher
 * Chemin ED1 Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This plugin is specifically for CheMin ED1 products, and "combines" three file datafields into a single
 * compact display.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class QanalyzePlugin
{

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * CheminED1Plugin constructor.
     *
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(EngineInterface $templating, Logger $logger) {
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Executes the Chemin ED1 Plugin on the provided datarecords
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
    public function execute($datarecords = array(), $datatype, $render_plugin, $theme_array = array(), $rendering_options = "")
    {

        try {
            // ----------------------------------------
            // Grab various properties from the render plugin array
            $render_plugin_instance = $render_plugin['renderPluginInstance'][0];
            $render_plugin_map = $render_plugin_instance['renderPluginMap'];
            $render_plugin_options = $render_plugin_instance['renderPluginOptions'];

            // Remap render plugin by name => value
            $max_option_date = 0;
            $options = array();
            foreach($render_plugin_options as $option) {
                if ( $option['active'] == 1 )
                    $option_date = new \DateTime($option['updated']->date);
                    $us = $option_date->format('u');
                    $epoch = strtotime($option['updated']->date) * 1000000;
                    $epoch = $epoch + $us;
                    if($epoch > $max_option_date) {
                        $max_option_date = $epoch;
                    }
                    $options[ $option['optionName'] ] = $option['optionValue'];
            }

            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($render_plugin_map as $rpm) {
                // Get the entities connected by the render_plugin_map entity??
                $rpf = $rpm['renderPluginFields'];
                $df_id = $rpm['dataField']['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$df_id]) )
                    $df = $datatype['dataFields'][$df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf['fieldName'].'", mapped to df_id '.$df_id);

                // Grab the field name specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                $datafield_mapping[$key] = array('datafield' => $df);
            }


            // ----------------------------------------
            // The names of the files uploaded to this child datarecord should have two parts...
            // ...the second part being more or less a description of the contents of the file, which can be ignored
            // ...the first part being a key that matches across all the different file types
            // Render and return the graph html


            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Qanalyze:qanalyze.html.twig'
            );
            return $output;
        }
        catch (\Exception $e) {
            // TODO Can we remove this?...
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Default:default_error.html.twig',
                array(
                    'message' => $e->getMessage()
                )
            );
            throw new \Exception( $output );
        }
    }
}
