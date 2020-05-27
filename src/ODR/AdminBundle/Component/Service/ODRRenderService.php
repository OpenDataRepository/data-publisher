<?php

/**
 * Open Data Repository Data Publisher
 * ODR Render Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service contains the functions to render the datatype/theme/datarecord arrays (or pieces
 * of them) via twig.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class ODRRenderService
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
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var ThemeInfoService
     */
    private $theme_service;

    /**
     * @var CloneThemeService
     */
    private $clone_theme_service;

    /**
     * @var CloneTemplateService
     */
    private $clone_template_service;

    /**
     * @var EntityMetaModifyService
     */
    private $emm_service;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * ODRRenderService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatafieldInfoService $datafield_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param DatatypeInfoService $datatype_info_service
     * @param PermissionsManagementService $permissions_service
     * @param ThemeInfoService $theme_info_service
     * @param CloneThemeService $clone_theme_service
     * @param CloneTemplateService $clone_template_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatafieldInfoService $datafield_info_service,
        DatarecordInfoService $datarecord_info_service,
        DatatypeInfoService $datatype_info_service,
        PermissionsManagementService $permissions_service,
        ThemeInfoService $theme_info_service,
        CloneThemeService $clone_theme_service,
        CloneTemplateService $clone_template_service,
        EntityMetaModifyService $entity_meta_modify_service,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dfi_service = $datafield_info_service;
        $this->dri_service = $datarecord_info_service;
        $this->dti_service = $datatype_info_service;
        $this->pm_service = $permissions_service;
        $this->theme_service = $theme_info_service;
        $this->clone_theme_service = $clone_theme_service;
        $this->clone_template_service = $clone_template_service;
        $this->emm_service = $entity_meta_modify_service;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Renders and returns the HTML for the master layout design page.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     *
     * @return string
     */
    public function getMasterDesignHTML($user, $datatype)
    {
        // ----------------------------------------
        // Need an array of fieldtype ids and typenames for notifications when changing fieldtypes
        $fieldtype_array = array();
        /** @var FieldType[] $fieldtypes */
        $fieldtypes = $this->em->getRepository('ODRAdminBundle:FieldType')->findAll();
        foreach ($fieldtypes as $fieldtype)
            $fieldtype_array[ $fieldtype->getId() ] = $fieldtype->getTypeName();

        // Store whether this datatype has datarecords..affects warnings when changing fieldtypes
        $query = $this->em->createQuery(
           'SELECT COUNT(dr) AS dr_count
            FROM ODRAdminBundle:DataRecord AS dr
            WHERE dr.dataType = :datatype_id'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        $has_datarecords = false;
        if ( $results[0]['dr_count'] > 0 )
            $has_datarecords = true;


        // ----------------------------------------
        $template_name = 'ODRAdminBundle:Displaytemplate:design_ajax.html.twig';
        $extra_parameters = array(
            'fieldtype_array' => $fieldtype_array,
            'has_datarecords' => $has_datarecords,

            'sync_with_template' => false,
            'sync_metadata_with_template' => false,

            'datafield_properties' => array(),
        );

        $datarecord = null;

        // TODO - eventually replace with $this->theme_service->getPreferredTheme()?
        $theme = $this->theme_service->getDatatypeMasterTheme($datatype->getId());

        // Ensure all relevant themes are in sync before rendering the end result
        $extra_parameters['notify_of_sync'] = self::notifyOfThemeSync($theme, $user);


        // Check whether the datatype is up to date with its master template if applicable
        if ( !is_null($datatype->getMasterDataType()) )
            $extra_parameters['sync_with_template'] = $this->clone_template_service->canSyncWithTemplate($datatype, $user);

        // Also check whether the metadata datatype needs a sync
        if ( !is_null($datatype->getMetadataDatatype())
            && !is_null($datatype->getMetadataDatatype()->getMasterDataType())
        ) {
            $extra_parameters['sync_metadata_with_template'] =
                $this->clone_template_service->canSyncWithTemplate($datatype->getMetadataDatatype(), $user);
        }

        return self::getHTML($user, $template_name, $extra_parameters, $datatype, $datarecord, $theme);
    }


    /**
     * Renders and returns the HTML for the design page of a non-master theme.
     *
     * @param ODRUser $user
     * @param Theme $theme
     *
     * @return string
     */
    public function getThemeDesignHTML($user, $theme)
    {
        throw new ODRNotImplementedException();
    }


    /**
     * Renders and returns the HTML for the viewing of a single datarecord.
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param string $search_key
     * @param Theme|null $theme
     *
     * @return string
     */
    public function getDisplayHTML($user, $datarecord, $search_key, $theme = null)
    {
        $template_name = 'ODRAdminBundle:Display:display_ajax.html.twig';
        $extra_parameters = array(
            'is_top_level' => 1,    // TODO - get rid of this requirement

            'record_display_view' => 'single',
            'search_key' => $search_key,
        );

        $datatype = $datarecord->getDataType();

        if ( !is_null($theme) ) {
            if ( $theme->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();
        }
        else {
            $theme_id = $this->theme_service->getPreferredTheme($user, $datatype->getId(), 'master');
            $theme = $this->em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
        }

        // Ensure all relevant themes are in sync before rendering the end result
        $extra_parameters['notify_of_sync'] = self::notifyOfThemeSync($theme, $user);

        return self::getHTML($user, $template_name, $extra_parameters, $datatype, $datarecord, $theme);
    }


    /**
     * Renders and returns the HTML for editing a single datarecord.
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param string $search_key Can be the empty string
     * @param int $search_theme_id Can be 0, only used to correctly set a redirect
     * @param Theme|null $theme If not null, which Theme to render the datarecord with
     *
     * @return string
     */
    public function getEditHTML($user, $datarecord, $search_key, $search_theme_id, $theme = null)
    {
        $template_name = 'ODRAdminBundle:Edit:edit_ajax.html.twig';
        $extra_parameters = array(
            'is_top_level' => 1,    // TODO - get rid of this requirement
            'search_key' => $search_key,

            'token_list' => array(),

            'search_theme_id' => $search_theme_id,    // TODO - refactor to get rid of this?

            'linked_datatype_ancestors' => array(),
        );

        $datatype = $datarecord->getDataType();

        $cached_datatree_array = $this->dti_service->getDatatreeArray();
        if ( isset($cached_datatree_array['linked_from'][$datatype->getId()]) ) {
            $ancestor_ids = $cached_datatree_array['linked_from'][$datatype->getId()];
            $query = $this->em->createQuery(
               'SELECT dt, dtm
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                WHERE dt IN (:datatype_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $ancestor_ids) );
            $results = $query->getArrayResult();

            foreach ($results as $num => $dt) {
                $dt_id = $dt['id'];
                $dt['dataTypeMeta'] = $dt['dataTypeMeta'][0];
                $extra_parameters['linked_datatype_ancestors'][$dt_id] = $dt;
            }
        }

        if ( !is_null($theme) ) {
            if ( $theme->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();
        }
        else {
            // TODO - eventually replace with $this->theme_service->getPreferredTheme()?
            $theme = $this->theme_service->getDatatypeMasterTheme($datatype->getId());
        }

        // Ensure all relevant themes are in sync before rendering the end result
        $extra_parameters['notify_of_sync'] = self::notifyOfThemeSync($theme, $user);

        return self::getHTML($user, $template_name, $extra_parameters, $datatype, $datarecord, $theme);
    }


    /**
     * Renders and returns the HTML for "editing" a "fake" top-level datarecord...one that doesn't
     * actually exist in the database.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     *
     * @return string
     */
    public function getFakeEditHTML($user, $datatype)
    {
        $template_name = 'ODRAdminBundle:Edit:fake_edit_ajax.html.twig';
        $extra_parameters = array(
            'is_top_level' => 1,    // TODO - get rid of this requirement

            'search_key' => '',
            'search_theme_id' => 0,    // TODO - refactor to get rid of this?
            'notify_of_sync' => false,    // No need to check this parameter

            'token_list' => array(),

            'is_fake_datarecord' => true,
        );


        // TODO - eventually replace with $this->theme_service->getPreferredTheme()?
        $theme = $this->theme_service->getDatatypeMasterTheme($datatype->getId());

        return self::getHTML($user, $template_name, $extra_parameters, $datatype, null, $theme);
    }


    /**
     * Renders and returns the HTML for making some modification(s) to all datarecords in a search
     * result.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param string $odr_tab_id
     * @param Theme|null $theme
     *
     * @return string
     */
    public function getMassEditHTML($user, $datatype, $odr_tab_id, $theme = null)
    {
        $template_name = 'ODRAdminBundle:MassEdit:massedit_ajax.html.twig';
        $extra_parameters = array(
            'odr_tab_id' => $odr_tab_id,
            'include_links' => false,
        );

        $datarecord = null;

        if ( !is_null($theme) ) {
            if ( $theme->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();
        }
        else {
            // TODO - eventually replace with $this->theme_service->getPreferredTheme()?
            $theme = $this->theme_service->getDatatypeMasterTheme($datatype->getId());
        }

        // Not allowed to mass edit linked datarecords, so it doesn't make sense to ensure they're
        //  in sync first

        return self::getHTML($user, $template_name, $extra_parameters, $datatype, $datarecord, $theme);
    }


    /**
     * Renders and returns the HTML for users to select datafields they want exported into a CSV
     * file from all datarecords in a search result.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param string $odr_tab_id
     * @param Theme|null $theme
     *
     * @return string
     */
    public function getCSVExportHTML($user, $datatype, $odr_tab_id, $theme = null)
    {
        $template_name = 'ODRAdminBundle:CSVExport:csvexport_ajax.html.twig';
        $extra_parameters = array(
            'odr_tab_id' => $odr_tab_id,
//            'include_links' => false,
        );

        $datarecord = null;

        if ( !is_null($theme) ) {
            if ( $theme->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();
        }
        else {
            // TODO - eventually replace with $this->theme_service->getPreferredTheme()?
            $theme = $this->theme_service->getDatatypeMasterTheme($datatype->getId());
        }

        // Ensure all relevant themes are in sync before rendering the end result
        $extra_parameters['notify_of_sync'] = self::notifyOfThemeSync($theme, $user);

        return self::getHTML($user, $template_name, $extra_parameters, $datatype, $datarecord, $theme);
    }


    /**
     * Renders and returns the HTML for modifying the what a group is allowed to view/edit.
     *
     * @param ODRUser $user
     * @param Group $group
     *
     * @return string
     */
    public function getGroupHTML($user, $group)
    {
        // Not allowed to make any changes to the default groups
        $prevent_all_changes = false;
        if ( $group->getPurpose() !== '' )
            $prevent_all_changes = true;

        $template_name = 'ODRAdminBundle:ODRGroup:permissions_ajax.html.twig';
        $extra_parameters = array(
            'group' => $group,
            'prevent_all_changes' => $prevent_all_changes,

            'include_links' => false,
        );

        $datatype = $group->getDataType();
        $datarecord = null;

        $theme = $this->theme_service->getDatatypeMasterTheme($datatype->getId());

        // Modification of group permissions doesn't need linked themes to be updated

        return self::getHTML($user, $template_name, $extra_parameters, $datatype, $datarecord, $theme);
    }


    /**
     * Renders and returns the HTML for viewing an arbitrary theme as an arbitrary user.
     *
     * @param ODRUser $user
     * @param ODRUser $target_user
     * @param Theme $theme
     *
     * @return string
     */
    public function getViewAsUserHTML($user, $target_user, $theme)
    {
        $template_name = 'ODRAdminBundle:ODRUser:view_ajax.html.twig';
        $extra_parameters = array(
            'target_user' => $target_user
        );

        $datatype = $theme->getDataType();
        $datarecord = null;

        // Ensure all relevant themes are in sync before rendering the end result
        $extra_parameters['notify_of_sync'] = self::notifyOfThemeSync($theme, $user);

        return self::getHTML($user, $template_name, $extra_parameters, $datatype, $datarecord, $theme);
    }


    /**
     * Renders and returns the given template with the given parameters.
     *
     * @param ODRUser $user
     * @param string $template_name
     * @param array $extra_parameters
     * @param DataType $datatype
     * @param DataRecord|null $datarecord
     * @param Theme $theme
     *
     * @return string
     */
    private function getHTML($user, $template_name, $extra_parameters, $datatype, $datarecord, $theme)
    {
        // ----------------------------------------
        // Most of the pages want to display linked datatypes, but for those that don't...
        $include_links = true;
        if ( isset($extra_parameters['include_links']) )
            $include_links = $extra_parameters['include_links'];

        $is_fake_datarecord = false;
        if ( isset($extra_parameters['is_fake_datarecord']) && $extra_parameters['is_fake_datarecord'] === true )
            $is_fake_datarecord = true;

        // All templates need the datatype and theme arrays...
        $initial_datatype_id = $datatype->getId();
        $initial_theme_id = $theme->getId();
        $datatype_array = $this->dti_service->getDatatypeArray($initial_datatype_id, $include_links);
        $theme_array = $this->theme_service->getThemeArray($initial_theme_id);

        // ...only get the datarecord arrays if a datarecord was specified
        $initial_datarecord_id = null;
        $datarecord_array = array();
        if ( !is_null($datarecord) ) {
            // Load the requested datarecord's data from the cache
            $initial_datarecord_id = $datarecord->getId();
            $datarecord_array = $this->dri_service->getDatarecordArray($initial_datarecord_id, $include_links);
        }
        else if ( $is_fake_datarecord ) {
            // Otherwise, this is Edit mode attempting to render a "fake" datarecord...
            $fake_dr = $this->dri_service->createFakeDatarecordEntry($datatype_array, $datatype->getId());
            $initial_datarecord_id = $fake_dr['id'];

            $datarecord_array[$initial_datarecord_id] = $fake_dr;
        }


        // ----------------------------------------
        // The datatype/datarecord arrays need to be filtered so the user isn't allowed to see stuff
        //  they shouldn't...the theme array intentionally isn't filtered
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);
        if ( isset($extra_parameters['target_user']) ) {
            // If rendering for "view_as_user" mode, then use the target user's permissions instead
            $user_permissions = $this->pm_service->getUserPermissionsArray( $extra_parameters['target_user'] );
        }

        $datatype_permissions = $user_permissions['datatypes'];
        $datafield_permissions = $user_permissions['datafields'];

        if ( !isset($extra_parameters['group']) ) {
            $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

            // If rendering for Edit mode, the token list requires filtering to be done first...
            if ( isset($extra_parameters['token_list']) )
                $extra_parameters['token_list'] = $this->dri_service->generateCSRFTokens($datatype_array, $datarecord_array);

            // If rendering for Master Design, only load datafield properties for the datafields the
            //  user is allowed to see...
            if ( isset($extra_parameters['datafield_properties']) )
                $extra_parameters['datafield_properties'] = $this->dfi_service->getDatafieldProperties($datatype_array);
        }
        else {
            // If displaying HTML for viewing/modifying a group, then the permission arrays need
            //  to be based off the group instead of the user
            /** @var Group $group */
            $group = $extra_parameters['group'];

            $permissions = $this->pm_service->getGroupPermissionsArray( $group->getId() );
            $datatype_permissions = $permissions['datatypes'];
            $datafield_permissions = $permissions['datafields'];
        }


        // ----------------------------------------
        // "Inflate" the currently flattened arrays to make twig's life easier...
        $stacked_datatype_array[ $initial_datatype_id ] =
            $this->dti_service->stackDatatypeArray($datatype_array, $initial_datatype_id);
        $stacked_theme_array[ $initial_theme_id ] =
            $this->theme_service->stackThemeArray($theme_array, $initial_theme_id);

        $stacked_datarecord_array = array();
        if ( !is_null($datarecord) || $is_fake_datarecord ) {
            $stacked_datarecord_array[ $initial_datarecord_id ] =
                $this->dri_service->stackDatarecordArray($datarecord_array, $initial_datarecord_id);
        }
//print '<pre>'.print_r($stacked_datatype_array, true).'</pre>'; exit();
//print '<pre>'.print_r($stacked_theme_array, true).'</pre>'; exit();
//print '<pre>'.print_r($stacked_datarecord_array, true).'</pre>'; exit();

        // If any of the stacked data arrays are empty, it's most likely a permissions problem
        // This probably only really happens when a user attempts to directly access child
        //  datarecords they don't have permissions for
//        if ( count($stacked_datarecord_array[ $requested_datarecord->getId() ]) == 0
//            || count($stacked_datatype_array[ $requested_datatype->getId() ]) == 0
//            || count($stacked_theme_array[ $theme_id ]) == 0
//        ) {
//            throw new ODRForbiddenException();
//        }


        // ----------------------------------------
        // Most templates tend to use all of these parameters...
        $parameters = array(
            'user' => $user,
//            'notify_of_sync' => $notify_of_sync,

            'datatype_array' => $stacked_datatype_array,
            'datarecord_array' => $stacked_datarecord_array,
            'theme_array' => $stacked_theme_array,

            'initial_datatype_id' => $initial_datatype_id,
            'initial_datarecord_id' => $initial_datarecord_id,
            'initial_theme_id' => $initial_theme_id,

            'datatype_permissions' => $datatype_permissions,
            'datafield_permissions' => $datafield_permissions,
        );

        // ...but there are specialty parameters that need to be passed in as well
        $parameters = array_merge($parameters, $extra_parameters);

        return $this->templating->render(
            $template_name,
            $parameters
        );
    }


    /**
     * Synchronizes the given theme with its source theme if needed, and returns whether to notify
     * the user it did so.  At the moment, a notification isn't needed when the synchronization adds
     * a datafield/datatype that the user can't view due to permissions.
     *
     * This may thematically fit better in the ThemeInfoService, but the CloneThemeService depends
     * on that service so it can't go in there.  The CloneThemeService uses Symfony's LockHandler
     * component to ensure that only a single request can synchronize the requested theme, but all
     * other simultaneous requests will block until the first request finishes the synchronization.
     *
     * @param Theme $theme
     * @param ODRUser $user
     *
     * @return bool
     */
    private function notifyOfThemeSync($theme, $user)
    {
        // If the theme doesn't need to be synched, or the user isn't in a position to review the
        //  theme afterwards, then there's no sense synchronizing anything
        if ( !$this->clone_theme_service->canSyncTheme($theme, $user) )
            return false;


        // ----------------------------------------
        // Otherwise, save the diff from before the impending synchronization...
        $theme_diff_array = $this->clone_theme_service->getThemeSourceDiff($theme);

        // ...then synchronize the theme
        $synched = $this->clone_theme_service->syncThemeWithSource($user, $theme);
        $this->em->refresh($theme);
        if (!$synched) {
            // If the synchronization didn't actually do anything, then don't update the version
            //  numbers in the database or notify the user of anything
            return false;
        }


        // TODO - this likely doesn't really do anything at the moment since some/most parts of ODR don't keep these version numbers up to date...
        // Since this theme got synched, also synch the version numbers of all themes with this
        //  this theme as their parent...
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            WHERE t.parentTheme = :theme_id
            AND t.deletedAt IS NULL'
        )->setParameters( array('theme_id' => $theme->getId()) );
        $results = $query->getResult();

        $need_flush = false;
        /** @var Theme[] $results */
        foreach ($results as $t) {
            $current_theme_version = $t->getSourceSyncVersion();
            $source_theme_version = $t->getSourceTheme()->getSourceSyncVersion();

            if ( $current_theme_version !== $source_theme_version ) {
                $properties = array(
                    'sourceSyncVersion' => $source_theme_version
                );
                $this->emm_service->updateThemeMeta($user, $t, $properties, true);    // don't flush immediately...
                $need_flush = true;
            }
        }
        if ($need_flush)
            $this->em->flush();


        // ----------------------------------------
        // Go through the previously saved theme diff and determine whether the user can view at
        //  least one of the added datafields/datatypes...
        $added_datafields = array();
        $added_datatypes = array();
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);

        foreach ($theme_diff_array as $theme_id => $diff_array) {
            if ( isset($diff_array['new_datafields']) )
                $added_datafields = array_merge($added_datafields, array_keys($diff_array['new_datafields']));
            if ( isset($diff_array['new_datatypes']) )
                $added_datatypes = array_merge($added_datatypes, array_keys($diff_array['new_datatypes']));
        }

        if ( count($added_datafields) > 0 ) {
            // Check if any of the added datafields are public...
            $query = $this->em->createQuery(
               'SELECT df.id, dfm.publicDate
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                WHERE df.id IN (:datafield_ids)
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
            )->setParameters( array('datafield_ids' => $added_datafields) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                if ( $result['publicDate']->format('Y-m-d H:i:s') !== '2200-01-01 00:00:00' )
                    // At least one datafield is public, notify the user
                    return true;
            }

            // All the added datafields are non-public, but the user could still see them if they
            //  have permissions...
            $datafield_permissions = $user_permissions['datafields'];
            foreach ($added_datafields as $num => $df_id) {
                if ( isset($datafield_permissions[$df_id])
                    && isset($datafield_permissions[$df_id]['view'])
                ) {
                    // User has permission to see this datafield, notify them of the synchronization
                    return true;
                }
            }
        }


        if ( count($added_datatypes) > 0 ) {
            // Check if any of the added datafields are public...
            $query = $this->em->createQuery(
               'SELECT dt.id, dtm.publicDate
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                WHERE dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $added_datatypes) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                if ( $result['publicDate']->format('Y-m-d H:i:s') !== '2200-01-01 00:00:00' )
                    // At least one datatype is public, notify the user
                    return true;
            }

            // All the added datatypes are non-public, but the user could still see them if they
            //  have permissions...
            $datatype_permissions = $user_permissions['datatypes'];
            foreach ($added_datatypes as $num => $dt_id) {
                if ( isset($datatype_permissions[$dt_id])
                    && isset($datatype_permissions[$dt_id]['dt_view'])
                ) {
                    // User has permission to see this datatype, notify them of the synchronization
                    return true;
                }
            }
        }

        // User isn't able to view anything that was added...do not notify
        return false;
    }


    /**
     * Renders and returns the HTML for a child/linked datatype on the edit page.
     *
     * @param ODRUser $user
     * @param ThemeElement $theme_element
     * @param DataRecord $parent_datarecord
     * @param DataRecord $top_level_datarecord
     * @param bool $insert_fake_datarecord
     *
     * @return string
     */
    public function reloadEditChildtype($user, $theme_element, $parent_datarecord, $top_level_datarecord, $insert_fake_datarecord = false)
    {
        $template_name = 'ODRAdminBundle:Edit:edit_childtype_reload.html.twig';

        $extra_parameters = array(
            'token_list' => array(),
        );

        if ($insert_fake_datarecord)
            $extra_parameters['insert_fake_datarecord'] = true;


        // TODO - is this needed?
        // Ensure all relevant themes are in sync before rendering the end result
//        $parent_theme = $theme_element->getTheme()->getParentTheme();
//        $extra_parameters['notify_of_sync'] = self::notifyOfThemeSync($parent_theme, $user);

        return self::reloadChildtype($user, $template_name, $extra_parameters, $theme_element, $parent_datarecord, $top_level_datarecord);
    }


    /**
     * Renders and returns the HTML for a child/linked datatype on the edit page.
     *
     * @param ODRUser $user
     * @param string $template_name
     * @param array $extra_parameters
     * @param ThemeElement $theme_element
     * @param DataRecord $parent_datarecord
     * @param DataRecord $top_level_datarecord
     *
     * @return string
     */
    private function reloadChildtype($user, $template_name, $extra_parameters, $theme_element, $parent_datarecord, $top_level_datarecord)
    {
        // ----------------------------------------
        // Going to need these to locate entities and determine a few properties
        /** @var ThemeDataType $theme_datatype */
        $theme_datatype = $theme_element->getThemeDataType()->first();
        $child_datatype = $theme_datatype->getDataType();
        $child_theme = $theme_datatype->getChildTheme();

        $parent_theme = $theme_element->getTheme();

        $top_level_theme = $parent_theme->getParentTheme();
        $top_level_datatype = $top_level_theme->getDataType();

        $parent_dr_id = $parent_datarecord->getId();
        $child_dt_id = $child_datatype->getId();

        // Load cached arrays of all the top-level entities
        $datatype_array = $this->dti_service->getDatatypeArray($top_level_datatype->getId());    // do want links
        $datarecord_array = $this->dri_service->getDatarecordArray($top_level_datarecord->getId());    // do want links
        $theme_array = $this->theme_service->getThemeArray($top_level_theme->getId());


        // ----------------------------------------
        // The datatype/datarecord arrays need to be filtered so the user isn't allowed to see stuff
        //  they shouldn't...the theme array intentionally isn't filtered
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);
        $datatype_permissions = $user_permissions['datatypes'];
        $datafield_permissions = $user_permissions['datafields'];

        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // Inline linking requires the insertion of a "fake" datarecord into the child/linked datatype
        //  during the reload process
        if ( isset($extra_parameters['insert_fake_datarecord']) ) {
            // Create the "fake" datarecord...
            $fake_dr_entry = $this->dri_service->createFakeDatarecordEntry($datatype_array, $child_datatype->getId());

            // Splice it into the existing $datarecord_array
            $fake_dr_id = $fake_dr_entry['id'];
            $datarecord_array[$fake_dr_id] = $fake_dr_entry;

            // Splice a reference to the "fake" datarecord into the parent_datarecord so the array
            //  stacker works properly
            if ( !isset($datarecord_array[$parent_dr_id]['children']) )
                $datarecord_array[$parent_dr_id]['children'] = array();
            if ( !isset($datarecord_array[$parent_dr_id]['children'][$child_dt_id]) )
                $datarecord_array[$parent_dr_id]['children'][$child_dt_id] = array();
            $datarecord_array[$parent_dr_id]['children'][$child_dt_id][] = $fake_dr_id;

            // TODO - stacking is inserting the fake record in the front?  not super bad since it's automatically selected, but........
        }

        // Building the token list requires filtering to be done first...
        $extra_parameters['token_list'] = $this->dri_service->generateCSRFTokens($datatype_array, $datarecord_array);


        // ----------------------------------------
        // "Inflate" the currently flattened arrays to make twig's life easier...
        $stacked_datatype_array[ $child_datatype->getId() ] =
            $this->dti_service->stackDatatypeArray($datatype_array, $child_datatype->getId());
        $stacked_datarecord_array[ $parent_datarecord->getId() ] =
            $this->dri_service->stackDatarecordArray($datarecord_array, $parent_datarecord->getId());
        $stacked_theme_array[ $child_theme->getId() ] =
            $this->theme_service->stackThemeArray($theme_array, $child_theme->getId());


        // Find the ThemeDatatype entry that contains the child/linked datatype getting reloaded
        $is_link = 0;          // default to "not linked"
        $display_type = 1;     // default to "accordion"
        $multiple_allowed = 1; // default to "multiple allowed"

        $tdt = null;
        foreach ($theme_array[$parent_theme->getId()]['themeElements'] as $num => $te) {
            if ( $te['id'] === $theme_element->getId() ) {
                if ( isset($te['themeDataType']) && isset($te['themeDataType'][0]) ) {
                    $tdt = $te['themeDataType'][0];

                    $is_link = $tdt['is_link'];
                    $display_type = $tdt['display_type'];
                    $multiple_allowed = $tdt['multiple_allowed'];
                }
            }
        }

        // The only way this exception gets thrown is when the backend database is messed up
        if ( is_null($tdt) )
            throw new ODRException('reloadEditChildtype(): Cached datarecord array malformed');


        // ----------------------------------------
        // Hard-code these, since Edit is the only mode that needs a childtype reload
        $parameters = array(
            'datatype_array' => $stacked_datatype_array,
            'datarecord_array' => $stacked_datarecord_array,
            'theme_array' => $stacked_theme_array,

            'target_datatype_id' => $child_datatype->getId(),
            'parent_datarecord_id' => $parent_datarecord->getId(),
            'target_theme_id' => $child_theme->getId(),

            'datatype_permissions' => $datatype_permissions,
            'datafield_permissions' => $datafield_permissions,

            'is_top_level' => 0,
            'is_link' => $is_link,
            'display_type' => $display_type,
            'multiple_allowed' => $multiple_allowed,
        );

        $parameters = array_merge($parameters, $extra_parameters);

        return $this->templating->render(
            $template_name,
            $parameters
        );
    }


    /**
     * Renders and returns the HTML for a single theme element on the master design page.
     *
     * @param ODRUser $user
     * @param ThemeElement $theme_element
     *
     * @return string
     */
    public function reloadMasterDesignThemeElement($user, $theme_element)
    {
        $template_name = 'ODRAdminBundle:Displaytemplate:design_fieldarea.html.twig';

        $is_datatype_admin = $this->pm_service->isDatatypeAdmin($user, $theme_element->getTheme()->getDataType());
        $extra_parameters = array(
            'is_datatype_admin' => $is_datatype_admin,

            'datafield_properties' => array(),
        );

        // Ensure all relevant themes are in sync before rendering the end result
        $parent_theme = $theme_element->getTheme()->getParentTheme();
        $extra_parameters['notify_of_sync'] = self::notifyOfThemeSync($parent_theme, $user);

        return self::reloadThemeElement($user, $template_name, $extra_parameters, $theme_element);
    }


    /**
     * Renders and returns the HTML for a single theme element on the design page for a
     * non-master theme.
     *
     * @param ODRUser $user
     * @param ThemeElement $theme_element
     *
     * @return string
     */
    public function reloadThemeDesignThemeElement($user, $theme_element)
    {
        $template_name = 'ODRAdminBundle:Theme:theme_fieldarea.html.twig';

        $is_datatype_admin = $this->pm_service->isDatatypeAdmin($user, $theme_element->getTheme()->getDataType());
        $extra_parameters = array(
            'is_datatype_admin' => $is_datatype_admin
        );

        // TODO - is this needed?  I'm guessing it'll never actually do something unless somebody else is modifying the master theme at the same time...
        // Ensure all relevant themes are in sync before rendering the end result
        $parent_theme = $theme_element->getTheme()->getParentTheme();
        $extra_parameters['notify_of_sync'] = self::notifyOfThemeSync($parent_theme, $user);

        return self::reloadThemeElement($user, $template_name, $extra_parameters, $theme_element);
    }


    /**
     * Renders and returns the HTML required to replace a single theme element in various display
     * modes of ODR.
     *
     * @param ODRUser $user
     * @param string $template_name
     * @param array $extra_parameters
     * @param ThemeElement $theme_element
     *
     * @return string
     */
    private function reloadThemeElement($user, $template_name, $extra_parameters, $theme_element)
    {
        // ----------------------------------------
        // Most of the pages want to display linked datatypes, but for those that don't...
        $include_links = true;
        if ( isset($extra_parameters['include_links']) )
            $include_links = $extra_parameters['include_links'];

        // All templates need the datatype and theme arrays...
        $initial_theme = $theme_element->getTheme();
        $initial_datatype = $initial_theme->getDataType();

        // This request could have been for a child datatype, but need the top-level theme and
        //  datatype because that's how the cache entries are stored
        $parent_theme = $initial_theme->getParentTheme();
        $top_level_datatype = $parent_theme->getDataType();

        $datatype_array = $this->dti_service->getDatatypeArray($top_level_datatype->getId(), $include_links);
        $theme_array = $this->theme_service->getThemeArray($parent_theme->getId());

        // ...only get the datarecord arrays if a datarecord was specified
//        $initial_datarecord_id = null;
        $datarecord_array = array();
//        if ( !is_null($datarecord) ) {
//            $initial_datarecord_id = $datarecord->getId();
//            $datarecord_array = $this->dri_service->getDatarecordArray($initial_datarecord_id, $include_links);
//        }

        // All of the templates rendered through this also require several boolean flags...
        $is_top_level = false;
        if ( $initial_datatype->getId() === $top_level_datatype->getId() )
            $is_top_level = true;

        $is_link = false;
        if ( $initial_datatype->getGrandparent()->getId() !== $top_level_datatype->getId() )
            $is_link = true;


        // ----------------------------------------
        // The datatype/datarecord arrays need to be filtered so the user isn't allowed to see stuff
        //  they shouldn't...the theme array intentionally isn't filtered
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);

        $datatype_permissions = $user_permissions['datatypes'];
//        $datafield_permissions = $user_permissions['datafields'];

        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // If rendering for Master Design, only load datafield properties for the datafields the
        //  user is allowed to see...
        if ( isset($extra_parameters['datafield_properties']) )
            $extra_parameters['datafield_properties'] = $this->dfi_service->getDatafieldProperties($datatype_array);


        // ----------------------------------------
        // "Inflate" the currently flattened arrays to make twig's life easier...
        $stacked_datatype_array[ $initial_datatype->getId() ] =
            $this->dti_service->stackDatatypeArray($datatype_array, $initial_datatype->getId());
        $stacked_theme_array[ $initial_theme->getId() ] =
            $this->theme_service->stackThemeArray($theme_array, $initial_theme->getId());

//        $stacked_datarecord_array = array();
//        if ( !is_null($datarecord) ) {
//            $stacked_datarecord_array[ $initial_datarecord_id ] =
//                $this->dri_service->stackDatarecordArray($datarecord_array, $initial_datarecord_id);
//        }


        // If $stacked_theme_array was passed to the twig file, then every single theme_element of
        //  this theme would get re-rendered...since we only want to re-render a single one, delete
        //  the other theme_elements from the array
        foreach ($stacked_theme_array[ $initial_theme->getId() ]['themeElements'] as $te_num => $te) {
            if ( $te['id'] !== $theme_element->getId() )
                unset( $stacked_theme_array[ $initial_theme->getId() ]['themeElements'][$te_num] );
        }

//print '<pre>'.print_r($stacked_datatype_array, true).'</pre>'; exit();
//print '<pre>'.print_r($stacked_theme_array, true).'</pre>'; exit();
//print '<pre>'.print_r($stacked_datarecord_array, true).'</pre>'; exit();

        // If any of the stacked data arrays are empty, it's most likely a permissions problem
        // This probably only really happens when a user attempts to directly access child
        //  datarecords they don't have permissions for
//        if ( count($stacked_datarecord_array[ $requested_datarecord->getId() ]) == 0
//            || count($stacked_datatype_array[ $requested_datatype->getId() ]) == 0
//            || count($stacked_theme_array[ $theme_id ]) == 0
//        ) {
//            throw new ODRForbiddenException();
//        }


        // ----------------------------------------
        // Most templates tend to use all of these parameters...
        $parameters = array(
            'datatype_array' => $stacked_datatype_array,
            'theme_array' => $stacked_theme_array,

            'target_datatype_id' => $initial_datatype->getId(),
            'target_theme_id' => $initial_theme->getId(),

            'datatype_permissions' => $datatype_permissions,

            'is_top_level' => $is_top_level,
            'is_link' => $is_link,
        );

        // ...but there are specialty parameters that need to be passed in as well
        $parameters = array_merge($parameters, $extra_parameters);

        return $this->templating->render(
            $template_name,
            $parameters
        );
    }


    /**
     * Renders and returns the HTML for a single datafield on the master design page.
     *
     * @param ODRUser $user
     * @param DataType $source_datatype
     * @param ThemeElement $theme_element
     * @param DataFields $datafield
     *
     * @return string
     */
    public function reloadMasterDesignDatafield($user, $source_datatype, $theme_element, $datafield)
    {
        if ( $datafield->getDataType()->getId() !== $source_datatype->getId() )
            throw new ODRBadRequestException();

        $extra_parameters = array(
            'datafield_properties' => array(),
        );

        // It doesn't make sense to synchronize the entire theme when just the datafield is getting
        //  reloaded

        $template_name = 'ODRAdminBundle:Displaytemplate:design_datafield.html.twig';
        return self::reloadDatafield($user, $template_name, $extra_parameters, $source_datatype, $theme_element, $datafield);
    }


    /**
     * Renders and returns the HTML for a single datafield on the edit page.
     *
     * @param ODRUser $user
     * @param DataType $source_datatype The Datatype of the top-level Datarecord the edit page is currently displaying
     * @param ThemeElement $theme_element The id of the ThemeElement being re-rendered
     * @param DataFields $datafield The id of the Datafield inside $datarecord_id being re-rendered
     * @param DataRecord $datarecord The id of the Datarecord being re-rendered
     *
     * @return string
     */
    public function reloadEditDatafield($user, $source_datatype, $theme_element, $datafield, $datarecord)
    {
        if ($datafield->getDataType()->getId() !== $datarecord->getDataType()->getId())
            throw new ODRBadRequestException();

        $extra_parameters = array(
            'token_list' => array(),
        );

        // It doesn't make sense to synchronize the entire theme when just the datafield is getting
        //  reloaded

        $template_name = 'ODRAdminBundle:Edit:edit_datafield.html.twig';
        return self::reloadDatafield($user, $template_name, $extra_parameters, $source_datatype, $theme_element, $datafield, $datarecord);
    }


    /**
     * Renders and returns the HTML required to replace a single datafield in various display
     * modes of ODR.
     *
     * @param ODRuser $user
     * @param string $template_name
     * @param array $extra_parameters
     * @param DataType $source_datatype
     * @param ThemeElement $theme_element
     * @param DataFields $datafield
     * @param DataRecord|null $datarecord
     *
     * @return string
     */
    private function reloadDatafield($user, $template_name, $extra_parameters, $source_datatype, $theme_element, $datafield, $datarecord = null)
    {
        // ----------------------------------------
        // Assume that the reload request could be for a datafield in a linked datatype
        $include_links = true;

        // All templates need the datatype and theme arrays...
        $datafield_id = $datafield->getId();
        $initial_datatype_id = $datafield->getDataType()->getId();
        $initial_theme_id = $theme_element->getTheme()->getId();

        $datatype_array = $this->dti_service->getDatatypeArray($source_datatype->getId(), $include_links);
        $master_theme = $this->theme_service->getDatatypeMasterTheme($source_datatype->getId());
        $theme_array = $this->theme_service->getThemeArray($master_theme->getId());

        // ...only get the datarecord arrays if a datarecord was specified
        $initial_datarecord_id = null;
        $datarecord_array = array();
        if ( !is_null($datarecord) ) {
            $initial_datarecord_id = $datarecord->getId();
            $datarecord_array = $this->dri_service->getDatarecordArray($initial_datarecord_id, $include_links);
        }


        // ----------------------------------------
        // The datatype/datarecord arrays need to be filtered so the user isn't allowed to see stuff
        //  they shouldn't...the theme array intentionally isn't filtered
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // If rendering for Edit mode, the token list requires filtering to be done first...
        if ( isset($extra_parameters['token_list']) )
            $extra_parameters['token_list'] = $this->dri_service->generateCSRFTokens($datatype_array, $datarecord_array);

        // If rendering for Master Design, only load datafield properties for the datafields the
        //  user is allowed to see...
        if ( isset($extra_parameters['datafield_properties']) )
            $extra_parameters['datafield_properties'] = $this->dfi_service->getDatafieldProperties($datatype_array, $datafield_id);


        // ----------------------------------------
        // It doesn't make any sense to "inflate" the datatype/theme/datarecord arrays for a datafield
        //  reload...just want a small segment of the arrays

        $parameters = array();
        if ( isset($extra_parameters['token_list']) ) {
            // This is a datafield reload for the edit page...need to locate array entries for
            //  the datatype, datarecord, and datafield...

            // Need to locate the array entry for the datatype...
            $target_datatype = $datatype_array[$initial_datatype_id];

            // ...and the datarecord...
            $target_datarecord = $datarecord_array[$initial_datarecord_id];

            // ...and the datafield
            $target_datafield = $target_datatype['dataFields'][$datafield_id];

            // Also need to figure out whether this is for a linked datarecord or not
            $is_link = false;
            if ( $source_datatype->getId() !== $datarecord->getDataType()->getGrandparent()->getId() )
                $is_link = true;

            // Tag datafields need to know whether the user is a datatype admin or not
            $is_datatype_admin = 0;
            if ( $this->pm_service->isDatatypeAdmin($user, $source_datatype) )
                $is_datatype_admin = 1;


            // The 'token_list' parameter will be merged into this shortly
            $parameters = array(
                'datatype' => $target_datatype,
                'datarecord' => $target_datarecord,
                'datafield' => $target_datafield,

                'is_link' => $is_link,
                'is_datatype_admin' => $is_datatype_admin,
            );
        }
        else {
            // This is a datafield reload for the master layout design page...need to locate the
            //  datafield and the theme_datafield array entries
            $datafield_array = $datatype_array[$initial_datatype_id]['dataFields'][$datafield_id];

            $theme_datafield = null;
            foreach ($theme_array[$initial_theme_id]['themeElements'] as $te_num => $te) {
                // No sense continuing to look if the array entry was already found
                if (!is_null($theme_datafield))
                    break;

                if (isset($te['themeDataFields'])) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        if ($tdf['dataField']['id'] === $datafield_id) {
                            $theme_datafield = $tdf;
                            break;
                        }
                    }
                }
            }

            $parameters = array(
                'theme_datafield' => $theme_datafield,
                'datafield' => $datafield_array,
            );
        }


        // ...merge in the last few special parameters before rendering and returning the html
        $parameters = array_merge($parameters, $extra_parameters);

        return $this->templating->render(
            $template_name,
            $parameters
        );
    }
}
