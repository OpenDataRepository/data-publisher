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
use ODR\AdminBundle\Entity\DataType;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;

class DatatreeInfoService
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
     * @var Logger
     */
    private $logger;


    /**
     * DatatreeInfoService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->logger = $logger;
    }


    /**
     * Utility function to return the DataTree table in array format
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
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id, dtm.is_link AS is_link, dtm.multiple_allowed AS multiple_allowed
            FROM ODRAdminBundle:DataType AS ancestor
            JOIN ODRAdminBundle:DataTree AS dt WITH ancestor = dt.ancestor
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE ancestor.setup_step IN (:setup_step) AND descendant.setup_step IN (:setup_step)
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
            AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('setup_step' => DataType::STATE_VIEWABLE) );
        $results = $query->getArrayResult();

        $datatree_array = array(
            'descendant_of' => array(),
            'linked_from' => array(),
            'multiple_allowed' => array(),
        );
        foreach ($results as $num => $result) {
            $ancestor_id = $result['ancestor_id'];
            $descendant_id = $result['descendant_id'];
            $is_link = $result['is_link'];
            $multiple_allowed = $result['multiple_allowed'];

            if ( !isset($datatree_array['descendant_of'][$ancestor_id]) )
                $datatree_array['descendant_of'][$ancestor_id] = '';

            if ($is_link == 0) {
                $datatree_array['descendant_of'][$descendant_id] = $ancestor_id;
            }
            else {
                if ( !isset($datatree_array['linked_from'][$descendant_id]) )
                    $datatree_array['linked_from'][$descendant_id] = array();

                $datatree_array['linked_from'][$descendant_id][] = $ancestor_id;
            }

            if ($multiple_allowed == 1) {
                if ( !isset($datatree_array['multiple_allowed'][$descendant_id]) )
                    $datatree_array['multiple_allowed'][$descendant_id] = array();

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
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataType AS grandparent WITH dt.grandparent = grandparent
            WHERE dt.setup_step IN (:setup_steps) AND dt.id = grandparent.id
            AND dt.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('setup_steps' => DataType::STATE_VIEWABLE) );
        $results = $query->getArrayResult();

        // AND dt.metadataFor IS NULL
        $top_level_datatypes = array();
        foreach ($results as $result)
            $top_level_datatypes[] = $result['datatype_id'];


        // ----------------------------------------
        // Store the list in the cache and return
        $this->cache_service->set('top_level_datatypes', $top_level_datatypes);
        return $top_level_datatypes;
    }

    // TODO - create something to return top-level templates?


    /**
     * Given the id of a top-level datatype, returns the ids of all other top-level datatypes that
     * need to have their cache entries loaded so that the original top-level datatype can get
     * rendered.
     *
     * @param int $top_level_datatype_id
     *
     * @return array
     */
    public function getAssociatedDatatypes($top_level_datatype_id)
    {
        // Need to locate all linked datatypes for the provided datatype
        $associated_datatypes = $this->cache_service->get('associated_datatypes_for_'.$top_level_datatype_id);
        if ($associated_datatypes == false) {
            // Only want to load the cached datatree array once...
            $datatree_array = self::getDatatreeArray();

            // The end result should always contain the requested top-level datatype id
            $associated_datatypes = array($top_level_datatype_id => 0);
            $datatypes_to_check = array($top_level_datatype_id);
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
                $datatypes_to_check = array();
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

        $child_datatypes = array();

        $datatypes_to_check = $datatype_ids;
        while ( !empty($datatypes_to_check) ) {
            $tmp = array();
            foreach ($datatypes_to_check as $num => $parent_dt_id) {
                $keys = array_keys($datatree_array['descendant_of'], $parent_dt_id, true);
                foreach ($keys as $num => $c_dt_id) {
                    $tmp[$c_dt_id] = 0;
                    $child_datatypes[$c_dt_id] = 0;
                }
            }

            if ($deep)
                $datatypes_to_check = array_keys($tmp);
            else
                $datatypes_to_check = array();
        }

        $child_datatypes = array_keys($child_datatypes);
        return $child_datatypes;
    }


    /**
     * Returns the grandparent ids of all datatypes that link to the given set of datatypes.
     *
     * @param array $datatype_ids
     * @param null|array $datatree_array
     * @param boolean $deep assuming  D links to C links to B links to A, and function is called with {A}
     *                      then if $deep == false, then only find the closest set of linked ancestors
     *                      e.g. getLinkedAncestors() returns {B}
     *                      then if true, then find all possible linked ancestors
     *                      e.g. getLinkedAncestors() returns {B, C, D, ...}
     *
     * @return int[]
     */
    public function getLinkedAncestors($datatype_ids, $datatree_array = null, $deep = false)
    {
        // Need the cached datatype array...
        if ( is_null($datatree_array) )
            $datatree_array = self::getDatatreeArray();

        $linked_ancestors = array();

        $datatypes_to_check = $datatype_ids;
        while ( !empty($datatypes_to_check) ) {
            $tmp = array();
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
                $datatypes_to_check = array();
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
     *                      then if $deep == false, then only find the closest set of linked descendents
     *                      e.g. getLinkedDescendants() returns {B}
     *                      then if true, then find all possible linked descendants
     *                      e.g. getLinkedDescendants() returns {B, C, D, ...}
     *
     * @return int[]
     */
    public function getLinkedDescendants($datatype_ids, $datatree_array = null, $deep = false)
    {
        // Need the cached datatype array...
        if ( is_null($datatree_array) )
            $datatree_array = self::getDatatreeArray();

        $linked_descendants = array();

        $datatypes_to_check = $datatype_ids;
        while ( !empty($datatypes_to_check) ) {
            $tmp = array();
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
                $datatypes_to_check = array();
        }

        $linked_descendants = array_keys($linked_descendants);
        return $linked_descendants;
    }


    /**
     * Returns the ids of all datarecords that need to have their cache entries loaded so the given
     *  $top_level_datarecord_id can be properly rendered.
     *
     * @param int $top_level_datarecord_id
     *
     * @return array
     */
    public function getAssociatedDatarecords($top_level_datarecord_id)
    {
//        $this->logger->debug('DatatreeInfoService: getAssociatedDatarecords: ' . $top_level_datarecord_id);

        // Need to locate all linked datarecords for the provided datarecord
        $associated_datarecords = $this->cache_service->get('associated_datarecords_for_'.$top_level_datarecord_id);
        if ($associated_datarecords == false) {
            $associated_datarecords = self::getAssociatedDatarecords_worker( array($top_level_datarecord_id) );

            // Also need the requested top-level datarecords in here
            $associated_datarecords[$top_level_datarecord_id] = 1;
            // These datarecord ids need to be stored as values instead of keys
            $associated_datarecords = array_keys($associated_datarecords);

            // Save the list of associated datarecords back into the cache
            $this->cache_service->set('associated_datarecords_for_'.$top_level_datarecord_id, $associated_datarecords);
        }

        return $associated_datarecords;
    }


    /**
     * Find all datarecords that are linked to by all children/grandchildren records in $datarecord_ids.
     *
     * @param array $datarecord_ids
     *
     * @return array
     */
    private function getAssociatedDatarecords_worker($datarecord_ids)
    {
        $datarecords_to_return = array();
        $datarecords_to_check = array();

        $query = $this->em->createQuery(
           'SELECT ldr.id AS ldr_id
            FROM ODRAdminBundle:DataRecord dr
            JOIN ODRAdminBundle:DataRecord cdr WITH cdr.grandparent = dr
            JOIN ODRAdminBundle:LinkedDataTree ldt WITH ldt.ancestor = cdr
            JOIN ODRAdminBundle:DataRecord ldr WITH ldt.descendant = ldr
            WHERE dr.id IN (:datarecord_ids)
            AND dr.deletedAt IS NULL AND cdr.deletedAt IS NULL
            AND ldt.deletedAt IS NULL AND ldr.deletedAt IS NULL'
        )->setParameters( array('datarecord_ids' => $datarecord_ids) );
        $results = $query->getArrayResult();

        foreach ($results as $num => $result) {
            $ldr_id = $result['ldr_id'];

            $datarecords_to_check[] = $ldr_id;
            $datarecords_to_return[$ldr_id] = 1;
        }

        if ( !empty($datarecords_to_check) ) {
            $tmp = self::getAssociatedDatarecords_worker($datarecords_to_check);

            foreach ($tmp as $dr_id => $num)
                $datarecords_to_return[$dr_id] = 1;
        }

        return $datarecords_to_return;
    }
}
