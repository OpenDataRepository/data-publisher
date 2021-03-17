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
use ODR\AdminBundle\Entity\DataFields;
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
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchSidebarService;
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

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchSidebarService $ssb_service */
            $ssb_service = $this->container->get('odr.search_sidebar_service');
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
            // Need to build everything used by the sidebar...
            $datatype_array = $ssb_service->getSidebarDatatypeArray($admin_user, $target_datatype->getId());
            $datatype_relations = $ssb_service->getSidebarDatatypeRelations($datatype_array, $target_datatype_id);
            $user_list = $ssb_service->getSidebarUserList($admin_user, $datatype_array);


            // ----------------------------------------
            // Grab a random background image if one exists and the user is allowed to see it
            $background_image_id = null;
/* TODO - current search page doesn't have a good place to put a background image...

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
*/


            // ----------------------------------------
            // Generate a random key to identify this tab
            $odr_tab_id = $odr_tab_service->createTabId();

            // TODO - modify search page to allow users to select from available themes
            $available_themes = $theme_info_service->getAvailableThemes($admin_user, $target_datatype, 'search_results');
            $preferred_theme_id = $theme_info_service->getPreferredTheme($admin_user, $target_datatype_id, 'search_results');


            // ----------------------------------------
            // Render just the html for the base page and the search page...$this->render() apparently creates a full Response object
            $site_baseurl = $this->container->getParameter('site_baseurl');
            /*
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $site_baseurl .= '/app_dev.php';
            */

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
                    'search_string' => $search_string,
                    'odr_tab_id' => $odr_tab_id,

                    // required for background image
                    'background_image_id' => $background_image_id,

                    // datatype/datafields to search
                    'search_params' => array(),
                    'target_datatype' => $target_datatype,
                    'datatype_array' => $datatype_array,
                    'datatype_relations' => $datatype_relations,

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
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
            // Check whether the search key is valid first...
            $search_key_service->validateSearchKey($search_key);

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

            // Bypass list entirely if only one datarecord...
            if ( count($datarecords) == 1 && $intent === 'searching') {
                $datarecord_id = $datarecords[0];
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

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
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

            // Verify the posted search request
            $search_key = $search_key_service->encodeSearchKey($search_params);
            $search_key_service->validateSearchKey($search_key);


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            $can_view_datarecords = $pm_service->canViewNonPublicDatarecords($user, $datatype);
            $can_add_datarecord = $pm_service->canAddDatarecord($user, $datatype);
            // ----------------------------------------


            // Ensure the user isn't trying to search on something they can't access...
            $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
            // Run a search on the given parameters
            $result = $search_api_service->performSearch($datatype, $filtered_search_key, $user_permissions);


            // Load the cached versions of the first couple datarecords matching the search
            $dr_array = array();
            $output = array();
            foreach ($result['grandparent_datarecord_list'] as $num => $dr_id) {
                $dr = $dri_service->getDatarecordArray($dr_id, false);

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
            $dt_array = $dti_service->getDatatypeArray($datatype_id, false);
            $pm_service->filterByGroupPermissions($dt_array, $dr_array, $user_permissions);


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
                            // ...entries for each datafield aren't guaranteed to exist in the
                            //  datarecord array...
                            if ( isset($dr['dataRecordFields'][$df_id]) ) {
                                // Only save the value if it's not empty
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

            if ( empty($output) ) {
                if ( $can_add_datarecord )
                    $dr_id = -1;
                else
                    $dr_id = -2;

                $final_output[$dr_id] = array(
                    'record_id' => $dr_id,
                    'fields' => array(),
                );
            }
            else {
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


    /**
     * Re-renders and returns the HTML to search a datafield in the search slideout.
     *
     * @param int $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function reloadsearchdatafieldAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() !== null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatafield($user, $datafield) )
                throw new ODRForbiddenException();
            // --------------------


            $searchable = $datafield->getSearchable();
            if ( $searchable === DataFields::NOT_SEARCHED || $searchable === DataFields::GENERAL_SEARCH ) {
                // Don't attempt to re-render the datafield if it's either "not searchable" or
                //  "general search only"
                $return['d'] = array(
                    'needs_update' => false,
                    'html' => ''
                );
            }
            else {
                // Datafield is in advanced search, so it has an HTML element on the sidebar
                // Need the datafield's array entry in order to re-render it
                $datatype_array = $dti_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);
                $df_array = $datatype_array[$datatype->getId()]['dataFields'][$datafield->getId()];

                $templating = $this->get('templating');
                $return['d'] = array(
                    'needs_update' => true,
                    'html' => $templating->render(
                        'ODROpenRepositorySearchBundle:Default:search_datafield.html.twig',
                        array(
                            'datatype_id' => $datatype->getId(),
                            'datafield' => $df_array,
                        )
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x9d85646e;
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
     * Renders and returns the HTML for a reload of the search sidebar.
     *
     * @param string $search_key
     * @param int $force_rebuild
     * @param string $intent
     * @param Request $request
     *
     * @return Response
     */
    public function reloadsearchsidebarAction($search_key, $force_rebuild, $intent, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchSidebarService $ssb_service */
            $ssb_service = $this->container->get('odr.search_sidebar_service');
            /** @var ThemeInfoService $ti_service */
            $ti_service = $this->container->get('odr.theme_info_service');


            // Ensure it's a valid search key first...
            $search_key_service->validateSearchKey($search_key);

            // Need to get the datatype id out of the search key service
            $search_params = $search_key_service->decodeSearchKey($search_key);
            $dt_id = intval( $search_params['dt_id'] );

            /** @var DataType $target_datatype */
            $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($dt_id);
            if ( $target_datatype->getDeletedAt() !== null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            if ( !$pm_service->canViewDatatype($user, $target_datatype) )
                throw new ODRForbiddenException();

            $logged_in = true;
            if ($user === 'anon.')
                $logged_in = false;
            // --------------------

            if ( $intent !== 'searching' && $intent !== 'linking' )
                throw new ODRBadRequestException();

            // Default to not making any changes
            $return['d'] = array('html' => '');

            // Only rebuild the search sidebar when it's not a default search
            if ( count($search_params) > 1 || $force_rebuild == 1 ) {
                // Need to build everything used by the sidebar...
                $datatype_array = $ssb_service->getSidebarDatatypeArray($user, $target_datatype->getId());
                $datatype_relations = $ssb_service->getSidebarDatatypeRelations($datatype_array, $target_datatype->getId());
                $user_list = $ssb_service->getSidebarUserList($user, $datatype_array);

                $preferred_theme_id = $ti_service->getPreferredTheme($user, $target_datatype->getId(), 'search_results');

                // Twig can figure out which radio options/tags are selected or unselected, but it's
                //  difficult to actually set them inside the template file...easier to use php
                //  to tweak the search params array here
                foreach ($datatype_array as $dt_id => $dt) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                        if ( $typeclass == 'Radio' || $typeclass == 'Tag' ) {
                            if ( isset($search_params[$df_id]) ) {
                                // This search has criteria for a radio/tag datafield
                                $ids = explode(',', $search_params[$df_id]);

                                $selected_ids = array();
                                $unselected_ids = array();
                                foreach ($ids as $id) {
                                    if ( strpos($id, '-') !== false )
                                        $unselected_ids[] = substr($id, 1);
                                    else
                                        $selected_ids[] = $id;
                                }

                                // Save everything and continue
                                $search_params[$df_id] = array(
                                    'selected' => $selected_ids,
                                    'unselected' => $unselected_ids
                                );
                            }
                        }
                    }
                }

                $templating = $this->get('templating');
                $return['d'] = array(
                    'num_params' => count($search_params),
                    'html' => $templating->render(
                        'ODROpenRepositorySearchBundle:Default:search_sidebar.html.twig',
                        array(
                            'search_key' => $search_key,
                            'search_params' => $search_params,

                            // required twig/javascript parameters
                            'user' => $user,
                            'datatype_permissions' => $datatype_permissions,
                            'datafield_permissions' => $datafield_permissions,

                            'user_list' => $user_list,
                            'logged_in' => $logged_in,
                            'intent' => $intent,
                            'sidebar_reload' => true,

                            // datatype/datafields to search
                            'target_datatype' => $target_datatype,
                            'datatype_array' => $datatype_array,
                            'datatype_relations' => $datatype_relations,

                            // theme selection
                            'preferred_theme_id' => $preferred_theme_id,
                        )
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0xaf1f4a0f;
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
