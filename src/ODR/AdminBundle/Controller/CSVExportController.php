<?php

/**
 * Open Data Repository Data Publisher
 * CSVExport Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The csvexport controller handles rendering and processing a
 * form that allows the user to select which datafields to export
 * into a csv file, and also handles the work of exporting the data.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
use Pheanstalk\Pheanstalk;
// Symfony
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Templating\EngineInterface;
// CSV Reader
use Ddeboer\DataImport\Writer\CsvWriter;


class CSVExportController extends ODRCustomController
{

    /**
     * Sets up a csv export request made from a search results page.
     *
     * @param integer $datatype_id The database id of the DataType the search was performed on.
     * @param integer $search_theme_id
     * @param string $search_key   The search key identifying which datarecords to potentially export
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function csvExportAction($datatype_id, $search_theme_id, $search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // A search key is required, otherwise there's no way to identify which datarecords
            //  should be exported
            if ($search_key == '')
                throw new ODRBadRequestException('Search key is blank');

            // A tab id is also required...
            $params = $request->query->all();
            if ( !isset($params['odr_tab_id']) )
                throw new ODRBadRequestException('Missing tab id');
            $odr_tab_id = $params['odr_tab_id'];


            // If $search_theme_id is set...
            if ($search_theme_id != 0) {
                // ...require the referenced theme to exist
                /** @var Theme $search_theme */
                $search_theme = $em->getRepository('ODRAdminBundle:Theme')->find($search_theme_id);
                if ($search_theme == null)
                    throw new ODRNotFoundException('Search Theme');

                // ...require it to match the datatype being rendered
                if ($search_theme->getDataType()->getId() !== $datatype->getId())
                    throw new ODRBadRequestException('Search theme does not belong to given datatype');
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Ensure user has permissions to be doing this
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This doesn't make sense on a master datatype
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to export from a master template');


            // ----------------------------------------
            // Don't want to prevent access to the csv_export page if a background job is running
            // If a background job is running, then csvExportStartAction() will refuse to start


            // ----------------------------------------
            // Verify the search key, and ensure the user can view the results
            $search_key_service->validateSearchKey($search_key);
            $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);

            if ($filtered_search_key !== $search_key) {
                // User can't view some part of the search key...kick them back to the search
                //  results list
                return $search_redirect_service->redirectToFilteredSearchResult($user, $filtered_search_key, $search_theme_id);
            }

            // Get the list of grandparent datarecords specified by this search key
            $grandparent_datarecord_list = $search_api_service->performSearch(
                $datatype,
                $search_key,
                $user_permissions
            );    // this will only return grandparent datarecord ids

            // If the user is attempting to view a datarecord from a search that returned no results...
            if ( empty($grandparent_datarecord_list) ) {
                // ...redirect to the "no results found" page
                return $search_redirect_service->redirectToSearchResult($filtered_search_key, $search_theme_id);
            }

            // Store the datarecord list in the user's session...there is a chance that it could get
            //  wiped if it was only stored in the cache
            $session = $request->getSession();
            $list = $session->get('csv_export_datarecord_lists');
            if ($list == null)
                $list = array();

            $list[$odr_tab_id] = array(
                'filtered_search_key' => $filtered_search_key,
            );
            $session->set('csv_export_datarecord_lists', $list);


            // ----------------------------------------
            // Generate the HTML required for a header
            $header_html = $templating->render(
                'ODRAdminBundle:CSVExport:csvexport_header.html.twig',
                array(
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $filtered_search_key,
                    'offset' => $offset,
                )
            );

            // Get the CSVExport page rendered
            $page_html = $odr_render_service->getCSVExportHTML($user, $datatype, $odr_tab_id);

            $return['d'] = array( 'html' => $header_html.$page_html );
        }
        catch (\Exception $e) {
            $source = 0x8647bcc3;
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
     * Begins the process of mass exporting to a csv file, by creating a beanstalk job containing
     * which datafields to export for each datarecord being exported
     *
     * @param Request $request
     *
     * @return Response
     */
    public function csvExportStartAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['odr_tab_id'])
                || !isset($post['datatype_id'])
                || !isset($post['delimiter'])
            ) {
                throw new ODRBadRequestException();
            }

            $odr_tab_id = $post['odr_tab_id'];
            $datatype_id = $post['datatype_id'];
            $delimiter = trim($post['delimiter']);

            // Need to have either 'datafields' or 'plugin_datafields'
            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];
            $plugin_datafields = array();
            if ( isset($post['plugin_datafields']) )
                $plugin_datafields = $post['plugin_datafields'];

            if ( empty($datafields) && empty($plugin_datafields) )
                throw new ODRBadRequestException();


            // The rest of these are only needed if a field of that typeclass is marked for export
            $file_image_delimiter = null;
            if ( isset($post['file_image_delimiter']) )
                $file_image_delimiter = trim($post['file_image_delimiter']);

            $radio_delimiter = null;
            if ( isset($post['radio_delimiter']) )
                $radio_delimiter = trim($post['radio_delimiter']);

            $tag_delimiter = null;
            if ( isset($post['tag_delimiter']) )
                $tag_delimiter = trim($post['tag_delimiter']);

            $tag_hierarchy_delimiter = null;
            if ( isset($post['tag_hierarchy_delimiter']) )
                $tag_hierarchy_delimiter = trim($post['tag_hierarchy_delimiter']);


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                throw new ODRBadRequestException('Unable to run CSVExport from a child datatype');

            // This doesn't make sense on a master datatype
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to export from a master template');


            $session = $request->getSession();
            $api_key = $this->container->getParameter('beanstalk_api_key');
            /** @var Pheanstalk $pheanstalk */
            $pheanstalk = $this->get('pheanstalk');

            $url = $this->generateUrl('odr_csv_export_worker', array(), UrlGeneratorInterface::ABSOLUTE_URL);


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Ensure user has permissions to be doing this
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // ----------------------------------------
            // Check whether any jobs that are currently running would interfere with a newly
            //  created 'csv_export' job for this datatype
            $new_job_data = array(
                'job_type' => 'csv_export',
                'target_entity' => $datatype,
            );

            $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to start a new CSVExport job, as it would interfere with an already running '.$conflicting_job.' job');


            // ----------------------------------------
            // Translate the primary delimiter if needed
            if ($delimiter === 'tab')
                $delimiter = "\t";
            if ( $delimiter === '' || strlen($delimiter) > 1 )
                throw new ODRBadRequestException('Invalid column delimiter');

            // If they exist, ensure that the secondary delimiters are legal
            if ( !is_null($file_image_delimiter)
                && ($file_image_delimiter === '' || strlen($file_image_delimiter) > 3)
            ) {
                throw new ODRBadRequestException('Invalid file/image delimiter');
            }

            if ( !is_null($radio_delimiter)
                && ($radio_delimiter === '' || strlen($radio_delimiter) > 3)
            ) {
                if ( $radio_delimiter !== 'space' ) {
                    // Radio delimiter is allowed to be 'space', otherwise it wouldn't really
                    //  transfer through the background jobs
                    throw new ODRBadRequestException('Invalid radio delimiter');
                }
            }

            if ( !is_null($tag_delimiter)
                && ($tag_delimiter === '' || strlen($tag_delimiter) > 3)
            ) {
                throw new ODRBadRequestException('Invalid tag delimiter');
            }

            if ( !is_null($tag_hierarchy_delimiter)
                && ($tag_hierarchy_delimiter === '' || strlen($tag_hierarchy_delimiter) > 3)
            ) {
                throw new ODRBadRequestException('Invalid tag hierarchy delimiter');
            }

            // Ensure that the secondary delimiters don't contain the primary delimiter...
            if ( !is_null($file_image_delimiter) && strpos($file_image_delimiter, $delimiter) !== false )
                throw new ODRBadRequestException('Invalid file/image delimiter');
            if ( !is_null($radio_delimiter) && strpos($radio_delimiter, $delimiter) !== false )
                throw new ODRBadRequestException('Invalid radio delimiter');
            if ( !is_null($tag_delimiter) && strpos($tag_delimiter, $delimiter) !== false )
                throw new ODRBadRequestException('Invalid tag delimiter');
            if ( !is_null($tag_hierarchy_delimiter) && strpos($tag_hierarchy_delimiter, $delimiter) !== false )
                throw new ODRBadRequestException('Invalid tag hierarchy delimiter');
            // ...or the field delimiter used by fputcsv() later on
            if ( !is_null($file_image_delimiter) && strpos($file_image_delimiter, "\"") !== false )
                throw new ODRBadRequestException('Invalid file/image delimiter');
            if ( !is_null($radio_delimiter) && strpos($radio_delimiter, "\"") !== false )
                throw new ODRBadRequestException('Invalid radio delimiter');
            if ( !is_null($tag_delimiter) && strpos($tag_delimiter, "\"") !== false )
                throw new ODRBadRequestException('Invalid tag delimiter');
            if ( !is_null($tag_hierarchy_delimiter) && strpos($tag_hierarchy_delimiter, "\"") !== false )
                throw new ODRBadRequestException('Invalid tag hierarchy delimiter');


            // If both tag delimiters are set, ensure that one doesn't contain the other
            if ( !is_null($tag_delimiter) && !is_null($tag_hierarchy_delimiter) ) {
                if ( strpos($tag_delimiter, $tag_hierarchy_delimiter) !== false
                    || strpos($tag_hierarchy_delimiter, $tag_delimiter) !== false
                ) {
                    throw new ODRBadRequestException('Invalid tag delimiters');
                }
            }


            // ----------------------------------------
            // Need to validate the given datafield information...
            $dt_array = $dbi_service->getDatatypeArray($datatype->getId(), true);    // may need linked datatypes
            $dr_array = array();
            $pm_service->filterByGroupPermissions($dt_array, $dr_array, $user_permissions);

            // $datafields could be empty, but if not then need to verify its info
            $flipped_datafields = array_flip($datafields);

            $df_mapping = array();
            foreach ($dt_array as $dt_id => $dt) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    if ( isset($flipped_datafields[$df_id]) || isset($plugin_datafields[$df_id]) ) {
                        $df_mapping[$df_id] = $dt_id;

                        $df = $dt['dataFields'][$df_id];
                        $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                        $typename = $df['dataFieldMeta']['fieldType']['typeName'];

                        // Require the relevant delimiter to be set if exporting File/Image/Radio/Tag typeclasses
                        if ( ($typeclass === 'File' || $typeclass === 'Image') && is_null($file_image_delimiter) )
                            throw new ODRBadRequestException('File/Image delimiter not set');
                        if ( ($typename === 'Multiple Radio' || $typename === 'Multiple Select') && is_null($radio_delimiter) )
                            throw new ODRBadRequestException('Radio delimiter not set');
                        if ($typeclass === 'Tag' && is_null($tag_delimiter) )
                            throw new ODRBadRequestException('Tag delimiter not set');

                        // Tag fields that permit multiple levels also need the tag hierarchy delimiter
                        $allow_multiple_levels = $df['dataFieldMeta']['tags_allow_multiple_levels'];
                        if ($allow_multiple_levels && is_null($tag_hierarchy_delimiter) )
                            throw new ODRBadRequestException('Tag hierarchy delimiter not set');
                    }
                }
            }

            // If these arrays don't match...then either the user can't view at least one of the
            //  fields they want to export, or they tried to export a field from an unrelated
            //  datatype.  This will typically only be triggered by manual edits of the POST data.
            if ( (count($datafields) + count($plugin_datafields)) !== count($df_mapping) )
                throw new ODRBadRequestException('Invalid Datafield list');


            // ----------------------------------------
            // Grab datarecord list and search key from user session...didn't use the cache because
            //  that could've been cleared and would cause this to work on a different subset of
            //  datarecords
            if ( !$session->has('csv_export_datarecord_lists') )
                throw new ODRBadRequestException('Missing CSVExport session variable');
            $list = $session->get('csv_export_datarecord_lists');
            if ( !isset($list[$odr_tab_id]) )
                throw new ODRBadRequestException('Missing CSVExport session variable');
            if ( !isset($list[$odr_tab_id]['filtered_search_key']) )
                throw new ODRBadRequestException('Malformed CSVExport session variable');

            // ...and need to not be blank
            $search_key = $list[$odr_tab_id]['filtered_search_key'];
            if ($search_key === '')
                throw new ODRBadRequestException('Search key is blank');

            // Shouldn't be an issue, but delete the datarecord list out of the user's session
            unset( $list[$odr_tab_id] );
            $session->set('csv_export_datarecord_lists', $list);


            // ----------------------------------------
            // CSVExport needs both versions of the lists of datarecords from a search result...

            // ...the grandparent datarecord list so that the export knows how many beanstalk jobs
            //  to create in the csv_export_worker queue...
            $grandparent_datarecord_list = $search_api_service->performSearch(
                $datatype,
                $search_key,
                $user_permissions
            );    // this only returns grandparent datarecord ids

            // ...and the complete datarecord list so that the csv_export_worker process can export
            //  the correct child/linked records
            $complete_datarecord_list = $search_api_service->performSearch(
                $datatype,
                $search_key,
                $user_permissions,
                true
            );    // this also returns child/linked descendant datarecord ids

            // However, the complete datarecord list can't be passed directly to the csv_export_worker
            //  queue because the list can easily exceed the maximum allowed job length...
            // Therefore, the list needs to be filtered for each csv_export_worker job so it only
            //  contains the child/linked records that are relevant to the grandparent datarecord
            $complete_datarecord_list = array_flip($complete_datarecord_list);


            // The most...reusable...method of performing this filtering is to copy the initial logic
            //  from SearchAPIService::performSearch().  This is duplication of work, but it should
            //  be fast enough to not make a noticable difference...


            // Convert the search key into a format suitable for searching
            $searchable_datafields = $search_api_service->getSearchableDatafieldsForUser(array($datatype->getId()), $user_permissions);
            $criteria = $search_key_service->convertSearchKeyToCriteria($search_key, $searchable_datafields);

            // Need to grab hydrated versions of the datafields/datatypes being searched on
            $hydrated_entities = $search_api_service->hydrateCriteria($criteria);

            // Each datatype being searched on (or the datatype of a datafield being search on) needs
            //  to be initialized to "-1" (does not match) before the results of each facet search
            //  are merged together into the final array
            $affected_datatypes = $criteria['affected_datatypes'];
            unset( $criteria['affected_datatypes'] );
            // Also don't want the list of all datatypes anymore either
            unset( $criteria['all_datatypes'] );
            // ...or what type of search this is
            unset( $criteria['search_type'] );

            // Get the base information needed so getSearchArrays() can properly setup the search arrays
            $search_permissions = $search_api_service->getSearchPermissionsArray($hydrated_entities['datatype'], $affected_datatypes, $user_permissions);

            // Going to need these two arrays to be able to accurately determine which datarecords
            //  end up matching the query
            $search_arrays = $search_api_service->getSearchArrays(array($datatype->getId()), $search_permissions);
//            $flattened_list = $search_arrays['flattened'];
            $inflated_list = $search_arrays['inflated'];
            // The top-level of $inflated_list is wrapped in the top-level datatype id...get rid of it
            $inflated_list = $inflated_list[ $datatype->getId() ];


            // ----------------------------------------
            // Get/create an entity to track the progress of this datatype recache
            $job_type = 'csv_export';
            $target_entity = 'datatype_'.$datatype_id;
            $additional_data = array('description' => 'Exporting data from DataType '.$datatype_id);
            $restrictions = '';
            $total = count($grandparent_datarecord_list);
            $reuse_existing = false;
//$reuse_existing = true;

            $tracked_job = self::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
            $tracked_job_id = $tracked_job->getId();

            $return['d'] = array("tracked_job_id" => $tracked_job_id);


            // ----------------------------------------
            // Create a beanstalk job for each of these datarecords
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');     // debug purposes only
            foreach ($grandparent_datarecord_list as $num => $datarecord_id) {
                // Need to use $complete_datarecord_list and $inflated_list to locate the child/linked
                //  datarecords related to this top-level datarecord
                $tmp_list = array($datarecord_id => $inflated_list[$datarecord_id]);
                $filtered_datarecord_list = self::getFilteredDatarecordList($tmp_list, $complete_datarecord_list);

                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        'tracked_job_id' => $tracked_job_id,
                        'user_id' => $user->getId(),

                        'delimiter' => $delimiter,
                        'file_image_delimiter' => $file_image_delimiter,
                        'radio_delimiter' => $radio_delimiter,
                        'tag_delimiter' => $tag_delimiter,
                        'tag_hierarchy_delimiter' => $tag_hierarchy_delimiter,

                        'datatype_id' => $datatype_id,
                        'datarecord_id' => $datarecord_id,    // top-level datarecord id
                        'complete_datarecord_list' => $filtered_datarecord_list,    // list of all datarecords related to $datarecord_id that matched the search
                        'datafields' => $datafields,
                        'plugin_datafields' => $plugin_datafields,

                        'redis_prefix' => $redis_prefix,    // debug purposes only
                        'url' => $url,
                        'api_key' => $api_key,
                    )
                );

//print '<pre>'.print_r($payload, true).'</pre>';    exit();

                $delay = 1; // one second
                $pheanstalk->useTube('csv_export_worker')->put($payload, $priority, $delay);
            }
        }
        catch (\Exception $e) {
            $source = 0x86acf50b;
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
     * Recursively digs through a single top-level datarecord from $inflated list to find all of its
     * child/linked datarecords that exist in $complete_datarecord_list.
     *
     * @param array $inflated_list @see SearchAPIService::buildDatarecordTree()
     * @param array $complete_datarecord_list The list of all datarecords matching the original search
     *                                        that this CSVExport is being run on...datarecord ids
     *                                        are the array keys
     *
     * @return array
     */
    private function getFilteredDatarecordList($inflated_list, $complete_datarecord_list)
    {
        $filtered_list = array();

        foreach ($inflated_list as $dr_id => $child_dt_list) {
            if ( isset($complete_datarecord_list[$dr_id]) ) {
                $filtered_list[] = $dr_id;
                if ( is_array($child_dt_list) ) {
                    // This datarecord has child/linked records, so those should get checked too
                    foreach ($child_dt_list as $child_dt_id => $dr_list) {
                        $tmp = self::getFilteredDatarecordList($dr_list, $complete_datarecord_list);
                        // Any matching child/linked records found should get added to the full list
                        foreach ($tmp as $num => $dr)
                            $filtered_list[] = $dr;
                    }
                }
            }

            // Otherwise, this datarecord is not in the search results list...it and any children
            //  should get ignored
        }

        return $filtered_list;
    }


    /**
     * Given a datarecord id and a list of datafield ids, builds a line of csv data to be written
     * to a csv file by Ddeboer\DataImport\Writer\CsvWriter
     *
     * @param Request $request
     *
     * @return Response
     */
    public function csvExportWorkerAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // This should only be called by a beanstalk worker process, so force exceptions to be in json
            $request->setRequestFormat('json');

            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['tracked_job_id'])
                || !isset($post['user_id'])
                || !isset($post['delimiter'])

                || !isset($post['datatype_id'])
                || !isset($post['datarecord_id'])
                || !isset($post['complete_datarecord_list'])
                || !isset($post['datafields'])
                || !isset($post['plugin_datafields'])

                || !isset($post['api_key'])
                || !isset($post['random_key'])
            ) {
                throw new ODRBadRequestException();
            }

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];

            $datatype_id = $post['datatype_id'];
            $datarecord_id = $post['datarecord_id'];
            $complete_datarecord_list = $post['complete_datarecord_list'];
            $datafields = $post['datafields'];
            $plugin_datafields = $post['plugin_datafields'];

            $api_key = $post['api_key'];
            $random_key = $post['random_key'];

            // Don't need to do any additional verification on these...that was handled back in
            //  csvExportStartAction()
            $delimiters = array(
                'base' => $post['delimiter'],
                'file' => null,
                'radio' => null,
                'tag' => null,
                'tag_hierarchy' => null,
            );

            if ( isset($post['file_image_delimiter']) )
                $delimiters['file'] = $post['file_image_delimiter'];

            if ( isset($post['radio_delimiter']) )
                $delimiters['radio'] = $post['radio_delimiter'];
            if ( $delimiters['radio'] === 'space' )
                $delimiters['radio'] = ' ';

            if ( isset($post['tag_delimiter']) )
                $delimiters['tag'] = $post['tag_delimiter'];

            if ( isset($post['tag_hierarchy_delimiter']) )
                $delimiters['tag_hierarchy'] = $post['tag_hierarchy_delimiter'];


            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            /** @var Pheanstalk $pheanstalk */
            $pheanstalk = $this->get('pheanstalk');

            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException();


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                throw new ODRBadRequestException('Unable to run CSVExport from a child datatype');

            // This doesn't make sense on a master datatype
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to export from a master template');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            if ($datarecord->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException('Datarecord does not match Datatype');


            // ----------------------------------------
            // Need the user to be able to filter data
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null || !$user->isEnabled())
                throw new ODRNotFoundException('User');

            // Ensure user has permissions to be doing this
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            // Perform filtering before attempting to find anything else
            $user_permissions = $pm_service->getUserPermissionsArray($user);
            $dt_array = $dbi_service->getDatatypeArray($datatype_id, true);    // may need linked datatypes
            $dr_array = $dri_service->getDatarecordArray($datarecord->getId(), true);    // may need links

            $pm_service->filterByGroupPermissions($dt_array, $dr_array, $user_permissions);


            // ----------------------------------------
            // Gather basic info about all datafields prior to actually loading data
            // If tags are being exported, then additional information will be needed
            $tag_data = array(
                'names' => array(),
                'tree' => array(),
            );

            // Ensure this datatype's external id field is going to be exported, if one exists
            $external_id_field = $dt_array[$datatype_id]['dataTypeMeta']['externalIdField'];
            if ( !is_null($external_id_field) )
                $datafields[] = $external_id_field['id'];

            // Need to locate fieldtypes of all datafields that are going to be exported
            $flipped_datafields = array_flip($datafields);
            foreach ($plugin_datafields as $df_id => $df_data)
                $flipped_datafields[$df_id] = 1;

            $datafields_to_export = array();
            foreach ($dt_array as $dt_id => $dt) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    if ( isset($flipped_datafields[$df_id]) ) {
                        $fieldtype = $df['dataFieldMeta']['fieldType'];
                        $typeclass = $fieldtype['typeClass'];
                        $typename = $fieldtype['typeName'];

                        // All fieldtypes except for Markdown can be exported
                        if ($typename !== 'Markdown')
                            $datafields_to_export[$df_id] = $typeclass;

                        // If exporting a tag datafield...
                        if ( $typename === 'Tag' && isset($df['tags']) ) {
                            // The tags are stored in a tree structure to make displaying them
                            //  easier...but for export, it's easier if they're flattened
                            $tag_data['names'] = self::getTagNames($df['tags']);
                            // The export process also needs to be able to locate the name of a
                            //  parent tag from a child tag
                            $tag_data['tree'] = self::getTagTree($df['tagTree']);
                        }

                        // "Mark" this datafield as seen
                        unset( $flipped_datafields[$df_id] );
                    }
                }
            }

            // If any entries remain in $flipped_datafields...they're either datafields the user can't
            //  view, or they belong to unrelated datatypes.  Neither should happen, at this point.
            if ( !empty($flipped_datafields) ) {
                $df_ids = implode(',', array_keys($flipped_datafields));
                throw new ODRBadRequestException('Unable to locate Datafields "'.$df_ids.'" for User '.$user_id.', Datatype '.$datatype_id);
            }


            // ----------------------------------------
            // Check whether this datatype has any attached render plugins that could override
            //  exporting

            // TODO - have the arrays here...dig through those, or use a database query?


            // ----------------------------------------
            // Stack the cached version of the datarecord array to make recursion work
            $dr_array = array(
                $datarecord->getId() => $dri_service->stackDatarecordArray($dr_array, $datarecord->getId())
            );

            // Remove all datarecords and datafields from the stacked datarecord array that the
            //  user doesn't want to export
            $datarecords_to_export = array_flip($complete_datarecord_list);
            $filtered_dr_array = self::filterDatarecordArray($dr_array, $datafields_to_export, $datarecords_to_export, $tag_data, $delimiters);


            // ----------------------------------------
            // In order to deal with child/linked datatypes correctly, the CSV exporter needs to know
            //  which child/linked datatypes allow multiple child/linked records
            $datatree_array = $dti_service->getDatatreeArray();

            // Unfortunately, this CSV exporter needs to be able to deal with the possibility of
            //  exporting more than one child/linked datatype that allows multiple child/linked
            // records.

            // For visualization purposes...  TODO
            // Sample (top-level)
            //   |- Mineral (only one allowed per Sample)
            //   |   |- Reference (multiple allowed per Mineral)
            //   |- Raman (multiple allowed per Sample)
            //   |- Infrared (multiple allowed per Sample)
            //   |- etc

            // Child/linked datatypes that only allow a single child/linked datarecord should get
            //  combined with their parent
            $combined_dr_array = array();
            foreach ($filtered_dr_array as $dr_id => $dr_array)
                $combined_dr_array[$dr_id] = self::mergeSingleChildtypes($datatree_array, $datatype_id, $dr_array);

            // Any remaining child/linked datatypes that permit multiple child/linked datarecords
            //  need to get recursively merged together
            $datarecord_data = self::mergeMultipleChildtypes($combined_dr_array);

            // Need to ensure all fields are always in the output and that the output is always in
            //  the same order
            $lines = array();
            foreach ($datarecord_data as $num => $data) {
                $line = array();
                foreach ($datafields_to_export as $df_id => $typeclass) {
                    // Due to the possibility of child/linked datatypes allowing multiple child/linked
                    //  records, the filtered/merged data arrays may not have entries for all of
                    //  the fields selected for export
                    if ( isset($data[$df_id]) )
                        $line[$df_id] = $data[$df_id];
                    else
                        $line[$df_id] = '';
                }

                // Store the line so it can be written to a csv file
                $lines[] = $line;
            }


            // ----------------------------------------
            // Ensure the random key is stored in the database for later retrieval by the finalization process
            $tracked_csv_export = $em->getRepository('ODRAdminBundle:TrackedCSVExport')->findOneBy( array('random_key' => $random_key) );
            if ($tracked_csv_export == null) {
                $query =
                   'INSERT INTO odr_tracked_csv_export (random_key, tracked_job_id, finalize)
                    SELECT * FROM (SELECT :random_key AS random_key, :tj_id AS tracked_job_id, :finalize AS finalize) AS tmp
                    WHERE NOT EXISTS (
                        SELECT random_key FROM odr_tracked_csv_export WHERE random_key = :random_key AND tracked_job_id = :tj_id
                    ) LIMIT 1;';
                $params = array('random_key' => $random_key, 'tj_id' => $tracked_job_id, 'finalize' => 0);
                $conn = $em->getConnection();
                $rowsAffected = $conn->executeUpdate($query, $params);

//print 'rows affected: '.$rowsAffected."\n";
            }

            // Ensure directories exists
            $csv_export_path = $this->getParameter('odr_tmp_directory').'/user_'.$user_id.'/';
            if ( !file_exists($csv_export_path) )
                mkdir( $csv_export_path );
            $csv_export_path .= 'csv_export/';
            if ( !file_exists($csv_export_path) )
                mkdir( $csv_export_path );

            // Open the indicated file
            $filename = 'f_'.$random_key.'.csv';
            $handle = fopen($csv_export_path.$filename, 'a');
            if ($handle !== false) {
                // Write the line given to the file
                // https://github.com/ddeboer/data-import/blob/master/src/Ddeboer/DataImport/Writer/CsvWriter.php
//                $delimiter = "\t";
                $enclosure = "\"";
                $writer = new CsvWriter($delimiters['base'], $enclosure);

                $writer->setStream($handle);

                foreach ($lines as $line)
                    $writer->writeItem($line);

                // Close the file
                fclose($handle);
            }
            else {
                // Unable to open file
                throw new ODRException('Could not open csv worker export file.');
            }


            // ----------------------------------------
            // Update the job tracker if necessary
            $completed = false;
            if ($tracked_job_id !== -1) {
                /** @var TrackedJob $tracked_job */
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total) {
                    $tracked_job->setCompleted( new \DateTime() );
                    $completed = true;
                }

                $em->persist($tracked_job);
                $em->flush();
//print '  Set current to '.$count."\n";
            }


            // ----------------------------------------
            // If this was the last line to write to be written to a file for this particular export...
            // NOTE - incrementCurrent()'s current implementation can't guarantee that only a single process will enter this block...so have to ensure that only one process starts the finalize step
            $random_keys = array();
            if ($completed) {
                // Make a hash from all the random keys used
                $query = $em->createQuery(
                   'SELECT tce.id AS id, tce.random_key AS random_key
                    FROM ODRAdminBundle:TrackedCSVExport AS tce
                    WHERE tce.trackedJob = :tracked_job AND tce.finalize = 0
                    ORDER BY tce.id'
                )->setParameters( array('tracked_job' => $tracked_job_id) );
                $results = $query->getArrayResult();

                // Due to ORDER BY, every process entering this section should compute the same $random_key_hash
                $random_key_hash = '';
                foreach ($results as $num => $result) {
                    $random_keys[ $result['id'] ] = $result['random_key'];
                    $random_key_hash .= $result['random_key'];
                }
                $random_key_hash = md5($random_key_hash);

                // Attempt to insert this hash back into the database...
                // NOTE: this uses the same random_key field as the previous INSERT WHERE NOT EXISTS query...the first time it had an 8 character string inserted into it, this time it's taking a 32 character string
                $query =
                   'INSERT INTO odr_tracked_csv_export (random_key, tracked_job_id, finalize)
                    SELECT * FROM (SELECT :random_key_hash AS random_key, :tj_id AS tracked_job_id, :finalize AS finalize) AS tmp
                    WHERE NOT EXISTS (
                        SELECT random_key FROM odr_tracked_csv_export WHERE random_key = :random_key_hash AND tracked_job_id = :tj_id AND finalize = :finalize
                    ) LIMIT 1;';
                $params = array('random_key_hash' => $random_key_hash, 'tj_id' => $tracked_job_id, 'finalize' => 1);
                $conn = $em->getConnection();
                $rowsAffected = $conn->executeUpdate($query, $params);

                if ($rowsAffected == 1) {
                    // This is the first process to attempt to insert this key...it will be in charge of creating the information used to concatenate the temporary files together
                    $completed = true;
                }
                else {
                    // This is not the first process to attempt to insert this key, do nothing so multiple finalize jobs aren't created
                    $completed = false;
                }
            }


            // ----------------------------------------
            if ($completed) {
                // Determine the contents of the header line
                $dt_array = $dbi_service->getDatatypeArray($datatype_id, true);    // may need linked datatypes

                // Need to locate fieldnames of all datafields that were exported...recreate the
                //  $flipped_datafields array
                $flipped_datafields = array_flip($datafields);

                $header_line = array();
                foreach ($dt_array as $dt_id => $dt) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        if ( isset($flipped_datafields[$df_id]) ) {
                            $typename = $df['dataFieldMeta']['fieldType']['typeName'];
                            $fieldname = $df['dataFieldMeta']['fieldName'];

                            // All fieldtypes except for Markdown can be exported
                            if ($typename !== 'Markdown')
                                $header_line[$df_id] = $fieldname;
                        }
                    }
                }

                // Make a "final" file for the export, and insert the header line
                $final_filename = 'export_'.$user_id.'_'.$tracked_job_id.'.csv';
                $final_file = fopen($csv_export_path.$final_filename, 'w');

                if ($final_file !== false) {
//                    $delimiter = "\t";
                    $enclosure = "\"";
                    $writer = new CsvWriter($delimiters['base'], $enclosure);

                    $writer->setStream($final_file);
                    $writer->writeItem($header_line);
                }
                else {
                    throw new ODRException('Could not open csv finalize export file.');
                }

                fclose($final_file);


                // ----------------------------------------
                // Now that the "final" file exists, need to splice the temporary files together into it
                $url = $this->generateUrl('odr_csv_export_finalize', array(), UrlGeneratorInterface::ABSOLUTE_URL);

                //
                $redis_prefix = $this->container->getParameter('memcached_key_prefix');     // debug purposes only
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        'tracked_job_id' => $tracked_job_id,
                        'final_filename' => $final_filename,
                        'random_keys' => $random_keys,

                        'user_id' => $user_id,
                        'redis_prefix' => $redis_prefix,    // debug purposes only
                        'url' => $url,
                        'api_key' => $api_key,
                    )
                );


                $delay = 1; // one second
                $pheanstalk->useTube('csv_export_finalize')->put($payload, $priority, $delay);
            }

            $return['d'] = '';
        }
        catch (\Exception $e) {
            $source = 0x5bd2c168;
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
     * The tag data stored in the cached datatype array is organized for display...parent tags
     * contain their child tags.  Having to recursively dig through this array repeatedly is bad
     * though, so the tag data should get flattened for easier lookup of tag names.
     *
     * @param array $df_data
     *
     * @return array
     */
    private function getTagNames($tags)
    {
        $tag_names = array();

        foreach ($tags as $tag_id => $tag_data) {
            $tag_names[$tag_id] = $tag_data['tagName'];

            if ( isset($tag_data['children']) ) {
                $tmp = self::getTagNames($tag_data['children']);
                foreach ($tmp as $t_id => $t_name)
                    $tag_names[$t_id] = $t_name;
            }
        }

        return $tag_names;
    }


    /**
     * The tag data stored in the cached datatype array is organized for display...parent tags
     * contain their child tags.  However, since the cached datarecord array only mentions which
     * bottom-level tags are selected, this tag hierarchy array needs to be flipped so CSV Export
     * can bulid up the "full" tag name.
     *
     * @param array $tag_tree
     *
     * @return array
     */
    private function getTagTree($tag_tree)
    {
        $inversed_tree = array();
        foreach ($tag_tree as $parent_tag_id => $child_tags) {
            foreach ($child_tags as $child_tag_id => $tmp)
                $inversed_tree[$child_tag_id] = $parent_tag_id;
        }

        return $inversed_tree;
    }


    /**
     * Extracts values of all datafields that have been selected for export from the cached
     * datarecord array.
     *
     * @param array $datarecord_data
     * @param array $datafields_to_export
     * @param array $datarecords_to_export
     * @param array $tag_hierarchy
     * @param array $delimiters
     *
     * @return array
     */
    private function filterDatarecordArray($datarecord_data, $datafields_to_export, $datarecords_to_export, $tag_hierarchy, $delimiters)
    {
        // Due to recursion, creating/returning a new array is easier than modifying the original
        $filtered_data = array();

        // Ignore all datafields that aren't supposed to be exported
        foreach ($datarecord_data as $dr_id => $dr_data) {
            // Ignore all datarecords that aren't supposed to be exported
            if ( !isset($datarecords_to_export[$dr_id]) )
                continue;

            $filtered_data[$dr_id] = array();

            // For any actual data in the datarecord...
            if ( isset($dr_data['dataRecordFields']) ) {
                $filtered_data[$dr_id]['values'] = array();

                foreach ($dr_data['dataRecordFields'] as $df_id => $df_data) {
                    // ...if it's supposed to be exported...
                    if ( isset($datafields_to_export[$df_id]) ) {
                        $tmp = array();

                        // ...then extract the value from the datarecord array...
                        $typeclass = $datafields_to_export[$df_id];
                        switch ( $typeclass ) {
                            case 'File':
                                $tmp = self::getFileData($df_data, $delimiters);
                                break;
                            case 'Image':
                                $tmp = self::getImageData($df_data, $delimiters);
                                break;
                            case 'Radio':
                                $tmp = self::getRadioData($df_data, $delimiters);
                                break;
                            case 'Tag':
                                $tmp = self::getTagData($df_data, $tag_hierarchy, $delimiters);
                                break;
                            default:
                                $tmp = self::getOtherData($df_data, $typeclass);
                                break;
                        }

                        // ...and save it
                        $filtered_data[$dr_id]['values'][$df_id] = $tmp;
                    }
                }

                // No sense having empty arrays
                if ( empty($filtered_data[$dr_id]['values']) )
                    unset( $filtered_data[$dr_id]['values'] );
            }

            // If the datarecord has any children...
            if ( isset($dr_data['children']) ) {
                foreach ($dr_data['children'] as $child_dt_id => $child_dr_list) {
                    // ...then repeat the process for each of the child datarecords
                    $tmp = self::filterDatarecordArray($child_dr_list, $datafields_to_export, $datarecords_to_export, $tag_hierarchy, $delimiters);
                    if ( !empty($tmp) )
                        $filtered_data[$dr_id]['children'][$child_dt_id] = $tmp;
                }
            }

            // No sense returning anything for this datarecord if it doesn't have values or children
            if ( !isset($filtered_data[$dr_id]['values']) && !isset($filtered_data[$dr_id]['children']) )
                unset( $filtered_data[$dr_id] );
        }

        return $filtered_data;
    }


    /**
     * Extracts file data for exporting.
     *
     * @param array $df_data
     * @param array $delimiters
     *
     * @return string
     */
    private function getFileData($df_data, $delimiters)
    {
        $files = array();
        if ( isset($df_data['file']) ) {
            foreach ($df_data['file'] as $num => $file) {
                // If there's already a file in the list, then insert a delimiter after the
                //  previous file
                if ( !empty($files) )
                    $files[] = $delimiters['file'];

                // Save the original filename for each file uploaded into this datafield
                $files[] = $file['fileMeta']['originalFileName'];
            }
        }

        // Implode the list of files with their delimiters to make a single string
        return implode("", $files);
    }


    /**
     * Extracts image data for exporting.
     *
     * @param array $df_data
     * @param array $delimiters
     *
     * @return string
     */
    private function getImageData($df_data, $delimiters)
    {
        $images = array();
        if ( isset($df_data['image']) ) {
            foreach ($df_data['image'] as $num => $thumbnail_image) {
                // If there's already an image in the list, then insert a delimiter after the
                //  previous image
                if ( !empty($images) )
                    $images[] = $delimiters['file'];

                // Don't want the thumbnails...want the filename of the corresponding full-size image
                $parent_image = $thumbnail_image['parent'];
                $images[] = $parent_image['imageMeta']['originalFileName'];
            }
        }

        // Implode the list of images with their delimiters to make a single string
        return implode("", $images);
    }


    /**
     * Extracts radio selection data for exporting.
     *
     * @param array $df_data
     * @param array $delimiters
     *
     * @return string
     */
    private function getRadioData($df_data, $delimiters)
    {
        $selections = array();
        if ( isset($df_data['radioSelection']) ) {
            foreach ($df_data['radioSelection'] as $ro_id => $rs) {
                // Only save radio option names when the radio option is selected
                if ( $rs['selected'] === 1 ) {
                    // If there's already a selected radio option in the list, then insert a delimiter
                    //  after the previous radio option
                    if ( !empty($selections) )
                        $selections[] = $delimiters['radio'];

                    $selections[] = $rs['radioOption']['optionName'];
                }
            }
        }

        // Implode the list of radio options with their delimiters to make a single string
        return implode("", $selections);
    }


    /**
     * Extracts tag selection data for exporting from the given top-level $dr_array.
     *
     * @param array $df_data
     * @param array $tag_data
     * @param array $delimiters
     *
     * @return string
     */
    private function getTagData($df_data, $tag_data, $delimiters)
    {
        $tags = array();
        if ( isset($df_data['tagSelection']) ) {
            foreach ($df_data['tagSelection'] as $tag_id => $tag_selection) {
                // If this tag is selected...
                if ( $tag_selection['selected'] === 1 ) {
                    // If there's already a selected tag in the list, then insert a delimiter
                    //  after the previous tag
                    if ( !empty($tags) )
                        $tags[] = $delimiters['tag'];

                    // Since tags can be arranged in a hierarchy, the export process may need to
                    //  locate all parents of this tag
                    $current_tag_id = $tag_id;
                    $full_tag_name = array();
                    $full_tag_name[] = $tag_data['names'][$current_tag_id];

                    // The name of each tag in the hierarchy needs to be added to an array...
                    while ( isset($tag_data['tree'][$current_tag_id]) ) {
                        $full_tag_name[] = $delimiters['tag_hierarchy'];
                        $current_tag_id = $tag_data['tree'][$current_tag_id];
                        $full_tag_name[] = $tag_data['names'][$current_tag_id];
                    }

                    // ...in order to reverse the array so the tag is described from the "top-down"
                    //  instead of from the "bottom-up"
                    $full_tag_name = array_reverse($full_tag_name);
                    $full_tag_name = implode(" ", $full_tag_name);

                    // Save the full name of this tag for the export
                    $tags[] = $full_tag_name;
                }
            }
        }

        // Implode the list of tags with their delimiters to make a single string
        return implode("", $tags);
    }


    /**
     * Extracts text/number/boolean data for exporting.
     *
     * @param array $df_data
     * @param string $typeclass
     *
     * @return string
     */
    private function getOtherData($df_data, $typeclass)
    {
        $value = $df_data[ lcfirst($typeclass) ][0]['value'];
        if ( $typeclass === 'DatetimeValue' )
            $value = $value->format('Y-m-d');

        return $value;
    }


    /**
     * Child/linked datatypes that only allow a single child/linked datarecord should get combined
     * with their parent
     *
     * @param array $datatree_array
     * @param int $current_datatype_id
     * @param array $dr_array
     *
     * @return array
     */
    private function mergeSingleChildtypes($datatree_array, $current_datatype_id, $dr_array)
    {
        // Don't continue when this datarecord has no children
        if ( !isset($dr_array['children']) )
            return $dr_array;

        // Make a copy of the given datarecord
        $dr = $dr_array;

        foreach ($dr['children'] as $child_dt_id => $child_dr_list) {
            // Regardless of whether this relation allows a single child/linked datarecord or
            //  not, need to recursively check any children of this child/linked record
            foreach ($child_dr_list as $child_dr_id => $child_dr) {
                // Only continue recursion if the child datarecord has children
                if ( isset($child_dr['children']) )
                    $dr['children'][$child_dt_id][$child_dr_id] = self::mergeSingleChildtypes($datatree_array, $child_dt_id, $child_dr);
            }

            // Determine whether the current datatype allows multiple records of this specific
            //  child/linked datatype
            $multiple_allowed = false;
            if ( isset($datatree_array['multiple_allowed'][$child_dt_id]) ) {
                $parent_list = $datatree_array['multiple_allowed'][$child_dt_id];
                if ( in_array($current_datatype_id, $parent_list) )
                    $multiple_allowed = true;
            }

            // If this relation only allows a single child/linked datarecord...
            if (!$multiple_allowed) {
                // ...then ensure this datarecord has a list of values, because...
                if ( !isset($dr['values']) )
                    $dr['values'] = array();

                foreach ($child_dr_list as $child_dr_id => $child_dr) {
                    if ( isset($child_dr['values']) ) {
                        foreach ($child_dr['values'] as $df_id => $value) {
                            // ...all values from that child datarecord need to get spliced into
                            //  this datarecord
                            $dr['values'][$df_id] = $value;
                        }
                    }

                    // Now that the values have been copied over, move any children of that child
                    //  datarecord so that they're children of the current datarecord
                    if ( isset($child_dr['children']) ) {
                        foreach ($child_dr['children'] as $grandchild_dt_id => $grandchild_dr_list)
                            $dr['children'][$grandchild_dt_id] = $grandchild_dr_list;
                    }

                    // All relevant parts of the child datarecord have been copied over, get rid
                    //  of the original
                    unset( $dr['children'][$child_dt_id] );
                    if ( empty($dr['children']) )
                        unset( $dr['children'] );
                }
            }
        }

        // Return the possibly modified values/children array for this datarecord
        return $dr;
    }


    /**
     * Any remaining child/linked datatypes that permit multiple child/linked datarecords need to
     * get recursively merged together
     *
     * @param array $dr_list
     *
     * @return array
     */
    private function mergeMultipleChildtypes($dr_list)
    {
        // Each datarecord can turn into multiple lines when it has multiple child/linked records
        $lines = array();

        foreach ($dr_list as $dr_id => $data) {
            // Any values for this datarecord are going to form the "start" of the block of data
            //  for this datarecord
            $line = array();
            if ( isset($data['values']) )
                $line = $data['values'];

            // If this datarecord has child/linked datarecords of its own...
            if ( isset($data['children']) ) {
                // ...then those child/linked datarecords need to be merged first...
                $child_lines = array();
                foreach ($data['children'] as $child_dt_id => $child_dr_list) {
                    $child_lines = self::mergeMultipleChildtypes($child_dr_list);

                    // ...and then this datarecord's data needs to be prepended before each
                    //  child/linked record's line of data
                    foreach ($child_lines as $child_line) {
                        // Make a copy of this datarecord's data first...
                        $new_line = array();
                        foreach ($line as $df_id => $value)
                            $new_line[$df_id] = $value;

                        // ...then append the child/linked datarecord's data afterwards
                        foreach ($child_line as $df_id => $value)
                            $new_line[$df_id] = $value;
                        $lines[] = $new_line;
                    }
                }
            }
            else {
                // No children to consider, just save the data from this datarecord
                $lines[] = $line;
            }
        }

        // Return all the lines created from this datarecord and its children
        return $lines;
    }


    /**
     * Takes a list of temporary files used for csv exporting, and appends each of their contents
     * to a "final" export file
     *
     * @param Request $request
     *
     * @return Response
     */
    public function csvExportFinalizeAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // This should only be called by a beanstalk worker process, so force exceptions to be in json
            $request->setRequestFormat('json');

            $post = $request->request->all();
//print_r($post);  exit();


            if ( !isset($post['tracked_job_id'])
                || !isset($post['final_filename'])
                || !isset($post['random_keys'])
                || !isset($post['user_id'])
                || !isset($post['api_key'])
            ) {
                throw new ODRBadRequestException();
            }

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $final_filename = $post['final_filename'];
            $random_keys = $post['random_keys'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            /** @var Pheanstalk $pheanstalk */
            $pheanstalk = $this->get('pheanstalk');

            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();


            // -----------------------------------------
            // Append the contents of one of the temporary files to the final file
            $csv_export_path = $this->getParameter('odr_tmp_directory').'/user_'.$user_id.'/csv_export/';
            $final_file = fopen($csv_export_path.$final_filename, 'a');
            if (!$final_file)
                throw new ODRException('Unable to open csv export finalize file');

            // Go through and append the contents of each of the temporary files to the "final" file
            $tracked_csv_export_id = null;
            foreach ($random_keys as $tracked_csv_export_id => $random_key) {
                $tmp_filename = 'f_'.$random_key.'.csv';
                $str = file_get_contents($csv_export_path.$tmp_filename);
//print $str."\n\n";

                if ( fwrite($final_file, $str) === false )
                    print 'could not write to "'.$csv_export_path.$final_filename.'"'."\n";

                // Done with this intermediate file, get rid of it
                if ( unlink($csv_export_path.$tmp_filename) === false )
                    print 'could not unlink "'.$csv_export_path.$tmp_filename.'"'."\n";

                $tracked_csv_export = $em->getRepository('ODRAdminBundle:TrackedCSVExport')->find($tracked_csv_export_id);
                $em->remove($tracked_csv_export);
                $em->flush();

                fclose($final_file);

                // Only want to append the contents of a single temporary file to the final file at a time
                break;
            }


            // -----------------------------------------
            // Done with this temporary file
            unset($random_keys[$tracked_csv_export_id]);

            if ( count($random_keys) >= 1 ) {
                // Create another beanstalk job to get another file fragment appended to the final file
                $url = $this->generateUrl('odr_csv_export_finalize', array(), UrlGeneratorInterface::ABSOLUTE_URL);

                //
                $redis_prefix = $this->container->getParameter('memcached_key_prefix');     // debug purposes only
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        'tracked_job_id' => $tracked_job_id,
                        'final_filename' => $final_filename,
                        'random_keys' => $random_keys,

                        'user_id' => $user_id,
                        'redis_prefix' => $redis_prefix,    // debug purposes only
                        'url' => $url,
                        'api_key' => $api_key,
                    )
                );

//print_r($payload);

                $delay = 1; // one second
                $pheanstalk->useTube('csv_export_finalize')->put($payload, $priority, $delay);
            }
            else {
                // Remove finalize marker from ODRAdminBundle:TrackedCSVExport
                $tracked_csv_export = $em->getRepository('ODRAdminBundle:TrackedCSVExport')->findOneBy( array('trackedJob' => $tracked_job_id) );  // should only be one left
                $em->remove($tracked_csv_export);
                $em->flush();

                // TODO - Notify user that export is ready
            }

            $return['d'] = '';
        }
        catch (\Exception $e) {
            $source = 0xa9285db8;
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
     * Sidesteps symfony to set up an CSV file download...
     *
     * @param integer $user_id The user requesting the download (Why????)
     * @param integer $tracked_job_id The tracked job that stored the progress of the csv export
     * @param Request $request
     *
     * @return Response
     */
    public function downloadCSVAction($user_id, $tracked_job_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var TrackedJob $tracked_job */
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);
            if ($tracked_job == null)
                throw new ODRNotFoundException('Job');
            $job_type = $tracked_job->getJobType();
            if ($job_type !== 'csv_export')
                throw new ODRNotFoundException('Job');

            $target_entity = $tracked_job->getTargetEntity();
            $tmp = explode('_', $target_entity);

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find( $tmp[1] );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This doesn't make sense on a master datatype
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to export from a master template');

            // TODO Is there some reason user_id is passed rather than retrieved from the user object?
            $user_id = $user->getId();

            $csv_export_path = $this->getParameter('odr_tmp_directory').'/user_'.$user_id.'/csv_export/';
            $filename = 'export_'.$user_id.'_'.$tracked_job_id.'.csv';


            // Mark the job deleted
            $em->remove($tracked_job);
            $em->flush();

            $handle = fopen($csv_export_path.$filename, 'r');
            if ($handle !== false) {
                // Set up a response to send the file back
                $response = new StreamedResponse();

                $response->setPrivate();
                $response->headers->set('Content-Type', mime_content_type($csv_export_path.$filename));
                $response->headers->set('Content-Length', filesize($csv_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'";');

//                $response->sendHeaders();

                // Use symfony's StreamedResponse to send the decrypted file back in chunks to the user
                $response->setCallback(function() use ($handle) {
                    while ( !feof($handle) ) {
                        $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                        echo $buffer;
                        flush();
                    }
                    fclose($handle);
                });

                return $response;
            }
            else {
                throw new FileNotFoundException($filename);
            }
        }
        catch (\Exception $e) {
            $source = 0x86a6eb3a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

}
