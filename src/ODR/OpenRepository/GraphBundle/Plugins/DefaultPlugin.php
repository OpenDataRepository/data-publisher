<?php 

/**
 * Open Data Repository Data Publisher
 * Default Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Allows datafields without a render plugin to render/display values from
 * the same format that would execute other datafield plugins.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;


class DefaultPlugin
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
     * Executes the URL Plugin on the provided datafield
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

            // Grab value of datafield
            $drf = $datarecord['dataRecordFields'][ $datafield['id'] ];
            $typeclass = $datafield['dataFieldMeta']['fieldType']['typeClass'];

            $entity = '';
            switch ($typeclass) {
                case 'IntegerValue':
                    $entity = $drf['integerValue'];
                    break;
                case 'DecimalValue':
                    $entity = $drf['decimalValue'];
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
                case 'DateTimeValue':
                    $entity = $drf['dateTimeValue'];
                    break;

                default:
                    throw new \Exception('Invalid Fieldtype');
                    break;
            }

            $value = '';
            if ($typeclass == 'DateTimeValue') {
                $value = $entity[0]['value']->format('Y-m-d');
                if ($value == '-0001-11-30')
                    $value = '';
            }
            else {
                $value = trim($entity[0]['value']);
            }


            $output = "";
            switch ($themeType) {
                case 'text':
                case 'table':
                    $output = $value;
                break;

                default:
                    $output = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:Default:default_default.html.twig', 
                        array(
                            'datafield' => $datafield,
                            'value' => $value,
                        )
                    );
                break;
            }

            return $output;
        }
        catch (\Exception $e) {
            throw new \Exception( $e->getMessage() );
        }
    }

}
