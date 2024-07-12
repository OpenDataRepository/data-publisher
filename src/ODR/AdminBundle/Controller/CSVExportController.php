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
use ODR\AdminBundle\Component\Service\CSVExportHelperService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
// Symfony
use Pheanstalk\Pheanstalk;
use Symfony\Bridge\Monolog\Logger;
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
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
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

            // More useful if the CSVExport page matches whatever theme the user prefers
            $theme_id = $theme_info_service->getPreferredThemeId($user, $datatype->getId(), 'display');
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);

            // Get the CSVExport page rendered
            $page_html = $odr_render_service->getCSVExportHTML($user, $datatype, $odr_tab_id, $theme);

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
    public function newCsvExportStartAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();
            //print_r($post);  exit();

            if ( !isset($post['odr_tab_id'])
                || !isset($post['datafields'])
                || !isset($post['datatype_id'])
                || !isset($post['delimiter'])
            ) {
                throw new ODRBadRequestException();
            }

            $odr_tab_id = $post['odr_tab_id'];
            $datafields = $post['datafields'];
            $datatype_id = $post['datatype_id'];
            $delimiter = trim($post['delimiter']);

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


            // If a datafield exists on the page more than once, then it can have more than one
            //  entry in the submitted form...this will cause an error later, so de-duplicate it
            $datafields = array_flip($datafields);
            $datafields = array_keys($datafields);
            // TODO - ...need to figure out whether the "existing more than once" thing is a problem


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CSVExportHelperService $csv_export_helper_service */
            $csv_export_helper_service = $this->container->get('odr.csv_export_helper_service');
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');

            /** @var Logger $logger */
            $logger = $this->container->get('logger');
            /** @var Pheanstalk $pheanstalk */
            $pheanstalk = $this->get('pheanstalk');


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

            $url = $this->generateUrl('odr_csv_export_worker', array(), UrlGeneratorInterface::ABSOLUTE_URL);


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
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
            $dt_array = $database_info_service->getDatatypeArray($datatype->getId(), true);    // may need linked datatypes
            $dr_array = array();
            $permissions_service->filterByGroupPermissions($dt_array, $dr_array, $user_permissions);

            $df_mapping = array();
            foreach ($datafields as $num => $df_id) {
                foreach ($dt_array as $dt_id => $dt) {
                    if ( isset($dt['dataFields'][$df_id]) ) {
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
            if ( count($datafields) !== count($df_mapping) )
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
            // Need to re-run the search to get a couple lists of datarecords for the export
            $export_search_results = $csv_export_helper_service->getExportSearchResults($datatype, $search_key, $user_permissions);
            $grandparent_datarecord_list = $export_search_results['grandparent_datarecord_list'];
            $complete_datarecord_list = $export_search_results['complete_datarecord_list'];
            $inflated_list = $export_search_results['inflated_list'];


            // ----------------------------------------
            // Get/create an entity to track the progress of this datatype recache
            $job_type = 'csv_export';
            $target_entity = 'datatype_'.$datatype_id;
            $additional_data = array('description' => 'Exporting data from DataType '.$datatype_id);
            $restrictions = '';
            $total = count($grandparent_datarecord_list);

            $tracked_job = new TrackedJob();
            $tracked_job->setTargetEntity($target_entity);
            $tracked_job->setJobType('csv_export');
            $tracked_job->setAdditionalData($additional_data);
            $tracked_job->setRestrictions($restrictions);

            $tracked_job->setStarted(null);
            $tracked_job->setCompleted(null);
            $tracked_job->setCurrent(0);
            $tracked_job->setTotal($total);
            $tracked_job->setFailed(false);

            $tracked_job->setCreated(new \DateTime());
            $tracked_job->setCreatedBy($user);

            $em->persist($tracked_job);
            $em->flush();
            $em->refresh($tracked_job);

            $tracked_job_id = $tracked_job->getId();

            $return['d'] = array("tracked_job_id" => $tracked_job_id);


            // ----------------------------------------
            // Now that the tracked job exists, create a "finalize" job for the export...this is
            //  what actually tracks the progress
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');     // debug purposes only

            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    'tracked_job_id' => $tracked_job_id,
                    'user_id' => $user->getId(),

                    'delimiter' => $delimiter,

                    'datatype_id' => $datatype_id,
                    'datafields' => $datafields,

                    'redis_prefix' => $redis_prefix,    // debug purposes only
                    'url' => $url,
                    'api_key' => $api_key,
                )
            );
//            $logger->debug('CSVExportController::newCsvExportStart() tracked_job_id: '.$tracked_job_id.', payload size: '.strlen($payload));
            $pheanstalk->useTube('csv_export_finalize_express')->put($payload, $priority, 0);


            // ----------------------------------------
            // Create a beanstalk job for each of these datarecords
            $datarecord_ids = [];
            $complete_datarecord_list_array = [];
            $job_order = 0;
            $counter = 0;
            foreach ($grandparent_datarecord_list as $num => $datarecord_id) {
                // Need to use $complete_datarecord_list and $inflated_list to locate the child/linked
                //  datarecords related to this top-level datarecord
                $tmp_list = array($datarecord_id => $inflated_list[$datarecord_id]);
                $filtered_datarecord_list = $csv_export_helper_service->getFilteredDatarecordList($tmp_list, $complete_datarecord_list);
                $datarecord_ids[] = $datarecord_id;
                $complete_datarecord_list_array[] = $filtered_datarecord_list;

                // Job order - used for reassembly of export temp files in the proper
                // order to match the original query.
                $counter++;
                if (
                    $counter % 100 === 0
                    || $counter === count($grandparent_datarecord_list)
                ) {
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
                            'job_order' => $job_order,

                            'datatype_id' => $datatype_id,
                            // top-level datarecord id
                            'datarecord_id' => $datarecord_ids,
                            // list of all datarecords related to $datarecord_id that matched the search
                            'complete_datarecord_list' => $complete_datarecord_list_array,
                            'datafields' => $datafields,

                            'redis_prefix' => $redis_prefix,    // debug purposes only
                            'url' => $url,
                            'api_key' => $api_key,
                        )
                    );
//                    $logger->debug('CSVExportController::newCsvExportStart() tracked_job_id: '.$tracked_job_id.', payload size: '.strlen($payload));
                    $pheanstalk->useTube('csv_export_worker_express')->put($payload, $priority, 0, 300);    // try to use a 5 minute ttl

                    // Reset for the next payload
                    $datarecord_ids = [];
                    $complete_datarecord_list_array = [];
                    $job_order++;
                }
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

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


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
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
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
