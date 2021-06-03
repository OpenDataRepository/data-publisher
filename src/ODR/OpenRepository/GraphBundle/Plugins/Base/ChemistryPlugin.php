<?php 

/**
 * Open Data Repository Data Publisher
 * Chemistry Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The chemistry plugin is designed to substitute certain characters in a datafield for html
 * <sub> and <sup> tags, which allow the string to more closely resemble a chemical formula.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Entities
use ODR\AdminBundle\Entity\RenderPluginInstance;
// Events
use ODR\AdminBundle\Component\Event\PluginOptionsChangedEvent;
use ODR\AdminBundle\Component\Event\PluginPreRemoveEvent;
// Services
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class ChemistryPlugin implements DatafieldPluginInterface
{

    /**
     * @var DatarecordInfoService
     */
    private $dri_service;

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * ChemistryPlugin constructor.
     *
     * @param DatarecordInfoService $dri_service
     * @param EngineInterface $templating
     */
    public function __construct(DatarecordInfoService $dri_service, EngineInterface $templating) {
        $this->dri_service = $dri_service;
        $this->templating = $templating;
    }


    /**
     * Executes the Chemistry Plugin on the provided datafield
     *
     * @param array $datafield
     * @param array $datarecord
     * @param array $render_plugin_instance
     * @param string $themeType     One of 'master', 'search_results', 'table', TODO?
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $themeType = 'master')
    {

        try {
            // ----------------------------------------
            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // Locate value of datafield
            $str = '';
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
                $str = trim( $entity[0]['value'] );
            }
            else {
                // No datarecordfield entry for this datarecord/datafield pair...because of the
                //  allowed fieldtypes, the plugin can just use the empty string in this case
                $str = '';
            }


            // Extract subscript/superscript characters from render plugin options
            $sub = "_";
            $super = "^";
            if ( isset($options['subscript_delimiter']) && $options['subscript_delimiter'] != '' )
                $sub = $options['subscript_delimiter'];
            else
                $sub = "_";
            if ( isset($options['superscript_delimiter']) && $options['superscript_delimiter'] != '' )
                $super = $options['superscript_delimiter'];
            else
                $super = "^";


            // Apply the subscripts...
            $sub = preg_quote($sub);
            $str = preg_replace('/'.$sub.'([^'.$sub.']+)'.$sub.'/', '<sub>$1</sub>', $str);
            
            // Apply the superscripts...
            $super = preg_quote($super);
            $str = preg_replace('/'.$super.'([^'.$super.']+)'.$super.'/', '<sup>$1</sup>', $str);
            
            // Redo the boxes...
            // TODO - replace with a css class? or with the '□' character? (0xE2 0x96 0xA1)
            $str = preg_replace('/\[box\]/', '<span style="border: 1px solid #333; font-size:7px;">&nbsp;&nbsp;&nbsp;</span>', $str);


            $output = "";
            switch ($themeType) {
                case 'text':
                case 'table':
                    $output = $str;
                break;

                default:
                    $output = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:Base:Chemistry/chemistry_default.html.twig',
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


    /**
     * Called when a user changes RenderPluginOptions or RenderPluginMaps entries for this plugin.
     *
     * @param PluginOptionsChangedEvent $event
     */
    public function onPluginOptionsChanged(PluginOptionsChangedEvent $event)
    {
        self::clearCacheEntries($event->getRenderPluginInstance());
    }


    /**
     * Called when a user removes this render plugin from a datafield.
     *
     * @param PluginPreRemoveEvent $event
     */
    public function onPluginPreRemove(PluginPreRemoveEvent $event)
    {
        self::clearCacheEntries($event->getRenderPluginInstance());
    }


    /**
     * The 'cached_table_data' entries store the values of datafields so the plugins don't have
     * to be executed every single time a search results page is loaded...therefore, when this
     * plugin is removed or a setting is changed, these cache entries need to get deleted
     *
     * @param RenderPluginInstance $rpi
     */
    private function clearCacheEntries($rpi)
    {
        // This is a datafield plugin, so getting the datatype via the datafield...
        $datatype_id = $rpi->getDataField()->getDataType()->getGrandparent()->getId();
        $this->dri_service->deleteCachedTableData($datatype_id);
    }
}
