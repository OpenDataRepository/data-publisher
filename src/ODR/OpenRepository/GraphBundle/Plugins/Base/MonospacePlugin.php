<?php

/**
 * Open Data Repository Data Publisher
 * Monospace Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Triggers the use of a monospace font for a Paragraph Text field.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// ODR
use ODR\AdminBundle\Entity\RenderPluginInstance;
// Events
// Services
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;


// Symfony
class MonospacePlugin implements DatafieldPluginInterface
{

    /**
     * MonospacePlugin constructor.
     *
     * @param \Twig\Environment $templating
     */
    public function __construct(
        private readonly \Twig\Environment $templating
    ) {

    }


    /**
     * @inheritDoc
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // The Monospace Plugin should work in the 'display' and 'edit' contexts
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
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

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

            $output = "";
            if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    '@ODROpenRepositoryGraph/Base/Monospace/monospace_display_datafield.html.twig',
                    [
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,
                    ]
                );
            }
            else if ( $rendering_options['context'] === 'edit' ) {
                $output = $this->templating->render(
                    '@ODROpenRepositoryGraph/Base/Monospace/monospace_edit_datafield.html.twig',
                    [
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'is_datatype_admin' => $is_datatype_admin,
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
