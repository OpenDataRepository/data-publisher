<?php 

/**
 * Open Data Repository Data Publisher
 * Comments Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The comments plugin takes a specially designed child datatypes
 * and collapses multiple datarecords of this child datatype into
 * an html table, sorted by the date each child datarecord was
 * created.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;


class CommentPlugin
{
    /**
     * @var mixed
     */
    private $templating;


    /**
     * CommentPlugin constructor.
     *
     * @param $templating
     */
    public function __construct($templating) {
        $this->templating = $templating;
    }


    /**
     * Executes the Graph Plugin on the provided datarecords
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin
     * @param array $theme
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin, $theme, $rendering_options)
    {

        try {

//            $str = '<pre>'.print_r($datarecords, true)."\n".print_r($datatype, true)."\n".print_r($render_plugin, true)."\n".print_r($theme, true).'</pre>';
//            throw new \Exception($str);


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

                // Want the full-fledged datafield entry...the one in $rpm['dataField'] has no render plugin or meta data
                // Unfortunately, the desired one is buried inside the $theme array somewhere...
                $df = null;
                foreach ($theme['themeElements'] as $te) {
                    if ( isset($te['themeDataFields']) ) {
                        foreach ($te['themeDataFields'] as $tdf) {
                            if ( isset($tdf['dataField']) && $tdf['dataField']['id'] == $df_id ) {
                                $df = $tdf['dataField'];
                                break;
                            }
                        }
                    }

                    if ($df !== null)
                        break;
                }

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf['fieldName'].'", mapped to df_id '.$df_id);

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                $datafield_mapping[$key] = array('datafield' => $df, 'render_plugin' => $df['dataFieldMeta']['renderPlugin']);
            }

//            throw new \Exception( '<pre>'.print_r($datafield_mapping, true).'</pre>' );

            // ----------------------------------------
            // For each datarecord that has been passed to this plugin, locate the associated comments field
            $comments = array();
            $count = 0;
            foreach ($datarecords as $dr_id => $dr) {
                $comment_datafield_id = $datafield_mapping['comment']['datafield']['id'];
                $comment_datafield_typeclass = $datafield_mapping['comment']['datafield']['dataFieldMeta']['fieldType']['typeClass'];

                $entity = array();
                $drf = $dr['dataRecordFields'][$comment_datafield_id];
                switch ($comment_datafield_typeclass) {
                    case 'LongText':
                        $entity = $drf['longText'];
                        break;

                    default:
                        throw new \Exception('Invalid Fieldtype for comment');
                        break;
                }

                // Grab the comment text and when it was made
                $date = $entity[0]['created'];
                $date = $date->format('Y-m-d');

                $count++;
                $comments[$date.'_'.$count] = array('datarecord' => $dr, 'entity' => $entity[0]);
            }

            // Sort by date, most recent to least recent
            if ( count($comments) > 1 )
                krsort($comments);

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Comments:comments.html.twig', 
                array(
                    'datatype_array' => $datatype,
                    'datarecord_array' => $datarecords,
                    'mapping' => $datafield_mapping,

                    'target_datatype_id' => $datatype['id'],
/*
                    'shortname' => $datatype->getShortName(),
                    'comments' => $comments,
                    'public_only' => $public_only,
*/
                )
            );

            return $output;
        }
        catch (\Exception $e) {
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Default:default_error.html.twig',
                array(
                    'message' => $e->getMessage()
                )
            );
            throw new \Exception( $output );
        }
    }

}
