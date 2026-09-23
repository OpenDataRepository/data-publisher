<?php

/**
 * Open Data Repository Data Publisher
 * Space Group Synonym Search Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Most space groups have multiple "synonyms"...e.g. A1, B1, C1, F1, I1, and P1 are all settings for
 * space group #1.  It's not uncommon for somebody to just want to use one of them as a shortcut
 * for the entire group, so it makes sense to have a plugin to perform this substitution for the user.
 * Additionally, this enables the use of a string like "#1" to look up all synonyms for space group #1.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Entities
// Events
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Interfaces
use ODR\OpenRepository\GraphBundle\Plugins\CrystallographyDef;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\SearchOverrideInterface;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchQueryService;
// Symfony
use Psr\Log\LoggerInterface;


class SpaceGroupSynonymSearchPlugin implements DatafieldPluginInterface, SearchOverrideInterface
{

    /**
     * SpaceGroupSynonymSearch Plugin constructor.
     *
     * @param CacheService $cache_service
     * @param SearchService $search_service
     * @param SearchQueryService $search_query_service
     * @param \Twig\Environment $templating
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheService $cache_service,
        private readonly SearchService $search_service,
        private readonly SearchQueryService $search_query_service,
        private readonly \Twig\Environment $templating,
        private readonly LoggerInterface $logger
    ) {

    }


    /**
     * @inheritDoc
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        // The plugin should only be executed as part of the search sidebar
        return false;
    }


    /**
     * @inheritDoc
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $rendering_options)
    {
        // The plugin should only be executed as part of the search sidebar
        return '';
    }


    /**
     * @inheritDoc
     */
    public function canExecuteSearchPlugin($render_plugin_instance, $datatype, $datafield, $rendering_options)
    {
        // Don't need any of the provided parameters to make a decision
        return true;
    }


    /**
     * @inheritDoc
     */
    public function executeSearchPlugin($render_plugin_instance, $datatype, $datafield, $preset_value, $rendering_options)
    {
        $plugin_options = $render_plugin_instance['renderPluginOptionsMap'];

        // Determine whether the field's checkbox should be checked or not
        $search_synonyms_by_default = true;
        if ( $plugin_options['search_synonyms_by_default'] === 'no' )
            $search_synonyms_by_default = false;

        $use_synonyms = $search_synonyms_by_default;
        if ( $preset_value !== '' && str_contains($preset_value, '~') )
            $use_synonyms = !$use_synonyms;

        // Potentially convert it into a different format for the search sidebar
        $preset_value = self::convertSearchTerm($preset_value, $use_synonyms, true);

        // Render the datafield for the search sidebar
        $output = $this->templating->render(
            '@ODROpenRepositoryGraph/Base/SpaceGroupSynonymsSearch/space_group_synonyms_search_datafield.html.twig',
            [
                'datatype' => $datatype,
                'datafield' => $datafield,
                'plugin_options' => $plugin_options,

                'preset_value' => $preset_value,
                'search_synonyms_by_default' => $search_synonyms_by_default,
                'use_synonyms' => $use_synonyms,
            ]
        );

        return $output;
    }


    /**
     * @inheritDoc
     */
    public function getSearchOverrideFields($df_list)
    {
        // Since this is a datafield plugin, $df_list will only have one field...always want to
        //  override how it's searched
        return $df_list;
    }


    /**
     * @inheritDoc
     */
    public function searchOverriddenField($datafield, $search_term, $render_plugin_fields, $render_plugin_options, $use_set_logic)
    {
        // ----------------------------------------
        // Don't continue if somehow called on the wrong type of datafield
        $allowed_typeclasses = array(
            'ShortVarchar',
        );
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ( !in_array($typeclass, $allowed_typeclasses) )
            throw new ODRBadRequestException('SpaceGroupSynonymSearchPlugin::searchPluginField() called with '.$typeclass.' datafield', 0xc2b0ae42);


        // ----------------------------------------
        // See if this search result is already cached...
        $cached_searches = $this->cache_service->get('cached_search_df_'.$datafield->getId());
        if ( !$cached_searches )
            $cached_searches = array();


        // ----------------------------------------
        // Going to silently convert the search value into a different value for the purposes of
        //  caching if it matches what the plugin is looking for...
        $value = trim( $search_term['value'] );

        // Need to determine whether this was supposed to search by synonyms or not
        $search_synonyms_by_default = true;
        if ( $render_plugin_options['search_synonyms_by_default'] === 'no' )
            $search_synonyms_by_default = false;

        $search_synonyms = $search_synonyms_by_default;
        if ( str_contains($value, '~') )
            $search_synonyms = !$search_synonyms;

        // Potentially convert it into a different format for the search system
        $value = self::convertSearchTerm($value, $search_synonyms, false);


        // ----------------------------------------
        // Since MYSQL's collation is case-insensitive, the php caching should treat it the same
        $cache_key = mb_strtolower($value);
        if ( !$use_set_logic ) {
            if ( isset($cached_searches[$cache_key]) )
                return $cached_searches[$cache_key];
        }
        else {
            if ( isset($cached_searches['set'][$cache_key]) )
                return $cached_searches['set'][$cache_key];
        }


        // ----------------------------------------
        // If the search isn't cached, then run it again
        $result = $this->search_query_service->searchTextOrNumberDatafield(
            $datafield->getDataType()->getId(),
            $datafield->getId(),
            $typeclass,
            $value,
            $use_set_logic
        );

        $end_result = array(
            'dt_id' => $datafield->getDataType()->getId(),
            'records' => $result['records'],
            'modify' => $result['modify'],
        );


        // ----------------------------------------
        // Recache the search result...
        if ( !$use_set_logic )
            $cached_searches[$cache_key] = $end_result;
        else
            $cached_searches['set'][$cache_key] = $end_result;
        $this->cache_service->set('cached_search_df_'.$datafield->getId(), $cached_searches);

        // ...then return it
        return $end_result;
    }


    /**
     * Converts a search term string into one of two formats required by the plugin.
     *
     * @param string $value
     * @param bool $use_synonyms
     * @param bool $for_sidebar If true, then the string is meant for sidebar rendering. If false,
     *                          then the string is meant for actual searching
     * @return string
     */
    private function convertSearchTerm($value, $use_synonyms, $for_sidebar)
    {
        $space_groups = CrystallographyDef::$space_groups;
        $space_group_synonyms = CrystallographyDef::$space_group_synonyms;

        // Never want the '~' character to make it back into the sidebar or the search system
        $value = str_replace('~', '', $value);

        if ( preg_match('/^\"?#\d{1,3}\"?$/', $value) ) {
            // This mode will ALWAYS search for synonyms, regardless of the value of $use_synonyms
            // ...unless the number isn't a valid space group number  (e.g. between 1 and 230)

            // If the given value is a number preceeded by a '#' character...
            if ( $for_sidebar ) {
                // ...and it's meant for the sidebar, then do nothing more to it
                return $value;
            }
            else {
                // ...otherwise, it's meant for the search system.  Extract the numerical portion...
                $num = intval( str_replace(['"', '#'], '', $value) );
                // ...and replace the search term with the list of synonyms for that space group number
                if ( isset($space_groups[$num]) )
                    $value = '"'.implode('" OR "', $space_groups[$num]).'"';
            }
        }
        else if ( $use_synonyms && preg_match('/^[abcdefipmnr123456\-\_\/\"]+$/i', $value) ) {
            // The given value could be a single Wyckoff space group...remove any '"' characters
            //  otherwise the value definitely won't match anything
            $key = str_replace('"', '', $value );

            if ( isset($space_group_synonyms[$key]) ) {
                // This is considered a single valid synonym by ODR
                $group_num = $space_group_synonyms[$key];

                // Convert the string into an expanded one that directly lists all of its synonyms
                $value = '"'.implode('" OR "', $space_groups[$group_num]).'"';
            }

            // Otherwise, this is not considered a valid synonym by ODR...and shouldn't be modified
            //  further
        }

        // If it didn't match the regular expression, then we're not going to do anything with it
        return $value;
    }

}
