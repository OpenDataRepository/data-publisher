<?php

/**
 * Open Data Repository Data Publisher
 * MassEdit Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The massedit controller handles rendering and processing a
 * form that allows the user to change the data content for a
 * collection of datarecords simultaneously.
 *
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\XYZData;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatarecordDeletedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordPublicStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatarecordLinkStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatatypeImportedEvent;
use ODR\AdminBundle\Component\Event\FilePublicStatusChangedEvent;
use ODR\AdminBundle\Component\Event\MassEditTriggerEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\TagHelperService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
// Symfony
use FOS\UserBundle\Doctrine\UserManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Templating\EngineInterface;


class MassEditController extends ODRCustomController
{

    /**
     * Sets up a mass edit request made from a search results page.
     *
     * @param integer $datatype_id The database id of the DataType the search was performed on.
     * @param integer $search_theme_id
     * @param string $search_key   The search key identifying which datarecords to potentially mass edit
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function massEditAction($datatype_id, $search_theme_id, $search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Require this MassEdit request to be for a top-level datatype
            if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                throw new ODRBadRequestException('Unable to run MassEdit from a child datatype');

            // A search key is required, otherwise there's no way to identify which datarecords
            //  should be mass edited
            if ($search_key == '')
                throw new ODRBadRequestException('Search key is blank');

            // A tab id is also required...
            $params = $request->query->all();
            if ( !isset($params['odr_tab_id']) )
                throw new ODRBadRequestException('Missing tab id');
            $odr_tab_id = $params['odr_tab_id'];


            // If $search_theme_id is set...
            if ($search_theme_id != 0) {
                // ...require the referenced theme to exist
                /** @var Theme $search_theme */
                $search_theme = $em->getRepository('ODRAdminBundle:Theme')->find($search_theme_id);
                if ($search_theme == null)
                    throw new ODRNotFoundException('Search Theme');

                // ...require it to match the datatype being rendered
                if ($search_theme->getDataType()->getId() !== $datatype->getId())
                    throw new ODRBadRequestException();
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            if ( !$permissions_service->canViewDatatype($user, $datatype) || !$permissions_service->canEditDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This doesn't make sense on a master template...
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to mass edit a master template');
            // ...or a metadata datatype
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to mass edit a metadata datatype');


            // ----------------------------------------
            // Don't want to prevent access to the mass_edit page if a background job is running
            // If a background job is running, then massUpdateAction() will refuse to start

            // ----------------------------------------
            // Verify the search key, and ensure the user can view the results
            $search_key_service->validateSearchKey($search_key);

            // Get the list of grandparent datarecords specified by this search key
            $grandparent_datarecord_list = $search_api_service->performSearch(
                $datatype,
                $search_key,
                $user_permissions
            );    // this will only return grandparent datarecord ids

            // If the user is attempting to view a datarecord from a search that returned no results...
            if ( count($grandparent_datarecord_list) === 0 ) {
                // ...redirect to the "no results found" page
                return $search_redirect_service->redirectToSearchResult($search_key, $search_theme_id);
            }

            // Further restrictions on which datarecords the user can edit will be dealt with later


            // ----------------------------------------
            // Store the datarecord list in the user's session...there is a chance that it could get
            //  wiped if it was only stored in the cache
            $session = $request->getSession();
            $list = $session->get('mass_edit_datarecord_lists');
            if ($list == null)
                $list = array();

            $list[$odr_tab_id] = array(
                'encoded_search_key' => $search_key
            );
            $session->set('mass_edit_datarecord_lists', $list);


            // ----------------------------------------
            // In order for the MassEditTrigger event to work, it needs a list of datafields that
            //  render plugins might want to overwrite...easiest way to do this is to first figure
            //  out whether the datatype has any plugins that listen to the MassEditTrigger event
            $mass_edit_trigger_plugins = array();

            $dt_array = $database_info_service->getDatatypeArray($datatype_id, false);    // not allowed to mass edit linked datatypes
            foreach ($dt_array as $dt_id => $dt) {
                // Check any plugins attached to the datatype...
                foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                    $plugin_events = $rpi['renderPlugin']['renderPluginEvents'];
                    if ( isset($plugin_events['MassEditTriggerEvent']) ) {
                        // ...if the plugin listens to the MassEditTrigger Event, then store its name
                        //  so it can be queried later
                        $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                        if ( !isset($mass_edit_trigger_plugins[$plugin_classname]) )
                            $mass_edit_trigger_plugins[$plugin_classname] = array();
                        $mass_edit_trigger_plugins[$plugin_classname][] = $rpi;
                    }
                }

                // Check any plugins attached to the datatype's datafields...
                if ( !empty($dt['dataFields']) ) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                            $plugin_events = $rpi['renderPlugin']['renderPluginEvents'];
                            if ( isset($plugin_events['MassEditTriggerEvent']) ) {
                                // ...if the plugin listens to the MassEditTrigger Event, then store
                                //  its name so it can be queried later
                                $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                                if ( !isset($mass_edit_trigger_plugins[$plugin_classname]) )
                                    $mass_edit_trigger_plugins[$plugin_classname] = array();
                                $mass_edit_trigger_plugins[$plugin_classname][] = $rpi;
                            }
                        }
                    }
                }
            }

            // Now that the relevant plugins have been found...
            $mass_edit_trigger_datafields = array();

            foreach ($mass_edit_trigger_plugins as $plugin_classname => $rpi_list) {
                // ...load up each of the plugins...
                /** @var MassEditTriggerEventInterface $plugin_svc */
                $plugin_svc = $this->container->get($plugin_classname);

                // ...and "ask" them what fields they intend to override
                foreach ($rpi_list as $num => $rpi) {
                    $rp_id = $rpi['renderPlugin']['id'];
                    $rp_name = $rpi['renderPlugin']['pluginName'];

                    $ret = $plugin_svc->getMassEditOverrideFields($rpi);
                    foreach ($ret as $df_id) {
                        if ( !isset($mass_edit_trigger_datafields[$df_id]) )
                            $mass_edit_trigger_datafields[$df_id] = array();

                        // Need to be able to differentiate between which plugin the user requested
                        //  in case the field has multiple plugins that could be run
                        $mass_edit_trigger_datafields[$df_id][] = array(
                            'plugin_id' => $rp_id,
                            'plugin_name' => $rp_name
                        );
                    }
                }
            }


            // ----------------------------------------
            // Generate the HTML required for a header
            $header_html = $templating->render(
                'ODRAdminBundle:MassEdit:massedit_header.html.twig',
                array(
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $search_key,
                    'offset' => $offset,
                )
            );

            // Get the mass edit page rendered
            $page_html = $odr_render_service->getMassEditHTML($user, $datatype, $odr_tab_id, $mass_edit_trigger_datafields);

            $return['d'] = array( 'html' => $header_html.$page_html );
        }
        catch (\Exception $e) {
            $source = 0x50ff5a99;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Spawns a pheanstalk job for each datarecord-datafield pair modified by the mass update.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function massUpdateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();
//print_r($post);  exit();

            // Need both of these fields in the post...
            if ( !isset($post['odr_tab_id']) || !isset($post['datatype_id']) )
                throw new ODRBadRequestException();

            // Need at least one of these fields in the post...
            if ( !isset($post['datafields']) && !isset($post['public_status']) && !isset($post['event_triggers']) )
                throw new ODRBadRequestException();

            $odr_tab_id = $post['odr_tab_id'];
            $datatype_id = $post['datatype_id'];

            // The rest of these are optional
            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];

            $public_status = array();
            if ( isset($post['public_status']) )
                $public_status = $post['public_status'];

            $event_triggers = array();
            if ( isset($post['event_triggers']) )
                $event_triggers = $post['event_triggers'];


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Require this MassEdit request to be for a top-level datatype
            if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                throw new ODRBadRequestException('Unable to run MassEdit from a child datatype');

            $dt_array = $database_info_service->getDatatypeArray($datatype_id, false);    // No links, MassEdit isn't allowed to affect them

            $session = $request->getSession();
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');    // debug purposes only


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->canViewDatatype($user, $datatype) || !$permissions_service->canEditDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Check whether any jobs that are currently running would interfere with a newly
            //  created 'mass_edit' job for this datatype
            $new_job_data = array(
                'job_type' => 'mass_edit',
                'target_entity' => $datatype,
            );

            $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to start a new MassEdit job, as it would interfere with an already running '.$conflicting_job.' job');


            // ----------------------------------------
            // Grab datarecord list and search key from user session...didn't use the cache because
            //  that could've been cleared and would cause this to work on a different subset of
            //  datarecords
            if ( !$session->has('mass_edit_datarecord_lists') )
                throw new ODRBadRequestException('Missing MassEdit session variable');

            $list = $session->get('mass_edit_datarecord_lists');
            if ( !isset($list[$odr_tab_id]) )
                throw new ODRBadRequestException('Missing MassEdit session variable');

            if ( !isset($list[$odr_tab_id]['encoded_search_key']) )
                throw new ODRBadRequestException('Malformed MassEdit session variable');

            $search_key = $list[$odr_tab_id]['encoded_search_key'];
            if ($search_key === '')
                throw new ODRBadRequestException('Search key is blank');


            // Need both lists of datarecords that the search can return...
            $grandparent_datarecord_list = $search_api_service->performSearch(
                $datatype,
                $search_key,
                $user_permissions
            );    // this will only return grandparent datarecord ids

            if ( count($grandparent_datarecord_list) === 0 ) {
                // If no such datarecord list exists....redirect to search results page
                return $search_redirect_service->redirectToSearchResult($search_key, 0);
            }
            $datarecords = $grandparent_datarecord_list;

            $complete_datarecord_list = $search_api_service->performSearch(
                $datatype,
                $search_key,
                $user_permissions,
                true
            );    // this will also return child/linked descendant datarecord ids


            // ----------------------------------------
            // Perform some rudimentary validation on the datafields marked for this mass update
            // Also, organize the datafields by datatype for later use
            $datafield_list = array();
            $datatype_list = array();

            foreach ($datafields as $df_id => $value) {
                /** @var DataFields $df */
                $df = $repo_datafield->find($df_id);

                // Ensure the datafield belongs to the top-level datatype or one of its descendants
                $df_array = self::getDatafieldArray($dt_array, $df_id);
                $cached_df_entry = $df_array['cached_df'];

                if ( $df->getIsUnique() || $df->getPreventUserEdits() ) {
                    // Silently ignore datafields that are marked as unique, or as not editable by
                    //  any user
                    unset( $datafields[$df_id] );
                }
                else {
                    // Verify that the user is allowed to change this field
                    if ( !$permissions_service->canEditDatafield($user, $df) )
                        throw new ODRForbiddenException();

                    // Verify that the given value is acceptable for the datafield
                    $typeclass = $df->getDataFieldMeta()->getFieldType()->getTypeClass();
                    $is_valid = true;
                    switch ($typeclass) {
                        case 'Boolean':
                            $is_valid = ValidUtility::isValidBoolean($value);
                            break;
                        case 'IntegerValue':
                            $is_valid = ValidUtility::isValidInteger($value);
                            break;
                        case 'DecimalValue':
                            $is_valid = ValidUtility::isValidDecimal($value);
                            break;
                        case 'LongText':    // paragraph text, can accept any value
                            break;
                        case 'LongVarchar':
                            $is_valid = ValidUtility::isValidLongVarchar($value);
                            break;
                        case 'MediumVarchar':
                            $is_valid = ValidUtility::isValidMediumVarchar($value);
                            break;
                        case 'ShortVarchar':
                            $is_valid = ValidUtility::isValidShortVarchar($value);
                            break;
                        case 'DatetimeValue':
                            if ( $value !== '' )    // empty string is valid MassEdit entry, but isn't valid datetime technically
                                $is_valid = ValidUtility::isValidDatetime($value);
                            break;

                        case 'Radio':
                            $is_valid = ValidUtility::areValidRadioOptions($cached_df_entry, $value);
                            break;
                        case 'Tag':
                            $is_valid = ValidUtility::areValidTags($cached_df_entry, $value);
                            break;

                        case 'File':
                        case 'Image':
                        case 'XYZData':
                            // Nothing to validate here...MassEdit can currently only change public
                            //  status or activate the MassEditTrigger event for these
                            break;

                        default:
                            throw new ODRException('Unable to MassEdit a "'.$typeclass.'" Typeclass');
                    }

                    //
                    if ( !$is_valid )
                        throw new ODRBadRequestException('Invalid value given for the datafield "'.$df->getFieldName().'"');


                    // Otherwise, store that this datatype is being affected by the MassEdit job...
                    $dt_id = $df->getDataType()->getId();
                    $datatype_list[$dt_id] = 1;
                    // ...and store which datatype this datafield belongs to
                    $datafield_list[$df_id] = $dt_id;
                }
            }

            // If the user wants to trigger the MassEditTrigger event, then just need to ensure the
            //  datafield belongs to this datatype
            $plugin_response_cache = array();
            foreach ($event_triggers as $df_id => $plugin_ids) {
                // Ensure the datafield belongs to the top-level datatype or one of its descendants
                $tmp = self::getDatafieldArray($dt_array, $df_id);
                $dt_id = $tmp['dt_id'];
                $cached_df = $tmp['cached_df'];

                // Verify the datafield is using the plugin mentioned in the event trigger, and that
                //  the plugin actually responds to the MassEditTrigger Event
                foreach ($plugin_ids as $rp_id => $num) {
                    $rpi = self::getRenderPluginInstance($dt_array[$dt_id], $rp_id, $df_id);

                    // If the datafield isn't being affected by the given render plugin, or the plugin
                    //  doesn't respond to MassEditTrigger events...then throw an exception
                    if ( !isset($rpi['renderPlugin']['renderPluginEvents']['MassEditTriggerEvent']) )
                        throw new ODRBadRequestException('Invalid render plugin id given for the datafield "'.$cached_df['dataFieldMeta']['fieldName'].'"');

                    // The actual background jobs need to have the plugin classname so they can
                    //  specify which plugin to listen to...the datafield could have multiple
                    //  plugins, and the user probably doesn't want all of them to activate in that
                    //  case
                    $event_triggers[$df_id][$rp_id] = $rpi['renderPlugin']['pluginClassName'];

                    // Just because the user checked the checkbox to activate the plugin for this
                    //  field doesn't necessarily mean the plugin should execute.  Certain plugins
                    //  will always want to activate...but others do their thing as part of the
                    //  PostUpdate event, and therefore do not want to activate if the user also
                    //  entered a value for MassEdit to update with
                    $rpi_id = $rpi['id'];
                    if ( !isset($plugin_response_cache[$rpi_id]) ) {
                        // Need to load the plugin service via the classname...
                        /** @var MassEditTriggerEventInterface $plugin_svc */
                        $plugin_svc = $this->container->get( $rpi['renderPlugin']['pluginClassName'] );
                        // ...so the plugin can return whether to trigger the event depending on
                        //  whether the user has entered a value or not
                        $plugin_response_cache[$rpi_id] = $plugin_svc->getMassEditTriggerFields($rpi);
                    }
                    else {
                        // The plugin has already responded to this request...
                        $plugin_response = $plugin_response_cache[$rpi_id];

                        if ( $plugin_response[$df_id] === true ) {
                            // ...the plugin wants the MassEditTrigger event to activate regardless
                            //  of whether the user changed a value in the field or not
                            /* do nothing */
                        }
                        else if ( !isset($datafields[$df_id]) ) {
                            // ...the plugin only wants the MassEditTrigger event to activate when
                            //  the user did NOT change a value in the field
                            /* do nothing */
                        }
                        else {
                            // If neither of the above conditions are true, then the event should
                            //  not be triggered
                            unset( $event_triggers[$df_id][$rp_id] );
                        }
                    }
                }

                // Since a render plugin will be making the changes, it technically doesn't matter
                //  whether the user has edit permissions to this field or not...just guarantee that
                //  the loops which create the background jobs will do something for this datafield
                $datatype_list[$dt_id] = 1;
                $datafield_list[$df_id] = $dt_id;
            }

            // Ensure the previous unset() calls haven't left empty arrays lying around
            foreach ($event_triggers as $df_id => $plugin_ids) {
                if ( empty($plugin_ids) )
                    unset( $event_triggers[$df_id] );
            }


            // If the user attempted to mass update public status of datarecords, verify that they're
            //  allowed to do that
            foreach ($public_status as $dt_id => $status) {
                $can_change_public_status = false;
                if ( isset($datatype_permissions[$dt_id]['dr_public']) )
                    $can_change_public_status = true;

                if ( !$can_change_public_status )
                    throw new ODRForbiddenException();
            }


            // ----------------------------------------
            // Now that all possible requests are valid, delete the datarecord list out of the user's session
            // If this was done earlier, then invalid datafield values could not be recovered from
            unset( $list[$odr_tab_id] );
            $session->set('mass_edit_datarecord_lists', $list);

            // If content of datafields was modified, get/create an entity to track the progress of this mass edit
            // Don't create a TrackedJob if this mass_edit just changes public status
            $tracked_job_id = -1;
            if ( count($public_status) > 0 || (count($datafield_list) > 0 && count($datarecords) > 0) ) {
                $job_type = 'mass_edit';
                $target_entity = 'datatype_'.$datatype_id;
                $additional_data = array('description' => 'Mass Edit of DataType '.$datatype_id, 'datafield_list' => array_keys($datafield_list));
                $restrictions = '';
                $total = -1;    // TODO - better way of dealing with this?
                $reuse_existing = false;
//$reuse_existing = true;

                $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
                $tracked_job_id = $tracked_job->getId();
            }


            // ----------------------------------------
            // Set the url for mass updating public status
            $url = $this->generateUrl('odr_mass_update_worker_status', array(), UrlGeneratorInterface::ABSOLUTE_URL);

            $job_count = 0;

            // Create background jobs for changes to datarecord public status first, if needed
            $updated = false;
            foreach ($public_status as $dt_id => $status) {
                // Get all datarecords of this datatype
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecord AS gp_dr WITH dr.grandparent = gp_dr
                    JOIN ODRAdminBundle:DataRecordMeta AS gp_drm WITH gp_drm.dataRecord = gp_dr
                    WHERE dr.dataType = :datatype_id AND dr.provisioned = false
                    AND gp_drm.prevent_user_edits = 0
                    AND dr.deletedAt IS NULL
                    AND gp_dr.deletedAt IS NULL AND gp_drm.deletedAt IS NULL'
                )->setParameters( array('datatype_id' => $dt_id) );
                $results = $query->getArrayResult();

                $all_datarecord_ids = array();
                foreach ($results as $num => $tmp)
                    $all_datarecord_ids[] = $tmp['dr_id'];

                // TODO - datarecord restriction?
                // Only save the datarecords from $complete_datarecord_list that belong to this datatype
                $affected_datarecord_ids = array_intersect($all_datarecord_ids, $complete_datarecord_list);
//print '<pre>public_status_change: '.print_r($affected_datarecord_ids, true).'</pre>';  exit();

                foreach ($affected_datarecord_ids as $num => $dr_id) {
                    // ...create a new beanstalk job for each datarecord of this datatype
                    $job_count++;

                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "job_type" => 'public_status_change',
                            "tracked_job_id" => $tracked_job_id,
                            "user_id" => $user->getId(),

                            "datarecord_id" => $dr_id,
                            "public_status" => $status,

                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 15;    // TODO - delay set rather high because unable to determine how many jobs need to be created beforehand...better way of dealing with this?
                    $pheanstalk->useTube('mass_edit')->put($payload, $priority, $delay);
                }
            }


            // ----------------------------------------
            // Set the url for mass updating datarecord values
            $url = $this->generateUrl('odr_mass_update_worker_values', array(), UrlGeneratorInterface::ABSOLUTE_URL);

            // TODO - ...modify this to only create one background job per datarecord?
            // TODO - ...this would reduce the number of events fired, but there will still be multiple events if modifying child records
            // TODO - also, just sending one job per drf is the least likely to run into "background job payload too big" issues
            // TODO - ...and any RSS subscriber is already going to need the ability to "debounce" repeated events anyways

            foreach ($datafield_list as $df_id => $dt_id) {
                // Ensure user has the permisions to modify values of this datafield
                $can_edit_datafield = false;
                if ( isset($datafield_permissions[$df_id][ 'edit' ]) )
                    $can_edit_datafield = true;

                if (!$can_edit_datafield)
                    continue;

                // Determine whether user can view non-public datarecords for this datatype
                $can_view_datarecord = false;
                if ( isset($datatype_permissions[$dt_id][ 'dr_view' ]) )
                    $can_view_datarecord = true;


                // Get all datarecords of this datatype that the user is allowed to view
                $query = null;
                if ($can_view_datarecord) {
                    $query = $em->createQuery(
                       'SELECT dr.id AS dr_id
                        FROM ODRAdminBundle:DataFields AS df
                        JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                        JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                        JOIN ODRAdminBundle:DataRecord AS gp_dr WITH dr.grandparent = gp_dr
                        JOIN ODRAdminBundle:DataRecordMeta AS gp_drm WITH gp_drm.dataRecord = gp_dr
                        WHERE df.id = :datafield_id AND gp_drm.prevent_user_edits = 0
                        AND df.deletedAt IS NULL AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL
                        AND gp_dr.deletedAt IS NULL AND gp_drm.deletedAt IS NULL'
                    )->setParameters( array('datafield_id' => $df_id) );
                }
                else {
                    $query = $em->createQuery(
                       'SELECT dr.id AS dr_id
                        FROM ODRAdminBundle:DataFields AS df
                        JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                        JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                        JOIN ODRAdminBundle:DataRecord AS gp_dr WITH dr.grandparent = gp_dr
                        JOIN ODRAdminBundle:DataRecordMeta AS gp_drm WITH gp_drm.dataRecord = gp_dr
                        WHERE df.id = :datafield_id AND gp_drm.publicDate != :public_date
                        AND gp_drm.prevent_user_edits = 0
                        AND df.deletedAt IS NULL AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL
                        AND gp_dr.deletedAt IS NULL AND gp_drm.deletedAt IS NULL'
                    )->setParameters( array('datafield_id' => $df_id, 'public_date' => '2200-01-01 00:00:00') );
                }
                $results = $query->getArrayResult();

                $all_datarecord_ids = array();
                foreach ($results as $num => $tmp)
                    $all_datarecord_ids[] = $tmp['dr_id'];

                // TODO - datarecord restriction?
                // Only save the datarecords from $complete_datarecord_list that belong to this datatype
                $affected_datarecord_ids = array_intersect($all_datarecord_ids, $complete_datarecord_list);
//print '<pre>value_change: '.print_r($affected_datarecord_ids, true).'</pre>';  exit();

                foreach ($affected_datarecord_ids as $num => $dr_id) {
                    // ...create a new beanstalk job for each datarecord of this datatype
                    $job_count++;

                    $priority = 1024;   // should be roughly default priority
                    $payload = array(
                        "job_type" => 'value_change',
                        "tracked_job_id" => $tracked_job_id,
                        "user_id" => $user->getId(),

                        "datarecord_id" => $dr_id,
                        "datafield_id" => $df_id,

                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "url" => $url,
                        "api_key" => $api_key,
                    );


                    if ( isset($datafields[$df_id]) ) {
                        // If the user wants to change the value of a datafield...
                        $payload['value'] = $datafields[$df_id];
                    }
                    if ( isset($event_triggers[$df_id]) ) {
                        // If the user wants to trigger execution of a plugin(s) on this field...
                        $payload['event_trigger'] = json_encode( $event_triggers[$df_id] );
                    }

                    if ( !isset($datafields[$df_id]) && !isset($event_triggers[$df_id]) ) {
                        // ...the datafield should've had a value in one or the other
                        throw new ODRException('missing data for datafield '.$df_id.', to submit for mass edit');
                    }

                    $payload = json_encode($payload);

                    $delay = 15;    // TODO - delay set rather high because unable to determine how many jobs need to be created beforehand...better way of dealing with this?
                    $pheanstalk->useTube('mass_edit')->put($payload, $priority, $delay);
                }
            }

            // TODO - better way of dealing with this?
            /** @var TrackedJob $tracked_job */
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);
            $tracked_job->setTotal($job_count);
            $em->persist($tracked_job);
            $em->flush();

            $return['d'] = array('tracked_job_id' => $tracked_job_id);

        }
        catch (\Exception $e) {
            $source = 0xf0de8b70;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Locates and returns the array version of the requested datafield from the given cached
     * datatype array, throwing an error if it doesn't exist.
     *
     * @param array $dt_array
     * @param int $df_id
     *
     * @return array
     */
    private function getDatafieldArray($dt_array, $df_id)
    {
        // Attempt to locate the requested cached datafield array entry...
        foreach ($dt_array as $dt_id => $dt) {
            if ( isset($dt['dataFields'][$df_id]) ) {
                return array(
                    'dt_id' => $dt_id,
                    'cached_df' => $dt['dataFields'][$df_id]
                );
            }
        }

        // ...array entry doesn't exist, throw an error
        throw new ODRBadRequestException('Unable to locate array entry for datafield '.$df_id);
    }


    /**
     * Searches the cached datatype array to find the renderPluginInstance array that affects the
     * given datafield.
     *
     * @param array $cached_dt
     * @param int $rp_id
     * @param int $df_id
     * @return array
     */
    private function getRenderPluginInstance($cached_dt, $rp_id, $df_id)
    {
        // Don't know whether the plugin is a datatype or a datafield plugin...check datatype
        //  plugins first
        foreach ($cached_dt['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['id'] === $rp_id ) {
                // Datatype plugins can affect more than one datafield, so need to determine if
                //  it maps to the given datafield
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                    // The 'id' property of the renderPluginField is actually a datafield id, not
                    //  a renderPluginField id
                    if ( $rpf['id'] === $df_id )
                        return $rpi;
                }
            }
        }

        // If it's not a datatype plugin, then check the datafield plugins
        $cached_df = $cached_dt['dataFields'][$df_id];
        foreach ($cached_df['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['id'] === $rp_id ) {
                // Datafield plugins can only affect one datafield, so don't need to keep looking
                return $rpi;
            }
        }

        // If this point is reached, then the given render plugin doesn't affect the given datafield
        return array();
    }


    /**
     * Called by the mass update worker processes to update the public status of a datarecord
     *
     * @param Request $request
     *
     * @return Response
     */
    public function massUpdateWorkerStatusAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // This should only be called by a beanstalk worker process, so force exceptions to be in json
            $ret = '';
            $request->setRequestFormat('json');

            $post = $request->request->all();
//print_r($post);  exit();


            if ( !isset($post['tracked_job_id'])
                || !isset($post['user_id'])
                || !isset($post['datarecord_id'])
                || !isset($post['public_status'])
                || !isset($post['api_key'])
            ) {
                throw new ODRBadRequestException();
            }

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $datarecord_id = $post['datarecord_id'];
            $public_status = $post['public_status'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
//            $logger = $this->get('logger');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var UserManager $user_manager */
            $user_manager = $this->container->get('fos_user.user_manager');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException();


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('MassEditCommand.php: DataRecord '.$datarecord_id.' is deleted, skipping');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('MassEditCommand.php: DataRecord '.$datarecord_id.' belongs to a deleted DataType, skipping');
            $datatype_id = $datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $user_manager->findUserBy( array('id' => $user_id) );

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->canChangePublicStatus($user, $datarecord) )
                throw new ODRForbiddenException();

            // Do not make changes to the record if edits are blocked
            if ( $datarecord->getGrandparent()->getPreventUserEdits() )
                throw new ODRForbiddenException('MassEditCommand.php: Datarecord '.$datarecord_id.' is set to prevent_user_edits, skipping');
            // --------------------


            // ----------------------------------------
            // Change the public status of the given datarecord
            $updated = false;

            if ( $public_status == -1 && $datarecord->isPublic() ) {
                // Make the datarecord non-public
                $properties = array('publicDate' => new \DateTime('2200-01-01 00:00:00'));
                $entity_modify_service->updateDatarecordMeta($user, $datarecord, $properties);

                $updated = true;
                $ret .= 'set datarecord '.$datarecord_id.' to non-public'."\n";
            }
            else if ( $public_status == 1 && !$datarecord->isPublic() ) {
                // Make the datarecord public
                $properties = array('publicDate' => new \DateTime());
                $entity_modify_service->updateDatarecordMeta($user, $datarecord, $properties);

                $updated = true;
                $ret .= 'set datarecord '.$datarecord_id.' to public'."\n";
            }

            if ($updated) {
                // Fire off a DatarecordPublicStatusChanged event...this will also end up triggering
                //  the database changes and cache clearing that a DatarecordModified event would cause
                try {
                    $event = new DatarecordPublicStatusChangedEvent($datarecord, $user);
                    $dispatcher->dispatch(DatarecordPublicStatusChangedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
                }
            }


            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total && $total != -1)
                    $tracked_job->setCompleted( new \DateTime() );

                $em->persist($tracked_job);
//                $em->flush();
                $ret .= '  Set current to '.$count."\n";
            }

            // Save all the changes that were made
            $em->flush();

            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            $source = 0xb506e43f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called by the mass update worker processes to update a datarecord-datafield pair to a new value.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function massUpdateWorkerValuesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $ret = '';

        try {
            // This should only be called by a beanstalk worker process, so force exceptions to be in json
            $request->setRequestFormat('json');

            $post = $request->request->all();
//print_r($post);  exit();

            // These parameters are required
            if ( !isset($post['tracked_job_id'])
                || !isset($post['user_id'])
                || !isset($post['datarecord_id'])
                || !isset($post['datafield_id'])
                || !isset($post['api_key'])
            ) {
                throw new ODRBadRequestException();
            }

            // At least one of these parameters is required
            if ( !isset($post['value']) && !isset($post['event_trigger']) )
                throw new ODRBadRequestException();

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $datarecord_id = $post['datarecord_id'];
            $datafield_id = $post['datafield_id'];
            $api_key = $post['api_key'];

            $value = null;
            if ( isset($post['value']) )
                $value = $post['value'];

            $event_trigger = array();
            if ( isset($post['event_trigger']) )
                $event_trigger = json_decode( $post['event_trigger'] );

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var TagHelperService $tag_helper_service */
            $tag_helper_service = $this->container->get('odr.tag_helper_service');
            /** @var UserManager $user_manager */
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var Logger $logger */
            $logger = $this->get('logger');

            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode


            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException();


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('MassEditCommand.php: Datarecord '.$datarecord_id.' is deleted, skipping');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('MassEditCommand.php: Datafield '.$datafield_id.' is deleted, skipping');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() !== null)
                throw new ODRNotFoundException('MassEditCommand.php: Datatype '.$datatype->getId().' is deleted, skipping');
            $datatype_id = $datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $user_manager->findUserBy( array('id' => $user_id) );

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();

            // If the datafield is set to prevent user edits, then prevent this controller action
            //  from making a change to it
            if ( $datafield->getPreventUserEdits() && empty($event_trigger) )
                throw new ODRForbiddenException('MassEditCommand.php: Datafield '.$datafield_id.' is set to prevent_user_edits, skipping');

            // Do not make changes to the record if edits are blocked
            if ( $datarecord->getGrandparent()->getPreventUserEdits() )
                throw new ODRForbiddenException('MassEditCommand.php: Datarecord '.$datarecord_id.' is set to prevent_user_edits, skipping');
            // --------------------


            // ----------------------------------------
            // Update the value stored in this datafield
            $field_typeclass = $datafield->getFieldType()->getTypeClass();
            $field_typename = $datafield->getFieldType()->getTypeName();

            if ($field_typeclass == 'Radio') {
                // Ensure a datarecordfield entity exists...
                $drf = $entity_create_service->createDatarecordField($user, $datarecord, $datafield);

                if ( !$datafield->getPreventUserEdits() && !is_null($value) ) {
                    // Load all selection objects attached to this radio object
                    $radio_selections = array();
                    /** @var RadioSelection[] $tmp */
                    $tmp = $repo_radio_selection->findBy( array('dataRecordFields' => $drf->getId()) );
                    foreach ($tmp as $rs)
                        $radio_selections[ $rs->getRadioOption()->getId() ] = $rs;
                    /** @var RadioSelection[] $radio_selections */

                    // Set radio_selection objects to the desired state
                    foreach ($value as $radio_option_id => $selected) {
                        // Single Select/Radio can have an id of "none", indicating that nothing
                        //  should be selected
                        if ($radio_option_id !== 'none') {
                            // Ensure a RadioSelection entity exists
                            /** @var RadioOptions $radio_option */
                            $radio_option = $repo_radio_option->find($radio_option_id);
                            $radio_selection = $entity_create_service->createRadioSelection($user, $radio_option, $drf);

                            // Ensure it has the correct selected value
                            $properties = array('selected' => $selected);
                            $entity_modify_service->updateRadioSelection($user, $radio_selection, $properties);

                            $ret .= 'setting radio_selection object for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', radio_option_id '.$radio_option_id.' to '.$selected."\n";

                            // If this datafield is a Single Radio/Select datafield, then the correct
                            //  radio option just got selected...remove it from the $radio_selections
                            //  array so the subsequent block can't modify it
                            unset( $radio_selections[$radio_option_id] );
                        }
                    }

                    // If only a single selection is allowed, deselect the other existing radio_selection objects
                    if ( $field_typename == "Single Radio" || $field_typename == "Single Select" ) {
                        // All radio options remaining in this array need to be marked as unselected
                        // The radio option id of "none" won't affect anything here
                        $changes_made = false;
                        foreach ($radio_selections as $radio_option_id => $rs) {
                            if ( $rs->getSelected() == 1 ) {
                                // Ensure this RadioSelection is deselected
                                $properties = array('selected' => 0);
                                $entity_modify_service->updateRadioSelection($user, $rs, $properties, true);    // don't flush immediately...
                                $changes_made = true;

                                $ret .= 'deselecting radio_option_id '.$radio_option_id.' for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId()."\n";
                            }
                        }

                        if ($changes_made)
                            $em->flush();
                    }
                }

                // $event_trigger will only have an entry for this datafield if the event is supposed
                //  to be triggered
                if ( !empty($event_trigger) ) {
                    foreach ($event_trigger as $rp_id => $rp_classname) {
                        try {
                            $event = new MassEditTriggerEvent($drf, $user, $rp_classname);
                            $dispatcher->dispatch(MassEditTriggerEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't particularly want to rethrow the error since it'll interrupt
                            //  everything downstream of the event (such as file encryption...), but
                            //  having the error disappear is less ideal on the dev environment...
                            if ($this->container->getParameter('kernel.environment') === 'dev')
                                throw $e;
                        }
                    }
                }
            }
            else if ($field_typeclass == 'Tag') {
                // Ensure a datarecordfield entity exists...
                $drf = $entity_create_service->createDatarecordField($user, $datarecord, $datafield);

                if ( !$datafield->getPreventUserEdits() && !is_null($value) ) {
                    // Need to ensure the values are numeric
                    $selections = array();
                    foreach ($value as $tag_id => $val)
                        $selections[$tag_id] = intval($val);

                    // Perform the update
                    $tag_helper_service->updateSelectedTags($user, $drf, $selections);
                }

                // $event_trigger will only have an entry for this datafield if the event is supposed
                //  to be triggered
                if ( !empty($event_trigger) ) {
                    foreach ($event_trigger as $rp_id => $rp_classname) {
                        try {
                            $event = new MassEditTriggerEvent($drf, $user, $rp_classname);
                            $dispatcher->dispatch(MassEditTriggerEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't particularly want to rethrow the error since it'll interrupt
                            //  everything downstream of the event (such as file encryption...), but
                            //  having the error disappear is less ideal on the dev environment...
                            if ($this->container->getParameter('kernel.environment') === 'dev')
                                throw $e;
                        }
                    }
                }
            }
            else if ($field_typeclass == 'File') {
                // Load all files associated with this entity
                $query = $em->createQuery(
                   'SELECT file
                    FROM ODRAdminBundle:File AS file
                    WHERE file.dataRecord = :dr_id AND file.dataField = :df_id
                    AND file.deletedAt IS NULL'
                )->setParameters( array('dr_id' => $datarecord_id, 'df_id' => $datafield_id) );
                $results = $query->getResult();

                $has_files = false;
                if ( count($results) > 0 )
                    $has_files = true;

                if ( !$datafield->getPreventUserEdits() && !is_null($value) && $value !== 0 && $has_files ) {
                    // Only makes sense to do stuff if there's at least one file uploaded
                    $files_changed = array();

                    foreach ($results as $num => $file) {
                        /** @var File $file */
                        if ( $file->isPublic() && $value == -1 ) {
                            // File is public, but needs to be non-public
                            $properties = array('publicDate' => new \DateTime('2200-01-01 00:00:00'));
                            $entity_modify_service->updateFileMeta($user, $file, $properties, true);    // don't flush immediately

                            // Delete the decrypted version of the file, if it exists
                            $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
                            $filename = 'File_'.$file->getId().'.'.$file->getExt();
                            $absolute_path = realpath($file_upload_path).'/'.$filename;

                            if ( file_exists($absolute_path) )
                                unlink($absolute_path);

                            $ret .= 'setting File '.$file->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be non-public'."\n";
                            $files_changed[] = $file;
                        }
                        else if ( !$file->isPublic() && $value == 1 ) {
                            // File is non-public, but needs to be public
                            $properties = array('publicDate' => new \DateTime());
                            $entity_modify_service->updateFileMeta($user, $file, $properties, true);    // don't flush immediately

                            // Immediately decrypt the file...don't need to specify a
                            //  filename because the file is guaranteed to be public
                            $crypto_service->decryptFile($file->getId());

                            $ret .= 'setting File '.$file->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be public'."\n";
                            $files_changed[] = $file;
                        }
                    }

                    if ( !empty($files_changed) ) {
                        $em->flush();

                        foreach ($files_changed as $file) {
                            // Fire off an event for each file that was modified
                            try {
                                $event = new FilePublicStatusChangedEvent($file, $datafield, 'mass_edit');
                                $dispatcher->dispatch(FilePublicStatusChangedEvent::NAME, $event);
                            }
                            catch (\Exception $e) {
                                // ...don't want to rethrow the error since it'll interrupt everything after this
                                //  event
//                                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                                throw $e;
                            }
                        }
                    }
                }

                // $event_trigger will only have an entry for this datafield if the event is supposed
                //  to be triggered
                if ( !empty($event_trigger) ) {
                    foreach ($event_trigger as $rp_id => $rp_classname) {
                        try {
                            $drf = $entity_create_service->createDatarecordField($user, $datarecord, $datafield);

                            $event = new MassEditTriggerEvent($drf, $user, $rp_classname);
                            $dispatcher->dispatch(MassEditTriggerEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't particularly want to rethrow the error since it'll interrupt
                            //  everything downstream of the event (such as file encryption...), but
                            //  having the error disappear is less ideal on the dev environment...
                            if ($this->container->getParameter('kernel.environment') === 'dev')
                                throw $e;
                        }
                    }
                }
            }
            else if ($field_typeclass == 'Image') {
                // Load all images associated with this entity
                $query = $em->createQuery(
                   'SELECT image
                    FROM ODRAdminBundle:Image AS image
                    WHERE image.dataRecord = :dr_id AND image.dataField = :df_id
                    AND image.original = 1 AND image.deletedAt IS NULL'
                )->setParameters( array('dr_id' => $datarecord_id, 'df_id' => $datafield_id) );
                $results = $query->getResult();

                $has_images = false;
                if ( count($results) > 0 )
                    $has_images = true;

                if ( !$datafield->getPreventUserEdits() && !is_null($value) && $value !== 0 && $has_images ) {
                    // Only makes sense to do stuff if there's at least one image uploaded
                    $images_changed = array();

                    foreach ($results as $num => $image) {
                        /** @var Image $image */
                        if ( $image->isPublic() && $value == -1 ) {
                            // Image is public, but needs to be non-public
                            $properties = array('publicDate' => new \DateTime('2200-01-01 00:00:00'));
                            $entity_modify_service->updateImageMeta($user, $image, $properties, true);    // don't flush immediately

                            // Delete the decrypted version of the file, if it exists
                            $image_upload_path = $this->getParameter('odr_web_directory').'/uploads/images/';
                            $filename = 'Image_'.$image->getId().'.'.$image->getExt();
                            $absolute_path = realpath($image_upload_path).'/'.$filename;

                            if ( file_exists($absolute_path) )
                                unlink($absolute_path);

                            $ret .= 'setting Image '.$image->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be non-public'."\n";
                            $images_changed[] = $image;
                        }
                        else if ( !$image->isPublic() && $value == 1 ) {
                            // Image is non-public, but needs to be public
                            $properties = array('publicDate' => new \DateTime());
                            $entity_modify_service->updateImageMeta($user, $image, $properties, true);    // don't flush immediately

                            // Immediately decrypt the image...don't need to specify a
                            //  filename because the image is guaranteed to be public
                            $crypto_service->decryptImage($image->getId());

                            $ret .= 'setting Image '.$image->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be public'."\n";
                            $images_changed[] = $image;
                        }
                    }

                    if ( !empty($images_changed) ) {
                        $em->flush();

                        foreach ($images_changed as $image) {
                            // Fire off an event for each image that was modified
                            try {
                                $event = new FilePublicStatusChangedEvent($image, $datafield, 'mass_edit');
                                $dispatcher->dispatch(FilePublicStatusChangedEvent::NAME, $event);
                            }
                            catch (\Exception $e) {
                                // ...don't want to rethrow the error since it'll interrupt everything after this
                                //  event
//                                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                                throw $e;
                            }
                        }
                    }
                }

                // $event_trigger will only have an entry for this datafield if the event is supposed
                //  to be triggered
                if ( !empty($event_trigger) ) {
                    foreach ($event_trigger as $rp_id => $rp_classname) {
                        try {
                            $drf = $entity_create_service->createDatarecordField($user, $datarecord, $datafield);

                            $event = new MassEditTriggerEvent($drf, $user, $rp_classname);
                            $dispatcher->dispatch(MassEditTriggerEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't particularly want to rethrow the error since it'll interrupt
                            //  everything downstream of the event (such as file encryption...), but
                            //  having the error disappear is less ideal on the dev environment...
                            if ($this->container->getParameter('kernel.environment') === 'dev')
                                throw $e;
                        }
                    }
                }
            }
            else if ($field_typeclass == 'DatetimeValue') {
                // For the DateTime fieldtype...
                /** @var DatetimeValue $storage_entity */
                $storage_entity = $entity_create_service->createStorageEntity($user, $datarecord, $datafield);
                $old_value = $storage_entity->getStringValue();

                if ( !$datafield->getPreventUserEdits() && !is_null($value) ) {
                    if ($old_value !== $value) {
                        // Make the change to the value stored in the storage entity
                        $entity_modify_service->updateStorageEntity($user, $storage_entity, array('value' => new \DateTime($value)));

                        $ret .= 'changing datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' from "'.$old_value.'" to "'.$value."\"\n";
                    }
                    else {
                        /* do nothing, current value in entity already matches desired value */
                        $ret .= 'ignoring datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', current value "'.$old_value.'" identical to desired value "'.$value.'"'."\n";
                    }
                }

                // $event_trigger will only have an entry for this datafield if the event is supposed
                //  to be triggered
                if ( !empty($event_trigger) ) {
                    foreach ($event_trigger as $rp_id => $rp_classname) {
                        try {
                            $drf = $storage_entity->getDataRecordFields();

                            $event = new MassEditTriggerEvent($drf, $user, $rp_classname);
                            $dispatcher->dispatch(MassEditTriggerEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't particularly want to rethrow the error since it'll interrupt
                            //  everything downstream of the event (such as file encryption...), but
                            //  having the error disappear is less ideal on the dev environment...
                            if ($this->container->getParameter('kernel.environment') === 'dev')
                                throw $e;
                        }
                    }
                }
            }
            else if ($field_typeclass === 'XYZData') {
                // XYZData entities only respond to MassEditTrigger requests  TODO - change this?
                if ( !empty($event_trigger) ) {
                    // $event_trigger will only have an entry for this datafield if the event is
                    //  supposed to be triggered

                    foreach ($event_trigger as $rp_id => $rp_classname) {
                        try {
                            $drf = $entity_create_service->createDatarecordField($user, $datarecord, $datafield);

                            $event = new MassEditTriggerEvent($drf, $user, $rp_classname);
                            $dispatcher->dispatch(MassEditTriggerEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't particularly want to rethrow the error since it'll interrupt
                            //  everything downstream of the event (such as file encryption...), but
                            //  having the error disappear is less ideal on the dev environment...
                            if ($this->container->getParameter('kernel.environment') === 'dev')
                                throw $e;
                        }
                    }
                }
            }
            else {
                // For every other fieldtype...
                if ( !$datafield->getPreventUserEdits() && !is_null($value) ) {
                    // Ensure the storage entity exists, since it'll get a value anyways
                    /** @var Boolean|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $storage_entity */
                    $storage_entity = $entity_create_service->createStorageEntity($user, $datarecord, $datafield);
                    $old_value = $storage_entity->getValue();

                    if ($old_value !== $value) {
                        // Make the change to the value stored in the storage entity
                        $entity_modify_service->updateStorageEntity($user, $storage_entity, array('value' => $value));

                        $ret .= 'changing datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' from "'.$old_value.'" to "'.$value."\"\n";
                    }
                    else {
                        /* do nothing, current value in entity already matches desired value */
                        $ret .= 'ignoring datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', current value "'.$old_value.'" identical to desired value "'.$value.'"'."\n";
                    }
                }

                if ( !empty($event_trigger) ) {
                    // $event_trigger will only have an entry for this datafield if the event is
                    //  supposed to be triggered

                    foreach ($event_trigger as $rp_id => $rp_classname) {
                        try {
                            /** @var Boolean|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $storage_entity */
                            $storage_entity = $entity_create_service->createStorageEntity($user, $datarecord, $datafield);
                            $drf = $storage_entity->getDataRecordFields();

                            $event = new MassEditTriggerEvent($drf, $user, $rp_classname);
                            $dispatcher->dispatch(MassEditTriggerEvent::NAME, $event);

                            $ret .= 'dispatching MassEditTriggerEvent for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId()."\n";
                        }
                        catch (\Exception $e) {
                            // ...don't particularly want to rethrow the error since it'll interrupt
                            //  everything downstream of the event (such as file encryption...), but
                            //  having the error disappear is less ideal on the dev environment...
                            if ($this->container->getParameter('kernel.environment') === 'dev')
                                throw $e;
                        }
                    }
                }
            }


            // ----------------------------------------
            // Mark this datarecord as updated
            try {
                $event = new DatarecordModifiedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                /** @var TrackedJob $tracked_job */
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total && $total != -1) {
                    $tracked_job->setCompleted( new \DateTime() );

                    // TODO - really want a better system than this...
                    // In theory, being here means the job is done, so delete all search cache entries
                    //  relevant to this datatype
                    try {
                        $event = new DatatypeImportedEvent($datatype, $user);
                        $dispatcher->dispatch(DatatypeImportedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                    }
                }

                $em->persist($tracked_job);
                $ret .= '  Set current to '.$count."\n";
            }

            // Save all the changes that were made
            $em->flush();

            $ret .=  "---------------\n";
            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            $source = 0x99001e8b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes the list of datarecords instead of editing or changing their public status
     *
     * @param int $datatype_id
     * @param string $odr_tab_id
     * @param Request $request
     *
     * @return Response
     */
    public function massDeleteAction($datatype_id, $odr_tab_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $conn = null;

        try {
            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->canDeleteDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This doesn't make sense on a master template...
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to mass edit a master template');
            // ...or a metadata datatype
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to mass edit a metadata datatype');


            // ----------------------------------------
            // Grab datarecord list and search key from user session...didn't use the cache because
            //  that could've been cleared and would cause this to work on a different subset of
            //  datarecords
            $session = $request->getSession();
            if ( !$session->has('mass_edit_datarecord_lists') )
                throw new ODRBadRequestException('Missing MassEdit session variable');

            $list = $session->get('mass_edit_datarecord_lists');
            if ( !isset($list[$odr_tab_id]) )
                throw new ODRBadRequestException('Missing MassEdit session variable');

            if ( !isset($list[$odr_tab_id]['encoded_search_key']) )
                throw new ODRBadRequestException('Malformed MassEdit session variable');

            $search_key = $list[$odr_tab_id]['encoded_search_key'];
            if ($search_key === '')
                throw new ODRBadRequestException('Search key is blank');


            // Need both lists of datarecords that the search can return...
            $grandparent_datarecord_list = $search_api_service->performSearch(
                $datatype,
                $search_key,
                $user_permissions
            );    // this will only return grandparent datarecord ids

            if ( count($grandparent_datarecord_list) === 0 ) {
                // If no such datarecord list exists....redirect to search results page
                return $search_redirect_service->redirectToSearchResult($search_key, 0);
            }
            $datarecords = $grandparent_datarecord_list;

            // Shouldn't be an issue, but delete the datarecord list out of the user's session
            unset( $list[$odr_tab_id] );
            $session->set('mass_edit_datarecord_lists', $list);


            // ----------------------------------------
            // Check whether any jobs that are currently running would interfere with the deletion
            //  of this datarecord
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find( $datarecords[0] );
            $new_job_data = array(
                'job_type' => 'delete_datarecord',
                'target_entity' => $datarecord,
            );

            $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to delete these Datarecords, as it would interfere with an already running '.$conflicting_job.' job');

            // TODO - replace with EntityDeletionService::deleteDatarecord()

            // ----------------------------------------
            // Recursively locate all children of these datarecords
//            $parent_ids = array();
//            $parent_ids[] = $datarecord->getId();
            $parent_ids = $datarecords;

//            $datarecords_to_delete = array();
//            $datarecords_to_delete[] = $datarecord->getId();
            $datarecords_to_delete = $datarecords;

            while ( count($parent_ids) > 0 ) {
                // Can't use the grandparent datarecord property, because this deletion request
                //  could be for a datarecord that isn't top-level
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS parent_dr
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.parent = parent_dr
                    JOIN ODRAdminBundle:DataRecord AS gp_dr WITH dr.grandparent = gp_dr
                    JOIN ODRAdminBundle:DataRecordMeta AS gp_drm WITH gp_drm.dataRecord = gp_dr
                    WHERE dr.id != parent_dr.id AND parent_dr.id IN (:parent_ids)
                    AND gp_drm.prevent_user_edits = 0
                    AND dr.deletedAt IS NULL AND parent_dr.deletedAt IS NULL
                    AND gp_dr.deletedAt IS NULL AND gp_drm.deletedAt IS NULL'
                )->setParameters( array('parent_ids' => $parent_ids) );
                $results = $query->getArrayResult();

                $parent_ids = array();
                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];

                    $parent_ids[] = $dr_id;
                    $datarecords_to_delete[] = $dr_id;
                }
            }
//print '<pre>'.print_r($datarecords_to_delete, true).'</pre>';  //exit();

            // Locate all datarecords that link to any of the datarecords that will be deleted...
            //  they will need to have their cache entries rebuilt
            $query = $em->createQuery(
               'SELECT ancestor.id AS dr_id, ancestor.unique_id AS dr_uuid
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                WHERE ldt.descendant IN (:datarecord_ids)
                AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
            )->setParameters( array('datarecord_ids' => $datarecords_to_delete) );
            $results = $query->getArrayResult();
            // NOTE - the grandparent record intentionally isn't loaded here, for the same reason as
            //  EntityDeletionService...but it's irrelevant here, because MassEdit only deletes
            //  top-level records

            $dr_ids = array();
            $dr_uuids = array();
            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $dr_uuid = $result['dr_uuid'];

                $dr_ids[$dr_id] = 1;
                $dr_uuids[$dr_uuid] = 1;
            }
            $ancestor_datarecord_ids = array_keys($dr_ids);
            $ancestor_datarecord_uuids = array_keys($dr_uuids);
//print '<pre>'.print_r($ancestor_datarecord_ids, true).'</pre>';  //exit();

            // If the datarecord contains any datafields that are being used as a name/sortfield for
            //  other datatypes, then need to clear the default sort order for those datatypes
            $query = $em->createQuery(
               'SELECT DISTINCT(l_dt.id) AS dt_id
                FROM ODRAdminBundle:DataRecord AS dr
                LEFT JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
                LEFT JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                LEFT JOIN ODRAdminBundle:DataTypeSpecialFields AS dtsf WITH dtsf.dataField = df
                LEFT JOIN ODRAdminBundle:DataType AS l_dt WITH dtsf.dataType = l_dt
                WHERE dr.id IN (:datarecords_to_delete)
                AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
                AND dtsf.deletedAt IS NULL AND l_dt.deletedAt IS NULL'
            )->setParameters(
                array( 'datarecords_to_delete' => $datarecords_to_delete )
            );
            $results = $query->getArrayResult();

            $datatypes_to_clear = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $datatypes_to_clear[] = $dt_id;
            }


            // ----------------------------------------
            // Since this needs to make updates to multiple tables, use a transaction
            $conn = $em->getConnection();
            $conn->beginTransaction();

            // TODO - delete datarecordfield entries as well?

            // ...delete all linked_datatree entries that reference these datarecords
            $query = $em->createQuery(
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
            $query = $em->createQuery(
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
            $query = $em->createQuery(
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
            // Ensure no records think they're still linked to this now-deleted record
            try {
                $event = new DatarecordLinkStatusChangedEvent($ancestor_datarecord_ids, $datatype, $user, true);
                $dispatcher->dispatch(DatarecordLinkStatusChangedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // We don't want to fire off multiple (potentially hundreds) of DatarecordDeleted events
            //  here, so the event was designed to permit arrays of ids/uuids
            try {
                $event = new DatarecordDeletedEvent($ancestor_datarecord_ids, $ancestor_datarecord_uuids, $datatype, $user);
                $dispatcher->dispatch(DatarecordDeletedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // NOTE: don't want/need a DatarecordModified event here...the deleted records are
            //  (currently) guaranteed to be top-level, and therefore have nothing to update

            // Due to a pile of records being deleted, it probably won't hurt to fire off this
            //  event either
            try {
                $event = new DatatypeImportedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeImportedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // ----------------------------------------
            // Reset sort order for the datatypes found earlier
            foreach ($datatypes_to_clear as $num => $dt_id) {
                $cache_service->delete('datatype_'.$dt_id.'_record_names');
                $cache_service->delete('datatype_'.$dt_id.'_record_order');
            }

            // NOTE: don't actually need to delete cached graphs for the datatype...the relevant
            //  plugins will end up requesting new graphs without the files for the deleted records


            // ----------------------------------------
            // Determine whether any datarecords of this datatype remain
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.deletedAt IS NULL AND dr.dataType = :datatype'
            )->setParameters(array('datatype' => $datatype->getId()));
            $remaining = $query->getArrayResult();

            if ( count($remaining) > 0 ) {
                $search_key = $search_key_service->encodeSearchKey(
                    array(
                        'dt_id' => $datatype->getId()
                    )
                );

                // If at least one datarecord remains, redirect to the search results list
                $preferred_theme_id = $theme_info_service->getPreferredThemeId($user, $datatype->getId(), 'search_results');
                $url = $this->generateUrl(
                    'odr_search_render',
                    array(
                        'search_theme_id' => $preferred_theme_id,
                        'search_key' => $search_key
                    )
                );
            }
            else {
                // ...otherwise, return to the list of datatypes
                $url = $this->generateUrl('odr_list_types', array('section' => 'databases'));
            }

            $return['d'] = array('redirect_url' => $url);
        }
        catch (\Exception $e) {
            // Don't commit changes if any error was encountered...
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0xd3f22684;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
