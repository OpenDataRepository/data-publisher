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

// ODR
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class QanalyzePlugin implements DatafieldPluginInterface
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
            // ----------------------------------------
            // Load the options from the render plugin array
            $render_plugin_options = $render_plugin['renderPluginInstance'][0]['renderPluginOptions'];

            $label_field = '';
            $xrd_field = '';
            $phase_field = '';
            $wavelength_field = '';
            foreach ($render_plugin_options as $num => $rpo) {
                switch ($rpo['optionName']) {
                    case 'label_field':
                        $label_field = $rpo['optionValue'];
                        break;
                    case 'xrd_pattern_field':
                        $xrd_field = $rpo['optionValue'];
                        break;
                    case 'phase_list_field':
                        $phase_field = $rpo['optionValue'];
                        break;
                    case 'wavelength_field':
                        $wavelength_field = $rpo['optionValue'];
                        break;
                }
            }

            if ($label_field == '')
                throw new \Exception("The \"Sample Label Datafield\" option in the plugin's configuration must have a value");
            if ($xrd_field == '')
                throw new \Exception("The \"XRD Pattern Datafield\" option in the plugin's configuration must have a value");


            // ----------------------------------------
            // Need to determine the value of the associated datafield
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
                // No datarecordfield entry for this datarecord/datafield pair...because of the
                //  allowed fieldtypes, the plugin can just use the empty string in this case
                $value = '';
            }


            // ----------------------------------------
            // If the datafield has a value of "0", then the "Run Qanalyze" button will be hidden
            $output = "";
            if ($value > 0) {
                $output = $this->templating->render(
                   'ODROpenRepositoryGraphBundle:Chemin:Qanalyze/qanalyze.html.twig',
                    array(
                        'label_field' => $label_field,
                        'xrd_field' => $xrd_field,
                        'phase_field' => $phase_field,
                        'wavelength_field' => $wavelength_field,
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
