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
class CommentPlugin implements DatatypePluginInterface
{

    /**
     * CommentPlugin constructor.
     *
     * @param \Twig\Environment $templating
     */
    public function __construct(private readonly \Twig\Environment $templating)
    {
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

            // This render plugin is only allowed to work in display mode
            if ( $context === 'display' )
                return true;
        }

        // TODO - should this have an option to work in edit mode?  would need some shennanigans so that the edit fields don't get clobbered

        return false;
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
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = [], $datatype_permissions = [], $datafield_permissions = [], $token_list = [])
    {

        try {
            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = [];
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

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf_name) );
                $datafield_mapping[$key] = $df;
            }


            // ----------------------------------------
            // For each datarecord that has been passed to this plugin, locate the associated comments field
            $comments = [];
            $count = 0;
            foreach ($datarecords as $dr_id => $dr) {
                $comment_datafield_id = $datafield_mapping['comment']['id'];
                $comment_datafield_typeclass = $datafield_mapping['comment']['dataFieldMeta']['fieldType']['typeClass'];

                if ( isset($dr['dataRecordFields'][$comment_datafield_id]) ) {
                    $entity = [];
                    $drf = $dr['dataRecordFields'][$comment_datafield_id];
                    $entity = match ($comment_datafield_typeclass) {
                        'ShortVarchar' => $drf['shortVarchar'],
                        'MediumVarchar' => $drf['mediumVarchar'],
                        'LongVarchar' => $drf['longVarchar'],
                        'LongText' => $drf['longText'],
                        default => throw new \Exception('Invalid Fieldtype for comment'),
                    };

                    // Grab the comment text and when it was made
                    $date = $entity[0]['created'];
                    $date = $date->format('Y-m-d H:i:s');

                    $count++;
                    $comments[$date.'_'.$count] = ['datarecord' => $dr, 'entity' => $entity[0]];
                }
            }

            // Sort by date, most recent to least recent
            if ( count($comments) > 1 )
                krsort($comments);

            $output = $this->templating->render(
                '@ODROpenRepositoryGraph/Base/Comments/comments.html.twig',
                [
                    'datatype' => $datatype,
                    'datarecord_array' => $datarecords,
                    'mapping' => $datafield_mapping,
                    'comments' => $comments,
                ]
            );

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
