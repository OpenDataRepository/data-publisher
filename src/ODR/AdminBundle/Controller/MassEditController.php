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

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
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
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\TagHelperService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


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


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

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

            // Ensure user has permissions to be doing this
            if ( !$user->hasRole('ROLE_ADMIN') )
                throw new ODRForbiddenException();

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
            // Only allow one mass edit job per datatype at a time
            $job_type = 'mass_edit';
            $target_entity = 'datatype_'.$datatype_id;

            $query = $em->createQuery(
               'SELECT tj
                FROM ODRAdminBundle:TrackedJob AS tj
                WHERE tj.job_type = :job_type AND tj.target_entity = :target_entity
                AND tj.deletedAt IS NULL AND tj.completed IS NULL'
            )->setParameters( array('job_type' => $job_type, 'target_entity' => $target_entity) );
            $results = $query->getArrayResult();
//print '<pre>'.print_r($results, true).'</pre>';  exit();

            if ( count($results) > 0 )
                throw new \Exception('A mass edit job is already in progress for this Datatype');


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
            $templating = $this->get('templating');
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


            if ( !(isset($post['odr_tab_id']) && (isset($post['datafields']) || isset($post['public_status'])) && isset($post['datatype_id'])) )
                throw new ODRBadRequestException();

            $odr_tab_id = $post['odr_tab_id'];
            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];
            $datatype_id = $post['datatype_id'];

            $public_status = array();
            if ( isset($post['public_status']) )
                $public_status = $post['public_status'];

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

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

            $can_view_datatype = $pm_service->canViewDatatype($user, $datatype);
            $can_edit_datarecord = $pm_service->canEditDatatype($user, $datatype);

            // Ensure user has permissions to be doing this
            if ( !$user->hasRole('ROLE_ADMIN') )
                throw new ODRForbiddenException();

            if ( !$can_view_datatype || !$can_edit_datarecord )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Only allow one mass edit job per datatype at a time
            $job_type = 'mass_edit';
            $target_entity = 'datatype_'.$datatype_id;

            $query = $em->createQuery(
               'SELECT tj
                FROM ODRAdminBundle:TrackedJob AS tj
                WHERE tj.job_type = :job_type AND tj.target_entity = :target_entity
                AND tj.deletedAt IS NULL AND tj.completed IS NULL'
            )->setParameters( array('job_type' => $job_type, 'target_entity' => $target_entity) );
            $results = $query->getArrayResult();
//print '<pre>'.print_r($results, true).'</pre>';  exit();

            if ( count($results) > 0 )
                throw new ODRException('A mass edit job is already in progress for this Datatype');


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


            // Shouldn't be an issue, but delete the datarecord list out of the user's session
            unset( $list[$odr_tab_id] );
            $session->set('mass_edit_datarecord_lists', $list);


            // ----------------------------------------
            // Ensure no unique datafields managed to get marked for this mass update...store which datatype they belong to at the same time
            $datafield_list = array();
            $datatype_list = array();
            foreach ($datafields as $df_id => $value) {
                /** @var DataFields $df */
                $df = $repo_datafield->find($df_id);
                if ( $df->getIsUnique() == 1 ) {
                    unset( $datafields[$df_id] );
                }
                else {
                    $dt_id = $df->getDataType()->getId();
                    if ( !isset($datatype_list[$dt_id]) )
                        $datatype_list[$dt_id] = 1;

                    $datafield_list[$df_id] = $dt_id;
                }
            }

            // Ensure no completely unrelated datafields are in the post request
            foreach ($datatype_list as $dt_id => $num) {
                $grandparent_datatype_id = $dti_service->getGrandparentDatatypeId($dt_id);
                if ($grandparent_datatype_id != $datatype->getId())
                    throw new ODRBadRequestException('Invalid datafield');
            }
/*
print '$complete_datarecord_list: '.print_r($complete_datarecord_list, true)."\n";
print '$datarecords: '.print_r($datarecords, true)."\n";
print '$datafields: '.print_r($datafields, true)."\n";
print '$datafield_list: '.print_r($datafield_list, true)."\n";
exit();
*/

            // ----------------------------------------
            // If content of datafields was modified, get/create an entity to track the progress of this mass edit
            // Don't create a TrackedJob if this mass_edit just changes public status
            $tracked_job_id = -1;
            if ( count($public_status) > 0 || (count($datafields) > 0 && count($datarecords) > 0) ) {
                $job_type = 'mass_edit';
                $target_entity = 'datatype_'.$datatype_id;
                $additional_data = array('description' => 'Mass Edit of DataType '.$datatype_id);
                $restrictions = '';
                $total = -1;    // TODO - better way of dealing with this?
                $reuse_existing = false;
//$reuse_existing = true;

                $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
                $tracked_job_id = $tracked_job->getId();
            }


            // ----------------------------------------
            // Set the url for mass updating public status
            $url = $this->container->getParameter('site_baseurl');
            $url .= $this->container->get('router')->generate('odr_mass_update_worker_status');

            $job_count = 0;

            // Deal with datarecord public status first, if needed
            $updated = false;
            foreach ($public_status as $dt_id => $status) {
                // Ensure user has the permisions to change public status of datarecords for this datatype
                $is_datatype_admin = false;
                if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id][ 'dt_admin' ]) )
                    $is_datatype_admin = true;

                if (!$is_datatype_admin)
                    continue;

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
            $url = $this->container->getParameter('site_baseurl');
            $url .= $this->container->get('router')->generate('odr_mass_update_worker_values');

            foreach ($datafields as $df_id => $value) {
                // Ensure user has the permisions to modify values of this datafield
                $can_edit_datafield = false;
                if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id][ 'edit' ]) )
                    $can_edit_datafield = true;

                if (!$can_edit_datafield)
                    continue;

                // TODO - $complete_datarecord_list is filtered now, so this is overkill
                // Determine whether user can view non-public datarecords for this datatype
                $dt_id = $datafield_list[$df_id];
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
                    $payload = json_encode(
                        array(
                            "job_type" => 'value_change',
                            "tracked_job_id" => $tracked_job_id,
                            "user_id" => $user->getId(),

                            "datarecord_id" => $dr_id,
                            "datafield_id" => $df_id,
                            "value" => $value,

                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

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
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException();


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $repo_user->find($user_id);

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('MassEditCommand.php: DataRecord '.$datarecord_id.' is deleted, skipping');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('MassEditCommand.php: DataRecord '.$datarecord_id.' belongs to a deleted DataType, skipping');

            $datatype_id = $datatype->getId();


            // ----------------------------------------
            // See if there are migrations jobs in progress for this datatype
            $block = false;
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null) {
                $target_entity = $tracked_job->getTargetEntity();
                $tmp = explode('_', $target_entity);
                $datafield_id = $tmp[1];

                $ret = 'MassEditCommand.php: Datafield '.$datafield_id.' is currently being migrated to a different fieldtype...'."\n";
                $return['r'] = 2;
                $block = true;
            }


            // ----------------------------------------
            if (!$block) {
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
                    // ----------------------------------------
                    // Mark this datarecord as updated
                    $dri_service->updateDatarecordCacheEntry($datarecord, $user);

                    // ...and delete the cached entry that stores public status for this datatype
                    $search_cache_service->onDatarecordPublicStatusChange($datarecord);
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


            if ( !isset($post['tracked_job_id'])
                || !isset($post['user_id'])
                || !isset($post['datarecord_id'])
                || !isset($post['datafield_id'])
                || !isset($post['value'])
                || !isset($post['api_key'])
            ) {
                throw new ODRBadRequestException();
            }

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $datarecord_id = $post['datarecord_id'];
            $datafield_id = $post['datafield_id'];
            $value = $post['value'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
//            $logger = $this->get('logger');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
//            $repo_datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');
            $repo_tag = $em->getRepository('ODRAdminBundle:Tags');
            $repo_tag_selection = $em->getRepository('ODRAdminBundle:TagSelection');

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var TagHelperService $th_service */
            $th_service = $this->container->get('odr.tag_helper_service');


            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException();


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);

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


            // ----------------------------------------
            // See if there are migrations jobs in progress for this datatype
            $block = false;
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null) {
                $target_entity = $tracked_job->getTargetEntity();
                $tmp = explode('_', $target_entity);
                $datafield_id = $tmp[1];

                $ret = 'MassEditCommand.php: Datafield '.$datafield_id.' is currently being migrated to a different fieldtype...'."\n";
                $return['r'] = 2;
                $block = true;
            }


            // ----------------------------------------
            if (!$block) {
                //
                $field_typeclass = $datafield->getFieldType()->getTypeClass();
                $field_typename = $datafield->getFieldType()->getTypeName();

                if ($field_typeclass == 'Radio') {
                    // Ensure a datarecordfield entity exists...
                    $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

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
                else if ($field_typeclass == 'Tag') {
                    // Ensure a datarecordfield entity exists...
                    $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

                    // Load all selection objects attached to this tag object
                    $tag_selections = array();
                    /** @var TagSelection[] $tmp */
                    $tmp = $repo_tag_selection->findBy( array('dataRecordFields' => $drf->getId()) );
                    foreach ($tmp as $ts)
                        $tag_selections[ $ts->getTag()->getId() ] = $ts;
                    /** @var TagSelection[] $tag_selections */

                    // Ensure that the given array only contains leaf-level tags
                    $leaf_selections = $th_service->expandTagSelections($datafield, $value);

                    // Set tag_selection objects to the desired state
                    foreach ($leaf_selections as $tag_id => $selected) {
                        // Ensure a TagSelection entity exists
                        /** @var Tags $tag */
                        $tag = $repo_tag->find($tag_id);
                        $tag_selection = $ec_service->createTagSelection($user, $tag, $drf);

                        // Ensure it has the correct selected value
                        $properties = array('selected' => $selected);
                        $emm_service->updateTagSelection($user, $tag_selection, $properties);

                        $ret .= 'setting tag_selection object for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', tag_id '.$tag_id.' to '.$selected."\n";
                    }
                }
                else if ($field_typeclass == 'File') {
                    // Load all files associated with this entity
                    if ($value !== 0) {
                        $query = $em->createQuery(
                           'SELECT file
                            FROM ODRAdminBundle:File AS file
                            WHERE file.dataRecord = :dr_id AND file.dataField = :df_id
                            AND file.deletedAt IS NULL'
                        )->setParameters( array('dr_id' => $datarecord_id, 'df_id' => $datafield_id) );
                        $results = $query->getResult();

                        if ( count($results) > 0 ) {
                            foreach ($results as $num => $file) {
                                /** @var File $file */
                                if ( $file->isPublic() && $value == -1 ) {
                                    // File is public, but needs to be non-public
                                    $properties = array('publicDate' => new \DateTime('2200-01-01 00:00:00'));
                                    $emm_service->updateFileMeta($user, $file, $properties);

                                    // Delete the decrypted version of the file, if it exists
                                    $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
                                    $filename = 'File_'.$file->getId().'.'.$file->getExt();
                                    $absolute_path = realpath($file_upload_path).'/'.$filename;

                                    if ( file_exists($absolute_path) )
                                        unlink($absolute_path);

                                    $ret .= 'setting File '.$file->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be non-public'."\n";
                                }
                                else if ( !$file->isPublic() && $value == 1 ) {
                                    // File is non-public, but needs to be public
                                    $properties = array('publicDate' => new \DateTime());
                                    $emm_service->updateFileMeta($user, $file, $properties);

                                    // Immediately decrypt the file...don't need to specify a
                                    //  filename because the file is guaranteed to be public
                                    $crypto_service->decryptFile($file->getId());

                                    $ret .= 'setting File '.$file->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be public'."\n";
                                }
                            }

                            $em->flush();
                        }
                    }
                }
                else if ($field_typeclass == 'Image') {
                    // Load all images associated with this entity
                    if ($value !== 0) {
                        $query = $em->createQuery(
                           'SELECT image
                            FROM ODRAdminBundle:Image AS image
                            WHERE image.dataRecord = :dr_id AND image.dataField = :df_id
                            AND image.original = 1 AND image.deletedAt IS NULL'
                        )->setParameters( array('dr_id' => $datarecord_id, 'df_id' => $datafield_id) );
                        $results = $query->getResult();

                        if ( count($results) > 0 ) {
                            foreach ($results as $num => $image) {
                                /** @var Image $image */
                                if ( $image->isPublic() && $value == -1 ) {
                                    // Image is public, but needs to be non-public
                                    $properties = array('publicDate' => new \DateTime('2200-01-01 00:00:00'));
                                    $emm_service->updateImageMeta($user, $image, $properties);

                                    // Delete the decrypted version of the file, if it exists
                                    $image_upload_path = $this->getParameter('odr_web_directory').'/uploads/images/';
                                    $filename = 'Image_'.$image->getId().'.'.$image->getExt();
                                    $absolute_path = realpath($image_upload_path).'/'.$filename;

                                    if ( file_exists($absolute_path) )
                                        unlink($absolute_path);

                                    $ret .= 'setting Image '.$image->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be non-public'."\n";
                                }
                                else if ( !$image->isPublic() && $value == 1 ) {
                                    // Image is non-public, but needs to be public
                                    $properties = array('publicDate' => new \DateTime());
                                    $emm_service->updateImageMeta($user, $image, $properties);

                                    // Immediately decrypt the image...don't need to specify a
                                    //  filename because the image is guaranteed to be public
                                    $crypto_service->decryptImage($image->getId());

                                    $ret .= 'setting Image '.$image->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be public'."\n";
                                }
                            }

                            $em->flush();
                        }
                    }
                }
                else if ($field_typeclass == 'DatetimeValue') {
                    // For the DateTime fieldtype...
                    /** @var DatetimeValue $storage_entity */
                    $storage_entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);
                    $old_value = $storage_entity->getStringValue();

                    if ($old_value != $value) {
                        // Make the change to the value stored in the storage entity
                        $emm_service->updateStorageEntity($user, $storage_entity, array('value' => new \DateTime($value)));

                        $ret .= 'changing datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' from "'.$old_value.'" to "'.$value."\"\n";
                    }
                    else {
                        /* do nothing, current value in entity already matches desired value */
                        $ret .= 'ignoring datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', current value "'.$old_value.'" identical to desired value "'.$value.'"'."\n";
                    }
                }
                else {
                    // For every other fieldtype...ensure the storage entity exists
                    /** @var Boolean|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $storage_entity */
                    $storage_entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);
                    $old_value = $storage_entity->getValue();

                    if ($old_value != $value) {
                        // Make the change to the value stored in the storage entity
                        $emm_service->updateStorageEntity($user, $storage_entity, array('value' => $value));

                        $ret .= 'changing datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' from "'.$old_value.'" to "'.$value."\"\n";
                    }
                    else {
                        /* do nothing, current value in entity already matches desired value */
                        $ret .= 'ignoring datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', current value "'.$old_value.'" identical to desired value "'.$value.'"'."\n";
                    }
                }


                // ----------------------------------------
                // Update the job tracker if necessary
                if ($tracked_job_id !== -1) {
                    $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                    $total = $tracked_job->getTotal();
                    $count = $tracked_job->incrementCurrent($em);

                    if ($count >= $total && $total != -1) {
                        $tracked_job->setCompleted( new \DateTime() );

                        // TODO - really want a better system than this...
                        // In theory, this means the job is done, so delete all search cache entries
                        //  relevant to this datatype
                        $search_cache_service->onDatatypeImport($datatype);
                    }

                    $em->persist($tracked_job);
//                    $em->flush();
$ret .= '  Set current to '.$count."\n";
                }

                // Save all the changes that were made
                $em->flush();


                // ----------------------------------------
                // Mark this datarecord as updated
                $dri_service->updateDatarecordCacheEntry($datarecord, $user);

                // Delete the search cache entries that relate to datarecord modification
                $search_cache_service->onDatarecordModify($datarecord);

$ret .=  "---------------\n";
                $return['d'] = $ret;
            }
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

        try {
            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');
            /** @var ThemeInfoService $ti_service */
            $ti_service = $this->container->get('odr.theme_info_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$user->hasRole('ROLE_ADMIN') )
                throw new ODRForbiddenException();

            if ( !$pm_service->canViewDatatype($user, $datatype) || !$pm_service->canDeleteDatarecord($user, $datatype) )
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
//print '<pre>'.print_r($ancestor_datarecord_ids, true).'</pre>';  //exit();

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
            // All datarecords deleted by this were top-level, so it doesn't make sense to mark
            //  anything as updated
//            if ( !$is_top_level )
//                $dri_service->updateDatarecordCacheEntry($parent_datarecord, $user);

            // Ensure no records think they're still linked to this now-deleted record
            $dri_service->deleteCachedDatarecordLinkData($ancestor_datarecord_ids);

            // Delete all search cache entries that could reference the deleted datarecords
            $search_cache_service->onDatarecordDelete($datatype);
            // Force anything that linked to this datatype to rebuild link entries since at least
            //  one record got deleted
            $search_cache_service->onLinkStatusChange($datatype);

            // Force a rebuild of the cache entries for each datarecord that linked to the records
            //  that just got deleted
            foreach ($ancestor_datarecord_ids as $num => $dr_id) {
                $cache_service->delete('cached_datarecord_'.$dr_id);
                $cache_service->delete('cached_table_data_'.$dr_id);
            }


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
                $preferred_theme_id = $ti_service->getPreferredTheme($user, $datatype->getId(), 'search_results');
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
