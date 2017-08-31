<?php

/**
 * Open Data Repository Data Publisher
 * Datarecord Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get and rebuild the cached version of the datatype array, as well
 * as several other utility functions related to lists of datatypes.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class DatatypeInfoService
{

    /**
     * @var string
     */
    private $environment;

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
     * DatatypeInfoService constructor.
     *
     * @param $environment
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param Logger $logger
     */
    public function __construct($environment, EntityManager $entity_manager, CacheService $cache_service, Logger $logger)
    {
        $this->environment = $environment;
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->logger = $logger;
    }


    /**
     * Returns an array of top-level datatype ids.
     *
     * @return int[]
     */
    public function getTopLevelDatatypes()
    {
        // ----------------------------------------
        // Always bypass cache if in dev mode?
        $force_rebuild = false;
        //if ($this->environment == 'dev')
            //$force_rebuild = true;

        // If list of top level datatypes exists in cache and user isn't demanding a fresh version, return that
        $top_level_datatypes = $this->cache_service->get('top_level_datatypes');
        if ( $top_level_datatypes !== false && count($top_level_datatypes) > 0 && !$force_rebuild)
            return $top_level_datatypes;


        // ----------------------------------------
        // Otherwise, rebuild the list of top-level datatypes
        $query = $this->em->createQuery(
           'SELECT dt.id AS datatype_id
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $all_datatypes = array();
        foreach ($results as $num => $result)
            $all_datatypes[] = $result['datatype_id'];

        // Get all datatypes that are ready to view
        $query = $this->em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
            FROM ODRAdminBundle:DataTree AS dt
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE dtm.is_link = 0 AND ancestor.setup_step IN (:setup_steps) AND descendant.setup_step IN (:setup_steps)
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('setup_steps' => DataType::STATE_VIEWABLE) );

        $results = $query->getArrayResult();

        $parent_of = array();
        foreach ($results as $num => $result)
            $parent_of[ $result['descendant_id'] ] = $result['ancestor_id'];

        $top_level_datatypes = array();
        foreach ($all_datatypes as $datatype_id) {
            if ( !isset($parent_of[$datatype_id]) )
                $top_level_datatypes[] = $datatype_id;
        }


        // ----------------------------------------
        // Store the list in the cache and return
        $this->cache_service->set('top_level_datatypes', $top_level_datatypes);
        return $top_level_datatypes;
    }


    /**
     * Returns the id of the grandparent of the given datatype.
     *
     * @param integer $initial_datatype_id
     *
     * @return integer
     */
    public function getGrandparentDatatypeId($initial_datatype_id)
    {
        $datatree_array = self::getDatatreeArray();

        $grandparent_datatype_id = $initial_datatype_id;
        while( isset($datatree_array['descendant_of'][$grandparent_datatype_id]) && $datatree_array['descendant_of'][$grandparent_datatype_id] !== '' )
            $grandparent_datatype_id = $datatree_array['descendant_of'][$grandparent_datatype_id];

        return $grandparent_datatype_id;
    }


    /**
     * Utility function to returns the DataTree table in array format
     *
     * @param boolean $force_rebuild
     *
     * @return array
     */
    public function getDatatreeArray($force_rebuild = false)
    {
        // If datatree data exists in cache and user isn't demanding a fresh version, return that
        $datatree_array = $this->cache_service->get('cached_datatree_array');
        if ( $datatree_array !== false && count($datatree_array) > 0 && !$force_rebuild)
            return $datatree_array;


        // Otherwise...get all the datatree data
        $query = $this->em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id, dtm.is_link AS is_link, dtm.multiple_allowed AS multiple_allowed
            FROM ODRAdminBundle:DataType AS ancestor
            JOIN ODRAdminBundle:DataTree AS dt WITH ancestor = dt.ancestor
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE ancestor.setup_step IN (:setup_step) AND descendant.setup_step IN (:setup_step)
            AND ancestor.deletedAt IS NULL AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND descendant.deletedAt IS NULL'
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
     * Builds and returns a list of all child and linked datatype ids related to the given datatype id.
     *
     * @param int[] $datatype_ids
     * @param boolean $include_links
     *
     * @return int[]
     */
    public function getAssociatedDatatypes($datatype_ids, $include_links = true)
    {
        // Locate all datatypes that are either children of or linked to the datatypes in $datatype_ids
        $results = array();
        if ($include_links) {
            $query = $this->em->createQuery(
               'SELECT descendant.id AS descendant_id
                FROM ODRAdminBundle:DataTree AS dt
                LEFT JOIN dt.dataTreeMeta AS dtm
                LEFT JOIN dt.ancestor AS ancestor
                LEFT JOIN dt.descendant AS descendant
                WHERE ancestor.id IN (:ancestor_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('ancestor_ids' => $datatype_ids) );
            $results = $query->getArrayResult();
        }
        else {
            $query = $this->em->createQuery(
               'SELECT descendant.id AS descendant_id
                FROM ODRAdminBundle:DataTree AS dt
                LEFT JOIN dt.dataTreeMeta AS dtm
                LEFT JOIN dt.ancestor AS ancestor
                LEFT JOIN dt.descendant AS descendant
                WHERE dtm.is_link = :is_link AND ancestor.id IN (:ancestor_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('is_link' => 0, 'ancestor_ids' => $datatype_ids) );
            $results = $query->getArrayResult();
        }

        // Flatten the resulting array...
        $child_datatype_ids = array();
        foreach ($results as $num => $result)
            $child_datatype_ids[] = $result['descendant_id'];

        // If child datatypes were found, also see if those child datatypes have children of their own
        if ( count($child_datatype_ids) > 0 )
            $child_datatype_ids = array_merge( $child_datatype_ids, self::getAssociatedDatatypes($child_datatype_ids, $include_links) );

        // Return an array of the requested datatype ids and their children
        $associated_datatypes = array_unique( array_merge($child_datatype_ids, $datatype_ids) );
        return $associated_datatypes;
    }


    /**
     * Loads and returns a cached data array for all datatypes of all datarecords in $datarecord array.
     * Use self::stackDatatypeArray() to get an array structure where child datatypes are stored "underneath" their
     * parent datatypes.
     *
     * @param array $datarecord_array
     *
     * @return array
     */
    public function getDatatypeArrayByDatarecords($datarecord_array, $parent_theme_id = null)
    {
        // Always bypass cache if in dev mode?
        $force_rebuild = false;
        //if ($this->environment == 'dev')
            //$force_rebuild = true;


        // ----------------------------------------
        // Grab all datatypes associated with the desired datarecord
        $associated_datatypes = array();
        foreach ($datarecord_array as $dr_id => $dr) {
            $dt_id = $dr['dataType']['id'];

            if ( !in_array($dt_id, $associated_datatypes) )
                $associated_datatypes[] = $dt_id;
        }

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = $this->cache_service->get('cached_datatype_'.$dt_id);
            if ($force_rebuild || $datatype_data == false)
                $datatype_data = self::buildDatatypeData($dt_id, $force_rebuild, $parent_theme_id);

            foreach ($datatype_data as $local_dt_id => $data)
                $datatype_array[$local_dt_id] = $data;
        }

        return $datatype_array;
    }


    /**
     * Loads and returns a cached data array for the specified datatype ids.
     * Use self::stackDatatypeArray() to get an array structure where child datatypes are stored "underneath" their
     * parent datatypes.
     *
     * @param int[] $datatype_ids
     *
     * @return array
     */
    public function getDatatypeArray($datatype_ids, $parent_theme_id = null)
    {
        // Always bypass cache if in dev mode?
        $force_rebuild = false;
        //if ($this->environment == 'dev')
            //$force_rebuild = true;

        $datatype_array = array();
        foreach ($datatype_ids as $num => $dt_id) {
            if($parent_theme_id == null) {
                $datatype_data = $this->cache_service->get('cached_datatype_'.$dt_id.'_default');
            }
            else {
                $datatype_data = $this->cache_service->get('cached_datatype_'.$dt_id.'_'.$parent_theme_id);
            }
            if ($force_rebuild || $datatype_data == false)
                $datatype_data = self::buildDatatypeData($dt_id, $force_rebuild, $parent_theme_id);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

        return $datatype_array;
    }


    /**
     * Gets all layout information required for the given datatype in array format
     * These should only store default themes when no theme parameter is sent.  When a
     * theme id is sent, a specific theme should be loaded.
     *
     * @param integer $datatype_id
     * @param boolean $force_rebuild
     *
     * @return array
     */
    private function buildDatatypeData($datatype_id, $force_rebuild = false, $parent_theme_id = null)
    {
/*
        $timing = true;
        $timing = false;

        $t0 = $t1 = $t2 = null;
        if ($timing)
            $t0 = microtime(true);
*/
        // If datatype data exists in cache and user isn't demanding a fresh version, return that
        if($parent_theme_id == null) {
            $cached_datatype_data = $this->cache_service->get('cached_datatype_'.$datatype_id.'_default');
        }
        else {
            $cached_datatype_data = $this->cache_service->get('cached_datatype_'.$datatype_id.'_'.$parent_theme_id);
        }
        if ( $cached_datatype_data !== false && count($cached_datatype_data) > 0 && !$force_rebuild)
            return $cached_datatype_data;


        // Otherwise...going to need the datatree array
        $datatree_array = self::getDatatreeArray($force_rebuild);

        // Get all non-layout data for the requested datatype
        $query_txt = 'SELECT
                t, pt, st, tm,
                dt, dtm, dt_rp, dt_rpi, dt_rpo, dt_rpm, dt_rpf, dt_rpm_df,
                te, tem,
                tdf, df, ro, rom,
                dfm, ft, df_rp, df_rpi, df_rpo, df_rpm,
                tdt, c_dt

            FROM ODRAdminBundle:DataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm

            LEFT JOIN dt.themes AS t
            LEFT JOIN t.parentTheme AS pt
            LEFT JOIN t.sourceTheme AS st
            LEFT JOIN t.themeMeta AS tm

            LEFT JOIN dtm.renderPlugin AS dt_rp
            LEFT JOIN dt_rp.renderPluginInstance AS dt_rpi WITH (dt_rpi.dataType = dt)
            LEFT JOIN dt_rpi.renderPluginOptions AS dt_rpo
            LEFT JOIN dt_rpi.renderPluginMap AS dt_rpm
            LEFT JOIN dt_rpm.renderPluginFields AS dt_rpf
            LEFT JOIN dt_rpm.dataField AS dt_rpm_df

            LEFT JOIN t.themeElements AS te
            LEFT JOIN te.themeElementMeta AS tem

            LEFT JOIN te.themeDataFields AS tdf
            LEFT JOIN tdf.dataField AS df
            LEFT JOIN df.radioOptions AS ro
            LEFT JOIN ro.radioOptionMeta AS rom

            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN dfm.fieldType AS ft

            LEFT JOIN dfm.renderPlugin AS df_rp
            LEFT JOIN df_rp.renderPluginInstance AS df_rpi WITH (df_rpi.dataField = df)
            LEFT JOIN df_rpi.renderPluginOptions AS df_rpo
            LEFT JOIN df_rpi.renderPluginMap AS df_rpm

            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.dataType AS c_dt

            WHERE
                dt.id = :datatype_id
                AND t.deletedAt IS NULL AND dt.deletedAt IS NULL AND te.deletedAt IS NULL';

        if($parent_theme_id == null) {
            // These are the default/system themes for the datatype
            // TODO All default themes must be public.  Need to refactor for this change.
            $query_txt .= ' AND (pt.id IS NULL OR (tm.isDefault = 1 and (tm.public IS NOT NULL AND tm.public < CURRENT_TIMESTAMP())) ) ';
        }
        else {
            // Note: two themes could have the same source theme, but no two top-level
            // themes could have the same parent.  Only child themes could have the same
            // parent theme
            $query_txt .= " AND pt.id = :parent_theme_id";
        }

        $query_txt .= ' ORDER BY dt.id, t.id, tem.displayOrder, te.id, tdf.displayOrder, df.id, rom.displayOrder, ro.id';

        if($parent_theme_id == null) {
            $query = $this->em->createQuery($query_txt)->setParameters(array('datatype_id' => $datatype_id));
        }
        else {
            $query = $this->em->createQuery($query_txt)->setParameters(array('datatype_id' => $datatype_id, 'parent_theme_id' => $parent_theme_id));
        }

        $datatype_data = $query->getArrayResult();
/*
        if ($timing) {
            $t1 = microtime(true);
            $diff = $t1 - $t0;
            print 'buildDatatypeData('.$datatype_id.')'."\n".'query execution in: '.$diff."\n";
        }
*/
        // The entity -> entity_metadata relationships have to be one -> many from a database perspective,
        // even though there's only supposed to be a single non-deleted entity_metadata object for each entity
        // Therefore, the preceding query generates an array that needs to be slightly flattened in a few places
        foreach ($datatype_data as $dt_num => $dt) {
            // Flatten datatype meta
            $dtm = $dt['dataTypeMeta'][0];
            $datatype_data[$dt_num]['dataTypeMeta'] = $dtm;

            // Flatten theme_meta of each theme, and organize by theme id instead of a random number
            $new_theme_array = array();
            foreach ($dt['themes'] as $t_num => $theme) {
                $theme_id = $theme['id'];

                $tm = $theme['themeMeta'][0];
                $theme['themeMeta'] = $tm;

                // Flatten theme_element_meta of each theme_element
                foreach ($theme['themeElements'] as $te_num => $te) {
                    $tem = $te['themeElementMeta'][0];
                    $theme['themeElements'][$te_num]['themeElementMeta'] = $tem;

                    // Flatten datafield_meta of each datafield
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        $dfm = $tdf['dataField']['dataFieldMeta'][0];
                        $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['dataFieldMeta'] = $dfm;

                        // Flatten radio options if it exists
                        foreach ($tdf['dataField']['radioOptions'] as $ro_num => $ro) {
                            $rom = $ro['radioOptionMeta'][0];
                            $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['radioOptions'][$ro_num]['radioOptionMeta'] = $rom;
                        }
                        if ( count($tdf['dataField']['radioOptions']) == 0 )
                            unset( $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['radioOptions'] );
                    }

                    // Attach the is_link property to each of the theme_datatype entries
                    foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                        $child_datatype_id = $tdt['dataType']['id'];

                        if ( isset($datatree_array['linked_from'][$child_datatype_id]) && in_array($datatype_id, $datatree_array['linked_from'][$child_datatype_id]) )
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['is_link'] = 1;
                        else
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['is_link'] = 0;

                        if ( isset($datatree_array['multiple_allowed'][$child_datatype_id]) && in_array($datatype_id, $datatree_array['multiple_allowed'][$child_datatype_id]) )
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['multiple_allowed'] = 1;
                        else
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['multiple_allowed'] = 0;
                    }

                    // Easier on twig if these arrays simply don't exist if nothing is in them...
                    if ( count($te['themeDataFields']) == 0 )
                        unset( $theme['themeElements'][$te_num]['themeDataFields'] );
                    if ( count($te['themeDataType']) == 0 )
                        unset( $theme['themeElements'][$te_num]['themeDataType'] );
                }

                $new_theme_array[$theme_id] = $theme;
            }

            unset( $datatype_data[$dt_num]['themes'] );
            $datatype_data[$dt_num]['themes'] = $new_theme_array;
        }

        // Organize by datatype id
        $formatted_datatype_data = array();
        foreach ($datatype_data as $num => $dt_data) {
            $dt_id = $dt_data['id'];

            $formatted_datatype_data[$dt_id] = $dt_data;
        }
/*
        if ($timing) {
            $t1 = microtime(true);
            $diff = $t2 - $t1;
            print 'buildDatatypeData('.$datatype_id.')'."\n".'array formatted in: '.$diff."\n";
        }
*/
        // Save the formatted datarecord data back in the cache, and return it
        if($parent_theme_id == null) {
            $this->cache_service->set('cached_datatype_'.$dt_id.'_default', $formatted_datatype_data);
        }
        else {
            $this->cache_service->set('cached_datatype_'.$dt_id.'_'.$parent_theme_id, $formatted_datatype_data);
        }
        return $formatted_datatype_data;
    }


    /**
     * "Inflates" the normally flattened $datatype_array...
     *
     * @param array $datatype_array
     * @param integer $initial_datatype_id
     * @param integer $theme_id
     * @param integer $parent_theme_id
     *
     * @return array
     */
    public function stackDatatypeArray($datatype_array, $initial_datatype_id, $theme_id, $parent_theme_id = null)
    {
        $current_datatype = array();
        if ( isset($datatype_array[$initial_datatype_id]) ) {
            $current_datatype = $datatype_array[$initial_datatype_id];

            // Check if parent theme is set
            if( isset($current_datatype['themes'][$theme_id]['parentTheme'])
                && $current_datatype['themes'][$theme_id]['parentTheme']['id'] > 0
            ) {
                $parent_theme_id = $current_datatype['themes'][$theme_id]['parentTheme']['id'];
            }

            // Foreach theme element in this theme...
            foreach ($current_datatype['themes'][$theme_id]['themeElements'] as $num => $theme_element) {
                // ...if this theme element contains a child datatype...
                if ( isset($theme_element['themeDataType']) ) {
                    $theme_datatype = $theme_element['themeDataType'][0];
                    $child_datatype_id = $theme_datatype['dataType']['id'];

                    $tmp = array();
                    if ( isset($datatype_array[$child_datatype_id]) ) {

                        $child_theme_id = '';
                        foreach ($datatype_array[$child_datatype_id]['themes'] as $t_id => $t) {
                            if( isset($t['parentTheme'])
                                && $t['parentTheme']['id'] != null
                                && $t['parentTheme']['id'] == $parent_theme_id
                            ) {
                                $child_theme_id = $t_id;
                            }
                            else if ( $t['themeType'] == 'master'
                                && $parent_theme_id == null
                            ) {
                                $child_theme_id = $t_id;
                            }
                        }

                        // Stack each child datatype individually
                        $tmp[$child_datatype_id] = self::stackDatatypeArray($datatype_array, $child_datatype_id, $child_theme_id, $parent_theme_id);
                    }

                    // ...store child datatypes under their parent
                    $current_datatype['themes'][$theme_id]['themeElements'][$num]['themeDataType'][0]['dataType'] = $tmp;
                }
            }
        }

        return $current_datatype;
    }
}
