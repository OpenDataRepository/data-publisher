<?php

/**
 * Open Data Repository Data Publisher
 * Tag Helper Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the pile of support functions that tag hierarchies need to work properly.
 */

namespace ODR\AdminBundle\Component\Service;

// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
// Utility
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class TagHelperService
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
     * @var CsrfTokenManager
     */
    private $token_manager;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * TagHelperService constructor.
     *
     * @param EntityManager $em
     * @param CacheService $cache_service
     * @param CsrfTokenManager $csrfTokenManager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $em,
        CacheService $cache_service,
        CsrfTokenManager $csrfTokenManager,
        Logger $logger
    ) {
        $this->em = $em;
        $this->cache_service = $cache_service;
        $this->token_manager = $csrfTokenManager;
        $this->logger = $logger;
    }


    /**
     * Loads and returns the tag hierarchy for the given datatype.  The returned array is organized
     * by datatype, then by datafield...and then lists pairs of (child_tag_id => parent_tag_id)
     * entries.
     *
     * This cache entry is just an empty array for datatypes/datafields without tags or without
     * a tag hierarchy.
     *
     * @param int $grandparent_datatype_id
     * @param bool $use_tag_uuids If true, use tag_uuids instead of tag_ids, and store in a different
     *                            cache entry
     *
     * @return array
     */
    public function getTagHierarchy($grandparent_datatype_id, $use_tag_uuids = false)
    {
        // Attempt to load this from the cache first...
        $tag_hierarchy = null;
        if (!$use_tag_uuids)
            $tag_hierarchy = $this->cache_service->get('cached_tag_tree_'.$grandparent_datatype_id);
        else
            $tag_hierarchy = $this->cache_service->get('cached_template_tag_tree_'.$grandparent_datatype_id);    // TODO - test this

        if ($tag_hierarchy == false) {
            // ...but rebuild if it doesn't exist
            $query = $this->em->createQuery(
               'SELECT dt.id AS dt_id, df.id AS df_id,
                    c_t.id AS child_tag_id, p_t.id AS parent_tag_id,
                    c_t.tagUuid AS child_tag_uuid, p_t.tagUuid AS parent_tag_uuid

                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                JOIN ODRAdminBundle:Tags AS c_t WITH c_t.dataField = df
                JOIN ODRAdminBundle:TagTree AS tt WITH tt.child = c_t
                JOIN ODRAdminBundle:Tags AS p_t WITH tt.parent = p_t
                WHERE dt.grandparent = :grandparent_datatype_id
                AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
                AND c_t.deletedAt IS NULL AND tt.deletedAt IS NULL AND p_t.deletedAt IS NULL'
            )->setParameters(
                array(
                    'grandparent_datatype_id' => $grandparent_datatype_id
                )
            );
            $results = $query->getArrayResult();

            $tag_hierarchy = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $df_id = $result['df_id'];
                $child_tag_id = $result['child_tag_id'];
                $parent_tag_id = $result['parent_tag_id'];
                if ($use_tag_uuids) {
                    $child_tag_id = $result['child_tag_uuid'];
                    $parent_tag_id = $result['parent_tag_uuid'];
                }

                if ( !isset($tag_hierarchy[$dt_id]) )
                    $tag_hierarchy[$dt_id] = array();
                if ( !isset($tag_hierarchy[$dt_id][$df_id]) )
                    $tag_hierarchy[$dt_id][$df_id] = array();
                if ( !isset($tag_hierarchy[$dt_id][$df_id][$parent_tag_id]) )
                    $tag_hierarchy[$dt_id][$df_id][$parent_tag_id] = array();

                $tag_hierarchy[$dt_id][$df_id][$parent_tag_id][$child_tag_id] = '';
            }

            // Store results back in the cache
            if (!$use_tag_uuids)
                $this->cache_service->set('cached_tag_tree_'.$grandparent_datatype_id, $tag_hierarchy);
            else
                $this->cache_service->set('cached_template_tag_tree_'.$grandparent_datatype_id, $tag_hierarchy);

        }

        return $tag_hierarchy;
    }


    /**
     * Stacks tags in a manner similar to DatatypeInfoService::stackDatatypeArray() or
     * DatarecordInfoService::stackDatarecordArray().
     *
     * @param array $tag_list An array tag data from the database, organized by tag_id
     * @param array $tag_tree @see self::getTagHierarchy()
     *
     * @return array
     */
    public function stackTagArray($tag_list, $tag_tree)
    {
        // Traverse the tag tree to create a list of tags which have parents
        $child_tags = array();
        foreach ($tag_tree as $parent_tag_id => $children) {
            foreach ($children as $child_tag_id => $tmp)
                $child_tags[$child_tag_id] = '';
        }

        // Create an array to store the stacked data in...
        $stacked_tags = array();
        foreach ($tag_list as $tag_id => $tag) {
            // Tags which have parents aren't top-level
            if ( !isset($child_tags[$tag_id]) )
                $stacked_tags[$tag_id] = $tag;
        }

        // Locate and store all child tags underneath each top-level tag
        foreach ($stacked_tags as $tag_id => $tag) {
            // If this tag has no children
            if ( !isset($tag_tree[$tag_id]) ) {
                // ...then don't need to do anything...don't need to store an empty array
//                $stacked_tags[$tag_id]['children'] = array();
            }
            else {
                // ...otherwise, build up a list of child tags for this top-level tag
                $stacked_tags[$tag_id]['children'] = self::stackTagArray_worker($tag_list, $tag_tree, $tag_id);
            }
        }

        // Return the array of stacked tags
        return $stacked_tags;
    }


    /**
     * Does the recursive part of stacking tags.
     *
     * @param array $tag_list An array tag data from the database, organized by tag_id
     * @param array $tag_tree @see self::getTagHierarchy()
     * @param int $parent_tag_id The tag currently being stacked
     *
     * @return array
     */
    private function stackTagArray_worker($tag_list, $tag_tree, $parent_tag_id)
    {
        // Create an array of all of $parent_tag_id's children
        $tmp = array();
        foreach ($tag_tree[$parent_tag_id] as $child_tag_id => $num)
            $tmp[$child_tag_id] = $tag_list[$child_tag_id];

        // For each child of $parent_tag_id...
        foreach ($tmp as $child_tag_id => $child_tag) {
            // ...if this child tag has no children itself
            if ( !isset($tag_tree[$child_tag_id]) ) {
                // ...then don't need to do anything...don't need to store an empty array
//                $stacked_tags[$tag_id]['children'] = array();
            }
            else {
                // ...otherwise, build up a list of child tags for child tag
                $tmp[$child_tag_id]['children'] = self::stackTagArray_worker($tag_list, $tag_tree, $child_tag_id);
            }
        }

        // Return the stacked array of $parent_tag_id's children
        return $tmp;
    }


    /**
     * Ordering a stacked tag array by each tag's displayOrder property is non-trivial...each tag
     * needs to be "grouped" with the other tags that have the same parent (or are top-level), and
     * each group needs to be ordered individually.
     *
     * Does not make any changes to the database.
     *
     * @param $stacked_tag_array @see self::stackTagArray()
     */
    public function orderStackedTagArray(&$stacked_tag_array)
    {
        // Order all the child tags first
        foreach ($stacked_tag_array as $tag_id => $tag) {
            if ( isset($tag['children']) && !empty($tag['children']) )
                $stacked_tag_array[$tag_id]['children'] = self::orderStackedTagArray_worker($tag['children']);
        }

        // Now that all child tags are ordered, order the top-level tags
        uasort($stacked_tag_array, "self::tagSort");
    }


    /**
     * Does the recursive part of sorting tags by displayOrder.
     *
     * @param array $tag_array
     *
     * @return array
     */
    private function orderStackedTagArray_worker(&$tag_array)
    {
        // Order all the children of this tag first
        foreach ($tag_array as $tag_id => $tag) {
            if ( isset($tag['children']) && !empty($tag['children']) )
                $tag_array[$tag_id]['children'] = self::orderStackedTagArray_worker($tag['children']);
        }

        // Now that all children of this "tag group" are ordered, order the "tag group" itself
        uasort($tag_array, "self::tagSort");
        return $tag_array;
    }


    /**
     * Custom function to sort tags by displayOrder.
     *
     * @param array $a
     * @param array $b
     *
     * @return int
     */
    private function tagSort($a, $b)
    {
        $a_displayOrder = intval($a['tagMeta']['displayOrder']);
        $b_displayOrder = intval($b['tagMeta']['displayOrder']);

        if ($a_displayOrder < $b_displayOrder)
            return -1;
        else if ($a_displayOrder > $b_displayOrder)
            return 1;
        else
            // Otherwise, sort by tag_id
            return ($a['id'] < $b['id']) ? -1 : 1;
    }


    /**
     * Generates a CSRF token for every datafield/tag pair in the provided arrays.  Use ONLY on the
     * design pages, DatarecordInfoService::generateCSRFTokens() should be used for the edit pages.
     *
     * @param array $datatype_array    @see DatatypeInfoService::buildDatatypeData()
     *
     * @return array
     */
    public function generateCSRFTokens($datatype_array)
    {
        $token_list = array();
        foreach ($datatype_array as $dt_id => $dt) {
            foreach ($dt['dataFields'] as $df_id => $df) {

                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                if ($typeclass !== 'Tag')
                    continue;

                foreach ($df['tags'] as $tag_id => $tag) {
                    $token_id = $typeclass.'Form_'.$df_id.'_'.$tag_id;
                    $token_list[$df_id][$tag_id] = $this->token_manager->getToken($token_id)->getValue();
                }
            }
        }

        return $token_list;
    }
}
