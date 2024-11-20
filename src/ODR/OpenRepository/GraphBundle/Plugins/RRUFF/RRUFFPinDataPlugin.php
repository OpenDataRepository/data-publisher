<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Pin Data Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This is a quick/dirty implementation of converting the raw data used to define orientation in
 * RRUFF into a slightly better format TODO
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class RRUFFPinDataPlugin implements DatatypePluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * RRUFF Pin Data Plugin constructor
     *
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->templating = $templating;
        $this->logger = $logger;
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

            if ( $context === 'display' )
                return true;
        }

        return false;
    }


    /**
     * Executes the RRUFF Pin Data Plugin on the provided datarecord
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

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
//            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            foreach ($theme_array as $t_id => $t)
                $initial_theme_id = $t_id;

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;

            // ----------------------------------------
            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            $plugin_fields = array();
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

                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];

                if ( !isset($datarecord['dataRecordFields'][$rpf_df_id]) ) {
                    // If a drf entry doesn't exist, then use the empty string instead
                    $datafield_mapping[$rpf_name] = '';
                }
                else {
                    // Otherwise, attempt to extract the value from the drf entry
                    $drf = $datarecord['dataRecordFields'][$rpf_df_id];
                    switch ($typeclass) {
                        case 'ShortVarchar':
                            $data = $drf['shortVarchar'];
                            if ( !isset($data[0]) || !isset($data[0]['value']) )
                                $datafield_mapping[$rpf_name] = '';
                            else
                                $datafield_mapping[$rpf_name] = $data[0]['value'];
                            break;

                        case 'Radio':
                            foreach ($drf['radioSelection'] as $ro_id => $rs) {
                                if ( $rs['selected'] === 1 )
                                    $datafield_mapping[$rpf_name] = $rs['radioOption']['optionName'];
                            }
                            break;

                        default:
                            throw new ODRException('Unexpected fieldtype');
                    }
                }

                $plugin_fields[$rpf_df_id] = $rpf_df;
                $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;
            }


            // ----------------------------------------
            // Convert the selected options into a single string
            $vector_parallel_str = self::getVectorStr(
                $datafield_mapping['Vector Parallel X'],
                $datafield_mapping['Vector Parallel Y'],
                $datafield_mapping['Vector Parallel Z'],
                $datafield_mapping['Vector Parallel Reference Space']
            );
            $vector_perpendicular_str = self::getVectorStr(
                $datafield_mapping['Vector Perpendicular X'],
                $datafield_mapping['Vector Perpendicular Y'],
                $datafield_mapping['Vector Perpendicular Z'],
                $datafield_mapping['Vector Perpendicular Reference Space']
            );


            // ----------------------------------------
            $record_display_view = 'single';
            if ( isset($rendering_options['record_display_view']) )
                $record_display_view = $rendering_options['record_display_view'];

            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFPinData/pindata_display_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord' => $datarecord,
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'record_display_view' => $record_display_view,
                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'plugin_fields' => $plugin_fields,

                        'vector_parallel_str' => $vector_parallel_str,
                        'vector_perpendicular_str' => $vector_perpendicular_str,
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
     * Converts the selected pin data into a single string.
     *
     * @param string $x
     * @param string $y
     * @param string $z
     * @param string $ref
     * @return string
     */
    private function getVectorStr($x, $y, $z, $ref)
    {
        $vector_str = $x.' '.$y.' '.$z;

        $dir = '';
        switch ($vector_str) {
            // Aligned to a single direction
            case '-1 0 0':
                $dir = '-a';
                break;
            case '1 0 0':
                $dir = 'a';
                break;
            case '0 -1 0':
                $dir = '-b';
                break;
            case '0 1 0':
                $dir = 'b';
                break;
            case '0 0 -1':
                $dir = '-c';
                break;
            case '0 0 1':
                $dir = 'c';
                break;

            // Aligned to more than one direction
            case '0 1 1':
            case '1 0 1':
            case '1 1 0':
            case '1 1 1':
                $dir = '';
                break;

            // Nothing else is allowed
            default:
                $vector_str = '';
                break;
        }

        if ( $vector_str === '' ) {
            // Not a valid alignment
            return $vector_str;
        }
        else {
            if ( $ref === 'direct' ) {
                $vector_str = '['.$vector_str.']';

                if ( $dir !== '' )
                    $vector_str = '<b>'.$dir.'</b>&nbsp;&nbsp;&nbsp;'.$vector_str;
                return $vector_str;
            }
            else if ( $ref === 'reciprocal' ) {
                $vector_str = '('.$vector_str.')';

                if ( $dir !== '' )
                    $vector_str = '<b>'.$dir.'*</b>&nbsp;&nbsp;&nbsp;'.$vector_str;
                return $vector_str;
            }
            else {
                // Some other problem
                return '';
            }
        }
    }
}
