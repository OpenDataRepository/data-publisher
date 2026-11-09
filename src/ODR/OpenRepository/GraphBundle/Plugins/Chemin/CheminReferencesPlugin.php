<?php

/**
 * Open Data Repository Data Publisher
 * Chemin References Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Chemin References plugin renders data describing an academic reference in a single line,
 * instead of scattered across a number of datafields.  This is separated from the default
 * ReferencesPlugin because Chemin requires an additional file datafield for "Supporting Files".
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Chemin;

// Events
use ODR\AdminBundle\Component\Event\PluginOptionsChangedEvent;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ODRReferencePlugins;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
// Symfony
use Doctrine\ORM\EntityManager;

class CheminReferencesPlugin extends ODRReferencePlugins implements DatatypePluginInterface, TableResultsOverrideInterface
{

    /**
     * CheminReferencesPlugin constructor.
     *
     * @param EntityManager $em
     * @param CacheService $cache_service
     * @param \Twig\Environment $templating
     */
    public function __construct(
        private readonly EntityManager $em,
        private readonly CacheService $cache_service,
        private readonly \Twig\Environment $templating
    ) {

    }


    /**
     * {@inheritDoc}
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            switch ($context) {
                case 'text':       // plain string
                case 'html':       // formatted string
                case 'text|html':  // both of the above
                case 'display':
                case 'edit':       // Preview div for reference
                case 'fake_edit':  // DOI lookups
                    return true;
            }
        }

        return false;
    }


    /**
     * {@inheritDoc}
     */
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = [], $datatype_permissions = [], $datafield_permissions = [], $token_list = [])
    {
        try {
            // ----------------------------------------
            // If no rendering context set, then return nothing so ODR's default templating will
            //  do its job
            if ( !isset($rendering_options['context']) )
                return '';


            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Grab various properties from the render plugin array
            $render_plugin_fields = $render_plugin_instance['renderPluginMap'];
            $render_plugin_options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = [];
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;
            $datarecord_id = $datarecord['id'];
            $grandparent_datarecord_id = $datarecord['grandparent']['id'];

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            foreach ($theme_array as $t_id => $t)
                $initial_theme_id = $t_id;


            // ----------------------------------------
            // Output depends on which context the plugin is being executed from
            $context = $rendering_options['context'];
            $output = '';
            if ( $context === 'display' || $context === 'text' || $context === 'html' || $context === 'text|html' ) {
                // The parts of the reference that aren't links might be cached
                $format = 'rruff';

                $citations = null;
                if ( isset($datarecord['cached_reference'][$format]) )
                    $citations = $datarecord['cached_reference'][$format];

                if ( is_null($citations) ) {
                    // Doesn't exist...render it via twig
                    $citations = parent::renderCitations($this->templating, $is_datatype_admin, $format, $datatype, $datarecord, $render_plugin_fields);
                    if ( is_null($citations) ) {
                        // If the previous function returned null, then the plugin can't execute
                        // Return the empty string so ODR's default rendering takes over
                        return '';
                    }

                    // Store the citations back in the datarecord
                    if ( !isset($datarecord['cached_reference']) )
                        $datarecord['cached_reference'] = [];
                    if ( !isset($datarecord['cached_reference'][$format]) )
                        $datarecord['cached_reference'][$format] = [];

                    $datarecord['cached_reference'][$format] = [
                        'text' => $citations['text'],
                        'html' => $citations['html'],
                    ];

                    // Store the datarecord back in the cache
                    $formatted_datarecord_data = $this->cache_service->get('cached_datarecord_'.$grandparent_datarecord_id);
                    $formatted_datarecord_data[$datarecord_id] = $datarecord;
                    $this->cache_service->set('cached_datarecord_'.$grandparent_datarecord_id, $formatted_datarecord_data);
                }

                // The citations array should not be null now...
                if ( $context === 'text' || $context === 'html' ) {
                    // In either of these two situations, just return the value directly
                    return $citations[$context];
                }
                else if ( $context === 'text|html' ) {
                    // Could be useful in the future
                    return $citations;
                }
                else {
                    // ...otherwise, this is for Display mode
                    // Don't need to recreate the citation string
                    $citation = $citations['html'];

                    // Going to render the reference differently if it's top-level...
                    $is_top_level = $rendering_options['is_top_level'];
                    $output = $this->templating->render(
                        '@ODROpenRepositoryGraph/Chemin/CheminReferences/cheminreferences_display.html.twig',
                        [
                            'is_top_level' => $is_top_level,
                            'original_context' => $context,
                            'citation' => $citation,
                        ]
                    );
                }
            }
            else if ( $context === 'fake_edit') {
                // Retrieve mapping between datafields and render plugin fields
                $plugin_fields = [];
                foreach ($render_plugin_fields as $rpf_name => $rpf_df) {
                    // Need to find the real datafield entry in the primary datatype array
                    $rpf_df_id = $rpf_df['id'];

                    $df = null;
                    if ( isset($datatype['dataFields'][$rpf_df_id]) )
                        $df = $datatype['dataFields'][$rpf_df_id];

                    // Autogenerated fields will continue to work even if the user can't see them,
                    //  but probably should still complain about plugin mapping errors to datatype admins
                    if ( is_null($df) && $is_datatype_admin)
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                    // Need to tweak display parameters for several of the fields...
                    $plugin_fields[$rpf_df_id] = $rpf_df;
                    $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;
                }

                // Need to generate a mapping between plugin field names and HTML element ids
                $doi_fields = self::getDOIFields($datatype, $datarecord, $plugin_fields);

                $output = $this->templating->render(
                    '@ODROpenRepositoryGraph/Chemin/CheminReferences/cheminreferences_fakeedit_fieldarea.html.twig',
                    [
                        'datatype_array' => [$initial_datatype_id => $datatype],
                        'datarecord_array' => [$datarecord['id'] => $datarecord],
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
                        'doi_fields' => $doi_fields,
                    ]
                );
            }
            else if ( $context === 'edit' ) {
                // Most of the fields need slightly modified saving javascript...
                $reference_field_list = [];
                foreach ($render_plugin_fields as $rpf_name => $rpf) {
                    switch ($rpf_name) {
                        case 'Authors':
                        case 'Article Title':
                        case 'Journal':
                        case 'Year':
                        case 'Month':
                        case 'Volume':
                        case 'Issue':
                        case 'Book Title':
                        case 'Publisher':
                        case 'Publisher Location':
                        case 'Pages':
                        case 'File':
                        case 'Supporting Files':
                        case 'URL':
                            $reference_field_list[ $rpf['id'] ] = $rpf_name;
                            break;
                    }
                }

                // Also need a list of which datafields are using the chemistry plugin
                // TODO - figure out some way to make plugins play nicer with each other?
                $chemistry_plugin_fields = [];
                foreach ($datatype['dataFields'] as $df_id => $df) {
                    foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.chemistry' ) {
                            $subscript_delimiter = $rpi['renderPluginOptionsMap']['subscript_delimiter'];
                            $superscript_delimiter = $rpi['renderPluginOptionsMap']['superscript_delimiter'];

                            $chemistry_plugin_fields[$df_id] = [
                                'subscript_delimiter' => $subscript_delimiter,
                                'superscript_delimiter' => $superscript_delimiter,
                            ];
                        }
                    }
                }

                // Need to be able to pass this option along if doing edit mode
                $edit_shows_all_fields = $rendering_options['edit_shows_all_fields'];
                $edit_behavior = $rendering_options['edit_behavior'];

                $output = $this->templating->render(
                    '@ODROpenRepositoryGraph/Chemin/CheminReferences/cheminreferences_edit_fieldarea.html.twig',
                    [
                        'datatype_array' => [$initial_datatype_id => $datatype],
                        'datarecord_array' => [$datarecord['id'] => $datarecord],
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,
                        'edit_shows_all_fields' => $edit_shows_all_fields,
                        'edit_behavior' => $edit_behavior,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,

                        'reference_field_list' => $reference_field_list,
                        'chemistry_plugin_fields' => $chemistry_plugin_fields,
                    ]
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
     * The DOI info loaded when in FakeEdit mode needs to know where on the page the data goes...
     *
     * @param array $dt_array
     * @param array $dr_array
     * @param array $plugin_fields
     * @return array
     */
    private function getDOIFields($dt_array, $dr_array, $plugin_fields)
    {
        $doi_fields = [];
        $dr_id = $dr_array['id'];

        foreach ($plugin_fields as $df_id => $rpf_df) {
            $typeclass = $dt_array['dataFields'][$df_id]['dataFieldMeta']['fieldType']['typeClass'];
            $rpf_df_name = $rpf_df['rpf_name'];
            switch ($rpf_df_name) {
                case 'Authors':
                case 'Article Title':
                case 'Book Title':
                case 'Journal':
                case 'Publisher':
//                case 'Publisher Location':
                case 'Year':
//                case 'Month':
                case 'Volume':
                case 'Issue':
                case 'Pages':
                case 'URL':
                    $doi_fields[$rpf_df_name] = $typeclass.'Form_'.$dr_id.'_'.$df_id;
                    break;
            }
        }

        return $doi_fields;
    }


    /**
     * {@inheritDoc}
     */
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // Don't do anything if fields aren't mapped
        $values = [];
        if ( !isset($render_plugin_instance['renderPluginMap']) )
            return [];

        $substitute_article_title = false;
        if ( isset($render_plugin_instance['renderPluginOptionsMap']['substitute_article_title'])
            && $render_plugin_instance['renderPluginOptionsMap']['substitute_article_title'] === 'yes'
        ) {
            $substitute_article_title = true;
        }
        $substitute_journal = false;
        if ( isset($render_plugin_instance['renderPluginOptionsMap']['substitute_journal'])
            && $render_plugin_instance['renderPluginOptionsMap']['substitute_journal'] === 'yes'
        ) {
            $substitute_journal = true;
        }

        // Since this is a datatype plugin, need to dig through the renderPluginInstance array
        $relevant_rpf_names = ['Article Title', 'Book Title', 'Journal', 'Publisher'];

        $df_mapping = [];
        $value_mapping = [];
        foreach ($relevant_rpf_names as $rpf_name) {
            $df_id = $render_plugin_instance['renderPluginMap'][$rpf_name]['id'];
            $df_mapping[$rpf_name] = $df_id;

            if ( isset($datarecord['dataRecordFields'][$df_id]) ) {
                $drf = $datarecord['dataRecordFields'][$df_id];

                // Brute-force typeclass since there are only four possibilities
                if ( isset($drf['longText'][0]['value']) )
                    $value_mapping[$df_id] = $drf['longText'][0]['value'];
                else if ( isset($drf['longVarchar'][0]['value']) )
                    $value_mapping[$df_id] = $drf['longVarchar'][0]['value'];
                else if ( isset($drf['mediumVarchar'][0]['value']) )
                    $value_mapping[$df_id] = $drf['mediumVarchar'][0]['value'];
                else if ( isset($drf['shortVarchar'][0]['value']) )
                    $value_mapping[$df_id] = $drf['shortVarchar'][0]['value'];
            }
        }


        // Want to put always italics around the Book Title
        $book_title_df_id = $df_mapping['Book Title'];
        if ( isset($value_mapping[$book_title_df_id]) && $value_mapping[$book_title_df_id] !== '' )
            $values[$book_title_df_id] = '<i>'.$value_mapping[$book_title_df_id].'</i>';

        // Only substitute a missing article title if configured to do so...
        if ( $substitute_article_title ) {
            $article_title_df_id = $df_mapping['Article Title'];
            if ( !isset($value_mapping[$article_title_df_id]) || $value_mapping[$article_title_df_id] === '' ) {
                if ( isset($values[$book_title_df_id]) ) {
                    // Replace the missing article title with the book title
                    $values[$article_title_df_id] = $values[$book_title_df_id];
                }
            }
        }

        // Only substitute a missing journal if configured to do so...
        if ( $substitute_journal ) {
            $journal_df_id = $df_mapping['Journal'];
            $publisher_df_id = $df_mapping['Publisher'];
            if ( !isset($value_mapping[$journal_df_id]) || $value_mapping[$journal_df_id] === '' ) {
                if ( isset($value_mapping[$publisher_df_id]) ) {
                    // Replace the missing article title with the book title
                    $values[$journal_df_id] = $value_mapping[$publisher_df_id];
                }
            }
        }


        // Return the value the table should display
        return $values;
    }


    /**
     * Called when a user changes RenderPluginOptions or RenderPluginMaps entries for this plugin.
     *
     * @param PluginOptionsChangedEvent $event
     */
    public function onPluginOptionsChanged(PluginOptionsChangedEvent $event)
    {
        foreach ($event->getChangedOptions() as $rpo_name) {
            if ( $rpo_name === 'substitute_article_title' || $rpo_name === 'substitute_journal' ) {
                // If either of these options got changed, then need to wipe the table entries for
                //  all records of this datatype
                $datatype_id = $event->getRenderPluginInstance()->getDataType()->getId();

                $query =
                   'SELECT dr.grandparent_id AS gdr_id
                    FROM odr_data_record dr
                    WHERE dr.data_type_id = '.$datatype_id.' AND dr.deletedAt IS NULL';
                $conn = $this->em->getConnection();
                $results = $conn->executeQuery($query);

                foreach ($results->iterateAssociative() as $result) {
                    $gdr_id = $result['gdr_id'];
                    $this->cache_service->delete('cached_table_data_'.$gdr_id);
                }
            }
        }
    }
}
