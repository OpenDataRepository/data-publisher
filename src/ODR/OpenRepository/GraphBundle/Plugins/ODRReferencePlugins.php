<?php

/**
 * Open Data Repository Data Publisher
 * ODRReferencePlugins.php
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * At the moment, the three different reference plugins in ODR have almost identical requirements
 * for rendering the actual citations...so it makes some sense to centralize that logic
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

use ODR\AdminBundle\Exception\ODRBadRequestException;

class ODRReferencePlugins
{

    /**
     * Takes the data from a render plugin and converts it into two different strings...one with
     * html, and one without.
     *
     * @param \Twig\Environment $templating
     * @param bool $is_datatype_admin
     * @param string $format
     * @param array $datatype
     * @param array $datarecord
     * @param array $render_plugin_fields
     * @param array $optional_rpf_names
     * @return array|null returns null if the plugin isn't configured correctly
     */
    protected function renderCitations($templating, $is_datatype_admin, $format, $datatype, $datarecord, $render_plugin_fields, $optional_rpf_names = [])
    {
        // Ensure the format is valid before trying to do anything
        switch ($format) {
            case 'rruff':
                break;
            default:
                throw new ODRBadRequestException('Unrecognized reference format: "'.$format.'"', 0xa2ecac6b);
        }

        // ----------------------------------------
        // Need to extract the values (and render plugin instances, if they exist) from the cache
        //  entries
        $datafield_mapping = [];
        foreach ($render_plugin_fields as $rpf_name => $rpf_df) {
            // Need to find the real datafield entry in the primary datatype array
            $rpf_df_id = $rpf_df['id'];

            $df = null;
            if ( isset($datatype['dataFields'][$rpf_df_id]) )
                $df = $datatype['dataFields'][$rpf_df_id];

            if ( is_null($df) ) {
                // Optional fields don't have to exist for this plugin to work...
                if ( isset($optional_rpf_names[$rpf_name]) )
                    continue;

                // ...but if a non-optional field doesn't exist in the datatype array, then either
                //  the plugin isn't configured correctly or the datafield is non-public and the user
                //  doesn't have permissions to view it (the most likely case)

                // The plugin can't continue executing in either case...
                if ( !$is_datatype_admin )
                    // ...regardless of what actually caused the issue, the plugin shouldn't execute
                    return null;
                else
                    // ...but if a datatype admin is seeing this, then they probably should fix it
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id.'...check plugin config.');
            }
            else {
                // The non-optional fields really should all be public...so actually throw an
                //  error if any of them aren't and the user can do something about it
                if ( isset($optional_rpf_names[$rpf_name]) )
                    continue;

                // If the datafield is non-public...
                $df_public_date = ($df['dataFieldMeta']['publicDate'])->format('Y-m-d H:i:s');
                if ( $df_public_date == '2200-01-01 00:00:00' ) {
                    if ( !$is_datatype_admin )
                        // ...but the user can't do anything about it, then just refuse to execute
                        return null;
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
                        $datafield_mapping[$key] = [
                            'datafield' => $df,
                            'render_plugin_instance' => $rpi
                        ];
                    }
                }
            }

            // If it does have a render plugin, then don't bother looking in the datarecord array
            //  for the value
            if ( isset($datafield_mapping[$key]) )
                continue;


            // Otherwise, look for the value in the datarecord array
            if ($typeclass === 'File') {
                $datafield_mapping[$key] = [];
                if ( isset($datarecord['dataRecordFields'][$rpf_df_id]) ) {
                    $datafield_mapping[$key] = [
                        'datarecordfield' => $datarecord['dataRecordFields'][$rpf_df_id]
                    ];
                }
            }
            else if ( !isset($datarecord['dataRecordFields'][$rpf_df_id]) ) {
                // As far as the reference plugins are concerned, empty strings are acceptable
                //  values when datarecordfield entries don't exist
                $datafield_mapping[$key] = ['text' => '', 'html' => ''];
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
                        $value = $drf['decimalValue'][0]['original_value'];
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
                        break;

                    default:
                        throw new \Exception('Invalid Fieldtype');
                }

                $value = trim((string) $value);
                $datafield_mapping[$key] = [
                    'text' => $value,
                    'html' => $value,
                ];
            }
        }

        // Need to try to ensure urls are valid...
        if ( !isset($datafield_mapping['url']['render_plugin_instance']) ) {
            $value = $datafield_mapping['url']['text'];
            if ( $value !== '' ) {
                // Ensure that DOIs that aren't entirely links still are valid
                if ( stripos($value, 'doi:') === 0 )
                    $value = 'https://doi.org/'.trim( substr($value, 4) );

                // Ensure that the values have an 'https://' prefix
                if ( !str_starts_with($value, 'http') )
                    $value = 'https://'.$value;

                if ( $value !== $datafield_mapping['url']['text'] )
                    $datafield_mapping['url'] = ['text' => $value, 'html' => $value];
            }
        }

        // Because of the possibility of render plugins, it's marginally simpler to use twig here
        $template = '@ODROpenRepositoryGraph/Base/References/format_'.$format.'.json.twig';
        $json = $templating->render(
            $template,
            [
                'datarecord' => $datarecord,
                'mapping' => $datafield_mapping,
            ]
        );
        // The twig fill will return a json array with two formatted strings...
        $values = json_decode($json, true);

        $ret = [];
        foreach ($values as $key => $val) {
            $val = trim( preg_replace('/(\s+)/', ' ', $val) );
//            $ret[$key] = str_replace(' ;', ';', $val);
            $ret[$key] = $val;
        }

        return $ret;
    }
}