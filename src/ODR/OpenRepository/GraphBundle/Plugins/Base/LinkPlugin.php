<?php

/**
 * Open Data Repository Data Publisher
 * Link Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The link plugin renders a button that will take you to the linked
 * Datarecord's page on ODR, instead of rendering that linked Datarecord's
 * contents.  The contents of the Datatype's external_id or name Datafield
 * can be optionally displayed next to this button, for use when multiple
 * linked Datarecords are allowed.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class LinkPlugin
{
    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * LinkPlugin constructor.
     *
     * @param EngineInterface $templating
     */
    public function __construct(EngineInterface $templating) {
        $this->templating = $templating;
    }


    /**
     * Executes the Link Plugin on the provided datarecords
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
    public function execute($datarecords, $datatype, $render_plugin, $theme_array, $rendering_options)
    {

        try {

//            $str = '<pre>'.print_r($datarecords, true)."\n".print_r($datatype, true)."\n".print_r($render_plugin, true)."\n".print_r($theme, true).'</pre>';
//            throw new \Exception($str);


            // ----------------------------------------
            // Grab various properties from the render plugin array
            $render_plugin_instance = $render_plugin['renderPluginInstance'][0];
            $render_plugin_map = $render_plugin_instance['renderPluginMap'];
            $render_plugin_options = $render_plugin_instance['renderPluginOptions'];

            // Remap render plugin by name => value
            $options = array();
            foreach($render_plugin_options as $option) {
                if ( $option['active'] == 1 )
                    $options[ $option['optionName'] ] = $option['optionValue'];
            }


            // ----------------------------------------
            // Determine which datafield's contents to use as a label for each datarecord's link button
            $labels = array();
            if ( isset($options['display_label']) && $options['display_label'] !== 'none' ) {
                foreach ($datarecords as $dr_id => $dr) {
                    if ( $options['display_label'] == 'external_id' )
                        $labels[$dr_id] = $dr['externalIdField_value'];
                    else if ( $options['display_label'] == 'name' )
                        $labels[$dr_id] = $dr['nameField_value'];
                }
            }


            // ----------------------------------------
            // Determine whether to this is being called as part of rendering a linked datatype...
            $output = '';
            if ( isset($rendering_options['is_link']) && $rendering_options['is_link'] == 1 ) {
                // ...if yes, then render just the link button and the labels if they exist
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:Link/link.html.twig',
                    array(
                        'datatype' => $datatype,
                        'datarecord_array' => $datarecords,
                        'labels' => $labels,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],
                    )
                );
            }
            else {
                // ...if not, then render using the default layout.  After all, it's not too useful
                //  to only have a link to this datarecord's view page when you're actually on this
                //  datarecord's view page...
                $parent_datarecord_id = '';
                $target_datarecord_id = '';
                foreach ($datarecords as $dr_id => $dr) {
                    $target_datarecord_id = $dr_id;
                    $parent_datarecord_id = $dr['parent']['id'];
                    break;
                }

                // Should only ever be one theme in here at a time...
                $target_theme_id = '';
                foreach ($theme_array as $t_id => $t)
                    $target_theme_id = $t_id;


                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:Link/link_default.html.twig',
                    array(
                        'datatype_array' => array($datatype['id'] => $datatype),
                        'datarecord_array' => $datarecords,
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $datatype['id'],
                        'parent_datarecord_id' => $parent_datarecord_id,
                        'target_datarecord_id' => $target_datarecord_id,
                        'target_theme_id' => $target_theme_id,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],
                    )
                );
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }

}
