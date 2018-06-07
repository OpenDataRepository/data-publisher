<?php

/**
 * Open Data Repository Data Publisher
 * Datarecord Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get and rebuild the cached version of the datarecord array.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
// Utility
use ODR\AdminBundle\Component\Utility\UserUtility;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManager;

// Exceptions
// use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
// use ODR\AdminBundle\Exception\ODRForbiddenException;
// use ODR\AdminBundle\Exception\ODRNotFoundException;
// use ODR\AdminBundle\Exception\ODRNotImplementedException;


class DatarecordInfoService
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
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var ThemeInfoService
     */
    private $theme_service;

    /**
     * @var CsrfTokenManager
     */
    private $token_manager;

    /**
     * @var TokenStorage
     */
    private $token_storage;

    /**
     * @var TwigEngine
     */
    private $templating;

    /**
     * DatarecordInfoService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param PermissionsManagementService $permissions_service
     * @param DatatypeInfoService $datatype_info_service
     * @param ThemeInfoService $theme_info_service
     * @param TwigEngine $templating
     * @param CsrfTokenManager $token_manager
     * @param TokenStorage $token_storage
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        PermissionsManagementService $permissions_service,
        DatatypeInfoService $datatype_info_service,
        ThemeInfoService $theme_info_service,
        TwigEngine $templating,
        CsrfTokenManager $token_manager,
        TokenStorage $token_storage,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->pm_service = $permissions_service;
        $this->dti_service = $datatype_info_service;
        $this->theme_service = $theme_info_service;
        $this->templating = $templating;
        $this->token_manager = $token_manager;
        $this->token_storage = $token_storage;
        $this->logger = $logger;
    }

    /**
     * Loads and returns the cached data array for the requested datarecord.  The returned array
     * contains data of all datarecords with the requested datarecord as their grandparent, as
     * well as any datarecords that are linked to by the requested datarecord or its children.
     *
     * Use self::stackDatarecordArray() to get an array structure where child/linked datarecords
     * are stored "underneath" their parent datarecords.
     *
     * @param integer $grandparent_datarecord_id
     * @param bool $include_links  If true, then the returned array will also contain linked datarecords
     *
     * @return array
     */
    public function getDatarecordArray($grandparent_datarecord_id, $include_links = true)
    {
        $associated_datarecords = array();
        if ($include_links) {
            // Need to locate all linked datarecords for the provided datarecord
            $associated_datarecords = $this->cache_service->get('associated_datarecords_for_'.$grandparent_datarecord_id);
            if ($associated_datarecords == false) {
                $associated_datarecords = self::getAssociatedDatarecords(array($grandparent_datarecord_id));

                // Save the list of associated datarecords back into the cache
                $this->cache_service->set('associated_datarecords_for_'.$grandparent_datarecord_id, $associated_datarecords);
            }
        }
        else {
            // Don't want any datarecords that are linked to from the given grandparent datarecord
            $associated_datarecords[] = $grandparent_datarecord_id;
        }

        // Grab the cached versions of all of the associated datarecords, and store them all at the same level in a single array
        $datarecord_array = array();
        foreach ($associated_datarecords as $num => $dr_id) {
            $datarecord_data = $this->cache_service->get('cached_datarecord_'.$dr_id);
            if ($datarecord_data == false)
                $datarecord_data = self::buildDatarecordData($dr_id);

            foreach ($datarecord_data as $dr_id => $data)
                $datarecord_array[$dr_id] = $data;
        }

        return $datarecord_array;
    }


    /**
     * This function locates all datarecords whose grandparent id is in $grandparent_datarecord_ids,
     * then calls self::getLinkedDatarecords() to locate all datarecords linked to by these datarecords,
     * which calls this function again to locate any datarecords that are linked to by those
     * linked datarecords...
     *
     * The end result is an array of top-level datarecord ids.  Due to recursive shennanigans,
     * these functions don't attempt to cache the results.
     *
     * @param int[] $grandparent_datarecord_ids
     *
     * @return int[]
     */
    public function getAssociatedDatarecords($grandparent_datarecord_ids)
    {
        // Locate all datarecords that are children of the datarecords listed in $grandparent_datarecord_ids
        $query = $this->em->createQuery(
           'SELECT dr.id AS id
            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.grandparent AS grandparent
            WHERE grandparent.id IN (:grandparent_ids)
            AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('grandparent_ids' => $grandparent_datarecord_ids) );
        $results = $query->getArrayResult();

        // Flatten the results array
        $datarecord_ids = array();
        foreach ($results as $result)
            $datarecord_ids[] = $result['id'];

        // Locate all datarecords that are linked to by the datarecords listed in $grandparent_datarecord_ids
        $linked_datarecord_ids = self::getLinkedDatarecords($datarecord_ids);

        // Don't want any duplicate datarecord ids...
        $associated_datarecord_ids = array_unique( array_merge($grandparent_datarecord_ids, $linked_datarecord_ids) );

        return $associated_datarecord_ids;
    }


    /**
     * Builds and returns a list of all datarecords linked to from the provided datarecord ids.
     *
     * @param int[] $ancestor_ids
     *
     * @return int[]
     */
    public function getLinkedDatarecords($ancestor_ids)
    {
        // Locate all datarecords that are linked to from any datarecord listed in $datarecord_ids
        $query = $this->em->createQuery(
           'SELECT descendant.id AS descendant_id
            FROM ODRAdminBundle:LinkedDataTree AS ldt
            JOIN ldt.ancestor AS ancestor
            JOIN ldt.descendant AS descendant
            WHERE ancestor.id IN (:ancestor_ids)
            AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('ancestor_ids' => $ancestor_ids) );
        $results = $query->getArrayResult();

        // Flatten the results array
        $linked_datarecord_ids = array();
        foreach ($results as $result)
            $linked_datarecord_ids[] = $result['descendant_id'];

        // If there were datarecords found, get all of their associated child/linked datarecords
        $associated_datarecord_ids = array();
        if ( count($linked_datarecord_ids) > 0 )
            $associated_datarecord_ids = self::getAssociatedDatarecords($linked_datarecord_ids);

        // Don't want any duplicate datarecord ids...
        $linked_datarecord_ids = array_unique( array_merge($linked_datarecord_ids, $associated_datarecord_ids) );

        return $linked_datarecord_ids;
    }


    /**
     * Runs a single database query to get all non-layout data for a given grandparent datarecord.
     *
     * @param integer $grandparent_datarecord_id
     *
     * @return array
     */
    private function buildDatarecordData($grandparent_datarecord_id)
    {
        // This function is only called when the cache entry doesn't exist

        // Otherwise...get all non-layout data for the requested grandparent datarecord
        $query = $this->em->createQuery(
           'SELECT
               dr, partial drm.{id, publicDate}, partial p_dr.{id}, partial gp_dr.{id},
               partial dr_cb.{id, username, email, firstName, lastName},
               partial dr_ub.{id, username, email, firstName, lastName},

               dt, dtm, partial dt_eif.{id}, partial dt_nf.{id}, partial dt_sf.{id},

               drf, partial df.{id}, partial dfm.{id}, partial ft.{id, typeClass},
               e_f, e_fm, partial e_f_cb.{id, username, email, firstName, lastName},
               e_i, e_im, e_ip, e_ipm, e_is, partial e_ip_cb.{id, username, email, firstName, lastName},

               e_b, e_iv, e_dv, e_lt, e_lvc, e_mvc, e_svc, e_dtv, rs, ro,

               partial e_b_ub.{id, username, email, firstName, lastName},
               partial e_iv_ub.{id, username, email, firstName, lastName},
               partial e_dv_ub.{id, username, email, firstName, lastName},
               partial e_lt_ub.{id, username, email, firstName, lastName},
               partial e_lvc_ub.{id, username, email, firstName, lastName},
               partial e_mvc_ub.{id, username, email, firstName, lastName},
               partial e_svc_ub.{id, username, email, firstName, lastName},
               partial e_dtv_ub.{id, username, email, firstName, lastName},
               partial rs_ub.{id, username, email, firstName, lastName},

               partial cdr.{id}, partial cdr_dt.{id},
               ldt, partial ldr.{id}, partial ldr_dt.{id}

            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.dataRecordMeta AS drm
            LEFT JOIN dr.createdBy AS dr_cb
            LEFT JOIN dr.updatedBy AS dr_ub
            LEFT JOIN dr.parent AS p_dr
            LEFT JOIN dr.grandparent AS gp_dr

            LEFT JOIN dr.dataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dtm.externalIdField AS dt_eif
            LEFT JOIN dtm.nameField AS dt_nf
            LEFT JOIN dtm.sortField AS dt_sf

            LEFT JOIN dr.dataRecordFields AS drf
            LEFT JOIN drf.file AS e_f
            LEFT JOIN e_f.fileMeta AS e_fm
            LEFT JOIN e_f.createdBy AS e_f_cb

            LEFT JOIN drf.image AS e_i
            LEFT JOIN e_i.imageMeta AS e_im
            LEFT JOIN e_i.parent AS e_ip
            LEFT JOIN e_ip.imageMeta AS e_ipm
            LEFT JOIN e_i.imageSize AS e_is
            LEFT JOIN e_ip.createdBy AS e_ip_cb

            LEFT JOIN drf.boolean AS e_b
            LEFT JOIN e_b.updatedBy AS e_b_ub
            LEFT JOIN drf.integerValue AS e_iv
            LEFT JOIN e_iv.updatedBy AS e_iv_ub
            LEFT JOIN drf.decimalValue AS e_dv
            LEFT JOIN e_dv.updatedBy AS e_dv_ub
            LEFT JOIN drf.longText AS e_lt
            LEFT JOIN e_lt.updatedBy AS e_lt_ub
            LEFT JOIN drf.longVarchar AS e_lvc
            LEFT JOIN e_lvc.updatedBy AS e_lvc_ub
            LEFT JOIN drf.mediumVarchar AS e_mvc
            LEFT JOIN e_mvc.updatedBy AS e_mvc_ub
            LEFT JOIN drf.shortVarchar AS e_svc
            LEFT JOIN e_svc.updatedBy AS e_svc_ub
            LEFT JOIN drf.datetimeValue AS e_dtv
            LEFT JOIN e_dtv.updatedBy AS e_dtv_ub
            LEFT JOIN drf.radioSelection AS rs
            LEFT JOIN rs.updatedBy AS rs_ub
            LEFT JOIN rs.radioOption AS ro

            LEFT JOIN drf.dataField AS df
            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN dfm.fieldType AS ft

            LEFT JOIN dr.children AS cdr
            LEFT JOIN cdr.dataType AS cdr_dt

            LEFT JOIN dr.linkedDatarecords AS ldt
            LEFT JOIN ldt.descendant AS ldr
            LEFT JOIN ldr.dataType AS ldr_dt

            WHERE
                dr.grandparent = :grandparent_id
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL
                AND (e_i.id IS NULL OR e_i.original = 0)'
        )->setParameters(array('grandparent_id' => $grandparent_datarecord_id));

        $datarecord_data = $query->getArrayResult();

        // TODO - if $datarecord_data is empty, then $grandparent_datarecord_id was deleted...should this return something special in that case?

        // The datarecordField entry returned by the preceeding query will have quite a few blank
        //  subarrays...all but the following keys should be unset in order to reduce the amount of
        //  memory that php needs to allocate to store a cached datarecord entry...it adds up.
        $drf_keys_to_keep = array(
            'id', 'created',
            'file', 'image'     // keeping these for now because multiple pieces of code assume they exist
        );

        // The entity -> entity_metadata relationships have to be one -> many from a database
        //  perspective, even though there's only supposed to be a single non-deleted entity_metadata
        //  object for each entity.  Therefore, the preceding query generates an array that needs
        //  to be slightly flattened in a few places.
        foreach ($datarecord_data as $dr_num => $dr) {
            // Flatten datarecord_meta
            $drm = $dr['dataRecordMeta'][0];
            $datarecord_data[$dr_num]['dataRecordMeta'] = $drm;
            $datarecord_data[$dr_num]['createdBy'] = UserUtility::cleanUserData( $dr['createdBy'] );
            $datarecord_data[$dr_num]['updatedBy'] = UserUtility::cleanUserData( $dr['updatedBy'] );

            // Store which datafields are used for the datatype's external_id_datafield, name_datafield, and sort_datafield
            $external_id_field = null;
            $name_datafield = null;
            $sort_datafield = null;

            if ( isset($dr['dataType']['dataTypeMeta'][0]['externalIdField']['id']) )
                $external_id_field = $dr['dataType']['dataTypeMeta'][0]['externalIdField']['id'];
            if ( isset($dr['dataType']['dataTypeMeta'][0]['nameField']['id']) )
                $name_datafield = $dr['dataType']['dataTypeMeta'][0]['nameField']['id'];
            if ( isset($dr['dataType']['dataTypeMeta'][0]['sortField']['id']) )
                $sort_datafield = $dr['dataType']['dataTypeMeta'][0]['sortField']['id'];

            $datarecord_data[$dr_num]['externalIdField_value'] = '';
            $datarecord_data[$dr_num]['nameField_value'] = '';
            $datarecord_data[$dr_num]['sortField_value'] = '';

            // Don't want to store the datatype's meta entry
            unset( $datarecord_data[$dr_num]['dataType']['dataTypeMeta'] );

            // Need to store a list of child/linked datarecords by their respective datatype ids
            $child_datarecords = array();
            foreach ($dr['children'] as $child_num => $cdr) {
                $cdr_id = $cdr['id'];
                $cdr_dt_id = $cdr['dataType']['id'];

                // A top-level datarecord is listed as its own parent in the database
                // Don't store it as its own child
                if ( $cdr_id == $dr['id'] )
                    continue;

                if ( !isset($child_datarecords[$cdr_dt_id]) )
                    $child_datarecords[$cdr_dt_id] = array();
                $child_datarecords[$cdr_dt_id][] = $cdr_id;
            }
            foreach ($dr['linkedDatarecords'] as $child_num => $ldt) {
                $ldr_id = $ldt['descendant']['id'];
                $ldr_dt_id = $ldt['descendant']['dataType']['id'];

                if ( !isset($child_datarecords[$ldr_dt_id]) )
                    $child_datarecords[$ldr_dt_id] = array();
                $child_datarecords[$ldr_dt_id][] = $ldr_id;
            }
            $datarecord_data[$dr_num]['children'] = $child_datarecords;
            unset( $datarecord_data[$dr_num]['linkedDatarecords'] );

            // Flatten datafield_meta of each datarecordfield, and organize by datafield id instead
            //  of some random number
            $new_drf_array = array();
            foreach ($dr['dataRecordFields'] as $drf_num => $drf) {

                // Going to delete most of the sub arrays inside $drf that are empty...
                $expected_fieldtype = $drf['dataField']['dataFieldMeta'][0]['fieldType']['typeClass'];
                $expected_fieldtype = lcfirst($expected_fieldtype);
                if ($expected_fieldtype == 'radio')
                    $expected_fieldtype = 'radioSelection';

                $df_id = $drf['dataField']['id'];
                unset( $drf['dataField'] );

                // Flatten file metadata and get rid of encrypt_key
                foreach ($drf['file'] as $file_num => $file) {
                    unset( $drf['file'][$file_num]['encrypt_key'] );

                    $fm = $file['fileMeta'][0];
                    $drf['file'][$file_num]['fileMeta'] = $fm;

                    // Get rid of all private/non-essential information in the createdBy association
                    $drf['file'][$file_num]['createdBy'] = UserUtility::cleanUserData( $drf['file'][$file_num]['createdBy'] );
                }

                // Flatten image metadata
                $ordered_images = array();
                foreach ($drf['image'] as $image_num => $image) {
                    // Get rid of both the thumbnail's and the parent's encrypt keys
                    unset( $image['encrypt_key'] );
                    unset( $image['parent']['encrypt_key'] );

                    unset( $image['imageMeta'] );   // This is a phantom meta entry created for this image's thumbnail
                    $im = $image['parent']['imageMeta'][0];
                    $image['parent']['imageMeta'] = $im;

                    // Get rid of all private/non-essential information in the createdBy association
                    $image['parent']['createdBy'] = UserUtility::cleanUserData( $image['parent']['createdBy'] );

                    $image_id = $image['parent']['id'];
                    $display_order = $image['parent']['imageMeta']['displayorder'];

                    $ordered_images[ $display_order.'_'.$image_id ] = $image;
                }

                // Sort the images and discard the sort key afterwards
                if ( count($ordered_images) > 0 ) {
                    ksort($ordered_images);
                    $ordered_images = array_values($ordered_images);

                    $drf['image'] = $ordered_images;
                }

                // Scrub all user information from the rest of the array
                $keys = array('boolean', 'integerValue', 'decimalValue', 'longText', 'longVarchar', 'mediumVarchar', 'shortVarchar', 'datetimeValue');
                foreach ($keys as $typeclass) {
                    if ( count($drf[$typeclass]) > 0 ) {
                        $drf[$typeclass][0]['updatedBy'] = UserUtility::cleanUserData( $drf[$typeclass][0]['updatedBy'] );

                        // Store the value from this storage entity if it's the one being used for external_id/name/sort datafields
                        if ($external_id_field !== null && $external_id_field == $df_id) {
                            $datarecord_data[$dr_num]['externalIdField_value'] = $drf[$typeclass][0]['value'];
                        }
                        if ($name_datafield !== null && $name_datafield == $df_id) {
                            // Need to ensure this value is a string so php sorting functions don't complain
                            if ($typeclass == 'datetimeValue')
                                $datarecord_data[$dr_num]['nameField_value'] = $drf[$typeclass][0]['value']->format('Y-m-d');
                            else
                                $datarecord_data[$dr_num]['nameField_value'] = $drf[$typeclass][0]['value'];
                        }
                        if ($sort_datafield !== null && $sort_datafield == $df_id) {
                            // Need to ensure this value is a string so php sorting functions don't complain
                            if ($typeclass == 'datetimeValue')
                                $datarecord_data[$dr_num]['sortField_value'] = $drf[$typeclass][0]['value']->format('Y-m-d');
                            else
                                $datarecord_data[$dr_num]['sortField_value'] = $drf[$typeclass][0]['value'];

                            // Also store the typeclass...Integer/Decimal need to use SORT_NUMERIC instead of SORT_NATURAL...
                            $datarecord_data[$dr_num]['sortField_typeclass'] = ucfirst($typeclass);
                        }
                    }
                }

                // Organize radio selections by radio option id
                $new_rs_array = array();
                foreach ($drf['radioSelection'] as $rs_num => $rs) {
                    $rs['updatedBy'] = UserUtility::cleanUserData( $rs['updatedBy'] );

                    $ro_id = $rs['radioOption']['id'];
                    $new_rs_array[$ro_id] = $rs;
                }
                $drf['radioSelection'] = $new_rs_array;

                // Delete everything that isn't strictly needed in this $drf array
                foreach ($drf as $k => $v) {
                    if ( in_array($k, $drf_keys_to_keep) || $k == $expected_fieldtype )
                        continue;

                    // otherwise, delete it
                    unset( $drf[$k] );
                }

                // Store the resulting $drf array by its datafield id
                $new_drf_array[$df_id] = $drf;
            }

            unset( $datarecord_data[$dr_num]['dataRecordFields'] );
            $datarecord_data[$dr_num]['dataRecordFields'] = $new_drf_array;
        }

        // Organize by datarecord id...it's intetionally left flat
        $formatted_datarecord_data = array();
        foreach ($datarecord_data as $num => $dr_data) {
            $dr_id = $dr_data['id'];

            // These two values should default to the datarecord id if empty
            if ( $dr_data['nameField_value'] == '' )
                $dr_data['nameField_value'] = $dr_id;
            if ( $dr_data['sortField_value'] == '' ) {
                $dr_data['sortField_value'] = $dr_id;
                $dr_data['sortField_typeclass'] = '';
            }

            $formatted_datarecord_data[$dr_id] = $dr_data;
        }

        // Save the formatted datarecord data back in the cache, and return it
        $this->cache_service->set('cached_datarecord_'.$grandparent_datarecord_id, $formatted_datarecord_data);
        return $formatted_datarecord_data;
    }


    /**
     * Recursively "inflates" a flattened $datarecord_array so that child/linked datarecords are
     * stored "underneath" their parents/grandparents.
     *
     * @see self::getDatarecordArray()
     *
     * @param array $datarecord_array
     * @param integer $initial_datarecord_id
     *
     * @return array
     */
    public function stackDatarecordArray($datarecord_array, $initial_datarecord_id)
    {
        $current_datarecord = array();
        if ( isset($datarecord_array[$initial_datarecord_id]) ) {
            $current_datarecord = $datarecord_array[$initial_datarecord_id];

            // If this datarecord has children...
            if ( isset($current_datarecord['children']) ) {
                foreach ($current_datarecord['children'] as $dt_id => $dr_list) {

                    // ...stack each child individually
                    $tmp = array();
                    foreach ($dr_list as $num => $dr_id) {
                        if ( isset($datarecord_array[$dr_id]) )
                            $tmp[$dr_id] = self::stackDatarecordArray($datarecord_array, $dr_id);
                    }

                    // ...sort array of child datarecords by their respective sortvalue
                    uasort($tmp, function ($a, $b) {
                        return strnatcmp($a['sortField_value'], $b['sortField_value']);
                    });

                    // ...store child datarecords under their parent
                    $current_datarecord['children'][$dt_id] = $tmp;
                }
            }
        }

        return $current_datarecord;
    }


    /**
     * Marks the specified datarecord (and all its parents) as updated by the given user.
     *
     * @param DataRecord $datarecord
     * @param ODRUser $user
     */
    public function updateDatarecordCacheEntry($datarecord, $user)
    {
        // Whenever an edit is made to a datarecord, each of its parents (if it has any) also need
        //  to be marked as updated
        $dr = $datarecord;
        while ($dr->getId() !== $dr->getParent()->getId()) {
            // Mark this (non-top-level) datarecord as updated by this user
            $dr->setUpdatedBy($user);
            $dr->setUpdated(new \DateTime());
            $this->em->persist($dr);

            // Continue locating parent datarecords...
            $dr = $dr->getParent();
        }

        // $dr is now the grandparent of $datarecord
        $dr->setUpdatedBy($user);
        $dr->setUpdated(new \DateTime());
        $this->em->persist($dr);

        // Save all changes made
        $this->em->flush();


        // Child datarecords don't have their own cached entries, it's all contained within the
        //  cache entry for their top-level datarecord
        $this->cache_service->delete('cached_datarecord_'.$dr->getId());

        // Delete the filtered list of data meant specifically for table themes
        $this->cache_service->delete('cached_table_data_'.$dr->getId());
    }


    /**
     * @param $search_theme_id
     * @param $search_key
     * @param $initial_datarecord_id
     * @param $template_name
     * @param $target_id
     * @return string
     */
    public function GetDisplayData(
        $search_theme_id = null,
        $search_key = null,
        $initial_datarecord_id,
        $template_name,
        $target_id
    ) {
        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->em;
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

        // Load all permissions for this user
        $user = $this->token_storage->getToken()->getUser();
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);
        $datatype_permissions = $user_permissions['datatypes'];
        $datafield_permissions = $user_permissions['datafields'];


        // ----------------------------------------
        // Load required objects based on parameters
        $is_top_level = 1;

        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($initial_datarecord_id);
        $grandparent_datarecord = $datarecord->getGrandparent();
        $grandparent_datatype = $grandparent_datarecord->getDataType();

        /** @var Theme $top_level_theme */
        $top_level_theme_id = $this->theme_service->getPreferredTheme($user, $grandparent_datatype->getId(), 'master');
        $top_level_theme = $repo_theme->find($top_level_theme_id);

        /** @var DataType $datatype */
        $datatype = null;
        /** @var Theme $theme */
        $theme = null;

        /** @var DataFields|null $datafield */
        $datafield = null;
        $datafield_id = null;


        // Don't allow a child reload request for a top-level datatype
        if ($template_name == 'child' && $datarecord->getDataType()->getId() == $target_id)
            $template_name = 'default';


        //  TODO Theme can be null - no "else"
        if ($template_name == 'default') {
            $datatype = $grandparent_datatype;
            $theme = $top_level_theme;

//            // TODO - May not necessarily be a render request for a top-level datarecord...
//            $datatype = $datarecord->getDataType();
//            if ( $grandparent_datarecord->getId() !== $datarecord->getId() )
//                $is_top_level = 0;
        }
        else if ($template_name == 'child') {
            $is_top_level = 0;

            $datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'parentTheme' => $top_level_theme->getId()) );      // TODO - this likely isn't going to work where linked datatypes are involved
        }
        else if ($template_name == 'datafield') {
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($target_id);
            $datafield_id = $target_id;

            $datatype = $datafield->getDataType();
            $theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'parentTheme' => $top_level_theme->getId()) );      // TODO - this likely isn't going to work where linked datatypes are involved
        }


        // ----------------------------------------
        // Grab all datarecords "associated" with the desired datarecord...
        $include_links = true;
        $datarecord_array = self::getDatarecordArray($grandparent_datarecord->getId(), $include_links);

        // Grab all datatypes associated with the desired datarecord
        $datatype_array = $this->dti_service->getDatatypeArray($grandparent_datatype->getId(), $include_links);

        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // Also need the theme array...
        $theme_array = $this->theme_service->getThemeArray($top_level_theme->getId());


        // ----------------------------------------
        // "Inflate" the currently flattened $datarecord_array and $datatype_array...needed so that render plugins for a datatype can also correctly render that datatype's child/linked datatypes
        $stacked_datarecord_array[ $datarecord->getId() ] = self::stackDatarecordArray($datarecord_array, $datarecord->getId());
        $stacked_datatype_array[ $datatype->getId() ] = $this->dti_service->stackDatatypeArray($datatype_array, $datatype->getId());
        $stacked_theme_array[ $theme->getId() ] = $this->theme_service->stackThemeArray($theme_array, $theme->getId());


        // ----------------------------------------
        // Render the requested version of this page

        $html = '';
        if ($template_name == 'default') {

            // ----------------------------------------
            // Need to determine ids and names of datatypes this datarecord can link to
            $query = $em->createQuery(
                'SELECT
                  dt, dtm,
                  ancestor, ancestor_meta,
                  descendant, descendant_meta

                FROM ODRAdminBundle:DataTree AS dt
                JOIN dt.dataTreeMeta AS dtm

                JOIN dt.ancestor AS ancestor
                JOIN ancestor.dataTypeMeta AS ancestor_meta
                JOIN dt.descendant AS descendant
                JOIN descendant.dataTypeMeta AS descendant_meta

                WHERE dtm.is_link = 1 AND (ancestor.id = :datatype_id OR descendant.id = :datatype_id)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
                AND ancestor.deletedAt IS NULL AND ancestor_meta.deletedAt IS NULL
                AND descendant.deletedAt IS NULL AND descendant_meta.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId()) );
            $results = $query->getArrayResult();
//exit( '<pre>'.print_r($results, true).'</pre>' );


            // Organize the linked datatypes into arrays
            $linked_datatype_ancestors = array();
            $linked_datatype_descendants = array();
            // Also store which datatype can't be linked to because they lack a search_results theme
            $disabled_datatype_links = array();

            foreach ($results as $num => $dt) {
                $ancestor_id = $dt['ancestor']['id'];
                $descendant_id = $dt['descendant']['id'];

                if ($ancestor_id == $datatype->getId() ) {
                    $descendant = $dt['descendant'];
                    $descendant['dataTypeMeta'] = $dt['descendant']['dataTypeMeta'][0];

                    // TODO Fix search to always work - auto-create templates
                    /*
                    if ( $descendant['setup_step'] == DataType::STATE_OPERATIONAL )
                        $linked_datatype_descendants[$descendant_id] = $descendant;
                    else
                        $disabled_datatype_links[$descendant_id] = $descendant;
                    */
                    $linked_datatype_descendants[$descendant_id] = $descendant;
                }
                else if ($descendant_id == $datatype->getId() ) {
                    $ancestor = $dt['ancestor'];
                    $ancestor['dataTypeMeta'] = $dt['ancestor']['dataTypeMeta'][0];

                    // TODO Fix search to always work - auto-create templates
                    /*
                    if ( $ancestor['setup_step'] == DataType::STATE_OPERATIONAL )
                        $linked_datatype_ancestors[$ancestor_id] = $ancestor;
                    else
                        $disabled_datatype_links[$ancestor_id] = $ancestor;
                    */
                    $linked_datatype_ancestors[$ancestor_id] = $ancestor;
                }
            }

            // ----------------------------------------
            // Generate a csrf token for each of the datarecord/datafield pairs
            $token_list = self::generateCSRFTokens($datatype_array, $datarecord_array);

            $html = $this->templating->render(
                'ODRAdminBundle:Edit:edit_ajax.html.twig',
                array(
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $search_key,

                    'datatype_array' => $stacked_datatype_array,
                    'datarecord_array' => $stacked_datarecord_array,
                    'theme_array' => $stacked_theme_array,

                    'initial_datatype_id' => $datatype->getId(),
                    'initial_datarecord_id' => $datarecord->getId(),
                    'initial_theme_id' => $theme->getId(),

                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'linked_datatype_ancestors' => $linked_datatype_ancestors,
                    'linked_datatype_descendants' => $linked_datatype_descendants,
                    'disabled_datatype_links' => $disabled_datatype_links,

                    'is_top_level' => $is_top_level,
                    'token_list' => $token_list,
                )
            );
        }
        else if ($template_name == 'child') {

            // Find the ThemeDatatype entry that contains the child datatype getting reloaded
            $theme_datatype = null;
            foreach ($theme_array as $t_id => $t) {
                foreach ($t['themeElements'] as $te_num => $te) {
                    if ( isset($te['themeDataType']) ) {
                        foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                            if ( $tdt['dataType']['id'] == $datatype->getId() && $tdt['childTheme']['id'] == $theme->getId() )
                                $theme_datatype = $tdt;
                        }
                    }
                }
            }

            if ($theme_datatype == null)
                throw new ODRException('Unable to locate theme_datatype entry for child datatype '.$datatype->getId());

            $is_link = $theme_datatype['is_link'];
            $display_type = $theme_datatype['display_type'];
            $multiple_allowed = $theme_datatype['multiple_allowed'];

            // Generate a csrf token for each of the datarecord/datafield pairs
            $token_list = self::generateCSRFTokens($datatype_array, $datarecord_array);

            $html = $this->templating->render(
                'ODRAdminBundle:Edit:edit_childtype_reload.html.twig',
                array(
                    'datatype_array' => $stacked_datatype_array,
                    'datarecord_array' => $stacked_datarecord_array,
                    'theme_array' => $stacked_theme_array,

                    'target_datatype_id' => $datatype->getId(),
                    'parent_datarecord_id' => $datarecord->getId(),
                    'target_theme_id' => $theme->getId(),

                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'is_top_level' => 0,
                    'is_link' => $is_link,
                    'display_type' => $display_type,
                    'multiple_allowed' => $multiple_allowed,

                    'token_list' => $token_list,
                )
            );
        }
        else if ($template_name == 'datafield') {

            // Extract all needed arrays from $datatype_array and $datarecord_array
            $datatype = $datatype_array[ $datatype->getId() ];
            $datarecord = $datarecord_array[ $initial_datarecord_id ];

            $datafield = null;
            if ( isset($datatype['dataFields'][$datafield_id]) )
                $datafield = $datatype['dataFields'][$datafield_id];

            if ( $datafield == null )
                throw new ODRException('Unable to locate array entry for datafield '.$datafield_id);

            // Generate a csrf token for each of the datarecord/datafield pairs
            $token_list = self::generateCSRFTokens($datatype_array, $datarecord_array);

            $html = $this->templating->render(
                'ODRAdminBundle:Edit:edit_datafield.html.twig',
                array(
                    'datatype' => $datatype,
                    'datarecord' => $datarecord,
                    'datafield' => $datafield,

                    'force_image_reload' => true,

                    'token_list' => $token_list,
                )
            );
        }

        return $html;
    }

    /**
     * Creates and persists a new DataRecord entity.
     *
     * @param User $user         The user requesting the creation of this entity
     * @param DataType $datatype
     *
     * @return DataRecord
     */
    public function addDataRecord($user, $datatype)
    {
        // Initial create
        $datarecord = new DataRecord();

        $datarecord->setDataType($datatype);
        $datarecord->setCreatedBy($user);
        $datarecord->setUpdatedBy($user);

        $datarecord->setProvisioned(true);  // Prevent most areas of the site from doing anything with this datarecord...whatever created this datarecord needs to eventually set this to false

        $this->em->persist($datarecord);
        $this->em->flush();
        $this->em->refresh($datarecord);

        $datarecord_meta = new DataRecordMeta();
        $datarecord_meta->setDataRecord($datarecord);
        $datarecord_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

        $datarecord_meta->setCreatedBy($user);
        $datarecord_meta->setUpdatedBy($user);

        $datarecord->addDataRecordMetum($datarecord_meta);
        $this->em->persist($datarecord_meta);

        return $datarecord;
    }

    /**
     * Generates a CSRF token for every datarecord/datafield pair in the provided arrays.
     *
     * @param array $datatype_array    @see parent::getDatatypeData()
     * @param array $datarecord_array  @see parent::getDatarecordData()
     *
     * @return array
     */
    public function generateCSRFTokens($datatype_array, $datarecord_array)
    {
        $token_list = array();

        foreach ($datarecord_array as $dr_id => $dr) {
            if ( !isset($token_list[$dr_id]) )
                $token_list[$dr_id] = array();

            $dt_id = $dr['dataType']['id'];

            if ( !isset($datatype_array[$dt_id]) )
                continue;

            foreach ($datatype_array[$dt_id]['dataFields'] as $df_id => $df) {

                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];

                $token_id = $typeclass.'Form_'.$dr_id.'_'.$df_id;
                $token_list[$dr_id][$df_id] = $this->token_manager->getToken($token_id)->getValue();

            }
        }

        return $token_list;
    }
}
