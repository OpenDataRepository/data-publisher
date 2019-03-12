<?php

/**
 * Open Data Repository Data Publisher
 * Clone Template Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains the functions required to clone or sync a datatype with its master template.
 * Apparently also works with metadata datatypes, provided they have a master template of their own.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\LockHandler;


class CloneTemplateService
{

    /**
     * @var EntityManager $em
     */
    private $em;

    /**
     * @var EntityMetaModifyService
     */
    private $emm_service;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var CloneThemeService
     */
    private $ct_service;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var EntityCreationService
     */
    private $ec_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var ThemeInfoService
     */
    private $ti_service;

    /**
     * @var UUIDService
     */
    private $uuid_service;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var DataType[]
     */
    private $template_datatypes;

    /**
     * @var DataFields[]
     */
    private $template_datafields;

    /**
     * @var RadioOptions[]
     */
    private $template_radio_options;

    /**
     * @var DataType[]
     */
    private $derived_datatypes;

    /**
     * @var DataFields[]
     */
    private $derived_datafields;

    /**
     * @var RadioOptions[]
     */
    private $derived_radio_options;

    /**
     * @var DataType[]
     */
    private $modified_linked_datatypes;


    /**
     * CloneTemplateService constructor.
     *
     * @param EntityManager $entity_manager
     * @param EntityMetaModifyService $entityMetaModifyService
     * @param CacheService $cache_service
     * @param CloneThemeService $cloneThemeService
     * @param DatatypeInfoService $datatypeInfoService
     * @param EntityCreationService $entityCreationService
     * @param PermissionsManagementService $pm_service
     * @param ThemeInfoService $themeInfoService
     * @param UUIDService $UUIDService
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        EntityMetaModifyService $entityMetaModifyService,
        CacheService $cache_service,
        CloneThemeService $cloneThemeService,
        DatatypeInfoService $datatypeInfoService,
        EntityCreationService $entityCreationService,
        PermissionsManagementService $pm_service,
        ThemeInfoService $themeInfoService,
        UUIDService $UUIDService,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->emm_service = $entityMetaModifyService;
        $this->cache_service = $cache_service;
        $this->ct_service = $cloneThemeService;
        $this->dti_service = $datatypeInfoService;
        $this->ec_service = $entityCreationService;
        $this->pm_service = $pm_service;
        $this->ti_service = $themeInfoService;
        $this->uuid_service = $UUIDService;
        $this->logger = $logger;

        $this->template_datatypes = array();
        $this->template_datafields = array();
        $this->template_radio_options = array();
        $this->derived_datatypes = array();
        $this->derived_datafields = array();
        $this->derived_radio_options = array();
        $this->modified_linked_datatypes = array();
    }


    /**
     * Saves and reloads the provided object from the database.
     *
     * @param mixed $obj
     * @param ODRUser $user
     * @param bool $delay_flush
     */
    private function persistObject($obj, $user, $delay_flush = false)
    {
        //
        if (method_exists($obj, "setCreated"))
            $obj->setCreated(new \DateTime());
        if (method_exists($obj, "setUpdated"))
            $obj->setUpdated(new \DateTime());

        //
        if ($user != null) {
            if (method_exists($obj, "setCreatedBy"))
                $obj->setCreatedBy($user);

            if (method_exists($obj, "setUpdatedBy"))
                $obj->setUpdatedBy($user);
        }

        $this->em->persist($obj);

        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($obj);
        }
    }


    /**
     * Returns true if the provided Datatype is missing Datafields and/or child/linked Datatypes
     * that its Master Template has.  Also, the user needs to be capable of actually making changes
     * to the layout for this to return true.
     *
     * @param DataType $datatype
     * @param ODRUser $user
     *
     * @return bool
     */
    public function canSyncWithTemplate($datatype, $user)
    {
        // ----------------------------------------
        // If the user isn't allowed to make changes to this datatype, or the datatype is not
        //  derived from a master template, then it makes no sense to continue...
        if ($user === 'anon.')
            return false;
        if ( !$this->pm_service->isDatatypeAdmin($user, $datatype) )
            return false;
        if ( is_null($datatype->getMasterDataType()) )
            return false;


        // ----------------------------------------
        // Determine if this datatype's master template has any datafields/datatypes that the
        //  given datatype does not have...
        $diff = self::getDiffWithTemplate($datatype);
        if ( count($diff) > 0 )
            return true;

        // Otherwise, no appreciable changes have been made...no need to synchronize
        return false;
    }


    /**
     * Locates all datatypes/datafields in the given datatype's master template that aren't in
     *  the given datatype.
     *
     * @param DataType $datatype
     *
     * @return array
     */
    public function getDiffWithTemplate($datatype)
    {
        // ----------------------------------------
        if ( is_null($datatype->getMetadataFor()) ) {
            // This is not a metadata datatype...
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Child datatypes should not be directly checked for differences...check their grandparent datatypes instead');
        }
        else {
            // This is a metadata datatype...require it to be top-level
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Only top-level metadata datatypes should be checked for differences');
        }
        if ( is_null($datatype->getMasterDataType()) )
            throw new ODRBadRequestException('The given datatype is not derived from a Master Template...unable to check for differences');


        // At the moment, only new datafields, new child/linked datatypes, and fieldtype changes
        //  are considered "noteworthy"...TODO - is there more stuff that should be?


        // ----------------------------------------
        // Load, stack, and clean the cached_datatype array for the master template
        $master_datatype = $datatype->getMasterDataType();
        $template_datatype = $this->dti_service->getDatatypeArray($datatype->getMasterDataType()->getId());
        $template_datatype[ $master_datatype->getId() ] = $this->dti_service->stackDatatypeArray($template_datatype, $master_datatype->getId());

        // TODO - stackDatatypeArray() apparently leaves the child/linked datatypes lying around in the stacked array?
        foreach ($template_datatype as $dt_id => $dt) {
            if ( $dt_id !== $master_datatype->getId() )
                unset( $template_datatype[$dt_id] );
        }
        $template_datatype = self::cleanDatatypeArray($template_datatype);


        // ----------------------------------------
        // Load, stack, and clean the cached_datatype array for the derived datatype
        $derived_datatype = $this->dti_service->getDatatypeArray($datatype->getId());
        $derived_datatype[ $datatype->getId() ] = $this->dti_service->stackDatatypeArray($derived_datatype, $datatype->getId());

        // TODO - stackDatatypeArray() apparently leaves the child/linked datatypes lying around in the stacked array?
        foreach ($derived_datatype as $dt_id => $dt) {
            if ( $dt_id !== $datatype->getId() )
                unset( $derived_datatype[$dt_id] );
        }
        $derived_datatype = self::cleanDatatypeArray($derived_datatype);


        // ----------------------------------------
        // Remove all entries from the template's array that the derived datatype already has
        $diff = self::removeMatchingEntries($template_datatype, $derived_datatype);

        return $diff;
    }


    /**
     * It's easier to use the existing cached_datatype arrays to detect template-related changes,
     *  but there's a lot of stuff in that array that's not relevant to this...
     *
     * @param array $datatype
     * @param bool $is_top_level
     *
     * @return array
     */
    private function cleanDatatypeArray($datatype)
    {
        // Only want to keep these keys...defining it this way because isset() is faster than in_array()
        $keep = array(
            'id' => 1,
//            'is_master_type' => 1,
//            'unique_id' => 1,
            'template_group' => 1,
//            'metadata_datatype' => 1,
//            'metadata_for' => 1,
            'masterDataType' => 1,
            'dataFields' => 1,
            'descendants' => 1,

            // TODO - utilize $copy_theme_structure ?
            'copy_theme_structure' => 1,
        );

        foreach ($datatype as $dt_id => $dt) {
            // This flag controls whether the template's theme_elements/theme_datafield entries are
            //  cloned directly from the template later during the synchronization process.
            // It's set to false when the derived datatype has at least one datafield from the
            //  master template already, but needs additional datafields to be "in sync".
            $datatype[$dt_id]['copy_theme_structure'] = 1;

            foreach ($dt as $key => $value) {
                if ( !isset($keep[$key]) )
                    unset( $datatype[$dt_id][$key] );
            }

            // Clean up all the unneeded stuff in the datafields segment of the array...
            if ( isset($dt['dataFields']) )
                $datatype[$dt_id]['dataFields'] = self::cleanDatafieldArray($dt['dataFields']);

            // Clean up child/linked datatypes as well...
            if ( isset($dt['descendants']) ) {
                foreach ($dt['descendants'] as $c_dt_id => $c_dt) {
                    // Save for later convenience
                    $datatype[$dt_id]['descendants'][$c_dt_id]['is_top_level'] = 0;

                    $datatype[$dt_id]['descendants'][$c_dt_id]['datatype'] = self::cleanDatatypeArray($c_dt['datatype']);
                }
            }
        }

        return $datatype;
    }


    /**
     * For logistical simplicity, it's easier to split this out from self::cleanDatatypeArray()...
     *
     * @param array $datafields
     *
     * @return array
     */
    private function cleanDatafieldArray($datafields)
    {
        // Only want to keep these keys...defining it this way because isset() is faster than in_array()
        $keep = array(
            'id' => 1,
//            'is_master_field' => 1,
//            'fieldUuid' => 1,
//            'templateFieldUuid' => 1,
            'masterDataField' => 1,
            'fieldType' => 1,
            'radioOptions' => 1,
        );

        foreach ($datafields as $df_id => $df) {
            // Move the fieldtype from the datafieldMeta entry into the datafield itself
            $datafields[$df_id]['fieldType'] = $df['dataFieldMeta']['fieldType']['typeClass'];

            // Get rid of every key that's not relevant to comparing with a template...
            foreach ($df as $key => $value) {
                if ( !isset($keep[$key]) )
                    unset( $datafields[$df_id][$key] );
            }

            // Flatten the array of radio options if it exists
            if ( isset($df['radioOptions']) ) {
                $new_ro_list = array();
                foreach ($df['radioOptions'] as $num => $ro)
                    $new_ro_list[ $ro['radioOptionUuid'] ] = $ro['optionName'];
                $datafields[$df_id]['radioOptions'] = $new_ro_list;
            }
        }

        return $datafields;
    }


    /**
     * Crawl through two cached_datatype arrays...one for a master template, the second for a
     *  datatype derived from that template...and attempt to locate any differences between them.
     *
     * Areas where the derived datatype matches the template are removed from the template array,
     *  and anything leftover in the template array indicates the derived datatype is out of date.
     *
     * @param array $template_array
     * @param array $derived_array
     *
     * @return array
     */
    private function removeMatchingEntries($template_array, $derived_array)
    {
        // Each array should only have one key at the top-level, the datatype_id
        $t_dt_id = array_keys($template_array)[0];
        $dt_id = array_keys($derived_array)[0];

        // ----------------------------------------
        // Check for differences between datafields in this datatype...
        if ( isset($derived_array[$dt_id]['dataFields']) ) {
            $derived_datafields = $derived_array[$dt_id]['dataFields'];
            foreach ($derived_datafields as $df_id => $df) {
                // If a field was manually added in the derived datatype, ignore it
                if ( is_null($df['masterDataField']) )
                    continue;
                $master_df_id = $df['masterDataField']['id'];
                $fieldtype = $df['fieldType'];

                // Otherwise, see if the master datafield still exists in the template...
                if ( isset($template_array[$t_dt_id]['dataFields']) ) {
                    $template_datafields = $template_array[$t_dt_id]['dataFields'];

                    if ( !isset($template_datafields[$master_df_id]) ) {
                        // TODO - field deleted out of template datatype
                    }
                    else {
                        // Field exists
                        $change_made = false;

                        if ( $template_datafields[$master_df_id]['fieldType'] !== $fieldtype ) {
                            // The derived datafield has a different fieldtype than the template
                            //  datafield...TODO - figure out what it would take to enable this...
//                            $change_made = true;
                        }

                        if ( isset($template_datafields[$master_df_id]['radioOptions']) ) {
                            // Check radio options separately...
                            $template_options = $template_datafields[$master_df_id]['radioOptions'];
                            $derived_options = $derived_datafields[$df_id]['radioOptions'];
                            $new_radio_options = self::removeMatchingRadioOptions($template_options, $derived_options);

                            if ( count($new_radio_options) > 0 ) {
                                $change_made = true;
                                $template_array[$t_dt_id]['dataFields'][$master_df_id]['radioOptions'] = $new_radio_options;
                            }
                        }

                        if ( !$change_made ) {
                            // If there's no difference between this field in the derived datatype
                            //  and the template, then get rid of it
                            unset( $template_array[$t_dt_id]['dataFields'][$master_df_id] );

                            // Also, since the derived datatype has at least one datafield from the
                            //  template datatype...if any copying needs to be done later, don't
                            //  copy straight from the template's theme structure
                            $template_array[$t_dt_id]['copy_theme_structure'] = 0;
                        }
                    }
                }
            }
        }

        // ----------------------------------------
        // Check for differences between any child datatypes...
        if ( isset($derived_array[$dt_id]['descendants']) ) {
            foreach ($derived_array[$dt_id]['descendants'] as $c_dt_id => $c_dt) {
                // If a child/linked datatype was manually added in the derived datatype, ignore it
                if ( is_null($c_dt['datatype'][$c_dt_id]['masterDataType']) )
                    continue;
                $child_master_id = $c_dt['datatype'][$c_dt_id]['masterDataType']['id'];

                if ( !isset($template_array[$t_dt_id]['descendants'][$child_master_id]) ) {
                    // TODO - childtype deleted out of template datatype
                }
                else {
                    // Recursively determine if any changes were made to these child datatypes
                    $template_child_datatype = $template_array[$t_dt_id]['descendants'][$child_master_id]['datatype'];
                    $derived_child_datatype = $c_dt['datatype'];

                    $cleaned_template_child = self::removeMatchingEntries($template_child_datatype, $derived_child_datatype);

                    if ( count($cleaned_template_child) > 0 ) {
                        // If there were differences in this child datatype, then save them
                        $template_array[$t_dt_id]['descendants'][$child_master_id]['datatype'][$child_master_id] = $cleaned_template_child[$child_master_id];
                    }
                    else {
                        // No differences...leave no trace of this child datatype in the template array
                        unset( $template_array[$t_dt_id]['descendants'][$child_master_id]/*['datatype'][$child_master_id]*/ );
                    }
                }
            }
        }


        // ----------------------------------------
        // Assume no changes made to the template...
        $has_datafield_changes = false;
        $has_childtype_changes = false;

        if ( isset($template_array[$t_dt_id]['dataFields']) ) {
            if ( count($template_array[$t_dt_id]['dataFields']) > 0 )
                $has_datafield_changes = true;
            else
                unset( $template_array[$t_dt_id]['dataFields'] );
        }

        if ( isset($template_array[$t_dt_id]['descendants']) ) {
            if ( count($template_array[$t_dt_id]['descendants']) > 0 )
                $has_childtype_changes = true;
            else
                unset( $template_array[$t_dt_id]['descendants'] );
        }


        // If the derived datatype matches the template datatype, then return that no changes need to be made
        if ( !$has_datafield_changes && !$has_childtype_changes )
            return array();
        else
            return $template_array;
    }


    /**
     * For logisitical simplicity, it's easier to split this out from
     * self::removeMatchingEntries()...
     *
     * @param array $template_options
     * @param array $derived_options
     *
     * @return array
     */
    private function removeMatchingRadioOptions($template_options, $derived_options)
    {
        // For every radio option listed in the template datatype...
        foreach ($template_options as $ro_uuid => $option_name) {
            // ...if the derived datatype has a radio option with the same uuid and label...
            if ( isset($derived_options[$ro_uuid]) && $derived_options[$ro_uuid] === $option_name ) {
                // ...then the derived dataype's radio option doesn't need to be synchronized
                unset( $template_options[$ro_uuid] );
            }
        }

        // Return the pruned array
        return $template_options;
    }


    /**
     * Ensures the given datatype is synchronized with its master template.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     *
     * @return bool
     */
    public function syncWithTemplate($user, $datatype)
    {
        // ----------------------------------------
        // If the user isn't an admin of this datatype, don't do anything
        if ( !$this->pm_service->isDatatypeAdmin($user, $datatype) )
            return false;

        // If no changes need to be made, don't do anything
        $diff = self::getDiffWithTemplate($datatype);
        if ( count($diff) === 0 )
            return false;


        // Bad Things (tm) happen if multiple processes attempt to synchronize the same template at
        //  the same time, so use Symfony's LockHandler component to prevent that...
        $lockHandler = new LockHandler('datatype_'.$datatype->getId().'_sync_with_master.lock');
        if (!$lockHandler->lock()) {
            // Another process is already synchronizing this template...block until it's done...
            $lockHandler->lock(true);
            // ...then abort the synchronization without duplicating any changes
            return false;
        }


        $master_datatype = $datatype->getMasterDataType();
        $this->logger->info('----------------------------------------');
        $this->logger->info('CloneTemplateService: attempting to sync datatype '.$datatype->getId().' "'.$datatype->getShortName().'" with its master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"...');


        // ----------------------------------------
        // Need to get a list of all top-level datatypes associated with the master template
        $associated_datatypes = $this->cache_service->get('associated_datatypes_for_'.$master_datatype->getId());
        if ($associated_datatypes == false) {
            $associated_datatypes = $this->dti_service->getAssociatedDatatypes( array($master_datatype->getId()) );

            // Save the list of associated datatypes back into the cache
            $this->cache_service->set('associated_datatypes_for_'.$master_datatype->getId(), $associated_datatypes);
        }

        // Traverse the diff array to locate the template's datatypes/datafields/radio options
        //  that need to be hydrated
        self::locateEntriesToHydrate($diff);
        // Convert the arrays of datatype/datafield/radio option ids into a format for querying
        $query_datatypes = array_keys($this->template_datatypes);
        $query_datafields = array_keys($this->template_datafields);
        $query_radio_options = array_keys($this->template_radio_options);


        // For convenience, pre-load and hydrate all relevant datatypes across the master template
        $query = $this->em->createQuery(
           'SELECT dt
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.grandparent IN (:grandparent_ids) AND dt.id IN (:datatype_ids)
            AND dt.deletedAt IS NULL'
        )->setParameters(
            array(
                'grandparent_ids' => $associated_datatypes,
                'datatype_ids' => $query_datatypes,
            )
        );
        $results = $query->getResult();

        /** @var DataType $dt */
        $logging_contents = array();
        $this->template_datatypes = array();
        foreach ($results as $dt) {
            $this->template_datatypes[$dt->getId()] = $dt;
            $logging_contents[$dt->getId()] = $dt->getShortName();
        }
        $this->logger->debug('CloneTemplateService: -- modified template_datatypes: '.print_r($logging_contents, true));


        // Do the same for all relevant datafields across the master template
        $query = $this->em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
            WHERE dt.grandparent IN (:grandparent_ids) AND df.id IN (:datafield_ids)
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters(
            array(
                'grandparent_ids' => $associated_datatypes,
                'datafield_ids' => $query_datafields,
            )
        );
        $results = $query->getResult();

        /** @var DataFields $df */
        $logging_contents = array();
        $this->template_datafields = array();
        foreach ($results as $df) {
            $this->template_datafields[$df->getId()] = $df;
            $logging_contents[$df->getId()] = $df->getFieldName();
        }
        $this->logger->debug('CloneTemplateService: -- modified template_datafields: '.print_r($logging_contents, true));


        // Do the same for all relevant radio options across the master template
        $query = $this->em->createQuery(
           'SELECT ro
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
            JOIN ODRAdminBundle:RadioOptions AS ro WITH ro.dataField = df
            WHERE dt.grandparent IN (:grandparent_ids) AND ro.radioOptionUuid IN (:ro_uuids)
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND ro.deletedAt IS NULL'
        )->setParameters(
            array(
                'grandparent_ids' => $associated_datatypes,
                'ro_uuids' => $query_radio_options,
            )
        );
        $results = $query->getResult();

        /** @var RadioOptions $ro */
        $logging_contents = array();
        $this->template_radio_options = array();
        foreach ($results as $ro) {
            $this->template_radio_options[$ro->getRadioOptionUuid()] = $ro;
            $logging_contents[$ro->getRadioOptionUuid()] = $ro->getOptionName();
        }
        $this->logger->debug('CloneTemplateService: -- modified template_radio_options: '.print_r($logging_contents, true));


        // ----------------------------------------
        // Also need to get a list of all top-level datatypes associated with the derived datatype
        $associated_datatypes = $this->cache_service->get('associated_datatypes_for_'.$datatype->getId());
        if ($associated_datatypes == false) {
            $associated_datatypes = $this->dti_service->getAssociatedDatatypes( array($datatype->getId()) );

            // Save the list of associated datatypes back into the cache
            $this->cache_service->set('associated_datatypes_for_'.$datatype->getId(), $associated_datatypes);
        }


        // For convenience, pre-load and hydrate all datatypes across the derived datatype
        // Ignore the ones that don't have a masterDatatype, they'll never be updated with this
        $query = $this->em->createQuery(
           'SELECT dt
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.grandparent IN (:grandparent_ids) AND dt.masterDataType IN (:master_datatypes)
            AND dt.deletedAt IS NULL'
        )->setParameters(
            array(
                'grandparent_ids' => $associated_datatypes,
                'master_datatypes' => $query_datatypes,
            )
        );
        $results = $query->getResult();

        /** @var DataType $dt */
        $logging_contents = array();
        foreach ($results as $dt) {
            $this->derived_datatypes[$dt->getId()] = $dt;
            $logging_contents[$dt->getId()] = $dt->getShortName();
        }
        $this->logger->debug('CloneTemplateService: -- modified derived_datatypes: '.print_r($logging_contents, true));


        // Do the same for all datafields across the derived datatype
        // Ignore the ones that don't have a masterDatafield, they'll never be updated with this
        $query = $this->em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
            WHERE dt.grandparent IN (:grandparent_ids) AND df.masterDataField IN (:master_datafields)
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters(
            array(
                'grandparent_ids' => $associated_datatypes,
                'master_datafields' => $query_datafields,
            )
        );
        $results = $query->getResult();

        /** @var DataFields $df */
        $logging_contents = array();
        foreach ($results as $df) {
            $this->derived_datafields[$df->getId()] = $df;
            $logging_contents[$df->getId()] = $df->getFieldName();
        }
        $this->logger->debug('CloneTemplateService: -- modified derived_datafields: '.print_r($logging_contents, true));


        // Also need to locate all existing radio options that are going to be updated
        $query = $this->em->createQuery(
           'SELECT ro
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
            JOIN ODRAdminBundle:RadioOptions AS ro WITH ro.dataField = df
            WHERE dt.grandparent IN (:grandparent_ids) AND ro.radioOptionUuid IN (:ro_uuids)
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND ro.deletedAt IS NULL'
        )->setParameters(
            array(
                'grandparent_ids' => $associated_datatypes,
                'ro_uuids' => $query_radio_options,
            )
        );
        $results = $query->getResult();

        /** @var RadioOptions $ro */
        $logging_contents = array();
        $this->derived_radio_options = array();
        foreach ($results as $ro) {
            $this->derived_radio_options[$ro->getRadioOptionUuid()] = $ro;
            $logging_contents[$ro->getRadioOptionUuid()] = $ro->getOptionName();
        }
        $this->logger->debug('CloneTemplateService: -- modified derived_radio_options: '.print_r($logging_contents, true));


        // ----------------------------------------
        //
        self::syncDatatype($datatype, $user, $diff[$master_datatype->getId()], 0);

        // If a linked theme got modified, then the updates only went to its master theme...
        if ( count($this->modified_linked_datatypes) > 0 ) {
            // ...need to synchronize the master themes of the given datatype with their source themes
            //  so the updates are immediately visible
            $master_theme = $this->ti_service->getDatatypeMasterTheme($datatype->getId());

            $this->logger->info('CloneTemplateService: synchronizing datatype '.$datatype->getId().' "'.$datatype->getShortName().'" with its source to pick up changes to linked datatypes...');
            $this->ct_service->syncThemeWithSource($user, $master_theme);
        }


        // ----------------------------------------
        // Done with the synchronization
        $this->logger->info('CloneTemplateService: datatype '.$datatype->getId().' "'.$datatype->getShortName().'" is now synchronized with its master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"');
        $this->logger->info('----------------------------------------');

        // Wipe all potentially relevant cache entries
        $this->cache_service->delete('top_level_datatypes');
        $this->cache_service->delete('top_level_themes');
        $this->cache_service->delete('cached_datatree_array');

        foreach ($this->derived_datatypes as $dt) {
            $this->cache_service->delete('cached_datatype_'.$dt->getId());
            $this->cache_service->delete('associated_datatypes_for_'.$dt->getId());

            $master_theme = $this->ti_service->getDatatypeMasterTheme($dt->getId());
            $this->cache_service->delete('cached_theme_'.$master_theme->getId());
        }

        // TODO - this checker construct needs a database entry, but i'm pretty sure this isn't the intended use
        $datatype->setDatatypeType(null);
        $this->em->persist($datatype);
        $this->em->flush();

        return true;
    }


    /**
     * Recursively locates the ids of all the template datatypes/datafields/radio options that need
     * to be hydrated, and temporarily stores all of those ids in $this->template_datatypes,
     * $this->template_datafields, and $this->template_radio_options.
     *
     * @param array $diff
     */
    private function locateEntriesToHydrate($diff)
    {
        foreach ($diff as $dt_id => $dt) {
            // This datatype either has properties that changed, or needs to be created in the
            //  derived datatype
            $this->template_datatypes[$dt_id] = 1;

            if ( isset($dt['dataFields']) ) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    // This datafield also is either new or had properties that changed
                    $this->template_datafields[$df_id] = 1;

                    if ( isset($df['radioOptions']) ) {
                        foreach ($df['radioOptions'] as $ro_uuid => $ro_name) {
                            // Same for this radio option...
                            $this->template_radio_options[$ro_uuid] = 1;
                        }
                    }
                }
            }

            if ( isset($dt['descendants']) ) {
                foreach ($dt['descendants'] as $c_dt_id => $c_dt) {
                    // Recursively do the same thing for any child/linked datatypes of this datatype
                    self::locateEntriesToHydrate( $c_dt['datatype'] );
                }
            }
        }
    }


    /**
     * Recursively ensures that $derived_datatype is up to date with its master datatype.
     *
     * @param DataType $derived_datatype
     * @param ODRUser $user
     * @param array $diff_array
     * @param int $indent
     */
    private function syncDatatype($derived_datatype, $user, $diff_array, $indent)
    {
        // Debugging assistance on recursive functions...
        $indent_text = '';
        for ($i = 0; $i < $indent; $i++)
            $indent_text .= ' --';

        $this->logger->debug('CloneTemplateService:'.$indent_text.' synchronizing datatype '.$derived_datatype->getId().' "'.$derived_datatype->getShortName().'" with its master datatype '.$derived_datatype->getMasterDataType()->getId().' "'.$derived_datatype->getMasterDataType()->getShortName().'"...');

        $master_theme = $this->ti_service->getDatatypeMasterTheme($derived_datatype->getId());
        $template_master_theme = $this->ti_service->getDatatypeMasterTheme($derived_datatype->getMasterDataType()->getId());

        // Going to increment this number a few times to "fake" updates, mostly so CloneThemeService
        //  continues to work as expected
        $source_sync_version = $master_theme->getSourceSyncVersion();
        if ( is_null($source_sync_version) )
            $source_sync_version = 0;


        // ----------------------------------------
        // Create/modify all datafields necessary
        $created_datafields = array();
        if ( isset($diff_array['dataFields']) ) {
            foreach ($diff_array['dataFields'] as $df_id => $df) {
                // Locate an existing datafield in the derived datatype that has $master_df as its
                //  masterDatafield, if possible
                $master_df = $this->template_datafields[$df_id];
                $master_dt = $master_df->getDataType();
                $derived_df = null;

                foreach ($this->derived_datafields as $d_df) {
                    if ( $d_df->getMasterDataField()->getId() === $master_df->getId() ) {
                        $derived_df = $d_df;
                        break;
                    }
                }

                // If that datafield does not exist, create it
                if ( is_null($derived_df) ) {
                    $new_df = clone $master_df;
                    $new_df->setMasterDataField($master_df);
                    $new_df->setTemplateFieldUuid($master_df->getFieldUuid());
                    $new_df->setFieldUuid($this->uuid_service->generateDatafieldUniqueId());
                    $new_df->setIsMasterField(false);
                    $new_df->setDataType($derived_datatype);

                    // Don't flush this immediately...
                    $derived_datatype->addDataField($new_df);
                    self::persistObject($new_df, $user, true);

                    $new_df_meta = clone $master_df->getDataFieldMeta();
                    $new_df_meta->setDataField($new_df);

                    // Don't flush this immediately...
                    $new_df->addDataFieldMetum($new_df_meta);
                    self::persistObject($new_df_meta, $user, true);

                    $created_datafields[ $master_df->getId() ] = $new_df;
                    $derived_df = $new_df;

                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- cloned new datafield "'.$new_df->getFieldName().'" (dt '.$derived_datatype->getId().') from master datafield '.$master_df->getId().' (dt_id '.$master_dt->getId().')');
                }

                $derived_df_typeclass = $derived_df->getFieldType()->getTypeClass();
                $master_df_typeclass = $master_df->getFieldType()->getTypeClass();
                if ( $derived_df_typeclass !== $master_df_typeclass ) {
                    // TODO - how to deal with change to the fieldtype of master datafield?
                    // TODO - refactor datafield migration so that it's not as slow?
                    // TODO - ...make datafield migration into a service?
                    // TODO - give the user the choice of which stuff to synchronize? (instead of "all at once")
                    // TODO - how to deal with deletion of datafields from the template?
//                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- datafield "'.$derived_df->getFieldName().'" (dt '.$derived_datatype->getId().') has the fieldtype "'.$derived_df_typeclass.'", while the master datafield '.$master_df->getId().' (dt_id '.$master_dt->getId().') has the fieldtype "'.$master_df_typeclass.'"');
                }

                // Create any missing radio options, or update the name of existing ones
                if ( isset($df['radioOptions']) ) {
                    foreach ($df['radioOptions'] as $ro_uuid => $option_name) {
                        $master_ro = $this->template_radio_options[$ro_uuid];

                        $derived_ro = null;
                        if ( isset($this->derived_radio_options[$ro_uuid]) )
                            $derived_ro = $this->derived_radio_options[$ro_uuid];

                        if ( is_null($derived_ro) ) {
                            // If the radio option does not exist, clone it
                            $new_ro = clone $master_ro;
                            $new_ro->setDataField($derived_df);

                            // Don't flush this immediately...
                            $derived_df->addRadioOption($new_ro);
                            self::persistObject($new_ro, $user, true);

                            $new_ro_meta = clone $master_ro->getRadioOptionMeta();
                            $new_ro_meta->setRadioOption($new_ro);

                            // Don't flush this immediately
                            $new_ro->addRadioOptionMetum($new_ro_meta);
                            self::persistObject($new_ro_meta, $user, true);

                            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- cloned new radio option "'.$new_ro->getOptionName().'" (df '.$derived_df->getId().') (dt '.$derived_datatype->getId().') from master radio option '.$master_ro->getId().' (df_id '.$master_df->getId().') (dt_id '.$master_dt->getId().')');
                        }
                        else {
                            // Otherwise, this is a name update
                            $properties['optionName'] = $option_name;
                            $this->emm_service->updateRadioOptionsMeta($user, $derived_ro, $properties, true);    // Don't want to immediately flush these changes

                            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- renamed radio option '.$derived_ro->getRadioOptionUuid().' to "'.$option_name.'" (df '.$derived_df->getId().') (dt '.$derived_datatype->getId().')');
                        }
                    }
                }
            }

            // Flush all the new/modified datafield/etc entities at once
            $derived_df = null;

            // If datafields got created, then they need to be attached to the datatype's theme...
            if ( count($created_datafields) > 0 ) {

                if ($diff_array['copy_theme_structure'] == 1) {
                    // If this flag is set, the derived datatype doesn't have any of the datafields
                    //  in the template datatype...therefore, it's possible to clone theme element
                    //  and themeDatafield settings straight from the template

                    // Using the hydrated version of the template datatype's theme instead of the
                    //  array version since it's easier to clone stuff that way...
                    foreach ($template_master_theme->getThemeElements() as $te) {
                        /** @var ThemeElement $te */
                        $tdf_list = $te->getThemeDataFields();

                        if ( count($tdf_list) > 0 ) {
                            // Create a new theme element to store the themeDatafield entries
                            // Do NOT clone the relevant source themeElement, as that seems to carry
                            //  over that source themeElement's themeDatafield list
                            $new_te = new ThemeElement();
                            $new_te->setTheme($master_theme);

                            $master_theme->addThemeElement($new_te);
                            self::persistObject($new_te, $user, true);

                            $new_te_meta = clone $te->getThemeElementMeta();
                            $new_te_meta->setThemeElement($new_te);

                            $new_te->addThemeElementMetum($new_te_meta);
                            self::persistObject($new_te_meta, $user, true);

                            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- cloned theme_element for derived datatype '.$derived_datatype->getId().' "'.$derived_datatype->getShortName().'"');

                            // Now clone each datafield in the theme element...
                            foreach ($tdf_list as $num => $tdf) {
                                /** @var ThemeDataField $tdf */
                                // Locate the new datafield
                                $master_df_id = $tdf->getDataField()->getId();
                                $derived_df = $created_datafields[$master_df_id];

                                // Clone the existing theme datafield entry
                                $new_tdf = clone $tdf;
                                $new_tdf->setThemeElement($new_te);
                                $new_tdf->setDataField($derived_df);

                                $new_te->addThemeDataField($new_tdf);
                                self::persistObject($new_tdf, $user, true);

                                $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- cloned theme_datafield entry for datafield '.$master_df_id.' "'.$derived_df->getFieldName().'"');
                            }
                        }
                        else {
                            // This is a theme element for a child/linked datatype...ignore for
                            //  now, it'll be cloned in the next segment
                        }
                    }
                }
                else {
                    // There were datafields already in here prior to synchronization...since the
                    //  user may have modified the display_order and/or size of the datafields that
                    //  already existed, we can't attempt to match the template structure, and
                    //  should instead attach the new datafields into a new ThemeElement

                    /** @var ThemeElement $new_te */
                    $new_te = null;

                    /** @var DataFields[] $created_datafields */
                    foreach ($created_datafields as $master_df_id => $new_df) {
                        //
                        $source_sync_version++;

                        // If a theme_element hasn't been created to store new themeDatafield entries
                        //  for this datatype, then create one now
                        if (is_null($new_te)) {
                            $new_te = $this->ec_service->createThemeElement($user, $master_theme, true);    // don't flush immediately...

                            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created new theme_element for derived datatype '.$derived_datatype->getId().' "'.$derived_datatype->getShortName().'"');
                        }

                        // Create a new ThemeDataField entry for this new datafield...
                        $this->ec_service->createThemeDatafield($user, $new_te, $new_df, true);    // don't flush immediately...
                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created new theme_datafield entry for datafield '.$new_df->getId().' "'.$new_df->getFieldName().'"');
                    }

                    // Flush the new theme_element and the new theme_datafield entries
                    $this->em->flush();
                }
            }
        }

        // Create the permission entries for each of the new datafields...
        foreach ($created_datafields as $master_df_id => $new_df) {
            $this->ec_service->createGroupsForDatafield($user, $new_df, true);
            $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created GroupDatafieldPermission entries for datafield '.$new_df->getId().' "'.$new_df->getFieldName().'" (master df '.$master_df_id.')');
        }

        // Can flush here now that the newly created datafields and their GroupDatafield entries
        // have been created
        $this->em->flush();


        // ----------------------------------------
        // Create all the datatypes (and their datafields) that don't exist
        if ( isset($diff_array['descendants']) ) {

            $copy_theme_structure = false;
            if ( $diff_array['copy_theme_structure'] == 1 )
                $copy_theme_structure = true;

            foreach ($diff_array['descendants'] as $c_dt_id => $c_dt) {
                // Locate this derived datatype's master template
                $master_datatype = $this->template_datatypes[$c_dt_id];

                $is_link = $c_dt['is_link'];

                $multiple_allowed = true;
                if ( $c_dt['multiple_allowed'] === 0 )
                    $multiple_allowed = false;


                // Locate the child/linked datatype in the derived datatype's "family", if possible
                $derived_child_datatype = null;
                foreach ($this->derived_datatypes as $dt) {
                    if ( $dt->getMasterDataType()->getId() === $c_dt_id ) {
                        $derived_child_datatype = $dt;
                        break;
                    }
                }

                if ( is_null($derived_child_datatype) ) {
                    // The derived datatype does not have the required child/linked datatype
                    $source_sync_version++;

                    // Nonexistant child and linked datatypes will need a new theme element...
                    $new_te = $this->ec_service->createThemeElement($user, $master_theme, true);    // don't flush immediately...

                    $new_te_meta = $new_te->getThemeElementMeta();
                    $new_te_meta->setDisplayOrder(999);    // send new theme elements to the back

                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created new theme_element for derived datatype '.$derived_datatype->getId());

                    if ($copy_theme_structure) {
                        // In order to copy the theme structure, the "original" theme element needs
                        //  to be located and a couple properties copied from its meta entry...
                        $query = $this->em->createQuery(
                           'SELECT tem
                            FROM ODRAdminBundle:Theme t
                            JOIN ODRAdminBundle:ThemeElement te WITH te.theme = t
                            JOIN ODRAdminBundle:ThemeElementMeta tem WITH tem.themeElement = te
                            JOIN ODRAdminBundle:ThemeDataType tdt WITH tdt.themeElement = te
                            WHERE t = :template_theme AND tdt.dataType = :template_datatype
                            AND t.deletedAt IS NULL AND te.deletedAt IS NULL
                            AND tem.deletedAt IS NULL AND tdt.deletedAt IS NULL'
                        )->setParameters(
                            array(
                                'template_theme' => $template_master_theme->getId(),
                                'template_datatype' => $master_datatype->getId(),
                            )
                        );
                        /** @var ThemeElementMeta $template_te_meta */
                        $sub_result = $query->getResult();
                        $template_te_meta = $sub_result[0];

                        $new_te_meta->setDisplayOrder( $template_te_meta->getDisplayOrder() );
                        $new_te_meta->setCssWidthMed( $template_te_meta->getCssWidthMed() );
                        $new_te_meta->setCssWidthXL( $template_te_meta->getCssWidthXL() );
                        $this->em->persist($new_te_meta);    // don't flush immediately

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- cloned dimensions of original theme_element');
                    }

                    if ( $is_link === 0 ) {
                        // ...then need to create a child datatype in that theme element
                        $derived_child_datatype = self::createChildDatatype($user, $new_te, $derived_datatype, $master_datatype, $multiple_allowed, $indent_text);
                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created new child datatype, derived from master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"...');
                    }
                    else {
                        // ...then need to create a linked datatype in that theme element
                        $derived_child_datatype = self::createLinkedDatatype($user, $new_te, $derived_datatype, $master_datatype, $multiple_allowed, $indent_text);
                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- created new linked datatype, derived from master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"...');

                        // May need to reference this later if the new linked datatype is referenced
                        //  more than once
                        $this->derived_datatypes[ $derived_child_datatype->getId() ] = $derived_child_datatype;
                    }
                }

                // Now that the child/linked datatype is guaranteed to exist, need to continue going
                //  through the diff array to ensure the child/linked datatype is up to date

                if ( $is_link === 0 ) {
                    // This is a child datatype...ensure it's up to date with its master template
                    $child_diff_array = $c_dt['datatype'][$derived_child_datatype->getMasterDataType()->getId()];
                    self::syncDatatype($derived_child_datatype, $user, $child_diff_array, $indent + 2);

                    $this->logger->debug('CloneTemplateService:'.$indent_text.' -- child datatype '.$derived_child_datatype->getId().' "'.$derived_child_datatype->getShortName().'" is up to date with its master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"');
                }
                else {
                    // This is a linked datatype...if a "primary" version was just created by
                    //  self::createLinkedDatatype(), then it already has the required theme,
                    //  theme element, and themeDatatype entries and self::linkToExistingDatatype()
                    //  should not be run.
                    $query = $this->em->createQuery(
                       'SELECT tdt
                        FROM ODRAdminBundle:Theme AS t
                        JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                        JOIN ODRAdminBundle:ThemeDataType AS tdt WITH tdt.themeElement = te
                        WHERE t.id = :master_theme_id AND tdt.dataType = :linked_datatype_id
                        AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdt.deletedAt IS NULL'
                    )->setParameters(
                        array(
                            'master_theme_id' => $master_theme->getId(),
                            'linked_datatype_id' => $derived_child_datatype->getId()
                        )
                    );
                    $results = $query->getArrayResult();

                    if ( count($results) === 0 ) {
                        // If a themeDatatype entry doesn't exist, then the derived datatype links
                        //  to this remote datatype more than once...another theme, theme element,
                        //  and themeDatatype entity is required for this additional instance
                        self::linkToExistingDatatype($user, $derived_datatype, $derived_child_datatype, $multiple_allowed, $indent_text);

                        $source_sync_version++;
                    }

                    // Only want to synchronize the "primary" version of the linked datatype once...
                    if ( !isset($this->modified_linked_datatypes[$derived_child_datatype->getId()]) ) {
                        // Don't run this block of code a second time
                        $this->modified_linked_datatypes[$derived_child_datatype->getId()] = $derived_child_datatype;

                        // Ensure the linked datatype is up to date with its master template
                        $child_diff_array = $c_dt['datatype'][$derived_child_datatype->getMasterDataType()->getId()];
                        self::syncDatatype($derived_child_datatype, $user, $child_diff_array, $indent + 2);

                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- linked datatype '.$derived_child_datatype->getId().' "'.$derived_child_datatype->getShortName().'" is up to date with its master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"');
                    }
                    else {
                        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- linked datatype '.$derived_child_datatype->getId().' "'.$derived_child_datatype->getShortName().'" is already up to date with its master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"');
                    }
                }
            }
        }

        // Update the source sync version property of the Theme that (might have) had new
        //  datafields and/or datatypes added to it
        if ( $source_sync_version !== $master_theme->getSourceSyncVersion() ) {
            $master_theme_meta = $master_theme->getThemeMeta();
            $master_theme_meta->setSourceSyncVersion($source_sync_version);
            $this->em->persist($master_theme_meta);
        }

        // Do a final flush
        $this->em->flush();
    }


    /**
     * Creates a new child datatype for compliance with templates
     *
     * @param ODRUser $user The user creating this child datatype
     * @param ThemeElement $theme_element The theme_element this child datatype is being attached to
     * @param DataType $parent_datatype The parent of this new child datatype
     * @param DataType $master_datatype The "master template" datatype for this child datatype
     * @param bool $multiple_allowed
     * @param string $indent_text For logging purposes
     *
     * @return DataType
     */
    private function createChildDatatype($user, $theme_element, $parent_datatype, $master_datatype, $multiple_allowed, $indent_text)
    {
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- creating new child datatype derived from master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"...');

        // Create a new datatype that uses $master_datatype as its template...
        $child_datatype = $this->ec_service->createDatatype(
            $user,
            $master_datatype->getShortName(),
            true
        );

        $child_datatype->setParent($parent_datatype);
        $child_datatype->setGrandparent($parent_datatype->getGrandparent());
        $child_datatype->setTemplateGroup($parent_datatype->getTemplateGroup());
        $child_datatype->setMasterDataType($master_datatype);

        $this->em->persist($child_datatype);

        $child_datatype_meta = $child_datatype->getDataTypeMeta();
        $child_datatype_meta->setRenderPlugin($master_datatype->getRenderPlugin());

        $this->em->persist($child_datatype_meta);
        $this->em->flush();

        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new child datatype '.$child_datatype->getId());


        // Need to create a Datatree entry connecting the new child Datatype...
        $is_link = false;
        $this->ec_service->createDatatree($user, $parent_datatype, $child_datatype, $is_link, $multiple_allowed);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new datatree entry between ancestor '.$parent_datatype->getId().' and descendant '.$child_datatype->getId());

        // ...and create a Theme for the new child Datatype...
        $child_theme = $this->ec_service->createTheme($user, $child_datatype, true);
        // Need to set this and at least somewhat keep it updated throughout this process, otherwise
        //  the CloneThemeService will tend to believe stuff is "up to date" even when it's not
        $child_theme_meta = $child_theme->getThemeMeta();
        $child_theme_meta->setSourceSyncVersion(0);
        $this->em->persist($child_theme_meta);

        $parent_master_theme = $this->ti_service->getDatatypeMasterTheme($parent_datatype->getGrandparent()->getId());
        $child_theme->setParentTheme($parent_master_theme);
        $this->em->persist($child_theme);

        $this->em->flush();
        $this->em->refresh($child_datatype);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new master theme for child datatype '.$child_datatype->getId());

        // If a child datatype is being created, then a themeDatatype entry will always need to be
        //  created as well...
        $this->ec_service->createThemeDatatype($user, $theme_element, $child_datatype, $child_theme, true);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created theme_datatype entry for child datatype '.$child_datatype->getId());

        // ...and finally need to create groups for the new child Datatype
        $this->ec_service->createGroupsForDatatype($user, $child_datatype);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- copied groups for child datatype '.$child_datatype->getId());


        // Child datatype is ready for use
        $child_datatype->setSetupStep(DataType::STATE_OPERATIONAL);
        $this->em->persist($child_datatype);

        $this->em->flush();

        return $child_datatype;
    }


    /**
     * Creates a new linked datatype for compliance with templates
     *
     * @param ODRUser $user The user creating this linked datatype
     * @param ThemeElement $theme_element The theme_element this linked datatype is being attached to
     * @param DataType $parent_datatype The datatype linking to this new datatype
     * @param DataType $master_datatype The "master template" datatype for this linked datatype
     * @param bool $multiple_allowed
     * @param string $indent_text For logging purposes
     *
     * @return DataType
     */
    private function createLinkedDatatype($user, $theme_element, $parent_datatype, $master_datatype, $multiple_allowed, $indent_text)
    {
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- creating new linked datatype derived from master datatype '.$master_datatype->getId().' "'.$master_datatype->getShortName().'"...');

        // Create a new datatype that uses $master_datatype as its template...
        $linked_datatype = $this->ec_service->createDatatype(
            $user,
            $master_datatype->getShortName(),
            true    // don't flush immediately...
        );

        $linked_datatype->setTemplateGroup($parent_datatype->getTemplateGroup());
        $linked_datatype->setMasterDataType($master_datatype);

        $this->em->persist($linked_datatype);

        $linked_datatype_meta = $linked_datatype->getDataTypeMeta();
        $linked_datatype_meta->setRenderPlugin($master_datatype->getRenderPlugin());

        $this->em->persist($linked_datatype_meta);
        $this->em->flush();

        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new linked datatype '.$linked_datatype->getId());


        // Need to create a Datatree entry connecting the new linked Datatype...
        $is_link = true;
        $this->ec_service->createDatatree($user, $parent_datatype, $linked_datatype, $is_link, $multiple_allowed);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new datatree entry between ancestor '.$parent_datatype->getId().' and descendant '.$linked_datatype->getId());


        // ...and create a top-level Theme for the new linked Datatype...
        $linked_theme = $this->ec_service->createTheme($user, $linked_datatype, true);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created new top-level theme for linked datatype '.$linked_datatype->getId());
        $this->em->persist($linked_theme);
        // Need to set this and at least somewhat keep it updated throughout this process, otherwise
        //  the CloneThemeService will tend to believe stuff is "up to date" even when it's not
        $linked_theme_meta = $linked_theme->getThemeMeta();
        $linked_theme_meta->setSourceSyncVersion(0);
        $this->em->persist($linked_theme_meta);


        // ...and create a copy of that Theme...
        $linked_theme_copy = $this->ec_service->createTheme($user, $linked_datatype, true);
        // ...the copy of $linked_datatype's theme needs to use $linked_theme as its source...
        $linked_theme_copy->setSourceTheme($linked_theme);

        $parent_master_theme = $this->ti_service->getDatatypeMasterTheme($parent_datatype->getGrandparent()->getId());
        // ...and reside inside $parent_datatype's theme group
        $linked_theme_copy->setParentTheme($parent_master_theme);

        $this->em->persist($linked_theme_copy);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- copied top-level theme to link within ancestor master theme '.$parent_master_theme->getId());

        // $parent_datatype needs a themeDatatype entry to point to the new linked datatype's theme
        $this->ec_service->createThemeDatatype($user, $theme_element, $linked_datatype, $linked_theme_copy, true);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created theme_datatype entry for linked datatype '.$linked_datatype->getId());

        // ...and finally need to create groups for the new linked Datatype
        $this->ec_service->createGroupsForDatatype($user, $linked_datatype);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- -- created groups for linked datatype '.$linked_datatype->getId());


        // Linked datatype is ready for use
        $linked_datatype->setSetupStep(DataType::STATE_OPERATIONAL);
        $this->em->persist($linked_datatype);

        $this->em->flush();

        return $linked_datatype;
    }


    /**
     * Link to an existing datatype for compliance with templates
     *
     * @param ODRUser $user
     * @param DataType $parent_datatype
     * @param DataType $linked_datatype
     * @param bool $multiple_allowed
     * @param string $indent_text For logging purposes
     */
    private function linkToExistingDatatype($user, $parent_datatype, $linked_datatype, $multiple_allowed, $indent_text)
    {
        // Need to locate the master themes for both $parent_datatype and $linked_datatype...
        $parent_datatype_master_theme = $this->ti_service->getDatatypeMasterTheme($parent_datatype->getId());
        $linked_datatype_master_theme = $this->ti_service->getDatatypeMasterTheme($linked_datatype->getId());

        $new_linked_theme = $this->ec_service->createTheme($user, $linked_datatype, true);
        $new_linked_theme->setSourceTheme($linked_datatype_master_theme);
        $new_linked_theme->setParentTheme($parent_datatype_master_theme->getParentTheme());
        $this->em->persist($new_linked_theme);

        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- (secondary) copied top-level theme to link within ancestor master theme '.$parent_datatype_master_theme->getId());

        // ...then need to create a new themeElement and themeDatatype to attach this linked datatype into
        $new_te = $this->ec_service->createThemeElement($user, $parent_datatype_master_theme, true);    // don't flush immediately...
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- (secondary) created new theme_element for derived datatype '.$parent_datatype->getId());

        $this->ec_service->createThemeDatatype($user, $new_te, $linked_datatype, $new_linked_theme, true);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- (secondary) created theme_datatype entry for linked datatype '.$linked_datatype->getId());


        // Also, the datatree entry linking the two datatypes doesn't exist at this point in time...create it
        $is_link = true;
        $this->ec_service->createDatatree($user, $parent_datatype, $linked_datatype, $is_link, $multiple_allowed);
        $this->logger->debug('CloneTemplateService:'.$indent_text.' -- (secondary) created new datatree entry between ancestor '.$parent_datatype->getId().' and descendant '.$linked_datatype->getId());

        $this->em->flush();
    }
}
