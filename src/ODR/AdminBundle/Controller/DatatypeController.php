<?php

/**
 * Open Data Repository Data Publisher
 * DataType Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The DataType controller handles creation and editing (most)
 * properties of datatypes.  Deletion is handled in DisplayTemplate.
 * It also handles rendering the pages that allow the user to design
 * datatypes, or modify datarecords of each datatype.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
use ODR\AdminBundle\Form\CreateDatatypeForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class DatatypeController extends ODRCustomController
{

    /**
     * Builds and returns a list of the actions a user can perform to each top-level DataType.
     * 
     * @param string $section  Either "records" or "design", dictating which set of options the user will see for each datatype
     * @param Request $request
     * 
     * @return Response
     */
    public function listAction($section, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $templating = $this->get('templating');

            // --------------------
            // Grab user privileges to determine what they can do
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            // --------------------


            // Grab a list of top top-level datatypes
            $top_level_datatypes = parent::getTopLevelDatatypes();

            // Grab each top-level datatype from the repository
            if($section == "master") {
                $query = $em->createQuery(
                    'SELECT dt, dtm, dt_cb, dt_ub
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                JOIN dt.createdBy AS dt_cb
                JOIN dt.updatedBy AS dt_ub
                WHERE dt.id IN (:datatypes)
                AND dt.is_master_type = 1
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters( array('datatypes' => $top_level_datatypes) );
            }
            else {
                $query = $em->createQuery(
                    'SELECT dt, dtm, dt_cb, dt_ub
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                JOIN dt.createdBy AS dt_cb
                JOIN dt.updatedBy AS dt_ub
                WHERE dt.id IN (:datatypes)
                AND dt.is_master_type = 0
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters( array('datatypes' => $top_level_datatypes) );
            }
            $results = $query->getArrayResult();

            $datatypes = array();
            foreach ($results as $result) {
                $dt_id = $result['id'];

                $dt = $result;
                $dt['dataTypeMeta'] = $result['dataTypeMeta'][0];
                $dt['createdBy'] = parent::cleanUserData($result['createdBy']);
                $dt['updatedBy'] = parent::cleanUserData($result['updatedBy']);

                $datatypes[$dt_id] = $dt;
            }

            // Determine whether user has the ability to view non-public datarecords for this datatype
            $can_view_public_datarecords = array();
            $can_view_nonpublic_datarecords = array();
            foreach ($datatypes as $dt_id => $dt) {
                if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dr_view']) )
                    $can_view_nonpublic_datarecords[] = $dt_id;
                else
                    $can_view_public_datarecords[] = $dt_id;
            }

            // Figure out how many datarecords the user can view for each of the datatypes
            $metadata = array();
            if ( count($can_view_nonpublic_datarecords) > 0 ) {
                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id, COUNT(dr.id) AS datarecord_count
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                    WHERE dt IN (:datatype_ids) AND dr.provisioned = false
                    AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL
                    GROUP BY dt.id'
                )->setParameters( array('datatype_ids' => $can_view_nonpublic_datarecords) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $dt_id = $result['dt_id'];
                    $count = $result['datarecord_count'];

                    $metadata[$dt_id] = $count;
                }
            }

            if ( count($can_view_public_datarecords) > 0 ) {
                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id, COUNT(dr.id) AS datarecord_count
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                    JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                    WHERE dt IN (:datatype_ids) AND dr.provisioned = false AND drm.publicDate != :public_date
                    AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL
                    GROUP BY dt.id'
                )->setParameters( array('datatype_ids' => $can_view_public_datarecords, 'public_date' => '2200-01-01 00:00:00') );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $dt_id = $result['dt_id'];
                    $count = $result['datarecord_count'];
                    $metadata[$dt_id] = $count;
                }
            }

            // Build a form for creating a new datatype, if needed
            $new_datatype_data = new DataTypeMeta();
            $form = $this->createForm(CreateDatatypeForm::class, $new_datatype_data);

            // Render and return the html
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:list_ajax.html.twig', 
                    array(
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'section' => $section,
                        'datatypes' => $datatypes,
                        'metadata' => $metadata,
                        'form' => $form->createView()
                    )
                )
            );

            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            $session = $request->getSession();
            $session->set('scroll_target', '');
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x884775820 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Creates a new top-level DataType.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function addAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $templating = $this->get('templating');

            // Don't need to verify permissions, firewall won't let this action be called unless user is admin
            /** @var User $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // Create new DataType form
            $submitted_data = new DataTypeMeta();
            $form = $this->createForm(CreateDatatypeForm::class, $submitted_data);

            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                // This was a POST request

                // Can't seem to figure out why it occassionally attempts to create an empty datatype, so...guessing here
                if ($form->isEmpty())
                    $form->addError( new FormError('Form is empty?') );

                $short_name = $submitted_data->getShortName();
                $long_name = $submitted_data->getLongName();
                if ($short_name == '' || $long_name == '')
                    $form->addError( new FormError('New Datatypes require both a short name and a long name') );

                if ($form->isValid()) {
                    // ----------------------------------------
                    // Create a new Datatype entity
                    $datatype = new DataType();
                    $datatype->setRevision(0);
                    $datatype->setHasShortresults(false);
                    $datatype->setHasTextresults(false);

                    // This is a Master Type
                    if($form['is_master_type']->getData() > 0) {
                        $datatype->setIsMasterType(true);
                    }

                    // Need to define the setup steps:
                    // create - creating the database and setting initial metadata
                    // required_themes - setting the required themes
                    $datatype->setSetupStep('create');


                    $datatype->setCreatedBy($admin);
                    $datatype->setUpdatedBy($admin);

                    // Save all changes made
                    $em->persist($datatype);
                    $em->flush();
                    $em->refresh($datatype);

                    // Fill out the rest of the metadata properties for this datatype...don't need to set short/long name
                    $submitted_data->setDataType($datatype);

                    $default_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);    // default render plugin
                    $submitted_data->setRenderPlugin($default_render_plugin);

                    $submitted_data->setDescription('');
                    $submitted_data->setSearchSlug(null);
                    $submitted_data->setXmlShortName('');

                    // Master Template Metadata
                    $submitted_data->setMasterRevision(0);
                    $submitted_data->setMasterPublishedRevision(0);
                    $submitted_data->setTrackingMasterRevision(0);

                    if($form['is_master_type']->getData() > 0) {
                        // Master Templates must increment revision
                        // so that data fields can reference the "to be published"
                        // revision.  Whenever an update is made to any data field
                        // the revision should be updated.  Master Published revision
                        // should only be updated when curators "publish" the latest
                        // revisions through the publication dialog.
                        $submitted_data->setMasterPublishedRevision(0);
                        $submitted_data->setMasterRevision(1);
                    }

                    $submitted_data->setUseShortResults(true);
                    $submitted_data->setPublicDate( new \DateTime('1980-01-01 00:00:00') );

                    $submitted_data->setExternalIdField(null);
                    $submitted_data->setNameField(null);
                    $submitted_data->setSortField(null);
                    $submitted_data->setBackgroundImageField(null);

                    $submitted_data->setCreatedBy($admin);
                    $submitted_data->setUpdatedBy($admin);
                    $em->persist($submitted_data);

                    // ----------------------------------------
                    // Create the default groups for this datatype
                    $is_top_level = true;
                    parent::ODR_createGroupsForDatatype($em, $admin, $datatype, $is_top_level);


                    // ----------------------------------------
                    // Create a new master theme for this new datatype
                    $theme = new Theme();
                    $theme->setDataType($datatype);
                    $theme->setThemeType('master');
                    $theme->setCreatedBy($admin);
                    $theme->setUpdatedBy($admin);

                    $em->persist($theme);
                    $em->flush();
                    $em->refresh($theme);

                    // ...and its associated meta entry
                    $theme_meta = new ThemeMeta();
                    $theme_meta->setTheme($theme);
                    $theme_meta->setTemplateName('');
                    $theme_meta->setTemplateDescription('');
                    $theme_meta->setIsDefault(true);
                    $theme_meta->setCreatedBy($admin);
                    $theme_meta->setUpdatedBy($admin);

                    $em->persist($theme_meta);
                    $em->flush();


                    // ----------------------------------------
                    // Clear memcached of all datatype permissions for all users...the entries will get rebuilt the next time they do something
                    $redis = $this->container->get('snc_redis.default');;
                    // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
                    $redis_prefix = $this->container->getParameter('memcached_key_prefix');

                    // Delete the cached version of the datatree array
                    $redis->del($redis_prefix.'.cached_datatree_array');
                }
                else {
                    // Return any errors encountered
                    $return['r'] = 1;
                    $return['d'] = parent::ODR_getErrorMessages($form);
                }
            }
            else {
                // Otherwise, this was a GET request
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Datatype:add_type_dialog_form.html.twig',
                    array(
                        'form' => $form->createView()
                    )
                );
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x3184489: '.$e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    /**
     * Triggers a recache of all datarecords of all datatypes.
     * @deprecated
     * TODO - using old recaching system.
     *
     * @param Request $request
     * 
     * @return Response
     */
    public function recacheallAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Permissions check
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError("rebuild the cache for");  // TODO - really should say everything

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
//            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $api_key = $this->container->getParameter('beanstalk_api_key');

            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
//            if ( $this->container->getParameter('kernel.environment') === 'dev') { $url .= './app_dev.php'; }
                $url .= $router->generate('odr_recache_record');

            // Grab all top-level datatypes on the site
            $top_level_datatypes = parent::getTopLevelDatatypes();

            // Create and start a recache job for each of them
            $current_time = new \DateTime();
            foreach ($top_level_datatypes as $num => $datatype_id) {
                // ----------------------------------------
                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);

                // Increment the datatype revision number so the worker processes will recache the datarecords
                $revision = $datatype->getRevision();
                $datatype->setRevision( $revision + 1 );
                $em->persist($datatype);
                $em->flush();

                // Grab all non-deleted datarecords of each datatype
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :dataType AND dr.provisioned = false
                    AND dr.deletedAt IS NULL'
                )->setParameters( array('dataType' => $datatype_id) );
                $results = $query->getArrayResult();

                if ( count($results) > 0 ) {
                    // ----------------------------------------
                    // Get/create an entity to track the progress of this datatype recache
                    $job_type = 'recache';
                    $target_entity = 'datatype_'.$datatype_id;
                    $additional_data = array('description' => 'Recache of DataType '.$datatype_id);
                    $restrictions = $datatype->getRevision();
                    $total = count($results);
                    $reuse_existing = true;

                    $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
                    $tracked_job_id = $tracked_job->getId();

                    // ----------------------------------------
                    // Schedule each of those datarecords for an update
                    foreach ($results as $result) {
                        $datarecord_id = $result['dr_id'];

                        // Insert the new job into the queue
                        $priority = 1024;   // should be roughly default priority
                        $payload = json_encode(
                            array(
                                "tracked_job_id" => $tracked_job_id,
                                "datarecord_id" => $datarecord_id,
                                "scheduled_at" => $current_time->format('Y-m-d H:i:s'),
                                "redis_prefix" => $redis_prefix,    // debug purposes only
                                "url" => $url,
                                "api_key" => $api_key,
                            )
                        );

                        $delay = 5;
                        $pheanstalk->useTube('recache_type')->put($payload, $priority, $delay);
                    }
                }
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x71034632: '.$e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
