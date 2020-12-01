<?php 

/**
 * Open Data Repository Data Publisher
 * Comments Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The comments plugin takes a specially designed child datatypes and collapses multiple datarecords
 * of this child datatype into an html table, sorted by the date each child datarecord was created.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// ODR
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class CommentPlugin implements DatatypePluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * CommentPlugin constructor.
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
        // This render plugin isn't allowed to work when in edit mode
        if ( isset($rendering_options['context']) && $rendering_options['context'] === 'edit' )
            return false;

        return true;
    }


    /**
     * Executes the Comment Plugin on the provided datarecords
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
            $render_plugin_options = $render_plugin_instance['renderPluginOptions'];

            // Remap render plugin by name => value
            $options = array();
            foreach($render_plugin_options as $option) {
                if ( $option['active'] == 1 )
                    $options[ $option['optionName'] ] = $option['optionValue'];
            }

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

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                $datafield_mapping[$key] = array('datafield' => $df, 'render_plugin' => $df['dataFieldMeta']['renderPlugin']);
            }


            // ----------------------------------------
            // For each datarecord that has been passed to this plugin, locate the associated comments field
            $comments = array();
            $count = 0;
            foreach ($datarecords as $dr_id => $dr) {
                $comment_datafield_id = $datafield_mapping['comment']['datafield']['id'];
                $comment_datafield_typeclass = $datafield_mapping['comment']['datafield']['dataFieldMeta']['fieldType']['typeClass'];

                if ( isset($dr['dataRecordFields'][$comment_datafield_id]) ) {
                    $entity = array();
                    $drf = $dr['dataRecordFields'][$comment_datafield_id];
                    switch ($comment_datafield_typeclass) {
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
                            throw new \Exception('Invalid Fieldtype for comment');
                            break;
                    }

                    // Grab the comment text and when it was made
                    $date = $entity[0]['created'];
                    $date = $date->format('Y-m-d H:i:s');

                    $count++;
                    $comments[$date.'_'.$count] = array('datarecord' => $dr, 'entity' => $entity[0]);
                }
            }

            // Sort by date, most recent to least recent
            if ( count($comments) > 1 )
                krsort($comments);

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Base:Comments/comments.html.twig',
                array(
                    'datatype' => $datatype,
                    'datarecord_array' => $datarecords,
                    'mapping' => $datafield_mapping,
                    'comments' => $comments,
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
