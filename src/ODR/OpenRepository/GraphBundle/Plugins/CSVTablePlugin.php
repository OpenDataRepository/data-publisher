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
class CSVTablePlugin
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
     * @var array
     */
    // private $line_colors;

    /**
     * @var array
     */
    // private $jpgraph_line_colors;


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
     * @param array $datafield
     * @param array $datarecord
     * @param array $render_plugin
     * @param array $themeType = "master" by default.
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin, $themeType = 'master')
    {

        try {

            // ----------------------------------------
            // Grab various properties from the render plugin array
            $render_plugin_options = $render_plugin['renderPluginInstance'][0]['renderPluginOptions'];

            // Remap render plugin by name => value
            $options = array();
            foreach($render_plugin_options as $option) {
                if ( $option['active'] == 1 )
                    $options[ $option['optionName'] ] = $option['optionValue'];
            }


            $file = $datarecord['dataRecordFields'][$datafield['id']]['file']['0'];

            // Check that the file exists...
            $local_filepath = realpath(dirname(__FILE__) . '/../../../../../web/' . $file['localFileName']);
            if (!$local_filepath) {
                // File does not exist for some reason...see if it's getting decrypted right now
                return $local_filepath . "<div>File not found.</div>." ;
            }

            // Load file and parse into array
            $data_array = array();
            if (($handle = fopen($local_filepath, "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    array_push($data_array, $data);
                }
                fclose($handle);
            }

            $data_array = json_encode($data_array);

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:CSVTable:csv_table.html.twig',
                array(
                    'datafield' => $datafield,
                    'datarecord' => $datarecord,
                    'data_array' => $data_array,
                    'local_file_path' => $local_filepath
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
