<?php

/**
 * Open Data Repository Data Publisher
 * JSmol Trigger Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The American Mineralogist Crystal Structure database (AMCSD) originally had the ability to render
 * CIF files with JSmol (https://jmol.sourceforge.net/) (https://wiki.jmol.org/index.php/JSmol).
 *
 * This plugin restores renders a clickable icon in a file datafield's header, which triggers an
 * ODR modal...this modal calls JSmolTriggerController.php to load/render the JSmol object.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// ODR
// Events
// Exceptions
// Services
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldHeaderPluginInterface;
// Symfony
use Psr\Log\LoggerInterface;


class JSmolTriggerPlugin implements DatafieldHeaderPluginInterface
{

    /**
     * JSmolTrigger Plugin constructor.
     *
     * @param \Twig\Environment $templating
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly \Twig\Environment $templating,
        private readonly LoggerInterface $logger
    ) {
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // The JSmolTrigger Plugin should work in both the 'display' and 'edit' contexts
            if ( $context === 'display' || $context === 'edit' )
                return true;
        }

        return false;
    }


    /**
     * @inheritDoc
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $rendering_options)
    {
        try {
            // ----------------------------------------
            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            $background_color = "#4F4F4F";
            if ( isset($options['background_color']) )
                $background_color = $options['background_color'];

            // Should always exist, but be safe...
            $datafield_id = $datafield['id'];

            $filename = 'JSmol Viewer';
            if ( isset($datarecord['dataRecordFields'][$datafield_id]['file'][0]['fileMeta']['originalFileName']) )
                $filename = $datarecord['dataRecordFields'][$datafield_id]['file'][0]['fileMeta']['originalFileName'];

            // ----------------------------------------
            $output = "";
            if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    '@ODROpenRepositoryGraph/RRUFF/JSmolTrigger/jsmol_trigger_display_addon.html.twig',
                    [
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'filename' => $filename,
                        'background' => $background_color,
                    ]
                );
            }
            else if ( $rendering_options['context'] === 'edit' ) {
                $output = $this->templating->render(
                    '@ODROpenRepositoryGraph/RRUFF/JSmolTrigger/jsmol_trigger_edit_addon.html.twig',
                    [
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'filename' => $filename,
                        'background' => $background_color,
                    ]
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
