<?php 

/**
 * Open Data Repository Data Publisher
 * Map Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contacts a 3rd-party mapping service (currently OpenStreetMap) to generate points on a map from
 * one or more datarecords that have datafields with decimal degree GPS coordinates in them.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
// Other
use FOS\UserBundle\Util\TokenGenerator;


class MapPlugin implements DatatypePluginInterface
{

    /**
     * @var string
     */
    private $api_key;


    /**
     * MapPlugin constructor.
     *
     * @param \Symfony\Component\Templating\EngineInterface $templating
     * @param TokenGenerator $tokenGenerator
     */
    public function __construct(private readonly \Twig\Environment $templating, private readonly TokenGenerator $tokenGenerator)
    {
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

            // This render plugin is only allowed to work in display mode
            if ( $context === 'display' )
                return true;
        }

        return false;
    }


    /**
     * Executes the Map Plugin on the provided datarecords
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
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = [], $datatype_permissions = [], $datafield_permissions = [], $token_list = [])
    {

        try {
            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = [];
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null) {
                    // If the datafield doesn't exist in the datatype_array, then either the datafield
                    //  is non-public and the user doesn't have permissions to view it (most likely),
                    //  or the plugin somehow isn't configured correctly

                    // The plugin can't continue executing in either case...
                    if ( !$is_datatype_admin )
                        // ...regardless of what actually caused the issue, the plugin shouldn't execute
                        return '';
                    else
                        // ...but if a datatype admin is seeing this, then they probably should fix it
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id.'...check plugin config.');
                }

                //
                $df_typeclass = lcfirst((string) $df['dataFieldMeta']['fieldType']['typeClass']);

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf_name) );
                $datafield_mapping[$key] = ['id' => $rpf_df_id, 'typeclass' => $df_typeclass];
            }


            // ----------------------------------------
            // For each datarecord that has been passed to this plugin, locate the associated comments field
            $gps_locations = [];
//            throw new \Exception( '<pre>'.print_r($datarecords, true).'</pre>' );

            $keys = [
                'latitude',
                'longitude',
            ];

            foreach ($datarecords as $dr_id => $dr) {
                // Attempt to extract properties from the datarecord data...
                $tmp = [];

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
            $record_display_view = 'single';
            if ( isset($rendering_options['record_display_view']) )
                $record_display_view = $rendering_options['record_display_view'];

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Base:Map/map_wrapper.html.twig',
                [
                    'datatype_array' => [$datatype['id'] => $datatype],
                    'datarecord_array' => $datarecords,
                    'theme_array' => $theme_array,

                    'target_datatype_id' => $datatype['id'],
                    'parent_datarecord' => $parent_datarecord,
                    'target_theme_id' => $theme['id'],

                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'record_display_view' => $record_display_view,
                    'is_top_level' => $rendering_options['is_top_level'],
                    'is_link' => $rendering_options['is_link'],
                    'display_type' => $rendering_options['display_type'],
                    'multiple_allowed' => $rendering_options['multiple_allowed'],

                    'plugin_options' => $options,

                    'gps_locations' => $gps_locations,
                    'unique_id' => $unique_id,
                ]
            );

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
