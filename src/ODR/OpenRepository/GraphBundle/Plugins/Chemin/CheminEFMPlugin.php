<?php 

/**
 * Open Data Repository Data Publisher
 * Chemin EFM Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This plugin is specifically for CheMin EFM products, and "combines" three file datafields into a single
 * compact display.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Chemin;

// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class CheminEFMPlugin implements DatatypePluginInterface
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
     * CheminEFMPlugin constructor.
     *
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(EngineInterface $templating, Logger $logger) {
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
        // This render plugin isn't allowed to work when in edit mode
        if ( isset($rendering_options['context']) && $rendering_options['context'] === 'edit' )
            return false;

        return true;
    }


    /**
     * Executes the CheminEFM Plugin on the provided datarecords
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
            // Grab various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];

            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                // Grab the field name specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf_name) );
                $datafield_mapping[$key] = array('datafield' => $df);
            }


            // ----------------------------------------
            // The names of the files uploaded to this child datarecord should have two parts...
            // ...the second part being more or less a description of the contents of the file, which can be ignored
            // ...the first part being a key that matches across all the different file types
            $datarecord_id = '';

            $file_data = array();
            foreach ($datarecords as $dr_id => $dr) {
                // Store the datarecord id to use to name the plugin's table later on
                $datarecord_id = $dr_id;

                foreach ($dr['dataRecordFields'] as $df_id => $drf) {
                    if ( isset($drf['file']) ) {
                        foreach ($drf['file'] as $num => $file) {
                            $original_filename = $file['fileMeta']['originalFileName'];

                            // Don't want the file's original extension
                            $pieces = explode('.', $original_filename);

                            // Only want the identifying info at the beginning of the filename
                            $pieces = explode('_', $pieces[0]);
                            $shortened_filename = $pieces[0].'_'.$pieces[1];
                            if ($pieces[0] == 'SUM')
                                $shortened_filename.= '_'.$pieces[2];

                            // Want to store all types of files under the same identifying info
                            if ( !isset($file_data[$shortened_filename]) )
                                $file_data[$shortened_filename] = array();

                            // Identify file type
                            switch($df_id) {
                                case $datafield_mapping['efm_raw_tiff_file']['datafield']['id']:
                                    $file_data[$shortened_filename]['efm_raw_tiff_file'] = $file;
                                    break;
                                case $datafield_mapping['efm_lbl_file']['datafield']['id']:
                                    $file_data[$shortened_filename]['efm_lbl_file'] = $file;
                                    break;
                                case $datafield_mapping['efm_dat_file']['datafield']['id']:
                                    $file_data[$shortened_filename]['efm_dat_file'] = $file;
                                    break;
                            }
                        }
                    }
                }
            }

            ksort($file_data);

            // Render and return the graph html
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Chemin:CheminEFM/chemin_efm.html.twig',
                array(
                    'file_data' => $file_data,
                    'chemin_efm_table' => 'chemin_efm_table_'.$datarecord_id,
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
