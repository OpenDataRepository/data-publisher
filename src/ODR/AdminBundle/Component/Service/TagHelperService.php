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

// Entities
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\TagSelection;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Services
use ODR\AdminBundle\Component\Utility\UniqueUtility;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
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
     * @var EntityCreationService
     */
    private $entity_create_service;

    /**
     * @var EntityMetaModifyService
     */
    private $entity_modify_service;

    /**
     * @var LockService
     */
    private $lock_service;

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
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param EntityCreationService $entity_creation_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param LockService $lock_service
     * @param CsrfTokenManager $token_manager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        EntityCreationService $entity_creation_service,
        EntityMetaModifyService $entity_meta_modify_service,
        LockService $lock_service,
        CsrfTokenManager $token_manager,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->entity_create_service = $entity_creation_service;
        $this->entity_modify_service = $entity_meta_modify_service;
        $this->lock_service = $lock_service;
        $this->token_manager = $token_manager;
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
            $tag_hierarchy = $this->cache_service->get('cached_template_tag_tree_'.$grandparent_datatype_id);

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

            // Apparently can't save an empty array into a cache entry, so define a not-quite-empty
            //  array here in case the above query returns nothing...
            // The rest of ODR is already designed to not assume any of the potentially three levels
            //  of this array exist, so this shouldn't cause a problem
            $tag_hierarchy = array($grandparent_datatype_id => array());

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
     * Stacks tags in a manner similar to DatabaseInfoService::stackDatatypeArray() or
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
     * @param bool $sort_by_name if true, sort by tag name...if false, sort by display order
     */
    public function orderStackedTagArray(&$stacked_tag_array, $sort_by_name = false)
    {
        // Order all the child tags first
        foreach ($stacked_tag_array as $tag_id => $tag) {
            if ( isset($tag['children']) && !empty($tag['children']) )
                $stacked_tag_array[$tag_id]['children'] = self::orderStackedTagArray_worker($tag['children'], $sort_by_name);
        }

        // Now that all child tags are ordered, order the top-level tags
        if ($sort_by_name)
            uasort($stacked_tag_array, "self::tagSort_name");
        else
            uasort($stacked_tag_array, "self::tagSort_displayOrder");
    }


    /**
     * Does the recursive part of sorting tags by displayOrder.
     *
     * @param array $tag_array
     * @param bool $sort_by_name if true, sort by tag name...if false, sort by display order
     *
     * @return array
     */
    private function orderStackedTagArray_worker(&$tag_array, $sort_by_name = false)
    {
        // Order all the children of this tag first
        foreach ($tag_array as $tag_id => $tag) {
            if ( isset($tag['children']) && !empty($tag['children']) )
                $tag_array[$tag_id]['children'] = self::orderStackedTagArray_worker($tag['children'], $sort_by_name);
        }

        // Now that all children of this "tag group" are ordered, order the "tag group" itself
        if ($sort_by_name)
            uasort($tag_array, "self::tagSort_name");
        else
            uasort($tag_array, "self::tagSort_displayOrder");

        return $tag_array;
    }


    /**
     * Custom function to sort tags by name.
     *
     * @param array $a
     * @param array $b
     *
     * @return int
     */
    private function tagSort_name($a, $b)
    {
        return strnatcasecmp($a['tagMeta']['tagName'], $b['tagMeta']['tagName']);
    }


    /**
     * Custom function to sort tags by displayOrder.
     *
     * @param array $a
     * @param array $b
     *
     * @return int
     */
    private function tagSort_displayOrder($a, $b)
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
     * TODO - this currently isn't used...
     *
     * Generates a CSRF token for every datafield/tag pair in the provided arrays.  Use ONLY on the
     * design pages, DatarecordInfoService::generateCSRFTokens() should be used for the edit pages.
     *
     * @param array $datatype_array    @see DatabaseInfoService::buildDatatypeData()
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


    /**
     * Takes an existing stacked tag array from a cached_datatype_array entry, and converts it into
     * a minimal stacked array where the tags are organized by tag names instead of tag ids.
     *
     * Only saves the minimal set of entries required to render the tag list later on.
     *
     * @param array $stacked_tags
     *
     * @return array
     */
    public function convertTagsForListImport($stacked_tags)
    {
        $stacked_tag_array = array();
        foreach ($stacked_tags as $tag_id => $tag_entry) {
            $tag = array(
                'id' => $tag_id,
                'tagMeta' => array(
                    'tagName' => $tag_entry['tagMeta']['tagName'],
                ),
                'tagUuid' => $tag_entry['tagUuid'],
            );

            if ( isset($tag_entry['children']) )
                $tag['children'] = self::convertTagsForListImport($tag_entry['children']);

            // Acceptable to store tags by name here, since none of its siblings *should* have the
            //  exact same name...
            $stacked_tag_array[ $tag_entry['tagName'] ] = $tag;
        }

        return $stacked_tag_array;
    }


    /**
     * Splices a single array of tags the user has provided into the existing tags for a datafield.
     *
     * @param array $existing_tag_array @see self::convertTagsForListImport()
     * @param string[] $new_tags
     * @param &bool $would_create_new_tag Variable is set to true whenever $new_tags contains a tag
     *                                    that is not in $existing_tag_array
     *
     * @return array
     */
    public function insertTagsForListImport($existing_tag_array, $new_tags, &$would_create_new_tag)
    {
        $tag_name = $new_tags[0];
        if ( !isset($existing_tag_array[$tag_name]) ) {
            // A tag with this name doesn't exist at this level yet
            $would_create_new_tag = true;

            // Twig needs an ID, but don't really care what it is...it won't be displayed, and will
            //  be discarded if/when the tag is actually persisted to the database
            $uuid = UniqueUtility::uniqueIdReal();

            // Acceptable to store tags by name here, since none of its siblings *should* have the
            //  exact same name...
            $existing_tag_array[$tag_name] = array(
                'id' => $uuid,
                'tagMeta' => array(
                    'tagName' => $tag_name
                ),
//                'tagUuid' => $uuid,    // Don't need this just for rendering
            );
        }

        // If there are more children/grandchildren to the tag to add...
        if ( count($new_tags) > 1 ) {
            // ...get any children the existing tag already has
            $existing_child_tags = array();
            if ( isset($existing_tag_array[$tag_name]['children']) )
                $existing_child_tags = $existing_tag_array[$tag_name]['children'];

            // This level has been processed, move on to its children
            $new_tags = array_slice($new_tags, 1);
            $existing_tag_array[$tag_name]['children'] =
                self::insertTagsForListImport($existing_child_tags, $new_tags, $would_create_new_tag);
        }

        return $existing_tag_array;
    }


    /**
     * ODR's original implementation of tags was designed so modifying tags was simple...selecting
     * or deselecting a tag had zero effect on any other tag in the datafield.  Parents of selected
     * tags were "assumed" to also be selected, requiring ODR to "fill in" the blanks when rendering,
     * searching, or exporting.  Unfortunately, exporting quirks made this "filling in" too complicated
     * to understand, so the spec was changed...
     *
     * The current ruleset states:
     * 1) selecting a tag needs to ensure its parent (and its parent's parent, etc) are selected
     * 2) deselecting a tag needs to ensure its descendants are unselected
     * 3) deselecting a tag might also make its parent unselected, if none of this tag's siblings
     * are selected
     *
     * Due to the rules, it makes more sense to have a function that can update multiple tags at
     * once...Edit mode will only request a change to one tag at a time (though it could end up
     * changing multiple tags), but FakeEdit/MassEdit/CSVImport/API all benefit from being able to
     * specify multiple tags.
     *
     * While delaying a flush is allowed, this shouldn't be used if possible...changesets are computed
     * based on the contents of the database, which can easily get out of date if flushes are delayed.
     *
     *
     * @param ODRUser $user
     * @param DataRecordFields $drf
     * @param array $desired_selections An array of (tag_id => desired_value)...desired_value can be
     *                                  0, 1, or '!'...the '!' value requests the inverse of the
     *                                  current selection.
     * @param bool $delay_flush If true, then don't flush prior to returning
     * @param array|null $tag_hierarchy
     * @param array|null $inversed_tag_hierarchy Both the tag hierarchies can be passed in to reduce
     *                                           the work done by this function
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     *
     * @return bool True if a change was made, false otherwise
     */
    public function updateSelectedTags($user, $drf, $desired_selections, $delay_flush = false, $tag_hierarchy = null, $inversed_tag_hierarchy = null, $created = null)
    {
        $datafield = $drf->getDataField();
        $datatype = $datafield->getDataType();
        if ( $datafield->getFieldType()->getTypeClass() !== 'Tag' )
            throw new ODRBadRequestException('Not allowed to call TagHelperService::updateSelectedTags() on a non-Tag field', 0x2078e3a4);


        if ( !is_null($tag_hierarchy) && !is_array($tag_hierarchy) )
            throw new ODRBadRequestException('$tag_hierarchy must be an array', 0x2078e3a4);
        if ( !is_null($inversed_tag_hierarchy) && !is_array($inversed_tag_hierarchy) )
            throw new ODRBadRequestException('$inversed_tag_hierarchy must be an array', 0x2078e3a4);


        // ----------------------------------------
        // If the tag field allows multiple levels...
        if ( $datafield->getTagsAllowMultipleLevels() ) {
            // ...then ensure the tag hierarcy array exists
            if ( is_null($tag_hierarchy) ) {
                $grandparent_datatype = $datafield->getDataType()->getGrandparent();
                $tag_hierarchy = self::getTagHierarchy($grandparent_datatype->getId());

                // Want to cut the datatype/datafield levels out of the hierarchy if they're in there
                if ( !isset($tag_hierarchy[$datatype->getId()][$datafield->getId()]) )
                    throw new ODRBadRequestException('Invalid tag hierarchy for TagHelperService::updateSelectedTags()', 0x2078e3a4);
                $tag_hierarchy = $tag_hierarchy[$datatype->getId()][$datafield->getId()];
            }

            // Need to invert the provided hierarchies so that the code can look up the parent
            //  tag when given a child tag
            if ( is_null($inversed_tag_hierarchy) ) {
                $inversed_tag_hierarchy = array();
                foreach ($tag_hierarchy as $parent_tag_id => $children) {
                    foreach ($children as $child_tag_id => $tmp)
                        $inversed_tag_hierarchy[$child_tag_id] = $parent_tag_id;
                }
            }
        }


        // ----------------------------------------
        // Due to reading and potentially creating/modifying multiple tags...using a lock is
        //  unavoidable
        $lockHandler = $this->lock_service->createLock('tag_update_for_drf_'.$drf->getId().'.lock'/*, 5*/);    // Need to lower this if debugging
        if ( !$lockHandler->acquire() ) {
            // Another process is attempting to create this entity...wait for it to finish...
            $lockHandler->acquire(true);
        }
        // Now that the lock is acquired, continue...

        // Need to determine what the current tag selections are...
        $query = $this->em->createQuery(
           'SELECT t.id AS t_id, ts.id AS ts_id, ts.selected AS ts_value
            FROM ODRAdminBundle:TagSelection ts
            JOIN ODRAdminBundle:Tags t WITH ts.tag = t
            WHERE ts.dataRecordFields = :drf_id
            AND ts.deletedAt IS NULL AND t.deletedAt IS NULL'
        )->setParameters( array('drf_id' => $drf->getId()) );
        $results = $query->getArrayResult();

        $current_selections = array();
        foreach ($results as $result) {
            $tag_id = $result['t_id'];
            $tag_selection_id = $result['ts_id'];
            $value = $result['ts_value'];

            $current_selections[$tag_id] = array(
                'ts_id' => $tag_selection_id,
                'value' => $value,
            );
        }

        // ...in order to tweak the array of desired selections to get rid of the '!' character
        foreach ($desired_selections as $tag_id => $value) {
            if ( $value === '!' ) {
                if ( !isset($current_selections[$tag_id]) ) {
                    // If the tag selection doesn't exist, then this value should result in creating
                    //  a new selected tag
                    $desired_selections[$tag_id] = 1;
                }
                else {
                    // If the tag selection already exists, then this value should invert the tag's
                    //  current selection
                    if ( $current_selections[$tag_id]['value'] === 0 )
                        $desired_selections[$tag_id] = 1;
                    else
                        $desired_selections[$tag_id] = 0;
                }
            }
        }

        // Now that the '!' character has been eliminated, we have an "actual" changeset...
        $this->logger->debug('attempting tag update for dr '.$drf->getDataRecord()->getId().', df '.$datafield->getId().' ("'.$datafield->getFieldName().'")...');
//        $this->logger->debug('-- desired selections: '.print_r($desired_selections, true));

        // ...though if a tag hierarchy is involved...
        $cascade_deselections = array();
        if ( !is_null($tag_hierarchy) ) {
            // ...but if a tag hierarcy is involved then we're not quite done yet...the desired
            //  changes might need to cascade up/down/around to other tags in the hierarchy

            // Any tag that the user wants selected...
            foreach ($desired_selections as $tag_id => $value) {
                if ( $value === 1 ) {
                    // ...should recursively ensure each of its parent tags are selected
                    $t_id = $tag_id;
                    while ( isset($inversed_tag_hierarchy[$t_id]) ) {
                        $parent_tag_id = $inversed_tag_hierarchy[$t_id];
                        $desired_selections[$parent_tag_id] = 1;

                        // NOTE: this intentionally overrules the user wanting $parent_tag_id to
                        //  be deselected

                        $t_id = $parent_tag_id;
                    }
                }
            }

            // Need to do two different steps for tags that the user wants deselected

            // The first step is to locate and also deselect all of the tag's descendants
            foreach ($desired_selections as $tag_id => $value) {
                if ( $value === 0 && isset($tag_hierarchy[$tag_id]) ) {

                    $descendant_tag_ids = array();
                    $current_parent_tags = array($tag_id => '');
                    while ( !empty($current_parent_tags) ) {
                        $tmp = array();
                        foreach ($current_parent_tags as $t_id => $str) {
                            if ( isset($tag_hierarchy[$t_id]) ) {
                                foreach ($tag_hierarchy[$t_id] as $child_tag_id => $str) {
                                    $descendant_tag_ids[$child_tag_id] = '';
                                    $tmp[$child_tag_id] = '';
                                }
                            }
                        }

                        // Reset for next loop
                        $current_parent_tags = $tmp;
                    }

                    // These extra deletions are stored in a different array for the moment
                    foreach ($descendant_tag_ids as $t_id => $num)
                        $cascade_deselections[$t_id] = 0;
                }
            }

            // The second step is to determine whether the deselection of this tag should also
            //  trigger the deselection of this tag's parent
            foreach ($desired_selections as $tag_id => $value) {
                if ( $value === 0 && isset($inversed_tag_hierarchy[$tag_id]) ) {
                    // Need to get this tag's parent...
                    $parent_tag_id = $inversed_tag_hierarchy[$tag_id];
                    while ( $parent_tag_id !== false ) {
                        // ...so that each of this tag's siblings can be found
                        $deselect_parent = true;
                        foreach ($tag_hierarchy[$parent_tag_id] as $sibling_tag_id => $str) {
                            if ( isset($desired_selections[$sibling_tag_id]) ) {
                                // If this sibling tag is being modified...
                                if ( $desired_selections[$sibling_tag_id] === 1 ) {
                                    // ...and it's being modified to be selected, then the parent
                                    //  tag should not be deselected
                                    $deselect_parent = false;
                                    break;
                                }

                                // Continue looking when this sibling tag is being unselected
                            }
                            else {
                                // If this sibling tag is not being modified...
                                if ( isset($current_selections[$sibling_tag_id]) && $current_selections[$sibling_tag_id]['value'] === 1 ) {
                                    // ...and it's already selected, then the parent tag should not
                                    //  be deselected
                                    $deselect_parent = false;
                                    break;
                                }

                                // Continue looking when this sibling tag is unselected
                            }
                        }

                        if ( $deselect_parent ) {
                            // If the parent tag needs to get deselected, then store that...
                            $desired_selections[$parent_tag_id] = 0;
                            // ...and check this tag's parent too if it exists
                            if ( isset($inversed_tag_hierarchy[$parent_tag_id]) )
                                $parent_tag_id = $inversed_tag_hierarchy[$parent_tag_id];
                            else
                                break;
                        }
                        else {
                            break;
                        }
                    }
                }
            }

            // Tags which get deselected by this third loop do not need to cascade the deselection
        }

        // Any cascading deselections can now be inserted into the array of desired deselections
        foreach ($cascade_deselections as $tag_id => $num)
            $desired_selections[$tag_id] = 0;


        // ----------------------------------------
        // Now that the array of desired selections covers any tag hierarcy shennanigans, split it
        //  apart into two arrays...creating a new tag selection is different than updating an
        //  existing one
        $new_selections = $changed_selections = array();
        foreach ($desired_selections as $tag_id => $value) {
            if ( !isset($current_selections[$tag_id]) ) {
                // If the tag selection doesn't already exist...
                if ( $value === 1 || $value === '!' ) {
                    // ...then either of these values should result in creating a new tag selection
                    //  with a value of 'selected'
                    $new_selections[$tag_id] = 1;
                }

                // Requests to create a tag selection just to make it 'unselected' are ignored
            }
            else {
                if ( $value === '!' ) {
                    // This value should invert the current selection for this tag
                    if ( $current_selections[$tag_id]['value'] === 0 )
                        $changed_selections[$tag_id] = 1;
                    else
                        $changed_selections[$tag_id] = 0;
                }
                else if ( $current_selections[$tag_id]['value'] !== $value ) {
                    // Otherwise, only do something when the requested value and the current value
                    //  are different
                    $changed_selections[$tag_id] = $value;
                }
            }
        }


        // ----------------------------------------
        $change_made = false;

        // If new TagSelection entries need to be created...
        if ( !empty($new_selections) ) {
            $change_made = true;

            // Need to hydrate each tag that is going to get a new tag selection
            $tag_ids = array_keys($new_selections);
            $query = $this->em->createQuery(
               'SELECT t
                FROM ODRAdminBundle:Tags t
                WHERE t.id IN (:tag_ids)
                AND t.deletedAt IS NULL'
            )->setParameters( array('tag_ids' => $tag_ids ) );
            /** @var Tags[] $results */
            $results = $query->getResult();

            /** @var Tags[] $tag_lookup */
            $tag_lookup = array();
            foreach ($results as $t)
                $tag_lookup[ $t->getId() ] = $t;

            foreach ($new_selections as $t_id => $value) {
                $tag = $tag_lookup[$t_id];

                $this->entity_create_service->createTagSelection($user, $tag, $drf, $value, true, $created);    // delay flush
//                $this->logger->debug(' -- created new tag selection for "'.$tag->getTagName().'" ('.$tag->getId().')');
            }
        }

        // If existing TagSelection entries need to be updated...
        if ( !empty($changed_selections) ) {
            $change_made = true;

            // Need to hydrate each tag selections that will be modified
            $tag_ids = array_keys($changed_selections);
            $query = $this->em->createQuery(
               'SELECT ts
                FROM ODRAdminBundle:Tags t
                JOIN ODRAdminBundle:TagSelection ts WITH ts.tag = t
                WHERE t.id IN (:tag_ids) AND ts.dataRecordFields = :drf_id
                AND t.deletedAt IS NULL AND ts.deletedAt IS NULL'
            )->setParameters(
                array(
                    'tag_ids' => $tag_ids,
                    'drf_id' => $drf->getId(),
                )
            );
            $results = $query->getResult();

            /** @var TagSelection[] $tag_selection_lookup */
            $tag_selection_lookup = array();
            foreach ($results as $ts) {
                /** @var TagSelection $ts */
                $tag_selection_lookup[ $ts->getTag()->getId() ] = $ts;
            }

            // Perform the modifications
            foreach ($changed_selections as $t_id => $value) {
                $tag_selection = $tag_selection_lookup[$t_id];
                $props = array('selected' => $value);

                $this->entity_modify_service->updateTagSelection($user, $tag_selection, $props, true, $created);    // delay flush

                $tag = $tag_selection->getTag();
//                $this->logger->debug(' -- ensuring existing tag selection for "'.$tag->getTagName().'" ('.$tag->getId().') has the value '.$value);
            }
        }

        if ( !$delay_flush && (!empty($new_selections) || !empty($changed_selections)) ) {
            // Flush now that everything is set to the correct value
            $this->em->flush();
        }

        // Now that the modifications are complete, release the lock
        $lockHandler->release();

        // Return whether a change was made
        return $change_made;
        // TODO - does it help the API if something other than boolean is returned?
    }
}
