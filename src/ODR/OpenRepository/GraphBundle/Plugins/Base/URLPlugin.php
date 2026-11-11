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

class URLPlugin implements DatafieldPluginInterface, TableResultsOverrideInterface
{

    /**
     * URLPlugin constructor.
     *
     * @param DatarecordInfoService $datarecord_info_service
     * @param \Twig\Environment $templating
     */
    public function __construct(
        private readonly DatarecordInfoService $datarecord_info_service,
        private readonly \Twig\Environment $templating
    ) {

    }


    /**
     * {@inheritDoc}
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // The URL Plugin should work in these contexts
            switch ($context) {
                case 'text':       // plain string
                case 'html':       // formatted string
                case 'text|html':  // both of the above
                case 'display':
                    return true;
            }
        }

        return false;
    }


    /**
     * {@inheritDoc}
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $rendering_options)
    {

        try {
            // ----------------------------------------
            $context = $rendering_options['context'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // Grab value of datafield
            $value = '';
            if ( isset($datarecord['dataRecordFields'][ $datafield['id'] ]) ) {
                $drf = $datarecord['dataRecordFields'][ $datafield['id'] ];
                $entity = '';
                $entity = match ($datafield['dataFieldMeta']['fieldType']['typeClass']) {
                    'IntegerValue' => $drf['integerValue'],
                    'ShortVarchar' => $drf['shortVarchar'],
                    'MediumVarchar' => $drf['mediumVarchar'],
                    'LongVarchar' => $drf['longVarchar'],
                    'LongText' => $drf['longText'],
                    default => throw new \Exception('Invalid Fieldtype'),
                };
                $value = trim( (string) $entity[0]['value'] );
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
            $ret = ['text' => '', 'html' => ''];
            if ( $value !== '' )
                $ret = self::buildURL($prepend, $append, $encode_input, $value);

            // If the caller doesn't want the actual field, then can return early
            if ( $context === 'text' )
                return $ret['text'];
            else if ( $context === 'html' )
                return $ret['html'];
            else if ( $context === 'text|html' )
                return $ret;

            // ...otherwise, need to involve twig
            $output = "";
            if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    '@ODROpenRepositoryGraph/Base/URL/url_display_datafield.html.twig',
                    [
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'value' => $ret['html'],
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
     * Converts the given value into a URL.
     *
     * @param string $prepend
     * @param string $append
     * @param boolean $encode_input
     * @param string $original_value
     * @return array
     */
    private function buildURL($prepend, $append, $encode_input, $original_value)
    {
        // The text version should be simple
        $text_str = $prepend.$original_value.$append;

        // https://tools.ietf.org/html/rfc3986#section-3  only the query and the fragment should
        //  be encoded (if at all), but without having a URL parser handy it's impossible to
        //  automatically and accurately encode a URL.
        // Fortunately, users shouldn't be putting "url-like" and "not-url-like" values in the
        //  same datafield, so handling it with a plugin option should be sufficient...
        $href_value = $original_value;
        if ($encode_input)
            $href_value = urlencode($href_value);

        // The HTML version should be an HTML link
        $html_str = '<a target="_blank" href="'.$prepend.$href_value.$append.'" class="underline">';
        // Display the prepend/append strings in the datafield contents if configured that way
        if ( isset($options['display_full_url']) && $options['display_full_url'] === 'yes' )
            $html_str .= $prepend.$original_value.$append;
        else
            $html_str .= $original_value;
        $html_str .= '</a>';

        return [
            'text' => $text_str,
            'html' => $html_str,
        ];
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
        $this->datarecord_info_service->deleteCachedTableData($datatype_id);
    }


    /**
     * {@inheritDoc}
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
        $value = [];
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
                if ( isset($data[0]['value']) && $data[0]['value'] !== '' ) {
                    $ret = self::buildURL($prepend, $append, $encode_input, $data[0]['value']);
                    // Always want the 'html' value for this purpose
                    $value[$df_id] = $ret['html'];
                }
            }
        }

        // Return the modified value
        return $value;
    }
}
