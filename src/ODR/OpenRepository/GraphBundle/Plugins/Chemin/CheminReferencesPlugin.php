<?php

/**
 * Open Data Repository Data Publisher
 * Chemin References Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Chemin References plugin renders data describing an academic reference in a single line,
 * instead of scattered across a number of datafields.  Separate from the default Render Plugin
 * because they require an additional file datafield for "Supporting Files".
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Chemin;

// Events
use ODR\AdminBundle\Component\Event\PluginOptionsChangedEvent;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class CheminReferencesPlugin implements DatatypePluginInterface, TableResultsOverrideInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var EngineInterface
     */
    private $templating;


    /**
     * CheminReferencesPlugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param EngineInterface $templating
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        EngineInterface $templating
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
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
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // This render plugin needs to be executed in the display or edit (for previews) modes
            if ( $context === 'display' || $context === 'edit' )
                return true;

            // Also need a "text" mode
            if ( $context === 'text' || $context === 'html' )
                return true;
        }

        return false;
    }


    /**
     * Executes the CheminReferences Plugin on the provided datarecord
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

            // Grab various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];

            // ----------------------------------------
            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            foreach ($theme_array as $t_id => $t)
                $initial_theme_id = $t_id;


            // ----------------------------------------
            // Output depends on which context the plugin is being executed from
            $context = $rendering_options['context'];
            $output = '';
            if ( $context === 'display' || $context === 'text' || $context === 'html' ) {
                $datafield_mapping = array();
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
                    else {
                        // All of this plugin's fields really should be public...so actually throw an
                        //  error if any of them aren't and the user can do something about it

                        // If the datafield is non-public...
                        $df_public_date = ($df['dataFieldMeta']['publicDate'])->format('Y-m-d H:i:s');
                        if ( $df_public_date == '2200-01-01 00:00:00' ) {
                            if ( !$is_datatype_admin )
                                // ...but the user can't do anything about it, then just refuse to execute
                                return '';
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
                                $datafield_mapping[$key] = array(
                                    'datafield' => $df,
                                    'render_plugin_instance' => $rpi
                                );
                            }
                        }
                    }

                    // If it does have a render plugin, then don't bother looking in the datarecord array
                    //  for the value
                    if ( isset($datafield_mapping[$key]) )
                        continue;


                    // Otherwise, look for the value in the datarecord array
                    if ( !isset($datarecord['dataRecordFields'][$rpf_df_id]) ) {
                        // As far as the reference plugin is concerned, empty strings are acceptable
                        //  values when datarecordfield entries don't exist
                        $datafield_mapping[$key] = '';
                    }
                    elseif ($typeclass === 'File') {
                        $datafield_mapping[$key] = array(
                            'datarecordfield' => $datarecord['dataRecordFields'][$rpf_df_id]
                        );
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
                                $datafield_mapping[$key] = $value;
                                break;

                            default:
                                throw new \Exception('Invalid Fieldtype');
                                break;
                        }

                        $datafield_mapping[$key] = trim($value);
                    }
                }

                // Need to try to ensure urls are valid...
                if ( $datafield_mapping['url'] !== '' ) {
                    // Ensure that DOIs that aren't entirely links still are valid
                    if ( strpos($datafield_mapping['url'], 'doi:') === 0 )
                        $datafield_mapping['url'] = 'https://doi.org/'.trim( substr($datafield_mapping['url'], 4) );

                    // Ensure that the values have an 'https://' prefix
                    if ( strpos($datafield_mapping['url'], 'http') !== 0 )
                        $datafield_mapping['url'] = 'https://'.$datafield_mapping['url'];
                }

                // Going to render the reference differently if it's top-level...
                $is_top_level = $rendering_options['is_top_level'];

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Chemin:CheminReferences/cheminreferences_display.html.twig',
                    array(
                        'datarecord' => $datarecord,
                        'mapping' => $datafield_mapping,

                        'is_top_level' => $is_top_level,
                        'original_context' => $context,
                    )
                );

                // If meant for text output, then replace all whitespace sequences with a single space
                if ( $context === 'text' || $context === 'html' )
                    $output = preg_replace('/(\s+)/', ' ', $output);
            }
            else if ( $context === 'edit' ) {
                // Most of the fields need slightly modified saving javascript...
                $reference_field_list = array();
                foreach ($fields as $rpf_name => $rpf) {
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
                $chemistry_plugin_fields = array();
                foreach ($datatype['dataFields'] as $df_id => $df) {
                    foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.chemistry' ) {
                            $subscript_delimiter = $rpi['renderPluginOptionsMap']['subscript_delimiter'];
                            $superscript_delimiter = $rpi['renderPluginOptionsMap']['superscript_delimiter'];

                            $chemistry_plugin_fields[$df_id] = array(
                                'subscript_delimiter' => $subscript_delimiter,
                                'superscript_delimiter' => $superscript_delimiter,
                            );
                        }
                    }
                }

                // Need to be able to pass this option along if doing edit mode
                $edit_shows_all_fields = $rendering_options['edit_shows_all_fields'];
                $edit_behavior = $rendering_options['edit_behavior'];

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Chemin:CheminReferences/cheminreferences_edit_fieldarea.html.twig',
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
                        'edit_shows_all_fields' => $edit_shows_all_fields,
                        'edit_behavior' => $edit_behavior,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,
                        'reference_field_list' => $reference_field_list,
                        'chemistry_plugin_fields' => $chemistry_plugin_fields,
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
     * @inheritDoc
     */
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // Don't do anything if fields aren't mapped
        $values = array();
        if ( !isset($render_plugin_instance['renderPluginMap']) )
            return array();

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
        $relevant_rpf_names = array('Article Title', 'Book Title', 'Journal', 'Publisher');

        $df_mapping = array();
        $value_mapping = array();
        foreach ($relevant_rpf_names as $rpf_name) {
            $df_id = $render_plugin_instance['renderPluginMap'][$rpf_name]['id'];
            $df_mapping[$rpf_name] = $df_id;

            if ( isset($datarecord['dataRecordFields'][$df_id]) ) {
                $drf = $datarecord['dataRecordFields'][$df_id];

                // Brute-force typeclass since there's only two possibilities
                if ( isset($drf['longText'][0]['value']) )
                    $value_mapping[$df_id] = $drf['longText'][0]['value'];
                else if ( isset($drf['longVarchar'][0]['value']) )
                    $value_mapping[$df_id] = $drf['longVarchar'][0]['value'];
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

                foreach ($results as $result) {
                    $gdr_id = $result['gdr_id'];
                    $this->cache_service->delete('cached_table_data_'.$gdr_id);
                }
            }
        }
    }
}
