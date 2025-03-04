<?php

/**
 * Open Data Repository Data Publisher
 * AHEDCoreProperties Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Metadata;

// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class AHEDPropertiesCorePlugin implements DatatypePluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * AHEDPropertiesCorePlugin constructor.
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
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // This render plugin is only allowed to work when in Edit mode
            if ( $context === 'edit' )
                return true;

            // TODO - pass in stuff so the plugin is only executed when in wizard mode?
        }

        return false;
    }


    /**
     * Executes the AHEDPropertiesCore Plugin on the provided datarecords
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param array $token_list
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {
        try {
            // ----------------------------------------
            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            foreach ($theme_array as $t_id => $t)
                $initial_theme_id = $t_id;


            // ----------------------------------------
            // Need to be able to pass this option along if doing edit mode
            $edit_shows_all_fields = $rendering_options['edit_shows_all_fields'];

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Metadata:Core/core_childtype.html.twig',
                array(
                    'datatype_array' => array($initial_datatype_id => $datatype),
                    'datarecord_array' => $datarecords,
                    'theme_array' => $theme_array,

                    'target_datatype_id' => $initial_datatype_id,
                    'parent_datarecord' => $parent_datarecord,
                    'target_theme_id' => $initial_theme_id,

                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,
                    'edit_shows_all_fields' => $edit_shows_all_fields,

                    'is_top_level' => $rendering_options['is_top_level'],
                    'is_link' => $rendering_options['is_link'],
                    'display_type' => $rendering_options['display_type'],

                    'token_list' => $token_list,
                )
            );

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
