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
            $master_templates = $query->getArrayResult();


            // Build a form for creating a new datatype, if needed
            // Render and return the html
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:list_ajax.html.twig',
                    array(
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'section' => $section,
                        'datatypes' => $datatypes,
                        'master_templates' => $master_templates,
                        'metadata' => $metadata,
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
            $return['d'] = 'Error 0x834283490 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    /**
     * Starts the create database wizard and loads master templates available for creation
     * from templates.
     *
     * @param bool $create_master - Create a master template (true/false). Only allows custom type creation when true.
     * @param Request $request
     *
     * @return Response
     */
    public function createAction($create_master = 0, Request $request)
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
            $master_templates = $query->getArrayResult();

            // Render and return the html
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:create_type_choose_template.html.twig',
                    array(
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'master_templates' => $master_templates,
                        'create_master' => $create_master
                    )
                )
            );

            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            // $session = $request->getSession();
            // $session->set('scroll_target', '');
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
     * Starts the create database wizard and loads master templates available for creation
     * from templates.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function createinfoAction($template_choice, Request $request)
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

            // Build a form for creating a new datatype, if needed
            $new_datatype_data = new DataTypeMeta();
            $form = $this->createForm(
                CreateDatatypeForm::class,
                $new_datatype_data,
                array(
                    'form_settings' => array(
                        'is_master_type' => 0,
                        'master_type_id' => $template_choice
                    )
                )
            );

            // Grab a list of top top-level datatypes
            $top_level_datatypes = parent::getTopLevelDatatypes();

            // Get the master templates
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
            $master_templates = $query->getArrayResult();

            // Render and return the html
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:create_type_database_info.html.twig',
                    array(
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'master_templates' => $master_templates,
                        'master_type_id' => $template_choice,
                        'form' => $form->createView()
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x898829340 ' . $e->getMessage();
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
                // Set Long Name equal to Short Name
                // DEPRECATED => Long Name
                $long_name = $submitted_data->getShortName();
                if ($short_name == '' || $long_name == '')
                    $form->addError( new FormError('New databases require a database name') );

                if ($form->isValid()) {
                    // ----------------------------------------
                    // Create a new Datatype entity
                    $datatype = new DataType();
                    $datatype->setRevision(0);
                    $datatype->setHasShortresults(false);
                    $datatype->setHasTextresults(false);

                    // Need to define the setup steps:
                    // create - creating the database and setting initial metadata
                    // required_themes - setting the required themes.
                    // Custom and master datatypes go directly to design.
                    $datatype->setSetupStep('design');

                    // Is this a Master Type?
                    $datatype->setIsMasterType(false);
                    if($form['is_master_type']->getData() > 0) {
                        $datatype->setIsMasterType(true);
                    }

                    // A master template has been chosen so
                    // the system needs to generate a copy of the
                    // master template before forwarding to design
                    // page for editing.
                    if($form['master_type_id']->getData() > 0) {
                        // Databases based on master types sit in "create"
                        // status until the system finishes cloning the parent
                        // master type to create the database.
                        $datatype->setSetupStep('create');
                        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
                        $master_datatype = $repo_datatype->find($form['master_type_id']->getData());
                        $datatype->setMasterDatatype($master_datatype);
                    }

                    $datatype->setCreatedBy($admin);
                    $datatype->setUpdatedBy($admin);

                    // Save all changes made
                    $em->persist($datatype);
                    $em->flush();
                    $em->refresh($datatype);

                    // Fill out the rest of the metadata properties for this datatype...don't need to set short/long name
                    $submitted_data->setDataType($datatype);
                    $submitted_data->setLongName($short_name);

                    $default_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);    // default render plugin
                    $submitted_data->setRenderPlugin($default_render_plugin);


                    if($submitted_data->getDescription() == null) {
                        $submitted_data->setDescription('');
                    }
                    $submitted_data->setSearchSlug(null);
                    $submitted_data->setXmlShortName('');

                    // Master Template Metadata
                    // Once a child database is completely created from
                    // the master template, the creation process will
                    // update the revisions appropriately.
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
                    // TODO - Determine if this is necessary
                    // Clear memcached of all datatype permissions for all users...the entries will get rebuilt the next time they do something
                    $redis = $this->container->get('snc_redis.default');;
                    // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
                    $redis_prefix = $this->container->getParameter('memcached_key_prefix');

                    // Delete the cached version of the datatree array
                    $redis->del($redis_prefix.'.cached_datatree_array');

                    // Master datatype will be null if not set?
                    if($datatype->getMasterDataType()) {
                        // Start the job to create the datatype from the template

                        // ----------------------------------------
                        // Use beanstalk to encrypt the file so the UI doesn't block on huge files
                        $pheanstalk = $this->get('pheanstalk');
                        $router = $this->container->get('router');
                        $redis_prefix = $this->container->getParameter('memcached_key_prefix');
                        $api_key = $this->container->getParameter('beanstalk_api_key');

                        // Insert the new job into the queue
                        $priority = 1024;   // should be roughly default priority
                        $payload = json_encode(
                            array(
                                "user_id" => $this->getUser()->getId(),
                                "datatype_id" => $datatype->getId(),
                                "redis_prefix" => $redis_prefix,    // debug purposes only
                                "api_key" => $api_key,
                            )
                        );

                        $delay = 0;
                        $pheanstalk->useTube('create_datatype')->put($payload, $priority, $delay);

                    }

                    // Forward to DisplayTemplate
                    $url = $this->generateUrl('odr_design_master_theme', array('datatype_id' => $datatype->getId()), false);
                    $return['d']['action'] = 'redirect';
                    $return['d']['redirect_url'] = $url;
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
                    'ODRAdminBundle:Datatype:create_datatype_info_form.html.twig',
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

}
