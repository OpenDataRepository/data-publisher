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
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
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
            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf_name) );
                $datafield_mapping[$key] = $df;
            }


            // ----------------------------------------
            // For each datarecord that has been passed to this plugin, locate the associated comments field
            $comments = array();
            $count = 0;
            foreach ($datarecords as $dr_id => $dr) {
                $comment_datafield_id = $datafield_mapping['comment']['id'];
                $comment_datafield_typeclass = $datafield_mapping['comment']['dataFieldMeta']['fieldType']['typeClass'];

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
}
