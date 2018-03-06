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
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Forms
use ODR\AdminBundle\Form\CreateDatatypeForm;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// Utility
use ODR\AdminBundle\Component\Utility\UserUtility;


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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // --------------------
            // Grab user privileges to determine what they can do
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);
            // --------------------


            // Grab a list of top top-level datatypes
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();

            // Grab each top-level datatype from the repository
            if ($section == "templates") {
                // Only want master templates to be displayed in this section
                $query = $em->createQuery(
                   'SELECT dt, dtm, md, md_dtm, dt_cb, dt_ub
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN dt.dataTypeMeta AS dtm
                    LEFT JOIN dt.metadata_datatype AS md
                    JOIN md.dataTypeMeta as md_dtm
                    JOIN dt.createdBy AS dt_cb
                    JOIN dt.updatedBy AS dt_ub
                    WHERE dt.id IN (:datatypes) AND dt.is_master_type = 1
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters( array('datatypes' => $top_level_datatypes) );
            }
            else {
                $query = $em->createQuery(
                   'SELECT dt, dtm, md, md_dtm dt_cb, dt_ub
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN dt.dataTypeMeta AS dtm
                    LEFT JOIN dt.metadata_datatype AS md
                    JOIN md.dataTypeMeta as md_dtm
                    JOIN dt.createdBy AS dt_cb
                    JOIN dt.updatedBy AS dt_ub
                    WHERE dt.id IN (:datatypes) AND dt.is_master_type = 0
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters( array('datatypes' => $top_level_datatypes) );
            }
            $results = $query->getArrayResult();

            $datatypes = array();
            foreach ($results as $result) {
                $dt_id = $result['id'];

                $dt = $result;
                $dt['dataTypeMeta'] = $result['dataTypeMeta'][0];
                $dt['createdBy'] = UserUtility::cleanUserData($result['createdBy']);
                $dt['updatedBy'] = UserUtility::cleanUserData($result['updatedBy']);
                if(isset($result['metadata_datatype'])) {
                    $dt['metadata_datatype']['dataTypeMeta'] = $result['metadata_datatype']['dataTypeMeta'][0];
                }

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


            // Render and return the html for the datatype list
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:type_list.html.twig',
                    array(
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'section' => $section,
                        'datatypes' => $datatypes,
                        'metadata' => $metadata,
                    )
                )
            );

            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            $session = $request->getSession();
            $session->set('scroll_target', '');
        }
        catch (\Exception $e) {
            $source = 0x24d5aae9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
    public function createAction($create_master, Request $request)
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // --------------------
            // Grab user privileges to determine what they can do
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);
            // --------------------

            // Grab a list of top top-level datatypes
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();

            $query = $em->createQuery(
               'SELECT dt, dtm, dt_cb, dt_ub
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                JOIN dt.createdBy AS dt_cb
                JOIN dt.updatedBy AS dt_ub
                WHERE dt.id IN (:datatypes) AND dt.is_master_type = 1
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
        }
        catch (\Exception $e) {
            $source = 0x72002e34;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Starts the create database wizard and loads master templates available for creation
     * from templates.
     *
     * @param integer $template_choice
     * @param integer $creating_master_template
     * @param Request $request
     *
     * @return Response
     */
    public function createinfoAction($template_choice, $creating_master_template, Request $request)
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // --------------------
            // Grab user privileges to determine what they can do
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);
            // --------------------

            if ($template_choice != 0 && $creating_master_template == 1)
                throw new ODRBadRequestException('Currently unable to copy a new Master Template from an existing Master Template');

            // Build a form for creating a new datatype, if needed
            $new_datatype_data = new DataTypeMeta();
            $params = array(
                'form_settings' => array(
                    'is_master_type' => $creating_master_template,
                    'master_type_id' => $template_choice,
                )
            );

            $form = $this->createForm(CreateDatatypeForm::class, $new_datatype_data, $params);

            // Grab a list of top top-level datatypes
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();

            // Get the master templates
            $query = $em->createQuery(
               'SELECT dt, dtm, dt_cb, dt_ub
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                JOIN dt.createdBy AS dt_cb
                JOIN dt.updatedBy AS dt_ub
                WHERE dt.id IN (:datatypes) AND dt.is_master_type = 1
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
                        'creating_master_template' => $creating_master_template,
                        'form' => $form->createView()
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xeaff78ff;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
        $return['d'] = array();

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

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

                $short_name = trim($submitted_data->getShortName());
                // Set Long Name equal to Short Name
                // DEPRECATED => Long Name
                $long_name = $short_name;
                if ($short_name == '' || $long_name == '')
                    $form->addError( new FormError('New databases require a database name') );

                if ( strlen($short_name) > 32)
                    $form->addError( new FormError('Shortname has a maximum length of 32 characters') );    // underlying database column only permits 32 characters

                if ($form['is_master_type']->getData() > 0 && $form['master_type_id']->getData() > 0)
                    $form->addError( new FormError('Currently unable to copy a new Master Template from an existing Master Template') );

                if ($form->isValid()) {
                    // ----------------------------------------
                    // Create a new Datatype entity
                    $datatype = new DataType();
                    $datatype->setRevision(0);

                    // Top-level datatypes exist in one of three three states...TODO - should there be more states?
                    // initial - datatype isn't ready for anything really...it shouldn't be displayed to the user
                    // incomplete - datatype can be viewed and modified as usual, but it's missing search result templates
                    // operational - datatype should work perfectly
                    $datatype->setSetupStep(DataType::STATE_INITIAL);

                    // Is this a Master Type?
                    $datatype->setIsMasterType(false);
                    if ($form['is_master_type']->getData() > 0)
                        $datatype->setIsMasterType(true);

                    // If the user decided to create this datatype from a master template...
                    if ($form['master_type_id']->getData() > 0) {
                        // ...locate the master template datatype and store that it's the "source" for this new datatype
                        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
                        /** @var DataType $master_datatype */
                        $master_datatype = $repo_datatype->find($form['master_type_id']->getData());
                        if ($master_datatype == null)
                            throw new ODRNotFoundException('Master Datatype');

                        $datatype->setMasterDataType($master_datatype);
                    }

                    $datatype->setCreatedBy($admin);
                    $datatype->setUpdatedBy($admin);

                    // Save all changes made
                    $em->persist($datatype);
                    $em->flush();
                    $em->refresh($datatype);

                    // Top level datatypes are their own parent/grandparent
                    $datatype->setParent($datatype);
                    $datatype->setGrandparent($datatype);
                    $em->persist($datatype);

                    // Fill out the rest of the metadata properties for this datatype...don't need to set short/long name since they're already from the form
                    $submitted_data->setDataType($datatype);
                    $submitted_data->setLongName($short_name);

                    /** @var RenderPlugin $default_render_plugin */
                    $default_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);    // default render plugin
                    $submitted_data->setRenderPlugin($default_render_plugin);

                    if ($submitted_data->getDescription() == null)
                        $submitted_data->setDescription('');

                    // Default search slug to Database ID
                    $submitted_data->setSearchSlug($datatype->getId());
                    $submitted_data->setXmlShortName('');

                    // Master Template Metadata
                    // Once a child database is completely created from the master template, the creation process will update the revisions appropriately.
                    $submitted_data->setMasterRevision(0);
                    $submitted_data->setMasterPublishedRevision(0);
                    $submitted_data->setTrackingMasterRevision(0);

                    if ($form['is_master_type']->getData() > 0) {
                        // Master Templates must increment revision so that data fields can reference the "to be published" revision.
                        // Whenever an update is made to any data field the revision should be updated.
                        // Master Published revision should only be updated when curators "publish" the latest revisions through the publication dialog.
                        $submitted_data->setMasterPublishedRevision(0);
                        $submitted_data->setMasterRevision(1);
                    }

                    $submitted_data->setPublicDate( new \DateTime('2200-01-01 00:00:00') );

                    $submitted_data->setExternalIdField(null);
                    $submitted_data->setNameField(null);
                    $submitted_data->setSortField(null);
                    $submitted_data->setBackgroundImageField(null);

                    $submitted_data->setCreatedBy($admin);
                    $submitted_data->setUpdatedBy($admin);
                    $em->persist($submitted_data);

                    // Ensure the "in-memory" version of the new datatype knows about its meta entry
                    $datatype->addDataTypeMetum($submitted_data);
                    $em->flush();


                    $datatypes_to_process = array();
                    array_push($datatypes_to_process, $datatype);

                    /*
                     * Create Datatype Metadata Object (a second datatype to store one record with the properties
                     * for the parent datatype).
                     */
                    // If is_master_type - automatically create master_type_metadata and set metadata_for_id
                    if($datatype->getIsMasterType()){
                        $metadata_datatype = clone $datatype;

                        // Set this to be metadata for new datatype
                        $metadata_datatype->setParent($metadata_datatype);
                        $metadata_datatype->setGrandparent($metadata_datatype);

                        // Set new datatype meta
                        $metadata_datatype_meta = clone $datatype->getDataTypeMeta();
                        $metadata_datatype_meta->setShortName($metadata_datatype_meta->getShortName() . " Properties");
                        $metadata_datatype_meta->setLongName($metadata_datatype_meta->getLongtName() . " Properties");
                        $metadata_datatype_meta->setDataType($metadata_datatype);

                        // Associate the metadata
                        $metadata_datatype->addDataTypeMetum($metadata_datatype_meta);

                        // New Datatype
                        $em->persist($metadata_datatype);
                        // New Datatype Meta
                        $em->persist($metadata_datatype_meta);

                        // Write to db
                        $em->flush();

                        // Set Metadata Datatype for Datatype
                        $datatype->setMetadataDatatype($metadata_datatype);
                        $em->persist($datatype);

                        // Set search slug
                        $metadata_datatype_meta->setSearchSlug($metadata_datatype->getId());
                        $em->persist($metadata_datatype_meta);
                        $em->flush();

                        array_push($datatypes_to_process, $metadata_datatype);
                    }
                    else if($datatype->getMasterDataType()) {
                        // If is non-master datatype, clone master-related metadata type
                        $master_datatype = $datatype->getMasterDataType();
                        $master_metadata = $master_datatype->getMetadataDatatype();

                        if($master_metadata != null) {
                            $metadata_datatype = clone $master_metadata;
                            // Unset is master type
                            $metadata_datatype->setIsMasterType(0);
                            $metadata_datatype->setGrandparent($metadata_datatype);
                            $metadata_datatype->setParent($metadata_datatype);
                            $metadata_datatype->setMasterDataType($master_metadata);

                            // Set new datatype meta
                            $metadata_datatype_meta = clone $datatype->getDataTypeMeta();
                            // $metadata_datatype_meta->setShortName($metadata_datatype_meta->getShortName() . " Properties");
                            // $metadata_datatype_meta->setLongName($metadata_datatype_meta->getLongtName() . " Properties");
                            $metadata_datatype_meta->setDataType($metadata_datatype);

                            // Associate the metadata
                            $metadata_datatype->addDataTypeMetum($metadata_datatype_meta);

                            // New Datatype
                            $em->persist($metadata_datatype);
                            // New Datatype Meta
                            $em->persist($metadata_datatype_meta);
                            // Set Metadata Datatype
                            $datatype->setMetadataDatatype($metadata_datatype);
                            $em->persist($datatype);
                            $em->flush();

                            array_push($datatypes_to_process, $metadata_datatype);

                        }
                    }
                    else {
                        // Do we automatcially create a metadata datatype?
                        // Probably not since it is confusing to know what to put there.
                    }
                    /*
                     * END Create Datatype Metadata Object
                     */


                    /*
                     * Clone theme or create theme as needed for new datatype(s)
                     */
                    foreach($datatypes_to_process as $datatype) {
                        // ----------------------------------------
                        // If the datatype is being created from a master template...
                        if ($datatype->getMasterDatatype()) {
                            // Start the job to create the datatype from the template
                            $pheanstalk = $this->get('pheanstalk');
                            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
                            $api_key = $this->container->getParameter('beanstalk_api_key');

                            // Insert the new job into the queue
                            $priority = 1024;   // should be roughly default priority
                            $payload = json_encode(
                                array(
                                    "user_id" => $admin->getId(),
                                    "datatype_id" => $datatype->getId(),

                                    "redis_prefix" => $redis_prefix,    // debug purposes only
                                    "api_key" => $api_key,
                                )
                            );

                            $delay = 0;
                            $pheanstalk->useTube('create_datatype')->put($payload, $priority, $delay);
                        }
                        else {
                            // ...otherwise, this is a new custom datatype.  It'll need a default master theme...
                            $theme = new Theme();
                            $theme->setDataType($datatype);
                            $theme->setThemeType('master');
                            $theme->setCreatedBy($admin);
                            $theme->setUpdatedBy($admin);

                            $em->persist($theme);
                            $em->flush();
                            $em->refresh($theme);

                            // "master" themes for top-level datatypes are considered their own parent and source
                            $theme->setParentTheme($theme);
                            $theme->setSourceTheme($theme);
                            $em->persist($theme);

                            // ...and an associated meta entry
                            $theme_meta = new ThemeMeta();
                            $theme_meta->setTheme($theme);
                            $theme_meta->setTemplateName('');
                            $theme_meta->setTemplateDescription('');
                            $theme_meta->setIsDefault(true);
                            $theme_meta->setShared(true);
                            $theme_meta->setIsTableTheme(false);
                            $theme_meta->setCreatedBy($admin);
                            $theme_meta->setUpdatedBy($admin);

                            $em->persist($theme_meta);

                            // This dataype is now technically viewable since it has basic theme data...
                            // Nobody is able to view it however, since it has no permission entries
                            $datatype->setSetupStep(DataType::STATE_INCOMPLETE);
                            $em->persist($datatype);
                            $em->flush();

                            // Delete the cached version of the datatree array and the list of top-level datatypes
                            $cache_service->delete('cached_datatree_array');
                            $cache_service->delete('top_level_datatypes');
                            $cache_service->delete('top_level_themes');

                            // Create the groups for the new datatype here so the datatype can be viewed
                            $pm_service->createGroupsForDatatype($admin, $datatype);

                            // Ensure the user who created this datatype becomes a member of the new datatype's "is_datatype_admin" group
                            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') ) {
                                // createUserGroup() updates the database so all super admins automatically have permissions to this new datatype

                                /** @var Group $admin_group */
                                $admin_group = $em->getRepository('ODRAdminBundle:Group')->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'admin') );
                                $pm_service->createUserGroup($admin, $admin_group, $admin);

                                // Delete cached version of this user's permissions
                                $cache_service->delete('user_'.$admin->getId().'_permissions');
                            }
                        }
                    }
                    /*
                     * END Clone theme or create theme as needed for new datatype(s)
                     */


                    // Note: Since the system will always create metadata database second, this redirect will push
                    // the user edit their metadata template first as it is the last thing set to $datatype above.
                    // Perhaps this should be more explicitly chosen.
                    // TODO - This is not good.  A long copy above may not be finished by the time the time the user arrives at design system.
                    $url = $this->generateUrl('odr_design_master_theme', array('datatype_id' => $datatype->getId()), false);
                    $return['d']['redirect_url'] = $url;
                }
                else {
                    // Return any errors encountered
                    $error_str = parent::ODR_getErrorMessages($form);
                    throw new ODRException($error_str);
                }
            }
            else {
                // Otherwise, this was a GET request
                $templating = $this->get('templating');
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Datatype:create_datatype_info_form.html.twig',
                    array(
                        'form' => $form->createView()
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x6151265b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
