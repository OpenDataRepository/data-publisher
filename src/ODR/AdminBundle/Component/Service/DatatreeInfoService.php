<?php

/**
 * Open Data Repository Data Publisher
 * Datatree Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get and rebuild the cached version of the datatree array.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Symfony
use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;

class DatatreeInfoService
{

    /**
     * DatatreeInfoService constructor.
     *
     * @param EntityManager $em
     * @param CacheService $cache_service
     * @param LoggerInterface $logger
     */
    public function __construct(private readonly EntityManager $em, private readonly CacheService $cache_service, private readonly LoggerInterface $logger)
    {
    }


    /**
     * Utility function to return the DataTree table as an array.  The array has the following format:
     * <pre>
     * array(
     *     'descendant_of' => array(
     *         <top_level_datatype_id> => '',
     *         <child_datatype_id> => <parent_datatype_id>,
     *         ...
     *     ),
     *     'linked_from' => array(
     *         <linked_descendant_datatype_id> => array(
     *             <linked_ancestor_datatype_id>,
     *             ...
     *         ),
     *         ...
     *     ),
     *     'multiple_allowed' => array(
     *         ...like 'linked_from', but for all child/linked datatypes that allow multiple descendants
     *     ),
     *     'edit_behavior' => array(
     *         ...like 'linked_from', but for all child/linked datatypes that override edit_behavior
     *     ),
     * )
     * </pre>
     *
     * @return array
     */
    public function getDatatreeArray()
    {
        // ----------------------------------------
        // If datatree data exists in cache and user isn't demanding a fresh version, return that
        $datatree_array = $this->cache_service->get('cached_datatree_array');
        if ( $datatree_array !== false && count($datatree_array) > 0 )
            return $datatree_array;


        // ----------------------------------------
        // Otherwise...get all the datatree data
        $query = $this->em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id,
                    dtm.is_link AS is_link, dtm.multiple_allowed AS multiple_allowed, dtm.edit_behavior AS edit_behavior
            FROM ODR\AdminBundle\Entity\DataType AS ancestor
            JOIN ODR\AdminBundle\Entity\DataTree AS dt WITH ancestor = dt.ancestor
            JOIN ODR\AdminBundle\Entity\DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODR\AdminBundle\Entity\DataType AS descendant WITH dt.descendant = descendant
            WHERE ancestor.setup_step IN (:setup_step) AND descendant.setup_step IN (:setup_step)
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
            AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( ['setup_step' => DataType::STATE_VIEWABLE] );
        $results = $query->getArrayResult();

        $datatree_array = [
            'descendant_of' => [],
            'linked_from' => [],
            'multiple_allowed' => [],
            'edit_behavior' => [],
        ];
        foreach ($results as $num => $result) {
            $ancestor_id = $result['ancestor_id'];
            $descendant_id = $result['descendant_id'];
            $is_link = $result['is_link'];
            $multiple_allowed = $result['multiple_allowed'];
            $edit_behavior = $result['edit_behavior'];

            // Always need an entry in this array so recursion can stop on a top-level datatype id
            if ( !isset($datatree_array['descendant_of'][$ancestor_id]) )
                $datatree_array['descendant_of'][$ancestor_id] = '';

            if ($is_link == 0) {
                // child datatypes need to point to their ancestor
                $datatree_array['descendant_of'][$descendant_id] = $ancestor_id;
            }
            else {
                // linked datatypes are slightly easier to work with if they have a list of all
                //  datatypes that link to them
                if ( !isset($datatree_array['linked_from'][$descendant_id]) )
                    $datatree_array['linked_from'][$descendant_id] = [];
                $datatree_array['linked_from'][$descendant_id][] = $ancestor_id;

                // The edit_behavior flag only matters for linked datatypes, and should only be stored
                //  when it deviates from the default behavior
                if ( $edit_behavior !== DataTreeMeta::ALWAYS_EDIT ) {
                    // ...might as well store it the same way as all the other sub-arrays
                    if ( !isset($datatree_array['edit_behavior'][$descendant_id]) )
                        $datatree_array['edit_behavior'][$descendant_id] = [];
                    $datatree_array['edit_behavior'][$descendant_id][$ancestor_id] = $edit_behavior;
                }
            }

            // No sense storing both values for the multiple-allowed flag...
            if ($multiple_allowed == 1) {
                // ...since it's more of a linked datatype option, might as well store it the same way
                if ( !isset($datatree_array['multiple_allowed'][$descendant_id]) )
                    $datatree_array['multiple_allowed'][$descendant_id] = [];
                $datatree_array['multiple_allowed'][$descendant_id][] = $ancestor_id;
            }
        }

        // Store in cache and return
        $this->cache_service->set('cached_datatree_array', $datatree_array);
        return $datatree_array;
    }


    /**
     * Returns an array of top-level datatype ids.
     *
     * Note: this also includes top-level "master templates"
     *
     * @return int[]
     */
    public function getTopLevelDatatypes()
    {
        // ----------------------------------------
        // If list of top level datatypes exists in cache, return that
        $top_level_datatypes = $this->cache_service->get('top_level_datatypes');
        if ( $top_level_datatypes !== false && count($top_level_datatypes) > 0 )
            return $top_level_datatypes;


        // ----------------------------------------
        // Otherwise, rebuild the list of top-level datatypes
        // TODO - enforce dt.is_master_type = 0  here?
        // TODO - cut out metadata datatypes from this?
        $query = $this->em->createQuery(
           'SELECT dt.id AS datatype_id
            FROM ODR\AdminBundle\Entity\DataType AS dt
            JOIN ODR\AdminBundle\Entity\DataType AS grandparent WITH dt.grandparent = grandparent
            WHERE dt.setup_step IN (:setup_steps) AND dt.id = grandparent.id
            AND dt.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( ['setup_steps' => DataType::STATE_VIEWABLE] );
        $results = $query->getArrayResult();

        // AND dt.metadataFor IS NULL
        $top_level_datatypes = [];
        foreach ($results as $result)
            $top_level_datatypes[] = $result['datatype_id'];


        // ----------------------------------------
        // Store the list in the cache and return
        $this->cache_service->set('top_level_datatypes', $top_level_datatypes);
        return $top_level_datatypes;
    }


    /**
     * Returns an array of top-level datatypes that are specifically "master templates".
     *
     * @return int[]
     */
    public function getTopLevelTemplates()
    {
        // ----------------------------------------
        // If list of top level datatypes exists in cache, return that
//        $top_level_templates = $this->cache_service->get('top_level_templates');
//        if ( $top_level_templates !== false && count($top_level_templates) > 0 )
//            return $top_level_templates;


        // ----------------------------------------
        // Otherwise, rebuild the list of top-level templates
        $query = $this->em->createQuery(
           'SELECT dt.id AS datatype_id
            FROM ODR\AdminBundle\Entity\DataType AS dt
            JOIN ODR\AdminBundle\Entity\DataType AS grandparent WITH dt.grandparent = grandparent
            WHERE dt.setup_step IN (:setup_steps) AND dt.id = grandparent.id
            AND dt.is_master_type = 1
            AND dt.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( ['setup_steps' => DataType::STATE_VIEWABLE] );
        $results = $query->getArrayResult();

        // AND dt.metadataFor IS NULL
        $top_level_templates = [];
        foreach ($results as $result)
            $top_level_templates[] = $result['datatype_id'];


        // ----------------------------------------
        // Store the list in the cache and return
//        $this->cache_service->set('top_level_templates', $top_level_templates);
        return $top_level_templates;
    }


    /**
     * Given the id of a top-level datatype, returns the ids of all other top-level datatypes that
     * "can be reached" from the original top-level datatype by traversing from "ancestors" to
     * "descendants".
     *
     * Most of ODR treats this as the default way to associate datatypes together, and it's used
     * for everything from rendering to permissions.
     *
     * @param integer $top_level_datatype_id
     * @param boolean $deep If true, then all children of the associated datatypes are also returned
     *
     * @return int[]
     */
    public function getAssociatedDatatypes($top_level_datatype_id, $deep = false)
    {
        // Only want to load the cached datatree array once...
        $datatree_array = null;

        // Need to locate all datatypes that the given datatype links to
        $associated_datatypes = $this->cache_service->get('associated_datatypes_for_'.$top_level_datatype_id);
        if ($associated_datatypes == false) {
            $datatree_array = self::getDatatreeArray();

            // The end result should always contain the requested top-level datatype id
            $associated_datatypes = [$top_level_datatype_id => 0];
            $datatypes_to_check = [$top_level_datatype_id];
            while ( !empty($datatypes_to_check) ) {
                // Need to first find all datatypes with the requested top-level datatype as their
                //  ancestor...
                $children = self::getChildDescendants($datatypes_to_check, $datatree_array);
                // Also need to ensure the requested to-level datatype is in this array
                foreach ($datatypes_to_check as $num => $dt_id)
                    $children[] = $dt_id;

                // Then need to find any datatypes that the previously found set links to
                $links = self::getLinkedDescendants($children, $datatree_array);

                // Save any linked descendants that were found...
                $datatypes_to_check = [];
                foreach ($links as $num => $c_dt_id) {
                    $associated_datatypes[$c_dt_id] = 0;
                    $datatypes_to_check[$c_dt_id] = 0;
                }
                // ...and then reset to find any datatypes that these descendants link to
                $datatypes_to_check = array_keys($datatypes_to_check);
            }

            // Save the list of associated datatypes back into the cache
            $associated_datatypes = array_keys($associated_datatypes);
            $this->cache_service->set('associated_datatypes_for_'.$top_level_datatype_id, $associated_datatypes);
        }

        // If the caller also needs the ids of the children of these top-level datatypes...
        if ($deep) {
            // ...ensure the datatree array exists...
            if ( is_null($datatree_array) )
                $datatree_array = self::getDatatreeArray();

            // ...dig through it to find all the non-linked descendants (since the linked descendants
            //  are already in $associated_datatypes)...
            $tmp = self::getChildDescendants($associated_datatypes, $datatree_array, true);
            // ...and splice those into the array for returning
            foreach ($tmp as $num => $dt_id)
                $associated_datatypes[] = $dt_id;
        }

        return $associated_datatypes;
    }


    /**
     * This function is similar to {@link self::getAssociatedDatatypes()}, but it goes from
     * "descendants" to "ancestors" instead.
     *
     * @param integer $bottom_level_datatype_id
     * @param integer $target_top_level_datatype_id If provided, then the returned array will only
     *                                               contain datatypes "on the path" from the bottom
     *                                               level datatype to the provided top-level datatype
     * @param boolean $deep If true, then all children of the associated datatypes are also returned
     *
     * @return array
     */
    public function getInverseAssociatedDatatypes($bottom_level_datatype_id, $target_top_level_datatype_id = 0, $deep = false)
    {
        // Only want to load the cached datatree array once...
        $datatree_array = null;

        // Need to locate all datatypes that link to the given datatype
        $associated_datatypes = $this->cache_service->get('inverse_associated_datatypes_for_'.$bottom_level_datatype_id);
        if ($associated_datatypes == false) {
            $datatree_array = self::getDatatreeArray();

            // The end result should always contain the requested top-level datatype id
            $associated_datatypes = [$bottom_level_datatype_id => 0];
            $datatypes_to_check = [$bottom_level_datatype_id];
            while ( !empty($datatypes_to_check) ) {
                // Need to get the ids of all datatypes that link to the requested datatype...
                $links = self::getLinkedAncestors($datatypes_to_check, $datatree_array);
                $immediate_parents = [];
                foreach ($links as $num => $dt_id)
                    $immediate_parents[] = $dt_id;

                // ...but then need to get the grandparents of those datatypes
                $datatypes_to_check = [];
                foreach ($immediate_parents as $num => $dt_id) {
                    $gp_dt_id = self::getGrandparentDatatypeId($dt_id, $datatree_array);

                    $associated_datatypes[$gp_dt_id] = 0;
                    $datatypes_to_check[$gp_dt_id] = 0;
                }

                // Reset for the next loop
                $datatypes_to_check = array_keys($datatypes_to_check);
            }

            // Save the list of associated datatypes back into the cache
            $associated_datatypes = array_keys($associated_datatypes);
            $this->cache_service->set('inverse_associated_datatypes_for_'.$bottom_level_datatype_id, $associated_datatypes);
        }

        // A value of '0' means the list of associated datatypes shouldn't be filtered...
        if ( $target_top_level_datatype_id !== 0 ) {
            // ...but if it is non-zero, then that means the user only wants the list of datatypes
            //  required to traverse from the bottom-level to the top-level datatype
            if ( !in_array($target_top_level_datatype_id, $associated_datatypes) )
                throw new ODRException('Invalid $target_top_level_datatype_id passed to DatatreeInfoService::getInverseAssociatedDatatypes()', 400, 0x0b4d0f4f);

            // The fastest way to figure out the traversal path should be to intersect this array
            //  with the array of associated datatypes from the target top-level...
            $tmp = self::getAssociatedDatatypes($target_top_level_datatype_id);
            // NOTE: don't want to use $deep == true here...only want top-level datatypes by the
            //  end of this if block.  If the user wants child datatypes, then the next if block
            //  will add them

            // ...but due to actually wanting the descendants of the target top-level datatype in
            //  the returned array, it's better to "manually" perform the intersection...
            $associated_datatypes = array_flip($associated_datatypes);
            $tmp = array_flip($tmp);

            foreach ($associated_datatypes as $dt_id => $num) {
                if ( !isset($tmp[$dt_id]) )
                    unset( $associated_datatypes[$dt_id] );
            }

            // ...because doing it this way makes it easier to add the descendants of the top-level
            //  datatype back in
            foreach ($tmp as $dt_id => $num)
                $associated_datatypes[$dt_id] = 0;

            // The array needs to be returned with the datatype ids as values, however
            $associated_datatypes = array_keys($associated_datatypes);
        }

        // If the caller also needs the ids of the children of these top-level datatypes...
        if ( $deep ) {
            // ...ensure the datatree array exists...
            if ( is_null($datatree_array) )
                $datatree_array = self::getDatatreeArray();

            // ...dig through it to find all the non-linked descendants (since the linked descendants
            //  are already in $associated_datatypes)...
            $tmp = self::getChildDescendants($associated_datatypes, $datatree_array, true);
            // ...and splice those into the array for returning
            foreach ($tmp as $num => $dt_id)
                $associated_datatypes[] = $dt_id;
        }

        return $associated_datatypes;
    }


    /**
     * Traverses the cached version of the datatree array in order to return the grandparent id
     * of the given datatype id.
     *
     * @param int $initial_datatype_id
     * @param array|null $datatree_array
     *
     * @return int
     */
    public function getGrandparentDatatypeId($initial_datatype_id, $datatree_array = null)
    {
        if ( is_null($datatree_array) )
            $datatree_array = self::getDatatreeArray();

        $grandparent_datatype_id = $initial_datatype_id;
        while (
            isset($datatree_array['descendant_of'][$grandparent_datatype_id])
            && $datatree_array['descendant_of'][$grandparent_datatype_id] !== ''
        ) {
            // This isn't a top-level datatype, so grab its immediate parent datatype's id
            $grandparent_datatype_id = $datatree_array['descendant_of'][$grandparent_datatype_id];
        }

        return $grandparent_datatype_id;
    }


    /**
     * Returns an array of all the datatypes that are children of the given datatypes.
     *
     * @param array $datatype_ids
     * @param null|array $datatree_array
     * @param boolean $deep if false, then only go one level down...if true, continue until bottom-level
     *
     * @return int[]
     */
    public function getChildDescendants($datatype_ids, $datatree_array = null, $deep = true)
    {
        if ( is_null($datatree_array) )
            $datatree_array = self::getDatatreeArray();

        $child_datatypes = [];

        $datatypes_to_check = $datatype_ids;
        while ( !empty($datatypes_to_check) ) {
            $tmp = [];
            foreach ($datatypes_to_check as $num => $parent_dt_id) {
                $keys = array_keys($datatree_array['descendant_of'], $parent_dt_id);
                foreach ($keys as $num => $c_dt_id) {
                    $tmp[$c_dt_id] = 0;
                    $child_datatypes[$c_dt_id] = 0;
                }
            }

            if ($deep)
                $datatypes_to_check = array_keys($tmp);
            else
                $datatypes_to_check = [];
        }

        $child_datatypes = array_keys($child_datatypes);
        return $child_datatypes;
    }


    /**
     * Returns the grandparent ids of all datatypes that link to the given set of datatypes.
     *
     * @param array $datatype_ids
     * @param null|array $datatree_array
     * @param boolean $deep assuming  D links to C links to B links to A  and function is called with {A}...
     *                      then if $deep == false, only return the closest set of linked ancestors  e.g. {B}.
     *                      Otherwise if true, then return all possible linked ancestors  e.g. {B,C,D,...}
     *
     * @return int[]
     */
    public function getLinkedAncestors($datatype_ids, $datatree_array = null, $deep = false)
    {
        // Need the cached datatree array...
        if ( is_null($datatree_array) )
            $datatree_array = self::getDatatreeArray();

        $linked_ancestors = [];

        $datatypes_to_check = $datatype_ids;
        while ( !empty($datatypes_to_check) ) {
            $tmp = [];
            foreach ($datatypes_to_check as $num => $child_dt_id) {
                if ( isset($datatree_array['linked_from'][$child_dt_id]) ) {
                    foreach ($datatree_array['linked_from'][$child_dt_id] as $num => $p_dt_id) {
                        // $p_dt_id may not necessarily be a top-level datatype
                        $p_dt_id = self::getGrandparentDatatypeId($p_dt_id, $datatree_array);

                        $tmp[$p_dt_id] = 0;
                        $linked_ancestors[$p_dt_id] = 0;
                    }
                }
            }

            // Reset for the next pass, if applicable
            if ($deep)
                $datatypes_to_check = array_keys($tmp);
            else
                $datatypes_to_check = [];
        }

        $linked_ancestors = array_keys($linked_ancestors);
        return $linked_ancestors;
    }


    /**
     * Returns the ids of all datatypes that the given set of datatypes link to.
     *
     * @param array $datatype_ids
     * @param null|array $datatree_array
     * @param boolean $deep assuming A links to B links to C links to D, and function is called with {A}
     *                      then if $deep == false, then only find the closest set of linked descendants
     *                      e.g. getLinkedDescendants() returns {B}
     *                      then if true, then find all possible linked descendants
     *                      e.g. getLinkedDescendants() returns {B, C, D, ...}
     *
     * @return int[]
     */
    public function getLinkedDescendants($datatype_ids, $datatree_array = null, $deep = false)
    {
        // Need the cached datatree array...
        if ( is_null($datatree_array) )
            $datatree_array = self::getDatatreeArray();

        $linked_descendants = [];

        $datatypes_to_check = $datatype_ids;
        while ( !empty($datatypes_to_check) ) {
            $tmp = [];
            foreach ($datatypes_to_check as $num => $parent_dt_id) {
                foreach ($datatree_array['linked_from'] as $c_dt_id => $parents) {
                    if ( in_array($parent_dt_id, $parents) ) {
                        $tmp[$c_dt_id] = 0;
                        $linked_descendants[$c_dt_id] = 0;
                    }
                }
            }

            // Reset for the next pass, if applicable
            if ($deep)
                $datatypes_to_check = array_keys($tmp);
            else
                $datatypes_to_check = [];
        }

        $linked_descendants = array_keys($linked_descendants);
        return $linked_descendants;
    }


    /**
     * Returns the ids of all datarecords that need to have their cache entries loaded so the given
     *  $top_level_datarecord_id can be properly rendered.
     *
     * @param int $top_level_datarecord_id
     * @param string $type  if "render", then return the records belonging to single and multiple-allowed links
     *                      if "table", then only return the records belonging to single-allowed links
     *                      if "both", then return both "multiple" and "single"
     *
     * @return array
     */
    public function getAssociatedDatarecords($top_level_datarecord_id, $type = "render")
    {
        // Need to locate all linked datarecords for the provided datarecord
        $associated_datarecords = $this->cache_service->get('associated_datarecords_for_'.$top_level_datarecord_id);
        if ($associated_datarecords == false) {
            // The $render_drs include records from all links, needed so that rendering can work
            //  properly
            $render_drs = self::getMultipleAllowedDatarecords_worker( [$top_level_datarecord_id] );
            // The $table_drs only include records from single-allowed links, used for the table
            //  search results...this is technically a subset of the previous
            $table_drs = self::getSingleAllowedDatarecords_worker( [$top_level_datarecord_id] );

            // Both of those arrays need the requested top-level datarecord in there...
            $render_drs[$top_level_datarecord_id] = 1;
            $table_drs[$top_level_datarecord_id] = 1;

            // ...and most places want the datarecord ids as values instead of keys
            $associated_datarecords = [
                0 => array_keys($render_drs),
                1 => array_keys($table_drs)
            ];

            // Save the list of associated datarecords back into the cache
            $this->cache_service->set('associated_datarecords_for_'.$top_level_datarecord_id, $associated_datarecords);
        }

        // Return the version the user wanted
        if ( $type === 'render' )
            return $associated_datarecords[0];
        else if ( $type === 'table' )
            return $associated_datarecords[1];
        else if ( $type === 'both' )
            return $associated_datarecords;

        // If this point is reached, then they submitted an invalid $type value
        throw new ODRException('Invalid argument $type passed to DatatreeInfoService::getAssociatedDatarecords()', 500, 0xcffd5791);
    }


    /**
     * Find all datarecords that are linked to by all children/grandchildren records in $datarecord_ids.
     *
     * @param array $datarecord_ids
     *
     * @return array
     */
    private function getMultipleAllowedDatarecords_worker($datarecord_ids)
    {
        $datarecords_to_return = [];
        $datarecords_to_check = [];

        $query = $this->em->createQuery(
           'SELECT ldr.id AS ldr_id
            FROM ODR\AdminBundle\Entity\DataRecord dr
            JOIN ODR\AdminBundle\Entity\DataRecord cdr WITH cdr.grandparent = dr
            JOIN ODR\AdminBundle\Entity\LinkedDataTree ldt WITH ldt.ancestor = cdr
            JOIN ODR\AdminBundle\Entity\DataRecord ldr WITH ldt.descendant = ldr
            WHERE dr.id IN (:datarecord_ids)
            AND dr.deletedAt IS NULL AND cdr.deletedAt IS NULL
            AND ldt.deletedAt IS NULL AND ldr.deletedAt IS NULL'
        )->setParameters( ['datarecord_ids' => $datarecord_ids] );
        $results = $query->getArrayResult();

        foreach ($results as $num => $result) {
            $ldr_id = $result['ldr_id'];

            $datarecords_to_check[] = $ldr_id;
            $datarecords_to_return[$ldr_id] = 1;
        }

        if ( !empty($datarecords_to_check) ) {
            $tmp = self::getMultipleAllowedDatarecords_worker($datarecords_to_check);

            foreach ($tmp as $dr_id => $num)
                $datarecords_to_return[$dr_id] = 1;
        }

        return $datarecords_to_return;
    }


    /**
     * Recursively finds all datarecords that are linked to by all children/grandchildren records in
     * $datarecord_ids, but ignores those belonging to links where "multiple_allowed" is true.
     *
     * @param array $datarecord_ids
     *
     * @return array
     */
    private function getSingleAllowedDatarecords_worker($datarecord_ids)
    {
        $query = $this->em->createQuery(
           'SELECT d_dt.id AS d_dt_id
            FROM ODR\AdminBundle\Entity\DataRecord a_dr
            JOIN ODR\AdminBundle\Entity\DataType a_dt WITH a_dr.dataType = a_dt
            JOIN ODR\AdminBundle\Entity\DataTree dt WITH dt.ancestor = a_dt
            JOIN ODR\AdminBundle\Entity\DataTreeMeta dtm WITH dtm.dataTree = dt
            JOIN ODR\AdminBundle\Entity\DataType d_dt WITH dt.descendant = d_dt
            WHERE a_dr.id IN (:datarecord_ids) AND dtm.multiple_allowed = 0
            AND a_dr.deletedAt IS NULL AND a_dt.deletedAt IS NULL
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
            AND d_dt.deletedAt IS NULL'
        )->setParameters(
            ['datarecord_ids' => $datarecord_ids]
        );
        $results = $query->getArrayResult();

        $single_allowed_datatype_ids = [];
        foreach ($results as $num => $result) {
            $d_dt_id = $result['d_dt_id'];

            $single_allowed_datatype_ids[] = $d_dt_id;
        }

        $datarecords_to_return = [];
        $datarecords_to_check = [];

        $query = $this->em->createQuery(
           'SELECT ldr.id AS ldr_id
            FROM ODR\AdminBundle\Entity\DataRecord dr
            JOIN ODR\AdminBundle\Entity\DataRecord cdr WITH cdr.grandparent = dr
            JOIN ODR\AdminBundle\Entity\LinkedDataTree ldt WITH ldt.ancestor = cdr
            JOIN ODR\AdminBundle\Entity\DataRecord ldr WITH ldt.descendant = ldr
            WHERE dr.id IN (:datarecord_ids) AND ldr.dataType IN (:single_allowed_datatype_ids)
            AND dr.deletedAt IS NULL AND cdr.deletedAt IS NULL
            AND ldt.deletedAt IS NULL AND ldr.deletedAt IS NULL'
        )->setParameters(
            [
                'datarecord_ids' => $datarecord_ids,
                'single_allowed_datatype_ids' => $single_allowed_datatype_ids
            ]
        );
        $results = $query->getArrayResult();

        foreach ($results as $num => $result) {
            $ldr_id = $result['ldr_id'];

            $datarecords_to_check[] = $ldr_id;
            $datarecords_to_return[$ldr_id] = 1;
        }

        if ( !empty($datarecords_to_check) ) {
            $tmp = self::getSingleAllowedDatarecords_worker($datarecords_to_check);

            foreach ($tmp as $dr_id => $num)
                $datarecords_to_return[$dr_id] = 1;
        }

        return $datarecords_to_return;
    }


    /**
     * Convenience function to return whether the link allows multiple records or not.
     *
     * @param integer $ancestor_datatype_id
     * @param integer $descendant_datatype_id
     * @return boolean
     */
    public function allowsMultipleLinkedDatarecords($ancestor_datatype_id, $descendant_datatype_id, $datatree_array = null)
    {
        // Need the cached datatree array...
        if ( is_null($datatree_array) )
            $datatree_array = self::getDatatreeArray();

        if ( !empty($datatree_array['multiple_allowed'][$descendant_datatype_id] )
            && in_array(
                $ancestor_datatype_id,
                $datatree_array['multiple_allowed'][$descendant_datatype_id]
            )
        ) {
            return true;
        }

        return false;
    }
}
