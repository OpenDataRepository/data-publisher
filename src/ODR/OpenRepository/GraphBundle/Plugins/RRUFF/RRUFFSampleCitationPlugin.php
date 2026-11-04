<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Sample Citation Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * In Nov 2026, Bob decided that having the LinkedDescendantMerger plugin combine all 5-6 separate
 * "paths" from the RRUFF Samples datatype to the RRUFF References datatype was confusing...he only
 * wanted the RRUFF Reference that was directly linked to by the RRUFF Sample.
 *
 * This could be achieved just by detaching the LinkedDescendantMerger and RRUFFSpecialReference
 * plugins, but the final wrinkle is that he wanted the "custom header" that the RRUFFSpecialReference
 * plugin had...so this plugin exists to gaslight ODR into thinking a particular linked datatype
 * has a different name.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Entities
// Services
use ODR\OpenRepository\GraphBundle\Plugins\ArrayPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ArrayPluginReturn;
// Symfony
use Psr\Log\LoggerInterface;

class RRUFFSampleCitationPlugin implements ArrayPluginInterface
{

    /**
     * RRUFF Sample Citation Plugin constructor.
     *
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {

    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            if ( $rendering_options['context'] === 'display' )
                return true;

            // NOTE: running array plugins in "Edit" mode is riskier
        }

        return false;
    }


    /**
     * Executes the RRUFF Sample Citation plugin on the provided datarecords
     *
     * @param array $datarecord_array
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     *
     * @return ArrayPluginReturn|null
     * @throws \Exception
     */
    public function execute($datarecord_array, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = [])
    {
        try {
            // ----------------------------------------
            // If no rendering context set, then return nothing so ODR's default templating will
            //  do its job
            if ( !isset($rendering_options['context']) )
                return null;

            // This render plugin has no fields to deal with
            $plugin_options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // Array plugins exist solely to gaslight ODR's regular rendering process.

            // This one is relatively benign, since it modifies the datatype array to "rename" the
            //  RRUFF Reference datatype that the RRUFF Sample datatype directly links to
            $modified_datatype_array = $datatype;
            if ( isset($modified_datatype_array['descendants'][734]['datatype'][734]['dataTypeMeta']) ) {
                $modified_datatype_array['descendants'][734]['datatype'][734]['dataTypeMeta']['shortName'] = $plugin_options['header_value'];
                $modified_datatype_array['descendants'][734]['datatype'][734]['dataTypeMeta']['longName'] = $plugin_options['header_value'];
            }


            // ----------------------------------------
            // Otherwise, need to provide the modified datarecord array info to the templating so
            //  that it ends up displaying correctly
            $datatype_id = $datatype['id'];
            if ($datatype_id !== 738 )
                return null;

            $modifications = new ArrayPluginReturn(
                $datatype_id,
                $modified_datatype_array,
                $datarecord_array,
                $theme_array
            );

            return $modifications;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
