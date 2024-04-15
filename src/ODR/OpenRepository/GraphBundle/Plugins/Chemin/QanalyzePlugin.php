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
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // The QAnalyze plugin is only allowed to work in display mode
            if ( $context === 'display' )
                return true;

            // TODO - make it work in edit mode by replacing the field with a boolean?
            // TODO - ...or change the required field to be a boolean in the first place?
        }

        return false;
    }


    /**
     * Executes the Qanalyze Plugin.
     *
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $render_plugin_instance
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $rendering_options)
    {

        try {
            // ----------------------------------------
            // Load the options from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            $label_field = '';
            $xrd_field = '';
            $phase_field = '';
            $wavelength_field = '';
            $always_display_run_button = true;
            foreach ($options as $option_name => $option_value) {
                switch ($option_name) {
                    case 'label_field':
                        $label_field = $option_value;
                        break;
                    case 'xrd_pattern_field':
                        $xrd_field = $option_value;
                        break;
                    case 'phase_list_field':
                        $phase_field = $option_value;
                        break;
                    case 'wavelength_field':
                        $wavelength_field = $option_value;
                        break;
                    case 'always_display_run_button':
                        if ($option_value == 'no')
                            $always_display_run_button = false;
                        break;
                }
            }

            if ($label_field == '')
                throw new \Exception("The \"Sample Label Datafield\" option in the plugin's configuration must have a value.");
            if ($xrd_field == '')
                throw new \Exception("The \"XRD Pattern Datafield\" option in the plugin's configuration must have a value.");


            // ----------------------------------------
            // The values of these fields are used as regular expressions, so they may need to have
            //  certain characters escaped first
            $search = array(
                ".", "*", "+", "?", "^",
                "$", "{", "}", "(", ")",
                "|", "[", "]", "/"
            );
            $replacement = array(
                "\\.", "\\*", "\\+", "\\?", "\\^",
                "\\$", "\\{", "\\}", "\\(", "\\)",
                "\\|", "\\[", "\\]", "\\/"
            );

            if ($label_field !== '')
                $label_field = str_replace($search, $replacement, $label_field);
            if ($xrd_field !== '')
                $xrd_field = str_replace($search, $replacement, $xrd_field);
            if ($phase_field !== '')
                $phase_field = str_replace($search, $replacement, $phase_field);
            if ($wavelength_field !== '')
                $wavelength_field = str_replace($search, $replacement, $wavelength_field);


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
            // If the datafield has a value of "0", then the "Run Qanalyze" button should be hidden
            // ...in order to make this happen, $output needs to not be empty though
            // TODO - make this a render plugin setting of some sort?  "no_fallback" or something?
            $output = "<div></div>";

            if ( $rendering_options['context'] === 'display' ) {
                if ($always_display_run_button || $value > 0) {
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
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
