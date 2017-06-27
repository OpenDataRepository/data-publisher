<?php 

/**
 * Open Data Repository Data Publisher
 * Graph Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The graph plugin plots a line graph out of data files uploaded
 * to a File DataField, and labels them using a "legend" field
 * selected when the graph plugin is created...
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Controllers/Classes

// Libraries
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;

// Symfony components
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
// use Doctrine\ORM\EntityManager;

/**
 * Class GraphPlugin
 * @package ODR\OpenRepository\GraphBundle\Plugins
 */
class CheminEE1Plugin
{
    /**
     * @var mixed
     */
    private $templating;

    /**
     * @var mixed
     */
    private $logger;

    /**
     * GraphPlugin constructor.
     *
     * @param $templating
     * @param $logger
     */
    public function __construct($templating, $logger, Container $container, $entity_manager) {
        $this->templating = $templating;
	    $this->logger = $logger;
        $this->container = $container;
        $this->em = $entity_manager;
    }

    /**
     * Executes the Graph Plugin on the provided datarecords
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

            // ----------------------------------------
            // Grab various properties from the render plugin array
            $render_plugin_instance = $render_plugin['renderPluginInstance'][0];
            $render_plugin_map = $render_plugin_instance['renderPluginMap'];
            $render_plugin_options = $render_plugin_instance['renderPluginOptions'];

            // Remap render plugin by name => value
            $max_option_date = 0;
            $options = array();
            foreach($render_plugin_options as $option) {
                if ( $option['active'] == 1 )
                    $option_date = new \DateTime($option['updated']->date);
                    $us = $option_date->format('u');
                    $epoch = strtotime($option['updated']->date) * 1000000;
                    $epoch = $epoch + $us;
                    if($epoch > $max_option_date) {
                        $max_option_date = $epoch;
                    }
                    $options[ $option['optionName'] ] = $option['optionValue'];
            }

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
                    if ( isset($te['themeDataFields']) ) {
                        foreach ($te['themeDataFields'] as $tdf) {
                            if ( isset($tdf['dataField']) && $tdf['dataField']['id'] == $df_id ) {
                                $df = $tdf['dataField'];
                                break;
                            }
                        }
                    }

                    if ($df !== null)
                        break;
                }

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf['fieldName'].'", mapped to df_id '.$df_id);

                // Grab the field name specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                $datafield_mapping[$key] = array('datafield' => $df);
            }

            $file_names = array();
            $file_data = array();
            foreach($datarecords as $dr_id => $datarecord) {
                foreach($datarecord['dataRecordFields'] as $drf_id => $data_record_field) {
                    //  Get file names and store array (First DRF can be used for name)
                    if(isset($data_record_field['file']) && isset($data_record_field['file'][0])) {
                        if(!isset($file_names[$dr_id])) {
                            $file_name = $data_record_field['file'][0]['fileMeta']['originalFileName'];
                            if(strlen($file_name) > 0) {
                                $file_name_data = preg_split("/_/", $file_name);
                                $file_name = $file_name_data[0] . "_" . $file_name_data[1];
                                if(preg_match("/SUM/",$file_name)) {
                                    $file_name .= "_" . $file_name_data[2];
                                }
                            }
                            $file_names[$dr_id] = $file_name;
                        }

                        // Identify File Type and Store $file_data array
                        switch($drf_id) {
                            case $datafield_mapping['ee1_processed_csv']['datafield']['id']:
                                $file_data[$dr_id]['ee1_processed_csv'] = $data_record_field['file'][0];
                                break;
                            case $datafield_mapping['ee1_raw_csv']['datafield']['id']:
                                $file_data[$dr_id]['ee1_raw_csv'] = $data_record_field['file'][0];
                                break;
                            case $datafield_mapping['ee1_raw_lbl_file']['datafield']['id']:
                                $file_data[$dr_id]['ee1_raw_lbl_file'] = $data_record_field['file'][0];
                                break;
                            case $datafield_mapping['ee1_raw_dat_file']['datafield']['id']:
                                $file_data[$dr_id]['ee1_raw_dat_file'] = $data_record_field['file'][0];
                                break;
                            case $datafield_mapping['ee1_processing_description']['datafield']['id']:
                                $file_data[$dr_id]['ee1_processing_description'] = $data_record_field['file'][0];
                                break;
                        }
                    }
                }
            }

            // Sort the file names for display
            asort($file_names);

            $chemin_ee1_table = "";
            foreach($file_names as $dr_id => $file_name) {
                $chemin_ee1_table .= $dr_id . "_";
            }
            $chemin_ee1_table = substr($chemin_ee1_table,0,(strlen($chemin_ee1_table) - 1));

            // Render the graph html
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:CheminEE1:chemin_ee1.html.twig',
                array(
                    'file_names' => $file_names,
                    'file_data' => $file_data,
                    'chemin_ee1_table' => $chemin_ee1_table
                )
            );
            return $output;
        }
        catch (\Exception $e) {
            // TODO Can we remove this?...
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
