<?php

/**
 * Open Data Repository Data Publisher
 * Entity Deletion Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Stores the code for ODR entities that require multiple database queries to properly delete.
 *
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class EntityDeletionService
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
     * @var DatafieldInfoService
     */
    private $dfi_service;

    /**
     * @var DatarecordInfoService
     */
    private $dri_service;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var EntityMetaModifyService
     */
    private $emm_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var SearchCacheService
     */
    private $search_cache_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var TrackedJobService
     */
    private $tj_service;

    /**
     * @var ThemeInfoService
     */
    private $ti_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * EntityDeletionService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatafieldInfoService $datafield_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param DatatypeInfoService $datatype_info_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param PermissionsManagementService $permissions_management_service
     * @param SearchCacheService $search_cache_service
     * @param SearchService $search_service
     * @param TrackedJobService $tracked_job_service
     * @param ThemeInfoService $theme_info_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatafieldInfoService $datafield_info_service,
        DatarecordInfoService $datarecord_info_service,
        DatatypeInfoService $datatype_info_service,
        EntityMetaModifyService $entity_meta_modify_service,
        PermissionsManagementService $permissions_management_service,
        SearchCacheService $search_cache_service,
        SearchService $search_service,
        TrackedJobService $tracked_job_service,
        ThemeInfoService $theme_info_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dfi_service = $datafield_info_service;
        $this->dri_service = $datarecord_info_service;
        $this->dti_service = $datatype_info_service;
        $this->emm_service = $entity_meta_modify_service;
        $this->pm_service = $permissions_management_service;
        $this->search_cache_service = $search_cache_service;
        $this->search_service = $search_service;
        $this->tj_service = $tracked_job_service;
        $this->ti_service = $theme_info_service;
        $this->logger = $logger;
    }


    /**
     * Deletes a datafield.
     *
     * @param DataFields $datafield
     * @param ODRUser $user
     *
     * @throws \Exception
     */
    public function deleteDatafield($datafield, $user)
    {
        $conn = null;

        try {
            // Going to need these later...
            $datatype = $datafield->getDataType();
            $grandparent_datatype = $datatype->getGrandparent();

            // --------------------
            // Ensure user has permissions to be doing this
            if (!$this->pm_service->isDatatypeAdmin($user, $datatype))
                throw new ODRForbiddenException();
            // --------------------


            // Check that the datafield isn't being used for something else before deleting it
            $datatype_array = $this->dti_service->getDatatypeArray($grandparent_datatype->getId(), false);    // don't want links
            $props = $this->dfi_service->canDeleteDatafield($datatype_array, $datatype->getId(), $datafield->getId());
            if ( !$props['can_delete'] )
                throw new ODRBadRequestException( $props['delete_message'] );


            // Also prevent a datafield from being deleted if certain jobs are in progress
            $restricted_jobs = array('mass_edit', 'migrate', 'csv_export', 'csv_import_validate', 'csv_import');
            $this->tj_service->checkActiveJobs($datafield, $restricted_jobs, "Unable to delete this datafield");


            // ----------------------------------------
            // Save which themes are going to get theme_datafield entries deleted
            $query = $this->em->createQuery(
               'SELECT t
                FROM ODRAdminBundle:ThemeDataField AS tdf
                JOIN ODRAdminBundle:ThemeElement AS te WITH tdf.themeElement = te
                JOIN ODRAdminBundle:Theme AS t WITH te.theme = t
                WHERE tdf.dataField = :datafield
                AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
            )->setParameters(array('datafield' => $datafield->getId()));
            $all_datafield_themes = $query->getResult();
            /** @var Theme[] $all_datafield_themes */

            // Determine which groups will be affected by the deletion of this datafield
            $query = $this->em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                JOIN ODRAdminBundle:Group AS g WITH gdfp.group = g
                WHERE gdfp.dataField = :datafield
                AND gdfp.deletedAt IS NULL AND g.deletedAt IS NULL'
            )->setParameters(array('datafield' => $datafield->getId()));
            $all_affected_groups = $query->getArrayResult();
//print '<pre>'.print_r($all_affected_groups, true).'</pre>';  //exit();

            // Save which users will need to have their permissions entries cleared since the
            //  groups got modified
            $query = $this->em->createQuery(
               'SELECT u.id AS user_id
                FROM ODRAdminBundle:Group AS g
                JOIN ODRAdminBundle:UserGroup AS ug WITH ug.group = g
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE g.id IN (:groups)
                AND g.deletedAt IS NULL AND ug.deletedAt IS NULL'
            )->setParameters(array('groups' => $all_affected_groups));
            $all_affected_users = $query->getArrayResult();
//print '<pre>'.print_r($all_affected_users, true).'</pre>'; exit();

            // Need to separately locate all super_admins, since they're going to need permissions
            //  cleared too
            $query = $this->em->createQuery(
               'SELECT u.id AS user_id
                FROM ODROpenRepositoryUserBundle:User AS u
                WHERE u.roles LIKE :role'
            )->setParameters( array('role' => '%ROLE_SUPER_ADMIN%') );
            $all_super_admins = $query->getArrayResult();

            // Merge the two lists together
            $all_affected_users = array_merge($all_affected_users, $all_super_admins);


            // ----------------------------------------
            // Since this needs to make updates to multiple tables, use a transaction
            $conn = $this->em->getConnection();
            $conn->beginTransaction();

            $need_flush = false;

            // ----------------------------------------
            // Perform a series of DQL mass updates to immediately remove everything that could break if it wasn't deleted...
/*
            // ...datarecordfield entries
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:DataRecordFields AS drf
                SET drf.deletedAt = :now
                WHERE drf.dataField = :datafield AND drf.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datafield' => $datafield->getId()
                )
            );
            $rows = $query->execute();
*/

            // ...theme_datafield entries
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:ThemeDataField AS tdf
                SET tdf.deletedAt = :now, tdf.deletedBy = :deleted_by
                WHERE tdf.dataField = :datafield AND tdf.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'deleted_by' => $user->getId(),
                    'datafield' => $datafield->getId()
                )
            );
            $rows = $query->execute();

            // ...datafield permissions
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                SET gdfp.deletedAt = :now
                WHERE gdfp.dataField = :datafield AND gdfp.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datafield' => $datafield->getId()
                )
            );
            $rows = $query->execute();

            // ...render plugin instances
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:RenderPluginInstance AS rpi
                SET rpi.deletedAt = :now
                WHERE rpi.dataField = :datafield AND rpi.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datafield' => $datafield->getId()
                )
            );
            $rows = $query->execute();

            // ...render plugin maps
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:RenderPluginMap AS rpm
                SET rpm.deletedAt = :now
                WHERE rpm.dataField = :datafield AND rpm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datafield' => $datafield->getId()
                )
            );
            $rows = $query->execute();


            // ----------------------------------------
            // Need to check whether any other datatypes are using this datafield for sorting...
            // This kinda needs to come before checking the datafield's datatype, because otherwise
            //  the delayed flushing will create multiple DatatypeMeta entries for the previously
            //  mentioned "other datatypes"...
            $query = $this->em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataTypeMeta AS dtm
                JOIN ODRAdminBundle:DataType AS dt WITH dtm.dataType = dt
                WHERE dtm.sortField = :datafield_id AND dt.id != :datatype_id
                AND dtm.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datafield_id' => $datafield->getId(),
                    'datatype_id' => $datatype->getId()    // don't want the datafield's datatype, it'll be taken care of next
                )
            );
            $results = $query->getResult();

            foreach ($results as $result) {
                /** @var DataType $dt */
                $dt = $result;

                $props['sortField'] = null;
                $this->emm_service->updateDatatypeMeta($user, $dt, $props, true);    // don't flush

                // Shouldn't need to clear cache entries as a result of this...
                $need_flush = true;
            }


            // ----------------------------------------
            // Ensure that the datatype no longer thinks this datafield has a special purpose...
            $properties = array();

            // ...external id field
            // NOTE: external id fields aren't allowed to be deleted, but keep for safety
            if ( !is_null($datatype->getExternalIdField())
                && $datatype->getExternalIdField()->getId() === $datafield->getId()
            ) {
                $properties['externalIdField'] = null;
            }

            // ...name field
            if ( !is_null($datatype->getNameField())
                && $datatype->getNameField()->getId() === $datafield->getId()
            ) {
                $properties['nameField'] = null;
            }

            // ...background image field
            if ( !is_null($datatype->getBackgroundImageField())
                && $datatype->getBackgroundImageField()->getId() === $datafield->getId()
            ) {
                $properties['backgroundImageField'] = null;
            }

            // ...sort field
            if ( !is_null($datatype->getSortField())
                && $datatype->getSortField()->getId() === $datafield->getId()
            ) {
                $properties['sortField'] = null;

                // TODO - shouldn't this technically be in SortService?
                // Delete the sort order for the datatype too, so it doesn't attempt to sort on a non-existent datafield
                $this->dti_service->resetDatatypeSortOrder($datatype->getId());
            }

            // Save any required changes
            if ( count($properties) > 0 ) {
                $need_flush = true;
                $this->emm_service->updateDatatypeMeta($user, $datatype, $properties, true);    // don't flush
            }


            // ----------------------------------------
            // Delete any cached search results that use this soon-to-be-deleted datafield
            $this->search_cache_service->onDatafieldDelete($datafield);

            // Now that nothing references the datafield, and no other action requires it to still
            //  exist, delete the meta entry...
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:DataFieldsMeta AS dfm
                SET dfm.deletedAt = :now
                WHERE dfm.dataField = :datafield AND dfm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datafield' => $datafield->getId()
                )
            );
            $rows = $query->execute();

            // ...and finally the datafield entry
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:DataFields AS df
                SET df.deletedAt = :now, df.deletedBy = :deleted_by
                WHERE df = :datafield AND df.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'deleted_by' => $user->getId(),
                    'datafield' => $datafield->getId()
                )
            );
            $rows = $query->execute();

            // No error encountered, commit changes
            $conn->commit();

            // Flushing needs to happen after committing the other queries
            if ( $need_flush )
                $this->em->flush();


            // ----------------------------------------
            // Deleting a datafield needs to update the master_revision property of its datatype
            if ( $datatype->getIsMasterType() )
                $this->emm_service->incrementDatatypeMasterRevision($user, $datatype);

            // Ensure that the cached tag hierarchy doesn't reference this datafield
            $this->cache_service->delete('cached_tag_tree_'.$grandparent_datatype->getId());
            $this->cache_service->delete('cached_template_tag_tree_'.$grandparent_datatype->getId());

            // Wipe cached data for all the datatype's datarecords
            $dr_list = $this->search_service->getCachedSearchDatarecordList($grandparent_datatype->getId());
            foreach ($dr_list as $dr_id => $parent_dr_id) {
                $this->cache_service->delete('cached_datarecord_'.$dr_id);
                $this->cache_service->delete('cached_table_data_'.$dr_id);
            }

            // Wipe cached permission entries for all users affected by this
            foreach ($all_affected_users as $u) {
                $user_id = $u['user_id'];
                $this->cache_service->delete('user_'.$user_id.'_permissions');
            }

            // Faster to just delete the cached list of default radio options, rather than try to
            //  figure out specifics
            $this->cache_service->delete('default_radio_options');

            // Mark this datatype as updated
            $this->dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Rebuild all cached theme entries the datafield belonged to
            foreach ($all_datafield_themes as $t)
                $this->ti_service->updateThemeCacheEntry($t->getParentTheme(), $user);

        }
        catch (\Exception $e) {
            // Don't commit changes if any error was encountered...
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x8388a9ab;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Deletes a datarecord.
     * TODO - test this
     * TODO - EditController only needs to delete one at a time, but MassEditController needs multiple?
     *
     * @param DataRecord $datarecord
     * @param ODRUser $user
     *
     * @throws \Exception
     */
    public function deleteDatarecord($datarecord, $user)
    {
        throw new ODRNotImplementedException();

        $conn = null;

        try {

            // Going to need these...
            $datatype = $datarecord->getDataType();
            $parent_datarecord = $datarecord->getParent();

            // Store whether this was a deletion for a top-level datarecord or not
            $is_top_level = true;
            if ($datatype->getId() !== $parent_datarecord->getDataType()->getId())
                $is_top_level = false;

            // ----------------------------------------
            // Ensure user has permissions to be doing this
            if ( !$this->pm_service->canEditDatarecord($user, $parent_datarecord) )
                throw new ODRForbiddenException();
            if ( !$this->pm_service->canDeleteDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Recursively locate all children of this datarecord
            $parent_ids = array();
            $parent_ids[] = $datarecord->getId();

            $datarecords_to_delete = array();
            $datarecords_to_delete[] = $datarecord->getId();

            while (count($parent_ids) > 0) {
                // TODO - refactor to use SearchService::getCachedSearchDatarecordList()?

                // Can't use the grandparent datarecord property, because this deletion request
                //  could be for a datarecord that isn't top-level
                $query = $this->em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS parent
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.parent = parent
                    WHERE dr.id != parent.id AND parent.id IN (:parent_ids)
                    AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL'
                )->setParameters(array('parent_ids' => $parent_ids));
                $results = $query->getArrayResult();

                $parent_ids = array();
                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];

                    $parent_ids[] = $dr_id;
                    $datarecords_to_delete[] = $dr_id;
                }
            }
//print '<pre>'.print_r($datarecords_to_delete, true).'</pre>';  exit();

            // TODO - refactor to use DatatreeInfoService::???

            // Locate all datarecords that link to any of the datarecords that will be deleted...
            //  they will need to have their cache entries rebuilt
            $query = $this->em->createQuery(
               'SELECT DISTINCT(gp.id) AS ancestor_id
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                JOIN ODRAdminBundle:DataRecord AS gp WITH ancestor.grandparent = gp
                WHERE ldt.descendant IN (:datarecord_ids)
                AND ldt.deletedAt IS NULL
                AND ancestor.deletedAt IS NULL AND gp.deletedAt IS NULL'
            )->setParameters(array('datarecord_ids' => $datarecords_to_delete));
            $results = $query->getArrayResult();

            $ancestor_datarecord_ids = array();
            foreach ($results as $result)
                $ancestor_datarecord_ids[] = $result['ancestor_id'];
//print '<pre>'.print_r($ancestor_datarecord_ids, true).'</pre>';  exit();

            // ----------------------------------------
            // Since this needs to make updates to multiple tables, use a transaction
            $conn = $this->em->getConnection();
            $conn->beginTransaction();

/*
            // ...delete all datarecordfield entries that reference these datarecords
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:DataRecordFields AS drf
                SET drf.deletedAt = :now
                WHERE drf.dataRecord IN (:datarecord_ids) AND drf.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datarecord_ids' => $datarecords_to_delete
                )
            );
            $rows = $query->execute();
*/

            // ...delete all linked_datatree entries that reference these datarecords
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:LinkedDataTree AS ldt
                SET ldt.deletedAt = :now, ldt.deletedBy = :deleted_by
                WHERE (ldt.ancestor IN (:datarecord_ids) OR ldt.descendant IN (:datarecord_ids))
                AND ldt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'deleted_by' => $user->getId(),
                    'datarecord_ids' => $datarecords_to_delete
                )
            );
            $rows = $query->execute();

            // ...delete each meta entry for the datarecords to be deleted
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:DataRecordMeta AS drm
                SET drm.deletedAt = :now
                WHERE drm.dataRecord IN (:datarecord_ids)
                AND drm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datarecord_ids' => $datarecords_to_delete
                )
            );
            $rows = $query->execute();

            // ...delete all of the datarecords
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:DataRecord AS dr
                SET dr.deletedAt = :now, dr.deletedBy = :deleted_by
                WHERE dr.id IN (:datarecord_ids)
                AND dr.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'deleted_by' => $user->getId(),
                    'datarecord_ids' => $datarecords_to_delete
                )
            );
            $rows = $query->execute();

            // No error encountered, commit changes
//$conn->rollBack();
            $conn->commit();

            // -----------------------------------
            // Mark this now-deleted datarecord's parent (and all its parents) as updated unless
            //  it was already a top-level datarecord
            if (!$is_top_level)
                $this->dri_service->updateDatarecordCacheEntry($parent_datarecord, $user);

            // If this was a top-level datarecord that just got deleted...
            if ($is_top_level) {
                // ...then ensure no other datarecords think they're still linked to this
                $this->dri_service->deleteCachedDatarecordLinkData($ancestor_datarecord_ids);
            }

            // Delete all search cache entries that could reference the deleted datarecords
            $this->search_cache_service->onDatarecordDelete($datatype);
            // Force anything that linked to this datatype to rebuild link entries since at least
            //  one record got deleted
            $this->search_cache_service->onLinkStatusChange($datatype);

            // Force a rebuild of the cache entries for each datarecord that linked to the records
            //  that just got deleted
            foreach ($ancestor_datarecord_ids as $num => $dr_id) {
                $this->cache_service->delete('cached_datarecord_'.$dr_id);
                $this->cache_service->delete('cached_table_data_'.$dr_id);
            }
        }
        catch (\Exception $e) {
            // Don't commit changes if any error was encountered...
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x6365473d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Deletes a Datatype
     *
     * @param DataType $datatype
     * @param ODRUser $user
     *
     * @throws \Exception
     */
    public function deleteDatatype($datatype, $user)
    {
        $conn = null;

        try {
            // Going to need these...
            $grandparent = $datatype->getGrandparent();
            if ($grandparent->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datatype');
            $grandparent_datatype_id = $grandparent->getId();


            // --------------------
            // Ensure user has permissions to be doing this
            if (!$this->pm_service->isDatatypeAdmin($user, $datatype))
                throw new ODRForbiddenException();
            // --------------------

            // Don't directly delete a metadata datatype
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to delete a metadata datatype');

            // TODO - prevent datatype deletion when called from a linked dataype?  not sure if this is possible...


            // Prevent a datatype from being deleted if certain jobs are in progress
            $restricted_jobs = array('mass_edit', 'migrate', 'csv_export', 'csv_import_validate', 'csv_import');
            $this->tj_service->checkActiveJobs($datatype, $restricted_jobs, "Unable to delete this datatype");


            // ----------------------------------------
            // Easier to handle updates to the "master_revision" and others before anything gets
            //  deleted...
            if ( $datatype->getIsMasterType() )
                $this->emm_service->incrementDatatypeMasterRevision($user, $datatype, true);    // don't flush immediately...

            // Even though it's getting deleted, mark this datatype as updated so its parents get
            //  updated as well
            $this->dti_service->updateDatatypeCacheEntry($datatype, $user);    // flushes here


            // ----------------------------------------
            // Locate ids of all datatypes that need deletion...can't just use grandparent datatype id
            //  since this could be a child datatype
            $datatree_array = $this->dti_service->getDatatreeArray();

            $tmp = array($datatype->getId() => 0);
            $datatypes_to_delete = array(0 => $datatype->getId());

            // If datatype has metadata, delete metadata
            if ($metadata_datatype = $datatype->getMetadataDatatype()) {
                array_push($datatypes_to_delete, $metadata_datatype->getId());
            }

            while (count($tmp) > 0) {
                $new_tmp = array();
                foreach ($tmp as $dt_id => $num) {
                    $child_datatype_ids = array_keys($datatree_array['descendant_of'], $dt_id);
                    foreach ($child_datatype_ids as $num => $child_datatype_id) {
                        $new_tmp[$child_datatype_id] = 0;
                        $datatypes_to_delete[] = $child_datatype_id;
                    }
                    unset($tmp[$dt_id]);
                }
                $tmp = $new_tmp;
            }
            $datatypes_to_delete = array_unique($datatypes_to_delete);
            $datatypes_to_delete = array_values($datatypes_to_delete);

            //print '<pre>'.print_r($datatypes_to_delete, true).'</pre>'; exit();

            // Determine all Groups and all Users affected by this
            $query = $this->em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:Group AS g
                WHERE g.dataType IN (:datatype_ids)
                AND g.deletedAt IS NULL'
            )->setParameters(array('datatype_ids' => $datatypes_to_delete));
            $results = $query->getArrayResult();

            $groups_to_delete = array();
            foreach ($results as $result)
                $groups_to_delete[] = $result['group_id'];
            $groups_to_delete = array_unique($groups_to_delete);
            $groups_to_delete = array_values($groups_to_delete);

            //print '<pre>'.print_r($groups_to_delete, true).'</pre>';  exit();

            $query = $this->em->createQuery(
               'SELECT u.id AS user_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE ug.group IN (:groups) AND ug.deletedAt IS NULL'
            )->setParameters(array('groups' => $groups_to_delete));
            $group_members = $query->getArrayResult();

            // Need to separately locate all super_admins, since they're going to need permissions
            //  cleared too
            $query = $this->em->createQuery(
               'SELECT u.id AS user_id
                FROM ODROpenRepositoryUserBundle:User AS u
                WHERE u.roles LIKE :role'
            )->setParameters( array('role' => '%ROLE_SUPER_ADMIN%') );
            $all_super_admins = $query->getArrayResult();

            // Merge the two lists together
            $all_affected_users = array();
            foreach ($group_members as $num => $u)
                $all_affected_users[ $u['user_id'] ] = 1;
            foreach ($all_super_admins as $num => $u)
                $all_affected_users[ $u['user_id'] ] = 1;
            $all_affected_users = array_keys($all_affected_users);

            // Locate all cached theme entries that need to be rebuilt...
            $query = $this->em->createQuery(
               'SELECT t.id AS theme_id
                FROM ODRAdminBundle:Theme AS t
                JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                JOIN ODRAdminBundle:ThemeDataType AS tdt WITH tdt.themeElement = te
                WHERE tdt.dataType IN (:datatype_ids)
                AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdt.deletedAt IS NULL'
            )->setParameters(array('datatype_ids' => $datatypes_to_delete));
            $results = $query->getArrayResult();

            $cached_themes_to_delete = array();
            foreach ($results as $result)
                $cached_themes_to_delete[] = $result['theme_id'];
            $cached_themes_to_delete = array_unique($cached_themes_to_delete);
            $cached_themes_to_delete = array_values($cached_themes_to_delete);

            //print '<pre>'.print_r($cached_themes_to_delete, true).'</pre>';  exit();


            // ----------------------------------------
            // Since this needs to make updates to multiple tables, use a transaction
            $conn = $this->em->getConnection();
            $conn->beginTransaction();

            /*
             * NOTE - the update queries can't use $em->createQuery(<DQL>)->execute(); because DQL
             * doesn't allow multi-table updates.
             *
             * Additionally, the update queries also can't use $conn->prepare(<SQL>)->execute();
             * because the SQL IN() clause typically won't be interpreted correctly by the underlying
             * database abstraction layer.
             *
             * These update queries have to use $conn->executeUpdate(<SQL>) and explicit typehinting...
             * that way, Doctrine can rewrite the queries so the database abstraction layer can
             * interpret them correctly.
             */


            // ----------------------------------------
            // Determine which datarecords are going to need to be recached, before the linked
            //  datatree entries are deleted...
            $query = $this->em->createQuery(
               'SELECT DISTINCT(grandparent.id) AS dr_id
                FROM ODRAdminBundle:DataRecord AS grandparent
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ancestor.grandparent = grandparent
                JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                WHERE descendant.dataType IN (:datatype_ids)
                AND descendant.deletedAt IS NULL AND ldt.deletedAt IS NULL
                AND ancestor.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
            )->setParameters(array('datatype_ids' => $datatypes_to_delete));
            $results = $query->getArrayResult();

            $datarecords_to_recache = array();
            foreach ($results as $result)
                $datarecords_to_recache[] = $result['dr_id'];

            // Get the ids of all LinkedDataTree entries that need to be deleted
            $query = $this->em->createQuery(
               'SELECT ldt.id AS ldt_id
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                WHERE (ancestor.dataType IN (:datatype_ids) OR descendant.dataType IN (:datatype_ids))
                AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters(array('datatype_ids' => $datatypes_to_delete));
            $results = $query->getArrayResult();

            $linked_datatree_ids = array();
            foreach ($results as $ldt)
                $linked_datatree_ids[] = $ldt['ldt_id'];

            // Since a datarecord can't link to itself, don't need to worry about duplicates


            // Delete the LinkedDataTree entries...the query could technically be done a different
            //  way, but this is consistent with the rest of the multi-table updates
            $query_str =
               'UPDATE odr_linked_data_tree AS ldt
                SET ldt.deletedAt = NOW(), ldt.deletedBy = '.$user->getId().'
                WHERE ldt.id IN (?)';
            $parameters = array(1 => $linked_datatree_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);


            // ----------------------------------------
/*
            // Delete Datarecord, DatarecordMeta, and DatarecordField entries
            $query_str =
               'UPDATE odr_data_record AS dr, odr_data_record_meta AS drm, odr_data_record_fields AS drf
                SET dr.deletedAt = NOW(), drm.deletedAt = NOW(), drf.deletedAt = NOW(), dr.deletedBy = '.$user->getId().'
                WHERE drm.data_record_id = dr.id AND drf.data_record_id = dr.id
                AND dr.data_type_id IN (?)
                AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL AND drf.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);
*/

            // ----------------------------------------
/*
            // Delete Datafields and their DatafieldMeta entries
            $query_str =
               'UPDATE odr_data_fields AS df, odr_data_fields_meta AS dfm
                SET df.deletedAt = NOW(), df.deletedBy = '.$user->getId().', dfm.deletedAt = NOW()
                WHERE dfm.data_field_id = df.id AND df.data_type_id IN (?)
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);
*/

            // Ensure no datatypes attempt to use datafields from these soon-to-be-deleted datatypes
            //  as their sortfield
            $query = $this->em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                JOIN ODRAdminBundle:DataFields AS df WITH dtm.sortField = df
                WHERE df.dataType IN (:a) AND dt.id NOT IN (:b)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND df.deletedAt IS NULL'
            )->setParameters(
                array(
                    'a' => $datatypes_to_delete,
                    'b' => $datatypes_to_delete
                )
            );
            $sub_results = $query->getResult();

            $needs_flush = false;
            foreach ($sub_results as $dt) {
                /** @var DataType $dt */
                $props = array('sortField' => null);
                $this->emm_service->updateDatatypeMeta($user, $dt, $props, true);    // don't flush immediately
                $needs_flush = true;
            }


            // ----------------------------------------
/*
            // Delete all ThemeDatatype entries
            $query_str =
               'UPDATE odr_theme_data_type AS tdt, odr_theme_element AS te, odr_theme AS t
                SET tdt.deletedAt = NOW(), tdt.deletedBy = '.$user->getId().'
                WHERE tdt.theme_element_id = te.id AND te.theme_id = t.id
                AND t.data_type_id IN (?)
                AND tdt.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);
*/

            // Delete any leftover ThemeDatatype entries that refer to $datatypes_to_delete...these would be other datatypes linking to the ones being deleted
            // (if block above is commented, then it'll also arbitrarily delete themeDatatype entries for child datatypes)
            $query_str =
               'UPDATE odr_theme_data_type AS tdt
                SET tdt.deletedAt = NOW(), tdt.deletedBy = '.$user->getId().'
                WHERE tdt.data_type_id IN (?)
                AND tdt.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);
/*
            // Delete all ThemeDatafield entries
            $query_str =
               'UPDATE odr_theme_data_field AS tdf, odr_theme_element AS te, odr_theme AS t
                SET tdf.deletedAt = NOW(), tdf.deletedBy = '.$user->getId().'
                WHERE tdf.theme_element_id = te.id AND te.theme_id = t.id
                AND t.data_type_id IN (?)
                AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);
*/
/*
            // Delete all ThemeElement and ThemeElementMeta entries
            $query_str =
               'UPDATE odr_theme_element AS te, odr_theme_element_meta AS tem, odr_theme AS t
                SET te.deletedAt = NOW(), tem.deletedAt = NOW(), te.deletedBy = '.$user->getId().'
                WHERE tem.theme_element_id = te.id AND te.theme_id = t.id
                AND t.data_type_id IN (?)
                AND te.deletedAt IS NULL AND tem.deletedAt IS NULL AND t.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);
*/

            // Delete all Theme and ThemeMeta entries
            $query_str =
               'UPDATE odr_theme AS t, odr_theme_meta AS tm
                SET t.deletedAt = NOW(), tm.deletedAt = NOW(), t.deletedBy = '.$user->getId().'
                WHERE tm.theme_id = t.id AND t.data_type_id IN (?)
                AND t.deletedAt IS NULL AND tm.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);


            // ----------------------------------------
            // Get the ids of all DataTree entries that need to be deleted
            $query = $this->em->createQuery(
               'SELECT ancestor.id AS ancestor_id, dt.id AS dt_id
                FROM ODRAdminBundle:DataTree AS dt
                JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
                JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
                WHERE (ancestor.id IN (:datatype_ids) OR descendant.id IN (:datatype_ids))
                AND dt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters(array('datatype_ids' => $datatypes_to_delete));
            $results = $query->getArrayResult();

            $ancestor_datatype_ids = array();
            $datatree_ids = array();
            foreach ($results as $dt) {
                $ancestor_datatype_ids[] = $dt['ancestor_id'];
                $datatree_ids[] = $dt['dt_id'];
            }

            // Shouldn't need to worry about duplicates...

            // Delete all Datatree and DatatreeMeta entries
            $query_str =
               'UPDATE odr_data_tree AS dt, odr_data_tree_meta AS dtm
                SET dt.deletedAt = NOW(), dtm.deletedAt = NOW(), dt.deletedBy = '.$user->getId().'
                WHERE dtm.data_tree_id = dt.id AND dt.id IN (?)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL';
            $parameters = array(1 => $datatree_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);


            // ----------------------------------------
/*
            // Delete Group, GroupMeta, GroupDatatypePermission, and GroupDatafieldPermission entries
            $query_str =
               'UPDATE odr_group AS g, odr_group_meta AS gm, odr_group_datatype_permissions AS gdtp, odr_group_datafield_permissions AS gdfp
                SET g.deletedAt = NOW(), gm.deletedAt = NOW(), gdtp.deletedAt = NOW(), gdfp.deletedAt = NOW(), g.deletedBy = '.$user->getId().'
                WHERE g.data_type_id IN (?)
                AND gm.group_id = g.id AND gdtp.data_type_id = g.id AND gdfp.data_type_id = g.id
                AND g.deletedAt IS NULL AND gm.deletedAt IS NULL AND gdtp.deletedAt IS NULL AND gdfp.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);
*/

            // Remove members from the Groups for this Datatype
            $query_str =
               'UPDATE odr_user_group AS ug
                SET ug.deletedAt = NOW(), ug.deletedBy = '.$user->getId().'
                WHERE ug.group_id IN (?)
                AND ug.deletedAt IS NULL';
            $parameters = array(1 => $groups_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);


            // ----------------------------------------
            // Delete all Datatype and DatatypeMeta entries
            $query_str =
               'UPDATE odr_data_type AS dt, odr_data_type_meta AS dtm
                SET dt.deletedAt = NOW(), dtm.deletedAt = NOW(), dt.deletedBy = '.$user->getId().'
                WHERE dtm.data_type_id = dt.id AND dt.id IN (?)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);


            // ----------------------------------------
            // Ensure that the cached tag hierarchy doesn't reference this datatype anymore
            $this->cache_service->delete('cached_tag_tree_'.$grandparent_datatype_id);

            // Delete cached versions of all Datarecords of this Datatype if needed
            if ($datatype->getId() == $grandparent_datatype_id) {
                $query = $this->em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype_id'
                )->setParameters(array('datatype_id' => $grandparent_datatype_id));
                $results = $query->getArrayResult();

                //print '<pre>'.print_r($results, true).'</pre>';  exit();

                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];

                    $this->cache_service->delete('cached_datarecord_'.$dr_id);
                    $this->cache_service->delete('cached_table_data_'.$dr_id);
                    $this->cache_service->delete('associated_datarecords_for_'.$dr_id);
                }
            }


            // ----------------------------------------
            // Delete cached versions of datatypes that linked to this Datatype
            foreach ($ancestor_datatype_ids as $num => $dt_id) {
                $this->cache_service->delete('cached_datatype_'.$dt_id);
                $this->cache_service->delete('associated_datatypes_for_'.$dt_id);
            }

            // Delete cached versions of datarecords that linked into this Datatype
            foreach ($datarecords_to_recache as $num => $dr_id) {
                $this->cache_service->delete('cached_datarecord_'.$dr_id);
                $this->cache_service->delete('cached_table_data_'.$dr_id);
                $this->cache_service->delete('associated_datarecords_for_'.$dr_id);
            }


            // ----------------------------------------
            // Delete cached permission entries for the users related to this Datatype
            foreach ($all_affected_users as $user_id)
                $this->cache_service->delete('user_'.$user_id.'_permissions');

            // ...cached searches
            $this->search_cache_service->onDatatypeDelete($datatype);

            // ...cached datatype data
            foreach ($datatypes_to_delete as $num => $dt_id) {
                $this->cache_service->delete('cached_datatype_'.$dt_id);
                $this->cache_service->delete('associated_datatypes_for_'.$dt_id);

                $this->cache_service->delete('dashboard_'.$dt_id);
                $this->cache_service->delete('dashboard_'.$dt_id.'_public_only');
            }

            // ...cached theme data
            foreach ($cached_themes_to_delete as $num => $t_id)
                $this->cache_service->delete('cached_theme_'.$t_id);


            // ...and the cached version of the datatree array
            $this->cache_service->delete('top_level_datatypes');
            $this->cache_service->delete('top_level_themes');
            $this->cache_service->delete('cached_datatree_array');

            // Faster to just delete the cached list of default radio options, rather than try to
            //  figure out specifics
            $this->cache_service->delete('default_radio_options');


            // ----------------------------------------
            // No error encountered, commit changes
            $conn->commit();

            // If a flush is needed, then only do it after the transaction is finished
            if ( $needs_flush )
                $this->em->flush();

        }
        catch (\Exception $e) {
            // Don't commit changes if any error was encountered...
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x1b7df498;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
