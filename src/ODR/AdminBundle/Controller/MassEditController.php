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
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
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
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $logged_in = true;

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

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
                $data = parent::getSavedSearch($datatype_id, $search_key, $logged_in, $request);
                $encoded_search_key = $data['encoded_search_key'];
                $datarecord_list = $data['datarecord_list'];
                $complete_datarecord_list = $data['complete_datarecord_list'];

                // If there is no tab id for some reason, or the user is attempting to view a datarecord from a search that returned no results...
                if ( $odr_tab_id === '' || $data['error'] == true || ($encoded_search_key !== '' && $datarecord_list === '') ) {
                    // ...get the search controller to redirect to "no results found" page
                    $search_controller = $this->get('odr_search_controller', $request);
                    return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
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
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

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
        $datatype_permissions = parent::getPermissionsArray($user->getId(), $request);
        $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);
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
            $datatype_data = $memcached->get($memcached_prefix.'.cached_datatype_'.$dt_id);
            if ($bypass_cache || $datatype_data == null)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }


        // ----------------------------------------
        // Filter by user permissions
        $datarecord_data = array();
        parent::filterByUserPermissions($datatype_id, $datatype_array, $datarecord_data, $datatype_permissions, $datafield_permissions);


        // Render the MassEdit page
        $templating = $this->get('templating');
        $html = $templating->render(
            'ODRAdminBundle:MassEdit:massedit_ajax.html.twig',
            array(
                'datatype_array' => $datatype_array,
                'initial_datatype_id' => $datatype_id,
                'theme_id' => $theme->getId(),

                'odr_tab_id' => $odr_tab_id,
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

//            $memcached = $this->get('memcached');
//            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
//            $logged_in = true;

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // Grab datarecord list and search key from user session...not using memcached because the possibility exists that the list could have been deleted
            $list = $session->get('mass_edit_datarecord_lists');

            $complete_datarecord_list = '';
            $datarecords = '';
            $encoded_search_key = null;

            if ( isset($list[$odr_tab_id]) ) {
                $complete_datarecord_list = explode(',', $list[$odr_tab_id]['complete_datarecord_list']);
                $datarecords = $list[$odr_tab_id]['datarecord_list'];
                $encoded_search_key = $list[$odr_tab_id]['encoded_search_key'];
            }

            // If the datarecord list doesn't exist for some reason, or the user is attempting to view a datarecord from a search that returned no results...
            if ( !isset($list[$odr_tab_id]) || ($encoded_search_key !== '' && $datarecords === '') ) {
                // ...redirect to "no results found" page
                $search_controller = $this->get('odr_search_controller', $request);
                $search_controller->setContainer($this->container);

                return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
            }

            // TODO - delete the datarecord list/search key out of the user's session?


//            $datarecords = explode(',', $datarecords);

            // ----------------------------------------
            // Ensure no unique datafields managed to get marked for this mass update
            foreach ($datafields as $df_id => $value) {
                /** @var DataFields $df */
                $df = $repo_datafield->find($df_id);
                if ( $df->getIsUnique() == 1 )
                    unset($datafields[$df_id]);
            }
/*
print '$datarecords: '.print_r($datarecords, true)."\n";
print '$datafields: '.print_r($datafields, true)."\n";
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
            $job_count = 0;

            // Deal with datarecord public status first, if needed
            $updated = false;
            foreach ($public_status as $dt_id => $status) {
                // TODO - is this necessary?
                // Get all datarecords of this datatype
                $query = $em->createQuery(
                   'SELECT dr.id AS id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype_id AND dr.provisioned = false
                    AND dr.deletedAt IS NULL'
                )->setParameters( array('datatype_id' => $dt_id) );
                $results = $query->getArrayResult();

                $all_datarecord_ids = array();
                foreach ($results as $num => $tmp)
                    $all_datarecord_ids[] = $tmp['id'];

                $affected_datarecord_ids = array_intersect($all_datarecord_ids, $complete_datarecord_list);

                // Set the url for mass updating public status
                $url = $this->container->getParameter('site_baseurl');
                $url .= $this->container->get('router')->generate('odr_mass_update_worker_status');

                foreach ($affected_datarecord_ids as $num => $dr_id) {
                    // ...create a new beanstalk job
                    $job_count++;

                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "job_type" => 'public_status_change',
                            "tracked_job_id" => $tracked_job_id,
                            "user_id" => $user->getId(),

                            "datarecord_id" => $dr_id,
                            "public_status" => $status,

                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 15;    // TODO - delay set rather high because unable to determine how many jobs need to be created beforehand...better way of dealing with this?
                    $pheanstalk->useTube('mass_edit')->put($payload, $priority, $delay);
                }
            }


            // ----------------------------------------
            foreach ($datafields as $df_id => $value) {
                // TODO - is this necessary?
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id, drf.id AS drf_id
                    FROM ODRAdminBundle:DataFields AS df
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataField = df
                    JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                    WHERE df.id = :datafield_id
                    AND df.deletedAt IS NULL AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL'
                )->setParameters( array('datafield_id' => $df_id) );
                $results = $query->getArrayResult();

                $affected_drfs = array();
                foreach ($results as $num => $tmp) {
                    $dr_id = $tmp['dr_id'];
                    $drf_id = $tmp['drf_id'];

                    $affected_drfs[ $dr_id ] = $drf_id;
                }

                // Set the url for mass updating datarecord values
                $url = $this->container->getParameter('site_baseurl');
                $url .= $this->container->get('router')->generate('odr_mass_update_worker_values');

                foreach ($affected_drfs as $dr_id => $drf_id) {
                    // If this datarecord matched the search...
                    if ( in_array($dr_id, $complete_datarecord_list) ) {
                        // ...create a new beanstalk job
                        $job_count++;

                        $priority = 1024;   // should be roughly default priority
                        $payload = json_encode(
                            array(
                                "job_type" => 'value_change',
                                "tracked_job_id" => $tracked_job_id,
                                "user_id" => $user->getId(),

                                "datarecordfield_id" => $drf_id,
                                "value" => $value,

                                "memcached_prefix" => $memcached_prefix,    // debug purposes only
                                "url" => $url,
                                "api_key" => $api_key,
                            )
                        );

                        $delay = 15;    // TODO - delay set rather high because unable to determine how many jobs need to be created beforehand...better way of dealing with this?
                        $pheanstalk->useTube('mass_edit')->put($payload, $priority, $delay);
                    }
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
     * Called by the mass update worker processes to update a datarecord-datafield pair to a new value.
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
            $tracked_job_id = $post['tracked_job_id'];
            $user_id = $post['user_id'];
            $datarecord_id = $post['datarecord_id'];
            $public_status = $post['public_status'];
            $api_key = $post['api_key'];

            // Load symfony objects
//            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
//            $pheanstalk = $this->get('pheanstalk');
//            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

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
                throw new \Exception('MassEditCommand.php: DataRecord '.$datarecord_id.' belongs to deleted DataType, skipping');

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
                    $options = array(
                        'user_id' => $user->getId(),    // since this action is called via command-line, need to specify which user is doing the mass updating
                        'mark_as_updated' => true
                    );

                    // Refresh the cache entries for this datarecord
                    parent::updateDatarecordCache($datarecord->getId(), $options);
                }
            }


            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id != -1) {
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

        try {
            $ret = '';
            $post = $_POST;
//$ret = print_r($post, true);
            if ( !isset($post['tracked_job_id']) || !isset($post['user_id']) || !isset($post['datarecordfield_id']) || !isset($post['value']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = $post['tracked_job_id'];
            $user_id = $post['user_id'];
            $datarecordfield_id = $post['datarecordfield_id'];
            $value = $post['value'];
            $api_key = $post['api_key'];

            // Load symfony objects
//            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
//            $pheanstalk = $this->get('pheanstalk');
//            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');
            $repo_datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');


            // ----------------------------------------
            /** @var User $user */
            $user = $repo_user->find($user_id);

            /** @var DataRecordFields $datarecordfield */
            $datarecordfield = $repo_datarecordfield->find($datarecordfield_id);
            if ($datarecordfield == null)
                throw new \Exception('MassEditCommand.php: DataRecordField '.$datarecordfield_id.' is deleted, skipping');

            $datarecord = $datarecordfield->getDataRecord();
            if ($datarecord == null)
                throw new \Exception('MassEditCommand.php: DataRecordField '.$datarecordfield_id.' refers to deleted DataRecord, skipping');

            $datafield = $datarecordfield->getDataField();
            if ($datafield == null)
                throw new \Exception('MassEditCommand.php: DataRecordField '.$datarecordfield_id.' refers to deleted DataField, skipping');

            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                throw new \Exception('MassEditCommand.php: DataRecordField '.$datarecordfield_id.' refers to deleted DataType, skipping');

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
                $field_typeclass = $datafield->getFieldType()->getTypeClass();
                $field_typename = $datafield->getFieldType()->getTypeName();

                if ($field_typeclass == 'Radio') {
                    // Grab all selection objects attached to this radio object
                    $radio_selections = array();
                    /** @var RadioSelection[] $tmp */
                    $tmp = $repo_radio_selection->findBy( array('dataRecordFields' => $datarecordfield->getId()) );
                    foreach ($tmp as $radio_selection)
                        $radio_selections[ $radio_selection->getRadioOption()->getId() ] = $radio_selection;
                    /** @var RadioSelection[] $radio_selections */

                    // $value is in format array('radio_option_id' => desired_state)
                    // Set radio_selection objects to the desired state
                    foreach ($value as $radio_option_id => $selected) {

                        /** @var RadioOptions $radio_option */
                        $radio_option = $repo_radio_option->find($radio_option_id);

                        if ( !isset($radio_selections[$radio_option_id]) ) {
                            // A RadioSelection entity for this RadioOption doesn't exist...create it
                            $new_radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield, $selected);
                            $em->persist($new_radio_selection);

                            $ret .= 'created radio_selection object for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', radio_option_id '.$radio_option_id.'...setting selected to '.$selected."\n";
                        }
                        else {
                            // A RadioSelection entity for this RadioOption already exists...
                            $radio_selection = $radio_selections[$radio_option_id];

                            if ( $selected != $radio_selection->getSelected() ) {
                                // ...but the value stored in it does not match the desired state...delete the old entity
                                $em->remove($radio_selection);

                                // Create a new RadioSelection entity with the desired state
                                $new_radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield, $selected);
                                $em->persist($new_radio_selection);

                                $ret .= 'found radio_selection object for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', radio_option_id '.$radio_option_id.'...setting selected to '.$selected."\n";
                            }
                            else {
                                /* value stored in RadioSelection entity matches desired state, do nothing */
                                $ret .= 'not changing radio_selection object for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', radio_option_id '.$radio_option_id.'...current selected status to desired status'."\n";
                            }

                            // Need to keep track of which radio_selection was modified for Single Radio/Select...
                            unset( $radio_selections[$radio_option_id] );
                        }
                    }

                    // If only a single selection is allowed, deselect the other existing radio_selection objects
                    if ( $field_typename == "Single Radio" || $field_typename == "Single Select" ) {
                        foreach ($radio_selections as $radio_option_id => $radio_selection) {
                            if ( $radio_selection->getSelected() == 1 ) {
                                // Delete the old RadioSelection entity
                                $em->remove($radio_selection);

                                // Create a new RadioSelection entity with the desired state
                                /** @var RadioOptions $radio_option */
                                $radio_option = $repo_radio_option->find($radio_option_id);
                                $new_radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield, 0);
                                $em->persist($new_radio_selection);

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
                            WHERE file.dataRecordFields = :drf
                            AND file.deletedAt IS NULL'
                        )->setParameters( array('drf' => $datarecordfield_id) );
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
                            WHERE image.dataRecordFields = :drf
                            AND image.deletedAt IS NULL'
                        )->setParameters( array('drf' => $datarecordfield_id) );
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
                    /** @var DatetimeValue $entity */
                    $entity = $datarecordfield->getAssociatedEntity();

                    if ($entity === null) {
                        // Create a new storage entity if one doesn't exist for some reason
                        $new_entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                        $new_entity->setValue( new \DateTime($value) );
                        $em->persist($new_entity);

                        $ret .= 'storage entity for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' did not exist...created and set to "'.$value."\"\n";
                    }
                    else if ($entity->getValue()->format('Y-m-d') != $value) {
                        // Delete old storage entity
                        $old_value = $entity->getValue()->format('Y-m-d');
                        $em->remove($entity);

                        // Create new storage entity with desired value
                        $new_entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                        $new_entity->setValue( new \DateTime($value) );
                        $em->persist($new_entity);

                        $ret .= 'changing datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' from "'.$old_value.'" to "'.$value."\"\n";
                    }
                    else {
                        /* do nothing, current value in entity already matches desired value */
                        $ret .= 'ignoring datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', current value "'.$entity->getValue()->format('Y-m-d').'" identical to desired value "'.$value.'"'."\n";
                    }
                }
                else {
                    // For every other fieldtype...
                    $entity = $datarecordfield->getAssociatedEntity();

                    if ($entity === null) {
                        // Create a new storage entity if one doesn't exist for some reason
                        $new_entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                        $new_entity->setValue($value);
                        $em->persist($new_entity);

                        $ret .= 'storage entity for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' did not exist...created and set to "'.$value."\"\n";
                    }
                    else if ($entity->getValue() != $value) {
                        // Delete old storage entity
                        $old_value = $entity->getValue();
                        $em->remove($entity);

                        // Create new storage entity with desired value
                        $new_entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
                        $new_entity->setValue($value);
                        $em->persist($new_entity);

                        $ret .= 'changing datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' from "'.$old_value.'" to "'.$value."\"\n";
                    }
                    else {
                        /* do nothing, current value in entity already matches desired value */
                        $ret .= 'ignoring datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', current value "'.$entity->getValue().'" identical to desired value "'.$value.'"'."\n";
                    }
                }


                // ----------------------------------------
                // Update the job tracker if necessary
                if ($tracked_job_id != -1) {
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
                // TODO - replace this block with code to directly update the cached version of the datarecord
                // Determine whether short/textresults needs to be updated
                $options = array(
                    'user_id' => $user->getId(),    // since this action is called via command-line, need to specify which user is doing the mass updating
                    'mark_as_updated' => true
                );

                if ( parent::inShortResults($datafield) )
                    $options['force_shortresults_recache'] = true;
                if ( $datafield->getDisplayOrder() != -1 )
                    $options['force_textresults_recache'] = true;

                // Recache this datarecord
                parent::updateDatarecordCache($datarecord->getId(), $options);
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
