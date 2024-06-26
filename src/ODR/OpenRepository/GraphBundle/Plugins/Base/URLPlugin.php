<?php 

/**
 * Open Data Repository Data Publisher
 * URL Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The url plugin is designed to modify the contents of a datafield to create a clickable URL.
 *
 * The generated HTML will look something like:
 * <a target="_blank" href="{{ prepend_str }}{{ encoded datafield value }}{{ append_str }}">{{ datafield value }}</a>
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Entities
use ODR\AdminBundle\Entity\RenderPluginInstance;
// Events
use ODR\AdminBundle\Component\Event\PluginAttachEvent;
use ODR\AdminBundle\Component\Event\PluginOptionsChangedEvent;
use ODR\AdminBundle\Component\Event\PluginPreRemoveEvent;
// Services
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class URLPlugin implements DatafieldPluginInterface, TableResultsOverrideInterface
{

    /**
     * @var DatarecordInfoService
     */
    private $dri_service;

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * URLPlugin constructor.
     *
     * @param DatarecordInfoService $dri_service
     * @param EngineInterface $templating
     */
    public function __construct(DatarecordInfoService $dri_service, EngineInterface $templating) {
        $this->dri_service = $dri_service;
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

            // The URL Plugin should work in the 'text' and 'display' contexts
            if ( $context === 'text' || $context === 'display' )
                return true;
        }

        return false;
    }


    /**
     * Executes the URL Plugin on the provided datafield
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


            // ----------------------------------------
            // Grab value of datafield
            $value = '';
            if ( isset($datarecord['dataRecordFields'][ $datafield['id'] ]) ) {
                $drf = $datarecord['dataRecordFields'][ $datafield['id'] ];
                $entity = '';
                switch ( $datafield['dataFieldMeta']['fieldType']['typeClass'] ) {
                    case 'IntegerValue':
                        $entity = $drf['integerValue'];
                        break;
                    case 'ShortVarchar':
                        $entity = $drf['shortVarchar'];
                        break;
                    case 'MediumVarchar':
                        $entity = $drf['mediumVarchar'];
                        break;
                    case 'LongVarchar':
                        $entity = $drf['longVarchar'];
                        break;
                    case 'LongText':
                        $entity = $drf['longText'];
                        break;

                    default:
                        throw new \Exception('Invalid Fieldtype');
                        break;
                }
                $value = trim( $entity[0]['value'] );
            }
            else {
                // No datarecordfield entry for this datarecord/datafield pair...because of the
                //  allowed fieldtypes, the plugin can just use the empty string in this case
                $value = '';
            }


            // Get all the options of the plugin...
            $prepend = '';
            if ( isset($options['base_url']) && $options['base_url'] !== 'auto' )
                $prepend = $options['base_url'];

            $append = '';
            if ( isset($options['post_url']) && $options['post_url'] !== 'auto' )
                $append = $options['post_url'];

            $encode_input = false;
            if ( isset($options['encode_input']) && $options['encode_input'] === 'yes' )
                $encode_input = true;


            // Convert the datafield's value into a URL
            $str = '';
            if ( $value !== '' )
                $str = self::buildURL($prepend, $append, $encode_input, $value);


            $output = "";
            if ( $rendering_options['context'] === 'text' ) {
                $output = $str;
            }
            else if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:URL/url_display_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'value' => $str,
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


    /**
     * Converts the given value into a URL.
     *
     * @param string $prepend
     * @param string $append
     * @param boolean $encode_input
     * @param string $original_value
     * @return string
     */
    private function buildURL($prepend, $append, $encode_input, $original_value)
    {
        // https://tools.ietf.org/html/rfc3986#section-3  only the query and the fragment should
        //  be encoded (if at all), but without having a URL parser handy it's impossible to
        //  automatically and accurately encode a URL.
        // Fortunately, users shouldn't be putting "url-like" and "not-url-like" values in the
        //  same datafield, so handling it with a plugin option should be sufficient...
        $href_value = $original_value;
        if ($encode_input)
            $href_value = urlencode($href_value);

        $str = '<a target="_blank" href="'.$prepend.$href_value.$append.'" class="underline">';

        // Display the prepend/append strings in the datafield contents if configured that way
        if ( isset($options['display_full_url']) && $options['display_full_url'] === 'yes' )
            $str .= $prepend.$original_value.$append;
        else
            $str .= $original_value;

        $str .= '</a>';

        return $str;
    }


    /**
     * Called when a user attaches this render plugin to a datafield.
     *
     * @param PluginAttachEvent $event
     */
    public function onPluginAttach(PluginAttachEvent $event)
    {
        self::clearCacheEntries($event->getRenderPluginInstance());
    }


    /**
     * Called when a user changes RenderPluginOptions or RenderPluginMaps entries for this plugin.
     *
     * @param PluginOptionsChangedEvent $event
     */
    public function onPluginOptionsChanged(PluginOptionsChangedEvent $event)
    {
        self::clearCacheEntries($event->getRenderPluginInstance());
    }


    /**
     * Called when a user removes this render plugin from a datafield.
     *
     * @param PluginPreRemoveEvent $event
     */
    public function onPluginPreRemove(PluginPreRemoveEvent $event)
    {
        self::clearCacheEntries($event->getRenderPluginInstance());
    }


    /**
     * The 'cached_table_data' entries store the values of datafields so the plugins don't have
     * to be executed every single time a search results page is loaded...therefore, when this
     * plugin is removed or a setting is changed, these cache entries need to get deleted
     *
     * @param RenderPluginInstance $rpi
     */
    private function clearCacheEntries($rpi)
    {
        // This is a datafield plugin, so getting the datatype via the datafield...
        $datatype_id = $rpi->getDataField()->getDataType()->getGrandparent()->getId();
        $this->dri_service->deleteCachedTableData($datatype_id);
    }


    /**
     * Returns an array of datafield values that TableThemeHelperService should display, instead of
     * using the values in the datarecord.
     *
     * @param array $render_plugin_instance
     * @param array $datarecord
     * @param array|null $datafield
     *
     * @return string[] An array where the keys are datafield ids, and the values are the strings to display
     */
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // Need to get the super/subscript values
        $render_plugin_options = $render_plugin_instance['renderPluginOptionsMap'];

        $prepend = '';
        if ( isset($render_plugin_options['base_url']) && $render_plugin_options['base_url'] !== 'auto' )
            $prepend = $render_plugin_options['base_url'];
        $append = '';
        if ( isset($render_plugin_options['post_url']) && $render_plugin_options['post_url'] !== 'auto' )
            $append = $render_plugin_options['post_url'];

        $encode_input = false;
        if ( isset($render_plugin_options['encode_input']) && $render_plugin_options['encode_input'] === 'yes' )
            $encode_input = true;


        // Since this is a datafield plugin, $datafield has a value
        $df_id = $datafield['id'];

        // Still need to find the value for this datafield in the given datarecord...
        $value = array();
        if ( isset($datarecord['dataRecordFields'][$df_id]) ) {
            $drf = $datarecord['dataRecordFields'][$df_id];

            // Don't know the typeclass, so brute-force it
            unset( $drf['id'] );
            unset( $drf['created'] );
            unset( $drf['file'] );
            unset( $drf['image'] );
            unset( $drf['dataField'] );

            // The remaining entry will be the correct value
            foreach ($drf as $typeclass => $data) {
                if ( isset($data[0]['value']) && $data[0]['value'] !== '' )
                    $value[$df_id] = self::buildURL($prepend, $append, $encode_input, $data[0]['value']);
            }
        }

        // Return the modified value
        return $value;
    }
}
