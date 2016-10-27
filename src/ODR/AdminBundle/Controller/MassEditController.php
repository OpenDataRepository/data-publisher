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

// Controllers/Classes
use ODR\OpenRepository\SearchBundle\Controller\DefaultController as SearchController;
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
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class MassEditController extends ODRCustomController
{


    /**
     * Sets up a mass edit request made from a search results page.
     * 
     * @param integer $datatype_id The database id of the DataType the search was performed on.
     * @param integer $offset
     * @param string $search_key   The search key identifying which datarecords to potentially mass edit
     * @param Request $request
     * 
     * @return Response
     */
    public function massEditAction($datatype_id, $offset, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_edit_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_edit']) )
                $can_edit_datarecord = true;

            // Ensure user has permissions to be doing this
            if ( !($datatype->isPublic() || $can_view_datatype) || !$can_edit_datarecord )
                return parent::permissionDeniedError("edit");
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
                throw new \Exception('A mass edit job is already in progress for this Datatype');


            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];

            // ----------------------------------------
            // If this datarecord is being viewed from a search result list, attempt to grab the list of datarecords from that search result
            $encoded_search_key = '';
            if ($search_key !== '') {
                // 
                $data = parent::getSavedSearch($em, $user, $datatype_permissions, $datafield_permissions, $datatype->getId(), $search_key, $request);
                $encoded_search_key = $data['encoded_search_key'];
                $datarecord_list = $data['datarecord_list'];
                $complete_datarecord_list = $data['complete_datarecord_list'];

                // If there is no tab id for some reason, or the user is attempting to view a datarecord from a search that returned no results...
                if ( $odr_tab_id === '' || $data['redirect'] == true || ($encoded_search_key !== '' && $datarecord_list === '') ) {
                    // ...get the search controller to redirect to "no results found" page
                    $url = $this->generateUrl('odr_search_render', array('search_key' => $data['encoded_search_key'], 'offset' => 1, 'source' => 'searching'));
                    return parent::searchPageRedirect($user, $url);
                }

                // Store the datarecord list in the user's session...there is a chance that it could get wiped if it was only stored in memcached
                $session = $request->getSession();
                $list = $session->get('mass_edit_datarecord_lists');
                if ($list == null)
                    $list = array();

                $list[$odr_tab_id] = array('datarecord_list' => $datarecord_list, 'complete_datarecord_list' => $complete_datarecord_list, 'encoded_search_key' => $encoded_search_key);
                $session->set('mass_edit_datarecord_lists', $list);
            }

            // Generate the HTML required for a header
            $templating = $this->get('templating');
            $header_html = $templating->render(
                'ODRAdminBundle:MassEdit:massedit_header.html.twig',
                array(
                    'search_key' => $encoded_search_key,
                    'offset' => $offset,
                )
            );

            // Get the mass edit page rendered
            $page_html = self::massEditRender($datatype_id, $odr_tab_id, $request);
            $return['d'] = array( 'html' => $header_html.$page_html );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x12736279 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns the html used for performing mass edits.
     * 
     * @param integer $datatype_id    The database id that the search was performed on.
     * @param string $odr_tab_id
     * @param Request $request
     * 
     * @return string
     */
    private function massEditRender($datatype_id, $odr_tab_id, Request $request)
    {
        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        if ($datatype == null)
            return parent::deletedEntityError('Datatype');

        /** @var Theme $theme */
        $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
        if ($theme == null)
            return parent::deletedEntityError('Theme');


        // --------------------
        // Determine user privileges
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
        $datatype_permissions = $user_permissions['datatypes'];
        $datafield_permissions = $user_permissions['datafields'];
        // --------------------

        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;


        // ----------------------------------------
        // Determine which datatypes/childtypes to load from the cache
        $include_links = false;
        $associated_datatypes = parent::getAssociatedDatatypes($em, array($datatype_id), $include_links);

//print '<pre>'.print_r($associated_datatypes, true).'</pre>'; exit();

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$dt_id)));
            if ($bypass_cache || $datatype_data == null)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }


        // ----------------------------------------
        // Filter by user permissions
        $datarecord_array = array();
        parent::filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


        // Render the MassEdit page
        $templating = $this->get('templating');
        $html = $templating->render(
            'ODRAdminBundle:MassEdit:massedit_ajax.html.twig',
            array(
                'datatype_array' => $datatype_array,
                'initial_datatype_id' => $datatype_id,
                'theme_id' => $theme->getId(),

                'odr_tab_id' => $odr_tab_id,
                'datatype_permissions' => $datatype_permissions,
                'datafield_permissions' => $datafield_permissions,
            )
        );

        return $html;
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
            // Ensure post is valid
            $post = $_POST;
//print_r($post);
//return;

            if ( !(isset($post['odr_tab_id']) && (isset($post['datafields']) || isset($post['public_status'])) && isset($post['datatype_id'])) )
                throw new \Exception('bad post request');

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
//            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
//            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

//            $redis = $this->container->get('snc_redis.default');;
//            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_edit_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_edit']) )
                $can_edit_datarecord = true;

            // Ensure user has permissions to be doing this
            if ( !($datatype->isPublic() || $can_view_datatype) || !$can_edit_datarecord )
                return parent::permissionDeniedError("edit");
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
                throw new \Exception('A mass edit job is already in progress for this Datatype');


            // ----------------------------------------
            // Grab datarecord list and search key from user session...not using memcached because the possibility exists that the list could have been deleted
            $list = $session->get('mass_edit_datarecord_lists');

            $complete_datarecord_list = '';
            $datarecords = '';
            $encoded_search_key = null;

            if ( isset($list[$odr_tab_id]) ) {
                $complete_datarecord_list = explode(',', $list[$odr_tab_id]['complete_datarecord_list']);   // This list is NOT already filtered by user permissions
                $datarecords = $list[$odr_tab_id]['datarecord_list'];                                       // This list is already filtered by user permissions
                $encoded_search_key = $list[$odr_tab_id]['encoded_search_key'];
            }

            // If the datarecord list doesn't exist for some reason, or the user is attempting to view a datarecord from a search that returned no results...
            if ( !isset($list[$odr_tab_id]) || ($encoded_search_key !== '' && $datarecords === '') ) {
                // ...redirect to "no results found" page
                /** @var SearchController $search_controller */
                $search_controller = $this->get('odr_search_controller', $request);
                $search_controller->setContainer($this->container);

                return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
            }

            // TODO - delete the datarecord list/search key out of the user's session?


//            $datarecords = explode(',', $datarecords);

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
            $datatree_array = parent::getDatatreeArray($em);
            foreach ($datatype_list as $dt_id => $num) {
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $dt_id);
                if ($grandparent_datatype_id != $datatype->getId())
                    throw new \Exception('Invalid Form');
            }
/*
print '$datarecords: '.print_r($datarecords, true)."\n";
print '$datafields: '.print_r($datafields, true)."\n";
print '$datafield_list: '.print_r($datafield_list, true)."\n";
return;
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

                // Only save the datarecords from $complete_datarecord_list that belong to this datatype
                $affected_datarecord_ids = array_intersect($all_datarecord_ids, $complete_datarecord_list);

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

                // Only save the datarecords from $complete_datarecord_list that belong to this datatype
                $affected_datarecord_ids = array_intersect($all_datarecord_ids, $complete_datarecord_list);

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
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x24463979 ' . $e->getMessage();
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
            $ret = '';
            $post = $_POST;
//$ret = print_r($post, true);
            if ( !isset($post['tracked_job_id']) || !isset($post['user_id']) || !isset($post['datarecord_id']) || !isset($post['public_status']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $datarecord_id = $post['datarecord_id'];
            $public_status = $post['public_status'];
            $api_key = $post['api_key'];

            // Load symfony objects
//            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
//            $pheanstalk = $this->get('pheanstalk');
//            $logger = $this->get('logger');

            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');


            // ----------------------------------------
            /** @var User $user */
            $user = $repo_user->find($user_id);

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new \Exception('MassEditCommand.php: DataRecord '.$datarecord_id.' is deleted, skipping');

            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                throw new \Exception('MassEditCommand.php: DataRecord '.$datarecord_id.' belongs to a deleted DataType, skipping');

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
                    $public_date = new \DateTime('2200-01-01 00:00:00');

                    $properties = array('publicDate' => $public_date);
                    parent::ODR_copyDatarecordMeta($em, $user, $datarecord, $properties);

                    $updated = true;
                    $ret .= 'set datarecord '.$datarecord_id.' to non-public'."\n";
                }
                else if ( $public_status == 1 && !$datarecord->isPublic() ) {
                    // Make the datarecord non-public
                    $public_date = new \DateTime();

                    $properties = array('publicDate' => $public_date);
                    parent::ODR_copyDatarecordMeta($em, $user, $datarecord, $properties);

                    $updated = true;
                    $ret .= 'set datarecord '.$datarecord_id.' to public'."\n";
                }

                if ($updated) {
                    // Refresh the cache entries for this datarecord
                    parent::tmp_updateDatarecordCache($em, $datarecord, $user);

                    // ----------------------------------------
                    // See if any cached search results need to be deleted...
                    $cached_searches = parent::getRedisData(($redis->get($redis_prefix.'.cached_search_results')));
                    if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
                        // Delete all cached search results for this datatype that contained this datarecord
                        foreach ($cached_searches[$datatype_id] as $search_checksum => $search_data) {
                            $datarecord_list = explode(',', $search_data['datarecord_list']['all']);    // if found in the list of all grandparents matching a search, just delete the entire cached search
                            if ( in_array($datarecord_id, $datarecord_list) )
                                unset ( $cached_searches[$datatype_id][$search_checksum] );
                        }

                        // Save the collection of cached searches back to memcached
                        $redis->set($redis_prefix.'.cached_search_results', gzcompress(serialize($cached_searches)));
                    }
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
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x392577639 ' . $e->getMessage();
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
            $post = $_POST;

            if ( !isset($post['tracked_job_id']) || !isset($post['user_id']) || !isset($post['datarecord_id']) || !isset($post['datafield_id']) || !isset($post['value']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $datarecord_id = $post['datarecord_id'];
            $datafield_id = $post['datafield_id'];
            $value = $post['value'];
            $api_key = $post['api_key'];

            // Load symfony objects
//            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
//            $pheanstalk = $this->get('pheanstalk');
//            $logger = $this->get('logger');

            // Grab memcached stuff
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
//            $repo_datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');


            // ----------------------------------------
            /** @var User $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new \Exception('MassEditCommand.php: Datarecord '.$datarecord_id.' is deleted, skipping');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new \Exception('MassEditCommand.php: Datafield '.$datafield_id.' is deleted, skipping');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() !== null)
                throw new \Exception('MassEditCommand.php: Datatype '.$datatype->getId().' is deleted, skipping');
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
                    // Ensure a datarecordfield entity exists...will receive the existing one back if it already exists
                    $drf = parent::ODR_addDataRecordField($em, $user, $datarecord, $datafield);

                    // Grab all selection objects attached to this radio object
                    $radio_selections = array();
                    /** @var RadioSelection[] $tmp */
                    $tmp = $repo_radio_selection->findBy( array('dataRecordFields' => $drf->getId()) );
                    foreach ($tmp as $radio_selection)
                        $radio_selections[ $radio_selection->getRadioOption()->getId() ] = $radio_selection;
                    /** @var RadioSelection[] $radio_selections */

                    // $value is in format array('radio_option_id' => desired_state)
                    // Set radio_selection objects to the desired state
                    foreach ($value as $radio_option_id => $selected) {

                        // Ensure a RadioSelection entity exists
                        /** @var RadioOptions $radio_option */
                        $radio_option = $repo_radio_option->find($radio_option_id);
                        $radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $drf);

                        // Ensure it has the correct selected value
                        $properties = array('selected' => $selected);
                        parent::ODR_copyRadioSelection($em, $user, $radio_selection, $properties);

                        $ret .= 'setting radio_selection object for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', radio_option_id '.$radio_option_id.' to '.$selected."\n";

                        // If this datafield is a Single Radio/Select datafield, then every single RadioSelection in $radio_selections except for this one that just got selected need to be deselected
                        unset( $radio_selections[$radio_option_id] );
                    }

                    // If only a single selection is allowed, deselect the other existing radio_selection objects
                    if ( $field_typename == "Single Radio" || $field_typename == "Single Select" ) {
                        foreach ($radio_selections as $radio_option_id => $rs) {
                            if ( $rs->getSelected() == 1 ) {
                                // Ensure this RadioSelection is deselected
                                $properties = array('selected' => 0);
                                parent::ODR_copyRadioSelection($em, $user, $rs, $properties);

                                $ret .= 'deselecting radio_option_id '.$radio_option_id.' for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId()."\n";
                            }
                        }
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
                                    parent::ODR_copyFileMeta($em, $user, $file, $properties);

                                    // Delete the decrypted version of the file, if it exists
                                    $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
                                    $filename = 'File_'.$file->getId().'.'.$file->getExt();
                                    $absolute_path = realpath($file_upload_path).'/'.$filename;

                                    if ( file_exists($absolute_path) )
                                        unlink($absolute_path);

                                    $ret .= 'setting File '.$file->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be non-public'."\n";
                                }
                                else if ( !$file->isPublic() && $value == 1 ) {
                                    // File is non-public, but needs to be public
                                    $properties = array('publicDate' => new \DateTime());
                                    parent::ODR_copyFileMeta($em, $user, $file, $properties);

                                    // Immediately decrypt the file
                                    parent::decryptObject($file->getId(), 'file');

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
                            AND image.deletedAt IS NULL'
                        )->setParameters( array('dr_id' => $datarecord_id, 'df_id' => $datafield_id) );
                        $results = $query->getResult();

                        if ( count($results) > 0 ) {
                            foreach ($results as $num => $image) {
                                /** @var Image $image */
                                if ( $image->isPublic() && $value == -1 ) {
                                    // Image is public, but needs to be non-public
                                    $properties = array('publicDate' => new \DateTime('2200-01-01 00:00:00'));
                                    parent::ODR_copyImageMeta($em, $user, $image, $properties);

                                    // Delete the decrypted version of the file, if it exists
                                    $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
                                    $filename = 'Image_'.$image->getId().'.'.$image->getExt();
                                    $absolute_path = realpath($image_upload_path).'/'.$filename;

                                    if ( file_exists($absolute_path) )
                                        unlink($absolute_path);

                                    $ret .= 'setting Image '.$image->getId().' of datarecord '.$datarecord->getId().' datafield '.$datafield->getId().' to be non-public'."\n";
                                }
                                else if ( !$image->isPublic() && $value == 1 ) {
                                    // Image is non-public, but needs to be public
                                    $properties = array('publicDate' => new \DateTime());
                                    parent::ODR_copyImageMeta($em, $user, $image, $properties);

                                    // Immediately decrypt the image
                                    parent::decryptObject($image->getId(), 'image');

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
                    $storage_entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);    // will create if it doesn't exist, and return existing entity otherwise
                    $old_value = $storage_entity->getValue()->format('Y-m-d');

                    if ($old_value != $value) {
                        // Make the change to the value stored in the storage entity
                        parent::ODR_copyStorageEntity($em, $user, $storage_entity, array('value' => new \DateTime($value)));

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
                    $storage_entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);    // will create if it doesn't exist, and return existing entity otherwise
                    $old_value = $storage_entity->getValue();

                    if ($old_value != $value) {
                        // Make the change to the value stored in the storage entity
                        parent::ODR_copyStorageEntity($em, $user, $storage_entity, array('value' => $value));

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

                    if ($count >= $total && $total != -1)
                        $tracked_job->setCompleted( new \DateTime() );

                    $em->persist($tracked_job);
//                    $em->flush();
$ret .= '  Set current to '.$count."\n";
                }

                // Save all the changes that were made
                $em->flush();


                // ----------------------------------------
                // TODO - replace this block with code to directly update the cached version of the datarecord?
                parent::tmp_updateDatarecordCache($em, $datarecord, $user);

                // ----------------------------------------
                // See if any cached search results need to be deleted...
                $cached_searches = parent::getRedisData(($redis->get($redis_prefix.'.cached_search_results')));
                if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
                    // Just delete all cached search results for this datatype...TODO - make it more precise than that...
                    unset ( $cached_searches[$datatype_id] );

                    // Save the collection of cached searches back to memcached
                    $redis->set($redis_prefix.'.cached_search_results', gzcompress(serialize($cached_searches)));
                }

$ret .=  "---------------\n";
                $return['d'] = $ret;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x61395739 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
