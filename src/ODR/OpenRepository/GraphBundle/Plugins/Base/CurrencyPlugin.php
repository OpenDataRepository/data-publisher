<?php

/**
 * Open Data Repository Data Publisher
 * Currency Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The currency plugin is designed to turn an integer or decimal value into a properly formatted
 * currency string.  Currently just does standard US currency output.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

class CurrencyPlugin
{
    /**
     * @var mixed
     */
    private $templating;


    /**
     * CurrencyPlugin constructor.
     *
     * @param EngineInterface $templating
     */
    public function __construct(EngineInterface $templating) {
        $this->templating = $templating;
    }


    /**
     * Executes the Currency Plugin on the provided datafield
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
                    case 'DecimalValue':
                        $entity = $drf['decimalValue'];
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


            // TODO - need options and stuff...

            // Make a currency string out of the datafield's value
            $str = '';
            if ($value !== '')
                $str = '$'.number_format($value, 2);


            $output = "";
            switch ($themeType) {
                case 'text':
                case 'table':
                    $output = $str;
                    break;

                default:
                    $output = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:Base:Currency/currency_default.html.twig',
                        array(
                            'datafield' => $datafield,
                            'value' => $str,
                        )
                    );
                break;
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }

}
