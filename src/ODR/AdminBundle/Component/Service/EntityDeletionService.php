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
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldDeletedEvent;
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordDeletedEvent;
use ODR\AdminBundle\Component\Event\DatarecordLinkStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\DatatypeDeletedEvent;
use ODR\AdminBundle\Component\Event\DatatypeModifiedEvent;
use ODR\AdminBundle\Component\Event\FileDeletedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
// Symfony
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


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
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var DatafieldInfoService
     */
    private $datafield_info_service;

    /**
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

    /**
     * @var EntityMetaModifyService
     */
    private $entity_modify_service;

    /**
     * @var PermissionsManagementService
     */
    private $permissions_service;

    /**
     * @var TrackedJobService
     */
    private $tracked_job_service;

    /**
     * @var ThemeInfoService
     */
    private $theme_info_service;

    /**
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

    // NOTE - $event_dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode

    /**
     * @var string
     */
    private $odr_web_dir;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * EntityDeletionService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatabaseInfoService $database_info_service
     * @param DatafieldInfoService $datafield_info_service
     * @param DatatreeInfoService $datatree_info_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param PermissionsManagementService $permissions_management_service
     * @param TrackedJobService $tracked_job_service
     * @param ThemeInfoService $theme_info_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param string $odr_web_dir
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatabaseInfoService $database_info_service,
        DatafieldInfoService $datafield_info_service,
        DatatreeInfoService $datatree_info_service,
        EntityMetaModifyService $entity_meta_modify_service,
        PermissionsManagementService $permissions_management_service,
        TrackedJobService $tracked_job_service,
        ThemeInfoService $theme_info_service,
        EventDispatcherInterface $event_dispatcher,
        string $odr_web_dir,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->database_info_service = $database_info_service;
        $this->datafield_info_service = $datafield_info_service;
        $this->datatree_info_service = $datatree_info_service;
        $this->entity_modify_service = $entity_meta_modify_service;
        $this->permissions_service = $permissions_management_service;
        $this->tracked_job_service = $tracked_job_service;
        $this->theme_info_service = $theme_info_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->odr_web_dir = realpath($odr_web_dir);
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
            $datafield_id = $datafield->getId();
            $datafield_uuid = $datafield->getFieldUuid();

            $typeclass = $datafield->getFieldType()->getTypeClass();
            $datatype = $datafield->getDataType();
            $grandparent_datatype = $datatype->getGrandparent();

            // --------------------
            // Ensure user has permissions to be doing this
            if (!$this->permissions_service->isDatatypeAdmin($user, $datatype))
                throw new ODRForbiddenException();
            // --------------------


            // Check that the datafield isn't being used for something else before deleting it
            $datatype_array = $this->database_info_service->getDatatypeArray($grandparent_datatype->getId(), false);    // don't want links
            $props = $this->datafield_info_service->canDeleteDatafield($datatype_array, $datatype->getId(), $datafield->getId());
            if ( !$props['can_delete'] )
                throw new ODRBadRequestException( $props['delete_message'] );


            // ----------------------------------------
            // Check whether any jobs that are currently running would interfere with the deletion
            //  of this datafield
            $new_job_data = array(
                'job_type' => 'delete_datafield',
                'target_entity' => $datafield,
            );

            $conflicting_job = $this->tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to delete this Datafield, as it would interfere with an already running '.$conflicting_job.' job');


            // ----------------------------------------
            // Save which themes are going to get theme_datafield entries deleted
            $query = $this->em->createQuery(
               'SELECT t
                FROM ODRAdminBundle:ThemeDataField AS tdf
                JOIN ODRAdminBundle:ThemeElement AS te WITH tdf.themeElement = te
                JOIN ODRAdminBundle:Theme AS t WITH te.theme = t
                WHERE tdf.dataField = :datafield
                AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId()) );
            $all_datafield_themes = $query->getResult();
            /** @var Theme[] $all_datafield_themes */

            // Determine which groups will be affected by the deletion of this datafield
            $query = $this->em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                JOIN ODRAdminBundle:Group AS g WITH gdfp.group = g
                WHERE gdfp.dataField = :datafield
                AND gdfp.deletedAt IS NULL AND g.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId()) );
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
            )->setParameters( array('groups' => $all_affected_groups) );
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

            // If any of the datafields being deleted are being used as a sortfield for other
            //  datatypes, then need to clear the default sort order for those datatypes
            $query = $this->em->createQuery(
               'SELECT DISTINCT(l_dt.id) AS dt_id
                FROM ODRAdminBundle:DataFields AS df
                LEFT JOIN ODRAdminBundle:DataTypeSpecialFields AS dtsf WITH dtsf.dataField = df
                LEFT JOIN ODRAdminBundle:DataType AS l_dt WITH dtsf.dataType = l_dt
                WHERE df.id IN (:datafields_to_delete) AND dtsf.field_purpose = :field_purpose
                AND df.deletedAt IS NULL AND dtsf.deletedAt IS NULL AND l_dt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datafields_to_delete' => array($datafield->getId()),
                    'field_purpose' => DataTypeSpecialFields::SORT_FIELD
                )
            );
            $results = $query->getArrayResult();

            $datatypes_to_reset_order = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $datatypes_to_reset_order[] = $dt_id;
            }

            // Need to also delete the cache entries of all datatypes that have a field derived
            //  from the soon-to-be-deleted datafield
            $query = $this->em->createQuery(
               'SELECT ddt.id AS dt_id
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFields AS ddf WITH ddf.masterDataField = df
                JOIN ODRAdminBundle:DataType AS ddt WITH ddf.dataType = ddt
                WHERE df.id = :datafield_to_delete
                AND df.deletedAt IS NULL AND ddf.deletedAt IS NULL AND ddt.deletedAt IS NULL'
            )->setParameters( array('datafield_to_delete' => $datafield->getId()) );
            $results = $query->getArrayResult();

            $datatypes_to_clear_cached_data = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $datatypes_to_clear_cached_data[] = $dt_id;
            }


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

            // ...derived datafields
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:DataFields AS df
                SET df.templateFieldUuid = NULL, df.masterDataField = NULL
                WHERE df.templateFieldUuid = :field_uuid'
            )->setParameters(
                array(
                    'field_uuid' => $datafield->getFieldUuid()
                )
            );
            $rows = $query->execute();

            // ...sidebar layout maps
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:SidebarLayoutMap AS slm
                SET slm.deletedAt = :now
                WHERE slm.dataField = :datafield AND slm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datafield' => $datafield->getId()
                )
            );
            $rows = $query->execute();


            // ----------------------------------------
            // Need to locate all other datatypes that are using this soon-to-be-deleted datafield
            //  as their sort field...
            $query = $this->em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataTypeSpecialFields dtsf
                LEFT JOIN ODRAdminBundle:DataType dt WITH dtsf.dataType = dt
                WHERE dtsf.dataField = :datafield_id AND dtsf.dataType != :datatype_id
                AND dtsf.deletedAt IS NULL'
            )->setParameters( array('datafield_id' => $datafield->getId(), 'datatype_id' => $datatype->getId()) );
            $results = $query->getResult();

            // ...and delete any mention that this field was used for a special purpose
            $query = $this->em->createQuery(
               'UPDATE ODRAdminBundle:DataTypeSpecialFields AS dtsf
                SET dtsf.deletedAt = :now, dtsf.deletedBy = :deleted_by
                WHERE dtsf.dataField = :datafield
                AND dtsf.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'deleted_by' => $user->getId(),
                    'datafield' => $datafield->getId()
                )
            );
            $rows = $query->execute();

            /** @var DataType[] $results */
            foreach ($results as $dt) {
                // Any datatypes this query finds had their sort fields changed, so they also need
                //  to rebuild their cache entries
                try {
                    $event = new DatatypeModifiedEvent($dt, $user, true);    // Also need to rebuild datarecord cache entries because they store sort/name field values
                    $this->event_dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
                }
            }


            // ----------------------------------------
            // Ensure that the datatype no longer thinks this datafield has a special purpose...
            // NOTE: external_id fields aren't allowed to be deleted, but keep for safety
            // NOTE: the rest of these aren't supposed to be used, but keep for the same reason
            $properties = array();

            // ...external id field
            if ( !is_null($datatype->getExternalIdField() )
                && $datatype->getExternalIdField()->getId() === $datafield->getId()
            ) {
                $properties['externalIdField'] = null;
            }

            // ...name field
            if ( !is_null($datatype->getNameField() )
                && $datatype->getNameField()->getId() === $datafield->getId()
            ) {
                $properties['nameField'] = null;
            }

            // ...background image field
            if ( !is_null($datatype->getBackgroundImageField() )
                && $datatype->getBackgroundImageField()->getId() === $datafield->getId()
            ) {
                $properties['backgroundImageField'] = null;
            }

            // ...sort field
            if ( !is_null($datatype->getSortField() )
                && $datatype->getSortField()->getId() === $datafield->getId()
            ) {
                $properties['sortField'] = null;
            }

            // Save any required changes
            if ( count($properties) > 0 ) {
                $need_flush = true;
                $this->entity_modify_service->updateDatatypeMeta($user, $datatype, $properties, true);    // don't flush
            }


            // ----------------------------------------
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
                $this->entity_modify_service->incrementDatatypeMasterRevision($user, $datatype);

            if ( $typeclass === 'Radio' ) {
                // Faster to just delete the cached list of default radio options, rather than try to
                //  figure out specifics
                $this->cache_service->delete('default_radio_options');
            }
            else if ( $typeclass === 'Tag' ) {
                // Ensure that the cached tag hierarchy doesn't reference this datafield
                $this->cache_service->delete('cached_tag_tree_'.$grandparent_datatype->getId());
                $this->cache_service->delete('cached_template_tag_tree_'.$grandparent_datatype->getId());
            }

            // Inform that this datafield was deleted
            try {
                $event = new DatafieldDeletedEvent($datafield_id, $datafield_uuid, $datatype, $user);
                $this->event_dispatcher->dispatch(DatafieldDeletedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Mark this datatype as updated
            try {
                $event = new DatatypeModifiedEvent($datatype, $user, true);    // Also need to rebuild datarecord cache entries in case they reference the datafield
                $this->event_dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Reset sort order for the datatypes found earlier
            foreach ($datatypes_to_reset_order as $num => $dt_id)
                $this->cache_service->delete('datatype_'.$dt_id.'_record_order');

            // Delete derived datatype cache entries if needed
            foreach ($datatypes_to_clear_cached_data as $num => $dt_id)
                $this->cache_service->delete('cached_datatype_'.$dt_id);

            // Rebuild all cached theme entries the datafield belonged to
            foreach ($all_datafield_themes as $t)
                $this->theme_info_service->updateThemeCacheEntry($t->getParentTheme(), $user);

            // Wipe cached permission entries for all users affected by this
            foreach ($all_affected_users as $u) {
                $user_id = $u['user_id'];
                $this->cache_service->delete('user_'.$user_id.'_permissions');
            }

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
     *
     * NOTE: changes made here should also be made in MassEditController::massdeleteAction(), though
     * complete parity won't be possible because of MassEdit's ability to delete multiple records
     * at once.
     *
     * @param DataRecord $datarecord
     * @param ODRUser $user
     * @param bool $fire_datarecord_modified_event APIController typically doesn't want to fire this event...
     *
     * @throws \Exception
     */
    public function deleteDatarecord($datarecord, $user, $fire_datarecord_modified_event = true)
    {
        $conn = null;

        try {
            // Going to need these
            $datarecord_id = $datarecord->getId();
            $datarecord_uuid = $datarecord->getUniqueId();


            // ----------------------------------------
            // Store whether this was a deletion for a top-level datarecord or not
            $datatype = $datarecord->getDataType();
            $parent_datarecord = $datarecord->getParent();

            $is_top_level = true;
            if ( $datatype->getId() !== $parent_datarecord->getDataType()->getId() )
                $is_top_level = false;


            // ----------------------------------------
            // Check whether any jobs that are currently running would interfere with the deletion
            //  of this datarecord
            $new_job_data = array(
                'job_type' => 'delete_datarecord',
                'target_entity' => $datarecord,
            );

            $conflicting_job = $this->tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to delete this Datarecord, as it would interfere with an already running '.$conflicting_job.' job');


            // ----------------------------------------
            // Recursively locate all children of this datarecord
            $parent_ids = array();
            $parent_ids[] = $datarecord->getId();

            $datarecords_to_delete = array();
            $datarecords_to_delete[] = $datarecord->getId();

            while ( count($parent_ids) > 0 ) {
                // Can't use the grandparent datarecord property, because this deletion request
                //  could be for a datarecord that isn't top-level
                $query = $this->em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS parent
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.parent = parent
                    WHERE dr.id != parent.id AND parent.id IN (:parent_ids)
                    AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL'
                )->setParameters( array('parent_ids' => $parent_ids) );
                $results = $query->getArrayResult();

                $parent_ids = array();
                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];

                    $parent_ids[] = $dr_id;
                    $datarecords_to_delete[] = $dr_id;
                }
            }
//print '<pre>'.print_r($datarecords_to_delete, true).'</pre>';  exit();

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
            )->setParameters( array('datarecord_ids' => $datarecords_to_delete) );
            $results = $query->getArrayResult();

            $ancestor_datarecord_ids = array();
            foreach ($results as $result)
                $ancestor_datarecord_ids[] = $result['ancestor_id'];
//print '<pre>'.print_r($ancestor_datarecord_ids, true).'</pre>';  exit();


            // If the datarecord contains any datafields that are being used as a sortfield for
            //  other datatypes, then need to clear the default sort order for those datatypes
            $query = $this->em->createQuery(
               'SELECT DISTINCT(l_dt.id) AS dt_id
                FROM ODRAdminBundle:DataRecord AS dr
                LEFT JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
                LEFT JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                LEFT JOIN ODRAdminBundle:DataTypeSpecialFields AS dtsf WITH dtsf.dataField = df
                LEFT JOIN ODRAdminBundle:DataType AS l_dt WITH dtsf.dataType = l_dt
                WHERE dr.id IN (:datarecords_to_delete) AND dtsf.field_purpose = :field_purpose
                AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
                AND dtsf.deletedAt IS NULL AND l_dt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datarecords_to_delete' => $datarecords_to_delete,
                    'field_purpose' => DataTypeSpecialFields::SORT_FIELD
                )
            );
            $results = $query->getArrayResult();

            $datatypes_to_reset_order = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $datatypes_to_reset_order[] = $dt_id;
            }


            // ----------------------------------------
            // Since this needs to make updates to multiple tables, use a transaction
            $conn = $this->em->getConnection();
            $conn->beginTransaction();

            // TODO - delete datarecordfield entries as well?
            // TODO - delete radio/tagSelection entries as well?

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
            // Fire off an event notifying that this datarecord got deleted
            try {
                $event = new DatarecordDeletedEvent($datarecord_id, $datarecord_uuid, $datatype, $user);
                $this->event_dispatcher->dispatch(DatarecordDeletedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // If this was a top-level datarecord that just got deleted...
            if ( $is_top_level ) {
                // ...then ensure no other datarecords think they're still linked to it
                try {
                    $event = new DatarecordLinkStatusChangedEvent($ancestor_datarecord_ids, $datatype, $user);
                    $this->event_dispatcher->dispatch(DatarecordLinkStatusChangedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }
            else {
                // ...if not, then mark this now-deleted datarecord's parent (and all its parents)
                //  as updated
                if ( $fire_datarecord_modified_event ) {
                    try {
                        $event = new DatarecordModifiedEvent($parent_datarecord, $user);
                        $this->event_dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }
            }


            // ----------------------------------------
            // Reset sort order for the datatypes found earlier
            foreach ($datatypes_to_reset_order as $num => $dt_id)
                $this->cache_service->delete('datatype_'.$dt_id.'_record_order');

            // NOTE: don't actually need to delete cached graphs for the datatype...the relevant
            //  plugins will end up requesting new graphs without the files for the deleted records
        }
        catch (\Exception $e) {
            // Don't commit changes if any error was encountered...
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x1d5d3aaf;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Deletes a Datatype.
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
            $datatype_id = $datatype->getId();
            $datatype_uuid = $datatype->getUniqueId();

            $parent_datatype = $datatype->getParent();
            if ($parent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datatype');
            $grandparent_datatype_id = $grandparent_datatype->getId();

            $deleting_top_level_datatype = false;
            if ( $datatype_id === $grandparent_datatype_id )
                $deleting_top_level_datatype = true;


            // --------------------
            // Ensure user has permissions to be doing this
            if (!$this->permissions_service->isDatatypeAdmin($user, $datatype))
                throw new ODRForbiddenException();
            // --------------------

            // Don't directly delete a metadata datatype
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to delete a metadata datatype');

            // Don't delete a child datatype when it's derived from a template
            if ( !$deleting_top_level_datatype && !is_null($datatype->getMasterDataType()) )
                throw new ODRBadRequestException('Unable to delete a child datatype that is derived from a master template');

            // TODO - prevent datatype deletion when called from a linked dataype?  not sure if this is possible...


            // ----------------------------------------
            // Check whether any jobs that are currently running would interfere with the deletion
            //  of this datatype
            $new_job_data = array(
                'job_type' => 'delete_datatype',
                'target_entity' => $datatype,
            );

            $conflicting_job = $this->tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to delete this Datatype, as it would interfere with an already running '.$conflicting_job.' job');


            // ----------------------------------------
            // Easier to handle updates to the "master_revision" and others before anything gets
            //  deleted...
            if ( $datatype->getIsMasterType() )
                $this->entity_modify_service->incrementDatatypeMasterRevision($user, $datatype);


            // ----------------------------------------
            // Locate ids of all datatypes that need deletion...can't just use grandparent datatype id
            //  since this could be a child datatype
            $datatree_array = $this->datatree_info_service->getDatatreeArray();

            $tmp = array($datatype->getId() => 0);
            $datatypes_to_delete = array($datatype->getId() => 0);

            // If datatype has metadata, delete metadata
            if ( !is_null($datatype->getMetadataDatatype()) )
                $datatypes_to_delete[ $datatype->getMetadataDatatype()->getId() ] = 0;

            while ( count($tmp) > 0 ) {
                $new_tmp = array();
                foreach ($tmp as $dt_id => $num) {
                    $child_datatype_ids = array_keys($datatree_array['descendant_of'], $dt_id);
                    foreach ($child_datatype_ids as $num => $child_datatype_id) {
                        $new_tmp[$child_datatype_id] = 0;
                        $datatypes_to_delete[$child_datatype_id] = 0;
                    }
                    unset( $tmp[$dt_id] );
                }
                $tmp = $new_tmp;
            }
            $datatypes_to_delete = array_keys($datatypes_to_delete);

            //print '<pre>'.print_r($datatypes_to_delete, true).'</pre>'; exit();

            // Need to also find which datafields are affected by this
            $query = $this->em->createQuery(
               'SELECT df.id AS df_id
                FROM ODRAdminBundle:DataFields AS df
                WHERE df.dataType IN (:datatype_ids)
                AND df.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatypes_to_delete) );
            $results = $query->getArrayResult();

            $datafields_to_delete = array();
            foreach ($results as $result)
                $datafields_to_delete[] = $result['df_id'];

            // If any of the datafields being deleted are being used as a sortfield for other
            //  datatypes, then need to clear the default sort order for those datatypes
            $query = $this->em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataFields AS df
                LEFT JOIN ODRAdminBundle:DataTypeSpecialFields AS dtsf WITH dtsf.dataField = df
                LEFT JOIN ODRAdminBundle:DataType AS dt WITH dtsf.dataType = dt
                WHERE df.id IN (:datafields_to_delete) AND dtsf.field_purpose = :field_purpose
                AND df.deletedAt IS NULL AND dtsf.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datafields_to_delete' => $datafields_to_delete,
                    'field_purpose' => DataTypeSpecialFields::SORT_FIELD
                )
            );
            $results = $query->getResult();

            $datatypes_to_reset_order = array();
            foreach ($results as $dt) {
                /** @var DataType $dt */
                $datatypes_to_reset_order[ $dt->getId() ] = $dt;
            }

            // Don't need to fire off resets for datatypes that are getting deleted though
            foreach ($datatypes_to_delete as $num => $dt_id) {
                if ( isset($datatypes_to_reset_order[$dt_id]) )
                    unset( $datatypes_to_reset_order[$dt_id] );
            }


            // ----------------------------------------
            // Need to also locate any datatypes that link to any of the datatypes being deleted
            $query = $this->em->createQuery(
               'SELECT ancestor
                FROM ODRAdminBundle:DataType AS descendant
                JOIN ODRAdminBundle:DataTree AS dt WITH dt.descendant = descendant
                JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
                JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                WHERE descendant.id IN (:datatypes_to_delete) AND dtm.is_link = 1
                AND descendant.deletedAt IS NULL AND dt.deletedAt IS NULL
                AND ancestor.deletedAt IS NULL'
            )->setParameters( array('datatypes_to_delete' => $datatypes_to_delete) );
            $results = $query->getResult();

            $linked_ancestor_datatypes = array();
            foreach ($results as $dt) {
                /** @var DataType $dt */
                $linked_ancestor_datatypes[ $dt->getId() ] = $dt;

                // This is for marking those datatype as updated after the link is broken, so don't
                //  want the grandparent datatypes here
            }

            // Don't need to update any ancestor datatypes that are getting deleted though
            foreach ($datatypes_to_delete as $num => $dt_id) {
                if ( isset($linked_ancestor_datatypes[$dt_id]) )
                    unset( $linked_ancestor_datatypes[$dt_id] );
            }

            // Get the ids of all LinkedDataTree entries that need to be deleted
            $query = $this->em->createQuery(
               'SELECT ldt.id AS ldt_id
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                WHERE (ancestor.dataType IN (:datatype_ids) OR descendant.dataType IN (:datatype_ids))
                AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatypes_to_delete) );
            $results = $query->getArrayResult();

            $linked_datatrees_to_delete = array();
            foreach ($results as $ldt)
                $linked_datatrees_to_delete[ $ldt['ldt_id'] ] = 0;
            $linked_datatrees_to_delete = array_keys($linked_datatrees_to_delete);
            // There shouldn't be any duplicates here, since none of the datatypes getting deleted
            //  can link to each other


            // ----------------------------------------
            // Determine all Groups and all Users affected by this
            $query = $this->em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:Group AS g
                WHERE g.dataType IN (:datatype_ids)
                AND g.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatypes_to_delete) );
            $results = $query->getArrayResult();

            $groups_to_delete = array();
            foreach ($results as $result)
                $groups_to_delete[ $result['group_id'] ] = 0;
            $groups_to_delete = array_keys($groups_to_delete);

            //print '<pre>'.print_r($groups_to_delete, true).'</pre>';  exit();

            $query = $this->em->createQuery(
               'SELECT u.id AS user_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE ug.group IN (:groups) AND ug.deletedAt IS NULL'
            )->setParameters( array('groups' => $groups_to_delete) );
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
            )->setParameters( array('datatype_ids' => $datatypes_to_delete) );
            $results = $query->getArrayResult();

            $cached_themes_to_delete = array();
            foreach ($results as $result)
                $cached_themes_to_delete[ $result['theme_id'] ] = 0;
            $cached_themes_to_delete = array_keys($cached_themes_to_delete);

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
            // Delete the LinkedDataTree entries...the query could technically be done a different
            //  way, but this is consistent with the rest of the multi-table updates
            $query_str =
               'UPDATE odr_linked_data_tree AS ldt
                SET ldt.deletedAt = NOW(), ldt.deletedBy = '.$user->getId().'
                WHERE ldt.id IN (?)';
            $parameters = array(1 => $linked_datatrees_to_delete);
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

            // Delete all DatatypeSpecialField entries for the datatypes that are getting deleted
            $query_str =
               'UPDATE odr_data_type_special_fields AS dtsf
                SET dtsf.deletedAt = NOW(), dtsf.deletedBy = '.$user->getId().'
                WHERE dtsf.data_type_id IN (?) OR dtsf.data_field_id IN (?)
                AND dtsf.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete, 2 => $datafields_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);


            // ----------------------------------------
            // Delete all StoredSearchKey entries for the datatypes that are getting deleted
            $query_str =
               'UPDATE odr_stored_search_keys AS ssk
                SET ssk.deletedAt = NOW(), ssk.deletedBy = '.$user->getId().'
                WHERE ssk.data_type_id IN (?)
                AND ssk.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);


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
               'SELECT dt.id AS dt_id
                FROM ODRAdminBundle:DataTree AS dt
                JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
                JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
                WHERE (ancestor.id IN (:datatype_ids) OR descendant.id IN (:datatype_ids))
                AND dt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatypes_to_delete) );
            $results = $query->getArrayResult();

            $datatree_ids = array();
            foreach ($results as $dt)
                $datatree_ids[] = $dt['dt_id'];
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
            // Delete all RenderPluginInstance entries
            $query_str =
               'UPDATE odr_render_plugin_instance AS rpi
                SET rpi.deletedAt = NOW()
                WHERE rpi.data_type_id IN (?) OR rpi.data_field_id IN (?)
                AND rpi.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete, 2 => $datafields_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);

            // Delete all RenderPluginMap entries
            $query_str =
               'UPDATE odr_render_plugin_map AS rpm
                SET rpm.deletedAt = NOW()
                WHERE rpm.data_type_id IN (?) OR rpm.data_field_id IN (?)
                AND rpm.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete, 2 => $datafields_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);


            // ----------------------------------------
            // Delete all SidebarLayoutMap entries
            $query_str =
               'UPDATE odr_sidebar_layout_map AS slm
                SET slm.deletedAt = NOW()
                WHERE slm.data_type_id IN (?) OR slm.data_field_id IN (?)
                AND slm.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete, 2 => $datafields_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);

            // Delete all SidebarLayoutPreferences entries
            $query_str =
               'UPDATE odr_sidebar_layout_preferences AS slp, odr_sidebar_layout AS sl
                SET slp.deletedAt = NOW()
                WHERE sl.data_type_id IN (?)
                AND slp.sidebar_layout_id = sl.id
                AND slp.deletedAt IS NULL AND sl.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);

            // Delete all SidebarLayout and SidebarLayoutMeta entries
            $query_str =
               'UPDATE odr_sidebar_layout AS sl, odr_sidebar_layout_meta AS slm
                SET sl.deletedAt = NOW(), slm.deletedAt = NOW(), sl.deletedBy = '.$user->getId().'
                WHERE slm.sidebar_layout_id = sl.id AND sl.data_type_id IN (?)
                AND sl.deletedAt IS NULL AND slm.deletedAt IS NULL';
            $parameters = array(1 => $datatypes_to_delete);
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
            // No error encountered, commit changes
            $conn->commit();


            // ----------------------------------------
            // Notify which datatype got deleted
            try {
                $event = new DatatypeDeletedEvent($datatype_id, $datatype_uuid, $user, $deleting_top_level_datatype);
                $this->event_dispatcher->dispatch(DatatypeDeletedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...unlike most other events, kind of want to throw errors here if they occur
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
                    throw $e;
            }


            // ----------------------------------------
            // There could be a lot of datatypes that need updated, so try to reduce the number of
            //  events fired off
            $datatypes_needing_events = array();

            // If the datatype that just got deleted was not a top-level...
            if ( !$deleting_top_level_datatype ) {
                $datatypes_needing_events[ $parent_datatype->getId() ] = $parent_datatype;
                // This also means the grandparent datatype will get updated, so the subsequent
                //  arrays don't need to duplicate that work
            }

            // If a datatype was using one of the now-deleted fields as a sort field...
            foreach ($datatypes_to_reset_order as $dt_id => $dt) {
                // Don't need to directly check $deleting_top_level_datatype here...the only part
                //  that matters is this if statement
                if ( $dt_id !== $grandparent_datatype_id )
                    $datatypes_needing_events[ $dt_id ] = $dt;
            }
            // ...or if a datatype linked to one of the now-deleted datatypes
            foreach ($linked_ancestor_datatypes as $dt_id => $dt) {
                // Don't need to directly check $deleting_top_level_datatype here...the only part
                //  that matters is this if statement
                if ( $dt_id !== $grandparent_datatype_id )
                    $datatypes_needing_events[ $dt_id ] = $dt;
            }

            // All these cases need to fire off a modified event for the datatype...
            foreach ($datatypes_needing_events as $dt_id => $dt) {
                try {
                    $event = new DatatypeModifiedEvent($dt, $user, true);    // ...and they all need to rebuild cache entries
                    $this->event_dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...unlike most other events, kind of want to throw errors here if they occur
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
                        throw $e;
                }
            }

            // This cache entry also needs to be deleted when sort fields are changed
            foreach ($datatypes_to_reset_order as $dt_id => $dt)
                $this->cache_service->delete('datatype_'.$dt_id.'_record_order');

            // This cache entries should also be deleted when linked datatypes are changed
            foreach ($linked_ancestor_datatypes as $dt_id => $dt)
                $this->cache_service->delete('associated_datatypes_for_'.$dt_id);
            foreach ($datatypes_to_delete as $dt_id => $dt)
                $this->cache_service->delete('inverse_associated_datatypes_for_'.$dt_id);


            // ----------------------------------------
            // Also need to delete cached theme stuff that references these datatypes...
            foreach ($cached_themes_to_delete as $num => $t_id)
                $this->cache_service->delete('cached_theme_'.$t_id);

            // ...as well as any permissions
            foreach ($all_affected_users as $user_id)
                $this->cache_service->delete('user_'.$user_id.'_permissions');

            // There are a couple other cache entries that might have referenced this datatype
            $this->cache_service->delete('dashboard_'.$grandparent_datatype_id);
            $this->cache_service->delete('dashboard_'.$grandparent_datatype_id.'_public_only');

            $this->cache_service->delete('default_radio_options');
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


    /**
     * Deletes the given file.
     *
     * @param File $file
     * @param ODRUser $user
     */
    public function deleteFile($file, $user)
    {
        try {
            // Going to need these
            $file_id = $file->getId();
            $datafield = $file->getDataField();
            $datarecord = $file->getDataRecord();


            // -----------------------------------
            // Delete the decrypted version of this file from the server, if it exists
            $file_upload_path = $this->odr_web_dir.'/uploads/files/';
            $filename = 'File_'.$file->getId().'.'.$file->getExt();
            $absolute_path = realpath($file_upload_path).'/'.$filename;

            if ( file_exists($absolute_path) )
                unlink($absolute_path);

            // Delete the file and its current metadata entry
            $file_meta = $file->getFileMeta();
            $file_meta->setDeletedAt(new \DateTime());
            $this->em->persist($file_meta);

            $file->setDeletedBy($user);
            $file->setDeletedAt(new \DateTime());
            $this->em->persist($file);

            $this->em->flush();


            // -----------------------------------
            // Notify that a file got deleted...
            try {
                $event = new FileDeletedEvent($file_id, $datafield, $datarecord, $user);
                $this->event_dispatcher->dispatch(FileDeletedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't particularly want to rethrow the error since it'll interrupt
                //  everything downstream of the event (such as file encryption...), but
                //  having the error disappear is less ideal on the dev environment...
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                   throw $e;
            }

            // ...and that something happened to the datafield...
            try {
                $event = new DatafieldModifiedEvent($datafield, $user);
                $this->event_dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // ...and finally that something happened to the datarecord
            try {
                $event = new DatarecordModifiedEvent($datarecord, $user);
                $this->event_dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }
        }
        catch (\Exception $e) {
            $source = 0xcec4fdf6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Deletes the given image.
     *
     * @param Image $image
     * @param ODRUser $user
     */
    public function deleteImage($image, $user)
    {
        try {
            // Ensure this only works on the original image
            if ( !is_null($image->getParent()) )
                $image = $image->getParent();

            // Going to need these
            $datafield = $image->getDataField();
            $datarecord = $image->getDataRecord();


            // -----------------------------------
            // Load all alternate sizes of the original image (currently just a thumbnail) and delete
            //  them
            /** @var Image[] $images */
            $images = $this->em->getRepository('ODRAdminBundle:Image')->findBy(
                array('parent' => $image->getId())
            );
            foreach ($images as $img) {
                // Ensure no decrypted version of any of the thumbnails exist on the server
                $image_upload_path = $this->odr_web_dir.'/uploads/images/';
                $filename = 'Image_'.$img->getId().'.'.$img->getExt();
                $absolute_path = realpath($image_upload_path).'/'.$filename;

                if ( file_exists($absolute_path) )
                    unlink($absolute_path);

                // Delete the alternate sized image from the database
                $img->setDeletedBy($user);
                $img->setDeletedAt(new \DateTime());
                $this->em->persist($img);
            }

            // Ensure no decrypted version of the original image exists on the server
            $image_upload_path = $this->odr_web_dir.'/uploads/images/';
            $filename = 'Image_'.$image->getId().'.'.$image->getExt();
            $absolute_path = realpath($image_upload_path).'/'.$filename;

            if ( file_exists($absolute_path) )
                unlink($absolute_path);


            // Delete the image's meta entry
            $image_meta = $image->getImageMeta();
            $image_meta->setDeletedAt(new \DateTime());
            $this->em->persist($image_meta);

            // Delete the image
            $image->setDeletedBy($user);
            $image->setDeletedAt(new \DateTime());
            $this->em->persist($image);

            $this->em->flush();


            // ----------------------------------------
            // Notify that something happened to the datafield...
            try {
                $event = new DatafieldModifiedEvent($datafield, $user);
                $this->event_dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // ...and to the datarecord
            try {
                $event = new DatarecordModifiedEvent($datarecord, $user);
                $this->event_dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }
        }
        catch (\Exception $e) {
            $source = 0x2d7604fa;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
