<?php 

/**
 * Open Data Repository Data Publisher
 * URL Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The url plugin is designed to append the contents of a datafield
 * to a "base" URL provided by the datatype designer...the generated
 * HTML will look like
 *
 * <a target="_blank" href="{{ baseurl }}{{ encoded datafield value }}">{{ datafield value }}</a>
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;


class URLPlugin
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


            // Grab baseurl for the link
            if ( isset($options['base_url']) && $options['base_url'] !== 'auto' )
                $baseurl = $options['base_url'];
            else
                throw new \Exception('base_url not set');


            // Escape the datafield's value 
            $encoded_value = urlencode($value);
            $str = '<a target="_blank" href="'.$baseurl.$encoded_value.'">'.$value.'</a>';

            $output = "";
            switch ($themeType) {
                case 'text':
                case 'table':
                    $output = $str;
                    break;

                default:
                    $output = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:URL:url_default.html.twig', 
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
            throw new \Exception( $e->getMessage() );
        }
    }

}
