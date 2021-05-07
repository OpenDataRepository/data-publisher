<?php 

/**
 * Open Data Repository Data Publisher
 * Default Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Allows datafields without a render plugin to render/display values from the same format that
 * would execute other datafield plugins.
 *
 * This should be avoided if at all possible.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class DefaultPlugin implements DatafieldPluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * DefaultPlugin constructor.
     *
     * @param EngineInterface $templating
     */
    public function __construct(EngineInterface $templating) {
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
            // ----------------------------------------
            // Grab value of datafield
            $typeclass = $datafield['dataFieldMeta']['fieldType']['typeClass'];

            $value = '';
            if ( isset($datarecord['dataRecordFields'][ $datafield['id'] ]) ) {
                $drf = $datarecord['dataRecordFields'][ $datafield['id'] ];
                $entity = array();
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

                // Datetime field values need to be turned into a string...
                if ($typeclass == 'DateTimeValue') {
                    $value = $entity[0]['value']->format('Y-m-d');
                    if ($value == '9999-12-31')
                        $value = '';
                }
                else {
                    $value = trim($entity[0]['value']);
                }
            }
            else {
                // No datarecordfield entry for this datarecord/datafield pair...because of the allowed fieldtypes, the plugin can just use the empty string in this case
                // Don't need special handling if this is a Datetime field due to default_default.html.twig just printing out a string instead of searching for the value
                $value = '';
            }


            $output = "";
            switch ($themeType) {
                case 'text':
                case 'table':
                    $output = $value;
                break;

                default:
                    $output = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:Base:Default/default_default.html.twig',
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
            // Just rethrow the exception
            throw $e;
        }
    }
}
