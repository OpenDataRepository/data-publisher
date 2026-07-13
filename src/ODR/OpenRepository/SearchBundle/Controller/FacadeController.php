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
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIServiceNoConflict;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchSidebarService;
use ODR\OpenRepository\UserBundle\Component\Service\TrackedPathService;
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
                    'record_uuid' => $datarecord->getUniqueId(),
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
     * Runs a template-level search and returns the matching records as export data (JSON or XML).
     *
     * A "template search" is performed against a master template's field/radio-option/tag UUIDs
     * rather than against the concrete field IDs of a single database.  Because every database
     * derived from a template shares the template's UUIDs, a single search key can therefore return
     * results across every datatype built from that template.
     *
     *
     * ---------------------------------------------------------------------------------------------
     * THE SEARCH KEY
     * ---------------------------------------------------------------------------------------------
     * The `$json_key` path segment is a URL-safe Base64 encoding of a JSON object (see the
     * "ENCODING" section below for exactly how to produce it).  Once decoded, the JSON object has
     * the following shape:
     *
     *   {
     *       "template_uuid": "92kasdfh2l",       // REQUIRED. unique_id of the master template to search.
     *       "template_name": "AHED Core 1.0",    // optional, informational only (ignored by the search).
     *       "general": "",                       // optional general/"search everything" text string.
     *       "fields": [ ... ],                   // optional array of per-field criteria (see below).
     *       "sort_by": [ ... ]                   // optional; at most ONE entry is currently supported.
     *   }
     *
     * Only "template_uuid" is mandatory, and it must match `/^[a-z0-9]+$/`.  Every UUID referenced
     * anywhere in the key ("template_field_uuid", "template_radio_option_uuid", "template_tag_uuid")
     * must actually belong to that template, or the request is rejected with a 400.  An empty key
     * (just a "template_uuid") matches every record built from the template.
     *
     * The "general" key holds a free-text string that is matched across all general-search-enabled
     * fields, exactly like the single search box in the ODR UI.
     *
     * The "sort_by" key is an array of objects, each with:
     *   - "template_field_uuid": the field to sort on
     *   - "dir": either "asc" or "desc"
     * NOTE: multi-field sorting is not implemented yet, so at most one entry may be provided.
     *
     *
     * ---------------------------------------------------------------------------------------------
     * THE "fields" ARRAY
     * ---------------------------------------------------------------------------------------------
     * Each entry in "fields" is an object that MUST contain a "template_field_uuid", plus exactly
     * one criteria key.  The criteria key that is allowed depends on the field's type:
     *
     *   - Text/Number/Boolean fields (ShortVarchar, MediumVarchar, LongVarchar, LongText,
     *     IntegerValue, DecimalValue, Boolean, XYZData) use "value":
     *         { "template_field_uuid": "abc123", "value": "quartz" }
     *
     *   - Radio/Select fields use "selected_options", an array of objects each holding a
     *     "template_radio_option_uuid" (the "name" is informational only):
     *         { "template_field_uuid": "abc123", "selected_options": [
     *               { "name": "Collection", "template_radio_option_uuid": "mc1kasdfsj" } ] }
     *
     *   - Tag fields use "selected_tags", an array of objects each holding a "template_tag_uuid":
     *         { "template_field_uuid": "abc123", "selected_tags": [
     *               { "template_tag_uuid": "0f9ktag12" } ] }
     *
     *   - Datetime fields use "before" and/or "after", each a "Y-m-d" date string:
     *         { "template_field_uuid": "abc123", "after": "2020-01-01", "before": "2020-12-31" }
     *
     *   - File/Image fields use "filename", "public_status", and/or "quality".
     *
     * An optional, informational "field_name" may be included on any field entry; it is ignored by
     * the search and exists only to make the decoded key human-readable.
     *
     * A worked example of a decoded key (two radio-option filters plus a sort):
     *   {
     *       "fields": [
     *           {
     *               "field_name": "Resource Type",
     *               "selected_options": [
     *                   {
     *                       "name": "Collection",
     *                       "template_radio_option_uuid": "mc1kasdfsj"
     *                   }
     *               ],
     *               "template_field_uuid": "mc82kdkgh"
     *           },
     *           {
     *               "field_name": "Funding Sources",
     *               "selected_options": [
     *                   {
     *                       "name": "Foreign &gt; Government &gt; ESA (EU)",
     *                       "template_radio_option_uuid": "28dkvmaso8"
     *                   }
     *               ],
     *               "template_field_uuid": "mxcnvu2nd"
     *           }
     *       ],
     *       "general": "",
     *       "sort_by": [
     *           {
     *               "dir": "asc",
     *               "template_field_uuid": "72dhuwkdk"
     *           }
     *       ],
     *       "template_name": "AHED Core 1.0",
     *       "template_uuid": "92kasdfh2l"
     *   }
     *
     *
     * ---------------------------------------------------------------------------------------------
     * ENCODING THE KEY (URL-safe Base64)
     * ---------------------------------------------------------------------------------------------
     * The JSON object above is not passed raw; it is encoded so it can travel safely inside a URL
     * path segment.  ODR uses a URL-safe variant of Base64 (see
     * {@link SearchKeyService::encodeSearchKey()} / {@link SearchKeyService::convertBase64toSearchKey()}).
     * To build `$json_key` from the JSON object:
     *
     *   1. Serialize the object to a JSON string (e.g. `json_encode($params)`).
     *   2. Base64-encode that string (`base64_encode(...)`).
     *   3. Strip any trailing '=' padding characters.
     *   4. Replace every '+' with '-' and every '/' with '_'.
     *
     * In PHP this is exactly:
     *   $json_key = strtr( rtrim( base64_encode( json_encode($params) ), '=' ), '+/', '-_' );
     *
     * Decoding (performed automatically by this action) simply reverses steps 4-2:
     *   $params = json_decode( base64_decode( strtr($json_key, '-_', '+/') ), true );
     *
     * A raw Base64 string will still decode correctly, but URLs containing '+', '/', or '=' can be
     * mangled by proxies/servers, so always use the URL-safe form above when constructing links.
     *
     * NOTE on oversized keys: if the encoded key would be too long for a URL, ODR can instead store
     * the real key server-side and hand out a short key containing only a "uuid" that points at it.
     * This action handles that transparently via {@link SearchKeyService::decodeSearchKey()}; callers
     * building keys by hand do not need to do anything special.
     *
     *
     * ---------------------------------------------------------------------------------------------
     * QUERY PARAMETERS
     * ---------------------------------------------------------------------------------------------
     *   - ?metadata=false : trims the response down to the most useful info instead of full metadata.
     *   - ?download=file  : returns the results as a file download instead of inline in the browser.
     *
     * @param string $version The API/export version (e.g. "v3").
     * @param string $json_key The URL-safe Base64 encoded template search key (see above).
     * @param integer $limit Maximum number of records to return; 0 means "return all".
     * @param integer $offset Number of leading records to skip (for pagination).
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

            /** @var DatarecordExportService $datarecord_export_service */
            $datarecord_export_service = $this->container->get('odr.datarecord_export_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
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
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            // TODO - enforce permissions on template side?
            // If either the datatype or the datarecord is not public, and the user doesn't have
            //  the correct permissions...then don't allow them to view the datarecord
//            if ( !$permissions_service->canViewDatatype($user, $datatype) )
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
            $data = $datarecord_export_service->getData(
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


    /**
     * Runs a search against a single (non-template) datatype and returns the matching records as
     * export data (JSON or XML).
     *
     * Unlike {@link self::searchTemplateGetAction()}, this is a POST endpoint that accepts a plain,
     * human-readable JSON search key in the request body -- no base64 encoding required.  The JSON
     * uses the same general shape as the template search, but targets one datatype by its uuid and
     * lets each field be identified by either its own "field_uuid" or the "template_field_uuid" of
     * the master-template field it derives from:
     *
     *   {
     *       "dataset_uuid": "<datatype unique_id>",   // REQUIRED (may also be given in the URL)
     *       "general": "quartz",                       // optional general search string
     *       "fields": [
     *           { "field_uuid": "abc123", "value": "Gold" },
     *           { "template_field_uuid": "mc82kdkgh", "selected_options": [
     *               { "radio_option_uuid": "mc1kasdfsj" } ] }
     *       ],
     *       "sort_by": [
     *           { "field_uuid": "72dhuwkdk", "dir": "asc" }
     *       ]
     *   }
     *
     * See {@link SearchKeyService::convertJsonToDatasetSearchKey()} for the full set of per-field
     * criteria keys ("value", "selected_options", "selected_tags", "before"/"after", "filename",
     * "public_status", "quality").
     *
     * Query parameters:
     *   - ?metadata=false : trims the response down to the most useful info instead of full metadata.
     *   - ?download=file  : returns the results as a file download instead of inline.
     *
     * @param string $version
     * @param string $dataset_uuid The unique_id of the datatype to search.  A "dataset_uuid" in the
     *                             JSON body, if present, takes precedence over this.
     * @param integer $limit Maximum number of records to return; 0 means "return all".
     * @param integer $offset Number of leading records to skip.
     * @param Request $request
     *
     * @return Response
     */
    public function datasetSearchByUUIDAction($version, $dataset_uuid, $limit, $offset, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to showing all info about the datatype...
            $display_metadata = true;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'false')
                $display_metadata = false;

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                $download_response = true;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordExportService $datarecord_export_service */
            $datarecord_export_service = $this->container->get('odr.datarecord_export_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var TokenGenerator $tokenGenerator */
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');


            // ----------------------------------------
            // Parse the JSON search request from the POST body
            $content = $request->getContent();
            $json = array();
            if ($content !== '') {
                $json = json_decode($content, true);
                if ($json === null)
                    throw new ODRBadRequestException('Invalid search request: request body is not valid JSON');
            }

            // Convert the friendly JSON request into a regular search key, then validate it
            $search_key = $search_key_service->convertJsonToDatasetSearchKey($dataset_uuid, $json);
            $search_key_service->validateSearchKey($search_key);

            // The search key is valid, so the datatype it references exists
            $params = $search_key_service->decodeSearchKey($search_key);

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find( $params['dt_id'] );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            // Only allow searching a datatype the user is allowed to view
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            // ----------------------------------------
            // Guard against a subtle footgun: the search system silently discards criteria that
            //  reference fields the user can't view, and an empty search then returns EVERY record.
            //  To avoid confusing "why is a value search returning non-matching records" behavior,
            //  detect any criteria that would be stripped and fail loudly instead.
            // NOTE: $ignore_searchable is true here (and in performSearch below) on purpose -- the
            //  DataFields "searchable" flag only controls whether a field shows up in the search
            //  sidebar UI, and shouldn't prevent an API caller from explicitly searching a field.
            //  View permissions are still enforced.
            $filtered_search_key = $search_api_service->filterSearchKeyForUser(
                $datatype->getId(),
                $search_key,
                $user_permissions,
                false,  // don't search as super-admin
                true    // ignore the "searchable" flag; only enforce view permissions
            );
            $filtered_params = $search_key_service->decodeSearchKey($filtered_search_key);

            // Any of my criteria keys that didn't survive filtering reference a non-viewable field
            $reserved_keys = array('dt_id', 'gen', 'gen_lim', 'merge', 'set', 'inverse', 'ignore', 'sort_by');
            $dropped = array();
            foreach ($params as $key => $value) {
                if ( in_array($key, $reserved_keys, true) )
                    continue;
                if ( !array_key_exists($key, $filtered_params) )
                    $dropped[] = $key;
            }
            if ( !empty($dropped) )
                throw new ODRForbiddenException('The search references one or more fields you do not have permission to view');


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
            // Run the search against the single datatype.  Passing the user's permissions (and not
            //  searching as a super-admin) means non-public records get filtered out automatically.
            //  $ignore_searchable is true so that fields flagged "not searchable" in the UI can still
            //  be searched when explicitly requested through the API.
            $datarecord_list = $search_api_service->performSearch(
                $datatype,
                $search_key,
                $user_permissions,
                false,      // return_complete_list
                array(),    // sort_datafields
                array(),    // sort_directions
                false,      // search_as_super_admin
                true        // ignore_searchable
            );

            // Apply limit/offset to the results
            $datarecord_list = array_slice($datarecord_list, $offset, $limit);

            // Render the resulting list of datarecords into a single chunk of export data
            $baseurl = $this->container->getParameter('site_baseurl');
            $data = $datarecord_export_service->getData(
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
                $response->headers->set('Content-Disposition', 'attachment; filename="' . $token . '.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        } catch (\Exception $e) {
            $source = 0x7f31ac05;
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
        $is_wordpress_integrated = $this->container->getParameter('odr_wordpress_integrated');
        $wordpress_site_baseurl = $this->container->getParameter('wordpress_site_baseurl');
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
                'datatype_permissions' => array(),
                'odr_wordpress_integrated' => $is_wordpress_integrated,
                'wordpress_site_baseurl' => $wordpress_site_baseurl,
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
            $limit = intval($request->query->get('limit'));
            $offset = intval($request->query->get('offset'));
            if($limit > 100) $limit = 100;
            if($limit === 0) $limit = 10;

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
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
            // TODO - where is this used?
            $search_params = $default_search_params = array();

            // Need to build everything used by the sidebar...
            $sidebar_layout_id = $search_sidebar_service->getPreferredSidebarLayoutId($admin_user, $target_datatype->getId(), 'searching');
            $sidebar_array = $search_sidebar_service->getSidebarDatatypeArray(
                $admin_user,
                $target_datatype->getId(),
                $search_params,
                $default_search_params,
                'searching',
                $sidebar_layout_id
            );
            $user_list = $search_sidebar_service->getSidebarUserList($admin_user, $sidebar_array);

            // ----------------------------------------
            // Grab a random background image if one exists and the user is allowed to see it
            $background_image_id = null;

            // ----------------------------------------
            // Generate a random key to identify this tab
            $odr_tab_id = $odr_tab_service->createTabId();

            // TODO - modify search page to allow users to select from available themes
            $preferred_theme_id = $theme_info_service->getPreferredThemeId($admin_user, $target_datatype_id, 'search_results');

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
            // $url =$this->container->getParameter('elastic_server_baseurl') . $datatype->getUniqueId() . '/_search?*.*&pretty=true';
            $url =$this->container->getParameter('elastic_server_baseurl')[0] . '/' . $datatype->getUniqueId() . '/_search?pretty=true';
            // print $url . "<br />";exit();
            $ch = curl_init($url);
            # Setup request to send json via POST.
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            $query = '{
                "from": ' . $offset . ',
                "size": ' . $limit . ',
                "sort" : [
                    { "internal_id" : {"order" : "asc"}}
                ]
             }';
            // { "fields_eb0451ce86d7f6cd20505170ea69.field_d6d969a74e57069e46e0cd3212ff.value" : {"order" : "asc"}}
            // { "fields_eb0451ce86d7f6cd20505170ea69.field_d6d969a74e57069e46e0cd3212ff" : {"order" : "asc"}}
            // function_score": {
              //   "boost": "5",
                //      "random_score": {},
                  //    "boost_mode": "multiply"
                   // }

            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            # Return response instead of printing.
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            # Send request.
            $result = curl_exec($ch);
            $log .= $result;

            // print $log;exit();
            $search_result = json_decode($result, true);
            curl_close($ch);

            $output = '';
            $output .= $this->get('templating')->render(
                'ODROpenRepositorySearchBundle:TemplateSearch:result_header.html.twig',
                array(
                    'total' => $search_result['hits']['total']['value'], // total records
                    'limit' => $limit,
                    'offset' => $offset
                )
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
            $is_wordpress_integrated = $this->container->getParameter('odr_wordpress_integrated');
            $wordpress_site_baseurl = $this->container->getParameter('wordpress_site_baseurl');

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
                    'odr_wordpress_integrated' => $is_wordpress_integrated,
                    'wordpress_site_baseurl' => $wordpress_site_baseurl,

                    // required for background image
                    'background_image_id' => $background_image_id,

                    // datatype/datafields to search
                    'search_params' => array(),
                    'target_datatype' => $target_datatype,
                    'sidebar_array' => $sidebar_array,

                    // theme selection
//                    'available_themes' => $available_themes,
                    'preferred_theme_id' => $preferred_theme_id,
                )
            );

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
     * Seeds the ElasticSearch database with data from a list of dataset_uuids
     * stored in an array in parameters.yml.  Note that only dataset_uuids and
     * not mastter type uuids should be present in the array.  If the dataset
     * is derived from a Naster type, its public will automatically be added
     * to the master index for cross-template searching.
     *
     * @param $record_uuid
     * @param $version
     * @param Request $request
     * @return Response|void
     * @throws ODRBadRequestException
     * @throws ODRNotFoundException
     */
    public function seedElasticRecordAction($record_uuid, $version, Request $request) {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var DataRecord $record */
        $record = $em->getRepository('ODRAdminBundle:DataRecord')
            ->findOneBy(
                array(
                    'unique_id' => $record_uuid
                )
            );

        if(!$record) {
            throw new ODRNotFoundException('Record');
        }

        // Get the datatype
        $datatype = $record->getDataType();

        /** @var SearchAPIServiceNoConflict $search_api_service */
        $search_api_service = $this->container->get('odr.search_api_service_no_conflict');

        $data = $search_api_service->getRecordData(
            $version,
            $record->getUniqueId(),
            $this->container->getParameter('site_baseurl'),
            'json',
            true,
            null,
            0
        );

        if(!preg_match('/\{/', $data)) {
            // TODO Figure out why some have no data...
            print 'NO DATA'; exit();
        }

        // Upload each record to ElasticSearch (temporary)
        // TODO Offload to a Beanstalk Queue
        /*
         * {"_index":"ahed","_id":"7e7f170c64ee9790344baccc170c","_version":1,"result":"created","_shards":{"total":2,"successful":1,"failed":0},"_seq_no":0,"_primary_term":1}
        */
        $log = "Pushing document: " . $datatype->getUniqueId() . '--' . $record->getUniqueId() . '<br />';
        $url =$this->container->getParameter('elastic_server_baseurl') . '/' . $datatype->getUniqueId() . "/_doc/" . $record->getUniqueId();
        $ch = curl_init($url);
        # Setup request to send json via POST.
        $payload = $data;
        // $log .= 'Payload: <br />' . $payload . "<br />";
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
            $url =$this->container->getParameter('elastic_server_baseurl') . '/' . $datatype->getMasterDataType()->getUniqueId() . "/_doc/" . $record->getUniqueId();
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

        return new Response($log);

    }

    /**
     *
     *  https://beta.rruff.net/odr_rruff/elastic
     *
     * @param $version
     * @param Request $request
     * @throws ODRBadRequestException
     * @throws ODRNotFoundException
     */
    public function seedElasticAction($version, Request $request) {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $log = "";

        // Array of dataset UUIDs from parameters.yml
        $dataset_uuids = $this->container->getParameter('elastic_dataset_uuids');

        // Initialize the output array
        $output = [];
        foreach($dataset_uuids as $dataset_uuid) {

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dataset_uuid
                )
            );

            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Ensure all index exists for the datatype
            $log .= "Creating Index: " . $this->container->getParameter('elastic_server_baseurl') . "/" . $datatype->getUniqueId() . '<br />';
            $url = $this->container->getParameter('elastic_server_baseurl') . '/' . $datatype->getUniqueId();
            $ch = curl_init($url);
            # Setup request to send json via POST.
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            # Return response instead of printing.
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            # Send request.
            $result = curl_exec($ch);
            $log .= "Result: " . $result . "<br />";
            curl_close($ch);

            // Ensure all index has total_field limit set properly
            $log .= "Setting index total_field limit: " . $datatype->getUniqueId() . '<br />';
            $url = $this->container->getParameter('elastic_server_baseurl') . '/' . $datatype->getUniqueId() . '/_settings';
            $ch = curl_init($url);
            # Setup request to send json via POST.
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS,'{"index.mapping.total_fields.limit": 6000}');
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            # Return response instead of printing.
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            # Send request.
            $result = curl_exec($ch);
            $log .= "Result: " . $result . "<br />";
            curl_close($ch);

            // Also build index for cross template search
            if($datatype->getMasterDataType()) {
                $log .= "Creating Index: " . $this->container->getParameter('elastic_server_baseurl') . "/" . $datatype->getMasterDataType()->getUniqueId() . '<br />';
                // Ensure all index exists
                $url = $this->container->getParameter('elastic_server_baseurl') . '/' . $datatype->getMasterDataType()->getUniqueId();
                $ch = curl_init($url);
                # Setup request to send json via POST.
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                # Return response instead of printing.
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                # Send request.
                $result = curl_exec($ch);
                $log .= "Result: " . $result . "<br />";
                curl_close($ch);

                $log .= "Setting index total_field limit: " . $datatype->getMasterDataType()->getUniqueId() . '<br />';
                // Ensure all index has total_field limit set properly
                $url = $this->container->getParameter('elastic_server_baseurl') . '/' . $datatype->getMasterDataType()->getUniqueId() . '/_settings';
                $ch = curl_init($url);
                # Setup request to send json via POST.
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt($ch, CURLOPT_POSTFIELDS, '{"index.mapping.total_fields.limit": 6000}');
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                # Return response instead of printing.
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                # Send request.
                $result = curl_exec($ch);
                $log .= "Result: " . $result . "<br />";
                curl_close($ch);
            }

            /** @var DataRecord[] $records */
            // TODO use "updated" to only update records modified within the last 24 hours
            $records = $em->getRepository('ODRAdminBundle:DataRecord')
                ->findBy(
                    array(
                        'dataType' => $datatype
                    )
                );

            // Get the pheanstalk queue
            $pheanstalk = $this->get('pheanstalk');
            $baseurl = $this->container->getParameter('site_baseurl');

            $counter = 0;
            // for($i=0; $i<10; $i++) {
                // $record = $records[$i];
            foreach($records as $record) {
                $url = $this->generateUrl('odr_search_seed_elastic_record', array('record_uuid' => $record->getUniqueId()));
                $url = $baseurl . $url;

                // Add record to pheanstalk queue
                $payload = json_encode(
                    array(
                        'url' => $url,
                    )
                );

                $pheanstalk->useTube('odr_seed_elastic_record')->put($payload);
                // if($counter == 5) {
                    // break;
                // }
                $counter++;

            }

            // Wrap array for JSON compliance
            if($datatype->getMetadataDatatype()) {
                $output[$dataset_uuid] = array(
                    'count' => $counter,
                    'master_datatype_id' => $datatype->getMasterDataType()->getUniqueId(),
                );
            }
            else {
                $output[$dataset_uuid] = array(
                    'count' => $counter,
                    'master_datatype_id' => $datatype->getUniqueId(),
                );
            }
        }

        // return new Response($log);
        // print_r($output);exit();
        return new JsonResponse($output);

    }

    /**
     * @param $version
     * @param Request $request
     * @return JsonResponse
     */
    public function updateRRUFFFilesAction($recent, $version, Request $request) {
        $baseurl = $this->container->getParameter('baseurl_no_prefix');
        $baseurl = $baseurl . "/odr_rruff";

        // Get the pheanstalk queue
        $pheanstalk = $this->get('pheanstalk');

        $job_data = array(
            'base_url' => $baseurl,
            'ima_update_rebuild' => $recent,
            'api_user' => $this->container->getParameter('api_user'),
            'api_key' => $this->container->getParameter('api_key'),
            'rruff_database_uuid' => $this->container->getParameter('rruff_database_uuid')
        );

        // TODO Updating File Zips requires a way to get
        // all modified files and remove them before the update.
        // Need to add odr_get_modified_file_names which will
        // check all descendants of the datarecord list and
        // then find all files that were modified or deleted since the
        // update date.
        $route_name = 'odr_api_datarecord_list';
        if($recent) {
            $route_name = 'odr_api_recent_datarecord_list';
            $recent = '99999999';
        }
        $full_rruff_url = $this->generateUrl(
            $route_name,
            array(
                'datatype_uuid' => $job_data['rruff_database_uuid'],
                'version' => 'v5',
                'recent' => $recent
            )
        );
        $full_rruff_url = $baseurl . $full_rruff_url;


        $route_name = 'odr_api_recently_modified_file_list';
        $rruff_modified_files_url = $this->generateUrl(
            $route_name,
            array(
                'datatype_uuid' => $job_data['rruff_database_uuid'],
                'version' => 'v5',
                'recent' => $recent
            )
        );
        $rruff_modified_files_url = $baseurl . $rruff_modified_files_url;

        // API Login URL
        $api_login_url = $this->generateUrl(
            'api_login_check_v4_odr',
            array(
            )
        );
        $api_login_url = $baseurl . $api_login_url;

        // API Job Create URL
        $api_create_job_url = $this->generateUrl(
            'odr_api_start_job',
            array(
                'version' => 'v4'
            )
        );
        $api_create_job_url = $baseurl . $api_create_job_url;

        // API Job Status URL
        $api_job_status_url = $this->generateUrl(
            'odr_api_job_status',
            array(
                'version' => 'v4'
            )
        );
        $api_job_status_url = $baseurl . $api_job_status_url;

        // API Worker Job Create URL
        $api_worker_job_url = $this->generateUrl(
            'odr_api_worker_job',
            array(
                'version' => 'v4',
            )
        );
        $api_worker_job_url = $baseurl . $api_worker_job_url;



        $job_data['recent'] = $recent;
        $job_data['full_rruff_url'] = $full_rruff_url;
        $job_data['rruff_modified_files_url'] = $rruff_modified_files_url;
        $job_data['api_login_url'] = $api_login_url;

        $job_data['api_worker_job_url'] = $api_worker_job_url;
        $job_data['api_create_job_url'] = $api_create_job_url;
        $job_data['api_job_status_url'] = $api_job_status_url;
        $job_data['api_login_url'] = $api_login_url;

        // Add record to pheanstalk queue
        $payload = json_encode($job_data);

        $pheanstalk->useTube('odr_rruff_record_analyzer')->put($payload);
        // Wrap array for JSON compliance
        $output = array("done" => true );

        return new JsonResponse($output);
    }

    /**
     * @param $version
     * @param Request $request
     * @return JsonResponse
     */
    public function updateAMCSDFilesAction($recent, $version, Request $request) {
        $baseurl = $this->container->getParameter('baseurl_no_prefix');
        $baseurl = $baseurl . "/odr_rruff";

        // Get the pheanstalk queue
        $pheanstalk = $this->get('pheanstalk');

        $job_data = array(
            'base_url' => $baseurl,
            'ima_update_rebuild' => $recent,
            'api_user' => $this->container->getParameter('api_user'),
            'api_key' => $this->container->getParameter('api_key'),
            'amcsd_database_uuid' => $this->container->getParameter('amcsd_database_uuid')
        );

        // TODO Updating File Zips requires a way to get
        // all modified files and remove them before the update.
        // Need to add odr_get_modified_file_names which will
        // check all descendants of the datarecord list and
        // then find all files that were modified or deleted since the
        // update date.
        $route_name = 'odr_api_datarecord_list';
        if($recent) {
            $route_name = 'odr_api_recent_datarecord_list';
            $recent = '99999999';
        }
        $full_amcsd_url = $this->generateUrl(
            $route_name,
            array(
                'datatype_uuid' => $job_data['amcsd_database_uuid'],
                'version' => 'v5',
                'recent' => $recent
            )
        );
        $full_amcsd_url = $baseurl . $full_amcsd_url;


        $route_name = 'odr_api_recently_modified_file_list';
        $amcsd_modified_files_url = $this->generateUrl(
            $route_name,
            array(
                'datatype_uuid' => $job_data['amcsd_database_uuid'],
                'version' => 'v5',
                'recent' => $recent
            )
        );
        $amcsd_modified_files_url = $baseurl . $amcsd_modified_files_url;

        // API Login URL
        $api_login_url = $this->generateUrl(
            'api_login_check_v4_odr',
            array(
            )
        );
        $api_login_url = $baseurl . $api_login_url;

        // API Job Create URL
        $api_create_job_url = $this->generateUrl(
            'odr_api_start_job',
            array(
                'version' => 'v4'
            )
        );
        $api_create_job_url = $baseurl . $api_create_job_url;

        // API Job Status URL
        $api_job_status_url = $this->generateUrl(
            'odr_api_job_status',
            array(
                'version' => 'v4'
            )
        );
        $api_job_status_url = $baseurl . $api_job_status_url;

        // API Worker Job Create URL
        $api_worker_job_url = $this->generateUrl(
            'odr_api_worker_job',
            array(
                'version' => 'v4',
            )
        );
        $api_worker_job_url = $baseurl . $api_worker_job_url;



        $job_data['recent'] = $recent;
        $job_data['full_amcsd_url'] = $full_amcsd_url;
        $job_data['amcsd_modified_files_url'] = $amcsd_modified_files_url;
        $job_data['api_login_url'] = $api_login_url;

        $job_data['api_worker_job_url'] = $api_worker_job_url;
        $job_data['api_create_job_url'] = $api_create_job_url;
        $job_data['api_job_status_url'] = $api_job_status_url;
        $job_data['api_login_url'] = $api_login_url;

        // Add record to pheanstalk queue
        $payload = json_encode($job_data);

        $pheanstalk->useTube('odr_amcsd_record_analyzer')->put($payload);
        // Wrap array for JSON compliance
        $output = array("done" => true );

        return new JsonResponse($output);
    }

    /**
     * @param $version
     * @param $ima_uuid
     * @param $cell_params_uuid
     * @param Request $request
     * @return JsonResponse
     * @throws ODRNotFoundException
     */
    public function IMAListRebuildAction($recent, $version, Request $request) {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Determine API URL for record lists
            /*
                mineral_data: 'web/uploads/mineral_data.js'
                cell_params: 'web/uploads/cell_params.js'
                cell_params_range: 'web/uploads/cell_params_range.js'
                cell_params_synonyms: 'web/uploads/cell_params_synonyms.js'
                tag_data: 'web/uploads/master_tag_data.js'
            */
            $baseurl = $this->container->getParameter('baseurl_no_prefix');
            $baseurl = $baseurl . "/odr_rruff";
            $job_data = array(
                'base_url' => $baseurl,
                'ima_update_rebuild' => $recent,
                'api_user' => $this->container->getParameter('api_user'),
                'api_key' => $this->container->getParameter('api_key'),
                'ima_uuid' => $this->container->getParameter('ima_uuid'),
                'ima_template_uuid' => $this->container->getParameter('ima_template_uuid'),
                'cell_params_uuid' => $this->container->getParameter('cell_params_uuid'),
                'rruff_database_uuid' => $this->container->getParameter('rruff_database_uuid'),
                'reference_database_uuid' => $this->container->getParameter('reference_database_uuid'),
                'amcsd_database_uuid' => $this->container->getParameter('amcsd_database_uuid'),
                'paragenetic_modes_uuid' => $this->container->getParameter('paragenetic_modes_uuid'),
                'mineral_data' => $this->container->getParameter('mineral_data'),
                'cell_params' => $this->container->getParameter('cell_params'),
                'pm_data' => $this->container->getParameter('pm_data'),
                'pm_tag_data' => $this->container->getParameter('pm_tag_data'),
                'references' => $this->container->getParameter('references'),
                'amcsd_file' => $this->container->getParameter('amcsd_file'),
                'cell_params_range' => $this->container->getParameter('cell_params_range'),
                'cell_params_synonyms' => $this->container->getParameter('cell_params_synonyms'),
                'master_tag_data' => $this->container->getParameter('tag_data'),
                'tag_data' => $this->container->getParameter('tag_data')
            );

            // Get the pheanstalk queue
            $pheanstalk = $this->get('pheanstalk');

            $route_name = 'odr_api_datarecord_list';
            $full_ima_url = $this->generateUrl(
                $route_name,
                array(
                    'datatype_uuid' => $job_data['ima_uuid'],
                    'version' => 'v5',
                    'recent' => 0
                )
            );
            $full_ima_url = $baseurl . $full_ima_url;

            if($recent) {
                $route_name = 'odr_api_recent_datarecord_list';
                $recent = '99999999';
            }
            $ima_url = $this->generateUrl(
                $route_name,
                array(
                    'datatype_uuid' => $job_data['ima_uuid'],
                    'version' => 'v5',
                    'recent' => $recent
                )
            );
            $ima_url = $baseurl . $ima_url;

            // TODO Check if this needs to be v5
            $ima_template_url = $this->generateUrl(
                'odr_api_get_template_single',
                array(
                    'datatype_uuid' => $job_data['ima_uuid'],
                    'version' => 'v5'
                )
            );
            $ima_template_url = $baseurl . $ima_template_url;

            $cell_params_url = $this->generateUrl(
                $route_name,
                array(
                    'datatype_uuid' => $job_data['cell_params_uuid'],
                    'version' => 'v5',
                    'recent' => $recent
                )
            );
            $cell_params_url = $baseurl . $cell_params_url;

            $powder_diffraction_url = $this->generateUrl(
                $route_name,
                array(
                    'datatype_uuid' => $job_data['rruff_database_uuid'],
                    'version' => 'v5',
                    'recent' => $recent
                )
            );
            $powder_diffraction_url = $baseurl . $powder_diffraction_url;

            $references_url = $this->generateUrl(
                $route_name,
                array(
                    'datatype_uuid' => $job_data['reference_database_uuid'],
                    'version' => 'v5',
                    'recent' => $recent
                )
            );
            $references_url = $baseurl . $references_url;

            // AMCSD ???
            $amcsd_url = $this->generateUrl(
                $route_name,
                array(
                    'datatype_uuid' => $job_data['amcsd_database_uuid'],
                    'version' => 'v5',
                    'recent' => $recent
                )
            );
            $amcsd_url = $baseurl . $amcsd_url;

            // Paragenetic Modes URL
            $paragenetic_modes_url = $this->generateUrl(
                // 'odr_api_datarecord_list',
                $route_name,
                array(
                    'datatype_uuid' => $job_data['paragenetic_modes_uuid'],
                    'version' => 'v5',
                    'recent' => $recent
                )
            );
            $paragenetic_modes_url = $baseurl . $paragenetic_modes_url;

            // Paragenetic Modes Template URL
            $paragenetic_modes_template_url = $this->generateUrl(
                'odr_api_get_template_single',
                // $route_name,
                array(
                    'datatype_uuid' => $job_data['paragenetic_modes_uuid'],
                    'version' => 'v5',
                    'recent' => $recent
                )
            );
            $paragenetic_modes_template_url = $baseurl . $paragenetic_modes_template_url;

            // API Login URL
            $api_login_url = $this->generateUrl(
                'api_login_check_v4_odr',
                array(
                )
            );
            // $api_login_url = $baseurl . '/odr_rruff/api/v3/token';
            $api_login_url = $baseurl . $api_login_url;

            // API Job Create URL
            $api_create_job_url = $this->generateUrl(
                'odr_api_start_job',
                array(
                    'version' => 'v4'
                )
            );
            // $api_create_job_url = $baseurl . '/odr_rruff' . $api_create_job_url;
            $api_create_job_url = $baseurl . $api_create_job_url;

            // API Job Status URL
            $api_job_status_url = $this->generateUrl(
                'odr_api_job_status',
                array(
                    'version' => 'v4'
                )
            );
            $api_job_status_url = $baseurl . $api_job_status_url;

            // API Worker Job Create URL
            $api_worker_job_url = $this->generateUrl(
                'odr_api_worker_job',
                array(
                    'version' => 'v4',
                )
            );
            // $api_worker_job_url = $baseurl . '/odr_rruff' .  $api_worker_job_url;
            $api_worker_job_url = $baseurl . $api_worker_job_url;

            $job_data['api_worker_job_url'] = $api_worker_job_url;
            $job_data['api_create_job_url'] = $api_create_job_url;
            $job_data['api_job_status_url'] = $api_job_status_url;
            $job_data['api_login_url'] = $api_login_url;
            $job_data['full_ima_url'] = $full_ima_url;
            $job_data['ima_url'] = $ima_url;
            $job_data['ima_template_url'] = $ima_template_url;
            $job_data['cell_params_url'] = $cell_params_url;
            $job_data['powder_diffraction_url'] = $powder_diffraction_url;
            $job_data['references_url'] = $references_url;
            $job_data['amcsd_url'] = $amcsd_url;
            $job_data['paragenetic_modes_url'] = $paragenetic_modes_url;
            $job_data['paragenetic_modes_template_url'] = $paragenetic_modes_template_url;

            $job_data['ima_record_map'] = $this->container->getParameter('ima_record_map');
            $job_data['reference_record_map'] = $this->container->getParameter('reference_record_map');
            $job_data['amcsd_record_map'] = $this->container->getParameter('amcsd_record_map');
            $job_data['paragenetic_modes_record_map'] = $this->container->getParameter('paragenetic_modes_record_map');
            $job_data['cell_params_map'] = $this->container->getParameter('cell_params_map');
            $job_data['powder_diffraction_map'] = $this->container->getParameter('powder_diffraction_map');

            // Add record to pheanstalk queue
            $payload = json_encode($job_data);

            $pheanstalk->useTube('odr_ima_data_builder')->put($payload);
            // Wrap array for JSON compliance
            $output = array("done" => true );

            return new JsonResponse($output);
        }
        catch(\Exception $e) {
            print $e->getMessage(); exit();
        }

    }


    /**
     * @deprecated
     * Search an individual datatype via the API
     *
     * @param $version
     * @param $limit
     * @param $offset
     * @param Request $request
     * @return JsonResponse
     * @throws ODRBadRequestException
     * @throws ODRNotFoundException
     */
    public function datasetSearchAction(
        $version,
        $search_key,
        $limit,
        $offset,
        $return_as_list = false,
        Request $request
    ) {

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        $params = json_decode(base64_decode($search_key), true);

        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
            array(
                'id' => $params['dt_id']
            )
        );
        if ($datatype == null)
            throw new ODRNotFoundException('Datatype');

        /** @var SearchAPIService $search_api_service */
        $search_api_service = $this->container->get('odr.search_api_service');
        $baseurl = $this->container->getParameter('site_baseurl');
        // $records = $search_api_service->performSearch($datatype, $baseurl, $params);
        $records = $search_api_service->performSearch(
            $datatype,
            $search_key,
            array(), // empty array without searching as super-admin means only public results
            false,   // only return grandparent records
            array(), // do not sort the matching records
            array(),
            false,   // do not search as a super-admin
            false,   // do not ignore field searchable status
            true     // ...all just to return the datarecord list in a different format than usual
        );

        // Flatten the associative array
        $records = array_values($records);

        // Slice for Limit/Offset
        if($limit > 0) {
            $records = array_slice($records, $offset, $limit);
        }
        $search_api_service_nc = $this->container->get('odr.search_api_service_no_conflict');

        $output_data = [];
        if(!$return_as_list) {
            foreach($records as $record) {
                $record_data = $search_api_service_nc->getRecordData(
                    'v3',
                    $record, // should be the record_id
                    $baseurl,
                    'json',
                    true,
                    null,
                    false
                );
                $output_data[] = json_decode($record_data);
            }
        }
        else {
            $output_data = $records;
        }

        // Wrap array for JSON compliance
        $output = array(
            'count' => count($output_data),
            'records' => $output_data
        );
        return new JsonResponse($output);

    }


    /**
     * @param $version
     * @param $limit
     * @param $offset
     * @param Request $request
     * @return JsonResponse
     * @throws ODRBadRequestException
     * @throws ODRNotFoundException
     */
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

            /** @var DatarecordExportService $datarecord_export_service */
            $datarecord_export_service = $this->container->get('odr.datarecord_export_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
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
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            // TODO - enforce permissions on template side?
            // If either the datatype or the datarecord is not public, and the user doesn't have
            //  the correct permissions...then don't allow them to view the datarecord
//            if ( !$permissions_service->canViewDatatype($user, $datatype) )
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
            $data = $datarecord_export_service->getData(
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
