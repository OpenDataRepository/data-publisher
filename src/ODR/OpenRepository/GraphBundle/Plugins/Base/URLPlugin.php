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
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class URLPlugin implements DatafieldPluginInterface
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
     * @param array $datarecord
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
     * @param array $datarecord
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


            // Load strings to append/prepend to the contents of the datafield
            $prepend = '';
            if ( isset($options['base_url']) && $options['base_url'] !== 'auto' )
                $prepend = $options['base_url'];

            $append = '';
            if ( isset($options['post_url']) && $options['post_url'] !== 'auto' )
                $append = $options['post_url'];


            // https://tools.ietf.org/html/rfc3986#section-3  only the query and the fragment should
            //  be encoded (if at all), but without having a URL parser handy it's impossible to
            //  automatically and accurately encode a URL.
            // Fortunately, users shouldn't be putting "url-like" and "not-url-like" values in the
            //  same datafield, so handling it with a plugin option should be sufficient...
            $href_value = $value;
            if ( isset($options['encode_input']) && $options['encode_input'] === 'yes' )
                $href_value = urlencode($value);


            $str = '';
            if ($value !== '') {
                $str = '<a target="_blank" href="'.$prepend.$href_value.$append.'" class="underline">';

                // Display the prepend/append strings in the datafield contents if configured that way
                if ( isset($options['display_full_url']) && $options['display_full_url'] === 'yes' )
                    $str .= $prepend.$value.$append;
                else
                    $str .= $value;

                $str .= '</a>';
            }


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
}
