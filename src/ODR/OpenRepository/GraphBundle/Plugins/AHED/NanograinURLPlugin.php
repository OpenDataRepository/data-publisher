<?php 

/**
 * Open Data Repository Data Publisher
 * Nanograin URL Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\AHED;

// Entities
// Events
// Services
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class NanograinURLPlugin implements DatafieldPluginInterface
{

    /**
     * @var DatarecordInfoService
     */
    private $datarecord_info_service;

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * Nanograin URLPlugin constructor.
     *
     * @param DatarecordInfoService $datarecord_info_service
     * @param EngineInterface $templating
     */
    public function __construct(
        DatarecordInfoService $datarecord_info_service,
        EngineInterface $templating
    ) {
        $this->datarecord_info_service = $datarecord_info_service;
        $this->templating = $templating;
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

            // This plugin should work in the 'display' and 'edit' contexts...the latter because we
            //  don't want an empty field displaying in edit mode for the moment
            if ( $context === 'display' || $context === 'edit' )
                return true;
        }

        return false;
    }


    /**
     * Executes the Nanograin URL Plugin on the provided datafield
     *
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $render_plugin_instance
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $rendering_options)
    {

        try {
            // ----------------------------------------
            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Get all the options of the plugin...
            $prepend = '';
            if ( isset($options['base_url']) && $options['base_url'] !== 'auto' )
                $prepend = $options['base_url'];

            $append = '';
            if ( isset($options['post_url']) && $options['post_url'] !== 'auto' )
                $append = $options['post_url'];

            $button_text = '';
            if ( isset($options['button_text']) )
                $button_text = $options['button_text'];

            if ( !isset($options['file_field_id']) )
                throw new \Exception('File field ID not defined');
            $file_datafield_id = $options['file_field_id'];


            // ----------------------------------------
            // Because this is a datafield plugin, it technically doesn't have access to values of
            //  other datafields
            $dr_id = $datarecord['id'];
            $gdr_id = $datarecord['grandparent']['id'];
            $dr_array = $this->datarecord_info_service->getDatarecordArray($gdr_id, false);
            $dr = $dr_array[$dr_id];

            // Grab a file id from the configured datafield
            $file_id = '';
            if ( isset($dr['dataRecordFields'][$file_datafield_id]) ) {
                $drf = $dr['dataRecordFields'][$file_datafield_id];

                if ( !empty($drf['file']) ) {
                    foreach ($drf['file'] as $file_num => $file)
                        $file_id = $file['id'];
                }
            }

            // Convert the file id into a URL
            $str = '';
            if ( $file_id !== '' )
                $str = $prepend.$file_id.$append;

            $output = "";
            if ( $rendering_options['context'] === 'display' || $rendering_options['context'] === 'edit' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:AHED:NanograinURL/nanograinurl_display_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'button_text' => $button_text,
                        'url' => $str,
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
