<?php 

/**
 * Open Data Repository Data Publisher
 * References Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The references plugin renders data describing an academic
 * reference in a single line, instead of scattered across a
 * number of datafields.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class ReferencesPlugin
{
    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * ReferencesPlugin constructor.
     *
     * @param EngineInterface $templating
     */
    public function __construct(EngineInterface $templating) {
        $this->templating = $templating;
    }


    /**
     * Executes the References Plugin on the provided datarecord
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin
     * @param array $theme_array
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin, $theme_array, $rendering_options)
    {

        try {

//            $str = '<pre>'.print_r($datarecords, true)."\n".print_r($datatype, true)."\n".print_r($render_plugin, true)."\n".print_r($theme, true).'</pre>';
//            throw new \Exception($str);

            // Grab various properties from the render plugin array
            $render_plugin_instance = $render_plugin['renderPluginInstance'][0];
            $render_plugin_map = $render_plugin_instance['renderPluginMap'];

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;

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

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                if ( isset($datarecord['dataRecordFields'][$df_id]) ) {
                    $datafield_mapping[$key] = array('datafield' => $df, 'render_plugin' => $df['dataFieldMeta']['renderPlugin'], 'datarecordfield' => $datarecord['dataRecordFields'][$df_id]);
                }
                else {
                    // As far as the reference plugin is concerned, empty strings are acceptable values when datarecordfield entries don't exist
                    $datafield_mapping[$key] = '';
                }
            }


//            return '<pre>'.print_r($mapping['file'], true).'</pre>';

            // Going to render the reference differently if it's top-level...
            $is_top_level = $rendering_options['is_top_level'];

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Base:References/references.html.twig',
                array(
                    'datarecord' => $datarecord,
                    'mapping' => $datafield_mapping,

                    'is_top_level' => $is_top_level,
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
