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
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\TagSelection;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatarecordDeletedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordPublicStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatarecordLinkStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatatypeImportedEvent;
use ODR\AdminBundle\Component\Event\PostMassEditEvent;
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
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
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

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
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
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            if ( !$pm_service->canViewDatatype($user, $datatype) || !$pm_service->canEditDatatype($user, $datatype) )
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
            $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);

            if ($filtered_search_key !== $search_key) {
                // User can't view some part of the search key...kick them back to the search
                //  results list
                return $search_redirect_service->redirectToFilteredSearchResult($user, $filtered_search_key, $search_theme_id);
            }

            // Get the list of every single datarecords specified by this search key
            // Don't care about sorting here
            $search_results = $search_api_service->performSearch($datatype, $search_key, $user_permissions);
            $grandparent_list = implode(',', $search_results['grandparent_datarecord_list']);
            $datarecord_list = implode(',', $search_results['complete_datarecord_list']);

            // If the user is attempting to view a datarecord from a search that returned no results...
            if ( $filtered_search_key !== '' && $datarecord_list === '' ) {
                // ...redirect to the "no results found" page
                return $search_redirect_service->redirectToSearchResult($filtered_search_key, $search_theme_id);
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
                'datarecord_list' => $grandparent_list,
                'complete_datarecord_list' => $datarecord_list,
                'encoded_search_key' => $filtered_search_key
            );
            $session->set('mass_edit_datarecord_lists', $list);


            // ----------------------------------------
            // Generate the HTML required for a header
            $header_html = $templating->render(
                'ODRAdminBundle:MassEdit:massedit_header.html.twig',
                array(
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $filtered_search_key,
                    'offset' => $offset,
                )
            );

            // Get the mass edit page rendered
            $page_html = $odr_render_service->getMassEditHTML($user, $datatype, $odr_tab_id);

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

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
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

            $dt_array = $dbi_service->getDatatypeArray($datatype_id, false);    // No links, MassEdit isn't allowed to affect them

            $session = $request->getSession();
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');    // debug purposes only


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            // Ensure user has permissions to be doing this
            if ( !$pm_service->canViewDatatype($user, $datatype) || !$pm_service->canEditDatatype($user, $datatype) )
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

            if ( !isset($list[$odr_tab_id]['encoded_search_key'])
                || !isset($list[$odr_tab_id]['datarecord_list'])
                || !isset($list[$odr_tab_id]['complete_datarecord_list'])
            ) {
                throw new ODRBadRequestException('Malformed MassEdit session variable');
            }

            $search_key = $list[$odr_tab_id]['encoded_search_key'];
            if ($search_key === '')
                throw new ODRBadRequestException('Search key is blank');


            // Need a list of datarecords from the user's session to be able to edit them...
            $complete_datarecord_list = trim($list[$odr_tab_id]['complete_datarecord_list']);
            $datarecords = trim($list[$odr_tab_id]['datarecord_list']);
            if ($complete_datarecord_list === '' || $datarecords === '') {
                // ...but no such datarecord list exists....redirect to search results page
                return $search_redirect_service->redirectToSearchResult($search_key, 0);
            }
            $complete_datarecord_list = explode(',', $complete_datarecord_list);
            $datarecords = explode(',', $datarecords);


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

                if ( $df->getIsUnique() || $df->getPreventUserEdits() ) {
                    // Silently ignore datafields that are marked as unique, or as not editable by
                    //  any user
                    unset( $datafields[$df_id] );
                }
                else {
                    // Verify that the user is allowed to change this field
                    if ( !$pm_service->canEditDatafield($user, $df) )
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
                            $is_valid = ValidUtility::areValidRadioOptions($df_array, $value);
                            break;
                        case 'Tag':
                            $is_valid = ValidUtility::areValidTags($df_array, $value);
                            break;

                        case 'File':
                        case 'Image':
                            // Nothing to validate here...MassEdit can currently only change public status for these
                            break;

                        default:
                            throw new ODRException('Unable to MassEdit a "'.$typeclass.'" Typeclass');
                    }

                    //
                    if ( !$is_valid )
                        throw new ODRBadRequestException('Invalid value given for the datafield "'.$df->getFieldName().'"');


                    // Otherwise, store which datatype this datafield belongs to
                    $dt_id = $df->getDataType()->getId();
                    if ( !isset($datatype_list[$dt_id]) )
                        $datatype_list[$dt_id] = 1;

                    $datafield_list[$df_id] = $dt_id;
                }
            }

            // If the user wants to trigger the PostMassEditEvent, then just need to ensure the
            //  datafield belongs to this datatype
            foreach ($event_triggers as $df_id => $val) {
                /** @var DataFields $df */
                $df = $repo_datafield->find($df_id);

                // Ensure the datafield belongs to the top-level datatype or one of its descendants
                $df_array = self::getDatafieldArray($dt_array, $df_id);

                // Since a render plugin will be making the changes, it doesn't actually matter
                //  whether the user has edit permissions to this field or not

                // Could technically validate whether this field uses a plugin with event, but it
                //  doesn't really matter...just store which datatype this datafield belongs to
                $dt_id = $df->getDataType()->getId();
                if ( !isset($datatype_list[$dt_id]) )
                    $datatype_list[$dt_id] = 1;

                $datafield_list[$df_id] = $dt_id;
            }


            // If the user attempted to mass update public status of datarecords, verify that they're
            //  allowed to do that
            foreach ($public_status as $dt_id => $status) {
                $can_change_public_status = false;
                if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dr_public']) )
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
                    WHERE dr.dataType = :datatype_id AND dr.provisioned = false
                    AND dr.deletedAt IS NULL'
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
                if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id][ 'edit' ]) )
                    $can_edit_datafield = true;

                if (!$can_edit_datafield)
                    continue;

                // Determine whether user can view non-public datarecords for this datatype
                $can_view_datarecord = false;
                if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id][ 'dr_view' ]) )
                    $can_view_datarecord = true;


                // Get all datarecords of this datatype that the user is allowed to view
                $query = null;
                if ($can_view_datarecord) {
                    $query = $em->createQuery(
                       'SELECT dr.id AS dr_id
                        FROM ODRAdminBundle:DataFields AS df
                        JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                        JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                        WHERE df.id = :datafield_id
                        AND df.deletedAt IS NULL AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL'
                    )->setParameters( array('datafield_id' => $df_id) );
                }
                else {
                    $query = $em->createQuery(
                       'SELECT dr.id AS dr_id
                        FROM ODRAdminBundle:DataFields AS df
                        JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                        JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                        JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                        WHERE df.id = :datafield_id AND drm.publicDate != :public_date
                        AND df.deletedAt IS NULL AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL'
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
                    else if ( isset($event_triggers[$df_id]) ) {
                        // If the user just wants to trigger an event on this field...
                        $payload['event_trigger'] = 1;
                    }
                    else {
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
            if ( isset($dt['dataFields'][$df_id]) )
                return $dt['dataFields'][$df_id];
        }

        // ...array entry doesn't exist, throw an error
        throw new ODRBadRequestException('Unable to locate array entry for datafield '.$df_id);
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

            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var UserManager $user_manager */
            $user_manager = $this->container->get('fos_user.user_manager');


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
            if ( !$pm_service->canChangePublicStatus($user, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Change the public status of the given datarecord
            $updated = false;

            if ( $public_status == -1 && $datarecord->isPublic() ) {
                // Make the datarecord non-public
                $properties = array('publicDate' => new \DateTime('2200-01-01 00:00:00'));
                $emm_service->updateDatarecordMeta($user, $datarecord, $properties);

                $updated = true;
                $ret .= 'set datarecord '.$datarecord_id.' to non-public'."\n";
            }
            else if ( $public_status == 1 && !$datarecord->isPublic() ) {
                // Make the datarecord public
                $properties = array('publicDate' => new \DateTime());
                $emm_service->updateDatarecordMeta($user, $datarecord, $properties);

                $updated = true;
                $ret .= 'set datarecord '.$datarecord_id.' to public'."\n";
            }

            if ($updated) {
                // Fire off a DatarecordPublicStatusChanged event...this will also end up triggering
                //  the database changes and cache clearing that a DatarecordModified event would cause
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
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

            $event_trigger = false;
            if ( isset($post['event_trigger']) )
                $event_trigger = true;

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');
            $repo_tag = $em->getRepository('ODRAdminBundle:Tags');
            $repo_tag_selection = $em->getRepository('ODRAdminBundle:TagSelection');

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var UserManager $user_manager */
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var Logger $logger */
            $logger = $this->get('logger');


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
            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Update the value stored in this datafield
            $field_typeclass = $datafield->getFieldType()->getTypeClass();
            $field_typename = $datafield->getFieldType()->getTypeName();

            if ($field_typeclass == 'Radio') {
                // Ensure a datarecordfield entity exists...
                $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

                if ( !is_null($value) ) {
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
                            $radio_selection = $ec_service->createRadioSelection($user, $radio_option, $drf);

                            // Ensure it has the correct selected value
                            $properties = array('selected' => $selected);
                            $emm_service->updateRadioSelection($user, $radio_selection, $properties);

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
                                $emm_service->updateRadioSelection($user, $rs, $properties, true);    // don't flush immediately...
                                $changes_made = true;

                                $ret .= 'deselecting radio_option_id '.$radio_option_id.' for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId()."\n";
                            }
                        }

                        if ($changes_made)
                            $em->flush();
                    }
                }

                if ( !is_null($value) || $event_trigger ) {
                    // This is wrapped in a try/catch block because any uncaught exceptions thrown
                    //  by the event subscribers will prevent further progress...
                    try {
                        // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                        //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                        /** @var EventDispatcherInterface $event_dispatcher */
                        $dispatcher = $this->get('event_dispatcher');
                        $event = new PostMassEditEvent($drf, $user);
                        $dispatcher->dispatch(PostMassEditEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't particularly want to rethrow the error since it'll interrupt
                        //  everything downstream of the event (such as file encryption...), but
                        //  having the error disappear is less ideal on the dev environment...
                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
                            throw $e;
                    }
                }
            }
            else if ($field_typeclass == 'Tag') {
                // Ensure a datarecordfield entity exists...
                $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

                if ( !is_null($value) ) {
                    // Load all selection objects attached to this tag object
                    $tag_selections = array();
                    /** @var TagSelection[] $tmp */
                    $tmp = $repo_tag_selection->findBy( array('dataRecordFields' => $drf->getId()) );
                    foreach ($tmp as $ts)
                        $tag_selections[ $ts->getTag()->getId() ] = $ts;
                    /** @var TagSelection[] $tag_selections */

                    // Set tag_selection objects to the desired state
                    foreach ($value as $tag_id => $selected) {
                        if ( isset($tag_selections[$tag_id]) && $tag_selections[$tag_id]->getSelected() != $selected ) {
                            // Ensure the TagSelection has the correct value
                            $tag_selection = $tag_selections[$tag_id];

                            $properties = array('selected' => $selected);
                            $emm_service->updateTagSelection($user, $tag_selection, $properties);

                            $ret .= 'updated existing tag_selection object for tag '.$tag_id.' ("'.$tag_selection->getTag()->getTagName().'") of datafield '.$datafield->getId().' ('.$field_typename.'), datarecord '.$datarecord->getId().'...set to '.$selected."\n";
                        }
                        else if ( !isset($tag_selections[$tag_id]) && $selected != 0 ) {
                            // Ensure a TagSelection entity exists
                            /** @var Tags $tag */
                            $tag = $repo_tag->find($tag_id);
                            $tag_selection = $ec_service->createTagSelection($user, $tag, $drf);

                            // Ensure it has the correct selected value
                            $properties = array('selected' => $selected);
                            $emm_service->updateTagSelection($user, $tag_selection, $properties);

                            $ret .= 'created new tag_selection object for tag '.$tag_id.' ("'.$tag->getTagName().'") of datafield '.$datafield->getId().' ('.$field_typename.'), datarecord '.$datarecord->getId().'...set to '.$selected."\n";
                        }
                        else {
                            // Do nothing...current tag selections in entity already match desired
                            //  values
                            $ret .= 'ignoring tag '.$tag_id.' of datafield '.$datafield->getId().' ('.$field_typename.'), datarecord '.$datarecord->getId().'...current value does not need to change'."\n";
                        }
                    }
                }

                if ( !is_null($value) || $event_trigger ) {
                    // This is wrapped in a try/catch block because any uncaught exceptions thrown
                    //  by the event subscribers will prevent further progress...
                    try {
                        // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                        //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                        /** @var EventDispatcherInterface $event_dispatcher */
                        $dispatcher = $this->get('event_dispatcher');
                        $event = new PostMassEditEvent($drf, $user);
                        $dispatcher->dispatch(PostMassEditEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't particularly want to rethrow the error since it'll interrupt
                        //  everything downstream of the event (such as file encryption...), but
                        //  having the error disappear is less ideal on the dev environment...
                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
                            throw $e;
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

                if ( !is_null($value) && $value !== 0 && $has_files ) {
                    // Only makes sense to do stuff if there's at least one file uploaded
                    $changes_made = false;

                    foreach ($results as $num => $file) {
                        /** @var File $file */
                        if ( $file->isPublic() && $value == -1 ) {
                            // File is public, but needs to be non-public
                            $properties = array('publicDate' => new \DateTime('2200-01-01 00:00:00'));
                            $emm_service->updateFileMeta($user, $file, $properties, true);    // don't flush immediately

                            // Delete the decrypted version of the file, if it exists
                            $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
                            $filename = 'File_'.$file->getId().'.'.$file->getExt();
                            $absolute_path = realpath($file_upload_path).'/'.$filename;

                            if ( file_exists($absolute_path) )
                                unlink($absolute_path);

                            $ret .= 'setting File '.$file->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be non-public'."\n";
                            $changes_made = true;
                        }
                        else if ( !$file->isPublic() && $value == 1 ) {
                            // File is non-public, but needs to be public
                            $properties = array('publicDate' => new \DateTime());
                            $emm_service->updateFileMeta($user, $file, $properties, true);    // don't flush immediately

                            // Immediately decrypt the file...don't need to specify a
                            //  filename because the file is guaranteed to be public
                            $crypto_service->decryptFile($file->getId());

                            $ret .= 'setting File '.$file->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be public'."\n";
                            $changes_made = true;
                        }
                    }

                    if ( $changes_made )
                        $em->flush();
                }

                if ( $has_files && (!is_null($value) || $event_trigger) ) {
                    // This is wrapped in a try/catch block because any uncaught exceptions thrown
                    //  by the event subscribers will prevent further progress...
                    try {
                        $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

                        // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                        //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                        /** @var EventDispatcherInterface $event_dispatcher */
                        $dispatcher = $this->get('event_dispatcher');
                        $event = new PostMassEditEvent($drf, $user);
                        $dispatcher->dispatch(PostMassEditEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't particularly want to rethrow the error since it'll interrupt
                        //  everything downstream of the event (such as file encryption...), but
                        //  having the error disappear is less ideal on the dev environment...
                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
                            throw $e;
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

                if ( !is_null($value) && $value !== 0 && $has_images ) {
                    // Only makes sense to do stuff if there's at least one image uploaded
                    $changes_made = false;

                    foreach ($results as $num => $image) {
                        /** @var Image $image */
                        if ( $image->isPublic() && $value == -1 ) {
                            // Image is public, but needs to be non-public
                            $properties = array('publicDate' => new \DateTime('2200-01-01 00:00:00'));
                            $emm_service->updateImageMeta($user, $image, $properties, true);    // don't flush immediately

                            // Delete the decrypted version of the file, if it exists
                            $image_upload_path = $this->getParameter('odr_web_directory').'/uploads/images/';
                            $filename = 'Image_'.$image->getId().'.'.$image->getExt();
                            $absolute_path = realpath($image_upload_path).'/'.$filename;

                            if ( file_exists($absolute_path) )
                                unlink($absolute_path);

                            $ret .= 'setting Image '.$image->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be non-public'."\n";
                            $changes_made = true;
                        }
                        else if ( !$image->isPublic() && $value == 1 ) {
                            // Image is non-public, but needs to be public
                            $properties = array('publicDate' => new \DateTime());
                            $emm_service->updateImageMeta($user, $image, $properties, true);    // don't flush immediately

                            // Immediately decrypt the image...don't need to specify a
                            //  filename because the image is guaranteed to be public
                            $crypto_service->decryptImage($image->getId());

                            $ret .= 'setting Image '.$image->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be public'."\n";
                            $changes_made = true;
                        }
                    }

                    if ( $changes_made )
                        $em->flush();
                }

                if ( $has_images && (!is_null($value) || $event_trigger) ) {
                    // This is wrapped in a try/catch block because any uncaught exceptions thrown
                    //  by the event subscribers will prevent further progress...
                    try {
                        $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

                        // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                        //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                        /** @var EventDispatcherInterface $event_dispatcher */
                        $dispatcher = $this->get('event_dispatcher');
                        $event = new PostMassEditEvent($drf, $user);
                        $dispatcher->dispatch(PostMassEditEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't particularly want to rethrow the error since it'll interrupt
                        //  everything downstream of the event (such as file encryption...), but
                        //  having the error disappear is less ideal on the dev environment...
                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
                            throw $e;
                    }
                }
            }
            else if ($field_typeclass == 'DatetimeValue') {
                // For the DateTime fieldtype...
                /** @var DatetimeValue $storage_entity */
                $storage_entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);
                $old_value = $storage_entity->getStringValue();

                // Currently, there's no situation where the PostMassEdit event will do anything
                //  different than the PostUpdate event that updateStorageEntity() fires, so want
                //  to ensure only one of the events fires
                $changes_made = false;

                if ( !is_null($value) ) {
                    if ($old_value !== $value) {
                        // Make the change to the value stored in the storage entity
                        $emm_service->updateStorageEntity($user, $storage_entity, array('value' => new \DateTime($value)));

                        $ret .= 'changing datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' from "'.$old_value.'" to "'.$value."\"\n";
                        $changes_made = true;
                    }
                    else {
                        /* do nothing, current value in entity already matches desired value */
                        $ret .= 'ignoring datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', current value "'.$old_value.'" identical to desired value "'.$value.'"'."\n";
                    }
                }

                if ( !$changes_made && $event_trigger ) {
                    // This is wrapped in a try/catch block because any uncaught exceptions thrown
                    //  by the event subscribers will prevent further progress...
                    try {
                        $drf = $storage_entity->getDataRecordFields();

                        // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                        //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                        /** @var EventDispatcherInterface $event_dispatcher */
                        $dispatcher = $this->get('event_dispatcher');
                        $event = new PostMassEditEvent($drf, $user);
                        $dispatcher->dispatch(PostMassEditEvent::NAME, $event);
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
            else {
                // For every other fieldtype...ensure the storage entity exists
                /** @var Boolean|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $storage_entity */
                $storage_entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);
                $old_value = $storage_entity->getValue();

                // Currently, there's no situation where the PostMassEdit event will do anything
                //  different than the PostUpdate event that updateStorageEntity() fires, so want
                //  to ensure only one of the events fires
                $changes_made = false;

                if ( !is_null($value) ) {
                    if ($old_value !== $value) {
                        // Make the change to the value stored in the storage entity
                        $emm_service->updateStorageEntity($user, $storage_entity, array('value' => $value));

                        $ret .= 'changing datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' from "'.$old_value.'" to "'.$value."\"\n";
                        $changes_made = true;
                    }
                    else {
                        /* do nothing, current value in entity already matches desired value */
                        $ret .= 'ignoring datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', current value "'.$old_value.'" identical to desired value "'.$value.'"'."\n";
                    }
                }

                if ( !$changes_made && $event_trigger ) {
                    // This is wrapped in a try/catch block because any uncaught exceptions thrown
                    //  by the event subscribers will prevent further progress...
                    try {
                        $drf = $storage_entity->getDataRecordFields();

                        // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                        //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                        /** @var EventDispatcherInterface $event_dispatcher */
                        $dispatcher = $this->get('event_dispatcher');
                        $event = new PostMassEditEvent($drf, $user);
                        $dispatcher->dispatch(PostMassEditEvent::NAME, $event);
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


            // ----------------------------------------
            // Mark this datarecord as updated
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
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
                        // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                        //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                        /** @var EventDispatcherInterface $event_dispatcher */
                        $dispatcher = $this->get('event_dispatcher');
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
     * TODO - move this to a service?  but it would have to import the symfony container...
     * Looks through the cached datatype array to determine whether any of the used render plugins
     * derive values for any of their datafields.
     *
     * @param array $datatype_array
     *
     * @return array
     */
    private function findDerivedDatafields($datatype_array)
    {
        $derived_datafields = array();

        foreach ($datatype_array as $dt_id => $dt) {
            // For each render plugin this datatype is using...
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                $plugin_classname = $rpi['renderPlugin']['pluginClassName'];

                // Check whether any of the renderPluginField entries are derived prior to attempting to
                //  load the renderPlugin itself
                $load_render_plugin = false;
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                    if ( isset($rpf['properties']['is_derived']) ) {
                        $load_render_plugin = true;
                        break;
                    }
                }

                // If a datafield from this plugin is derived...
                if ($load_render_plugin) {
                    /** @var DatafieldDerivationInterface $render_plugin */
                    $render_plugin = $this->container->get($plugin_classname);

                    if ($render_plugin instanceof DatafieldDerivationInterface) {
                        // ...then request an array of the datafields that are derived from some other
                        //  field so the rest of FakeEdit can use it
                        $tmp = $render_plugin->getDerivationMap($rpi);
                        foreach ($tmp as $derived_df_id => $source_datafields)
                            $derived_datafields[$derived_df_id] = $source_datafields;

                        // TODO - multiple plugins attempting to derive the value in the same datafield?
                    }
                }
            }
        }

        return $derived_datafields;
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
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->canDeleteDatarecord($user, $datatype) )
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

            if ( !isset($list[$odr_tab_id]['encoded_search_key'])
                || !isset($list[$odr_tab_id]['datarecord_list'])
                || !isset($list[$odr_tab_id]['complete_datarecord_list'])
            ) {
                throw new ODRBadRequestException('Malformed MassEdit session variable');
            }

            $search_key = $list[$odr_tab_id]['encoded_search_key'];
            if ($search_key === '')
                throw new ODRBadRequestException('Search key is blank');


            // Need a list of datarecords from the user's session to be able to edit them...
            $complete_datarecord_list = trim($list[$odr_tab_id]['complete_datarecord_list']);
            $datarecords = trim($list[$odr_tab_id]['datarecord_list']);
            if ($complete_datarecord_list === '' || $datarecords === '') {
                // ...but no such datarecord list exists....redirect to search results page
                return $search_redirect_service->redirectToSearchResult($search_key, 0);
            }

            // Can't use the complete datarecord list, since that also contains linked datarecords
            // Only want the mass delete to affect records from this datatype
//            $complete_datarecord_list = explode(',', $complete_datarecord_list);
            $datarecords = explode(',', $datarecords);


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
//print '<pre>'.print_r($datarecords_to_delete, true).'</pre>';  //exit();

            // Locate all datarecords that link to any of the datarecords that will be deleted...
            //  they will need to have their cache entries rebuilt
            $query = $em->createQuery(
               'SELECT gp.id AS dr_id, gp.unique_id AS dr_uuid
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                JOIN ODRAdminBundle:DataRecord AS gp WITH ancestor.grandparent = gp
                WHERE ldt.descendant IN (:datarecord_ids)
                AND ldt.deletedAt IS NULL
                AND ancestor.deletedAt IS NULL AND gp.deletedAt IS NULL'
            )->setParameters( array('datarecord_ids' => $datarecords_to_delete) );
            $results = $query->getArrayResult();

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

            // If the datarecord contains any datafields that are being used as a sortfield for
            //  other datatypes, then need to clear the default sort order for those datatypes
            $query = $em->createQuery(
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
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordLinkStatusChangedEvent($ancestor_datarecord_ids, $datatype, $user);
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
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
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
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
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
            foreach ($datatypes_to_reset_order as $num => $dt_id)
                $cache_service->delete('datatype_'.$dt_id.'_record_order');

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
                $preferred_theme_id = $theme_info_service->getPreferredTheme($user, $datatype->getId(), 'search_results');
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
