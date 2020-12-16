<?php

/**
 * Open Data Repository Data Publisher
 * AMCSD Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This plugin attempts to mimic the original behavior of the American Mineralogy Crystal Structure
 * Database (AMCSD).  The plugin itself just blocks editing of most of its required fields, since
 * they're technically derived from the contents of the AMC file.
 *
 * The actual derivation is performed by AMCSDFileEncryptedSubscriber.php.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// ODR
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class AMCSDPlugin implements DatatypePluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * AMCSD Plugin constructor.
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
        // This render plugin does most of its work when in Edit mode, but also needs to get
        //  executed in Display mode so the user can be notified of problems with the AMC file
//        if ( isset($rendering_options['context']) && $rendering_options['context'] === 'edit' )
            return true;

//        return false;
    }


    /**
     * Executes the AMCSD Plugin on the provided datarecords
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


            $plugin_fields = array();
            $editable_datafields = array();
            foreach ($render_plugin_map as $rpm) {
                // Get the entities connected by the render_plugin_map entity??
                $rpf = $rpm['renderPluginFields'];

                $rpf_name = $rpf['fieldName'];
                $df_id = $rpm['dataField']['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$df_id]) )
                    $df = $datatype['dataFields'][$df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf['fieldName'].'", mapped to df_id '.$df_id);

                // Need to tweak display parameters for several of the fields...
                $plugin_fields[$df_id] = $rpf_name;

                // These strings are the "name" entries for each of the required fields
                // So, "database_code_amcsd", not "database code"
                switch ($rpf_name) {
                    case 'fileno':    // TODO - this field technically doesn't come from the AMC file, but also shouldn't really be editable?
                    case 'database_code_amcsd':
                    case 'Authors':
                    case 'File Contents':

                    // These three can be edited
//                    case 'amc_file':
//                    case 'cif_file':
//                    case 'dif_file':

                    case 'Mineral':
                    case 'a':
                    case 'b':
                    case 'c':
                    case 'alpha':
                    case 'beta':
                    case 'gamma':
                    case 'Space Group':
                        // None of these fields can be edited, since they come straight from the AMC file
                        break;

                    default:
                        $editable_datafields[$df_id] = $rpf_name;
                        break;
                }
            }


            // ----------------------------------------
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
            // Might need to prepend the AMC file problems themeElement to the output...
            $output = '';
            if ( isset($rendering_options['context']) && $rendering_options['context'] === 'edit' ) {
                // When in edit mode, use the plugin's override
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_edit_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord_array' => array($datarecord['id'] => $datarecord),
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,

                        'plugin_fields' => $plugin_fields,
                        'editable_datafields' => $editable_datafields,
                    )
                );
            }
            else {
                // Otherwise when in display mode, just use the default display render
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_display_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord' => $datarecord,
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'plugin_fields' => $plugin_fields,
                    )
                );
            }

            // If an AMC file is uploaded, but none of the other values exist, then there was a
            //  problem, and an additional themeElement should be inserted to complain
            if ( self::fileHasProblem($plugin_fields, $datarecord) ) {
                // Determine whether the user can edit the "AMC File" datafield
                $can_edit_relevant_datafield = false;
                $df_id = array_search('AMC File', $editable_datafields);
                if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['edit']) )
                    $can_edit_relevant_datafield = true;

                $error_div = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:AMCSD/amcsd_error.html.twig',
                    array(
                        'can_edit_relevant_datafield' => $can_edit_relevant_datafield,
                    )
                );

                $output = $error_div.$output;
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * The file uploaded into the "AMC File" field could have a problem...if it does, the event
     * subscriber should've caught it, and forced all (or hopefully most) of the other plugin fields
     * to be blank...
     *
     * @param array $plugin_fields
     * @param array $datarecord
     *
     * @return bool
     */
    private function fileHasProblem($plugin_fields, $datarecord)
    {
        $value_mapping = array();
        foreach ($datarecord['dataRecordFields'] as $df_id => $drf) {
            // Don't want to have to locate typeclass...
            unset( $drf['id'] );
            unset( $drf['created'] );
            unset( $drf['image'] );

            if ( count($drf) === 1 ) {
                // This is a file datafield...
                if ( !empty($drf['file']) )
                    // ...has something uploaded
                    $value_mapping[$df_id] = $drf['file'][0];
                else
                    // ...doesn't have anything uploaded
                    $value_mapping[$df_id] = array();
            }
            else {
                // Not a file datafield, but don't want to have to locate typeclass, so...
                unset( $drf['file'] );
                foreach ($drf as $typeclass => $entity) {
                    // Should only be one entry left in typeclass
                    if ( !empty($entity) )
                        $value_mapping[$df_id] = $entity[0]['value'];
                    else
                        $value_mapping[$df_id] = '';
                }
            }
        }

        // If the "AMC file" datafield doesn't have anything uploaded to it, then there can't be
        //  a problem with the field
        $df_id = array_search('AMC File', $plugin_fields);
        if ( empty($value_mapping[$df_id]) )
            return false;


        // Otherwise, there's something uploaded to the "AMC File" datafield...determine whether
        //  all of the other fields derived from the AMC file have a value
        foreach ($plugin_fields as $df_id => $rpf_name) {
            switch ($rpf_name) {
                case 'database_code_amcsd':
                case 'Authors':
                case 'File Contents':
                case 'Mineral':
                case 'a':
                case 'b':
                case 'c':
                case 'alpha':
                case 'beta':
                case 'gamma':
                case 'Space Group':
                    // All of these fields need to have a value for the AMC file to be valid...if
                    //  they don't, then the file has a problem
                    if ( is_null($value_mapping[$df_id]) || $value_mapping[$df_id] === '' )
                        return true;
                    break;

//                case 'fileno':    // TODO - this field technically doesn't come from the AMC file, but also shouldn't really be editable?
//                case 'amc_file':
//                case 'cif_file':
//                case 'dif_file':
                default:
                    // These fields, or another other field in the datatype, don't matter for the
                    //  purposes of determining whether the amc file had problems
                    break;
            }
        }

        // Otherwise, all required fields have a value, so there's no problem with the AMC file
        return false;
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
