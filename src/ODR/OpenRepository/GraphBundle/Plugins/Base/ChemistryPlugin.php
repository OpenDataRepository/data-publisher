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
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datafield
     * @param array $datarecord
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        // The Chemistry Plugin should work in the 'text', 'display', and 'edit' contexts
        $context = $rendering_options['context'];
        if ( $context === 'text' || $context === 'display' || $context === 'edit' )
            return true;

        return false;
    }


    /**
     * Executes the Chemistry Plugin on the provided datafield
     *
     * @param array $datafield
     * @param array $datarecord
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
            // Extract various properties from the render plugin array
            $context = $rendering_options['context'];
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // Locate value of datafield
            $str = '';
            if ( isset($datarecord['dataRecordFields'][ $datafield['id'] ]) ) {
                $drf = $datarecord['dataRecordFields'][ $datafield['id'] ];
                $entity = '';
                switch ( $datafield['dataFieldMeta']['fieldType']['typeClass'] ) {
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
            if ( $context === 'text' ) {
                $output = $str;
            }
            else if ( $context === 'display' ) {
                if ( $datafield['dataFieldMeta']['fieldType']['typeName'] === "Paragraph Text" ) {
                    // Replace all newlines with the HTML '<br>' tag, since the output will be
                    //  displayed in a div instead of an <input> or <textarea>
                    $str = str_replace(array("\r\n", "\n\r", "\n", "\r"), '<br>', $str);
                }

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:Chemistry/chemistry_display_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'value' => $str,
                    )
                );
            }
            else if ( $context === 'edit' ) {
                // This may not be set...
                $is_datatype_admin = false;
                if ( isset($rendering_options['is_datatype_admin']) )
                    $is_datatype_admin = $rendering_options['is_datatype_admin'];

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:Chemistry/chemistry_edit_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,
                        'value' => $str,

                        'subscript_delimiter' => $sub,
                        'superscript_delimiter' => $super,

                        'is_datatype_admin' => $is_datatype_admin,
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
