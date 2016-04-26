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
use ODR\AdminBundle\Entity\UserPermissions;
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
     * @return Response TODO
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
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            // --------------------


            // Grab a list of top top-level datatypes
            $top_level_datatypes = parent::getTopLevelDatatypes();
//print_r($top_level_datatypes);

            // Grab each top-level datatype from the repository
            $query = $em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataType AS dt
                WHERE dt IN (:datatypes)'
            )->setParameters( array('datatypes' => $top_level_datatypes) );
            $results = $query->getResult();

            $datatypes = array();
            $metadata = array();
            foreach ($results as $dt) {
                /** @var DataType $dt */
                $datatypes[] = $dt;

                $dt_id = $dt->getId();
                $metadata[$dt_id] = array();
                $metadata[$dt_id]['count'] = 0;
            }
            /** @var DataType[] $datatypes */

            // Do a second query to grab which datatypes have datarecords, and how many
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id, COUNT(dr.id) AS datarecord_count
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                WHERE dt IN (:datatypes) AND dr.deletedAt IS NULL AND dr.provisioned = false
                GROUP BY dt.id'
            )->setParameters( array('datatypes' => $top_level_datatypes) );
            $results = $query->getArrayResult();
//print_r($results);

            foreach ($results as $result) {
                $datatype_id = $result['dt_id'];
                $datarecord_count = $result['datarecord_count'];

                $metadata[$datatype_id]['count'] = $datarecord_count;
            }
//print_r($metadata);

            // Build a form for creating a new datatype, if needed
            $new_datatype_data = new DataTypeMeta();
            $form = $this->createForm(new CreateDatatypeForm($new_datatype_data), $new_datatype_data);

            // Render and return the html
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:list_ajax.html.twig', 
                    array(
                        'user' => $user,
                        'permissions' => $user_permissions,
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
     * TODO - sitemap function
     * Builds and returns a JSON list of all top-level DataTypes.
     * 
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function getlistAction(Request $request)
    {
/*
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'json';
        $return['d'] = '';

        try {
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $repo_user_permissions = $this->getDoctrine()->getRepository('ODRAdminBundle:UserPermissions');
            $user_permissions = $repo_user_permissions->findBy( array('user_id' => $user) );

            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $this->getDoctrine()->getRepository('ODRAdminBundle:DataType');
            $repo_datatree = $this->getDoctrine()->getRepository('ODRAdminBundle:DataTree');

            // Need to only return top-level datatypes
            // TODO - TOP LEVEL DATATYPES
            $datatypes = null;
            $datatrees = $repo_datatree->findAll();
            $tmp_datatypes = $repo_datatype->findAll();

            // Locate the IDs of all datatypes that are descended from another datatype
            $descendants = array();
            foreach ($datatrees as $datatree) {
                if ($datatree->getIsLink() == 0)
                    $descendants[] = $datatree->getDescendant()->getId();
            }

            // Only save the datatypes that aren't descended from another datatype
            foreach ($tmp_datatypes as $tmp_datatype) {
                if ( !in_array($tmp_datatype->getId(), $descendants) )
                    $datatypes[] = $tmp_datatype;
            }

            // Determine additional metadata...
            $metadata = array();

            // ...how many datarecords each datatype has
            $query = $em->createQuery(
                'SELECT dt, COUNT(dt.id)
                FROM ODRAdminBundle:DataRecord dr
                JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
                WHERE dr.deletedAt IS NULL
                GROUP BY dt.id');
            $results = $query->getResult();

            foreach ($results as $num => $data) {
                // Parse the returned result for the datatype id and the number of datarecords belonging to that datatype
                $datatype_id = $count = 0;
                foreach ($data as $key => $value) {
                    if ($value instanceof \ODR\AdminBundle\Entity\DataType)
                        $datatype_id = $value->getId();
                    else
                        $count = $value;
                }

                $metadata[$datatype_id] = array();
                $metadata[$datatype_id]['count'] = $count;
            }


            $datatype_list = array();
            foreach ($datatypes as $dt) {
                $id = $dt->getId();
                $name = $dt->getShortName();

                if ( isset($metadata[$id]) ) {
                    $datatype_list[$id]['name'] = $name;
                    $datatype_list[$id]['count'] = $metadata[$id]['count'];
                }
            }

//print_r($datatype_list);

            $return['d'] = $datatype_list;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x828474520 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
*/
    }


    /**
     * Creates a new top-level DataType.
     * 
     * @param Request $request
     * 
     * @return Response TODO
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
            $admin = $this->container->get('security.context')->getToken()->getUser();

            // Create new DataType form
            $submitted_data = new DataTypeMeta();
            $form = $this->createForm(new CreateDatatypeForm($submitted_data), $submitted_data);

            // Verify 
            if ($request->getMethod() == 'POST') {
                $form->bind($request, $submitted_data);

                // Can't seem to figure out why it occassionally attempts to create an empty datatype, so...guessing here
                if ($form->isEmpty())
                    $form->addError( new FormError('Form is empty?') );

                $short_name = $submitted_data->getShortName();
                $long_name = $submitted_data->getLongName();
                if ($short_name == '' || $long_name == '')
                    $form->addError( new FormError('New Datatypes require both a short name and a long name') );

//$form->addError( new FormError('do not save') );

                if ($form->isValid()) {
                    // ----------------------------------------
                    // Create a new Datatype entity
                    $datatype = new DataType();
                    $datatype->setRevision(0);
                    $datatype->setHasShortresults(false);
                    $datatype->setHasTextresults(false);

                    $datatype->setCreatedBy($admin);
                    $datatype->setUpdatedBy($admin);

                    // TODO - delete these eleven properties
                    $datatype->setShortName('');
                    $datatype->setLongName('');
                    $datatype->setDescription('');
                    $datatype->setPublicDate(new \DateTime('1980-01-01 00:00:00'));
                    /** @var RenderPlugin $default_render_plugin */
                    $default_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);    // default render plugin
                    $datatype->setRenderPlugin($default_render_plugin);

                    // Set defaults for other stuff...
                    $datatype->setXmlShortName('');
                    $datatype->setUseShortResults(true);
                    $datatype->setExternalIdField(null);
                    $datatype->setNameField(null);
                    $datatype->setSortField(null);
                    $datatype->setDisplayType(0);

                    // Save all changes made
                    $em->persist($datatype);
                    $em->flush();
                    $em->refresh($datatype);


                    // Fill out the rest of the metadata properties for this datatype...don't need to set short/long name
                    $submitted_data->setDataType($datatype);
                    $submitted_data->setRenderPlugin($default_render_plugin);

                    $submitted_data->setDescription('');
                    $submitted_data->setSearchSlug(null);
                    $submitted_data->setXmlShortName('');

                    $submitted_data->setDisplayType(0);
                    $submitted_data->setUseShortResults(true);
                    $submitted_data->setPublicDate( new \DateTime('1980-01-01 00:00:00') );

                    $submitted_data->setExternalIdField(null);
                    $submitted_data->setNameField(null);
                    $submitted_data->setSortField(null);
                    $submitted_data->setBackgroundImageField(null);

                    $submitted_data->setCreatedBy($admin);
                    $em->persist($submitted_data);

                    // ----------------------------------------
                    // Ensure the user that created this datatype has permissions to do everything to it
                    $initial_permissions = array(
                        'can_view_type' => 1,
                        'can_add_record' => 1,
                        'can_edit_record' => 1,
                        'can_delete_record' => 1,
                        'can_design_type' => 1,
                        'is_type_admin' => 1
                    );
                    parent::ODR_addUserPermission($em, $admin->getId(), $admin->getId(), $datatype->getId(), $initial_permissions);


                    // ----------------------------------------
                    // Clear memcached of all datatype permissions for all users...the entries will get rebuilt the next time they do something
                    $memcached = $this->get('memcached');
                    $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
                    $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

                    $user_manager = $this->container->get('fos_user.user_manager');
                    /** @var User[] $users */
                    $users = $user_manager->findUsers();
                    foreach ($users as $user)
                        $memcached->delete($memcached_prefix.'.user_'.$user->getId().'_datatype_permissions');
                }
                else {
                    // Return any errors encountered
                    $return['r'] = 1;
                    $return['d'] = parent::ODR_getErrorMessages($form);
                }
            }
            else {
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
     * Recursively locates and loads all Datatype entities that have the Datatype pointed to by $parent_datatype_id as an ancestor
     *
     * @param \Doctrine\ORM\Entitymanager $em
     * @param array $datatree_array            @see ODRCustomController::getDatatreeArray()
     * @param integer $parent_datatype_id
     *
     * @return array
     */
    private function getAllDatatypes($em, $datatree_array, $parent_datatype_id)
    {
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $datatypes = array();

        $tmp = array_keys($datatree_array['descendant_of'], $parent_datatype_id);
        foreach ($tmp as $num => $child_datatype_id) {
            $datatypes[$child_datatype_id] = array('datatype' => $repo_datatype->find($child_datatype_id), 'children' => array() );

            $datatypes[$child_datatype_id]['children'] = self::getAllDatatypes($em, $datatree_array, $child_datatype_id);
        }

        return $datatypes;
    }


    /**
     * Loads a form used to edit DataType Properties.
     * 
     * @param integer $datatype_id Which datatype is having its properties changed.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function editAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
            $site_baseurl = $this->container->getParameter('site_baseurl');

            // Grab necessary objects
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['design'])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // Ensure this isn't called on a child datatype
            $datatree_array = parent::getDatatreeArray($em);
            if ( isset($datatree_array['descendant_of'][$datatype_id]) && $datatree_array['descendant_of'][$datatype_id] !== '' )
                throw new \Exception('This action is only permitted on top-level datatypes.');

            // Create required form
            $datatype_meta = $datatype->getDataTypeMeta();
            $for_slideout = false;
            $is_top_level = true;   // by definition...this can't be called on a child datatype
            $datatype_form = $this->createForm(new UpdateDataTypeForm($datatype, $for_slideout, $is_top_level), $datatype_meta);


            // ----------------------------------------
            // Determine whether user can view permissions of other users
            $can_view_permissions = false;
            if ($user->hasRole('ROLE_SUPER_ADMIN') || (isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['admin'])) )
                $can_view_permissions = true;

            $all_datatypes = array();
            $all_permissions = array();
            if ($can_view_permissions) {
                // Recursively locate all childtypes of this datatype
                $all_datatypes = array($datatype_id => array('datatype' => $datatype, 'children' => array()));
                $all_datatypes[$datatype_id]['children'] = self::getAllDatatypes($em, $datatree_array, $datatype_id);

                // Easier to look through $datatree_array directly to find all relevant datatype ids...
                $all_datatype_ids = array($datatype_id);
                $parent_datatype_ids = array($datatype_id);
                while (count($parent_datatype_ids) > 0) {

                    $tmp_datatype_ids = array();
                    foreach ($parent_datatype_ids as $num => $dt_id) {
                        $tmp = array_keys($datatree_array['descendant_of'], $dt_id);

                        foreach ($tmp as $child_datatype_id) {
                            $tmp_datatype_ids[] = $child_datatype_id;
                            $all_datatype_ids[] = $child_datatype_id;
                        }
                    }

                    $parent_datatype_ids = $tmp_datatype_ids;
                }

                // Locate all users that can access this datatype
                $query = $em->createQuery(
                   'SELECT DISTINCT(u.id)
                    FROM ODRAdminBundle:UserPermissions AS up
                    JOIN ODROpenRepositoryUserBundle:User AS u WITH up.user = u
                    WHERE up.dataType IN (:datatypes)
                    AND (up.can_view_type = 1 OR up.can_edit_record = 1 OR up.can_add_record = 1 OR up.can_delete_record = 1 OR up.can_design_type = 1 OR up.is_type_admin = 1)'
                )->setParameters(array('datatypes' => $all_datatype_ids));
                $results = $query->getArrayResult();

                // Store all relevant permissions in an array for twig...
                $all_permissions = array();
                foreach ($results as $num => $data) {
                    $user_id = $data[1];

                    if (!isset($all_permissions[$user_id])) {
                        /** @var User $site_user */
                        $site_user = $repo_user->find($user_id);
                        if ($site_user->isEnabled() == 1 && !$site_user->hasRole('ROLE_SUPER_ADMIN'))   // only display this user's permissions for this datatype if they're not deleted and aren't super admin
                            $all_permissions[$user_id] = array('user' => $site_user, 'permissions' => parent::getPermissionsArray($user_id, $request, false));
                    }
                }
                ksort($all_permissions);
            }

            // ----------------------------------------
            // Grab datafields that this page needs
            $query = $em->createQuery(
               'SELECT df AS datafield, dfm.is_unique AS is_unique, ft.typeName AS typename, ft.canBeSortField AS can_be_sortfield
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
                WHERE df.dataType = :datatype
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
            )->setParameters( array('datatype' => $datatype_id) );
            $results = $query->getResult();
//$results = $query->getArrayResult();
//print_r($results);

            $unique_datafields = array();
            $sort_datafields = array();

            $image_datafields = array();
            $textresults_datafields = array();
            foreach ($results as $num => $result) {
                /** @var DataFields $datafield */
                $datafield = $result['datafield'];
                $typename = $result['typename'];
                $is_unique = $result['is_unique'];
                $can_be_sortfield = $result['can_be_sortfield'];

                // Classify various datafields...
                if ($is_unique == '1')
                    $unique_datafields[] = $datafield;
                if ($can_be_sortfield == '1')
                    $sort_datafields[] = $datafield;
                if ($typename == 'Image')
                    $image_datafields[] = $datafield;

                // Build a list of fields which can be displayed in TextResults
                switch ($typename) {
                    case 'DateTime':
                    case 'Integer':
                    case 'Short Text':
                    case 'Medium Text':
                    case 'Long Text':
                    case 'Boolean':
                    case 'Paragraph Text':
                    case 'Single Radio':
                    case 'Single Select':
                    case 'Decimal':
                        $textresults_datafields[] = $datafield;
                        break;

                    case 'File':
                        if ($datafield->getAllowMultipleUploads() == "0")
                            $textresults_datafields[] = $datafield;
                        break;

                    default:
                        break;
                }
            }


            // ----------------------------------------
            // Render the edit page
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:edit_datatype_properties.html.twig',
                    array(
                        'can_view_permissions' => $can_view_permissions,
                        'all_datatypes' => $all_datatypes,
                        'all_permissions' => $all_permissions,

                        'site_baseurl' => $site_baseurl,
                        'datatype' => $datatype,
                        'datatype_form' => $datatype_form->createView(),

                        'unique_datafields' => $unique_datafields,
                        'sort_datafields' => $sort_datafields,
                        'image_datafields' => $image_datafields,
                        'textresults_datafields' => $textresults_datafields,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x7803293: '.$e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a recache of all datarecords of all datatypes.
     * 
     * @param Request $request
     * 
     * @return Response TODO
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
            $user = $this->container->get('security.context')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError("rebuild the cache for");  // TODO - really should say everything

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
//            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

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
                                "memcached_prefix" => $memcached_prefix,    // debug purposes only
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
