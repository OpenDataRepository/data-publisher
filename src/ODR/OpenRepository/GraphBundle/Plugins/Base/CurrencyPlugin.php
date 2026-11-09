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
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
// Symfony

class CurrencyPlugin implements DatafieldPluginInterface, TableResultsOverrideInterface
{

    /**
     * CurrencyPlugin constructor.
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

            // The Currency Plugin should work in these contexts
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
                    'DecimalValue' => $drf['decimalValue'],
                    default => throw new \Exception('Invalid Fieldtype'),
                };
                $value = trim( (string) $entity[0]['value'] );
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
            if ( $context === 'text' || $context === 'html' ) {
                $output = $str;
            }
            else if ( $context === 'text|html' ) {
                return ['text' => $str, 'html' => $str];
            }
            else if ( $context === 'display' ) {
                $output = $this->templating->render(
                    '@ODROpenRepositoryGraph/Base/Currency/currency_display_datafield.html.twig',
                    [
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'value' => $str,
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
        $this->datarecord_info_service->deleteCachedTableData($datatype_id);
    }


    /**
     * {@inheritDoc}
     */
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // TODO - need options and stuff...

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
                if ( isset($data[0]['value']) && $data[0]['value'] !== '' )
                    $value[$df_id] = '$'.number_format($data[0]['value'], 2);
            }
        }

        // Return the modified value
        return $value;
    }
}
