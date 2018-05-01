<?php 

/**
 * Open Data Repository Data Publisher
 * QAnalyze Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This plugin turns a datafield into a button to POST a Chemin XRD pattern to a remote site to
 * perform analysis on it.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Chemin;

// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class QanalyzePlugin
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
     * QanalyzePlugin constructor.
     *
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(EngineInterface $templating, Logger $logger) {
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Executes the Qanalyze Plugin.
     *
     * @param array $datafield
     * @param array $datarecord
     * @param array $render_plugin
     * @param string $themeType     One of 'master', 'search_results', 'table', TODO?
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin, $themeType = 'master')
    {

        try {

//            $str = '<pre>'.print_r($datafield, true)."\n".print_r($datarecord, true)."\n".print_r($render_plugin, true)."\n".'</pre>';
//            return $str;
            // Grab various properties from the render plugin array
            $render_plugin_options = $render_plugin['renderPluginInstance'][0]['renderPluginOptions'];

            // Remap render plugin by name => value
            $options = array();
            foreach($render_plugin_options as $option) {
                if ( $option['active'] == 1 )
                    $options[ $option['optionName'] ] = $option['optionValue'];
            }
            // Grab value of datafield
            $value = '';
            if ( isset($datarecord['dataRecordFields'][ $datafield['id'] ]) ) {
                $drf = $datarecord['dataRecordFields'][ $datafield['id'] ];
                $entity = '';
                switch ( $datafield['dataFieldMeta']['fieldType']['typeClass'] ) {
                    case 'IntegerValue':
                        $entity = $drf['integerValue'];
                        break;
                    case 'ShortVarchar':
                        $entity = $drf['shortVarchar'];
                        break;
                    case 'MediumVarchar':
                        $entity = $drf['mediumVarchar'];
                        break;
                    case 'LongVarchar':
                        $entity = $drf['longVarchar'];
                        break;
                    case 'LongText':
                        $entity = $drf['longText'];
                        break;

                    default:
                        throw new \Exception('Invalid Fieldtype');
                        break;
                }
                $value = trim( $entity[0]['value'] );
            }
            else {
                // No datarecordfield entry for this datarecord/datafield pair...because of the allowed fieldtypes, the plugin can just use the empty string in this case
                $value = '';
            }


            // ----------------------------------------
            // The names of the files uploaded to this child datarecord should have two parts...
            // ...the second part being more or less a description of the contents of the file, which can be ignored
            // ...the first part being a key that matches across all the different file types
            // Render and return the graph html


            $output = "";
            if($value > 0) {
                $output = $this->templating->render(
                   'ODROpenRepositoryGraphBundle:Chemin:Qanalyze/qanalyze.html.twig'
                );
            }
            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
