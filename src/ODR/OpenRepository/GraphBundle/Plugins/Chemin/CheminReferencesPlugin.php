<?php

/**
 * Open Data Repository Data Publisher
 * Chemin References Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Chemin References plugin renders data describing an academic reference in a single line,
 * instead of scattered across a number of datafields.  Separate from the default Render Plugin
 * because they require an additional file datafield for "Supporting Files".
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Chemin;

// ODR
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class CheminReferencesPlugin implements DatatypePluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * CheminReferencesPlugin constructor.
     *
     * @param EngineInterface $templating
     */
    public function __construct(EngineInterface $templating) {
        $this->templating = $templating;
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin, $datatype, $rendering_options)
    {
        // TODO - make changes so it can actually run in Edit mode?
        // This render plugin isn't allowed to work when in edit mode
        if ( isset($rendering_options['context']) && $rendering_options['context'] === 'edit' )
            return false;

        return true;
    }


    /**
     * Executes the CheminReferences Plugin on the provided datarecord
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param array $token_list
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {

        try {
            // ----------------------------------------
            // Grab various properties from the render plugin array
            $render_plugin_instance = $render_plugin['renderPluginInstance'][0];
            $render_plugin_map = $render_plugin_instance['renderPluginMap'];

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;

            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($render_plugin_map as $rpm) {
                // Get the entities connected by the render_plugin_map entity??
                $rpf = $rpm['renderPluginFields'];
                $df_id = $rpm['dataField']['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$df_id]) )
                    $df = $datatype['dataFields'][$df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf['fieldName'].'", mapped to df_id '.$df_id);

                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                $pluginClassName = $df['dataFieldMeta']['renderPlugin']['pluginClassName'];

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                if ( !isset($datarecord['dataRecordFields'][$df_id]) ) {
                    // As far as the reference plugin is concerned, empty strings are acceptable values when datarecordfield entries don't exist
                    $datafield_mapping[$key] = '';
                }
                elseif ( $pluginClassName !== 'odr_plugins.base.default' || $typeclass === 'File' ) {
                    // Either this is a file datafield, or ODR needs to execute a render plugin on
                    //  this datafield's value...have to leave the data in this format so twig can
                    //  either call the required render plugin or iterate over the files
                    $datafield_mapping[$key] = array(
                        'datafield' => $df,
                        'render_plugin' => $df['dataFieldMeta']['renderPlugin'],
                        'datarecordfield' => $datarecord['dataRecordFields'][$df_id]
                    );
                }
                else {
                    // Don't need to execute a render plugin on this datafield's value...extract it
                    //  directly from the datarecord array
                    // $drf is guaranteed to exist at this point
                    $drf = $datarecord['dataRecordFields'][$df_id];
                    $value = '';

                    switch ($typeclass) {
                        case 'IntegerValue':
                            $value = $drf['integerValue'][0]['value'];
                            break;
                        case 'DecimalValue':
                            $value = $drf['decimalValue'][0]['value'];
                            break;
                        case 'ShortVarchar':
                            $value = $drf['shortVarchar'][0]['value'];
                            break;
                        case 'MediumVarchar':
                            $value = $drf['mediumVarchar'][0]['value'];
                            break;
                        case 'LongVarchar':
                            $value = $drf['longVarchar'][0]['value'];
                            break;
                        case 'LongText':
                            $value = $drf['longText'][0]['value'];
                            break;
                        case 'DateTimeValue':
                            $value = $drf['dateTimeValue'][0]['value']->format('Y-m-d');
                            if ($value == '9999-12-31')
                                $value = '';
                            $datafield_mapping[$key] = $value;
                            break;

                        default:
                            throw new \Exception('Invalid Fieldtype');
                            break;
                    }

                    $datafield_mapping[$key] = trim($value);
                }
            }

            // Going to render the reference differently if it's top-level...
            $is_top_level = $rendering_options['is_top_level'];

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Base:CheminReferences/chemin_references.html.twig',
                array(
                    'datarecord' => $datarecord,
                    'mapping' => $datafield_mapping,

                    'is_top_level' => $is_top_level,
                )
            );

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
