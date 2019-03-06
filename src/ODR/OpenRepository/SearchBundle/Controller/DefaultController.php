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

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entites
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
use ODR\OpenRepository\UserBundle\Component\Service\TrackedPathService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;


class DefaultController extends Controller
{

    /**
     * Renders the base page for searching purposes
     *
     * @param String $search_slug   Which datatype to load a search page for.
     * @param String $search_string An optional string to immediately enter into the general search field and search with.
     * @param Request $request
     *
     * @return Response
     */
    public function searchpageAction($search_slug, $search_string, Request $request)
    {
        $html = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            $cookies = $request->cookies;


            // ------------------------------
            // Grab user and their permissions if possible
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($admin_user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            // Store if logged in or not
            $logged_in = true;
            if ($admin_user === 'anon.')
                $logged_in = false;
            // ------------------------------


            // Locate the datatype referenced by the search slug, if possible...
            if ($search_slug == '') {
                if ( $cookies->has('prev_searched_datatype') ) {
                    $search_slug = $cookies->get('prev_searched_datatype');
                    return $this->redirectToRoute(
                        'odr_search',
                        array(
                            'search_slug' => $search_slug
                        )
                    );
                }
                else {
                    if ($logged_in) {
                        // Instead of displaying a "page not found", redirect to the datarecord list
                        $baseurl = $this->generateUrl('odr_admin_homepage');
                        $hash = $this->generateUrl('odr_list_types', array( 'section' => 'databases') );

                        return $this->redirect( $baseurl.'#'.$hash );
                    }
                    else {
                        return $this->redirectToRoute('odr_admin_homepage');
                    }
                }
            }

            // Now that a search slug is guaranteed to exist, locate the desired datatype
            /** @var DataTypeMeta $meta_entry */
            $meta_entry = $em
                ->getRepository('ODRAdminBundle:DataTypeMeta')
                ->findOneBy(
                    array(
                        'searchSlug' => $search_slug
                    )
                );
            if ($meta_entry == null)	
                throw new ODRNotFoundException('Datatype');

            // Check if this is a database properties database
            if($meta_entry->getDataType()->getMetadataFor() != null) {
                $target_datatype = $meta_entry->getDataType()->getMetadataFor();
            }
            else {
                $target_datatype = $meta_entry->getDataType();
            }

            if ($target_datatype == null)
                throw new ODRNotFoundException('Datatype');


            // Check if user has permission to view datatype
            $target_datatype_id = $target_datatype->getId();
            if ( !$pm_service->canViewDatatype($admin_user, $target_datatype) ) {
                if (!$logged_in) {
                    // Can't just throw a 401 error here and have Symfony auto-redirect to login
                    // So, in order to get the user to login and then return them back to this page...

                    // ...need to clear existing session redirect paths
                    /** @var TrackedPathService $tracked_path_service */
                    $tracked_path_service = $this->container->get('odr.tracked_path_service');
                    $tracked_path_service->clearTargetPaths();

                    // ...then need to save the user's current URL into their session
                    $url = $request->getRequestUri();
                    $session = $request->getSession();
                    $session->set('_security.main.target_path', $url);

                    // ...then finally we can redirect to the login page
                    return $this->redirectToRoute('fos_user_security_login');
                }
                else {
                    throw new ODRForbiddenException();
                }
            }



            // ----------------------------------------
            // Most of the data required to build the search page is already contained within the
            //  cached datatype arrays...
            $datatype_array = $dti_service->getDatatypeArray($target_datatype_id, true);

            // ...conveniently, they can also be filtered right here
            $datarecord_array = array();
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

            // In the interest of making finding these datafields a bit easier...sort by name
            foreach ($datatype_array as $dt_id => $dt) {
                uasort($datatype_array[$dt_id]['dataFields'], function($a, $b) {
                    $a_name = $a['dataFieldMeta']['fieldName'];
                    $b_name = $b['dataFieldMeta']['fieldName'];

                    return strcmp($a_name, $b_name);
                });
            }


            // ----------------------------------------
            // Need to determine whether a datatype is a child of the top-level datatype...if it's
            //  not, then it's linked
            $datatree_array = $dti_service->getDatatreeArray();
            $datatype_list = array(
                'child_datatypes' => array(),
                'linked_datatypes' => array(),
            );
            foreach ($datatype_array as $dt_id => $datatype_data) {
                // Don't want the top-level datatype in this array
                if ($dt_id === $target_datatype_id)
                    continue;

                // Locate this particular datatype's grandparent id...
                $gp_dt_id = $dti_service->getGrandparentDatatypeId($dt_id, $datatree_array);

                if ($gp_dt_id === $target_datatype_id) {
                    // If it's the same as the target datatype being searched on, then it's a child
                    //  datatype
                    $datatype_list['child_datatypes'][] = $dt_id;
                }
                else {
                    // Otherwise, it's a linked datatype (or a child of a linked datatype)
                    $datatype_list['linked_datatypes'][] = $dt_id;
                }
            }



            // ----------------------------------------
            // Grab a random background image if one exists and the user is allowed to see it
            $background_image_id = null;
            if ( !is_null($target_datatype) && !is_null($target_datatype->getBackgroundImageField()) ) {

                // Determine whether the user is allowed to view the background image datafield
                $df = $target_datatype->getBackgroundImageField();
                if ( $pm_service->canViewDatafield($admin_user, $df) ) {
                    $query = null;
                    if ( $pm_service->canViewNonPublicDatarecords($admin_user, $target_datatype) ) {
                        // Users with the $can_view_datarecord permission can view all images in all datarecords of this datatype
                        $query = $em->createQuery(
                           'SELECT i.id AS image_id
                            FROM ODRAdminBundle:Image as i
                            JOIN ODRAdminBundle:DataRecordFields AS drf WITH i.dataRecordFields = drf
                            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                            WHERE i.original = 1 AND i.dataField = :datafield_id AND i.encrypt_key != :encrypt_key
                            AND i.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
                        )->setParameters( array('datafield_id' => $df->getId(), 'encrypt_key' => '') );
                    }
                    else {
                        // Users without the $can_view_datarecord permission can only view public images in public datarecords of this datatype
                        $query = $em->createQuery(
                           'SELECT i.id AS image_id
                            FROM ODRAdminBundle:Image as i
                            JOIN ODRAdminBundle:ImageMeta AS im WITH im.image = i
                            JOIN ODRAdminBundle:DataRecordFields AS drf WITH i.dataRecordFields = drf
                            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                            JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                            WHERE i.original = 1 AND i.dataField = :datafield_id AND i.encrypt_key != :encrypt_key
                            AND im.publicDate NOT LIKE :public_date AND drm.publicDate NOT LIKE :public_date
                            AND i.deletedAt IS NULL AND im.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL'
                        )->setParameters( array('datafield_id' => $df->getId(), 'encrypt_key' => '', 'public_date' => '2200-01-01 00:00:00') );
                    }
                    $results = $query->getArrayResult();

                    // Pick a random image from the list of available images
                    if (count($results) > 0) {
                        $index = rand(0, count($results) - 1);
                        $background_image_id = $results[$index]['image_id'];
                    }
                }
            }


            // ----------------------------------------
            // Grab users to populate the created/modified by boxes with
            $user_list = self::getSearchUserList($admin_user, $datatype_permissions);

            // Generate a random key to identify this tab
            $odr_tab_id = $odr_tab_service->createTabId();

            // TODO - modify search page to allow users to select from available themes
            $available_themes = $theme_info_service->getAvailableThemes($admin_user, $target_datatype, 'search_results');
            $preferred_theme_id = $theme_info_service->getPreferredTheme($admin_user, $target_datatype_id, 'search_results');


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
                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'user_list' => $user_list,
                    'logged_in' => $logged_in,
                    'window_title' => $target_datatype->getShortName(),
                    'intent' => 'searching',
                    'search_slug' => $search_slug,
                    'site_baseurl' => $site_baseurl,
                    'search_string' => $search_string,
                    'odr_tab_id' => $odr_tab_id,

                    // required for background image
                    'background_image_id' => $background_image_id,

                    // datatype/datafields to search
                    'target_datatype' => $target_datatype,
                    'datatype_array' => $datatype_array,
                    'datatype_list' => $datatype_list,

                    // theme selection
                    'available_themes' => $available_themes,
                    'preferred_theme_id' => $preferred_theme_id,
                )
            );

            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            $session = $request->getSession();
            $session->set('scroll_target', '');
        }
        catch (\Exception $e) {
            $source = 0xd75fa46d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
     * @return Response
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            // Need to grab all searchable datafields for the target_datatype and its descendants
            $target_datatype_id = intval($target_datatype_id);
            /** @var DataType $target_datatype */
            $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($target_datatype_id);
            if ($target_datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ------------------------------
            // Grab user and their permissions if possible
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($admin_user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $logged_in = true;


            // ----------------------------------------
            // Most of the data required to build the search page is already contained within the
            //  cached datatype arrays...
            $datatype_array = $dti_service->getDatatypeArray($target_datatype_id, true);

            // ...conveniently, they can also be filtered right here
            $datarecord_array = array();
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

            // In the interest of making finding these datafields a bit easier...sort by name
            foreach ($datatype_array as $dt_id => $dt) {
                uasort($datatype_array[$dt_id]['dataFields'], function($a, $b) {
                    $a_name = $a['dataFieldMeta']['fieldName'];
                    $b_name = $b['dataFieldMeta']['fieldName'];

                    return strcmp($a_name, $b_name);
                });
            }


            // ----------------------------------------
            // Need to determine whether a datatype is a child of the top-level datatype...if it's
            //  not, then it's linked
            $datatree_array = $dti_service->getDatatreeArray();
            $datatype_list = array(
                'child_datatypes' => array(),
                'linked_datatypes' => array(),
            );
            foreach ($datatype_array as $dt_id => $datatype_data) {
                // Don't want the top-level datatype in this array
                if ($dt_id === $target_datatype_id)
                    continue;

                // Locate this particular datatype's grandparent id...
                $gp_dt_id = $dti_service->getGrandparentDatatypeId($dt_id, $datatree_array);

                if ($gp_dt_id === $target_datatype_id) {
                    // If it's the same as the target datatype being searched on, then it's a child
                    //  datatype
                    $datatype_list['child_datatypes'][] = $dt_id;
                }
                else {
                    // Otherwise, it's a linked datatype (or a child of a linked datatype)
                    $datatype_list['linked_datatypes'][] = $dt_id;
                }
            }


            // ----------------------------------------
            // Grab all the users
            $user_list = self::getSearchUserList($admin_user, $datatype_permissions);

            // Save which theme the user wants to use to render the search box with
            $preferred_theme_id = $theme_info_service->getPreferredTheme($admin_user, $target_datatype->getId(), 'search_results');


            // ----------------------------------------
            // Render the template
            $site_baseurl = $this->container->getParameter('site_baseurl');
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODROpenRepositorySearchBundle:Default:search.html.twig',
                    array(
                        // required twig/javascript parameters
                        'user' => $admin_user,
                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'user_list' => $user_list,
                        'logged_in' => $logged_in,
                        'intent' => 'linking',
                        'site_baseurl' => $site_baseurl,
                        'preferred_theme_id' => $preferred_theme_id,

                        // required for background image
                        'background_image_id' => null,

                        // datatype/datafields to search
                        'target_datatype' => $target_datatype,
                        'datatype_array' => $datatype_array,
                        'datatype_list' => $datatype_list,
                    )
                )
            );

            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            $session = $request->getSession();
            $session->set('scroll_target', '');

        }
        catch (\Exception $e) {
            $source = 0x5078a3e1;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns an array of user ids and usernames based on the datatypes that $admin_user can see,
     * so that the search page can populate createdBy/updatedBy fields correctly
     *
     * @param ODRUser $admin_user
     * @param array $datatype_permissions
     *
     * @return array
     */
    private function getSearchUserList($admin_user, $datatype_permissions)
    {
        // Determine if the user has the permissions required to see anybody in the created/modified by search fields
        $admin_permissions = array();
        foreach ($datatype_permissions as $datatype_id => $up) {
            if ( (isset($up['dr_edit']) && $up['dr_edit'] == 1)
                || (isset($up['dr_delete']) && $up['dr_delete'] == 1)
                || (isset($up['dr_add']) && $up['dr_add'] == 1)
            ) {
                $admin_permissions[ $datatype_id ] = $up;
            }
        }

        if ( $admin_user == 'anon.' || count($admin_permissions) == 0 ) {
            // Not logged in, or has none of the required permissions
            return array();
        }


        // Otherwise, locate users to populate the created/modified by boxes with
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        // Get a list of datatypes the user is allowed to access
        $datatype_list = array();
        foreach ($admin_permissions as $dt_id => $tmp)
            $datatype_list[] = $dt_id;

        // Get all other users which can view that list of datatypes
        $query = $em->createQuery(
           'SELECT u.id, u.username, u.email, u.firstName, u.lastName
            FROM ODROpenRepositoryUserBundle:User AS u
            JOIN ODRAdminBundle:UserGroup AS ug WITH ug.user = u
            JOIN ODRAdminBundle:Group AS g WITH ug.group = g
            JOIN ODRAdminBundle:GroupDatatypePermissions AS gdtp WITH gdtp.group = g
            JOIN ODRAdminBundle:GroupDatafieldPermissions AS gdfp WITH gdfp.group = g
            WHERE u.enabled = 1 AND g.dataType IN (:datatypes) AND (gdtp.can_add_datarecord = 1 OR gdtp.can_delete_datarecord = 1 OR gdfp.can_edit_datafield = 1)
            GROUP BY u.id'
        )->setParameters( array('datatypes' => $datatype_list) );   // purposefully getting ALL users, including the ones that are deleted
        $results = $query->getArrayResult();

        // Convert them into a list of users that the admin user is allowed to search by
        $user_list = array();
        foreach ($results as $user) {
            $username = '';
            if ( is_null($user['firstName']) || $user['firstName'] === '' )
                $username = $user['email'];
            else
                $username = $user['firstName'].' '.$user['lastName'];

            $user_list[ $user['id'] ] = $username;
        }

        return $user_list;
    }


    /**
     * Fixes searches to follow the new URL system and redirects the user.
     *
     * @param $search_key
     * @param Request $request
     *
     * @return Response
     */
    public function legacy_searchAction($search_key, Request $request)
    {
        // Convert legacy render to new render and run
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');

            // Need to reformat to create proper search key and forward internally to view controller

            $search_param_elements = preg_split("/\|/",$search_key);
            $search_params = array();
            foreach($search_param_elements as $search_param_element) {
                $search_param_data = preg_split("/\=/",$search_param_element);
                $search_params[$search_param_data[0]] = $search_param_data[1];
            }
            $new_search_key = $search_key_service->encodeSearchKey($search_params);

            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            // use whatever default theme this datatype has
            $search_theme_id = 0;

            return $search_redirect_service->redirectToFilteredSearchResult($user, $new_search_key, $search_theme_id);
        }
        catch (\Exception $e) {
            $source = 0x11286399;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Called when the user performs a search from the search page.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function searchAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Going to use the data in the POST request to build a new search key
            $search_params = $request->request->all();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');


            /** @var DataType $datatype */
            $dt_id = $search_params['dt_id'];
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($dt_id);
            if ( is_null($datatype) )
                throw new ODRNotFoundException('Datatype');

            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            // This parameter shows up when an "inline search" is made from edit_ajax.html.twig
            // It doesn't do anything here anymore, but keeping around just in case...
            if ( isset($search_params['ajax_request']) )
                unset( $search_params['ajax_request'] );


            // ----------------------------------------
            // Convert the POST request into a search key and validate it
            $search_key = $search_key_service->convertPOSTtoSearchKey($search_params);
            $search_key_service->validateSearchKey($search_key);

            // Filter out the stuff from the given search key that the user isn't allowed to see
            $user_permissions = $pm_service->getUserPermissionsArray($user);
            $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);

            // No sense actually running the search here...whatever calls this needs to use the
            //  search key to redirect to the render page
            $return['d'] = array(
                'search_key' => $filtered_search_key
            );
        }
        catch (\Exception $e) {
            $source = 0xd809c18a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Fixes searches to follow the new URL system and redirects the user.
     *
     * @param $search_key
     * @param $offset
     * @param string $source
     * @param Request $request
     *
     * @return Response
     */
    public function legacy_renderAction($search_key, $offset, $source = "searching", Request $request)
    {
        // Convert legacy render to new render and run
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            // Need to reformat to create proper search key and forward internally to view controller

            $search_param_elements = preg_split("/\|/",$search_key);
            $search_params = array();
            foreach($search_param_elements as $search_param_element) {
                $search_param_data = preg_split("/\=/",$search_param_element);
                $search_params[$search_param_data[0]] = $search_param_data[1];
            }
            $new_search_key = $search_key_service->encodeSearchKey($search_params);

            // Generate new style search key from passed search key
            return $this->redirectToRoute(
                "odr_search_render",
                array(
                    'search_key' => $new_search_key,
                    'search_theme_id' => 0,    // Use whatever default search_theme the datatype has
                    'offset' => $offset,
                    'source' => $source
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xb1c117ba;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Renders a Short/Textresults list of all datarecords that match the given search key
     *
     * @param integer $search_theme_id If non-zero, which theme to use to render this list
     * @param string $search_key The terms the user is searching for
     * @param integer $offset Which page of the search results to render
     * @param string $intent "searching" if searching from frontpage, or "linking" if searching for datarecords to link
     * @param Request $request
     *
     * @return Response
     */
    public function renderAction($search_theme_id, $search_key, $offset, $intent, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab default objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var ODRCustomController $odrcc */
            $odrcc = $this->get('odr_custom_controller', $request);
            $odrcc->setContainer($this->container);


            // ----------------------------------------
            // Grab the datatype id from the cached search result
            $search_params = $search_key_service->decodeSearchKey($search_key);
            if ( !isset($search_params['dt_id']) )
                throw new \Exception('Invalid search string');

            $datatype_id = $search_params['dt_id'];
            if ( $datatype_id == '' || !is_numeric($datatype_id) )
                throw new \Exception('Invalid search string');
            $datatype_id = intval($datatype_id);


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            // ----------------------------------------
            // Check whether the search key needs to be filtered or not
            $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
            if ($filtered_search_key !== $search_key) {
                if ($intent === 'searching') {
                    // User can't view the results of this search key, redirect to the one they can view
                    return $search_redirect_service->redirectToFilteredSearchResult($user, $filtered_search_key, $search_theme_id);
                }
                else {
                    // TODO - what to do here?  can't redirect, and silently modifying their search key probably isn't the best idea...
                    $search_key = $filtered_search_key;
                }
            }


            // TODO - better error handling, likely need more options as well...going to need a way to get which theme the user wants to use too
            // Grab the desired theme to use for rendering search results
            $theme_type = null;

            // If a theme isn't specified and the user wants a search results page...
            $search_theme_id = intval($search_theme_id);
            if ($search_theme_id == 0) {
                // ...attempt to get the user's preferred theme for this datatype
                $search_theme_id = $theme_service->getPreferredTheme($user, $datatype->getId(), 'search_results');

                if ($intent === 'searching') {
                    // ...before redirecting them to the search results URL with their preferred theme
                    return $search_redirect_service->redirectToSearchResult($search_key, $search_theme_id);
                }
            }

            // Ensure the theme exists before attempting to use it
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($search_theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            if ($theme->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException('The specified Theme does not belong to this Datatype');

            // NOTE - pretending that both of these aren't issues here...renderList() will take care of it
//            if (!$theme->isShared() && $theme->getCreatedBy()->getId() !== $user->getId())
//                throw new ODRForbiddenException('Theme is non-public');

            // Set the currently selected theme as the user's preferred theme for this session
//            $theme_service->setSessionTheme($datatype->getId(), $theme);


            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];
            else
                $odr_tab_id = $odr_tab_service->createTabId();


            // Need to ensure a sort criteria is set for this tab, otherwise the table plugin
            //  will display stuff in a different order
            $sort_df_id = 0;
            $sort_ascending = true;

            // TODO - provide some method for non-table search result pages to change order of results
            $sort_criteria = $odr_tab_service->getSortCriteria($odr_tab_id);
            if ( $theme->getThemeType() === 'table' && !is_null($sort_criteria) ) {
                // This is a table layout and it already has sort criteria

                // Load the criteria from the user's session
                $sort_df_id = $sort_criteria['datafield_id'];
                if ($sort_criteria['sort_direction'] === 'desc')
                    $sort_ascending = false;
            }
            else {
                // Otherwise, reset the sort order for now...having some arbitrary sort order that
                //  can't be changed doesn't work

                if ( is_null($datatype->getSortField()) ) {
                    // ...this datarecord list is currently ordered by id
                    $odr_tab_service->setSortCriteria($odr_tab_id, 0, 'asc');
                }
                else {
                    // ...this datarecord list is ordered by whatever the sort datafield for this datatype is
                    $sort_df_id = $datatype->getSortField()->getId();
                    $odr_tab_service->setSortCriteria($odr_tab_id, $sort_df_id, 'asc');
                }
            }


            // Run the search specified by the search key
            $search_results = $search_api_service->performSearch($datatype, $search_key, $user_permissions, $sort_df_id, $sort_ascending);
            $datarecords = $search_results['grandparent_datarecord_list'];

            // Bypass list entirely if only one datarecord
            if ( count($datarecords) == 1 && $intent === 'searching') {
                $datarecord_id = $datarecords[0];
                return $search_redirect_service->redirectToSingleDatarecord($datarecord_id);
            }


            // ----------------------------------------
            // Render and return the page
            $path_str = $this->generateUrl(
                'odr_search_render',
                array(
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $filtered_search_key
                )
            );

            $html = $odrcc->renderList(
                $datarecords,
                $datatype,
                $theme,
                $user,
                $path_str,
                $intent,
                $filtered_search_key,
                $offset,
                $request
            );

            $return['d'] = array(
                'html' => $html,
            );
        }
        catch (\Exception $e) {
            $source = 0x66d3804b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Redirects to the default search results page for the given datatype
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function defaultrenderAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // TODO - change to use search_redirect_service?
            // Encode the default search key for this datatype, and generate a route for it
            $search_key = $search_key_service->encodeSearchKey(array('dt_id' => $datatype_id));
            $url = $this->generateUrl(
                'odr_search_render',
                array(
                    'search_theme_id' => 0,     // use whatever default theme this datatype has
                    'search_key' => $search_key
                )
            );

            // Return the URL to redirect to
            $return['d'] = array('search_slug' => $datatype->getSearchSlug(), 'url' => $url);
        }
        catch (\Exception $e) {
            $source = 0xc49e75eb;
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
