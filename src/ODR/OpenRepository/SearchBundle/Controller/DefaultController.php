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

// Entites
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;

class DefaultController extends Controller
{

    /**
     * TODO
     *
     * @param string $error_message
     * @param Request $request
     * @param boolean $inline
     *
     * @return Response TODO
     */
    public function searchPageError($error_message, Request $request, $inline = false)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $html = '';

        try
        {
            // Grab user and their permissions if possible
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            // Store if logged in or not
            $logged_in = true;
            if ($user === 'anon.') {
                $user = null;
                $logged_in = false;
            }

            if ($inline) {
                $templating = $this->get('templating');
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODROpenRepositorySearchBundle:Default:searchpage_error.html.twig',
                        array(
                            'logged_in' => $logged_in,
                            'error_message' => $error_message,
                        )
                    )
                );
            }
            else {
                // Grab user permissions if possible
                $user_permissions = array();
                if ($logged_in) {
                    $odrcc = $this->get('odr_custom_controller', $request);
                    $odrcc->setContainer($this->container);
                    $user_permissions = $odrcc->getPermissionsArray($user->getId(), $request);
                }

                $site_baseurl = $this->container->getParameter('site_baseurl');
                if ($this->container->getParameter('kernel.environment') === 'dev')
                    $site_baseurl .= '/app_dev.php';

                // Generate a random key to identify this tab
                $tokenGenerator = $this->container->get('fos_user.util.token_generator');
                $odr_tab_id = substr($tokenGenerator->generateToken(), 0, 15);

                $html = $this->renderView(
                    'ODROpenRepositorySearchBundle:Default:index_error.html.twig',
                    array(
                        // required twig/javascript parameters
                        'user' => $user,
                        'user_permissions' => $user_permissions,

//                        'user_list' => $user_list,
                        'logged_in' => $logged_in,
                        'window_title' => 'ODR Admin',
                        'source' => 'searching',
                        'search_slug' => 'admin',
                        'site_baseurl' => $site_baseurl,
                        'search_string' => '',
                        'odr_tab_id' => $odr_tab_id,

                        'error_message' => $error_message,
                    )
                );
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x22127327 ' . $e->getMessage();
        }

        $response = null;
        if ($inline) {
            $response = new Response(json_encode($return));
            $response->headers->set('Content-Type', 'application/json');
        }
        else {
            $response = new Response($html);
            $response->headers->set('Content-Type', 'text/html');
        }

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
           'SELECT ancestor.id AS ancestor_id, ancestor_meta.shortName AS ancestor_name, descendant.id AS descendant_id, descendant_meta.shortName AS descendant_name, descendant_meta.publicDate AS public_date, dtm.is_link AS is_link
            FROM ODRAdminBundle:DataTree AS dt
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataTypeMeta AS ancestor_meta WITH ancestor_meta.dataType = ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            JOIN ODRAdminBundle:DataTypeMeta AS descendant_meta WITH descendant_meta.dataType = descendant
            WHERE dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND ancestor_meta.deletedAt IS NULL AND descendant.deletedAt IS NULL AND descendant_meta.deletedAt IS NULL');
        $results = $query->getArrayResult();

        $datatype_names = array();
        $descendant_of = array();
        $links = array();
        foreach ($results as $num => $result) {
            $ancestor_id = $result['ancestor_id'];
            $ancestor_name = $result['ancestor_name'];
            $descendant_id = $result['descendant_id'];
            $descendant_name = $result['descendant_name'];
            $public_date = $result['public_date'];
            $is_link = $result['is_link'];

            // TODO - public datatype
            $is_public = true;
            if ( $public_date->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' )
                $is_public = false;

            if ($is_link == 0) {
                // Save childtypes encountered
                if ( !isset($descendant_of[$ancestor_id]) ) {
                    $descendant_of[$ancestor_id] = array();
                    $datatype_names[$ancestor_id] = $ancestor_name;
                }

                // Only save this datatype if the user is allowed to view it
                if ( $is_public || (isset($user_permissions[$descendant_id]) && isset($user_permissions[$descendant_id]['view'])) ) {
                    $descendant_of[$ancestor_id][] = $descendant_id;
                    $datatype_names[$descendant_id] = $descendant_name;
                }
            }
            else {
                // Save datatype links encountered
                if ( !isset($links[$ancestor_id]) ) {
                    $links[$ancestor_id] = array();
                    $datatype_names[$ancestor_id] = $ancestor_name;
                }

                // Only save this datatype if the user is allowed to view it
                if ( $is_public || (isset($user_permissions[$descendant_id]) && isset($user_permissions[$descendant_id]['view'])) ) {
                    $links[$ancestor_id][] = $descendant_id;
                    $datatype_names[$descendant_id] = $descendant_name;
                }
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
            'datatype_names' => $datatype_names,
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
            /** @var DataFields $datafield */
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
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

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
                    return self::searchPageError("Page not found", $request);
                }
            }
            else {
                $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy( array('searchSlug' => $search_slug) );
                if ($target_datatype == null)
                    return self::searchPageError("Page not found", $request);
            }
            /** @var DataType $target_datatype */


            // ------------------------------
            // Grab user and their permissions if possible
            /** @var User $admin_user */
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
                return self::searchPageError("You don't have permission to access this DataType.", $request);

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
            /** @var User[] $user_list */
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
                   'SELECT u
                    FROM ODROpenRepositoryUserBundle:User AS u
                    JOIN ODRAdminBundle:UserPermissions AS up WITH up.user_id = u
                    WHERE up.dataType IN (:datatypes) AND up.can_view_type = 1
                    GROUP BY u.id'
                )->setParameters( array('datatypes' => $datatype_list) );   // purposefully getting ALL users, including the ones that are deleted
                $results = $query->getResult();

                // Convert them into a list of users that the admin user is allowed to search by
                $user_list = array();
                foreach ($results as $user)
                    $user_list[] = $user;
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
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $odrcc = $this->get('odr_custom_controller', $request);
            $odrcc->setContainer($this->container);

            // Need to grab all searchable datafields for the target_datatype and its descendants
            /** @var DataType $target_datatype */
            $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($target_datatype_id);
            if ($target_datatype == null)
                return $odrcc->deletedEntityError('Datatype');

            // ------------------------------
            // Grab user and their permissions if possible
            /** @var User $admin_user */
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
            /** @var User[] $user_list */
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
                   'SELECT u
                    FROM ODROpenRepositoryUserBundle:User AS u
                    JOIN ODRAdminBundle:UserPermissions AS up WITH up.user_id = u
                    WHERE up.dataType IN (:datatypes) AND up.can_view_type = 1
                    GROUP BY u.id'
                )->setParameters( array('datatypes' => $datatype_list) );   // purposefully getting ALL users, including the ones that are deleted
                $results = $query->getResult();

                // Convert them into a list of users that the admin user is allowed to search by
                $user_list = array();
                foreach ($results as $user)
                    $user_list[] = $user;
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
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');


//            $templating = $this->get('templating');
/*
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();
*/

            // Get ODRCustomController from the AdminBundle...going to need functions from it
            $odrcc = $this->get('odr_custom_controller', $request);
            $odrcc->setContainer($this->container);

            // --------------------
            // Determine user privileges
            /** @var User $user */
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
            if ( $search_params['error'] == true ) {
                $render_inline = true;
                return self::searchPageError($search_params['message'], $request, $render_inline);
            }

            // Now that the search is guaranteed to exist and be correct...get all pieces of info about the search
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find( $search_params['datatype_id'] );
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
            $theme = null;
            if ($source == 'linking' || $datatype->getUseShortResults() == 0)
                $theme = $repo_theme->find(4);  // textresults
            else
                $theme = $repo_theme->find(2);  // shortresults
            /** @var Theme $theme */


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
     * @return boolean|array true if no error, or array with necessary data otherwise
     */
    public function performSearch($search_key, Request $request)
    {
        // Grab default objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
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
$more_debug = true;
$more_debug = false;
$timing = true;
$timing = false;

$start_time = microtime(true);

if ($debug || $more_debug || $timing)
    print '<pre>';

if ($debug) {
    print 'cached datarecord str: '.$cached_datarecord_str."\n";
}

        // --------------------------------------------------
        // If there's something in the cache, use that
        if ($cached_datarecord_str != null) {
            //$datarecords = $cached_datarecord_str;
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
                    /** @var DataFields $datafield */
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
    print 'search_key: '.$search_key."\n";
    print 'md5('.$search_key.'): '.md5($search_key)."\n";
    print 'encoded_search_key: '.$encoded_search_key."\n";
    print 'md5('.$encoded_search_key.'): '.md5($encoded_search_key)."\n";
    print 'post: '.print_r($post, true)."\n";
}

            $target_datatype_id = $post['dt_id'];
            /** @var DataType $datatype */
            $datatype = $repo_datatype->find($target_datatype_id);
            if ($datatype == null)
                return array('message' => "This datatype is deleted", 'encoded_search_key' => $encoded_search_key);


            // General Search will search all datafields of these fieldtypse that belong to this datatype and its childtypes
            $search_entities = array('ShortVarchar', 'MediumVarchar', 'LongVarchar', 'LongText', 'IntegerValue', 'Radio');   // TODO - DecimalValue too? or not...
            // NOTE - this purposefully ignores boolean...otherwise a general search string of '1' would return all checked boolean entities, and '0' would return all unchecked boolean entities


            $session->set('prev_searched_datatype_id', $target_datatype_id);    // TODO - storing previously searched datatype in session doesn't really work for users

            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];
            $general_string = null;
            if ( isset($post['gen']) )
                $general_string = trim($post['gen']);


            // --------------------------------------------------
            // Determine level of user's permissions...
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();
            $logged_in = false;

            if ($user !== null && $user !== 'anon.') {
                $logged_in = true;

                // Grab user's permissions
                $user_permissions = $odrcc->getPermissionsArray($user->getId(), $request);
            }


            // --------------------------------------------------
            // Get rid of empty/blank search strings for datafields
            foreach ($datafields as $id => $str) {
                if ( !is_array($str) && trim($str) === '' )
                    unset( $datafields[$id] );
            }

            // Keep track of the datafields that are being searched on...this will get stored in memcached so cached searches involving these datafield can be deleted when changes are made to their contents
            $searched_datafields = array();
            foreach ($datafields as $df_id => $search_value)
                $searched_datafields[] = $df_id;


            // --------------------------------------------------
            // Grab all datatypes related to the one being searched
            $searched_datatypes = array();
            $related_datatypes = self::getRelatedDatatypes($em, $target_datatype_id, $user_permissions);

if ($debug)
    print '$related_datatypes: '.print_r($related_datatypes, true)."\n";

            // The next DQL query needs a comma separated list of datatype ids...
            $datatype_list = array();
            foreach ($related_datatypes['child_datatypes'] as $child_datatype_id => $tmp)
                $datatype_list[] = $child_datatype_id;
            foreach ($related_datatypes['linked_datatypes'] as $num => $linked_datatype_id)
                $datatype_list[] = $linked_datatype_id;


            // TODO - partial duplicate of self::getSearchableDatafields()...
            // Grab typeclasses for each of the searchable datafields in all datatypes related to the target datatype
            $query_str =
               'SELECT ft.typeClass AS type_class, dt.id AS dt_id, dtym.publicDate AS dt_public_date, df.id AS df_id, dfm.user_only_search AS user_only_search
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
                JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                JOIN ODRAdminBundle:DataTypeMeta AS dtym WITH dtym.dataType = dt
                WHERE df.dataType IN (:datatypes) AND dfm.searchable > 0
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL AND dt.deletedAt IS NULL AND dtym.deletedAt IS NULL';

            $query = $em->createQuery($query_str)->setParameters( array('datatypes' => $datatype_list) );
            $results = $query->getArrayResult();

            // Organize the datafields into lists, and remove the ones the user can't search
            $datafield_array = array('by_typeclass' => array(), 'by_id' => array(), 'datatype_of' => array(), 'by_datatype' => array());
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
                    // The first list of datafield ids is organized by typeclass then by datatype, and is used during general search
                    if ( in_array($typeclass, $search_entities) ) {
                        if (!isset($datafield_array['by_typeclass'][$typeclass]))
                            $datafield_array['by_typeclass'][$typeclass] = array();
                        $datafield_array['by_typeclass'][$typeclass][$dt_id][] = $df_id;

                        if ($general_string !== null)
                            $searched_datatypes[] = $dt_id;
                    }

                    // The second list stores the typeclass for each datafield, and is used during advanced search
                    $datafield_array['by_id'][$df_id] = $typeclass;

                    // The third list stores the datatype id for each datafield, and is used for finding some query errors and also during advanced search
                    $datafield_array['datatype_of'][$df_id] = $dt_id;

                    // The fourth list stores the datafields for each datatype...makes computing the intersection of search results easier after general/advanced searches are completed
                    if ( !isset($datafield_array['by_datatype'][$dt_id]) )
                        $datafield_array['by_datatype'][$dt_id] = array();
                    $datafield_array['by_datatype'][$dt_id][] = $df_id;


                    // Also store the datatypes of the datafields the user is searching on
                    if ( in_array($df_id, $searched_datafields) )
                        $searched_datatypes[] = $dt_id;
                }
            }


            // --------------------------------------------------
            // All datatypes being searched on receive an initial "flag" that is applied to each possible datarecord that could satisfy the search...by default, most of them don't have to match search criteria exactly...
            $initial_datatype_flags = array();
            foreach ($searched_datatypes as $num => $dt_id)
                $initial_datatype_flags[$dt_id] = 0;

            // If the user is searching on a datafield, then datarecords of that datafield's datatype MUST match search query to be included
            foreach ($datafields as $df_id => $value)
                $initial_datatype_flags[ $datafield_array['datatype_of'][$df_id] ] = -1;

            // Top-level datarecords must also be set to -1, otherwise most searches would always return all top-level datarecords
            $initial_datatype_flags[$target_datatype_id] = -1;


            // --------------------------------------------------
            // Deal with metadata, if it exists
            $metadata = array();
            if ( isset($post['metadata']) ) {
                $metadata = $post['metadata'];

                // Fix the metadata array
                foreach ($metadata as $datatype_id => $data) {
                    // This datatype is being searched on
                    $searched_datatypes[] = $datatype_id;
                    $initial_datatype_flags[$datatype_id] = -1;

                    // In case one of the searched datafields doesn't cover it, ensure an entry exists for this datatype's metadata...will be used later during the intersection calculations
                    if ( !isset($datafield_array['by_datatype'][$datatype_id]) )
                        $datafield_array['by_datatype'][$datatype_id] = array();
                    $datafield_array['by_datatype'][$datatype_id][] = 'dt_'.$datatype_id.'_metadata';

                    foreach ($data as $key => $tmp) {
                        // Don't change the public_date key
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

                // Ensure that the intersection calculation is aware that this datatype is restricted to public datarecords
                if ( !in_array('dt_'.$target_datatype_id.'_metadata', $datafield_array['by_datatype'][$target_datatype_id]) )
                    $datafield_array['by_datatype'][$target_datatype_id][] = 'dt_'.$target_datatype_id.'_metadata';
            }

            // For each datafield the user is searching on...
            foreach ($datafields as $df_id => $value) {
                if ( !isset($datafield_array['datatype_of'][$df_id]) ) {
                    /** @var DataFields $tmp_datafield */
                    $tmp_datafield = $repo_datafield->find($df_id);

                    if ( $tmp_datafield == null || $tmp_datafield->getSearchable() == 0 || $tmp_datafield->getDataType()->getId() !== $datatype->getId() )
                        return array('message' => "Invalid search query", 'encoded_search_key' => $encoded_search_key);
                    else if (!$logged_in)
                        return array('message' => "Permission denied...try logging in", 'encoded_search_key' => $encoded_search_key);
                    else
                        return array('message' => "Permission denied", 'encoded_search_key' => $encoded_search_key);
                }

                $dt_id = $datafield_array['datatype_of'][$df_id];
                if ( !( isset($user_permissions[$dt_id]) && isset($user_permissions[$dt_id]['view']) ) ) {
                    // ...if the user does not have view permissions for the datatype this datafield belongs to, enforce viewing of public datarecords only
                    $metadata[$dt_id] = array();    // always want to clear updated/created (by)
                    $metadata[$dt_id]['public'] = 1;

                    // Ensure that the intersection calculation is aware that this datatype is restricted to public datarecords
                    if ( !in_array('dt_'.$dt_id.'_metadata', $datafield_array['by_datatype'][$dt_id]) )
                        $datafield_array['by_datatype'][$dt_id][] = 'dt_'.$dt_id.'_metadata';
                }
            }

            // Not strictly necessary, but remove any duplicates from the array of datatypes being searched on
            $searched_datatypes = array_unique($searched_datatypes);

if ($debug) {
    print '$datafield_array: '.print_r($datafield_array, true)."\n";
    print '$metadata: '.print_r($metadata, true)."\n";
    print '$searched_datatypes: '.print_r($searched_datatypes, true)."\n";
    print '$initial_datatype_flags: '.print_r($initial_datatype_flags, true)."\n";
//exit();
}

if ($timing)
    print 'base arrays built in: '.((microtime(true) - $start_time)*1000)."ms \n";

            // --------------------------------------------------
            // Get all top-level datarecords of this datatype that could possibly match the desired search
            // ...has to be done this way so that when the user searches on criteria for both childtypes A and B, a top-level datarecord that only has either childtype A or childtype B won't match
            $allowed_grandparents = null;
            foreach ($searched_datatypes as $num => $dt_id) {
                // Don't restrict by top-level datatype here, and also don't restrict if the user isn't directly searching on a datafield of the datatype
                if ($dt_id == $target_datatype_id || $initial_datatype_flags[$dt_id] == 0)
                    continue;

                // Linked datarecords needs a different query to extract "grandparents", since we actually want the grandparent of the datarecord that is linking to this linked datarecord
                $sub_query = null;
                if ( in_array($dt_id, $related_datatypes['linked_datatypes']) ) {
                    $sub_query = $em->createQuery(
                       'SELECT grandparent.id AS id
                        FROM ODRAdminBundle:DataRecord AS ldr
                        JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldr = ldt.descendant
                        JOIN ODRAdminBundle:DataRecord AS dr WITH dr = ldt.ancestor
                        JOIN ODRAdminBundle:DataRecord AS grandparent WITH grandparent = dr.grandparent
                        WHERE ldr.dataType = :linked_datatype_id AND grandparent.dataType = :target_datatype_id
                        AND ldr.deletedAt IS NULL AND ldt.deletedAt IS NULL AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
                    )->setParameters( array('linked_datatype_id' => $dt_id, 'target_datatype_id' => $target_datatype_id) );
                }
                else {
                    $sub_query = $em->createQuery(
                       'SELECT grandparent.id AS id
                        FROM ODRAdminBundle:DataRecord AS dr
                        JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                        WHERE dr.dataType = :dt_id AND grandparent.dataType = :target_datatype_id
                        AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
                    )->setParameters(array('dt_id' => $dt_id, 'target_datatype_id' => $target_datatype_id));
                }
                $result = $sub_query->getArrayResult();

                // Flatten the array doctrine returns so array_intersect works properly
                $sub_result = array();
                foreach ($result as $tmp => $data)
                    $sub_result[] = $data['id'];
                $sub_result = array_unique($sub_result);

                // Intersect the flattened array with the array of currently allowed top-level datarecords
                if ($allowed_grandparents == null)
                    $allowed_grandparents = $sub_result;
                else
                    $allowed_grandparents = array_intersect($allowed_grandparents, $sub_result);
            }


            // Get every single child datarecord of each of the allowed top-level datarecords
            $parameters = array();
            $query_str =
               'SELECT dr.id AS dr_id, parent.id AS parent_id, dt.id AS datatype_id
                FROM ODRAdminBundle:DataRecord AS dr
                JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
                JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
                JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                WHERE grandparent.dataType = :target_datatype';
            $parameters['target_datatype'] = $target_datatype_id;

            // If there's some restriction on the top-level datarecords to return, splice that into the query
            if ( $allowed_grandparents !== null && count($allowed_grandparents) > 0 ) {
                $query_str .= ' AND grandparent.id IN (:allowed_grandparents)';
                $parameters['allowed_grandparents'] = $allowed_grandparents;
            }
            $query_str .= ' AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL AND grandparent.deletedAt IS NULL AND dt.deletedAt IS NULL';

            // Run the query to get all child datarecords
            $query = $em->createQuery($query_str)->setParameters($parameters);
            $child_datarecords = $query->getArrayResult();


            // Get every single linked datarecord of each of the allowed top-level datarecords
            $parameters = array();
            $query_str =
               'SELECT ldr.id AS dr_id, parent.id AS parent_id, ldr_dt.id AS datatype_id
                FROM ODRAdminBundle:DataRecord AS ldr
                JOIN ODRAdminBundle:DataType AS ldr_dt WITH ldr.dataType = ldr_dt
                JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = ldr
                JOIN ODRAdminBundle:DataRecord AS parent WITH ldt.ancestor = parent
                JOIN ODRAdminBundle:DataRecord AS grandparent WITH parent.grandparent = grandparent
                WHERE grandparent.dataType = :target_datatype';
            $parameters['target_datatype'] = $target_datatype_id;

            // If there's some restriction on the top-level datarecords to return, splice that into the query
            if ( $allowed_grandparents !== null && count($allowed_grandparents) > 0 ) {
                $query_str .= ' AND grandparent.id IN (:allowed_grandparents)';
                $parameters['allowed_grandparents'] = $allowed_grandparents;
            }
            $query_str .= ' AND ldr.deletedAt IS NULL AND ldr_dt.deletedAt IS NULL AND ldt.deletedAt IS NULL AND parent.deletedAt IS NULL AND grandparent.deletedAt IS NULL';

            // Run the query to get all linked datarecords
            $query = $em->createQuery($query_str)->setParameters($parameters);
            $linked_datarecords = $query->getArrayResult();


            // Merge all child and linked datarecords together into the same array
            $results = array_merge($child_datarecords, $linked_datarecords);


            // @see self::buildDatarecordTree() for structure of this array
            $descendants_of_datarecord = array();

            // Need to recursively enforce these logical rules for searching...
            // 1) A child datarecord of a child datatype that isn't being directly searched on is automatically included if its parent is included
            // 2) A datarecord of a datatype that is being directly searched on must match criteria to be included
            // 3) If none of the child datarecords of a given child datatype for a given parent datarecord match the search query, that parent datarecord must also be excluded

            // $matched_datarecords is an array where every single datarecord id listed in $descendants_of_datarecord points to the integers -1, 0, or 1
            // -1 denotes "does not match search, exclude", 0 denotes "not being searched on", and 1 denotes "matched search"
            // All datarecords of every datatype that are being searched on are initialized to -1...their contents must match the search for them to be included in the results.
            // During the very last phase of searching, $descendants_of_datarecords is recursively traversed...datarecords with a 0 or 1 are included in the final list of search results unless it would violate rule 3 above.
            $matched_datarecords = array();

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $parent_id = $result['parent_id'];
                $dt_id = $result['datatype_id'];

                // Keep track of which children this datarecord has
                if ($dr_id == $parent_id) {
                    // ...this is a top-level datarecord
                    if ( !isset($descendants_of_datarecord[0]) ) {
                        $descendants_of_datarecord[0] = array();
                        $descendants_of_datarecord[0][$target_datatype_id] = array();
                    }
                    $descendants_of_datarecord[0][$target_datatype_id][$dr_id] = '';
                }
                else {
                    // ...this is a some child or linked datarecord
                    if ( !isset($descendants_of_datarecord[$parent_id]) )
                        $descendants_of_datarecord[$parent_id] = array();
                    if ( !isset($descendants_of_datarecord[$parent_id][$dt_id]) )
                        $descendants_of_datarecord[$parent_id][$dt_id] = array();
                    $descendants_of_datarecord[$parent_id][$dt_id][$dr_id] = '';
                }

                // Apply the default inclusion flag to this datarecord, if specified
                $flag = 0;
                if ( isset($initial_datatype_flags[$dt_id]) )
                    $flag = $initial_datatype_flags[$dt_id];

                $matched_datarecords[$dr_id] = $flag;
            }

            // $descendants_of_datarecord array is currently partially flattened..."inflate" it into the true tree structure described above
            $matched_datarecords[0] = 0;
            $descendants_of_datarecord = array(0 => self::buildDatarecordTree($descendants_of_datarecord, 0));
/*
if ($more_debug) {
    print '$matched_datarecords: '.print_r($matched_datarecords, true)."\n";
    print '$descendants_of_datarecord: '.print_r($descendants_of_datarecord, true)."\n";
}
*/

if ($timing)
    print 'datarecord trees built in: '.((microtime(true) - $start_time)*1000)."ms \n";

            // --------------------------------------------------
            // General search
            $basic_results = array();
            if ($general_string !== null) {

                // Assume user wants exact search...
                $general_search_params = self::parseField($general_string, $more_debug);
if ($more_debug)
    print '$general_search_params: '.print_r($general_search_params, true)."\n";

                foreach ($search_entities as $typeclass) {
                    // Grab list of datafields to search with this typeclass
                    if ( !isset($datafield_array['by_typeclass'][$typeclass]) )
                        continue;

                    $by_typeclass = $datafield_array['by_typeclass'][$typeclass];
                    foreach ($by_typeclass as $datatype_id => $datafield_list) {
                        // Don't apply a general search to datafields from linked datatypes
                        if ( in_array($datatype_id, $related_datatypes['linked_datatypes']) )
                            continue;

                        $results = array();
                        if ($typeclass == 'Radio') {
                            // Radio requires a different set of parameters
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
                            $results = self::runSearchQuery($em, $datafield_list, $typeclass, $datatype_id, $radio_search_params, $related_datatypes, $metadata, $more_debug);
                        }
                        else {
                            // Run the query for most of the typeclasses
                            $results = self::runSearchQuery($em, $datafield_list, $typeclass, $datatype_id, $general_search_params, $related_datatypes, $metadata, $more_debug);
                        }

                        // Save the results
                        foreach ($results as $dr_id => $grandparent_id) {
                            if ( !isset($basic_results[$dr_id]) )
                                $basic_results[$dr_id] = $grandparent_id;
                        }

                        // Save that metadata was applied to this datatype
                        $metadata[$datatype_id]['searched'] = 1;
                    }
                }
            }

if ($debug) {
    print '----------'."\n";
    print '$basic_results: '.print_r($basic_results, true)."\n";
//exit();
}

if ($timing)
    print 'general search results found in: '.((microtime(true) - $start_time)*1000)."ms \n";

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
                        $search_params = self::parseField($search_string, $more_debug);
//print_r($search_params);
                    }

                    // Run the query and save the results
                    $datafields = array( $datafield_id );
                    $typeclass = $datafield_array['by_id'][$datafield_id];
                    $results = self::runSearchQuery($em, $datafields, $typeclass, $datatype_id, $search_params, $related_datatypes, $metadata, $more_debug);

                    $using_adv_search = true;
                    $adv_results[$datafield_id] = $results;

                    // Save that metadata was applied to this datatype
                    $metadata[$datatype_id]['searched'] = 1;
                }

if ($more_debug)
    print 'after general/advanced searches: '.print_r($metadata, true)."\n";

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
                            $metadata_target = 'grandparent';
                            $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], $metadata_target);
                            $where = 'WHERE dr.data_type_id = '.$datatype_id.' ';
                        }
                        else if ( isset($related_datatypes['child_datatypes'][ $datatype_id ]) ) {
                            $metadata_target = 'dr';
                            $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], $metadata_target);
                            $where = 'WHERE dr.data_type_id = '.$datatype_id.' ';
                        }
                        else if ( in_array($datatype_id, $related_datatypes['linked_datatypes']) ) {
                            $metadata_target = 'ldr';
                            $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], $metadata_target);
                            $linked_join = 'INNER JOIN odr_linked_data_tree AS ldt ON dr.id = ldt.ancestor_id
                                INNER JOIN odr_data_record AS ldr ON ldt.descendant_id = ldr.id';
                            $where = 'WHERE ldr.deletedAt IS NULL AND ldr.data_type_id = '.$datatype_id.' AND dr.data_type_id = '.$related_datatypes['target_datatype'].' ';    // TODO - links to childtypes?
                        }

                        //
                        $metadata_str = $search_metadata['metadata_str'].' GROUP BY grandparent.id';
                        $parameters = $search_metadata['metadata_params'];

                        $query =
                           'SELECT dr.id AS dr_id, grandparent.id AS grandparent_id
                            FROM odr_data_record AS grandparent
                            INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
                            '.$linked_join.'
                            '.$where.' AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL '.$metadata_str;

if ($more_debug) {
    print $query."\n";
    print '$parameters: '.print_r($parameters, true)."\n";
}

                        // ----------------------------------------
                        // Execute and return the native SQL query
                        $conn = $em->getConnection();
                        $results = $conn->fetchAll($query, $parameters);

if ($more_debug) {
    print '>> '.print_r($results, true)."\n";
}

                        // Save the query result so the search results are correctly restricted
                        $using_adv_search = true;
                        foreach ($results as $result) {
                            $dr_id = $result['dr_id'];
                            $grandparent_id = $result['grandparent_id'];

                            if ( !isset($adv_results['dt_'.$datatype_id.'_metadata']) )
                                $adv_results['dt_'.$datatype_id.'_metadata'] = array();
                            $adv_results['dt_'.$datatype_id.'_metadata'][$dr_id] = $grandparent_id;
                        }
                    }
                }
            }

if ($debug) {
    print '----------'."\n";
    print '$adv_results: '.print_r($adv_results, true)."\n";
//exit();
}

if ($timing)
    print 'adv search/metadata results gathered in: '.((microtime(true) - $start_time)*1000)."ms \n";


            // ----------------------------------------
            // Now, need to combine both $basic_results and $adv_results into a list of datarecord ids that matched the search...

            // $adv_results stores all results for all datafields on the same level for simplicity...but intersections need to be performed only within a datatype
            foreach ($datafield_array['by_datatype'] as $dt_id => $tmp) {
                // Use $intersection to temporarily hold...
                $intersection = array();

                // Check for each possible piece of data that could have been searched on
                foreach ($tmp as $num => $df_id) {
                    // Don't bother if the datafield/metadata wasn't searched on
                    if ( !isset($adv_results[$df_id]) )
                        continue;

                    // If no datarecords matched the search, do nothing
                    if ( !is_array($intersection) || count($adv_results[$df_id]) == 0 ) {
                        $intersection = false;
                    }
                    else if ( count($intersection) == 0 ) {
                        // ...if nothing has been stored in $intersection yet, store this
                        $intersection = $adv_results[$df_id];
                    }
                    else {
                        // ...otherwise, remove datarecords from $intersection that aren't in $adv_results[$df_id]
                        foreach ($intersection as $dr_id => $gp_id) {
                            if ( !isset($adv_results[$df_id][$dr_id]) )
                                unset( $intersection[$dr_id] );
                        }
                    }
                }

                // If there was a general search string, remove datarecords from $intersection that aren't in $basic_results
                if ($general_string !== null) {
                    foreach ($intersection as $dr_id => $gp_id)
                        if ( !isset($basic_results[$dr_id]) )
                            unset( $intersection[$dr_id] );
                }

                // ...now that $intersection has the correct set of datarecords of this datatype that matched the search, update $matched_datarecords with the results
                if ( is_array($intersection) && count($intersection) > 0 ) {
if ($debug)
    print '$intersection of dt '.$dt_id.': '.print_r($intersection, true)."\n";

                    // Mark each of the datarecords left after the intersections as matching the search
                    foreach ($intersection as $dr_id => $gp_id)
                        $matched_datarecords[$dr_id] = 1;
                }
            }

if ($timing)
    print 'search results intersection calculated in: '.((microtime(true) - $start_time)*1000)."ms \n";

            // Deal with the case where there's only a general search string
            if ( $general_string !== null && count($basic_results) > 0 && count($adv_results) == 0 ) {
                foreach ($basic_results as $dr_id => $gp_id) {
                    // All datarecords in $basic_results match the general search string by definition
                    $matched_datarecords[$dr_id] = 1;

                    // All of their grandparents are also considered to match by definition
                    $matched_datarecords[$gp_id] = 1;
                }
            }


            // ----------------------------------------
            // Compute the final list of datarecords/grandparent datarecords...
            $datarecords = '';
            $grandparents = '';

            if ( $general_string === null && count($searched_datafields) == 0 && count($metadata) == 0 ) {
                // Ensure that datarecord_id 0 doesn't get in the final search results...
                unset( $matched_datarecords[0] );

                // In the case when there was nothing entered in the search at all...every possible datarecord satisfies the search
                foreach ($matched_datarecords as $dr_id => $flag)
                    $datarecords .= $dr_id.',';
                $datarecords = substr($datarecords, 0, -1);

                $grandparents = $odrcc->getSortedDatarecords($datatype);
            }
            else {
                // In the case where something was searched on...

if ($more_debug) {
    print '$matched_datarecords: '.print_r($matched_datarecords, true)."\n";
    print '$descendants_of_datarecord: '.print_r($descendants_of_datarecord, true)."\n";
}

                // Build the final list of datarecords/grandparent datarecords matched by the query
                foreach ($descendants_of_datarecord[0] as $dt_id => $top_level_datarecords) {
                    foreach ($top_level_datarecords as $gp_id => $tmp) {
                        $results = self::getFinalSearchResults($matched_datarecords, $descendants_of_datarecord[0][$dt_id], $gp_id);

                        $datarecords .= $results;
                        if ($results !== '')
                            $grandparents .= $gp_id.',';
                    }
                }

                // Remove trailing commas
                if (strpos($datarecords, ',') !== false)
                    $datarecords = substr($datarecords, 0, -1);
                if (strpos($grandparents, ',') !== false) {
                    $grandparents = substr($grandparents, 0, -1);
                    $grandparents = $odrcc->getSortedDatarecords($datatype, $grandparents);
                }
            }

            // Get rid of any duplicates in the datarecord list...linked datarecords should theoretically be the only ones, but don't want any duplicates
            $datarecords = explode(',', $datarecords);
            $datarecords = array_unique($datarecords);
            $datarecords = implode(',', $datarecords);

if ($debug) {
    print '----------'."\n";
    print '----------'."\n";
    print '$datarecords: '.$datarecords."\n";
    print '$grandparents: '.$grandparents."\n";

    if ($grandparents == '')
        print 'count($grandparents): 0'."\n";
    else
        print 'count($grandparents): '.(substr_count($grandparents,',')+1)."\n";

    print '----------'."\n";
//    exit();
}

if ($timing)
    print 'final datarecord lists calculated in: '.((microtime(true) - $start_time)*1000)."ms \n";


            // --------------------------------------------------
            // Store the list of datarecord ids for later use
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $search_checksum = md5($search_key);
            $datatype_id = $datatype->getId();
            $searched_datafields = implode(',', $searched_datafields);

            $cached_searches = $memcached->get($memcached_prefix.'.cached_search_results');

if ($debug || $more_debug || $timing)
    print '</pre>';

if ($timing) {
    print 'total execution time: '.((microtime(true) - $start_time)*1000)."ms \n";
    exit();
}

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
                $cached_searches[$datatype_id][$search_checksum]['logged_in'] = array('complete_datarecord_list' => $datarecords, 'datarecord_list' => $grandparents);
            else
                $cached_searches[$datatype_id][$search_checksum]['not_logged_in'] = array('complete_datarecord_list' => $datarecords, 'datarecord_list' => $grandparents);

            $memcached->set($memcached_prefix.'.cached_search_results', $cached_searches, 0);

if ($more_debug) {
//    print 'saving datarecord_str: '.$datarecords."\n";
    print_r($cached_searches);
}
        }

        return true;
    }


    /**
     * Turns the originally flattened $descendants_of_datarecord array into a recursive tree structure of the form...
     *
     * parent_datarecord_id => array(
     *     child_datatype_1_id => array(
     *         child_datarecord_1_id of child_datatype_1 => '',
     *         child_datarecord_2_id of child_datatype_1 => '',
     *         ...
     *     ),
     *     child_datatype_2_id => array(
     *         child_datarecord_1_id of child_datatype_2 => '',
     *         child_datarecord_2_id of child_datatype_2 => '',
     *         ...
     *     ),
     *     ...
     * )
     *
     * If child_datarecord_X_id has children of its own, then it is also a parent datarecord, and it points to another recursive tree structure of this type instead of an empty string.
     * Linked datatypes/datarecords are handled identically to child datatypes/datarecords.
     *
     * The tree's root looks like...
     *
     * 0 => array(
     *     target_datatype_id => array(
     *         top_level_datarecord_1_id => ...
     *         top_level_datarecord_2_id => ...
     *         ...
     *     )
     * )
     *
     * @param array $descendants_of_datarecord
     * @param string|integer $current_datarecord_id
     *
     * @return array
     */
    private function buildDatarecordTree($descendants_of_datarecord, $current_datarecord_id)
    {
        if ( !isset($descendants_of_datarecord[$current_datarecord_id]) ) {
            // $current_datarecord_id has no children
            return '';
        }
        else {
            // $current_datarecord_id has children
            $result = array();

            // For every child datatype this datarecord has...
            foreach ($descendants_of_datarecord[$current_datarecord_id] as $dt_id => $datarecords) {
                // For every child datarecord of this child datatype...
                foreach ($datarecords as $dr_id => $tmp) {
                    // ...get all children of this child datarecord and store them
                    $result[$dt_id][$dr_id] = self::buildDatarecordTree($descendants_of_datarecord, $dr_id);
                }
            }

            return $result;
        }
    }


    /**
     * Recursively traverses the datarecord tree for all datarecords if the datatype being searched on, and returns a comma-separated
     *  list of all child/linked/top-level datarecords that effectively match the search query
     *
     * @param array $matched_datarecords
     * @param array $descendants_of_datarecord
     * @param string|integer $current_datarecord_id
     *
     * @return string
     */
    private function getFinalSearchResults($matched_datarecords, $descendants_of_datarecord, $current_datarecord_id)
    {
        // If this datarecord is excluded from the search results for some reason, don't bother checking any child datarecords...they're also excluded by definition
        if ( $matched_datarecords[$current_datarecord_id] == -1 ) {
            return '';
        }
        // If this datarecord has children...
        else if ( is_array($descendants_of_datarecord[$current_datarecord_id]) ) {
            // ...keep track of whether each child datarecord matched the search
            $datarecords = '';

            foreach ($descendants_of_datarecord[$current_datarecord_id] as $dt_id => $child_datarecords) {

                $dr_matches = '';
                foreach ($child_datarecords as $dr_id => $tmp)
                    $dr_matches .= self::getFinalSearchResults($matched_datarecords, $descendants_of_datarecord[$current_datarecord_id][$dt_id], $dr_id);

                if ($dr_matches == '') {
                    // None of the child datarecords of this datatype matched the search...therefore the parent datarecord doesn't match either
                    return '';
                }
                else {
                    // Save the child datarecords that matched this search
                    $datarecords .= $dr_matches;
                }
            }

            // ...otherwise, return this datarecord and all of the child datarecords that matched
            return $current_datarecord_id.','.$datarecords;
        }
        else {
            // ...otherwise, this datarecord has no children, and either matches the search or is not otherwise excluded
            return $current_datarecord_id.',';
        }
    }


    /**
     * Given a set of search parameters, runs a search on the given datafields, and returns the grandparent id of all datarecords that match the query
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datafield_list            An array of datafield ids...must all be of the same typeclass
     * @param integer $datatype_id             The datatype that the datafields in $datafield_list belong to
     * @param string $typeclass                The typeclass of every datafield in $datafield_list
     * @param array $search_params     
     * @param array $related_datatypes         @see self::getRelatedDatatypes()
     * @param array $metadata
     * @param boolean $debug
     *
     * @return array TODO
     */
    private function runSearchQuery($em, $datafield_list, $typeclass, $datatype_id, $search_params, $related_datatypes, $metadata, $debug)
    {
//$debug = true;
//$debug = false;

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
            // Searching from a linked datatype also requires different metadata
            if ( isset($metadata[$datatype_id]) ) {
                $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], 'dr');

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
               'SELECT dr.id AS dr_id, grandparent.id AS grandparent_id
                FROM odr_data_record AS grandparent
                INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
                '.$drf_join.'
                INNER JOIN odr_radio_selection AS rs ON rs.data_record_fields_id = drf.id
                INNER JOIN odr_radio_options AS ro ON rs.radio_option_id = ro.id
                WHERE drf.data_field_id IN ('.$datafields.') AND ('.$search_params['str'].')
                AND grandparent.deletedAt IS NULL AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL ';

            $query .= $metadata_str;
        }
        else if ($typeclass == 'Image' || $typeclass == 'File') {
            // Build the native SQL query that will check for (non)existence of files/images in this datafield
            $query =
               'SELECT dr.id AS dr_id, grandparent.id AS grandparent_id
                FROM odr_data_record AS grandparent
                INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
                '.$drf_join.'
                LEFT JOIN '.$table_names[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE drf.data_field_id IN ('.$datafields.') AND ('.$search_params['str'].')
                AND grandparent.deletedAt IS NULL AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL ';

            $query .= $metadata_str;
        }
        else {
            // Build the native SQL query that will check content of any other datafields
            $query =
               'SELECT dr.id AS dr_id, grandparent.id AS grandparent_id
                FROM odr_data_record AS grandparent
                INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
                '.$drf_join.'
                INNER JOIN '.$table_names[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE '.$datafield_str.' AND ('.$search_params['str'].')
                AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL ';

            $query .= $metadata_str;
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

        $datarecords = array();
        foreach ($results as $result)
            $datarecords[ $result['dr_id'] ] = $result['grandparent_id'];

        return $datarecords;
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
                            if ( $i != 0 && $str[$i-1] == ' ' && ($str[$i+1] == 'R' || $str[$i+1] == 'r') && $str[$i+2] == ' ' ) {
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

