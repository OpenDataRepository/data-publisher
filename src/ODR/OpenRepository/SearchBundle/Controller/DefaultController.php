<?php

/**
* Open Data Repository Data Publisher
* Search Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The search controller handles rendering of search pages and
* the actual process of searching.
*
* The rendering of the results from searching is handled by
* ODRCustomController.
*/

namespace ODR\OpenRepository\SearchBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use ODR\AdminBundle\Controller\ODRCustomController;

// Entites
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\Boolean;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;

class DefaultController extends Controller
{

    /**
     *  Returns a formatted 404 error from the search page.
     * TODO - this doesn't work like it should?
     * 
     * @param string $error_message
     * @param string $status_code
     * 
     * @return Response TODO
     */
    private function searchPageError($error_message, $status_code)
    {

        // Grab user and their permissions if possible
        $user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

        // Store if logged in or not
        $logged_in = true;
        if ($user === 'anon.') {
            $user = null;
            $logged_in = false;
        }

        $html = $this->renderView(
            'ODROpenRepositorySearchBundle:Default:searchpage_error.html.twig',
            array(
                // required twig/javascript parameters
                'user' => $user,
                'logged_in' => $logged_in,
                'error_message' => $error_message,
            )
        );

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');
        $response->setStatusCode($status_code);
        return $response;

    }


    /**
     * Returns a list of all datatypes which are either children or linked to an optional target datatype, minus the ones a user doesn't have permissions to see
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $target_datatype_id      If set, which top-level datatype to save child/linked datatypes for
     * @param array $user_permissions          If set, the current user's permissions array
     *
     * @return array TODO 
     */
    private function getRelatedDatatypes($em, $target_datatype_id = null, $user_permissions = array())
    {
        // Grab all entities out of the 
        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id, descendant.publicDate AS public_date, dt.is_link AS is_link
            FROM ODRAdminBundle:DataTree AS dt
            JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE dt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL');
        $results = $query->getArrayResult();

        $descendant_of = array();
        $links = array();
        foreach ($results as $num => $result) {
            $ancestor_id = $result['ancestor_id'];
            $descendant_id = $result['descendant_id'];
            $public_date = $result['public_date'];
            $is_link = $result['is_link'];

            // TODO - public datatype
            $is_public = true;
            if ( $public_date->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' )
                $is_public = false;

            if ($is_link == 0) {
                // Save childtypes encountered
                if ( !isset($descendant_of[$ancestor_id]) )
                    $descendant_of[$ancestor_id] = array();

                // Only save this datatype if the user is allowed to view it
                if ( $is_public || (isset($user_permissions[$descendant_id]) && isset($user_permissions[$descendant_id]['view'])) )
                    $descendant_of[$ancestor_id][] = $descendant_id;
            }
            else {
                // Save datatype links encountered
                if ( !isset($links[$ancestor_id]) )
                    $links[$ancestor_id] = array();

                // Only save this datatype if the user is allowed to view it
                if ( $is_public || (isset($user_permissions[$descendant_id]) && isset($user_permissions[$descendant_id]['view'])) )
                    $links[$ancestor_id][] = $descendant_id;
            }
        }

/*
print '$target_datatype_id: '.$target_datatype_id."\n";
//print '$descendant_of: '.print_r($descendant_of, true)."\n";
print '$links: '.print_r($links, true)."\n";
*/

        $descendants = array();
        if ($target_datatype_id == null) {
            $descendants = $descendant_of;
        }
        else {
            // Only want ids of datatypes that are descendants of the given datatype
            $list = array($target_datatype_id);

            while ( count($list) > 0 ) {
                // Grab the id of the first datatype to process
                $datatype_id = array_shift($list);

                // Save any descendants of this datatype
                if ( isset($descendant_of[$datatype_id]) )
                    $descendants[$datatype_id] = $descendant_of[$datatype_id];
                else
                    $descendants[$datatype_id] = '';

                // If there are descendants of this datatype, queue them for processing
                $tmp = array();
                if ( isset($descendant_of[$datatype_id]) ) {
                    $tmp = $descendant_of[$datatype_id];

                    foreach ($tmp as $num => $id)
                        $list[] = $id;
                }
            }

            // TODO - links in childtypes? 
            // Only save datatypes that are linked to the target datatype
            foreach ($links as $ancestor_id => $tmp) {
                if ($ancestor_id != $target_datatype_id)
                    unset($links[$ancestor_id]);
            }
        }

        $linked_datatypes = array();
        if ( isset($links[$target_datatype_id]) )
            $linked_datatypes = $links[$target_datatype_id];

        $datatype_tree = array(
            'target_datatype' => $target_datatype_id,
            'child_datatypes' => $descendants,
            'linked_datatypes' => $linked_datatypes,
        );
/*
print '<pre>';
print_r($datatype_tree);
print '</pre>';
exit();
*/

        return $datatype_tree;
    }


    /**
     * Given a list of datatypes, returns an array of all datafields the requesting user is allowed to search
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $related_datatypes         An array returned by @see self::getRelatedDatatypes()
     * @param array $user_permissions          If set, the current user's permission array
     *
     * @return array an array of ODR\AdminBundle\Entity\DataFields objects, grouped by their Datatype id
     */
    private function getSearchableDatafields($em, $related_datatypes, $user_permissions = array())
    {
        // Just want a comma separated list of related datatypes...
        $datatypes = array();
        foreach ($related_datatypes['child_datatypes'] as $datatype_id => $tmp)
            $datatypes[] = $datatype_id;
        foreach ($related_datatypes['linked_datatypes'] as $num => $linked_datatype_id)
            $datatypes[] = $linked_datatype_id;

//print_r($datatypes);

        // Build a query to get all datafields of these datatypes
        $query = $em->createQuery(
           'SELECT dt.id AS dt_id, df AS datafield
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
            WHERE df.deletedAt IS NULL AND dt.deletedAt IS NULL
            AND df.dataType IN (:datatypes) AND df.searchable > 0'
        )->setParameters( array('datatypes' => $datatypes) );
        $results = $query->getResult();

        // Group the datafields by datatype id
        $searchable_datafields = array();
        foreach ($results as $num => $result) {
            $datatype_id = $result['dt_id'];
            $datafield = $result['datafield'];
            $user_only_search = $datafield->getUserOnlySearch();

            // Only save datafields the user has permissions to view
            // TODO - actual datafield permissions
            if ( $user_only_search == 0 || ( isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['view']) ) ) {
                if ( !isset($searchable_datafields[$datatype_id]) )
                    $searchable_datafields[$datatype_id] = array();

                $searchable_datafields[$datatype_id][] = $datafield;
            }
        }

        return $searchable_datafields;
    }


    /**
     * Renders the base page for searching purposes
     * 
     * @param String $search_slug   Which datatype to load a search page for.
     * @param String $search_string An optional string to immediately enter into the general search field and search with.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function searchpageAction($search_slug, $search_string, Request $request)
    {

        $html = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');

            $odrcc = $this->get('odr_custom_controller', $request);
            $odrcc->setContainer($this->container);

            $cookies = $request->cookies;

            // Locate the datatype referenced by the search slug, if possible...
            $target_datatype = null;
            if ($search_slug == '') {

                if ( $cookies->has('prev_searched_datatype') ) {
                    $search_slug = $cookies->get('prev_searched_datatype');
                    return $this->redirect( $this->generateURL('odr_search', array( 'search_slug' => $search_slug ) ));
                }
                else {
//                    return new Response("Page not found", 404);
                    return self::searchPageError("Page not found", 404);
                }
            }
            else {
                $target_datatype = $repo_datatype->findOneBy( array('searchSlug' => $search_slug) );
                if ($target_datatype == null)
                    return self::searchPageError("Page not found", 404);
            }


            // ------------------------------
            // Grab user and their permissions if possible
            $admin_user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();

            // Store if logged in or not
            $logged_in = true;
            if ($admin_user === 'anon.') {
                $admin_user = null;
                $logged_in = false;
            }
            else {
                // Grab user permissions
                $user_permissions = $odrcc->getPermissionsArray($admin_user->getId(), $request);
            }
            // ------------------------------

            // Check if user has permission to view datatype
            $target_datatype_id = $target_datatype->getId();
            if ( !$target_datatype->isPublic() && !(isset($user_permissions[ $target_datatype_id ]) && isset($user_permissions[ $target_datatype_id ][ 'view' ])) )
//                return $odrcc->permissionDeniedError('search');
                return self::searchPageError("You don't have permission to access this DataType.", 403);

            // Need to grab all searchable datafields for the target_datatype and its descendants

$debug = true;
$debug = false;

            // ----------------------------------------
            // Grab ids of all datatypes related to the requested datatype that the user can view
            $related_datatypes = self::getRelatedDatatypes($em, $target_datatype_id, $user_permissions);

if ($debug) {
    print '<pre>';
    print "\n\n\n";
//    print '$user_permissions: '.print_r($user_permissions, true)."\n";
    print '$related_datatypes: '.print_r($related_datatypes, true)."\n";
}
            // Grab all searchable datafields 
            $searchable_datafields = self::getSearchableDatafields($em, $related_datatypes, $user_permissions);

if ($debug) {
    $print = array();
    foreach ($searchable_datafields as $dt_id => $tmp) {
        $print[$dt_id] = array();
        foreach ($tmp as $num => $df)
            $print[$dt_id][] = $df->getId();
    }

    print '$searchable_datafields: '.print_r($print, true)."\n";
    print '</pre>';
//exit();
}


            // ----------------------------------------
            // Grab a random background image
            $background_image_id = null;
            if ( $target_datatype !== null && $target_datatype->getBackgroundImageField() !== null ) {
                $query_str =
                   'SELECT image.id
                    FROM ODRAdminBundle:Image AS image
                    WHERE image.original = 1 AND image.deletedAt IS NULL 
                    AND image.dataField = :datafield';
                $parameters = array('datafield' => $target_datatype->getBackgroundImageField());

                // Should logged-in users be able to view non-public images on this search page?  currently defaulting to no
//                if (!$logged_in) {
                    $query_str .= ' AND image.publicDate NOT LIKE :date';
                    $parameters['date'] = "2200-01-01 00:00:00";
//                }

                $query = $em->createQuery($query_str)->setParameters($parameters);
                $results = $query->getArrayResult();

                // Pick a random image from the list of available images
                if ( count($results) > 0 ) {
                    $index = rand(0, count($results)-1);
                    $background_image_id = $results[$index]['id'];
                }
            }


            // ----------------------------------------
            // Grab users to populate the created/modified by boxes with
            $user_manager = $this->container->get('fos_user.user_manager');
            $user_list = $user_manager->findUsers();

            // Determine if the user has the permissions required to see anybody in the created/modified by search fields
            $admin_permissions = array();
            foreach ($user_permissions as $datatype_id => $up) {
                if ( (isset($up['edit']) && $up['edit'] == 1) || (isset($up['delete']) && $up['delete'] == 1) || (isset($up['add']) && $up['add'] == 1) || (isset($up['admin']) && $up['admin'] == 1) ) {
                    $admin_permissions[ $datatype_id ] = $up;
                }
            }

            if ( $admin_user == null || count($admin_permissions) == 0 ) {
                // Not logged in, or has none of the required permissions
                $user_list = array();
            }
            else if ( $admin_user->hasRole('ROLE_SUPER_ADMIN') ) {
                /* do nothing, which lets the user see everybody */
            }
            else {
                // Get a list of datatypes the user is allowed to access
                $datatype_list = array();
                foreach ($admin_permissions as $dt_id => $tmp)
                    $datatype_list[] = $dt_id;

                // Get all other users which can view that list of datatypes
                $query = $em->createQuery(
                   'SELECT u AS user
                    FROM ODROpenRepositoryUserBundle:User AS u
                    JOIN ODRAdminBundle:UserPermissions AS up WITH up.user_id = u
                    WHERE up.dataType IN (:datatypes) AND up.can_view_type = 1
                    GROUP BY u.id'
                )->setParameters( array('datatypes' => $datatype_list) );
                $results = $query->getResult();

                // Convert them into a list of users that the admin user is allowed to search by
                $user_list = array();
                foreach ($results as $num => $result)
                    $user_list[] = $result['user'];
            }

            // Generate a random key to identify this tab
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');
            $odr_tab_id = substr($tokenGenerator->generateToken(), 0, 15);


            // ----------------------------------------
            // Render just the html for the base page and the search page...$this->render() apparently creates a full Response object
            $site_baseurl = $this->container->getParameter('site_baseurl');
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $site_baseurl .= '/app_dev.php';

            $html = $this->renderView(
                'ODROpenRepositorySearchBundle:Default:index.html.twig',
                array(
                    // required twig/javascript parameters
                    'user' => $admin_user,
                    'user_permissions' => $user_permissions,

                    'user_list' => $user_list,
                    'logged_in' => $logged_in,
                    'window_title' => $target_datatype->getShortName(),
                    'source' => 'searching',
                    'search_slug' => $search_slug,
                    'site_baseurl' => $site_baseurl,
                    'search_string' => $search_string,
                    'odr_tab_id' => $odr_tab_id,

                    // required for background image
                    'background_image_id' => $background_image_id,

                    // datatype/datafields to search
                    'target_datatype' => $target_datatype,
                    'related_datatypes' => $related_datatypes,
                    'searchable_datafields' => $searchable_datafields,
                )
            );

            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            $session = $request->getSession();
            $session->set('scroll_target', '');

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x81286282 ' . $e->getMessage();
        }

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');
        $response->headers->setCookie(new Cookie('prev_searched_datatype', $search_slug));
        return $response;
    }


    /**
     * Renders a version of the search page currently used for linking datarecords.
     * TODO - move this somewhere?  reorganize so that this is the action that renders search page?
     * 
     * @param integer $target_datatype_id The database id of the DataType marked for searching...
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function searchboxAction($target_datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Need to only return top-level datatypes
            $em = $this->getDoctrine()->getManager();
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
//            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');

            $odrcc = $this->get('odr_custom_controller', $request);
            $odrcc->setContainer($this->container);

            // Need to grab all searchable datafields for the target_datatype and its descendants
            $target_datatype = $repo_datatype->find($target_datatype_id);
            if ($target_datatype == null)
                return $odrcc->deletedEntityError('Datatype');

            // ------------------------------
            // Grab user and their permissions if possible
            $admin_user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $odrcc->getPermissionsArray($admin_user->getId(), $request);
            $logged_in = true;

            // ----------------------------------------
            // Grab ids of all datatypes related to the requested datatype that the user can view
            $related_datatypes = self::getRelatedDatatypes($em, $target_datatype_id, $user_permissions);
            // Grab all searchable datafields 
            $searchable_datafields = self::getSearchableDatafields($em, $related_datatypes, $user_permissions);


            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            $user_list = $user_manager->findUsers();

            // Determine if the user has the permissions required to see anybody in the created/modified by search fields
            $admin_permissions = array();
            foreach ($user_permissions as $datatype_id => $up) {
                if ( (isset($up['edit']) && $up['edit'] == 1) || (isset($up['delete']) && $up['delete'] == 1) || (isset($up['add']) && $up['add'] == 1) || (isset($up['admin']) && $up['admin'] == 1) ) {
                    $admin_permissions[ $datatype_id ] = $up;
                }
            }


            if ( $admin_user == null || count($admin_permissions) == 0 ) {
                // Not logged in, or has none of the required permissions
                $user_list = array();
            }
            else if ( $admin_user->hasRole('ROLE_SUPER_ADMIN') ) {
                /* do nothing, which will let the user see everybody */
            }
            else {
                // Get a list of datatypes the user is allowed to access
                $datatype_list = array();
                foreach ($admin_permissions as $dt_id => $tmp)
                    $datatype_list[] = $dt_id;

                // Get all other users which can view that list of datatypes
                $query = $em->createQuery(
                   'SELECT u AS user
                    FROM ODROpenRepositoryUserBundle:User AS u
                    JOIN ODRAdminBundle:UserPermissions AS up WITH up.user_id = u
                    WHERE up.dataType IN (:datatypes) AND up.can_view_type = 1
                    GROUP BY u.id'
                )->setParameters( array('datatypes' => $datatype_list) );
                $results = $query->getResult();

                // Convert them into a list of users that the admin user is allowed to search by
                $user_list = array();
                foreach ($results as $num => $result)
                    $user_list[] = $result['user'];
            }


            // Render the template
            $site_baseurl = $this->container->getParameter('site_baseurl');
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                  'ODROpenRepositorySearchBundle:Default:search.html.twig',
                    array(
                        // required twig/javascript parameters
//                        'user' => $admin_user,
//                        'user_permissions' => $user_permissions,

                        'user_list' => $user_list,
                        'logged_in' => $logged_in,
//                        'window_title' => $target_datatype->getShortName(),
                        'source' => 'linking',
//                        'search_slug' => $search_slug,
                        'site_baseurl' => $site_baseurl,
//                        'search_string' => $search_string,

                        // required for background image
                        'background_image_id' => null,

                        // datatype/datafields to search
                        'target_datatype' => $target_datatype,
                        'related_datatypes' => $related_datatypes,
                        'searchable_datafields' => $searchable_datafields,
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
            $return['d'] = 'Error 0x18742232 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders a Short/Textresults list of all datarecords stored in a given memcached key
     * 
     * @param string $search_key The terms the user is searching for
     * @param integer $offset    Which page of the search results to render
     * @param string $source     "searching" if searching from frontpage, or "linking" if searching for datarecords to link
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function renderAction($search_key, $offset, $source, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab default objects
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $theme = $repo_theme->find(2);  // TODO - theme

            $templating = $this->get('templating');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();

            // Get ODRCustomController from the AdminBundle...going to need functions from it
            $odrcc = $this->get('odr_custom_controller', $request);
            $odrcc->setContainer($this->container);

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = array();
            $logged_in = false;
            if ($user !== 'anon.') {
                $user_permissions = $odrcc->getPermissionsArray($user->getId(), $request);
                $logged_in = true;
            }

            // TODO - ???
            // --------------------


            // -----------------------------------
            // Attempt to load the search results (for this user and/or search string?) from memcached
            // Extract the datatype id from the search string
            $datatype_id = '';
            $tokens = preg_split("/\|(?![\|\s])/", $search_key);
            foreach ($tokens as $num => $token) {
                $pieces = explode('=', $token);
                if ($pieces[0] == 'dt_id') {
                    $datatype_id = $pieces[1];
                    break;
                }
            }

            if ($datatype_id == '')
                throw new \Exception('Invalid search string');

            $search_params = $odrcc->getSavedSearch($datatype_id, $search_key, $logged_in, $request);

            // Now that the search is guaranteed to exist and be correct...get all pieces of info about the search
            $datatype = $repo_datatype->find( $search_params['datatype_id'] );
            $datarecord_list = $search_params['datarecord_list'];
            $encoded_search_key = $search_params['encoded_search_key'];

            // Turn the search results string into an array of datarecord ids
            $datarecords = array();
            if ( trim($datarecord_list) !== '')
                $datarecords = explode(',', trim($datarecord_list));


            // -----------------------------------
            // Bypass list entirely if only one datarecord
            if ( count($datarecords) == 1 && $source !== 'linking' ) {
                $datarecord_id = $datarecords[0];

                // Can't use $this->redirect, because it won't update the hash...
                $return['r'] = 2;
//                if ($target == 'results')
                    $return['d'] = array( 'url' => $this->generateURL('odr_results_view', array('datarecord_id' => $datarecord_id)) );
//                else if ($target == 'record')
//                    $return['d'] = array( 'url' => $this->generateURL('odr_record_edit', array('datarecord_id' => $datarecord_id)) );

                $response = new Response(json_encode($return));
                $response->headers->set('Content-Type', 'application/json');
                return $response;
            }


            // -----------------------------------
            // TODO - THIS IS A TEMP FIX
            if ($source == 'linking' || $datatype->getUseShortResults() == 0)
                $theme = $repo_theme->find(4);  // textresults
            else
                $theme = $repo_theme->find(2);  // shortresults


            // -----------------------------------
            // Render and return the page
            $path_str = $this->generateUrl('odr_search_render', array('search_key' => $encoded_search_key) );   // this will double-encode the search key, mostly
            $path_str = urldecode($path_str);   // decode it to get single-encoded search key

            $target = 'results';
            if ($source == 'linking')
                $target = $source;
            $html = $odrcc->renderList($datarecords, $datatype, $theme, $user, $path_str, $target, $encoded_search_key, $offset, $request);

             $return['d'] = array(
                'html' => $html,
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x14168352 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called when the user performs a search from the search page.
     * 
     * @param string $search_key The terms the user wants to search on
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function searchAction($search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            self::performSearch($search_key, $request);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x19846813 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Searches a DataType for all DataRecords matching the user-defined search criteria, then stores the result in memcached using the given search_key
     * 
     * @param string $search_key The terms the user wants to search on
     * @param Request $request
     *
     */
    public function performSearch($search_key, Request $request)
    {
        // Grab default objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
        $session = $request->getSession();

        // Get the controller from the AdminBundle for utility methods
        $odrcc = $this->get('odr_custom_controller', $request);
        $odrcc->setContainer($this->container);


        // --------------------------------------------------
        // Attempt to load the search results (for this user and/or search string?) from the cache
        $datarecords = array();
/*
        $cached_datarecord_str = null;
        if ( $session->has('saved_searches') ) {
            $saved_searches = $session->get('saved_searches');

            $search_checksum = md5($search_key);
            if ( isset($saved_searches[$search_checksum]) ) {
                $search_params = $saved_searches[$search_checksum];
                $was_logged_in = $search_params['logged_in'];

                // Only consider search results as valid if the user has not logged in/out since they were made
                if ($logged_in == $was_logged_in)
                    $cached_datarecord_str = $search_params['datarecords'];
            }
        }

        // No caching in dev environment
        if ($this->container->getParameter('kernel.environment') === 'dev')
*/
            $cached_datarecord_str = null;

$debug = true;
$debug = false;
if ($debug) {
    print 'cached datarecord str: '.$cached_datarecord_str."\n";
}

        // --------------------------------------------------
        // If there's something in the cache, use that
        if ($cached_datarecord_str != null) {
            $datarecords = $cached_datarecord_str;
        }
        else {
            // Turn the URL $search_string into a $_POST array
            $encoded_search_key = '';
            $post = array();
            $post['datafields'] = array();

            // Split the entire search key on pipes that are not followed by another pipe or space
            $get = preg_split("/\|(?![\|\s])/", $search_key);

            foreach ($get as $key => $value) {
                // Split each search value into "(datafield id or other identifier)=(search term)"
                $pattern = '/([0-9a-z_]+)\=(.+)/';
                $matches = array();
                preg_match($pattern, $value, $matches);

                $key = $matches[1];
                $value = $matches[2];

                // Determine whether this field is a radio fieldtype
                $is_radio = false;
                if ( is_numeric($key) ) {
                    $datafield = $repo_datafield->find($key);
                    if ($datafield !== null) {
                        if ( $datafield->getFieldType()->getTypeClass() == "Radio" ) {
                            $is_radio = true;
                        }
                    }
                }

                if ( $is_radio ) {
                    // Multiple selected checkbox/radio items...
                    $values = explode(',', $value);
                    $post['datafields'][$key] = $values;

                    $encoded_search_key .= $key.'='.$value.'|';
                }
                else if ( strpos($key, '_s') !== false || strpos($key, '_e') !== false || strpos($key, '_by') !== false ) {
                    // Fields involving dates, or metadata
                    $keys = explode('_', $key);

                    if ( !is_numeric($keys[0]) ) {
                        // Create/Modify fields
                        $dt_id = $keys[1];
                        $type = $keys[2];       // 'm' or 'c' (modify or create)
                        if ($type == 'm')
                            $type = 'updated';
                        else
                            $type = 'created';

                        $position = $keys[3];   // 's' or 'e' (start or end)
                        if ($position == 's')
                            $position = 'start';
                        else if ($position == 'e')
                            $position = 'end';

                        $post['metadata'][$dt_id][$type][$position] = $value;
                    }
                    else {
                        // Regular DateTime fields
                        $df_id = $keys[0];
                        $pos = $keys[1];

                        if ( !isset($post['datafields'][$df_id]) )
                            $post['datafields'][$df_id] = array();

                        $post['datafields'][$df_id][$pos] = $value;
                    }

                    $encoded_search_key .= $key.'='.$value.'|';
                }
                else if ( strpos($key, '_pub') !== false ) {
                    // public/non-public status
                    $keys = explode('_', $key);
                    $dt_id = $keys[1];

                    $post['metadata'][$dt_id]['public'] = $value;

                    $encoded_search_key .= $key.'='.$value.'|';
                }
                else {
                    // Should cover all other fields...
                    if ( is_numeric($key) ) {
                        $post['datafields'][$key] = $value;
                        $encoded_search_key .= $key.'='.self::encodeURIComponent($value).'|';
                    }
                    else {
                        $post[$key] = $value;
                        $encoded_search_key .= $key.'='.self::encodeURIComponent($value).'|';
                    }
                }
            }

            $encoded_search_key = substr($encoded_search_key, 0, -1);
if ($debug) {
//$params = $request->query->all();
//print '$_GET string: '.print_r($params, true)."\n\n";

    print 'search_key: '.$search_key."\n";
    print 'md5('.$search_key.'): '.md5($search_key)."\n";
    print 'encoded_search_key: '.$encoded_search_key."\n";
    print 'md5('.$encoded_search_key.'): '.md5($encoded_search_key)."\n";
    print 'post: '.print_r($post, true)."\n";
}
//return;

            //
            $searched_datafields = array();
            foreach ($post['datafields'] as $df_id => $df_value)
                $searched_datafields[] = $df_id;
            $searched_datafields = implode(',', $searched_datafields);

            $target_datatype_id = $post['dt_id'];
            $datatype = $repo_datatype->find($target_datatype_id);
            if ($datatype == null)
                return self::searchPageError("Page not found", 404);

            $session->set('prev_searched_datatype_id', $target_datatype_id);

            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];
            $general_string = null;
            if ( isset($post['gen']) )
                $general_string = trim($post['gen']);

            // Get rid of empty/blank search strings for datafields
//            if ($datafields !== null) {
                foreach ($datafields as $id => $str) {
                    if ( !is_array($str) && trim($str) === '' )
                        unset( $datafields[$id] );
                }

//                if ( count($datafields) == 0 )
//                    $datafields = null;
//            }


            // --------------------------------------------------
            // Determine level of user's permissions...
            $user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();
            $logged_in = false;

            if ($user !== null && $user !== 'anon.') {
                $logged_in = true;

                // Grab user's permissions
                $user_permissions = $odrcc->getPermissionsArray($user->getId(), $request);
            }

if ($debug) {
//    print 'logged_in: '.$logged_in."\n";
//    print 'has_view_permission: '.$has_view_permission."\n";
//    print '$user_permissions: '.print_r($user_permissions, true)."\n";
}
            $using_adv_search = false;
            $basic_search_datarecords = array();
            $adv_search_datarecords = array();


            // --------------------------------------------------
            // Grab all datatypes related to the one being searched
            $related_datatypes = self::getRelatedDatatypes($em, $target_datatype_id, $user_permissions);

if ($debug)
    print '$related_datatypes: '.print_r($related_datatypes, true)."\n";

            // The next query needs just a comma separated list of datatype ids...
            $datatype_list = array();
            foreach ($related_datatypes['child_datatypes'] as $child_datatype_id => $tmp)
                $datatype_list[] = $child_datatype_id;
            foreach ($related_datatypes['linked_datatypes'] as $num => $linked_datatype_id)
                $datatype_list[] = $linked_datatype_id;


            // TODO - partial duplicate of self::getSearchableDatafields()...
            // Grab typeclasses for each of the searchable datafields in this DataType
            $query_str =
               'SELECT ft.typeClass AS type_class, dt.id AS dt_id, dt.publicDate AS dt_public_date, df.id AS df_id, df.user_only_search AS user_only_search
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:FieldType AS ft WITH df.fieldType = ft
                JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                WHERE df.dataType IN (:datatypes) AND df.searchable > 0';

            $query = $em->createQuery($query_str)->setParameters( array('datatypes' => $datatype_list) );
            $results = $query->getArrayResult();

            // Organize the datafields into lists, and remove the ones the user can't search
            $datafield_array = array('by_typeclass' => array(), 'by_id' => array(), 'datatype_of' => array());
            foreach ($results as $num => $result) {
                $typeclass = $result['type_class'];
                $dt_id = $result['dt_id'];
                $dt_public_date = $result['dt_public_date'];
                $dt_public_date = $dt_public_date->format('Y-m-d');
                $df_id = $result['df_id'];
                $user_only_search = $result['user_only_search'];

                // TODO - public datatype
                $datatype_is_public = true;
                if ( $dt_public_date == '2200-01-01' )
                    $datatype_is_public = false;

                $has_view_permission = false;
                if ( isset($user_permissions[$dt_id]) && isset($user_permissions[$dt_id]['view']) )
                    $has_view_permission = true;

                // Only save the datafield if it's public, or the user has permissions to view it
                // TODO - actual datafield permissions
//                if ( $user_only_search == 0 || (isset($user_permissions[$dt_id]) && isset($user_permissions[$dt_id]['view'])) ) {
                if ( (!$logged_in && $user_only_search == 1) || (!$datatype_is_public && !$has_view_permission) ) {
                    /* either the datafield isn't visible to non-logged in users, or the user isn't allowed to see the datatype itself...either way, don't save the datafield */
                }
                else {
                    // ...first list being by typeclass, to use during general search
                    if ( !isset($datafield_array['by_typeclass'][$typeclass]) )
                        $datafield_array['by_typeclass'][$typeclass] = array();
                    $datafield_array['by_typeclass'][$typeclass][$dt_id][] = $df_id;

                    // ...second list being by datafield, to use during advanced search
                    $datafield_array['by_id'][$df_id] = $typeclass;

                    // ...third list mentioning what datatype they belong to
                    $datafield_array['datatype_of'][$df_id] = $dt_id;
                }
            }
if ($debug)
    print '$datafield_array: '.print_r($datafield_array, true)."\n";

            // --------------------------------------------------
            // Deal with metadata, if it exists
            $metadata = array();
            if ( isset($post['metadata']) ) {
                $metadata = $post['metadata'];

                // Fix the metadata array
                foreach ($metadata as $datatype_id => $data) {
                    foreach ($data as $key => $tmp) {
                        // Don't change the public key
                        if ($key == 'public')
                            continue;

                        // Ensure both start and end dates exist if either one exists
                        if ( isset($data[$key]['start']) && !isset($data[$key]['end']) ) {
                            $metadata[$datatype_id][$key]['end'] = '2200-01-01';
                        }
                        else if ( !isset($data[$key]['start']) && isset($data[$key]['end']) ) {
                            $metadata[$datatype_id][$key]['start'] = '1980-01-01';
                        }
                        else if ( isset($data[$key]['start']) && isset($data[$key]['end']) ) {
                            // Selecting a date start of...say, 2015-04-26 and a date end of 2015-04-28...gives the impression that the search will everything between the "26th" and the "28th", inclusive.
                            // However, to actually include results from the "28th", the end date needs to be incremented by 1 to 2015-04-29...
                            $date_end = new \DateTime( $data[$key]['end'] );

                            $date_end->add(new \DateInterval('P1D'));
                            $date_end = $date_end->format('Y-m-d');
                            $metadata[$datatype_id][$key]['end'] = $date_end;

if ($debug)
    print 'changed $'.$key.'_date_end to '.$date_end."\n";

                        }
                    }
                }
            }

            // Need to enforce these rules...
            // 1) If user doesn't have view permissions for target datatype, only show public datarecords of target datatype
            // If user doesn't have view permissions for child/linked datatypes, then
            //  2) searching datafields of child/linked dataypes must be restricted to public datarecords, or the user would be able to see non-public datarecords
            //  3) searching datafields of just the target datatype can't be restricted by non-public child/linked datatypes...or the user would be able to infer the existence of non-public child/linked datarecords

            // If user doesn't have view permissions for the target_datatype, force viewing of public datarecords only
            if ( !( isset($user_permissions[$target_datatype_id]) && isset($user_permissions[$target_datatype_id]['view']) ) ) {
                $metadata[$target_datatype_id] = array();   // clears updated/created (by)
                $metadata[$target_datatype_id]['public'] = 1;
            }

            // For each datafield the user is searching on...
            foreach ($datafields as $df_id => $value) {
                $dt_id = $datafield_array['datatype_of'][$df_id];
                if ( !( isset($user_permissions[$dt_id]) && isset($user_permissions[$dt_id]['view']) ) ) {
                    // ...if the user does not have view permissions for the datatype this datafield belongs to, enforce viewing of public datarecords only
//                    if ( !isset($metadata[$dt_id]) )
                        $metadata[$dt_id] = array();    // always want to clear updated/created (by)
                    $metadata[$dt_id]['public'] = 1;
                }
            }

if ($debug)
    print '$metadata: '.print_r($metadata, true)."\n";
//exit();


            // --------------------------------------------------
            // General search
            if (trim($general_string) !== '') {

                // Assume user wants exact search...
                $general_search_params = self::parseField($general_string, $debug);
if ($debug)
    print '$general_search_params: '.print_r($general_search_params, true)."\n";

                $basic_results = array();
                // Search all datafields belonging to this datatype that are of these fieldtypes
                $search_entities = array('ShortVarchar', 'MediumVarchar', 'LongVarchar', 'LongText', 'IntegerValue', 'Radio');   // TODO - DecimalValue too? or not...

                // NOTE - this purposefully ignores boolean...otherwise a general search string of '1' would return all checked boolean entities, and '0' would return all unchecked boolean entities

                foreach ($search_entities as $typeclass) {
                    // Grab list of datafields to search with this typeclass
                    if ( !isset($datafield_array['by_typeclass'][$typeclass]) )
                        continue;

                    $by_typeclass = $datafield_array['by_typeclass'][$typeclass];
                    foreach ($by_typeclass as $datatype_id => $datafield_list) {
                        // Don't apply a general search to datafields from linked datatypes
                        if ( in_array($datatype_id, $related_datatypes['linked_datatypes']) )
                            continue;

//print '$datafields: '.print_r($datafields, true)."\n";

                        $results = array();
                        if ($typeclass == 'Radio') {
                            // Radio requires a different set of parameters
                            $general_string = trim($general_string);
                            $comparision = '=';

                            // If general_string has quotes around it, strip them
                            if ( substr($general_string, 0, 1) == "\"" && substr($general_string, -1) == "\"" ) {
                                $general_string = substr($general_string, 1, -1);
                            }
                            else {
                                // Attach wildcards to search
                                $comparision = 'LIKE';
                                $general_string = '%'.$general_string.'%';
                            }

                            $radio_search_params = array(
                                'str' => 'ro.option_name '.$comparision.' :string AND rs.selected = 1',
                                'params' => array(
                                    'string' => $general_string
                                ),
                            );

                            // Run the radio-typeclass-specific query
                            $results = self::runSearchQuery($em, $datafield_list, $typeclass, $datatype_id, $radio_search_params, $related_datatypes, $metadata);
                        }
                        else {
                            // Run the query for most of the typeclasses
                            $results = self::runSearchQuery($em, $datafield_list, $typeclass, $datatype_id, $general_search_params, $related_datatypes, $metadata);
                        }

//print_r($results);
                        // Save the results
                        $basic_results = array_merge($basic_results, $results);

                        // Save that metadata was applied to this datatype
                        $metadata[$datatype_id]['searched'] = 1;
                    }
                }

if ($debug) {
    print '----------'."\n";
    print '$basic_results: '.print_r($basic_results, true)."\n";
    print '----------'."\n";
}

                // --------------------------------------------------
                // Now, need to turn the array into a list of datarecord ids
                $seen_datarecords = array();
                $datarecords = array();
                foreach ($basic_results as $num => $data) {
                    $dr = $data['id'];

                    if ( !isset($seen_datarecords[$dr]) ) {
                        $seen_datarecords[$dr] = 0;
                        $datarecords[] = $dr;
                    }
                }

                // Convert into a string of datarecord ids
                $str = '';
                foreach ($datarecords as $num => $dr_id)
                    $str .= $dr_id.',';
                $datarecord_str = substr($str, 0, strlen($str)-1);

                // Sort the subset of datarecords, if possible
                $basic_search_datarecords = $odrcc->getSortedDatarecords($datatype, $datarecord_str);
            }


            // --------------------------------------------------
            // Want the next block to run always, because it has to catch instances where metadata for a datatype is specified, but there are no searches performed on datafields of that datatype...
            // (since metadata is searched at the same time as the datafield contents are...)
            if ($datafields == null)
                $datafields = array();

            // Advanced Search
            $adv_results = array();
            if ($datafields !== null) {
                foreach ($datafields as $datafield_id => $search_string) {
                    // Skip db queries for empty searches
                    $adv_results[$datafield_id] = 'any';
                    if ( !is_array($search_string) && trim($search_string) === '')
                        continue;

                    // If user can't search this datafield, skip
                    if ( !isset($datafield_array['by_id'][$datafield_id]) )
                        continue;

                    // Grab information about the datafield
                    $typeclass = $datafield_array['by_id'][$datafield_id];
                    $datatype_id = $datafield_array['datatype_of'][$datafield_id];

                    // Build an array of search terms for the various fieldtypes...
                    $search_params = array();
                    if ($typeclass == 'Radio') {
                        // Convert Single Select/Radio to array so next part works for any version of a Radio datafield
                        if ( !is_array($search_string) ) {
                            $tmp = $search_string;
                            $search_string = array($tmp);
                        }

                        // Turn the array of radio options into a search string
                        $conditions = array();
                        $parameters = array();
                        $count = 0;
                        foreach ($search_string as $num => $radio_option_id) {
                            $str = '';
                            if ( strpos($radio_option_id, '-') !== false ) {
                                // Want this option to be unselected
                                $radio_option_id = substr($radio_option_id, 1);
                                $str = '(ro.id = :radio_option_'.$count.' AND rs.selected = 0)';
                            }
                            else {
                                // Want this option to be selected
                                $str = '(ro.id = :radio_option_'.$count.' AND rs.selected = 1)';
                            }

                            $conditions[] = $str;
                            $parameters[ 'radio_option_'.$count ] = $radio_option_id;
                            $count++;
                        }

                        // Build array of search params for this datafield
                        $search_params = array(
                            'str' => implode(' OR ', $conditions),
//                            'str' => implode(' AND ', $conditions),     // TODO - why is this 'OR' instead of 'AND'?
                            'params' => $parameters,
                        );
                    }
                    else if ($typeclass == 'Image' || $typeclass == 'File') {
                        // Assume user wants existence of files/images
                        $condition = 'e.id IS NOT NULL';
                        if ($search_string == 0)
                            $condition = 'e.id IS NULL';

                        // Build array of search params for this datafield
                        $search_params = array(
                            'str' => $condition,
                            'params' => array(),
                        );
                    }
                    else if ($typeclass == 'DatetimeValue') {
                        // Ensure correct versions of starting/ending date exist prior to searching
                        $start = $end = '';
                        if ( isset($search_string['s']) )    // if start date is set
                            $start = trim($search_string['s']);
                        if ( isset($search_string['e']) )    // if end date is set
                            $end = trim($search_string['e']);

                        if ($start == '' && $end == '')
                            continue;
                        else if ($end == '')
                            $end = '2200-01-01 00:00:00';
                        else if ($start == '')
                            $start = '1980-01-01 00:00:00';

/*
                        // Unlike create/modify dates, DateTime field values currently have no hour/minute/second component...therefore, for the time being, no adjustment to the $end value is necessary to match human expectations
                        if ($start == $end) {
                            $end = new \DateTime($end);

                            $end->add(new \DateInterval('P1D'));
                            $end = $end->format('Y-m-d H:i:s');

if ($debug)
    print '$start and $end values for DataField '.$datafield->getId().' are identical, changing $end to '.$end."\n";
                        }
*/

                        // Build array of search params for this datafield
                        $search_params = array(
                            'str' => 'e.value BETWEEN :start AND :end',
                            'params' => array(
                                'start' => $start,
                                'end' => $end,
                            ),
                        );
                    }
                    else {
                        // Every other FieldType...

                        // Assume user wants exact search...
                        $search_params = self::parseField($search_string, $debug);
//print_r($search_params);
                    }

                    // Run the query and save the results
                    $datafields = array( $datafield_id );
                    $typeclass = $datafield_array['by_id'][$datafield_id];
                    $results = self::runSearchQuery($em, $datafields, $typeclass, $datatype_id, $search_params, $related_datatypes, $metadata);

                    $using_adv_search = true;
                    $adv_results[$datafield_id] = $results;

                    // Save that metadata was applied to this datatype
                    $metadata[$datatype_id]['searched'] = 1;
                }

if ($debug)
    print 'after normal searches: '.print_r($metadata, true)."\n";

                // --------------------------------------------------
                // Check to see if any pieces of metadata didn't get searched on
                foreach ($metadata as $datatype_id => $data) {
                    if ( !isset($data['searched']) ) {
                        // User specified some metadata terms for a datatype...but those terms weren't applied because no datafield of that datatype was searched
                        // Create a query just to search on the metadata terms for this datatype

                        // Need different queries depending how the unsearched datatype relates to the target datatype
                        $search_metadata = array();
                        $linked_join = '';
                        $where = '';
                        if ($datatype_id == $related_datatypes['target_datatype']) {
                            $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], 'grandparent');
                            $where = 'WHERE dr.data_type_id = '.$datatype_id.' ';
                        }
                        else if ( isset($related_datatypes['child_datatypes'][ $datatype_id ]) ) {
                            $metadata_target = 'dr';
                            $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], 'dr');
                            $where = 'WHERE dr.data_type_id = '.$datatype_id.' ';
                        }
                        else if ( in_array($datatype_id, $related_datatypes['linked_datatypes']) ) {
                            $metadata_target = 'ldr';
                            $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], 'ldr');
                            $linked_join = 'INNER JOIN odr_linked_data_tree AS ldt ON dr.id = ldt.ancestor_id
                                INNER JOIN odr_data_record AS ldr ON ldt.descendant_id = ldr.id';
                            $where = 'WHERE ldr.deletedAt IS NULL AND ldr.data_type_id = '.$datatype_id.' AND dr.data_type_id = '.$related_datatypes['target_datatype'].' ';    // TODO - links to childtypes?
                        }

                        //
                        $metadata_str = $search_metadata['metadata_str'].' GROUP BY grandparent.id';
                        $parameters = $search_metadata['metadata_params'];

                        $query = '
                            SELECT grandparent.id
                            FROM odr_data_record AS grandparent
                            INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
                            '.$linked_join.'
                            '.$where.' AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL '.$metadata_str;

if ($debug) {
    print $query."\n";
    print '$parameters: '.print_r($parameters, true)."\n";
}

                        // ----------------------------------------
                        // Execute and return the native SQL query
                        $conn = $em->getConnection();
                        $results = $conn->fetchAll($query, $parameters);

if ($debug) {
    print '>> '.print_r($results, true)."\n";
}

                        // Save the query result so the search results are correctly restricted
                        $using_adv_search = true;
                        $adv_results['dt_'.$datatype_id.'_metadata'] = $results;
                    }
                }
if ($debug) {
    print '----------'."\n";
    print '$adv_results: '.print_r($adv_results, true)."\n";
    print '----------'."\n";
}


                // --------------------------------------------------
                // Now, need to turn the metadata/datafield array into a list of datarecord ids
                $has_result = false;
                $datarecords = array();
                foreach ($adv_results as $datafield_id => $data) {
                    if ($data !== 'any') {
                        $has_result = true;
                        // Due to this search being an implicit AND, if one of the datafields had no hits on the search term, everything fails
                        if ( count($data) == 0 ) {
                            $datarecords = array();
                            break;
                        }

                        // Otherwise, flatten $data into a 2D array where $datarecords[$datafield_id] = <list of datarecords matching search term for $datafield_id>
                        $datarecord_list = array();
                        foreach ($data as $key => $tmp)
                            $datarecord_list[] = $tmp['id'];
                        $datarecords[] = $datarecord_list;
                    }
                }

                if ($has_result == true) {
                    // Reduce $datarecords into a list of datarecord ids that matched all search terms
                    if ( count($datarecords) == 1 ) {
                        $datarecords = $datarecords[0];
                    }
                    else if ( count($datarecords) > 1 ) {
                        $tmp = $datarecords[0];
                        for ($i = 1; $i < count($datarecords); $i++)
                            $tmp = array_intersect($tmp, $datarecords[$i]);
                        $datarecords = $tmp;
                    }

                    // Convert into a string of datarecord ids
                    $str = '';
                    foreach ($datarecords as $num => $dr_id)
                        $str .= $dr_id.',';
                    $datarecord_str = substr($str, 0, strlen($str)-1);

                    // Sort the subset of datarecords, if possible
                    $adv_search_datarecords = $odrcc->getSortedDatarecords($datatype, $datarecord_str);
                }
                else {
                    // Metadata and datafield searches produced no results...datarecord list restriction will depend solely on general search
                    $adv_search_datarecords = 'any';
                }

            }

if ($debug) {
    print '----------'."\n";
    print '$basic_search_datarecords: '.print_r($basic_search_datarecords, true)."\n";
    print '$adv_search_datarecords: '.print_r($adv_search_datarecords, true)."\n";
    print '----------'."\n";
}


            // --------------------------------------------------
            // Combine advanced and basic search results if necessary
            $datarecords = '';
            if ($using_adv_search && trim($general_string) !== '') {
if ($debug)
    print "a\n";
                if ($adv_search_datarecords == 'any') {
                    $datarecords = $basic_search_datarecords;
                }
                else {
                    // Some combination of both basic and adv search...only return records that are in both basic and adv search
                    $basic_search_datarecords = explode(',', $basic_search_datarecords);
                    $adv_search_datarecords = explode(',', $adv_search_datarecords);

                    $str = '';
                    foreach ($basic_search_datarecords as $b_dr) {
                        if ( in_array($b_dr, $adv_search_datarecords) )
                            $str .= $b_dr.',';
                    }
                    $datarecords = substr($str, 0, strlen($str)-1);
                }
            }
            else if (trim($general_string) !== '') {
if ($debug)
    print "b\n";
                // used basic search, return any results
                $datarecords = $basic_search_datarecords;
            }
            else if ($using_adv_search && $adv_search_datarecords != 'any') {
if ($debug)
    print "c\n";
                // used adv search, return any results
                $datarecords = $adv_search_datarecords;
            }
            else {
if ($debug)
    print "d\n";
                // Nothing entered in either search, return everything
                $datarecords = $odrcc->getSortedDatarecords($datatype);
            }


            // --------------------------------------------------
            // Store the list of datarecord ids for later use
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $search_checksum = md5($search_key);
            $datatype_id = $datatype->getId();

            $cached_searches = $memcached->get($memcached_prefix.'.cached_search_results');

            // Create pieces of the array if they don't exist
            if ($cached_searches == null)
                $cached_searches = array();
            if ( !isset($cached_searches[$datatype_id]) )
                $cached_searches[$datatype_id] = array();
            if ( !isset($cached_searches[$datatype_id][$search_checksum]) )
                $cached_searches[$datatype_id][$search_checksum] = array('searched_datafields' => $searched_datafields, 'encoded_search_key' => $encoded_search_key);

            if ($datarecords == false)  // apparently $datarecords gets set to false sometimes...
                $datarecords = '';

            // Store the data in the memcached entry
            if ($logged_in)
                $cached_searches[$datatype_id][$search_checksum]['logged_in'] = array('datarecord_list' => $datarecords);
            else
                $cached_searches[$datatype_id][$search_checksum]['not_logged_in'] = array('datarecord_list' => $datarecords);

            $memcached->set($memcached_prefix.'.cached_search_results', $cached_searches, 0);

if ($debug) {
    print 'saving datarecord_str: '.$datarecords."\n";

    print_r($cached_searches);
}
        }

    }


    /**
     * Given a set of search parameters, runs a search on the given datafields, and returns the grandparent id of all datarecords that match the query
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datafield_list    An array of datafield ids...must all be of the same typeclass
     * @param integer $datatype_id     The datatype that the datafields in $datafield_list belong to
     * @param string $typeclass        The typeclass of every datafield in $datafield_list
     * @param array $search_params     
     * @param array $related_datatypes @see self::getRelatedDatatypes()
     * @param array $metadata          
     *
     * @return array TODO
     */
    private function runSearchQuery($em, $datafield_list, $typeclass, $datatype_id, $search_params, $related_datatypes, $metadata)
    {
$debug = true;
$debug = false;

        // Conversion array from typeclass to physical table name
        $table_names = array(
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',

            'IntegerValue' => 'odr_integer_value',
            'DecimalValue' => 'odr_decimal_value',
            'DatetimeValue' => 'odr_datetime_value',

            'Boolean' => 'odr_boolean',

            'File' => 'odr_file',
            'Image' => 'odr_image',
        );

        // If no datafields to search, return nothing
        if ( count($datafield_list) == 0 || count($search_params) == 0 )
            return array();

        // ----------------------------------------
        // Convert the array of datafields to a comma-separated list
        $datafields = array();
        foreach ($datafield_list as $num => $datafield_id)
            $datafields[] = $datafield_id;
        $datafields = implode(',', $datafields);
        $datafield_str = 'e.data_field_id IN ('.$datafields.')';

        $query = '';
        $parameters = $search_params['params'];


        // ----------------------------------------
        // Always include created/updated/public metadata for the grandparent if it exists...
        $metadata_str = '';
        $target_datatype_id = $related_datatypes['target_datatype'];
        $from_linked_datatype = false;
        if ( isset($metadata[$target_datatype_id]) )  {
            $search_metadata = self::buildMetadataQueryStr($metadata[$target_datatype_id], 'grandparent');

            if ( $search_metadata['metadata_str'] !== '' ) {
                $metadata_str .= $search_metadata['metadata_str'];
                $parameters = array_merge($parameters, $search_metadata['metadata_params']);
            }
        }


        $drf_join = 'INNER JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id';
        if ( $datatype_id !== $target_datatype_id && isset($related_datatypes['child_datatypes'][ $datatype_id ]) ) {
            // Searching from a child datatype requires different metadata
            if ( isset($metadata[$datatype_id]) ) {
                $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], 'dr');

                if ( $search_metadata['metadata_str'] !== '' ) {
                    $metadata_str .= $search_metadata['metadata_str'];
                    $parameters = array_merge($parameters, $search_metadata['metadata_params']);
                }
            }
        }
        else if ( in_array($datatype_id, $related_datatypes['linked_datatypes']) ) {

            $from_linked_datatype = true;

            // Searching from a linked datatype requires different INNER JOINs
            $drf_join = 'INNER JOIN odr_linked_data_tree AS ldt ON dr.id = ldt.ancestor_id
                INNER JOIN odr_data_record AS ldr ON ldt.descendant_id = ldr.id
                INNER JOIN odr_data_record_fields AS drf ON drf.data_record_id = ldr.id';

            // Searching from a linked datatype also requires different metadata
            if ( isset($metadata[$datatype_id]) ) {
                $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], 'ldr');

                if ( $search_metadata['metadata_str'] !== '' ) {
                    $metadata_str .= $search_metadata['metadata_str'];
                    $parameters = array_merge($parameters, $search_metadata['metadata_params']);
                }
            }
        }


        // ----------------------------------------
        // Different typeclasses need different queries...
        if ($typeclass == 'Radio') {
            // Build the native SQL query specifically for Radio datafields
            $query = 
               'SELECT grandparent.id
                FROM odr_data_record AS grandparent
                INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
                '.$drf_join.'
                INNER JOIN odr_radio_selection AS rs ON rs.data_record_fields_id = drf.id
                INNER JOIN odr_radio_options AS ro ON rs.radio_option_id = ro.id
                WHERE drf.data_field_id IN ('.$datafields.') AND ('.$search_params['str'].')
                AND grandparent.deletedAt IS NULL AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL ';

            if ($from_linked_datatype)
                $query .= 'AND ldt.deletedAt IS NULL AND ldr.deletedAt IS NULL AND dr.data_type_id = '.$target_datatype_id.' '; // TODO - links to childtypes?

            $query .= $metadata_str;
            $query .= ' GROUP BY grandparent.id';
        }
        else if ($typeclass == 'Image' || $typeclass == 'File') {
            // Build the native SQL query that will check for (non)existence of files/images in this datafield
            $query =
               'SELECT grandparent.id
                FROM odr_data_record AS grandparent
                INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
                '.$drf_join.'
                LEFT JOIN '.$table_names[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE drf.data_field_id IN ('.$datafields.') AND ('.$search_params['str'].')
                AND grandparent.deletedAt IS NULL AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL ';

            if ($from_linked_datatype)
                $query .= 'AND ldt.deletedAt IS NULL AND dr.data_type_id = '.$target_datatype_id.' ';   // TODO - links to childtypes?

            $query .= $metadata_str;
            $query .= ' GROUP BY grandparent.id';
        }
        else {
            // Build the native SQL query that will check content of any other datafields
            $query =
               'SELECT grandparent.id
                FROM odr_data_record AS grandparent
                INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
                '.$drf_join.'
                INNER JOIN '.$table_names[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE '.$datafield_str.' AND ('.$search_params['str'].')
                AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL ';

            if ($from_linked_datatype)
                $query .= 'AND ldt.deletedAt IS NULL AND ldr.deletedAt IS NULL AND dr.data_type_id = '.$target_datatype_id.' '; // TODO - links to childtypes?

            $query .= $metadata_str;
            $query .= ' GROUP BY grandparent.id';
        }

if ($debug) {
    print $query."\n";
    print '$parameters: '.print_r($parameters, true)."\n";
}

        // ----------------------------------------
        // Execute and return the native SQL query
        $conn = $em->getConnection();
        $results = $conn->fetchAll($query, $parameters);

if ($debug) {
    print '>> '.print_r($results, true)."\n";
}

        return $results;
    }


    /**
     * Turns a specially built array of created/updated (by) and public date requirements for searching a datatype into native SQL
     *
     * @param array $metadata
     * @param string $target  'grandparent', 'dr', or 'ldr'...corresponding to attaching the metadata to the top-level, a child, or a linked datarecord, respectively
     *
     * @return array ...of native SQL and the corresponding parameters
     */
    private function buildMetadataQueryStr($metadata, $target)
    {
        $metadata_str = '';
        $metadata_params = array();

//print '$__metadata: '.print_r($metadata, true)."\n";

        // ----------------------------------------
        // Deal with modify dates and updatedBy
        if ( isset($metadata['updated']) ) {
            // Search by modify date
            if ( isset($metadata['updated']['start']) ) {
                $metadata_str .= 'AND '.$target.'.updated BETWEEN :updated_start AND :updated_end ';
                $metadata_params['updated_start'] = $metadata['updated']['start'];
                $metadata_params['updated_end'] = $metadata['updated']['end'];
            }

            // Search by updatedBy
            if ( isset($metadata['updated']['by']) ) {
                $metadata_str .= 'AND '.$target.'.updatedBy = :updated_by ';
                $metadata_params['updated_by'] = $metadata['updated']['by'];
            }
        }

        // ----------------------------------------
        // Deal with create dates and createdBy
        if ( isset($metadata['created']) ) {
            // Search by create date
            if ( isset($metadata['created']['start']) ) {
                $metadata_str .= 'AND '.$target.'.created BETWEEN :created_start AND :created_end ';
                $metadata_params['created_start'] = $metadata['created']['start'];
                $metadata_params['created_end'] = $metadata['created']['end'];
            }

            // Search by createdBy
            if ( isset($metadata['created']['by']) ) {
                $metadata_str .= 'AND '.$target.'.createdBy = :created_by ';
                $metadata_params['created_by'] = $metadata['created']['by'];
            }
        }

        // ----------------------------------------
        // Deal with public status
        if ( isset($metadata['public']) ) {
            if ( $metadata['public'] == 1 ) {
                // Search for public datarecords only
                $metadata_str .= 'AND '.$target.'.public_date != :public_date ';
                $metadata_params['public_date'] = '2200-01-01 00:00:00';
            }
            else if ( $metadata['public'] == 0 ) {
                // Search for non-public datarecords only
                $metadata_str .= 'AND '.$target.'.public_date = :public_date ';
                $metadata_params['public_date'] = '2200-01-01 00:00:00';
            }
        }

        $metadata = array(
            'metadata_str' => $metadata_str,
            'metadata_params' => $metadata_params,
        );

        return $metadata;
    }


    /**
     * Turns a piece of the search string into a more DQL-friendly format.
     *
     * @param string $str    The string to turn into DQL...
     * @param boolean $debug Whether to print out debug info or not.
     *
     * @return array
     */
    private function parseField($str, $debug) {
        // ?
        $str = str_replace(array("\n", "\r"), '', $str);

if ($debug) {
    print "\n".'--------------------'."\n";
    print $str."\n";
}

        $pieces = array();
        $in_quotes = false;
        $tmp = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
        
            if ($char == "\"") {
                if ($in_quotes) {
                    // found closing quote
                    $in_quotes = false;
        
                    // save fragment
                    $tmp .= "\"";
                    $pieces[] = $tmp;
                    $tmp = '';
        
                    // skip over next character?
        //            $i++;
                }
                else {
                    // found opening quote
                    $in_quotes = true;
                    $tmp = "\"";
                }
            }
            else {
                if ($in_quotes) {
                    // append to fragment
                    $tmp .= $char;
                }
                else {
                    switch ($char) {
                        case ' ':
                            // save any existing piece before saving the operator
                            if ($tmp !== '') {
                                $pieces[] = $tmp;
                                $tmp = '';
                            }
                            $pieces[] = '&&';
                            break;
                        case '!':
//                        case '-':
                            // attempt to ignore the operator if not attached to a term
                            /*if ( $str[$i+1] !== ' ' )*/
                                $pieces[] = '!';
                            break;
                        case '>':
                            // attempt to ignore the operator if not attached to a term
                            if ( $str[$i+1] == '=' /*&& $str[$i+2] !== ' '*/ ) {
                                $pieces[] = '>=';
                                $i++;
                            }
                            else /*if ( $str[$i+1] !== ' ' )*/
                                $pieces[] = '>';
                            break;
                        case '<':
                            // attempt to ignore the operator if not attached to a term
                            if ( $str[$i+1] == '=' /*&& $str[$i+2] !== ' '*/ ) {
                                $pieces[] = '<=';
                                $i++;
                            }
                            else /*if ( $str[$i+1] !== ' ' )*/
                                $pieces[] = '<';
                            break;
                        case 'o':
                        case 'O':
                            // only count this as an operator if the 'O' is part of the substring ' OR '
                            if ( $str[$i-1] == ' ' && ($str[$i+1] == 'R' || $str[$i+1] == 'r') && $str[$i+2] == ' ' ) {
                                $pieces[] = '||';
        //                        $i++;
                                $i += 2;
/*
                                // cut out the 'AND' token that was added as a result of the preceding space
                                if ( $pieces[count($pieces)-2] == '&&' )
                                    unset( $pieces[count($pieces)-2] );
*/
                            }
                            else {
                                // otherwise, part of a string
                                $tmp .= $char;
                            }
                            break;
                        default:
                            // part of a string
                            $tmp .= $char;
                            break;
                    }
                }
            }
        }
        // save any remaining piece
        if ($tmp !== '')
            $pieces[] = $tmp;

if ($debug)
    print_r($pieces);
        // clean up the array as best as possible
        $pieces = array_values($pieces);
        $first = true;
        $previous = 0;
        foreach ($pieces as $num => $piece) {
            // prevent operators needing two operands from being out in front
            if ( $first && self::isConnective($piece) ) {
                unset( $pieces[$num] );
                continue;
            }
            // save the first "good" token
            if ($first) {
                $first = false;
                $previous = $piece;
                continue;
            }

            // delete consecutive operators 
            if ( $pieces[$num] == '&&' && $pieces[$num+1] == '||' )
                unset( $pieces[$num] );
            else if ( self::isLogicalOperator($previous) && self::isLogicalOperator($piece) )
                unset( $pieces[$num] );
            // delete operators after inequalities
            else if ( self::isInequality($previous) && (self::isConnective($piece) || self::isInequality($piece)) )
                unset( $pieces[$num] );
            // legitimate token
            else
                $previous = $piece;
            
        }

        // remove trailing operators...they're unmatched by definition
        $pieces = array_values($pieces);
        $num = count($pieces)-1;
        if ( self::isLogicalOperator($pieces[$num]) || self::isInequality($pieces[$num]) )
            unset( $pieces[$num] );
if ($debug)
    print_r($pieces);

        $negate = false;
        $inequality = false;
        $str = 'e.value';
        $parameters = array();
        $count = 0;
        foreach ($pieces as $num => $piece) {
            if ($piece == '!') {
                $negate = true;
            }
            else if ($piece == '&&') {
                $str .= ' AND e.value';
            }
            else if ($piece == '||') {
                $str .= ' OR e.value';
            }
            else if ($piece == '>') {
                $inequality = true;
                if ($negate)
                    $str .= ' <= ';
                else
                    $str .= ' > ';
            }
            else if ($piece == '<') {
                $inequality = true;
                if ($negate)
                    $str .= ' >= ';
                else
                    $str .= ' < ';
            }
            else if ($piece == '>=') {
                $inequality = true;
                if ($negate)
                    $str .= ' < ';
                else
                    $str .= ' >= ';
            }
            else if ($piece == '<=') {
                $inequality = true;
                if ($negate)
                    $str .= ' > ';
                else
                    $str .= ' <= ';
            }
            else {
                if (!$inequality) {
                    if ( strpos($piece, "\"") !== false ) {  // does have a quote
                        $piece = str_replace("\"", '', $piece);
                        if ( is_numeric($piece) )
                            if ( strpos($piece, '.') === false )
                                $piece = intval($piece);
                            else
                                $piece = floatval($piece);

                        if ($negate)
                            $str .= ' != ';
                        else
                            $str .= ' = ';
                    }
                    else {
                        $piece = '%'.$piece.'%';
                        if ($negate)
                            $str .= ' NOT LIKE ';
                        else
                            $str .= ' LIKE ';
                    }
                }
                else if ( is_numeric($piece) ) {
                    if ( strpos($piece, '.') === false )
                        $piece = intval($piece);
                    else
                        $piece = floatval($piece);
                }
                $negate = false;
                $inequality = false;
        
                $str .= ':term_'.$count;
                $parameters['term_'.$count] = $piece;
                $count++;
            }
        }
        $str = trim($str);

if ($debug) {
    print $str."\n";
    print_r($parameters);
    print "\n".'--------------------'."\n";
}

        return array('str' => $str, 'params' => $parameters);
    }


    /**
     * Returns true if the string describes a binary operator  a && b, a || b
     *
     * @param string $str The string to test
     *
     * @return boolean 
     */
    private function isConnective($str) {
        if ( $str == '&&' || $str == '||' )
            return true;
        else
            return false;
    }


    /**
     * Returns true if the string describes a logical operator  &&, ||, !
     *
     * @param string $str The string to test
     *
     * @return boolean 
     */
    private function isLogicalOperator($str) {
        if ( $str == '&&' || $str == '||' || $str == '!' )
            return true;
        else
            return false;
    }


    /**
     * Returns true if the string describes an inequality
     *
     * @param string $str The string to test
     *
     * @return boolean 
     */
    private function isInequality($str) {
        if ( $str == '>=' || $str == '<=' || $str == '<' || $str == '>' )
            return true;
        else
            return false;
    }


    /**
     * TODO - short description
     *
     * @param string $str The string to convert
     *
     * @return string
     */
    private function encodeURIComponent($str) {
        $revert = array('%21'=>'!', '%2A'=>'*', '%27'=>"'", '%28'=>'(', '%29'=>')');
        return strtr(rawurlencode($str), $revert);
    }

}

