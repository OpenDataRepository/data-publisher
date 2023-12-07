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
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // This render plugin is only allowed to work in 'display' or 'text' modes
            if ( $context === 'display' || $context === 'text' )
                return true;

            // TODO - make changes so it can actually run in Edit mode?
        }

        return false;
    }


    /**
     * Executes the CheminReferences Plugin on the provided datarecord
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin_instance
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
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {

        try {
            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Grab various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;


            // ----------------------------------------
            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null) {
                    // If the datafield doesn't exist in the datatype_array, then either the datafield
                    //  is non-public and the user doesn't have permissions to view it (most likely),
                    //  or the plugin somehow isn't configured correctly

                    // The plugin can't continue executing in either case...
                    if ( !$is_datatype_admin )
                        // ...regardless of what actually caused the issue, the plugin shouldn't execute
                        return '';
                    else
                        // ...but if a datatype admin is seeing this, then they probably should fix it
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id.'...check plugin config.');
                }
                else {
                    // All of this plugin's fields really should be public...so actually throw an
                    //  error if any of them aren't and the user can do something about it

                    // If the datafield is non-public...
                    $df_public_date = ($df['dataFieldMeta']['publicDate'])->format('Y-m-d H:i:s');
                    if ( $df_public_date == '2200-01-01 00:00:00' ) {
                        if ( !$is_datatype_admin )
                            // ...but the user can't do anything about it, then just refuse to execute
                            return '';
                        else
                            // ...the user can do something about it, so they need to fix it
                            throw new \Exception('The field "'.$rpf_name.'" is not public...all fields which are part of a reference MUST be public.');
                    }
                }

                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf_name) );

                // The datafield may have a render plugin that should be executed, but only if
                //  it's not a file field...
                if ( !empty($df['renderPluginInstances']) && $typeclass !== 'File' ) {
                    foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['render'] !== false ) {
                            // ...if it does, then create an array entry for it
                            $datafield_mapping[$key] = array(
                                'datafield' => $df,
                                'render_plugin_instance' => $rpi
                            );
                        }
                    }
                }

                // If it does have a render plugin, then don't bother looking in the datarecord array
                //  for the value
                if ( isset($datafield_mapping[$key]) )
                    continue;


                // Otherwise, look for the value in the datarecord array
                if ( !isset($datarecord['dataRecordFields'][$rpf_df_id]) ) {
                    // As far as the reference plugin is concerned, empty strings are acceptable
                    //  values when datarecordfield entries don't exist
                    $datafield_mapping[$key] = '';
                }
                elseif ($typeclass === 'File') {
                    $datafield_mapping[$key] = array(
                        'datarecordfield' => $datarecord['dataRecordFields'][$rpf_df_id]
                    );
                }
                else {
                    // Don't need to execute a render plugin on this datafield's value...extract it
                    //  directly from the datarecord array
                    // $drf is guaranteed to exist at this point
                    $drf = $datarecord['dataRecordFields'][$rpf_df_id];
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

            // Need to try to ensure urls are valid...
            if ( $datafield_mapping['url'] !== '' ) {
                // Ensure that DOIs that aren't entirely links still are valid
                if ( strpos($datafield_mapping['url'], 'doi:') === 0 )
                    $datafield_mapping['url'] = 'https://doi.org/'.trim( substr($datafield_mapping['url'], 4) );

                // Ensure that the values have an 'https://' prefix
                if ( strpos($datafield_mapping['url'], 'http') !== 0 )
                    $datafield_mapping['url'] = 'https://'.$datafield_mapping['url'];
            }

            // Going to render the reference differently if it's top-level...
            $is_top_level = $rendering_options['is_top_level'];

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Chemin:CheminReferences/chemin_references.html.twig',
                array(
                    'datarecord' => $datarecord,
                    'mapping' => $datafield_mapping,

                    'is_top_level' => $is_top_level,
                    'original_context' => $rendering_options['context'],
                )
            );

            // If meant for text output, then replace all whitespace sequences with a single space
            if ( $rendering_options['context'] === 'text' )
                $output = preg_replace('/(\s+)/', ' ', $output);

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
