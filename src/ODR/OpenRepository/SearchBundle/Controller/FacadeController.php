<?php

/**
 * Open Data Repository Data Publisher
 * Facade Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller redirects searching/API requests to the controller that can actually respond to
 * them.
 */

namespace ODR\OpenRepository\SearchBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\DatatypeCreateService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIServiceNoConflict;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// use FOS\UserBundle\Model\UserManagerInterface;
// Symfony
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// Other
use FOS\UserBundle\Util\TokenGenerator;
use Symfony\Component\Intl\Tests\Data\Provider\Json\JsonRegionDataProviderTest;


class FacadeController extends Controller
{

    /**
     * Determines datatype id via search slug, then forwards to the equivalent function in
     * ODRAdminBundle:APIController.
     *
     * @param string $search_slug
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeExportAction($search_slug, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy(array('searchSlug' => $search_slug));
            if ($datatype_meta == null)
                throw new ODRNotFoundException('Datatype');

            // TODO - filter out metadata datatype?

            $datatype = $datatype_meta->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            $type = 'databases';
            if ($datatype->getIsMasterType())
                $type = 'templates';

            // Let the APIController do the rest of the error-checking
            return $this->forward(
                'ODRAdminBundle:API:getDatatypeExport',
                array(
                    'version' => 'v1',
                    'datatype_uuid' => $datatype->getUniqueId(),
                    '_format' => $request->getRequestFormat(),
                    'type' => $type,
                ),
                $request->query->all()
            );
        } catch (\Exception $e) {
            $source = 0x9ab9a4bf;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Determines datatype id via search slug, then forwards to the equivalent function in
     * ODRAdminBundle:APIController.
     *
     * @param string $search_slug
     * @param Request $request
     *
     * @return Response
     */
    public function getDatarecordListAction($search_slug, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy(array('searchSlug' => $search_slug));
            if ($datatype_meta == null)
                throw new ODRNotFoundException('Datatype');

            // TODO - filter out metadata datatype?

            $datatype = $datatype_meta->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // TODO - apparently this demands the limit/offset parameters are defined beforehand?
            // Let the APIController do the rest of the error-checking
            return $this->forward(
                'ODRAdminBundle:API:getDatarecordList',
                array(
                    'version' => 'v1',
                    'datatype_uuid' => $datatype->getUniqueId(),
                    '_format' => $request->getRequestFormat()
                ),
                $request->query->all()
            );
        } catch (\Exception $e) {
            $source = 0x100ae284;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Determines datatype id via search slug, then forwards to the equivalent function in
     * ODRAdminBundle:APIController.
     *
     * @param string $search_slug
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @return Response
     */
    public function getDatarecordExportAction($search_slug, $datarecord_id, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy(array('searchSlug' => $search_slug));
            if ($datatype_meta == null)
                throw new ODRNotFoundException('Datatype');

            // TODO - filter out metadata datatype?

            $datatype = $datatype_meta->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');


            // Let the APIController do the rest of the error-checking
            return $this->forward(
                'ODRAdminBundle:API:getDatarecordExport',
                array(
                    'version' => 'v1',
                    'datatype_id' => $datatype->getId(),
                    'datarecord_id' => $datarecord->getId(),
                    '_format' => $request->getRequestFormat(),
                ),
                $request->query->all()
            );
        } catch (\Exception $e) {
            $source = 0x50cf3669;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Attempts to convert the given json-encoded key into a format usable by ODR, and then attempts
     * to run a search that returns results across multiple datatypes.
     *
     * @param string $version
     * @param string $json_key
     * @param integer $limit
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function searchTemplateGetAction($version, $json_key, $limit, $offset, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to only showing all info about the datatype/template...
            $display_metadata = true;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'false')
                // ...but restrict to only the most useful info upon request
                $display_metadata = false;

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordExportService $dre_service */
            $dre_service = $this->container->get('odr.datarecord_export_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var TokenGenerator $tokenGenerator */
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');


            // ----------------------------------------
            // Validate the given search information
            $search_key = $search_key_service->convertBase64toSearchKey($json_key);
            $search_key_service->validateTemplateSearchKey($search_key);

            // Now that the search key is valid, load the datatype being searched on
            $params = $search_key_service->decodeSearchKey($search_key);
            $dt_uuid = $params['template_uuid'];

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dt_uuid
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // TODO - enforce permissions on template side?
            // If either the datatype or the datarecord is not public, and the user doesn't have
            //  the correct permissions...then don't allow them to view the datarecord
//            if ( !$pm_service->canViewDatatype($user, $datatype) )
//                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Allow users to specify positive integer values less than a billion for these variables
            $offset = intval($offset);
            $limit = intval($limit);

            // If limit is set to 0, then return all results
            if ($limit === 0)
                $limit = 999999999;

            // TODO - Is this necessary?
            if ($offset >= 1000000000)
                throw new ODRBadRequestException('Offset must be less than a billion');
            if ($limit >= 1000000000)
                throw new ODRBadRequestException('Limit must be less than a billion');


            // ----------------------------------------
            // Run the search
            $search_results = $search_api_service->performTemplateSearch($search_key, $user_permissions);
            $datarecord_list = $search_results['grandparent_datarecord_list'];

            // Apply limit/offset to the results
            // TODO Querying with limit and offset would be much faster most likely
            $datarecord_list = array_slice($datarecord_list, $offset, $limit);

            // Render the resulting list of datarecords into a single chunk of export data
            $baseurl = $this->container->getParameter('site_baseurl');
            $data = $dre_service->getData(
                $version,
                $datarecord_list,
                $request->getRequestFormat(),
                $display_metadata,
                $user,
                $baseurl,
                1,
                true
            );


            // ----------------------------------------
            // Set up a response to return the datarecord list
            $response = new Response();

            if ($download_response) {
                // Generate a token for this download
                $token = substr($tokenGenerator->generateToken(), 0, 15);

                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="' . $token . '.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        } catch (\Exception $e) {
            $source = 0x9c2fcbde;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    public function searchTemplateGetTestAction($search_key, $version, $limit, $offset, Request $request) {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var SearchKeyService $search_key_service */
        $search_key_service = $this->container->get('odr.search_key_service');

        $search_key = $search_key_service->convertBase64toSearchKey($search_key);
        // $search_key_service->validateTemplateSearchKey($search_key);

        // Now that the search key is valid, load the datatype being searched on
        $params = $search_key_service->decodeSearchKey($search_key);
        print "<pre>";print_r($params);print "</pre>";
        $dt_uuid = $params['template_uuid'];

        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
            array(
                'unique_id' => $dt_uuid
            )
        );
        if ($datatype == null)
            throw new ODRNotFoundException('Datatype');

        /** @var SearchAPIServiceNoConflict $search_api_service */
        $search_api_service = $this->container->get('odr.search_api_service_no_conflict');
        $baseurl = $this->container->getParameter('site_baseurl');
        $records = $search_api_service->fullTemplateSearch($datatype, $baseurl, $params);

        // Render the base html for the page...$this->render() apparently creates and automatically returns a full Reponse object
        // Grab the current user
        /** @var ODRUser $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        $html = $this->renderView(
            'ODROpenRepositorySearchBundle:Default:test.html.twig',
            array(
                'records' => '<pre>' . var_export($records,true) . '</pre>',
                'user' => $user,
                'datatype_permissions' => array()
            )
        );

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }

    public function elasticSearchAction($version, $limit, $offset, Request $request) {

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var SearchKeyService $search_key_service */
        $search_key_service = $this->container->get('odr.search_key_service');

        $post = $request->request->all();
        if ( !isset($post['search_key']) )
            throw new ODRBadRequestException();

        $search_key = $post['search_key'];

        $params = json_decode(base64_decode($search_key), true);
        $dt_uuid = $params['template_uuid'];

        // $output =  json_encode($params);
        // return new Response($output);

        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                   'unique_id' => $dt_uuid
                )
            );

        if ($datatype == null)
            throw new ODRNotFoundException('Datatype');

        // /** @var SearchAPIServiceNoConflict $search_api_service */
        // $search_api_service = $this->container->get('odr.search_api_service_no_conflict');
        // $baseurl = $this->container->getParameter('site_baseurl');
        // $records = $search_api_service->fullTemplateSearch($datatype, $baseurl, $params);

        // Slice for Limit/Offset
        // $output_records = array_slice($records, $offset, $limit);

        $url = "http://localhost:9299/ahed/_search?q=*:*";
        $ch = curl_init( $url );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $output = curl_exec($ch);
        curl_close($ch);
        return new Response($output);
    }


    /**
     * @param $version
     * @param $dataset_uuid
     * @param $limit
     * @param $offset
     * @param Request $request
     * @return Response|void
     * @throws ODRException
     * @throws ODRNotFoundException
     */
    public function networkSearchAPIAction($version, $dataset_uuid, $limit, $offset, Request $request) {
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
            // If using Wordpress - check for Wordpress User and Log them in.
            if($this->container->getParameter('odr_wordpress_integrated')) {
                $odr_wordpress_user = getenv("WORDPRESS_USER");
                if($odr_wordpress_user) {
                    // print $odr_wordpress_user . ' ';
                    $user_manager = $this->container->get('fos_user.user_manager');
                    /** @var User $admin_user */
                    $admin_user = $user_manager->findUserBy(array('email' => $odr_wordpress_user));
                }
            }

            $user_permissions = $pm_service->getUserPermissionsArray($admin_user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            // Store if logged in or not
            $logged_in = true;
            if ($admin_user == 'anon.')
                $logged_in = false;

            /** @var DataType $target_datatype */
            $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dataset_uuid
                )
            );
            if ($target_datatype == null)
                throw new ODRNotFoundException('Datatype');

            // ----------------------------------------
            // Check if user has permission to view datatype

            $target_datatype_id = $target_datatype->getId();
            if ( !$pm_service->canViewDatatype($admin_user, $target_datatype) ) {
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
            // Need to build everything used by the sidebar...
            $datatype_array = $ssb_service->getSidebarDatatypeArray($admin_user, $target_datatype->getId());
            $datatype_relations = $ssb_service->getSidebarDatatypeRelations($datatype_array, $target_datatype_id);
            $user_list = $ssb_service->getSidebarUserList($admin_user, $datatype_array);

            // ----------------------------------------
            // Grab a random background image if one exists and the user is allowed to see it
            $background_image_id = null;

            // ----------------------------------------
            // Generate a random key to identify this tab
            $odr_tab_id = $odr_tab_service->createTabId();

            // TODO - modify search page to allow users to select from available themes
            $preferred_theme_id = $theme_info_service->getPreferredTheme($admin_user, $target_datatype_id, 'search_results');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dataset_uuid
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Random cross domain search
            $log = "Cross domain search: " . $datatype->getUniqueId() . '<br />';
            // $url = "http://localhost:9299/" . $datatype->getUniqueId() . '/_search?*.*&pretty=true';
            $url = "http://localhost:9299/" . $datatype->getUniqueId() . '/_search?pretty=true';
            $ch = curl_init($url);
            # Setup request to send json via POST.
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            $query = '{
                "from": 0,
                "size": 20,
                "query": {
                   "function_score": {
                     "boost": "5",
                     "random_score": {},
                     "boost_mode": "multiply"
                   }
                }
             }';

            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            # Return response instead of printing.
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            # Send request.
            $result = curl_exec($ch);
            $log .= $result;

            $search_result = json_decode($result, true);
            curl_close($ch);

            $output = '';
            $output .= $this->get('templating')->render(
                'ODROpenRepositorySearchBundle:TemplateSearch:result_header.html.twig',
            );
            $counter = 1;
            foreach($search_result['hits']['hits'] as $record_data) {
                $record = $record_data['_source'];
                $output .= $this->get('templating')->render(
                    'ODROpenRepositorySearchBundle:TemplateSearch:result.html.twig',
                    array(
                        'record'=> $record,
                        'record_num' => $counter
                    )
                );

                $counter++;
            }









            // ----------------------------------------
            // Render just the html for the base page and the search page...$this->render() apparently creates a full Response object
            $site_baseurl = $this->container->getParameter('site_baseurl');
            $html = $this->renderView(
                'ODROpenRepositorySearchBundle:TemplateSearch:index.html.twig',
                array(
                    // required twig/javascript parameters
                    'user' => $admin_user,
                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,
                    'search_results' => $output,

                    'user_list' => $user_list,
                    'logged_in' => $logged_in,
                    'window_title' => $target_datatype->getShortName(),
                    'intent' => 'searching',
                    'sidebar_reload' => false,
                    'search_slug' => '',
                    'site_baseurl' => $site_baseurl,
                    'search_string' => '',
                    'odr_tab_id' => $odr_tab_id,

                    // required for background image
                    'background_image_id' => $background_image_id,

                    // datatype/datafields to search
                    'search_params' => array(),
                    'target_datatype' => $target_datatype,
                    'datatype_array' => $datatype_array,
                    'datatype_relations' => $datatype_relations,

                    // theme selection
//                    'available_themes' => $available_themes,
                    'preferred_theme_id' => $preferred_theme_id,
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
            print $e; exit();
            $source = 0xd75fa46d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');
        // $response->headers->setCookie(new Cookie('prev_searched_datatype', $search_slug));
        return $response;
    }

    /**
     *
     *  https://beta.rruff.net/odr/rruff_samples#/odr/search/list/fb6f69c3bd16119659b4d058967a
     *
     *
     * @param $version
     * @param $dataset_uuid
     * @param $limit
     * @param $offset
     * @param Request $request
     * @throws ODRBadRequestException
     * @throws ODRNotFoundException
     */
    public function listRecordsAPIAction($version, $dataset_uuid, $limit, $offset, Request $request) {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
            array(
                'unique_id' => $dataset_uuid
            )
        );
        if ($datatype == null)
            throw new ODRNotFoundException('Datatype');

        // Ensure all index exists
        $url = "http://localhost:9299/" . $datatype->getUniqueId();
        $ch = curl_init($url);
        # Setup request to send json via POST.
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        # Return response instead of printing.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        # Send request.
        $result = curl_exec($ch);
        $log .= $result;
        curl_close($ch);

        // Ensure all index has total_field limit set properly
        $log .= "Setting index total_field limit: " . $datatype->getUniqueId() . '<br />';
        $url = "http://localhost:9299/" . $datatype->getUniqueId() . '/_settings';
        $ch = curl_init($url);
        # Setup request to send json via POST.
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS,'{"index.mapping.total_fields.limit": 3000}');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        # Return response instead of printing.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        # Send request.
        $result = curl_exec($ch);
        $log .= $result;
        curl_close($ch);


        // Also build index for cross template search
        if($datatype->getMasterDataType()) {
             $log .= "Creating Index: " . $datatype->getMasterDataType()->getUniqueId() . '<br />';
             // Ensure all index exists
             $url = "http://localhost:9299/" . $datatype->getMasterDataType()->getUniqueId();
             $ch = curl_init($url);
             # Setup request to send json via POST.
             curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
             curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
             # Return response instead of printing.
             curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
             # Send request.
             $result = curl_exec($ch);
             $log .= $result . '<br />';
             curl_close($ch);

            $log .= "Setting index total_field limit: " . $datatype->getMasterDataType()->getUniqueId() . '<br />';
            // Ensure all index has total_field limit set properly
            $url = "http://localhost:9299/" . $datatype->getMasterDataType()->getUniqueId() . '/_settings';
            $ch = curl_init($url);
            # Setup request to send json via POST.
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{"index.mapping.total_fields.limit": 3000}');
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            # Return response instead of printing.
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            # Send request.
            $result = curl_exec($ch);
            $log .= $result . '<br />';
            curl_close($ch);
        }

        /** @var DataRecord[] $records */
        $records = $em->getRepository('ODRAdminBundle:DataRecord')
            ->findBy(
                array(
                    'dataType' => $datatype
                )
            );

        /** @var SearchAPIServiceNoConflict $search_api_service */
        $search_api_service = $this->container->get('odr.search_api_service_no_conflict');

        $baseurl = $this->container->getParameter('site_baseurl');

        $output_records = [];
        foreach($records as $record) {
            // Render the requested datarecord
            $data = $search_api_service->getRecordData(
                $version,
                $record->getUniqueId(),
                $baseurl,
                'json',
                true,
                null,
                0
            );

            array_push($output_records, json_decode($data));

            // Upload each record to ElasticSearch (temporary)
            /*
             * {"_index":"ahed","_id":"7e7f170c64ee9790344baccc170c","_version":1,"result":"created","_shards":{"total":2,"successful":1,"failed":0},"_seq_no":0,"_primary_term":1}
            */
            $log .= "Pushing document: " . $datatype->getUniqueId() . '--' . $record->getUniqueId() . '<br />';
            $url = "http://localhost:9299/" . $datatype->getUniqueId() . "/_doc/" . $record->getUniqueId();
            $ch = curl_init($url);
            # Setup request to send json via POST.
            $payload = $data;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            # Return response instead of printing.
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            # Send request.
            $result = curl_exec($ch);
            $log .= $result . "<br />";
            curl_close($ch);
            # Print response.

            // Also build index for cross template search
            if($datatype->getMasterDataType()) {
                $log .= "Pushing document: " . $datatype->getMasterDataType()->getUniqueId() . '--' . $record->getUniqueId() . '<br />';
                $url = "http://localhost:9299/" . $datatype->getMasterDataType()->getUniqueId() . "/_doc/" . $record->getUniqueId();
                $ch = curl_init($url);
                # Setup request to send json via POST.
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                # Return response instead of printing.
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                # Send request.
                $result = curl_exec($ch);
                $log .= $result . "<br />";
                curl_close($ch);
            }

            $log .= "<br /><br />" . $data . "<br /><br />";
            // print $log;exit();
        }

        // Slice for Limit/Offset
        if($limit == 0) $limit = 999999999;
        $output_records = array_slice($output_records, $offset, $limit);

        // Wrap array for JSON compliance
        $output = array(
            'count' => count($records),
            'master_datatype_id' => $datatype->getMasterDataType()->getUniqueId(),
        );
        // 'records' => $output_records

        print_r($output);exit();
        // return new JsonResponse($output);

    }

    public function searchTemplatePostOptimizedAction($version, $limit, $offset, Request $request) {

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var SearchKeyService $search_key_service */
        $search_key_service = $this->container->get('odr.search_key_service');

        $post = $request->request->all();
        if ( !isset($post['search_key']) )
            throw new ODRBadRequestException();
        $search_key = $post['search_key'];

        $params = json_decode(base64_decode($search_key), true);
        $dt_uuid = $params['template_uuid'];

        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
            array(
                'unique_id' => $dt_uuid
            )
        );
        if ($datatype == null)
            throw new ODRNotFoundException('Datatype');

        /** @var SearchAPIServiceNoConflict $search_api_service */
        $search_api_service = $this->container->get('odr.search_api_service_no_conflict');
        $baseurl = $this->container->getParameter('site_baseurl');
        $records = $search_api_service->fullTemplateSearch($datatype, $baseurl, $params);

        // Slice for Limit/Offset
        $output_records = array_slice($records, $offset, $limit);

        // Wrap array for JSON compliance
        $output = array(
            'count' => count($records),
            'records' => $output_records
        );
        return new JsonResponse($output);

    }

    /**
     * Attempts to convert a POST request into a format usable by ODR, and then attempts to run a
     * search that returns results across multiple datatypes.
     *
     * @param string $version
     * @param integer $limit
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function searchTemplatePostAction($version, $limit, $offset, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to only showing all info about the datatype/template...
            $display_metadata = true;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'false')
                // ...but restrict to only the most useful info upon request
                $display_metadata = false;

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordExportService $dre_service */
            $dre_service = $this->container->get('odr.datarecord_export_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var TokenGenerator $tokenGenerator */
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');


            // ----------------------------------------
            // Validate the given search information
            $post = $request->request->all();
            if ( !isset($post['search_key']) )
                throw new ODRBadRequestException();
            $base64 = $post['search_key'];

            $search_key = $search_key_service->convertBase64toSearchKey($base64);
            $search_key_service->validateTemplateSearchKey($search_key);

            // Now that the search key is valid, load the datatype being searched on
            $params = $search_key_service->decodeSearchKey($search_key);
            $dt_uuid = $params['template_uuid'];

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dt_uuid
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            // Only public records currently...
            // TODO Determine a better way to determine how API Users should get public/private records
            // TODO - act as user should be passed on this call?
            $user = "anon.";
            // $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // TODO - enforce permissions on template side?
            // If either the datatype or the datarecord is not public, and the user doesn't have
            //  the correct permissions...then don't allow them to view the datarecord
//            if ( !$pm_service->canViewDatatype($user, $datatype) )
//                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Allow users to specify positive integer values less than a billion for these variables
            $offset = intval($offset);
            $limit = intval($limit);

            // If limit is set to 0, then return all results
            if ($limit === 0)
                $limit = 999999999;

            if ($offset >= 1000000000)
                throw new ODRBadRequestException('Offset must be less than a billion');
            if ($limit >= 1000000000)
                throw new ODRBadRequestException('Limit must be less than a billion');


            // ----------------------------------------
            // Run the search
            $search_results = $search_api_service->performTemplateSearch($search_key, $user_permissions);
            $datarecord_list = $search_results['grandparent_datarecord_list'];

            // Apply limit/offset to the results
            $datarecord_list = array_slice($datarecord_list, $offset, $limit);

            // Render the resulting list of datarecords into a single chunk of export data
            $baseurl = $this->container->getParameter('site_baseurl');
            $data = $dre_service->getData(
                $version,
                $datarecord_list,
                $request->getRequestFormat(),
                $display_metadata,
                $user,
                $baseurl,
                1,
                true
            );


            // ----------------------------------------
            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                // Generate a token for this download
                $token = substr($tokenGenerator->generateToken(), 0, 15);

                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="'.$token.'.'.$request->getRequestFormat().'";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x7f543ec7;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
