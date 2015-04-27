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
use ODR\AdminBundle\Entity\RadioOption;
use ODR\AdminBundle\Entity\RadioSelection;
// Forms
//use ODR\AdminBundle\Form\CheckboxForm;
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\ShortVarcharForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
     * @return TODO
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
     * Renders the base page for searching purposes when not logged in.
     * 
     * @param String $search_slug   Which datatype to load a search page for.
     * @param String $search_string An optional string to immediately enter into the general search field and search with.
     * @param Request $request
     * 
     * @return a Symfony HTML response containing
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
            $user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();

            // Store if logged in or not
            $logged_in = true;
            if ($user === 'anon.') {
                $user = null;
                $logged_in = false;
            }
            else {
                // Grab user permissions
                $user_permissions = $odrcc->getPermissionsArray($user->getId(), $request);
            }
            // ------------------------------

            // Check if user has permission to view datatype
            $target_datatype_id = $target_datatype->getId();
            if ( !$target_datatype->isPublic() && !(isset($user_permissions[ $target_datatype_id ]) && isset($user_permissions[ $target_datatype_id ][ 'view' ])) )
//                return $odrcc->permissionDeniedError('search');
                return self::searchPageError("You don't have permission to access this DataType.", 403);

            // Need to grab all searchable datafields for the target_datatype and its descendants

            // Grab all searchable datafields belonging to non-deleted datatypes
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields df
                JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                WHERE df.searchable > 0
                AND df.deletedAt IS NULL AND dt.deletedAt IS NULL');
            $results = $query->getResult();

            $datafields = array();
            foreach ($results as $num => $result)
                $datafields[] = $result;

            // Locate the IDs of all datatypes that are descended from another datatype
            $datatrees = $repo_datatree->findAll();
            $ancestor_of = array();
            foreach ($datatrees as $datatree) {
                if ($datatree->getIsLink() == 0)
                    $ancestor_of[ $datatree->getDescendant()->getId() ] = $datatree->getAncestor()->getId();
            }

            // Only save the searchable datafields
            $has_searchable_fields = array();
            $searchable_datafields = array();
            foreach ($datafields as $df) {
                // If user it not logged on, or they don't have view permission...ignore datafields that can't be searched by everybody
                if ( $user == null && $df->getUserOnlySearch() == 1 )
                    continue;
                if ( $df->getUserOnlySearch() == 1 && !(isset($user_permissions[ $target_datatype_id ]) && isset($user_permissions[ $target_datatype_id ]['view'])) )
                    continue;

                // Locate the highest-level parent of the datatype this datafield belongs to
                $datatype_id = $df->getDatatype()->getId();
                while ( isset($ancestor_of[$datatype_id]) )
                    $datatype_id = $ancestor_of[$datatype_id];

                $has_searchable_fields[$datatype_id] = 1;

                // If this datafield isn't a descendant of the target datatype, ignore it
                if ( $target_datatype->getId() !== $datatype_id )
                    continue;

                // Otherwise, store the datafield
                $searchable_datafields[] = $df;
            }

            // Grab datatypes linked to this target datatype
            $linked_datatypes = array();
            $query = $em->createQuery(
               'SELECT ancestor.id AS id, ancestor.shortName AS short_name, ancestor.searchSlug AS abbreviation
                FROM ODRAdminBundle:DataTree AS dt
                JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
                WHERE dt.is_link = 1 AND dt.descendant = :datatype
                AND dt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
            )->setParameters( array('datatype' => $target_datatype->getId()) );
            $results = $query->getResult();
            foreach ($results as $result) {
                $id = $result['id'];
                $short_name = $result['short_name'];
                $abbreviation = $result['abbreviation'];

                if ( isset($has_searchable_fields[$id]) && $abbreviation != null )
                    $linked_datatypes[$short_name] = $abbreviation;
            }
            $query = $em->createQuery(
               'SELECT descendant.id AS id, descendant.shortName AS short_name, descendant.searchSlug AS abbreviation
                FROM ODRAdminBundle:DataTree AS dt
                JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
                WHERE dt.is_link = 1 AND dt.ancestor = :datatype
                AND dt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('datatype' => $target_datatype->getId()) );
            $results = $query->getResult();
            foreach ($results as $result) {
                $id = $result['id'];
                $short_name = $result['short_name'];
                $abbreviation = $result['abbreviation'];

                if ( isset($has_searchable_fields[$id]) && $abbreviation != null )
                    $linked_datatypes[$short_name] = $abbreviation;
            }

            // Grab background image?
            $background_image_id = null;
            if ( $target_datatype !== null && $target_datatype->getBackgroundImageField() !== null ) {
                $query = $em->createQuery(
                   'SELECT image.id
                    FROM ODRAdminBundle:Image image
                    WHERE image.original = 1 AND image.deletedAt IS NULL 
                    AND image.dataField = :datafield AND image.publicDate NOT LIKE :date'   // TODO - more conditions?
                )->setParameters( array('datafield' => $target_datatype->getBackgroundImageField(), 'date' => '2200-01-01 00:00:00' ) );
                $results = $query->getResult();

                if ( count($results) > 0 ) {
                    $index = rand(0, count($results)-1);
                    $background_image_id = $results[$index]['id'];
                }
            }

            // Generate a random key to identify this search
//            $tokenGenerator = $this->container->get('fos_user.util.token_generator');
//            $search_key = substr($tokenGenerator->generateToken(), 0, 15);

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            $user_list = $user_manager->findUsers();

            // Render just the html for the base page and the search page...$this->render() apparently creates a full Response object
            $site_baseurl = $this->container->getParameter('site_baseurl');
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $site_baseurl .= '/app_dev.php';

            $html = $this->renderView(
                'ODROpenRepositorySearchBundle:Default:index.html.twig',
                array(
                    // required twig/javascript parameters
                    'user' => $user,
                    'user_permissions' => $user_permissions,

                    'user_list' => $user_list,
                    'logged_in' => $logged_in,
                    'window_title' => $target_datatype->getShortName(),
                    'source' => 'searching',
//                    'search_key' => $search_key,
                    'search_slug' => $search_slug,
                    'site_baseurl' => $site_baseurl,
                    'search_string' => $search_string,

                    // required for background image
                    'background_image_id' => $background_image_id,

                    // datatype/datafields to search
                    'linked_datatypes' => $linked_datatypes,
                    'datatypes' => array( $target_datatype->getId() => $target_datatype ),
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
     * @return a Symfony JSON response containing HTML
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

            // Need to grab all searchable datafields for the target_datatype and its descendants
            $target_datatype = $repo_datatype->find($target_datatype_id);
            // TODO - error on deleted datatype?

            // Grab all searchable datafields belonging to non-deleted datatypes
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields df
                JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                WHERE df.searchable > 0
                AND df.deletedAt IS NULL AND dt.deletedAt IS NULL');
            $results = $query->getResult();

            $datafields = array();
            foreach ($results as $num => $result)
                $datafields[] = $result;

            // Locate the IDs of all datatypes that are descended from another datatype
            $datatrees = $repo_datatree->findAll();
            $ancestor_of = array();
            foreach ($datatrees as $datatree) {
                if ($datatree->getIsLink() == 0)
                    $ancestor_of[ $datatree->getDescendant()->getId() ] = $datatree->getAncestor()->getId();
            }

            // Only save the searchable datafields
            $searchable_datafields = array();
            foreach ($datafields as $df) {
                // If user is not logged on, ignore datafields that can't be searched by everybody
//                if ( $user == null && $df->getUserOnlySearch() == 1 )
//                    continue;

                // Locate the highest-level parent of the datatype this datafield belongs to
                $datatype_id = $df->getDatatype()->getId();
                while ( isset($ancestor_of[$datatype_id]) )
                    $datatype_id = $ancestor_of[$datatype_id];

                // If this datafield isn't a descendant of the target datatype, ignore it
                if ( $target_datatype->getId() !== $datatype_id )
                    continue;

                // Otherwise, store the datafield
                $searchable_datafields[] = $df;
            }

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            $user_list = $user_manager->findUsers();

            // Generate a random key to identify this search
//            $tokenGenerator = $this->container->get('fos_user.util.token_generator');
//            $search_key = substr($tokenGenerator->generateToken(), 0, 15);

            // This should always be true?
            $logged_in = true;

            // Render the template
            $site_baseurl = $this->container->getParameter('site_baseurl');
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                  'ODROpenRepositorySearchBundle:Default:search.html.twig',
                    array(
                        // required twig/javascript parameters
//                        'user' => $user,
                        'user_list' => $user_list,
                        'logged_in' => $logged_in,
//                        'window_title' => $target_datatype->getShortName(),
                        'source' => 'linking',
//                        'search_key' => $search_key,
//                        'search_slug' => $search_slug,
                        'site_baseurl' => $site_baseurl,

                        // required for background image
//                        'background_image_id' => $background_image_id,
                        'background_image_id' => null,

                        // datatype/datafields to search
//                        'linked_datatypes' => $linked_datatypes,
                        'linked_datatypes' => null,
                        'datatypes' => array( $target_datatype->getId() => $target_datatype ),
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
     * @return TODO
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
            $theme = $repo_theme->find(2);

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
            // --------------------


            // -----------------------------------
            // Attempt to load the search results (for this user and/or search string?) from the cache
            // TODO - this block of code is effectively duplicated multiple times
            $datarecords = array();
            $search_results = null;

            if ( !$session->has('saved_searches') ) {
                // no saved searches at all for some reason, redo the search with the given search key...
                self::performSearch($search_key, $request);
            }

            // Grab the list of saved searches
            $saved_searches = $session->get('saved_searches');
            $search_checksum = md5($search_key);

            if ( !isset($saved_searches[$search_checksum]) ) {
                // no saved search for this query, redo the search...
                self::performSearch($search_key, $request);
                $saved_searches = $session->get('saved_searches');
            }

            // Grab whether user was logged in at the time this search was performed
            $search_params = $saved_searches[$search_checksum];
            $was_logged_in = $search_params['logged_in'];

            // If user's login status changed between now and when the search was run...
            if ($was_logged_in !== $logged_in) {
                // ...run the search again 
                self::performSearch($search_key, $request);
                $saved_searches = $session->get('saved_searches');
                $search_params = $saved_searches[$search_checksum];
            }

            // Now that the search is guaranteed to exist and be correct...get all pieces of info about the search
            $datatype = $repo_datatype->find( $search_params['datatype'] );
            $search_results = $search_params['datarecords'];
            $encoded_search_key = $search_params['encoded_search_key'];


            // Turn the search results string into an array of datarecord ids
            $datarecords = array();
            if ( trim($search_results) !== '') {
                $search_results = explode(',', trim($search_results));
                foreach ($search_results as $id)
                    $datarecords[] = $id;
            }

            // -----------------------------------
            // $datarecords now contains the ids of datarecords that match this search
            // However, need to ensure that there are no deleted datarecords in this list...
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.id IN (:datarecords) AND dr.deletedAt IS NULL'
            )->setParameters( array('datarecords' => $datarecords) );
            $results = $query->getArrayResult();

            if ( count($results) < count($datarecords) ) {
                // At least one of the datarecords in this search result list got deleted...

                // Build a list of the undeleted datarecords
                $datarecords = array();
                foreach ($results as $num => $result)
                    $datarecords[] = $result['dr_id'];
                $datarecord_str = implode(',', $datarecords);

                // Sort the string of undeleted datarecords
                if ($datarecord_str !== '') 
                    $datarecord_str = $odrcc->getSortedDatarecords($datatype, $datarecord_str);

                // Store the updated datarecord string in the session
                $saved_searches[$search_checksum] = array('logged_in' => $logged_in, 'datatype' => $datatype->getId(), 'datarecords' => $datarecord_str, 'encoded_search_key' => $encoded_search_key);
                $session->set('saved_searches', $saved_searches);

                // Convert the string of undeleted datarecords back to array format for use below
                $datarecords = explode(',', $datarecord_str);
            }


            // -----------------------------------
            // Bypass list entirely if only one datarecord
            $displayed_datarecords = count($datarecords);
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
     * @return TODO
     */
    public function searchAction($search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $return = self::performSearch($search_key, $request);
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
     * @return TODO
     */
    public function performSearch($search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

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
//            $get = explode('|', $search_key);
$get = preg_split("/\|(?![\|\s])/", $search_key);
            foreach ($get as $key => $value) {
                $tmp = explode('=', $value);
                $key = $tmp[0];
                $value = $tmp[1];

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
        
//                if ( strpos($value, ',') !== false ) {
                if ( $is_radio ) {
                    // Multiple selected checkbox/radio items...
                    $values = explode(',', $value);
                    $post['datafields'][$key] = $values;

                    $encoded_search_key .= $key.'='.$value.'|';
                }
                else if ( strpos($key, '_start') !== false || strpos($key, '_end') !== false ) {
                    // Fields involving dates...
                    $keys = explode('_', $key);
                    $df_id = $keys[0];
                    $pos = $keys[1];

                    if ( !is_numeric($df_id) ) {
                        // Create/Modify fields
                        $post[$key] = $value;
                    }
                    else {
                        // Regular DateTime fields
                        if ( !isset($post['datafields'][$df_id]) )
                            $post['datafields'][$df_id] = array();
                    
                        $post['datafields'][$df_id][$pos] = $value;
                    }

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

            $datatype_id = $post['datatype_id'];
            $datatype = $repo_datatype->find($datatype_id);
            $session->set('prev_searched_datatype_id', $datatype_id);


            $datafields = null;
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];
            $general_string = null;
            if ( isset($post['general_string']) )
                $general_string = trim($post['general_string']);

            $modify_date_start = null;
            if ( isset($post['modify_date_start']) )
                $modify_date_start = $post['modify_date_start'];
            $modify_date_end = null;
            if ( isset($post['modify_date_end']) )
                $modify_date_end = $post['modify_date_end'];
            $modified_by = null;
            if ( isset($post['modified_by']) )
                $modified_by = $post['modified_by'];

            $create_date_start = null;
            if ( isset($post['create_date_start']) )
                $create_date_start = $post['create_date_start'];
            $create_date_end = null;
            if ( isset($post['create_date_end']) )
                $create_date_end = $post['create_date_end'];
            $created_by = null;
            if ( isset($post['created_by']) )
                $created_by = $post['created_by'];

            $public = null;
            if ( isset($post['public']) )
                $public = $post['public'];


            // Ignore datafields that don't belong to this datatype
            if ($datafields !== null) {
                foreach ($datafields as $id => $str) {
                    if ( !is_array($str) && trim($str) === '' )
                        unset( $datafields[$id] );
                }

                if ( count($datafields) == 0 )
                    $datafields = null;
            }


            // --------------------------------------------------
            // Determine level of user's permissions...
            $user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $logged_in = false;
            $has_view_permission = false;
            $search_restriction = 0;    // don't search datafields restricted to users-only
            if ($user !== null && $user !== 'anon.') {
                $logged_in = true;
                $search_restriction = 1;    // search all datafields 

                // Grab user's permissions
                $user_permissions = $odrcc->getPermissionsArray($user->getId(), $request);

                // Check to see if user has view permissions
                if ( isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'view' ]) )
                    $has_view_permission = true;
            }

if ($debug) {
    print 'logged_in: '.$logged_in."\n";
    print 'has_view_permission: '.$has_view_permission."\n";
}
            $using_adv_search = false;
            $basic_search_datarecords = array();
            $adv_search_datarecords = array();


            // --------------------------------------------------
            // Advanced search...individual datafields and metadata
            $using_metadata = false;
            $query_str =
               'SELECT grandparent.id
                FROM ODRAdminBundle:DataRecord AS grandparent
                WHERE grandparent.dataType = :datatype AND grandparent.deletedAt IS NULL';
            $parameters = array('datatype' => $datatype->getId());

            // If user doesn't have view permissions (either because they're not logged in, or they're set to not have view permissions), only return public datarecords
            if ( !$has_view_permission ) {
                $using_metadata = true;
                $query_str .= ' AND grandparent.publicDate < :current_date';
                $parameters = array_merge( $parameters, array('current_date' => new \DateTime()) );
            }

            // Search by when the datarecord was last modified
            if ($modify_date_start !== null || $modify_date_end !== null) {
                if ($modify_date_start == null)
                    $modify_date_start = '1980-01-01 00:00:00';
                if ($modify_date_end == null)
                    $modify_date_end = '2200-01-01 00:00:00';
                else {
                    // Selecting a modify date start of, say, 2015-04-26 and a modify date end of 2015-04-28...gives the impression that the search will return everything created between the "26th" and the "28th", inclusive.
                    // However, to actually include results from the "28th", the end date needs to be changed to 2015-04-29...therefore, increment the end date by 1 day so search results match human expectations
                    $modify_date_end = new \DateTime($modify_date_end);

                    $modify_date_end->add(new \DateInterval('P1D'));
                    $modify_date_end = $modify_date_end->format('Y-m-d H:i:s');

if ($debug)
    print 'changing $modify_date_end to '.$modify_date_end."\n";
                }

                $using_metadata = true;
                $query_str .= ' AND grandparent.updated BETWEEN :modified_date_start AND :modified_date_end';
                $parameters = array_merge( $parameters, array('modified_date_start' => $modify_date_start, 'modified_date_end' => $modify_date_end) );
            }

            // Search by when the datarecord was created
            if ($create_date_start !== null || $create_date_end !== null) {
                if ($create_date_start == null)
                    $create_date_start = '1980-01-01 00:00:00';
                if ($create_date_end == null)
                    $create_date_end = '2200-01-01 00:00:00';
                else {
                    // Selecting a create date start of, say, 2015-04-26 and a create date end of 2015-04-28...gives the impression that the search will return everything modified between the "26th" and the "28th", inclusive.
                    // However, to actually include results from the "28th", the end date needs to be changed to 2015-04-29...therefore, increment the end date by 1 day so search results match human expectations
                    $create_date_end = new \DateTime($create_date_end);

                    $create_date_end->add(new \DateInterval('P1D'));
                    $create_date_end = $create_date_end->format('Y-m-d H:i:s');

if ($debug)
    print 'changing $create_date_end to '.$create_date_end."\n";
                }

                $using_metadata = true;
                $query_str .= ' AND grandparent.created BETWEEN :created_date_start AND :created_date_end';
                $parameters = array_merge( $parameters, array('created_date_start' => $create_date_start, 'created_date_end' => $create_date_end) );
            }

            // Search by who last modified the datarecord
            if ($modified_by !== null && $modified_by != -1) {
                $using_metadata = true;
                $query_str .= ' AND grandparent.updatedBy = :modified_by';
                $parameters = array_merge( $parameters, array('modified_by' => $modified_by) );
            }

            // Search by who created the datarecord
            if ($created_by !== null && $created_by != -1) {
                $using_metadata = true;
                $query_str .= ' AND grandparent.createdBy = :created_by';
                $parameters = array_merge( $parameters, array('created_by' => $created_by) );
            }

            // Search by public status of datarecord
            if ($public != null) {
                $using_metadata = true;
                $parameters = array_merge( $parameters, array('public_date' => new \DateTime('2200-01-01 00:00:00')) );

                if ($public == 0) {
                    // Locate non-public datarecords
                    $query_str .= ' AND grandparent.publicDate = :public_date';
                }
                else if ($public == 1) {
                    // Locate public datarecords
                    $query_str .= ' AND grandparent.publicDate != :public_date';
                }
            }

            $adv_results = array();
            if ($using_metadata) {
                $using_adv_search = true;

                $query = $em->createQuery( $query_str )->setParameters( $parameters );
                $adv_results['metadata'] = $query->getResult();
            }
            $parameters = null;


            // --------------------------------------------------
            // Check each of the datafields
            if ($datafields !== null) {
                foreach ($datafields as $datafield_id => $search_string) {
                    // Skip db queries for empty searches
                    $adv_results[$datafield_id] = 'any';
                    if ( !is_array($search_string) && trim($search_string) === '')
                        continue;

                    // Otherwise, attempt to locate datarecords matching the search string for this datafield
                    $datafield = $repo_datafield->find($datafield_id);
                    $typeclass = $datafield->getFieldType()->getTypeClass();

                    $query = null;
                    if ($typeclass == 'Radio') {
                        // Single/Multiple Select/Radio...

                        // Convert Single Select/Radio to array so implode works regardless
                        if ( !is_array($search_string) ) {
                            $tmp = $search_string;
                            $search_string = array($tmp);
                        }

                        $db_search_string = '('.implode(',', $search_string).')';
                        if ($db_search_string == '()')
                            continue;

                        $query = $em->createQuery(
                           'SELECT grandparent.id
                            FROM ODRAdminBundle:RadioOptions ro
                            JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.radioOption = ro
                            JOIN ODRAdminBundle:DataRecordFields AS drf WITH rs.dataRecordFields = drf
                            JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                            JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                            WHERE df = :datafield AND ro.id IN '.$db_search_string.' AND rs.selected = 1 AND df.user_only_search <= :search_restriction
                            AND ro.deletedAt IS NULL AND rs.deletedAt IS NULL AND df.deletedAt IS NULL AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
                        )->setParameters( array('datafield' => $datafield, 'search_restriction' => $search_restriction) );
                    }
                    else if ($typeclass == 'Image' || $typeclass == 'File') {
                        // Image/File fields...

                        $condition = ' AND e.id IS NOT NULL';   // assume user wants existence of files/images
                        if ($search_string == 0)
                            $condition = ' AND e.id IS NULL';

                        $query = $em->createQuery(
                           'SELECT grandparent.id
                            FROM ODRAdminBundle:DataRecordFields AS drf
                            JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                            JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent

                            LEFT JOIN ODRAdminBundle:'.$typeclass.' AS e WITH e.dataRecordFields = drf

                            WHERE df = :datafield AND df.user_only_search <= :search_restriction
                            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'.$condition.' GROUP BY grandparent.id'

                        )->setParameters( array('datafield' => $datafield, 'search_restriction' => $search_restriction) );
                    }
                    else if ($typeclass == 'DatetimeValue') {
                        // DateTime fields...
                        $start = trim($search_string['start']);
                        $end = trim($search_string['end']);

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

                        $query = $em->createQuery(
                           'SELECT grandparent.id
                            FROM ODRAdminBundle:DatetimeValue dv
                            JOIN ODRAdminBundle:DataFields AS df WITH dv.dataField = df
                            JOIN ODRAdminBundle:DataRecord AS dr WITH dv.dataRecord = dr
                            JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                            WHERE df = :datafield AND df.user_only_search <= :search_restriction
                            AND df.deletedAt IS NULL AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL
                            AND dv.value BETWEEN :start AND :end'
                        )->setParameters( array('datafield' => $datafield->getId(), 'search_restriction' => $search_restriction, 'start' => $start, 'end' => $end) );
                    }
                    else {
                        // Every other FieldType...

                        // Assume user wants exact search...
                        $ret = self::parseField($search_string, $debug);
//print_r($ret);
                        $parameters = array('datafield' => $datafield->getId(), 'search_restriction' => $search_restriction);
                        $parameters = array_merge($parameters, $ret['params']);

                        // Create a query to locate the entities of the datafield that have the search string, and return their datarecords
                        $query = $em->createQuery(
                           'SELECT grandparent.id
                            FROM ODRAdminBundle:'.$typeclass.' e
                            JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                            JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                            JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                            WHERE df = :datafield AND df.user_only_search <= :search_restriction AND ('.$ret['str'].')
                            AND drf.deletedAt IS NULL AND df.deletedAt IS NULL AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
                        )->setParameters( $parameters );
//print_r($query);
                    }

                    // Get and store the results
                    $using_adv_search = true;
                    $adv_results[$datafield_id] = $query->getResult();
                }
            }


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

if ($debug) {
    print '----------'."\n";
    print_r($adv_search_datarecords, true)."\n";
    print '----------'."\n";
}

            // --------------------------------------------------
            // General search
            if (trim($general_string) !== '') {

                // Assume user wants exact search...
                $ret = self::parseField($general_string, $debug);
//print $ret."\n";
                $parameters = array('datatype' => $datatype, 'search_restriction' => $search_restriction);
                $parameters = array_merge($parameters, $ret['params']);

                $basic_results = array();
                // Search all datafields belonging to this datatype that are of these fieldtypes
                $search_entities = array('ShortVarchar', 'MediumVarchar', 'LongVarchar', 'LongText');   // TODO - IntegerValue too?

                // NOTE - this purposefully ignores boolean...otherwise a general search string of '1' would return all checked boolean entities, and '0' would return all unchecked boolean entities

                foreach ($search_entities as $entity) {
                    // Create a query to locate entities across all searchable datafields of the given datatype, and return those matching 
                    $query = $em->createQuery(
                       'SELECT grandparent.id
                        FROM ODRAdminBundle:'.$entity.' e
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                        JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                        JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                        JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                        WHERE df.searchable > 0 AND df.user_only_search <= :search_restriction AND ('.$ret['str'].')
                        AND df.deletedAt IS NULL AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL AND drf.deletedAt IS NULL
                        AND grandparent.dataType = :datatype'
                    )->setParameters( $parameters );

                    $basic_results = array_merge($basic_results, $query->getResult());
                }

                // Also search based for any hits in searchable radio datafields
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

                $query = $em->createQuery(
                   'SELECT grandparent.id
                    FROM ODRAdminBundle:RadioOptions ro
                    JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.radioOption = ro
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH rs.dataRecordFields = drf
                    JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                    JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                    WHERE df.searchable > 0 AND ro.optionName '.$comparision.' :search AND rs.selected = 1 AND df.user_only_search <= :search_restriction
                    AND ro.deletedAt IS NULL AND rs.deletedAt IS NULL AND df.deletedAt IS NULL AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND grandparent.deletedAt IS NULL
                    AND grandparent.dataType = :datatype'
                )->setParameters( array('search' => $general_string, 'datatype' => $datatype, 'search_restriction' => $search_restriction) );

                $basic_results = array_merge($basic_results, $query->getResult());
if ($debug) {
    print '----------'."\n";
    print_r($basic_results, true)."\n";
    print '----------'."\n";
}

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

if ($debug) {
    print 'adv: '.print_r($adv_search_datarecords, true)."\n";
    print 'basic: '.print_r($basic_search_datarecords, true)."\n";
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
            $saved_searches = array();
            if ( $session->has('saved_searches') )
                $saved_searches = $session->get('saved_searches');
            $search_checksum = md5($search_key);

            if ($datarecords == false)  // apparently $datarecords gets set to false sometimes...
                $datarecords = '';

            $saved_searches[$search_checksum] = array('logged_in' => $logged_in, 'datatype' => $datatype->getId(), 'datarecords' => $datarecords, 'encoded_search_key' => $encoded_search_key);
            $session->set('saved_searches', $saved_searches);

if ($debug) {
    print 'saving datarecord_str: '.$datarecords."\n";

    $saved_searches = $session->get('saved_searches');
    print_r($saved_searches);
}
        }

/*
        if ( trim($datarecords) !== '' ) {
            // Really only need to return non-failure...
            $return['d'] = '';
        }
        else {
            $return['r'] = 2;
            $return['d'] = 'No Records Found!';
        }
*/

        return $return;
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
                        case '-':
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
                            $piece = intval($piece);

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
                    $piece = intval($piece);
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

        $ret = array('str' => $str, 'params' => $parameters);
        return $ret;
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

