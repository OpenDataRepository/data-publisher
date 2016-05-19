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

namespace ODR\OpenRepository\GraphBundle\Plugins;


class ReferencesPlugin
{
    /**
     * @var mixed
     */
    private $templating;


    /**
     * URLPlugin constructor.
     *
     * @param $templating
     */
    public function __construct($templating) {
        $this->templating = $templating;
    }


    /**
     * Executes the URL Plugin on the provided datarecord
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin
     * @param array $theme
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin, $theme, $rendering_options)
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

                // Want the full-fledged datafield entry...the one in $rpm['dataField'] has no render plugin or meta data
                // Unfortunately, the desired one is buried inside the $theme array somewhere...
                $df = null;
                foreach ($theme['themeElements'] as $te) {
                    foreach ($te['themeDataFields'] as $tdf) {
                        if ( $tdf['dataField']['id'] == $df_id ) {
                            $df = $tdf['dataField'];
                            break;
                        }
                    }

                    if ($df !== null)
                        break;
                }

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                $datafield_mapping[$key] = array('datafield' => $df, 'render_plugin' => $df['dataFieldMeta']['renderPlugin'], 'datarecordfield' => $datarecord['dataRecordFields'][$df_id]);
            }

//            return '<pre>'.print_r($mapping['file'], true).'</pre>';

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:References:references.html.twig', 
                array(
                    'datarecord' => $datarecord,
                    'mapping' => $datafield_mapping,
                )
            );

            return $output;
        }
        catch (\Exception $e) {
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Default:default_error.html.twig',
                array(
                    'message' => $e->getMessage()
                )
            );
            throw new \Exception( $output );
        }
    }

}
