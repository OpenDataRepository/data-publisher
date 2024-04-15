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
use ODR\AdminBundle\Component\Event\PluginAttachEvent;
use ODR\AdminBundle\Component\Event\PluginOptionsChangedEvent;
use ODR\AdminBundle\Component\Event\PluginPreRemoveEvent;
// Services
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class ChemistryPlugin implements DatafieldPluginInterface, TableResultsOverrideInterface
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
     * @param array|null $datarecord
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // The Chemistry Plugin should work in the 'text', 'display', and 'edit' contexts
            if ($context === 'text' || $context === 'display' || $context === 'edit')
                return true;
        }

        return false;
    }


    /**
     * Executes the Chemistry Plugin on the provided datafield
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


            if ( $str !== '' )
                $str = self::applyChemistryFormatting($super, $sub, $str);

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
     * Applies chemistry formatting to the given string.
     *
     * @param string $superscript_delimiter
     * @param string $subscript_delimiter
     * @param string $str
     * @return string
     */
    private function applyChemistryFormatting($superscript_delimiter, $subscript_delimiter, $str)
    {
        // Ensure any less/greater than signs don't confuse the browser
        $str = str_replace(array('<', '>'), array('&lt;', '&gt;'), $str);

        // Apply the subscripts...
        $sub = preg_quote($subscript_delimiter);
        $str = preg_replace('/'.$sub.'([^'.$sub.']+)'.$sub.'/', '<sub>$1</sub>', $str);

        // Apply the superscripts...
        $super = preg_quote($superscript_delimiter);
        $str = preg_replace('/'.$super.'([^'.$super.']+)'.$super.'/', '<sup>$1</sup>', $str);

        // Replace the "[box]" sequence with U+25FB "◻" (WHITE MEDIUM SQUARE)
        $str = preg_replace('/\[box\]/', '◻', $str);

        return $str;
    }


    /**
     * Called when a user attaches this render plugin to a datafield.
     *
     * @param PluginAttachEvent $event
     */
    public function onPluginAttach(PluginAttachEvent $event)
    {
        self::clearCacheEntries($event->getRenderPluginInstance());
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


    /**
     * Returns an array of datafield values that TableThemeHelperService should display, instead of
     * using the values in the datarecord.
     *
     * @param array $render_plugin_instance
     * @param array $datarecord
     * @param array|null $datafield
     *
     * @return string[] An array where the keys are datafield ids, and the values are the strings to display
     */
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // Need to get the super/subscript values
        $superscript_delimiter = $render_plugin_instance['renderPluginOptionsMap']['superscript_delimiter'];
        $subscript_delimiter = $render_plugin_instance['renderPluginOptionsMap']['subscript_delimiter'];

        // Since this is a datafield plugin, $datafield has a value
        $df_id = $datafield['id'];

        // Still need to find the value for this datafield in the given datarecord...
        $value = array();
        if ( isset($datarecord['dataRecordFields'][$df_id]) ) {
            $drf = $datarecord['dataRecordFields'][$df_id];

            // Don't know the typeclass, so brute-force it
            unset( $drf['id'] );
            unset( $drf['created'] );
            unset( $drf['file'] );
            unset( $drf['image'] );
            unset( $drf['dataField'] );

            // The remaining entry will be the correct value
            foreach ($drf as $typeclass => $data) {
                if ( isset($data[0]['value']) && $data[0]['value'] !== '' )
                    $value[$df_id] = self::applyChemistryFormatting($superscript_delimiter, $subscript_delimiter, $data[0]['value']);
            }
        }

        // Return the modified value
        return $value;
    }
}
