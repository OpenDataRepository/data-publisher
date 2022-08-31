<?php

/**
 * Open Data Repository Data Publisher
 * Currency Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The currency plugin is designed to turn an integer or decimal value into a properly formatted
 * currency string.  Currently just does standard US currency output.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// ODR
use ODR\AdminBundle\Entity\RenderPluginInstance;
// Events
use ODR\AdminBundle\Component\Event\PluginAttachEvent;
use ODR\AdminBundle\Component\Event\PluginPreRemoveEvent;
// Services
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class CurrencyPlugin implements DatafieldPluginInterface
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
     * CurrencyPlugin constructor.
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
        // The Currency Plugin should work in the 'text' and 'display' contexts
        if ( $rendering_options['context'] === 'text' || $rendering_options['context'] === 'display' )
            return true;

        return false;
    }


    /**
     * Executes the Currency Plugin on the provided datafield
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
                    case 'DecimalValue':
                        $entity = $drf['decimalValue'];
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


            // TODO - need options and stuff...

            // Make a currency string out of the datafield's value
            $str = '';
            if ($value !== '')
                $str = '$'.number_format($value, 2);


            $output = "";
            if ( $rendering_options['context'] === 'text' ) {
                $output = $str;
            }
            else if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:Currency/currency_display_datafield.html.twig',
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
