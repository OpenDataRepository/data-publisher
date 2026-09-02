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
use ODR\AdminBundle\Entity\RenderPluginOptionsDef;
// Events
// Exceptions
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldHeaderPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\PluginSettingsDialogOverrideInterface;
// Symfony
use Psr\Log\LoggerInterface;


class JSmolTriggerPlugin implements DatafieldHeaderPluginInterface, PluginSettingsDialogOverrideInterface
{

    /**
     * JSmolTrigger Plugin constructor.
     *
     * @param DatabaseInfoService $database_info_service
     * @param \Twig\Environment $templating
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly DatabaseInfoService $database_info_service,
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

    /**
     * @inheritDoc
     */
    public function getRenderPluginOptionsOverride($user, $is_datatype_admin, $render_plugin, $datatype, $datafield = null, $render_plugin_instance = null)
    {
        $custom_rpo_html = [];
        foreach ($render_plugin->getRenderPluginOptionsDef() as $rpo) {
            // This plugin currently has several options, but only "jsmol_config" needs to use a
            //  custom render for the dialog...
            /** @var RenderPluginOptionsDef $rpo */
            if ( $rpo->getUsesCustomRender() ) {
                // This is the "jsmol_config" option...it's using a custom renderbecause it's easier
                //  to have a textarea instead of an <input>
                $jsmol_config_string = '';

                // Might as well use the cache entry
                $datatype = $datafield->getDataType();
                $datatype_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want linked datatypes
                $dt = $datatype_array[$datatype->getId()];

                // The datafield is guaranteed to exist since this is a datafield plugin
                $df = $dt['dataFields'][$datafield->getId()];
                if ( !empty($df['renderPluginInstances']) ) {
                    // The datafield could have more than one renderPluginInstance
                    foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.jsmol_trigger' ) {
                            if ( isset($rpi['renderPluginOptionsMap']['jsmol_config']) )
                                $jsmol_config_string = trim($rpi['renderPluginOptionsMap']['jsmol_config']);
                        }
                    }
                }

                // ...which allows a template to be rendered
                $custom_rpo_html[$rpo->getId()] = $this->templating->render(
                    '@ODROpenRepositoryGraph/RRUFF/JSmolTrigger/plugin_settings_dialog_field_list_override.html.twig',
                    [
                        'rpo_id' => $rpo->getId(),
                        'value' => $jsmol_config_string,
                    ]
                );
            }
        }

        // As a side note, the plugin settings dialog does no logic to determine which options should
        //  have custom rendering...it's solely determined by the contents of the array returned by
        //  this function.  As such, there's no validation whatsoever
        return $custom_rpo_html;
    }
}
