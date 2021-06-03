<?php

/**
 * Open Data Repository Data Publisher
 * Link Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The link plugin renders a button that will take you to the linked Datarecord's page on ODR,
 * instead of rendering that linked Datarecord's contents.  The contents of the Datatype's
 * external_id or name Datafield can be optionally displayed next to this button, for use when
 * multiple linked Datarecords are allowed.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class LinkPlugin implements DatatypePluginInterface
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
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        // This render plugin isn't allowed to work when in edit mode
        // TODO - allow execution in Edit mode?
        if ( isset($rendering_options['context']) && $rendering_options['context'] === 'edit' )
            return false;

        // This plugin should only be executed when it's being used to render a linked datatype
        if ( isset($rendering_options['is_link']) && $rendering_options['is_link'] === 1 )
            return true;

        return false;
    }


    /**
     * Executes the Link Plugin on the provided datarecords
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
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {

        try {
            // ----------------------------------------
            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];


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
            // This will only be called on a linked datatype in display mode...
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

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
