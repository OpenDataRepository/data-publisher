<?php

/**
 * Open Data Repository Data Publisher
 * Linked Descendant Merger Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * ODR intentionally prevents a database from directly linking to some other database more than once,
 * due to causing complications with the rendering process, but it will allow situations where...
 * A links to B, B links to C, and A also links to C...aka {A->B->C, A->C}.  When rendering A in
 * this situation, then C will effectively be rendered twice on the page, albeit with different
 * records and usually in different areas of the page.
 *
 * At the moment, this situation only arises when C is some sort of a reference datatype that's been
 * linked to multiple times...in which case it's easier for users to understand when these multiple
 * instances are "merged" together into a single ThemeElement.
 *
 *
 * Fortunately, the rendering system can be easily "deceived" by moving the contents of the cached
 * datarecord array around, and nothing actually breaks in Display mode by doing this.  This does
 * not work for Edit mode, however, because combining the records into a single themeElement means
 * there's no way to determine which of the links the user wants to add a record too...do they mean
 * the B->C link, or the A->C link?
 *
 * This plugin is still something of a hack though, and the average user probably won't understand
 * what's going on when it refuses to work.
 *
 *
 * NOTE: this plugin's actions mean that the display settings (show/hide, accordion/dropdown/etc) for
 * themeElements which aren't the selected destination "don't matter" outside of Edit mode. There's
 * no good way to indicate on the layout design pages what this plugin is going to do, unfortunately.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginOptionsDef;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\OpenRepository\GraphBundle\Plugins\ArrayPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ArrayPluginReturn;
use ODR\OpenRepository\GraphBundle\Plugins\PluginSettingsDialogOverrideInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class LinkedDescendantMergerPlugin implements ArrayPluginInterface, PluginSettingsDialogOverrideInterface
{

    /**
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var SortService
     */
    private $sort_service;

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
     * @param DatabaseInfoService $database_info_service
     * @param SortService $sort_service
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        DatabaseInfoService $database_info_service,
        SortService $sort_service,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->database_info_service = $database_info_service;
        $this->sort_service = $sort_service;
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
            if ( $rendering_options['context'] === 'display' )
                return true;

            // NOTE: can't really run this in "Edit" mode, because moving stuff around destroys
            //  the ancestor info of the linked descendants
        }

        return false;
    }


    /**
     * Locates the configuration for the plugin if it exists, and converts it into a more useful
     * array format for actual use.  There could technically be multiple configs, if the top-level
     * database has multiple candidates for them.
     *
     * The returned array looks like this:
     * array(
     *     array(
     *         'src' => array("<prefix_1>","<prefix_2>",...),
     *         'dest' => "<prefix_n>"
     *     ),
     *     ...
     * )
     *
     * @param DataType|array $datatype
     * @return array
     */
    private function getCurrentPluginConfig($datatype)
    {
        $config = array();

        // This function could be passed either a cached array or a datatype entity, so ensure the
        //  rest of the function has a cached array to work off of...
        $dt = $datatype;
        if ( $datatype instanceof DataType ) {
            $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId());    // don't need links
            $dt = $dt_array[ $datatype->getId() ];
        }

        if ( !empty($dt['renderPluginInstances']) ) {
            // The datatype could haev more than one renderPluginInstance
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.linked_descendant_merger' ) {
                    $plugin_config = trim( $rpi['renderPluginOptionsMap']['plugin_config'] );

                    // The config is stored as a string...there could be multiple linked descendants
                    //  in the confg, so they're separated first by '|'
                    $configs = explode('|', $plugin_config);
                    foreach ($configs as $num => $value) {
                        // The src/dest are separated by ':'
                        $tmp = explode(':', $value);

                        // The src prefixes are separated by ','
                        $config_tmp = array(
                            'src' => explode(',', $tmp[0]),
                            'dest' => $tmp[1]
                        );

                        // In order for the plugin to work, it needs...
                        $save_config = true;
                        // ...to have both src and dest values
                        if ( $tmp[0] === '' || $tmp[1] === '' )
                            $save_config = false;
                        // ...the dest value has to also be a src value
                        if ( !in_array($config_tmp['dest'], $config_tmp['src']) )
                            $save_config = false;
                        // ...there has to be more than one src value
                        if ( count($config_tmp['src']) < 2 )
                            $save_config = false;

                        // If no problems, then save the config
                        if ( $save_config )
                            $config[] = $config_tmp;
                    }
                }
            }
        }

        return $config;
    }


    /**
     * The RenderPlugin settings dialog needs to determine which datatypes are eligible for this
     * merging, and uniquely identify them for both the user and later execution of the plugin.
     *
     * This is done by converting a datatype hierarchy into a "prefix"...a string of datatype ids
     * separated by underscores.  Due to ODR preventing users from linking datatype A to datatype B
     * more than once, each prefix ends up being unique.  The plugin is only interested in situations
     * where a datatype is linked to more than once though...e.g. datatype C in {A->B->C, A->C}.
     * Or, datatype D in {A->B->D, A->C->D}, or datatypes D/E in {A->B->D, A->B->E, A->C->D, A->D->E}
     *
     * The returned array looks like this:
     * array(
     *     '<dt_id_1>' => array(
     *         'src' => array(
     *             "<prefix_1>" => "<prefix_1_label>",
     *             "<prefix_2>" => "<prefix_2_label>",
     *             ...
     *         ),
     *         'dest' => array(
     *             "<prefix_a>" => 1,
     *             "<prefix_b>" => 1,
     *             ...
     *         )
     *     ),
     *     '<dt_id_2>' => array(...),
     *     ...
     * )
     *
     * Of note is that the 'dest' subarray typically won't contain all the prefixes that are in
     * the 'src' subarray. @see self::canBeDestination()
     *
     * @param DataType $datatype
     * @return array
     *
     */
    private function getAvailableConfigurations($datatype)
    {
        // For whatever reason, this is easier for my brain to comprehend when it's using a stacked
        //  datatype array.  Dang long covid.
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId());    // do want links
        $stacked_dt = $this->database_info_service->stackDatatypeArray($dt_array, $datatype->getId());

        // Going to recursively dig through the stacked datatype array, building up every possible
        //  prefix along the way
        $counts = array();
        $prefixes = array();

        self::buildPrefixes($stacked_dt, '', $prefixes, $counts);

        // There could be multiple descendants that are linked to more than once...
        // e.g, if the structure looks like {A->B->D, A->B->E, A->C->D, A->D->E}, then both D and E
        //  are eligible for this plugin to work on
        $dt_groups = array();
        foreach ($counts as $dt_id => $count) {
            // If the datatype is eligible, then it'll have been counted more than once
            if ( $count > 1 ) {
                $dt_groups[$dt_id] = array(
                    'src' => array(),
                    'dest' => array(),
                );

                // Wrapping each datatype id in underscores during buildPrefixes() was done so that
                //  this part can just use strpos() to find all prefixes involving a datatype
                foreach ($prefixes as $prefix => $label) {
                    if ( strpos($prefix, '_'.$dt_id.'_') !== false ) {
                        // ...do need to undo the wrapping though before saving it for real
                        $trimmed_prefix = substr($prefix, 1, -1);
                        $trimmed_prefix = str_replace('__', '_', $trimmed_prefix);

                        $dt_groups[$dt_id]['src'][$trimmed_prefix] = $label;

                        // Determine whether this prefix is allowed to indicate a destination for
                        //  the records to move to
                        if ( self::canBeDestination($dt_array, $trimmed_prefix) )
                            $dt_groups[$dt_id]['dest'][$trimmed_prefix] = 1;
                    }
                }
            }
        }

        return $dt_groups;
    }


    /**
     * Building prefixes and finding labels for said prefixes is easier if performed recursively.
     *
     * @param array $datatype_array
     * @param string $old_prefix
     * @param array $prefixes
     * @param array $counts
     */
    private function buildPrefixes($dt, $old_prefix, &$prefixes, &$counts)
    {
        // Keep track of how many times this datatype has been seen in the stacked datatype array
        $dt_id = strval($dt['id']);
        if ( !isset($counts[$dt_id]) )
            $counts[$dt_id] = 0;
        $counts[$dt_id]++;

        // The main goal of this function is to build up a prefix that contains the "path" to reach
        //  the descendant datatype, condensed into a string so it can be exploded later.
        // The prefix returned by this function has every datatype id bracketed by underscores, so
        //  self::getAvailableConfigurations() can just use strpos() to determine which prefix
        //  includes any desired datatype.
        $new_prefix = '';
        if ( $old_prefix === '' ) {
            $new_prefix = '_'.$dt_id.'_';
            $prefixes[$new_prefix] = $dt['dataTypeMeta']['shortName'];
        }
        else {
            $new_prefix = $old_prefix.'_'.$dt_id.'_';
            $prefixes[$new_prefix] = $prefixes[$old_prefix].' >> '.$dt['dataTypeMeta']['shortName'];
        }

        // If this datatype has descendants of its own...
        if ( isset($dt['descendants']) ) {
            foreach ($dt['descendants'] as $descendant_dt_id => $tdt) {
                // ...then count/build up the prefixes for the descendant too
                $descendant_dt = $tdt['datatype'][$descendant_dt_id];
                self::buildPrefixes($descendant_dt, $new_prefix, $prefixes, $counts);
            }
        }
    }


    /**
     * Determines whether the given prefix is allowed to be a destination for moving records to.
     *
     * It's allowed unless an intermediate datatype is "multiple_allowed"...in which case, there
     * could be multiple intermediate records...and there's no way to determine which one to move
     * the bottom-level records into.
     *
     * @param array $dt_array
     * @param string $prefix
     *
     * @return bool
     */
    private function canBeDestination($dt_array, $prefix)
    {
        if ( strpos($prefix, '_') !== strrpos($prefix, '_') ) {
            // This prefix has at least one intermediate datatype

            // NOTE - for the time being, I'm restricting this to only allow destinations immediately
            //  below the top-level database...e.g. given {A->B->C, A->C}, then the latter is the
            //  only valid choice.  This restriction means I don't have to figure out how to handle
            //  situations where the datarecord for B doesn't exist.
            return false;

            // This technically means that databases with structures like {A->B->D, A->C->D} or
            //  {A->B->D, A->B->E, A->C->D, A->D->E} don't have any valid destinations...but I can't
            //  think of a reason for either of those structures to want to use this plugin when
            //  they don't have an A->D or A->E link as well.


            // The remainder of this function is left here, however...even if the above restriction
            //  was removed, then the plugin still couldn't use any destination where one of the
            //  intermediate datatypes allows multiple datarecords.

            // Don't want the top-level or the bottom-level datatypes
            $dt_ids = explode('_', $prefix);
            for ($i = 1; $i < count($dt_ids)-1; $i++) {
                // Need to locate the themeDatatype entry, basically
                $ancestor_dt_id = $dt_ids[$i-1];
                $descendant_dt_id = $dt_ids[$i];

                // If any of the intermediate datatypes allow multiple records, then this prefix
                //  is unsuitable for use as a destination
                $ancestor_dt = $dt_array[$ancestor_dt_id];
                if ( $ancestor_dt['descendants'][$descendant_dt_id]['multiple_allowed'] === 1 )
                    return false;
            }
        }

        // Otherwise, there's no problem...so this is a valid destination
        return true;
    }


    /**
     * Executes the Linked Descendant Merger plugin on the provided datarecords
     *
     * @param array $datarecord_array
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     *
     * @return ArrayPluginReturn|null
     * @throws \Exception
     */
    public function execute($datarecord_array, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array())
    {
        try {
            // ----------------------------------------
            // If no rendering context set, then return nothing so ODR's default templating will
            //  do its job
            if ( !isset($rendering_options['context']) )
                return null;


            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // This render plugin has no fields to deal with


            // ----------------------------------------
            // So this plugin exists to effectively gaslight ODR's regular rendering process.

            // If there's a database structured like {A->B->C, A->C}, then the records from the
            //  B->C link can get "merged" with the records from the A->C link, and they can all
            //  get displayed in the A->C section on the page.  Other database structures are also
            //  possible.

            // The first step is to load the config the user set from the RenderPlugin settings dialog
            $plugin_config = self::getCurrentPluginConfig($datatype);

            // If the config is invalid, don't execute the plugin
            if ( empty($plugin_config) )
                return null;


            // ----------------------------------------
            // Because this plugin could be getting called on a descendant datatype, $datarecord_array
            //  could have multiple datarecords...
            $modified_datarecord_array = array();
            foreach ($datarecord_array as $datarecord_id => $datarecord) {
                // Need to have a copy of the original children datarecords in the top-level datarecord...
                $datarecord['original_children'] = $datarecord['children'];

                // For each datarecord belonging to this datatype...
                $dr_list = array();
                foreach ($plugin_config as $num => $dt_group) {
                    // The plugin could've been configured to run on multiple linked descendant
                    //  datatypes at the same time, so the lists need to be separated according to
                    //  the relevant config
                    $dr_list[$num] = array();

                    foreach ($dt_group['src'] as $prefix_num => $prefix) {
                        // ...pull out this datarecord's descendants that are listed by the config
                        $tmp_dr_list = self::getDescendantDatarecords($datarecord, $prefix);

                        // Add each descendant to another list of records...
                        foreach ($tmp_dr_list as $dr_id => $dr)
                            $dr_list[$num][$dr_id] = $dr;
                    }
                }

                // Now that we have lists of all datarecords that need to get moved, splice each
                //  group of records into their desired destinations
                foreach ($plugin_config as $num => $dt_group) {
                    if ( !empty($dr_list[$num]) )
                        self::moveDatarecords($datarecord, $dr_list[$num], $dt_group['dest']);
                }

                // Store the modified datarecord into a different array to be returned later
                $modified_datarecord_array[$datarecord_id] = $datarecord;
            }


            // ----------------------------------------
            // Otherwise, need to provide the modified datarecord array info to the templating so
            //  that it ends up displaying correctly
            $modifications = new ArrayPluginReturn(
                $datatype,
                $modified_datarecord_array,
                $theme_array
            );

            return $modifications;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Recursively extracts all datarecords pointed to by $prefix, deleting them out of $datarecord
     * so they can get re-inserted back into $datarecord later on at a (usually) different location.
     *
     * @param array $datarecord
     * @param string $prefix
     *
     * @return array
     */
    private function getDescendantDatarecords(&$datarecord, $prefix)
    {
        // Cut out this datarecord's datatype_id from the prefix...the recursion will never go all
        //  the way down to the last datatype_id in the prefix, so don't need to check whether the
        //  prefix has an underscore or not
        $new_prefix = substr($prefix, strpos($prefix, '_')+1);

        // If the new prefix doesn't have an underscore, then the descendant is bottom-level...
        if ( strpos($new_prefix, '_') === false ) {
            // ...which means recursion is no longer necessary
            if ( isset($datarecord['children'][$new_prefix]) ) {
                // This record has descendants of this datatype...
                $tmp = $datarecord['children'][$new_prefix];

                // ...they need to still be available near their original location, in case there
                //  are render plugins that rely on their existence...
                if ( !isset($datarecord['original_children']) )
                    $datarecord['original_children'] = array();
                $datarecord['original_children'][$new_prefix] = $datarecord['children'][$new_prefix];

                // ...but don't want twig to attempt to render them
                unset( $datarecord['children'][$new_prefix] );

                return $tmp;
            }
            else {
                // Otherwise, no descendants of this datatype exist...no records to return
                return array();
            }
        }
        else {
            // This descendant isn't bottom-level yet, so need more recursion...if it exists, that is
            $descendant_dt_id = substr($new_prefix, 0, strpos($new_prefix, '_'));
            if ( isset($datarecord['children'][$descendant_dt_id]) ) {
                // There could be multiple descendant datarecords, so need to loop over all of them
                $dr_list = array();

                foreach ($datarecord['children'][$descendant_dt_id] as $descendant_dr_id => $descendant_dr) {
                    // Get any records from this descendant that match the prefix...
                    $tmp = self::getDescendantDatarecords($descendant_dr, $new_prefix);
                    // ...and merge whatever is returned into a list
                    foreach ($tmp as $dr_id => $dr)
                        $dr_list[$dr_id] = $dr;

                    // Also, replace the descendant record's entry in this datarecord, so the unset()
                    //  in the first half of this function will propogate upwards
                    $datarecord['children'][$descendant_dt_id][$descendant_dr_id] = $descendant_dr;
                }

                return $dr_list;
            }
            else {
                // Otherwise, no descendants of this datatype exist...no records to return
                return array();
            }
        }
    }


    /**
     * Uses $dest_prefix to determine where in $datarecord to insert the list of datarecords
     * from $dr_list.
     *
     * @param array $datarecord
     * @param array $dr_list
     * @param string $dest_prefix
     */
    private function moveDatarecords(&$datarecord, $dr_list, $dest_prefix)
    {
        // Cut out this datarecord's datatype_id from the prefix...the recursion will never go all
        //  the way down to the last datatype_id in the prefix, so don't need to check whether the
        //  prefix has an underscore or not
        $new_prefix = substr($dest_prefix, strpos($dest_prefix, '_')+1);

        // If the new prefix doesn't have an underscore, then the descendant is bottom-level...
        if ( strpos($new_prefix, '_') === false ) {
            // ...which means recursion is no longer necessary.  Ensure that the datarecord array
            //  has the correct entries to store these records from $dr_list...
            if ( !isset($datarecord['children']) )
                $datarecord['children'] = array();
            if ( !isset($datarecord['children'][$new_prefix]) )
                $datarecord['children'][$new_prefix] = array();

            // ...need to ensure the list of datarecords is sorted prior to storing it
            $subset_str = implode(',', array_keys($dr_list));
            $sorted_dr_list = $this->sort_service->getSortedDatarecordList($new_prefix, $subset_str);

            foreach ($sorted_dr_list as $dr_id => $sort_value)
                $datarecord['children'][$new_prefix][$dr_id] = $dr_list[$dr_id];
        }
        else {
            // This descendant isn't bottom-level yet, so need more recursion
            $descendant_dt_id = substr($new_prefix, 0, strpos($new_prefix, '_'));

            // TODO - how to handle the situation where one of the intermediates doesn't exist?
            // TODO - currently, preventing any prefix with an intermediate from being a destination in self::canBeDestination()

            // Due to guards elsewhere, there will only be one datarecord available here
            foreach ($datarecord['children'][$descendant_dt_id] as $descendant_dr_id => $descendant_dr) {
                self::moveDatarecords($descendant_dr, $dr_list, $new_prefix);

                // Also, replace the descendant's entry in this datarecord, so the new array entries
                //  propogate upwards
                $datarecord['children'][$descendant_dt_id][$descendant_dr_id] = $descendant_dr;
            }
        }
    }


    /**
     * Returns an array of HTML strings for each RenderPluginOption in this RenderPlugin that needs
     * to use custom HTML in the RenderPlugin settings dialog.
     *
     * @param ODRUser $user                                     The user opening the dialog
     * @param boolean $is_datatype_admin                        Whether the user is able to make changes to this RenderPlugin's config
     * @param RenderPlugin $render_plugin                       The RenderPlugin in question
     * @param DataType $datatype                                The relevant datatype if this is a Datatype Plugin, otherwise the Datatype of the given Datafield
     * @param DataFields|null $datafield                        Will be null unless this is a Datafield Plugin
     * @param RenderPluginInstance|null $render_plugin_instance Will be null if the RenderPlugin isn't in use
     * @return string[]
     */
    public function getRenderPluginOptionsOverride($user, $is_datatype_admin, $render_plugin, $datatype, $datafield = null, $render_plugin_instance = null)
    {
        $custom_rpo_html = array();
        foreach ($render_plugin->getRenderPluginOptionsDef() as $rpo) {
            /** @var RenderPluginOptionsDef $rpo */
            if ( $rpo->getUsesCustomRender() ) {
                // This is the "plugin_config" option...figure out which links exist first...
                $available_configurations = self::getAvailableConfigurations($datatype);
                // ...and also need to figure out what the current config is if possible...
                $current_plugin_config = self::getCurrentPluginConfig($datatype);

                // ...which allows a template to be rendered
                $custom_rpo_html[$rpo->getId()] = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:LinkedMerger/plugin_settings_dialog_field_list_override.html.twig',
                    array(
                        'rpo_id' => $rpo->getId(),

                        'available_config' => $available_configurations,
                        'current_config' => $current_plugin_config,
                    )
                );
            }
        }

        // As a side note, the plugin settings dialog does no logic to determine which options should
        //  have custom rendering...it's solely determined by the contents of the array returned by
        //  this function.  As such, there's no validation whatsoever
        return $custom_rpo_html;
    }
}
