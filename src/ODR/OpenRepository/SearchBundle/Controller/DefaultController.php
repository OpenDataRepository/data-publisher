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
// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\StoredSearchKey;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchSidebarService;
use ODR\OpenRepository\UserBundle\Component\Service\TrackedPathService;
// Symfony
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;


class DefaultController extends Controller
{

    /**
     * TODO
     *
     * @param String $search_slug Which datatype to load a search page for.
     * @param String $search_string An optional string to immediately enter into the general search field and search with.
     * @param Request $request
     * @return Response|RedirectResponse
     */
    public function homeAction($search_slug, $search_string, Request $request)
    {
        $html = '';
        $is_wordpress_integrated = $this->container->getParameter('odr_wordpress_integrated');

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchSidebarService $search_sidebar_service */
            $search_sidebar_service = $this->container->get('odr.search_sidebar_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');

            $cookies = $request->cookies;


            // ------------------------------
            // Grab user and their permissions if possible
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            // If using Wordpress - check for Wordpress User and Log them in.
            if($this->container->getParameter('odr_wordpress_integrated')) {
                $odr_wordpress_user = getenv("WORDPRESS_USER");
                if($odr_wordpress_user) {
                    // print $odr_wordpress_user . ' ';
                    $user_manager = $this->container->get('fos_user.user_manager');
                    /** @var ODRUser $admin_user */
                    $admin_user = $user_manager->findUserBy(array('email' => $odr_wordpress_user));
                }
            }

            $user_permissions = $permissions_service->getUserPermissionsArray($admin_user);
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


            // ----------------------------------------
            // Now that a search slug is guaranteed to exist, locate the desired datatype

            /** @var DataType $target_datatype */
            $target_datatype = null;

            /** @var DataTypeMeta $meta_entry */
            $meta_entry = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy(
                array(
                    'searchSlug' => $search_slug
                )
            );
            if ( is_null($meta_entry) ) {
                // Couldn't find a datatypeMeta entry with that search slug, so check whether the
                //  search slug is actually a database uuid instead
                $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                    array(
                        'unique_id' => $search_slug
                    )
                );

                if ( is_null($target_datatype) ) {
                    // $search_slug is neither a search slug nor a database uuid...give up
                    throw new ODRNotFoundException('Datatype');
                }
                else {
                    // Redirect so the page uses the search slug instead of the uuid
                    return $this->redirectToRoute(
                        'odr_search',
                        array(
                            'search_slug' => $target_datatype->getSearchSlug()
                        )
                    );
                }
            }
            else {
                // Found a matching datatypeMeta entry
                $target_datatype = $meta_entry->getDataType();
                if ( !is_null($target_datatype->getDeletedAt()) )
                    throw new ODRNotFoundException('Datatype');
            }

            // If this is a metadata datatype...
            if ( !is_null($target_datatype->getMetadataFor()) ) {
                // ...only want to run searches on "real" datatypes
                $target_datatype = $target_datatype->getMetadataFor();
                if ( !is_null($target_datatype->getDeletedAt()) )
                    throw new ODRNotFoundException('Datatype');

                // ...pretty sure redirecting to the "real" datatype is undesirable here
            }


            // ----------------------------------------
            // Check if user has permission to view datatype
            $target_datatype_id = $target_datatype->getId();
            if ( !$permissions_service->canViewDatatype($admin_user, $target_datatype) ) {
                if (!$logged_in) {
                    // Can't just throw a 401 error here...Symfony would redirect the user to the
                    //  list of datatypes, instead of back to this search page.

                    // So, need to clear existing session redirect paths...
                    /** @var TrackedPathService $tracked_path_service */
                    $tracked_path_service = $this->container->get('odr.tracked_path_service');
                    $tracked_path_service->clearTargetPaths();

                    // ...then need to save the user's current URL into their session
                    $url = $request->getRequestUri();
                    $session = $request->getSession();
                    $session->set('_security.main.target_path', $url);

                    // ...so they can get redirected to the login page
                    return $this->redirectToRoute('fos_user_security_login');
                }
                else {
                    throw new ODRForbiddenException();
                }
            }


            // ----------------------------------------
            // If this datatype has a default search key...
            $default_search_key = '';
            $default_search_params = array();
            if ($target_datatype->getStoredSearchKeys() && $target_datatype->getStoredSearchKeys()->count() > 0) {
                // ...then extract it so the sidebar can load with said search key
                /** @var StoredSearchKey $ssk */
                $ssk = $target_datatype->getStoredSearchKeys()->first();
                $default_search_key = $ssk->getSearchKey();

                // Convert the search key into a parameter list so that the sidebar can start out
                //  with the right stuff
                $default_search_params = $search_key_service->decodeSearchKey($default_search_key);

                // Don't need to worry if the search key refers to an invalid/deleted datafield
                //  ...the user will end up being redirected to the "empty" search key for the datatype

                // The same thing will happen when it refers to a datafield the user can't view
            }

            if ( $search_string !== '' )
                $default_search_params['gen'] = $search_string;

            // Need to build everything used by the sidebar...
            $sidebar_layout_id = $search_sidebar_service->getPreferredSidebarLayoutId($admin_user, $target_datatype->getId(), 'searching');
            $sidebar_array = $search_sidebar_service->getSidebarDatatypeArray($admin_user, $target_datatype->getId(), $default_search_params, $sidebar_layout_id);
            $user_list = $search_sidebar_service->getSidebarUserList($admin_user, $sidebar_array);
            $inverse_dt_names = $search_sidebar_service->getSidebarInverseDatatypeNames($admin_user, $target_datatype->getId());


            // ----------------------------------------
            // Grab a random background image if one exists and the user is allowed to see it
            $background_image_id = null;
            // TODO - current search page doesn't have a good place to put a background image...


            // ----------------------------------------
            // Generate a random key to identify this tab
            $odr_tab_id = $odr_tab_service->createTabId();

            // TODO - modify search page to allow users to select from available themes
//            $available_themes = $theme_info_service->getAvailableThemes($admin_user, $target_datatype, 'search_results');
            $preferred_theme_id = $theme_info_service->getPreferredThemeId($admin_user, $target_datatype_id, 'search_results');
            $preferred_theme = $em->getRepository('ODRAdminBundle:Theme')->find($preferred_theme_id);


            // ----------------------------------------
            // Render just the html for the base page and the search page...$this->render() apparently creates a full Response object
            $site_baseurl = $this->container->getParameter('site_baseurl');
            $wordpress_site_baseurl = $this->container->getParameter('wordpress_site_baseurl');

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
                    'sidebar_reload' => false,
                    'search_slug' => $search_slug,
                    'site_baseurl' => $site_baseurl,
                    'odr_tab_id' => $odr_tab_id,
                    'odr_wordpress_integrated' => $is_wordpress_integrated,
                    'wordpress_site_baseurl' => $wordpress_site_baseurl,

                    // required for background image
                    'background_image_id' => $background_image_id,

                    // datatype/datafields to search
//                    'search_params' => array(),
                    'target_datatype' => $target_datatype,
                    'sidebar_array' => $sidebar_array,
                    'inverse_dt_names' => $inverse_dt_names,

                    // defaults if needed
                    'search_key' => $default_search_key,
                    'search_params' => $default_search_params,

                    // theme selection
//                    'available_themes' => $available_themes,
                    'preferred_theme' => $preferred_theme,
                )
            );

            // print "WP Header: " . $request->wordpress_header; exit();
            $html = $request->wordpress_header . $html . $request->wordpress_footer;
            // $html = $request->wordpress_header . "<div style='min-height: 500px'></div>" . $request->wordpress_footer;

            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            $session = $request->getSession();
            $session->set('scroll_target', '');
        }
        catch (\Exception $e) {
            if ( $is_wordpress_integrated ) {
                print $e; exit();
            }

            $source = 0xd75fa46d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');
        $response->headers->setCookie(new Cookie('prev_searched_datatype', $search_slug));
        return $response;
    }


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
        $is_wordpress_integrated = $this->container->getParameter('odr_wordpress_integrated');

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchSidebarService $search_sidebar_service */
            $search_sidebar_service = $this->container->get('odr.search_sidebar_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');

            $cookies = $request->cookies;


            // ------------------------------
            // Grab user and their permissions if possible
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            $user_permissions = $permissions_service->getUserPermissionsArray($admin_user);
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


            // ----------------------------------------
            // Now that a search slug is guaranteed to exist, locate the desired datatype

            /** @var DataType $target_datatype */
            $target_datatype = null;

            /** @var DataTypeMeta $meta_entry */
            $meta_entry = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy(
                array(
                    'searchSlug' => $search_slug
                )
            );
            if ( is_null($meta_entry) ) {
                // Couldn't find a datatypeMeta entry with that search slug, so check whether the
                //  search slug is actually a database uuid instead
                $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                    array(
                        'unique_id' => $search_slug
                    )
                );

                if ( is_null($target_datatype) ) {
                    // $search_slug is neither a search slug nor a database uuid...give up
                    throw new ODRNotFoundException('Datatype');
                }
                else {
                    // Redirect so the page uses the search slug instead of the uuid
                    return $this->redirectToRoute(
                        'odr_search',
                        array(
                            'search_slug' => $target_datatype->getSearchSlug()
                        )
                    );
                }
            }
            else {
                // Found a matching datatypeMeta entry
                $target_datatype = $meta_entry->getDataType();
                if ( !is_null($target_datatype->getDeletedAt()) )
                    throw new ODRNotFoundException('Datatype');
            }

            // If this is a metadata datatype...
            if ( !is_null($target_datatype->getMetadataFor()) ) {
                // ...only want to run searches on "real" datatypes
                $target_datatype = $target_datatype->getMetadataFor();
                if ( !is_null($target_datatype->getDeletedAt()) )
                    throw new ODRNotFoundException('Datatype');

                // ...pretty sure redirecting to the "real" datatype is undesirable here
            }


            // ----------------------------------------
            // Check if user has permission to view datatype
            $target_datatype_id = $target_datatype->getId();
            if ( !$permissions_service->canViewDatatype($admin_user, $target_datatype) ) {
                if (!$logged_in) {
                    // Can't just throw a 401 error here...Symfony would redirect the user to the
                    //  list of datatypes, instead of back to this search page.

                    // So, need to clear existing session redirect paths...
                    /** @var TrackedPathService $tracked_path_service */
                    $tracked_path_service = $this->container->get('odr.tracked_path_service');
                    $tracked_path_service->clearTargetPaths();

                    // ...then need to save the user's current URL into their session
                    $url = $request->getRequestUri();
                    $session = $request->getSession();
                    $session->set('_security.main.target_path', $url);

                    // ...so they can get redirected to the login page
                    return $this->redirectToRoute('fos_user_security_login');
                }
                else {
                    throw new ODRForbiddenException();
                }
            }


            // ----------------------------------------
            // If this datatype has a default search key...
            $default_search_key = '';
            $default_search_params = array();
            if ($target_datatype->getStoredSearchKeys() && $target_datatype->getStoredSearchKeys()->count() > 0) {
                // ...then extract it so the sidebar can load with said search key
                /** @var StoredSearchKey $ssk */
                $ssk = $target_datatype->getStoredSearchKeys()->first();
                $default_search_key = $ssk->getSearchKey();

                // Convert the search key into a parameter list so that the sidebar can start out
                //  with the right stuff
                $default_search_params = $search_key_service->decodeSearchKey($default_search_key);

                // Don't need to worry if the search key refers to an invalid/deleted datafield
                //  ...the user will end up being redirected to the "empty" search key for the datatype

                // The same thing will happen when it refers to a datafield the user can't view
            }

            if ( $search_string !== '' )
                $default_search_params['gen'] = $search_string;

            // Need to build everything used by the sidebar...
            $sidebar_layout_id = $search_sidebar_service->getPreferredSidebarLayoutId($admin_user, $target_datatype->getId(), 'searching');
            $sidebar_array = $search_sidebar_service->getSidebarDatatypeArray($admin_user, $target_datatype->getId(), $default_search_params, $sidebar_layout_id);
            $user_list = $search_sidebar_service->getSidebarUserList($admin_user, $sidebar_array);
            $inverse_dt_names = $search_sidebar_service->getSidebarInverseDatatypeNames($admin_user, $target_datatype->getId());


            // ----------------------------------------
            // Grab a random background image if one exists and the user is allowed to see it
            $background_image_id = null;
            // TODO - current search page doesn't have a good place to put a background image...


            // ----------------------------------------
            // Generate a random key to identify this tab
            $odr_tab_id = $odr_tab_service->createTabId();

            // TODO - modify search page to allow users to select from available themes
//            $available_themes = $theme_info_service->getAvailableThemes($admin_user, $target_datatype, 'search_results');
            $preferred_theme_id = $theme_info_service->getPreferredThemeId($admin_user, $target_datatype_id, 'search_results');
            $preferred_theme = $em->getRepository('ODRAdminBundle:Theme')->find($preferred_theme_id);


            // ----------------------------------------
            // Render just the html for the base page and the search page...$this->render() apparently creates a full Response object
            // Wordpress Integrated - use full & body
            $site_baseurl = $this->container->getParameter('site_baseurl');
            $wordpress_site_baseurl = $this->container->getParameter('wordpress_site_baseurl');
            // print "WP Header: " . $request->wordpress_header; exit();

            if ( $is_wordpress_integrated ) {
                // WPNonce Should exist
                $logout_url = wp_logout_url();

                $html = $this->renderView(
                    'ODROpenRepositorySearchBundle:Default:home.html.twig',
                    array(
                        // required twig/javascript parameters
                        'user' => $admin_user,
                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,
                        'odr_wordpress_integrated' => $is_wordpress_integrated,
                        'wordpress_site_baseurl' => $wordpress_site_baseurl,
                        'logout_url' => $logout_url,

                        'user_list' => $user_list,
                        'logged_in' => $logged_in,
                        'window_title' => $target_datatype->getShortName(),
                        'intent' => 'searching',
                        'sidebar_reload' => false,
                        'search_slug' => $search_slug,
                        'site_baseurl' => $site_baseurl,
                        'odr_tab_id' => $odr_tab_id,

                        // required for background image
                        'background_image_id' => $background_image_id,

                        // datatype/datafields to search
                        'target_datatype' => $target_datatype,
                        'sidebar_array' => $sidebar_array,
                        'inverse_dt_names' => $inverse_dt_names,

                        // defaults if needed
                        'search_key' => $default_search_key,
                        'search_params' => $default_search_params,

                        // theme selection
//                        'available_themes' => $available_themes,
                        'preferred_theme' => $preferred_theme,
                    )
                );
                // Prepend/append the wordpress header and footer
                $html = $request->wordpress_header . $html . $request->wordpress_footer;
            }
            else {
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
                        'sidebar_reload' => false,
                        'search_slug' => $search_slug,
                        'site_baseurl' => $site_baseurl,
                        'odr_tab_id' => $odr_tab_id,
                        'odr_wordpress_integrated' => $is_wordpress_integrated,
                        'wordpress_site_baseurl' => $wordpress_site_baseurl,

                        // required for background image
                        'background_image_id' => $background_image_id,

                        // datatype/datafields to search
                        'target_datatype' => $target_datatype,
                        'sidebar_array' => $sidebar_array,
                        'inverse_dt_names' => $inverse_dt_names,

                        // defaults if needed
                        'search_key' => $default_search_key,
                        'search_params' => $default_search_params,

                        // theme selection
//                    'available_themes' => $available_themes,
                        'preferred_theme' => $preferred_theme,
                    )
                );
            }


            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            $session = $request->getSession();
            $session->set('scroll_target', '');
        }
        catch (\Exception $e) {
            if ( $is_wordpress_integrated ) {
                print $e; exit();
            }

            $source = 0xd75fa46d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');
        $response->headers->setCookie(new Cookie('prev_searched_datatype', $search_slug));
        return $response;
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * This gets called when the user clicks the "Search" button...it converts the POST with the
     * search values into an ODR search key, and returns it so the searching javascript can trigger
     * a search results page render.
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
            if ( !isset($search_params['dt_id']) )
                throw new ODRBadRequestException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
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
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            // This parameter shows up when an "inline search" is made from edit_ajax.html.twig
            // It doesn't do anything here anymore, but keeping around just in case...
            if ( isset($search_params['ajax_request']) )
                unset( $search_params['ajax_request'] );


            // ----------------------------------------
            // Convert the POST request into a search key and validate it
            $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
            $search_key = $search_key_service->convertPOSTtoSearchKey($search_params, $is_wordpress_integrated);
            $search_key_service->validateSearchKey($search_key);

            // Filter out the stuff from the given search key that the user isn't allowed to see
            $user_permissions = $permissions_service->getUserPermissionsArray($user);
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Renders a Short/Textresults list of all datarecords that match the given search key
     *
     * @param integer $search_theme_id If non-zero, which theme to use to render this list
     * @param string $search_key The terms the user is searching on
     * @param integer $offset Which page of the search results to render
     * @param string $intent "searching" if searching from frontpage, or "linking" if searching for datarecords to link
     * @param Request $request
     *
     * @return Response
     */
    public function renderAction($search_theme_id, $search_key, $offset, $intent, Request $request)
    {
        $start = microtime(true);
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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


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
            $user_permissions = $permissions_service->getUserPermissionsArray($user);
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            // ----------------------------------------
            // Check whether the search key is valid first...
            $search_key_service->validateSearchKey($search_key);

            // Check whether the search key needs to be filtered or not
            $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
            if ($filtered_search_key !== $search_key) {
                // The search key got changed...determine whether it's because something was out of
                //  order, or the user tried to search on a field they can't view...
                if ($intent === 'searching') {
                    $decoded_original = $search_key_service->decodeSearchKey($search_key);
                    $decoded_modified = $search_key_service->decodeSearchKey($filtered_search_key);

                    if ( count($decoded_original) == count($decoded_modified) ) {
                        // User submitted a search key that's "out of order"...silently redirect to the sorted one
                        return $search_redirect_service->redirectToSearchResult($filtered_search_key, $search_theme_id);
                    }
                    else {
                        // User can't view the results of this search key, redirect to the one they can view
                        return $search_redirect_service->redirectToFilteredSearchResult($user, $filtered_search_key, $search_theme_id);
                    }
                }
                else {
                    // TODO - this currently happens when something gets filtered in the "link to datarecord" search page
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
                $search_theme_id = $theme_info_service->getPreferredThemeId($user, $datatype->getId(), 'search_results');

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
//            $theme_info_service->setSessionTheme($datatype->getId(), $theme);


            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];
            else
                $odr_tab_id = $odr_tab_service->createTabId();

            // Ensure the tab refers to the given search key
            $expected_search_key = $odr_tab_service->getSearchKey($odr_tab_id);
            if ( $expected_search_key !== $search_key )
                $odr_tab_service->setSearchKey($odr_tab_id, $search_key);

            // Need to ensure a sort criteria is set for this tab, otherwise the table plugin
            //  will display stuff in a different order
            $sort_datafields = array();
            $sort_directions = array();

            $sort_criteria = $odr_tab_service->getSortCriteria($odr_tab_id);
            if ( !is_null($sort_criteria) ) {
                // Prefer the criteria from the user's session whenever possible
                $sort_datafields = $sort_criteria['datafield_ids'];
                $sort_directions = $sort_criteria['sort_directions'];
            }
            else if ( isset($search_params['sort_by']) ) {
                // If the user's session doesn't have anything but the search key does, then
                //  use that
                foreach ($search_params['sort_by'] as $display_order => $data) {
                    $sort_datafields[$display_order] = intval($data['sort_df_id']);
                    $sort_directions[$display_order] = $data['sort_dir'];
                }

                // Store this in the user's session
                $odr_tab_service->setSortCriteria($odr_tab_id, $sort_datafields, $sort_directions);
            }
            else {
                // No criteria set...get this datatype's current list of sort fields, and convert
                //  into a list of datafield ids for storing this tab's criteria
                foreach ($datatype->getSortFields() as $display_order => $df) {
                    $sort_datafields[$display_order] = $df->getId();
                    $sort_directions[$display_order] = 'asc';
                }
                $odr_tab_service->setSortCriteria($odr_tab_id, $sort_datafields, $sort_directions);

                // TODO - provide some method for non-table search result pages to change order of results
            }

            // Run the search specified by the search key
            $grandparent_datarecord_list = $search_api_service->performSearch(
                $datatype,
                $search_key,
                $user_permissions,
                false,  // only want the grandparent datarecord ids that match the search
                $sort_datafields,
                $sort_directions
            );
            // Want to store this so it isn't being re-run constantly...    // TODO - should this work exactly the same way as the Display/Edit controllers?
            $odr_tab_service->setSearchResults($odr_tab_id, $grandparent_datarecord_list);

            // Bypass search results list entirely if only one datarecord...
            if ( count($grandparent_datarecord_list) == 1 && $intent === 'searching') {
                $datarecord_id = $grandparent_datarecord_list[0];
                // ...but also send the search_theme_id and the search key so the search sidebar
                //  doesn't disappear on users
                return $search_redirect_service->redirectToSingleDatarecord($datarecord_id, $search_theme_id, $filtered_search_key);
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

            // print  count($datarecords) . " -- ";
            // print microtime(true) - $start; exit();

            $html = $odrcc->renderList(
                $grandparent_datarecord_list,
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
                'search_key' => $filtered_search_key,
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Handles an "inline" linking request...takes search values from fields on the page, and then
     * returns an array so that a jQuery autocomplete plugin can render an abbreviated view of the
     * datarecords that matched the search.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function inlinelinksearchAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        // The max number of results this function should return
        $max_results = 10;

        try {
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['dt_id']) || !is_numeric($post['dt_id']) ) {
                throw new ODRBadRequestException();
            }

            $datatype_id = intval($post['dt_id']);

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->container->get('odr.datarecord_info_service');
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Find the first/single datafield in the post
            $search_params = array('dt_id' => $datatype_id);
            foreach ($post as $key => $value) {
                if ( $key === 'dt_id' || $key === 'ajax_request' )
                    continue;

                if ( !is_numeric($key) )
                    throw new ODRBadRequestException();

                // TODO - modify so the search can handle radio options and tags?
                $search_params[ intval($key) ] = trim($value);
            }

            // Need to unescape these values if they're coming from a wordpress install...
            $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
            if ( $is_wordpress_integrated ) {
                foreach ($search_params as $key => $value)
                    $search_params[$key] = stripslashes($value);
            }

            // Verify the posted search request
            $search_key = $search_key_service->encodeSearchKey($search_params);
            $search_key_service->validateSearchKey($search_key);


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            $can_view_datarecords = $permissions_service->canViewNonPublicDatarecords($user, $datatype);
            $can_add_datarecord = $permissions_service->canAddDatarecord($user, $datatype);
            // ----------------------------------------


            // Ensure the user isn't trying to search on something they can't access...
            $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
            // Run a search on the given parameters
            $grandparent_datarecord_list = $search_api_service->performSearch(
                $datatype,
                $filtered_search_key,
                $user_permissions
            );    // this only returns grandparent datarecord ids


            // Load the cached versions of the first couple datarecords matching the search
            $dr_array = array();
            $output = array();
            foreach ($grandparent_datarecord_list as $num => $dr_id) {
                $dr = $datarecord_info_service->getDatarecordArray($dr_id, false);

                // Only store the cached datarecord if the user can view it
                $public_date = $dr[$dr_id]['dataRecordMeta']['publicDate'];
                if ( $can_view_datarecords || $public_date->format('Y-m-d H:i:s') !== '2200-01-01 00:00:00' ) {
                    $dr_array[$dr_id] = $dr[$dr_id];

                    // Also initialize an output container for each datarecord
                    $output[$dr_id] = array();
                }

                // Continue loading datarecord entries until the limit is reached
                if ( count($dr_array) >= $max_results)
                    break;
            }

            // Filter out all datafields from the datarecord arrays that the user isn't allowed to see
            // Need to have the actual cached datatype array, otherwise it won't work properly
            $dt_array = $database_info_service->getDatatypeArray($datatype_id, false);
            $permissions_service->filterByGroupPermissions($dt_array, $dr_array, $user_permissions);


            // ----------------------------------------
            // Only interested in these typeclasses...
            $typeclasses = array(
                'IntegerValue' => 1,
                'DecimalValue' => 1,
                'ShortVarchar' => 1,
                'MediumVarchar' => 1,
                'LongVarchar' => 1,
                'LongText' => 1,
            );

            // datarecordFields use the same typeclass name, but the first character is lowercase
            foreach ($typeclasses as $typeclass => $num)
                $typeclasses[$typeclass] = lcfirst($typeclass);


            // Need to display all fields of these typeclasses in the same order...
            foreach ($dt_array as $dt_id => $dt) {
                // ...so for each datafield in the datatype...
                foreach ($dt['dataFields'] as $df_id => $df) {
                    // ...if it's one of the correct typeclasses...
                    $field_typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                    if ( isset($typeclasses[$field_typeclass]) ) {
                        // ...then locate the value for this field in each of the datarecords
                        $drf_typeclass = $typeclasses[$field_typeclass];

                        foreach ($dr_array as $dr_id => $dr) {
                            // Entries for each datafield aren't guaranteed to exist in the array...
                            if ( isset($dr['dataRecordFields'][$df_id]) ) {
                                $field_value = trim($dr['dataRecordFields'][$df_id][$drf_typeclass][0]['value']);
                                if ($field_value !== '')
                                    $output[$dr_id][$df_id] = $field_value;
                            }
                        }
                    }
                }
            }


            // ----------------------------------------
            // TODO - convert $output into a string here so .autocomplete( "instance" )._renderItem
            // TODO -  in edit_ajax.html.twig doesn't have to?
            $final_output = array();
            foreach ($output as $dr_id => $data) {
                $final_output[$dr_id] = array(
                    'record_id' => $dr_id,
                    'fields' => array()
                );

                foreach ($data as $df_id => $value) {
                    $final_output[$dr_id]['fields'][] = array(
                        'field_id' => $df_id,
                        'field_value' => $value,
                    );
                }
            }

            if ( $can_add_datarecord ) {
                // Always want the ability to make a new datarecord even if other records were found
                $dr_id = -1;
                $final_output[$dr_id] = array(
                    'record_id' => $dr_id,
                    'fields' => array(),
                );
            }
            else if ( empty($output) ) {
                // If the user can't make new records, then they need a message when their search
                //  didn't match anything
                $dr_id = -2;
                $final_output[$dr_id] = array(
                    'record_id' => $dr_id,
                    'fields' => array(),
                );
            }

            $return['d'] = $final_output;
        }
        catch (\Exception $e) {
            $source = 0x76b670c0;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
