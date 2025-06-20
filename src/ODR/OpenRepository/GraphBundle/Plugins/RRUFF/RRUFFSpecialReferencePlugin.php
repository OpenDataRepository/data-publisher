<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Special Reference Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RRUFF Sample database has two "paths" to reach the RRUFF Reference database...one is a "direct
 * link", while the other goes through the IMA list.  The LinkedDescendantMerger plugin was created
 * awhile back to get ODR to "combine" these two different "paths", but it turns out that they also
 * want the abiilty to place the "direct link" in a prominent place on the page because they're using
 * it to promote community data and stuff.
 *
 * In theory this could be extended to special render anything, but it's purely in RRUFF at the
 * moment because I suspect they're going to have enough problems understanding this even when they're
 * (mostly) familiar with the underlying database structure.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Services
use ODR\OpenRepository\GraphBundle\Plugins\ThemeElementPluginInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class RRUFFSpecialReferencePlugin implements ThemeElementPluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * RRUFF Special Reference Plugin constructor
     *
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->templating = $templating;
        $this->logger = $logger;
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
            $context = $rendering_options['context'];

            // This render plugin is only allowed to execute in display mode
            if ( $context === 'display' )
                return true;

            // Design mode doesn't call this, as it only demands placeholder HTML
        }

        return false;
    }


    /**
     * Executes the RRUFF Special Reference Plugin on the provided datarecord
     *
     * @param array $datarecord
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecord, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $datatype_permissions = array(), $datafield_permissions = array())
    {
        try {
            // ----------------------------------------
            // Shouldn't happen, but make sure this only executes in display mode
            if ( !isset($rendering_options['context']) || $rendering_options['context'] !== 'display' )
                return '';

            $plugin_options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // This plugin has no required fields or options, but does require a direct descendant
            //  that is using the "RRUFF References"
            $reference_datatype_array = null;
            $rpm = null;
            foreach ($datatype['descendants'] as $dt_id => $dt_wrapper) {
                if ( isset($dt_wrapper['datatype'][$dt_id]) ) {
                    // $dt won't exist if the user can't view the datatype
                    $dt = $dt_wrapper['datatype'][$dt_id];

                    foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.rruff_references' ) {
                            // Found the datatype, save its renderPluginMap array and break out of both
                            //  loops
                            $reference_datatype_array = $dt;
                            $rpm = $rpi['renderPluginMap'];
                            break 2;
                        }
                    }
                }
            }
            // ...if the datatype is using the "RRUFF References Plugin", it'll have a renderPluginMap array
            if ( is_null($rpm) ) {
                if ( $is_datatype_admin )
                    // Only throw an error if the user is a datatype admin...
                    throw new \Exception('Unable to locate a descendant datatype using the "RRUFF References Plugin"');
                else
                    // ...because if they're not, then the user can't do anything about it
                    return '';
            }

            // ----------------------------------------
            // This plugin is only interested in executing when the RRUFF Sample is directly linked
            //  to a RRUFF Reference
            $reference_datarecord_array = null;

            // Sidestep the LinkedDescendantMerger plugin if it already executed
            $child_dr_list = $datarecord['children'];
            if ( isset($datarecord['original_children']) )
                $child_dr_list = $datarecord['original_children'];

            // Could be multiple references in here, unfortunately...
            $reference_dt_id = $reference_datatype_array['id'];
            if ( isset($child_dr_list[$reference_dt_id]) )
                $reference_datarecord_array = $child_dr_list[$reference_dt_id];

            // If not directly linked to at least one relevant reference, then don't do anything
            if ( is_null($reference_datarecord_array) )
                return '';

            // Might as well also find the related theme array to make twig's life easier when it
            //  attempts to execute the RRUFF References plugin
            $reference_theme_array = null;
            foreach ($theme_array as $t_id => $t) {
                foreach ($t['themeElements'] as $num => $te) {
                    if ( isset($te['themeDataType']) && $te['themeDataType'][0]['dataType']['id'] === $reference_dt_id )
                        $reference_theme_array = $te['themeDataType'][0]['childTheme']['theme'];
                }
            }


            // ----------------------------------------
            // Don't want the default div wrappers the reference rendering comes with
            $rendering_options['context'] = 'text';

            // Could have multiple references in here, so need to get them one by one
            $references = array();
            foreach ($reference_datarecord_array as $dr_id => $ref_dr) {
                $ref_text = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFSpecialReference/rruff_special_reference_execute.html.twig',
                    array(
                        'is_datatype_admin' => $is_datatype_admin,
                        'plugin_options' => $plugin_options,

                        'rendering_options' => $rendering_options,

                        'parent_datarecord' => $datarecord,
                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'reference_datatype_array' => $reference_datatype_array,
                        'reference_datarecord_array' => array($dr_id => $ref_dr),
                        'reference_theme_array' => $reference_theme_array,
                    )
                );

                // Save the reference if it got rendered
                if ( trim($ref_text) !== '' )
                    $references[] = $ref_text;
            }

            // If any references got rendered...
            $output = '';
            if ( !empty($references) ) {
                // ...then put them inside an html wrapper before returning
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFSpecialReference/rruff_special_reference_themeElement.html.twig',
                    array(
                        'references' => $references,
                        'plugin_options' => $plugin_options,

                        'reference_datatype_array' => $reference_datatype_array,
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
     * {@inheritDoc}
     */
    public function getPlaceholderHTML($datatype, $render_plugin_instance, $theme_array, $rendering_options)
    {
        // Render the placeholder html
        return $this->templating->render(
            'ODROpenRepositoryGraphBundle:RRUFF:RRUFFSpecialReference/rruff_special_reference_placeholder.html.twig'
        );
    }
}
