<?php 

/**
 * Open Data Repository Data Publisher
 * Map Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Generates a google map from one or more datarecords that have datafields with decimal degree
 * GPS coordinates in them.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// ODR
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
// Other
use FOS\UserBundle\Util\TokenGenerator;


class MapPlugin implements DatatypePluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var TokenGenerator
     */
    private $tokenGenerator;

    /**
     * @var string
     */
    private $api_key;


    /**
     * MapPlugin constructor.
     *
     * @param EngineInterface $templating
     * @param TokenGenerator $tokenGenerator
     */
    public function __construct(
        EngineInterface $templating,
        TokenGenerator $tokenGenerator
    ) {
        $this->templating = $templating;
        $this->tokenGenerator = $tokenGenerator;
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin, $datatype, $rendering_options)
    {
        // This render plugin isn't allowed to work when in edit mode
        if ( isset($rendering_options['context']) && $rendering_options['context'] === 'edit' )
            return false;

        return true;
    }


    /**
     * Executes the Map Plugin on the provided datarecords
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin
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
    public function execute($datarecords, $datatype, $render_plugin, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {

        try {
            // ----------------------------------------
            // Grab various properties from the render plugin array
            $render_plugin_instance = $render_plugin['renderPluginInstance'][0];
            $render_plugin_map = $render_plugin_instance['renderPluginMap'];
            $render_plugin_options = $render_plugin_instance['renderPluginOptions'];

            // Remap render plugin by name => value
            $options = array();
            foreach($render_plugin_options as $option) {
                if ( $option['active'] == 1 )
                    $key = strtolower( str_replace(' ', '_', $option['optionName']) );
                    $options[$key] = $option['optionValue'];
            }


            // ----------------------------------------
            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($render_plugin_map as $rpm) {
                // Get the entities connected by the render_plugin_map entity??
                $rpf = $rpm['renderPluginFields'];
                $df_id = $rpm['dataField']['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$df_id]) )
                    $df = $datatype['dataFields'][$df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf['fieldName'].'", mapped to df_id '.$df_id);

                //
                $df_typeclass = lcfirst($df['dataFieldMeta']['fieldType']['typeClass']);

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );
                $datafield_mapping[$key] = array('id' => $df_id, 'typeclass' => $df_typeclass);
            }


            // ----------------------------------------
            // For each datarecord that has been passed to this plugin, locate the associated comments field
            $gps_locations = array();
//            throw new \Exception( '<pre>'.print_r($datarecords, true).'</pre>' );

            $keys = array(
                'latitude',
                'longitude',
            );

            foreach ($datarecords as $dr_id => $dr) {
                // Attempt to extract properties from the datarecord data...
                $tmp = array();

                foreach ($keys as $key) {
                    $df_id = $datafield_mapping[$key]['id'];
                    $df_typeclass = $datafield_mapping[$key]['typeclass'];

                    if ( isset($dr['dataRecordFields'][$df_id]) ) {
                        //
                        $drf = $dr['dataRecordFields'][$df_id];
                        if ( !isset($drf[$df_typeclass]) )
                            throw new \Exception('Data for "'.$key.'" field is missing expected typeclass "'.$df_typeclass.'"');

                        //
                        $tmp[$key] = $drf[$df_typeclass][0]['value'];
                    }
                }

                if ( count($tmp) !== 2 )
                    throw new \Exception('Unexpected data count...');

                $gps_locations[] = $tmp;
            }


            // ----------------------------------------
            // Since there could be multiple maps on the page, generate a sufficiently unique
            //  token to identify this map div
            $unique_id = substr($this->tokenGenerator->generateToken(), 0, 10);
            $unique_id = strtr($unique_id, '-', '_');   // replace all occurrences of '-' with '_'

            // Should only be one element in $theme_array...
            $theme = null;
            foreach ($theme_array as $t_id => $t)
                $theme = $t;

            // Render and return the map
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Base:Map/map_wrapper.html.twig',
                array(
                    'datatype_array' => array($datatype['id'] => $datatype),
                    'datarecord_array' => $datarecords,
                    'theme_array' => $theme_array,

                    'target_datatype_id' => $datatype['id'],
                    'target_theme_id' => $theme['id'],

                    'is_top_level' => $rendering_options['is_top_level'],
                    'is_link' => $rendering_options['is_link'],
                    'display_type' => $rendering_options['display_type'],

                    'plugin_options' => $options,

                    'gps_locations' => $gps_locations,
                    'unique_id' => $unique_id,
                )
            );

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Called when a user removes a specific instance of this render plugin
     *
     * @param RenderPluginInstance $render_plugin_instance
     */
    public function onRemoval($render_plugin_instance)
    {
        // This plugin doesn't need to do anything here
        return;
    }


    /**
     * Called when a user changes a mapped field or an option for this render plugin
     * TODO - pass in which field mappings and/or plugin options got changed?
     *
     * @param RenderPluginInstance $render_plugin_instance
     */
    public function onSettingsChange($render_plugin_instance)
    {
        // This plugin doesn't need to do anything here
        return;
    }
}
