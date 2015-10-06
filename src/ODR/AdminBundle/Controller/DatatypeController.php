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
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\UserPermissions;
// Forms
use ODR\AdminBundle\Form\DatatypeForm;
// Symfony
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
     * @return a Symfony JSON response containing HTML TODO 
     */
    public function listAction($section, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $templating = $this->get('templating');

            // --------------------
            // Grab user privileges to determine what they can do
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            // --------------------


            // Grab a list of top top-level datatypes
            $top_level_datatypes = parent::getTopLevelDatatypes();
//print_r($top_level_datatypes);

            // Grab each top-level datatype from the repository
            $query = $em->createQuery(
               'SELECT dt AS datatype
                FROM ODRAdminBundle:DataType dt
                WHERE dt IN (:datatypes)'
            )->setParameters( array('datatypes' => $top_level_datatypes) );
            $results = $query->getResult();

            $datatypes = array();
            $metadata = array();
            foreach ($results as $result) {
                $datatype = $result['datatype'];
                $datatype_id = $datatype->getId();

                $datatypes[] = $datatype;
                $metadata[$datatype_id] = array();
                $metadata[$datatype_id]['count'] = 0;
            }

            // Do a second query to grab which datatypes have datarecords, and how many
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id, COUNT(dr.id) AS datarecord_count
                FROM ODRAdminBundle:DataType dt
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

            // Create new DataType form
            $datatype = new DataType();
            $form = $this->createForm(new DatatypeForm($datatype), $datatype);

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
     * @return TODO
     */
    public function getlistAction(Request $request)
    {
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
            // TOP LEVEL DATATYPES
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

    }


    /**
     * Creates a new top-level DataType.
     * 
     * @param Request $result
     * 
     * @return TODO
     */
    public function addAction(Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
//            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $templating = $this->get('templating');

            // Don't need to verify permissions, firewall won't let this action be called unless user is admin
            $admin = $this->container->get('security.context')->getToken()->getUser();

            // Create new DataType form
            $datatype = new DataType();
            $form = $this->createForm(new DatatypeForm($datatype), $datatype);

            // Verify 
            if ($request->getMethod() == 'POST') {
                $form->bind($request, $datatype);
                if ($form->isValid()) {
                    // ----------------------------------------
                    // Set stuff that the form doesn't take care of
                    $datatype->setMultipleRecordsPerParent(1);
                    $datatype->setPublicDate(new \DateTime('1980-01-01 00:00:00'));
                    $datatype->setCreatedBy($admin);
                    $datatype->setUpdatedBy($admin);

                    $render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);    // default render plugin
                    $datatype->setRenderPlugin($render_plugin);

                    // Set defaults for other stuff...
                    $datatype->setUseShortResults(1);
                    $datatype->setExternalIdField(null);
                    $datatype->setNameField(null);
                    $datatype->setSortField(null);
                    $datatype->setDisplayType(0);
                    $datatype->setRevision(0);
                    $datatype->setHasShortResults(false);
                    $datatype->setHasTextResults(false);

                    // Save all changes made
                    $em->persist($datatype);
                    $em->flush();

                    // ----------------------------------------
                    // Ensure the user that created this datatype has permissions to do everything to it
                    $user_permission = new UserPermissions();
                    $user_permission->setDataType($datatype);
                    $user_permission->setUserId($admin);
                    $user_permission->setCreatedBy($admin);

                    $user_permission->setCanEditRecord(1);
                    $user_permission->setCanAddRecord(1);
                    $user_permission->setCanDeleteRecord(1);
                    $user_permission->setCanViewType(1);
                    $user_permission->setCanDesignType(1);
                    $user_permission->setIsTypeAdmin(1);

                    $em->persist($user_permission);
                    $em->flush();


                    // ----------------------------------------
                    // Clear memcached of all datatype permissions for all users...the entries will get rebuilt the next time they do something
                    $memcached = $this->get('memcached');
                    $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
                    $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

                    $user_manager = $this->container->get('fos_user.user_manager');
                    $users = $user_manager->findUsers();
                    foreach ($users as $user)
                        $memcached->delete($memcached_prefix.'user_'.$user->getId().'_datatype_permissions');

                }
                else {
                    // Return any errors encountered
                    $return['r'] = 1;
                    $return['d'] = $form->getErrorsAsString();
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
     * Loads a form used to edit DataType Properties.
     * 
     * @param integer $datatype_id Which datatype is having its properties changed.
     * @param Request $result
     * 
     * @return a Symfony JSON reponse containing HTML
     */
    public function editAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
//            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $site_baseurl = $this->container->getParameter('site_baseurl');

            // Grab necessary objects
            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab datafields that this page needs
            $query = $em->createQuery(
               'SELECT df AS datafield, df.is_unique AS is_unique, ft.typeName AS typename, ft.canBeSortField AS can_be_sortfield
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:FieldType AS ft WITH df.fieldType = ft
                WHERE df.dataType = :datatype
                AND df.deletedAt IS NULL AND ft.deletedAt IS NULL'
            )->setParameters( array('datatype' => $datatype_id) );
            $results = $query->getResult();
//$results = $query->getArrayResult();
//print_r($results);

            $unique_datafields = array();
            $sort_datafields = array();

            $image_datafields = array();
            $textresults_datafields = array();
            foreach ($results as $num => $result) {
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
//                        if ($datafield['allow_multiple_uploads'] == "0")
                            $textresults_datafields[] = $datafield;
                        break;

                    default:
                        break;
                }
            }

            // Render the edit page
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:edit_datatype_properties.html.twig',
                    array(
                        'site_baseurl' => $site_baseurl,
                        'datatype' => $datatype,

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
     * Saves changes made to the DataType Properties form.
     * TODO - change this to use a Symfony Form object...
     * 
     * @param Request $result
     * 
     * @return an empty Symfony JSON response, unless an error occurs
     */
    public function saveAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $post = $_POST;
//print_r($post);   exit();

            $datatype_id = $post['datatype_id'];
            $shortdisplay = $post['shortdisplay'];
            $old_search_slug = $post['old_search_slug'];
            $search_slug = $post['search_slug'];

            $external_datafield_id = $post['external_id_datafield'];
            $name_datafield_id = $post['name_datafield'];
            $sort_datafield_id = $post['sort_datafield'];
            $image_datafield_id = $post['image_datafield'];

            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'design' ])) ) 
                return parent::permissionDeniedError("edit");
            // --------------------


            $need_recache = false;

            // ----------------------------------------
            // Deal with external id datafield...
            if ($external_datafield_id == '-1')
                $external_datafield_id = null;

            $old_external_datafield_id = null;
            if ($datatype->getExternalIdField() !== null)
                $old_external_datafield_id = strval( $datatype->getExternalIdField()->getId() );

            if ($old_external_datafield_id !== $external_datafield_id) {
                $need_recache = true;

                if ($external_datafield_id == null)
                    $datatype->setExternalIdField(null);
                else
                    $datatype->setExternalIdField( $repo_datafield->find($external_datafield_id) );

                // Not caching this value currently?
//                $query = $em->createQuery('UPDATE ODRAdminBundle:DataRecord AS dr SET dr.external_id = NULL WHERE dr.dataType = :datatype')->setParameters( array('datatype' => $datatype_id) );
//                $num_updated = $query->execute();
            }

            // ----------------------------------------
            // Deal with name datafield...
            if ($name_datafield_id == '-1')
                $name_datafield_id = null;

            $old_name_datafield_id = null;
            if ($datatype->getNameField() !== null) 
                $old_name_datafield_id = strval( $datatype->getNameField()->getId() );

            if ($old_name_datafield_id !== $name_datafield_id) {
                $need_recache = true;

                if ($name_datafield_id == null)
                    $datatype->setNameField(null);
                else
                    $datatype->setNameField( $repo_datafield->find($name_datafield_id) );

                // Not caching this value currently?
//                $query = $em->createQuery('UPDATE ODRAdminBundle:DataRecord AS dr SET dr.namefield_value = NULL WHERE dr.dataType = :datatype')->setParameters( array('datatype' => $datatype_id) );
//                $num_updated = $query->execute();
            }

            // ----------------------------------------
            // Deal with sort datafield...
            if ($sort_datafield_id == '-1')
                $sort_datafield_id = null;

            $old_sort_datafield_id = null;
            if ($datatype->getSortField() !== null) 
                $old_sort_datafield_id = strval( $datatype->getSortField()->getId() );

            if ($old_sort_datafield_id !== $sort_datafield_id) {
                $need_recache = true;
                $memcached->delete($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');

                if ($sort_datafield_id == null)
                    $datatype->setSortField(null);
                else
                    $datatype->setSortField( $repo_datafield->find($sort_datafield_id) );

                // Not caching this value currently?
//                $query = $em->createQuery('UPDATE ODRAdminBundle:DataRecord AS dr SET dr.sortfield_value = NULL WHERE dr.dataType = :datatype')->setParameters( array('datatype' => $datatype_id) );
//                $num_updated = $query->execute();
            }

            // ----------------------------------------
            // Deal with background image datafield...
            $image_datafield = null;
            if ($image_datafield_id !== '-1')
                $image_datafield = $repo_datafield->find($image_datafield_id);
            $datatype->setBackgroundImageField($image_datafield);


            // ----------------------------------------
            // Ensure the search slug provided doesn't match one already in the database
            if ($old_search_slug !== $search_slug) {
                $query = $em->createQuery(
                   'SELECT dt.id, dt.searchSlug AS dt_search_slug
                    FROM ODRAdminBundle:DataType AS dt
                    WHERE dt.searchSlug != :empty
                    AND dt.deletedAt IS NULL'
                )->setParameters( array( 'empty' => '') );
                $results = $query->getArrayResult();
//print_r($results);

                $unique = true;
                foreach ($results as $num => $result) {
                    $dt_search_slug = $result['dt_search_slug'];

                    if ($search_slug == $dt_search_slug) {
                        $unique = false;
                        break;
                    }
                }

                if ($unique) {
                    // If no other datatype has this search slug, save it
                    $datatype->setSearchSlug($search_slug);
                }
                else {
                    // Otherwise, notify the page that it needs to alert the user to this error
                    // Don't stop the rest of the page from saving, though...
                    $return['r'] = 2;
                }
            }

            // Save changes
            $datatype->setUseShortResults($shortdisplay);
            $em->persist($datatype);
            $em->flush();

            // Schedule the cache for an update if needed
            if ($need_recache) {
                parent::updateDatatypeCache($datatype->getId());
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x7036729: '.$e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


   /**
     * Triggers a recache of all datarecords of all datatypes.
     * 
     * @param Request $result
     * 
     * @return an empty Symfony JSON response, unless an error occurs
     */
    public function recacheallAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Permissions check
            $user = $this->container->get('security.context')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError("rebuild the cache for");  // TODO - really should say everything

            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
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
                $datatype = $repo_datatype->find($datatype_id);

                // Increment the datatype revision number so the worker processes will recache the datarecords
                $revision = $datatype->getRevision();
                $datatype->setRevision( $revision + 1 );
                $em->persist($datatype);
                $em->flush();

                // Grab all non-deleted datarecords of each datatype
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord dr
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
