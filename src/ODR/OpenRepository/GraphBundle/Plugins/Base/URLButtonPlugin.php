<?php

/**
 * Open Data Repository Data Publisher
 * URL Button Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This plugin, unlike the "URL Plugin", ignores the contents of the datafield to turn it into a
 * button.  When clicked, the button takes the user to a URL defined in the plugin options.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Entities
// Events
// Services
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class URLButtonPlugin implements DatafieldPluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * URLButtonPlugin constructor.
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

            // The URLButton Plugin should work in the contexts that render something
            if ( $context === 'display' || $context === 'edit'
                || $context === 'mass_edit' || $context === 'fake_edit'
            ) {
                return true;
            }
        }

        return false;
    }


    /**
     * Executes the URLButton Plugin on the provided datafield
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
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // The value of the datafield doesn't matter
            $value = '';

            // Get all the options of the plugin...
            $button_label = '';
            if ( isset($options['button_label']) && $options['button_label'] !== '' )
                $button_label = $options['button_label'];

            $target_url = '';
            if ( isset($options['target_url']) && $options['target_url'] !== '' )
                $target_url = $options['target_url'];

            $render_in_display = false;
            if ( isset($options['render_in_display']) && $options['render_in_display'] === 'yes' )
                $render_in_display = true;

            $render_in_edit = false;
            if ( isset($options['render_in_edit']) && $options['render_in_edit'] === 'yes' )
                $render_in_edit = true;

            $problem_option = '';
            if ( $button_label === '' )
                $problem_option = 'button_label';
            if ( $target_url === '' )
                $problem_option = 'target_url';


            // Replace the datafield with the button
            $output = "";
            if ( $problem_option !== '' ) {
                // If there's a problem, then only actually display the error if the user wants
                //  the plugin to work in that context
                if (
                    ($rendering_options['context'] === 'edit' && $render_in_edit)
                    || ($rendering_options['context'] === 'display' && $render_in_display)
                ) {
                    $output = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:Base:URLButton/url_button_error.html.twig',
                        array(
                            'problem_option' => $problem_option,
                            'is_datatype_admin' => $is_datatype_admin,
                        )
                    );
                }
                else {
                    // ...if the user didn't want the plugin to work in that context, then display
                    //  nothing instead
                    $output = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:Base:URLButton/url_button_hidden_datafield.html.twig'
                    );
                }
            }
            else if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:URLButton/url_button_display_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'button_label' => $button_label,
                        'target_url' => $target_url,
                        'render_in_display' => $render_in_display,
                    )
                );
            }
            else if ( $rendering_options['context'] === 'edit' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:URLButton/url_button_edit_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'button_label' => $button_label,
                        'target_url' => $target_url,
                        'render_in_edit' => $render_in_edit,
                    )
                );
            }
            else {
                // The plugin intentionally activates on more than just the display/edit contexts,
                //  but it does so specifically to prevent the default field render from activating
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:URLButton/url_button_hidden_datafield.html.twig'
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
