<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Instrument Usage Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This plugin generates a button/link so users can quickly get to a list of "RRUFF Samples that
 * have a specific RRUFF Instrument Name".
 *
 * It's considerably easier for this plugin to trigger a two-step process to pull this off instead
 * of creating a link directly to that specific search result...doing it that way would require
 * considerably more effort to deal with caching and/or events, depending on the specific method
 * chosen.
 *
 * This isn't necessarily true for non-table layouts, but might as well use the same scheme for both.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Entities
// Events
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\Routing\Router;


class RRUFFInstrumentUsagePlugin implements DatafieldPluginInterface, TableResultsOverrideInterface
{

    /**
     * @var string
     */
    private $environment;

    /**
     * @var string
     */
    private $site_baseurl;

    /**
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var Router $router
     */
    private $router;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * RRUFFInstrumentUsagePlugin constructor.
     *
     * @param EngineInterface $templating
     */
    public function __construct(
        string $environment,
        string $site_baseurl,
        DatabaseInfoService $database_info_service,
        Router $router,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->environment = $environment;
        $this->site_baseurl = $site_baseurl;
        $this->database_info_service = $database_info_service;
        $this->router = $router;
        $this->templating = $templating;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // This should work in all contexts that actually render something
            if ( $context === 'display' || $context === 'edit'
                || $context === 'mass_edit' || $context === 'fake_edit'
            ) {
                return true;
            }
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
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Get all the options of the plugin...
            $button_label = '';
            if ( isset($options['button_label']) && $options['button_label'] !== '' )
                $button_label = $options['button_label'];

            $target_datatype_id = '';
            if ( isset($options['target_datatype']) && $options['target_datatype'] !== '' )
                $target_datatype_id = $options['target_datatype'];
            $source_datafield_id = '';
            if ( isset($options['source_datafield']) && $options['source_datafield'] !== '' )
                $source_datafield_id = $options['source_datafield'];

            $render_in_display = false;
            if ( isset($options['render_in_display']) && $options['render_in_display'] === 'yes' )
                $render_in_display = true;

            $render_in_edit = false;
            if ( isset($options['render_in_edit']) && $options['render_in_edit'] === 'yes' )
                $render_in_edit = true;

            $problem_option = '';
            if ( $button_label === '' )
                $problem_option = 'button_label';
            if ( $target_datatype_id === '' )
                $problem_option = 'target_datatype';
            if ( $source_datafield_id === '' )
                $problem_option = 'source_datafield';


            // The URL this plugin creates is the same whether it's for a table layout or not
            $url = self::getUrl($render_plugin_instance, $datarecord);


            // ----------------------------------------
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
                        'ODROpenRepositoryGraphBundle:RRUFF:RRUFFInstrumentUsage/rruffinstrumentusage_error.html.twig',
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
                        'ODROpenRepositoryGraphBundle:RRUFF:RRUFFInstrumentUsage/rruffinstrumentusage_hidden_datafield.html.twig'
                    );
                }
            }
            else if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFInstrumentUsage/rruffinstrumentusage_display_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'button_label' => $button_label,
                        'render_in_display' => $render_in_display,

                        'target_url' => $url,
                    )
                );
            }
            else if ( $rendering_options['context'] === 'edit' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFInstrumentUsage/rruffinstrumentusage_edit_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'button_label' => $button_label,
                        'render_in_edit' => $render_in_edit,

                        'target_url' => $url,
                    )
                );
            }
            else {
                // The plugin intentionally activates on more than just the display/edit contexts,
                //  but it does so specifically to prevent the default field render from activating
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFInstrumentUsage/rruffinstrumentusage_hidden_datafield.html.twig'
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
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // The desired URL only depends on one plugin setting and the datarecord id...
        $url = self::getUrl($render_plugin_instance, $datarecord);
        // ...and because it's a datafield plugin, the datafield id is already available
        $link_field_df_id = $datafield['id'];

        // Need one more option value out of the plugin
        $options = $render_plugin_instance['renderPluginOptionsMap'];
        $button_label = $options['button_label'];

        return array(
            $link_field_df_id => '<a target="_blank" href="'.$url.'">'.$button_label.'&nbsp;<i class="fa fa-external-link"></i></a>',
        );
    }


    /**
     * The plugin generates a (static-ish) link to a specific controller action.
     *
     * The action then proceeds to generate the desired search link...which couldn't be done in
     * table layouts without either a) forcing a onPostUpdate check so changes to Instrument Name
     * will delete table cache entries or b) forcing table layouts to have plugin-provided "global"
     * javascript.
     *
     * @param array $render_plugin_instance
     * @param array $datarecord
     * @return string
     */
    private function getUrl($render_plugin_instance, $datarecord)
    {
        // Going to need some info out of the render plugin...
        $options = $render_plugin_instance['renderPluginOptionsMap'];

        // The contents of that field need to get turned into a search key
        $target_datatype_id = $options['target_datatype'];

        // Define the part before the '#' character...
        $dt_array = $this->database_info_service->getDatatypeArray($target_datatype_id, false);

        $search_slug = 'admin';
        if ( isset($dt_array[$target_datatype_id]['dataTypeMeta']['searchSlug']) )
            $search_slug = $dt_array[$target_datatype_id]['dataTypeMeta']['searchSlug'];
        $baseurl = 'https:'.$this->site_baseurl.'/';
        if ( $this->environment === 'dev' )
            $baseurl .= 'app_dev.php/';
        $baseurl .= $search_slug;

        $route = $this->router->generate(
            'odr_plugin_rruff_instrument_usage_redirect',
            array(
                'datarecord_id' => $datarecord['id']
            )
        );

        // This generates the final value for the table cell
        $url = $baseurl.'#'.$route;
        return $url;
    }
}
