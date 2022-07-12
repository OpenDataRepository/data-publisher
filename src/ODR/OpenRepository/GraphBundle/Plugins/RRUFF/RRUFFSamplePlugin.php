<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Sample Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The primary function of the RRUFF Sample Plugin is to screw with the cached data arrays so
 * that ODR will render child datatypes/datarecords in different places than it typically would, so
 * it matches the look of the existing sample stuff on rruff.info.
 *
 * Doing this is incredibly irritating.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Services
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class RRUFFSamplePlugin implements DatatypePluginInterface
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
     * RRUFFSamplePlugin constructor.
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
        // TODO - more contexts?
        if ( isset($rendering_options['context']) ) {
            if ( $rendering_options['context'] === 'display' ) {
                return true;
            }
        }

        // Render plugins aren't called outside of the above contexts, so this will never be run
        // ...for now
        return false;
    }


    /**
     * Executes the RRUFF Sample Plugin on the provided datarecords
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

            // Use the values in the options to locate relevant datatypes by their name
            $ima_mineral_dt_id = null;
            $rruff_reference_dt_id = null;
            $comment_dt_ids = null;
            self::findDatatypeIds($datatype, $options, $ima_mineral_dt_id, $rruff_reference_dt_id, $comment_dt_ids);

            // If either of the reference datatypes or the ima datatype were not found, then disable
            //  all parts of the plugin that affect them
            if ( is_null($ima_mineral_dt_id) || is_null($rruff_reference_dt_id) )
                $ima_mineral_dt_id = $rruff_reference_dt_id = null;


            // ----------------------------------------
            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            $theme = array();
            foreach ($theme_array as $t_id => $t) {
                $initial_theme_id = $t_id;
                $theme = $t;
            }

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;


            // ----------------------------------------
            // So the RRUFF Sample plugin exists to perform horrible unspeakable violations to ODR's
            //  regular rendering process...since the plugin only executes in Display mode, the sheer
            //  amount of gaslighting going on shouldn't have too many far-reaching consequences...

            // First step...take all RRUFF References linked to by the IMA Mineral, and move them
            //  so the rendering process thinks that RRUFF Sample links to them instead...this will
            //  "combine" both sets of linked records, and simultaneously keeps the references from
            //  being rendered underneath the mineral
            if ( !is_null($ima_mineral_dt_id) )
                self::moveReferenceRecords($datatype, $datarecord, $ima_mineral_dt_id, $rruff_reference_dt_id);

            // NOTE - this means that the display settings (show/hide, accordion/dropdown/etc) for
            //  the version of RRUFF Reference that is linked to by the IMA Mineral datatype
            //  effectively "don't matter" when this render plugin is active...there will never be
            //  any records to render with those display settings


            // Second step...cut the RRUFF Sample Child datatype out of the picture, so that the
            //  RRUFF Sample effectively considers the Chemistry/Raman/Raman Full/Infrared/Powder
            //  childtypes as its direct descendants, instead of its grandchildren
            self::moveSpectra($datarecord, $datatype, $theme);

            // NOTE - were it not for the Sample Child Comments, the entire Sample Child datatype
            //  wouldn't need to exist...and therefore this step wouldn't be necessary


            // Third step...delete the half dozen or so Comment datatypes/datarecords.  This is done
            //  last because the second step reduces the amount of levels in the array to dig through
            if ( !is_null($comment_dt_ids) )
                self::deleteComments($datarecord, $comment_dt_ids);

            // NOTE - this could technically be done elsewhere with the show/hide feature, but these
            //  comments should never be visible outside Edit mode, so...


            // ----------------------------------------
            // If no rendering context set, then return nothing so ODR's default templating will
            //  do its job
            if ( !isset($rendering_options['context']) )
                return '';

            // Otherwise, output depends on which context the plugin is being executed from
            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODRAdminBundle:Display:display_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord' => $datarecord,
                        'theme_array' => array($initial_theme_id => $theme),

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],
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
     * The primary function of the RRUFF Sample Plugin is to screw with the cached data arrays so
     * that ODR will render child datatypes/datarecords in different places than it typically would.
     *
     * In order to perform this skullduggery, ODR needs to locate the relevant datatypes first...the
     * RenderPluginOptions for this RenderPlugin contain strings that are checked against the names
     * of the descendant/linked datatypes...if the name of a descendant datatype matches, then it's
     * assumed to be the relevant datatype.
     *
     * The "correct" way to handle this would be to modify the RenderPlugin to map to datatypes,
     * like it currently maps to datafields...and then implement another pile of checks and rules
     * that are similar to the ones already in place for how any kind of changes to Datafields
     * relate to RenderPlugins.
     *
     * I have no desire to implement any of that, given the currently existing issues with modifying
     * the underlying database schema.
     *
     * @param array $datatype
     * @param array $options
     * @param int|null $ima_mineral_dt_id
     * @param int|null $rruff_reference_dt_id
     * @param int|null $comment_dt_ids
     */
    private function findDatatypeIds($datatype, $options, &$ima_mineral_dt_id, &$rruff_reference_dt_id, &$comment_dt_ids) {
        if ( !isset($datatype['descendants']) )
            return;

        foreach ($datatype['descendants'] as $dt_id => $tdt) {
//            $parent_datatype_id = $datatype['id'];
            $dt = $tdt['datatype'][$dt_id];

            if ( strpos($dt['dataTypeMeta']['shortName'], $options['ima_datatype']) !== false ) {
                // Assume that the datatype name matching this option's value is the IMA List datatype
                $ima_mineral_dt_id = $dt_id;
            }

            if ( strpos($dt['dataTypeMeta']['shortName'], $options['reference_datatype']) !== false ) {
                // This datatype's name matches the option's value for a "reference" datatype...
                if ( isset($dt['renderPluginInstances']) ) {
                    foreach ($dt['renderPluginInstances'] as $num => $rpi) {
                        // ...ensure it's using the RRUFF Reference renderPlugin before saving it though
                        if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.rruff_references' )
                            $rruff_reference_dt_id = $dt_id;
                    }
                }
            }

            if ( strpos($dt['dataTypeMeta']['shortName'], $options['comment_datatypes']) !== false ) {
                // Assume that the datatypes with a name matching this option's value are comment
                //  datatypes
                if ( is_null($comment_dt_ids) )
                    $comment_dt_ids = array();
                $comment_dt_ids[$dt_id] = 1;
            }

            // If this child datatype has children of its own, continue searching for more comment
            //  datatypes
            if ( isset($dt['descendants']) )
                self::findDatatypeIds($dt, $options, $ima_mineral_dt_id, $rruff_reference_dt_id, $comment_dt_ids);
        }
    }


    /**
     * The primary function of the RRUFF Sample Plugin is to screw with the cached data arrays so
     * that ODR will render child datatypes/datarecords in different places than it typically would.
     *
     * The first part of this requires that all RRUFF References linked to by the IMA Mineral get
     * moved so that ODR believes they're linked to by the RRUFF Sample instead.  This results in
     * the references being rendered together at the bottom of the page, like rruff.info has them.
     *
     * @param array $datatype
     * @param array $datarecord
     * @param int $ima_mineral_dt_id
     * @param int $rruff_reference_dt_id
     */
    private function moveReferenceRecords(&$datatype, &$datarecord, $ima_mineral_dt_id, $rruff_reference_dt_id)
    {
        if ( isset($datarecord['children']) && isset($datarecord['children'][$ima_mineral_dt_id]) ) {
            // The RRUFF Sample can link to at most one IMA Mineral...
            $mineral_dr = null;
            foreach ($datarecord['children'][$ima_mineral_dt_id] as $dr_id => $dr)
                $mineral_dr = $dr;
            if ( is_null($mineral_dr) )
                return;

            $mineral_dr_id = $mineral_dr['id'];

            $mineral_reference_drs = array();
            if ( isset($mineral_dr['children']) && isset($mineral_dr['children'][$rruff_reference_dt_id]) ) {
                // The IMA Mineral links to at least one RRUFF Reference...
                $mineral_reference_drs = $mineral_dr['children'][$rruff_reference_dt_id];

                // Don't render these RRUFF References underneath the IMA Mineral
                unset( $datarecord['children'][$ima_mineral_dt_id][$mineral_dr_id]['children'][$rruff_reference_dt_id] );
                // The rendering should be able to handle an empty 'children' array
            }

            if ( !empty($mineral_reference_drs) ) {
                // ----------------------------------------
                // Most RRUFF Samples don't directly link to a RRUFF Reference, so need to ensure
                //  that the childtype exists
                if ( !isset($datarecord['children'][$rruff_reference_dt_id]) )
                    $datarecord['children'][$rruff_reference_dt_id] = array();

                // Fortunately, since both RRUFF Sample and IMA Mineral link to the same datatype,
                //  the various RenderPlugin map/option settings don't need to be modified
                // Do need to locate the datafield being used for the "reference_id", however
                $reference_id_df = null;
                $rruff_reference_dt = $datatype['descendants'][$rruff_reference_dt_id]['datatype'][$rruff_reference_dt_id];
                foreach ($rruff_reference_dt['renderPluginInstances'] as $num => $rpi) {
                    if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.rruff_references' ) {
                        $reference_id_df = $rpi['renderPluginMap']['Reference ID']['id'];
                        break;
                    }
                }

                // The only detectable issue here is that the ThemeElement and DataTree options for
                //  the IMA Mineral -> RRUFF Reference link are completely ignored...the renderer
                //  only sees and uses information from the RRUFF Sample -> RRUFF Reference link

                // Copy all the References from the IMA Mineral into this array
                foreach ($mineral_reference_drs as $dr_id => $dr)
                    $datarecord['children'][$rruff_reference_dt_id][$dr_id] = $dr;

                // on RRUFF, the two types of references are sorted by reference id after merging
                uasort($datarecord['children'][$rruff_reference_dt_id], function($a, $b) use ($reference_id_df) {
                    $reference_id_a = $a['dataRecordFields'][$reference_id_df]['integerValue'][0]['value'];
                    $reference_id_b = $b['dataRecordFields'][$reference_id_df]['integerValue'][0]['value'];

                    // Able to use the "spaceship" operator since the reference ids are always going
                    //  to be integer values
                    return $reference_id_a <=> $reference_id_b;
                });
            }
        }
    }


    /**
     * The primary function of the RRUFF Sample Plugin is to screw with the cached data arrays so
     * that ODR will render child datatypes/datarecords in different places than it typically would.
     *
     * The second part of this requires that every single one of the various spectra childtypes
     * (Chemistry/Raman/Raman_full/Infrared/Powder) across all RRUFF Sample Child records need to
     * be moved, so that the renderer believes they are direct descendants of the RRUFF Sample.  The
     * RRUFF Sample Child records need to disappear completely.
     *
     * Were it not for the RRUFF Sample Child Comments, the RRUFF Sample Child datatype wouldn't
     * need to exist in the first place...and the various spectra datatypes could go in the "correct"
     * places to begin with.
     *
     * @param array $datarecord
     * @param array $datatype
     * @param array $theme
     */
    private function moveSpectra(&$datarecord, &$datatype, &$theme)
    {
        // TODO - need the API uploader to work before this can be implemented...CSVImport can only do top-levels or their immediate descendants
    }


    /**
     * The primary function of the RRUFF Sample Plugin is to screw with the cached data arrays so
     * that ODR will render child datatypes/datarecords in different places than it typically would.
     *
     * The third part of this requires that every single one of the Comment childtypes are deleted
     * out of the cached arrays so that the renderer will never display them...it's considerably
     * easier to delete them out of the cached arrays than to try to ensure that all...six?...of them
     * never show up in Display mode by using ODR's existing show/hide functionality...
     *
     * @param array $datarecord
     * @param array $comment_dt_ids
     */
    private function deleteComments(&$datarecord, $comment_dt_ids)
    {
        foreach ($datarecord['children'] as $child_dt_id => $child_dr_list) {
            if ( isset($comment_dt_ids[$child_dt_id]) ) {
                // This should delete the Sample Comments...
                $datarecord['children'][$child_dt_id] = array();
            }
            else {
                // ...but need to use recursion to find the other Comment datatypes
                $datarecord['children'][$child_dt_id] = self::deleteComments_worker($child_dr_list, $comment_dt_ids);
            }
        }
    }


    /**
     * This does the recursive work of deleting Comment childtypes.
     *
     * @param array $dr_list
     * @param array $comment_dt_ids
     * @return array
     */
    private function deleteComments_worker($dr_list, $comment_dt_ids)
    {
        foreach ($dr_list as $dr_id => $dr) {
            if ( empty($dr['children']) )
                continue;

            foreach ($dr['children'] as $child_dt_id => $child_dr_list) {
                if ( isset($comment_dt_ids[$child_dt_id]) ) {
                    $dr_list[$dr_id]['children'][$child_dt_id] = array();
                }
                else {
                    $dr_list[$dr_id]['children'][$child_dt_id] = self::deleteComments_worker($child_dr_list, $comment_dt_ids);
                }
            }
        }

        return $dr_list;
    }
}
