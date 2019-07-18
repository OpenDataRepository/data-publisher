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
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\TagHelperService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
// Symfony
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
            if ( !$user->hasRole('ROLE_ADMIN') || !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This doesn't make sense on a master datatype
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to export from a master template');


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
            // Don't care about sorting here
            $search_results = $search_api_service->performSearch($datatype, $search_key, $user_permissions);
            $datarecord_list = implode(',', $search_results['grandparent_datarecord_list']);

            // If the user is attempting to view a datarecord from a search that returned no results...
            if ( $filtered_search_key !== '' && $datarecord_list === '' ) {
                // ...redirect to the "no results found" page
                return $search_redirect_service->redirectToSearchResult($filtered_search_key, $search_theme_id);
            }

            // Store the datarecord list in the user's session...there is a chance that it could get
            //  wiped if it was only stored in the cache
            $session = $request->getSession();
            $list = $session->get('csv_export_datarecord_lists');
            if ($list == null)
                $list = array();

            // TODO - whenever CSVExport can handle child/linked datatypes with multiple child/linked records...
            // TODO - ...this is going to have to be changed to not use the "grandparent_datarecord_list"
            $list[$odr_tab_id] = array('datarecord_list' => $datarecord_list, 'encoded_search_key' => $filtered_search_key);
            $session->set('csv_export_datarecord_lists', $list);


            // ----------------------------------------
            // Generate the HTML required for a header
            $templating = $this->get('templating');
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Begins the process of mass exporting to a csv file, by creating a beanstalk job containing which datafields to export for each datarecord being exported
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


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                throw new ODRBadRequestException('CSVExport called on a child datatype');

            $session = $request->getSession();
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');

            $url = $this->container->getParameter('site_baseurl');
            $url .= $this->container->get('router')->generate('odr_csv_export_construct');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$user->hasRole('ROLE_ADMIN') || !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This doesn't make sense on a master datatype
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to export from a master template');



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
                throw new ODRBadRequestException('Invalid radio delimiter');
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
            $datatree_array = $dti_service->getDatatreeArray();
            $valid_csv_export_datatypes = self::getValidCSVExportDatatypes($datatree_array, $datatype->getId());

            $dt_array = $dti_service->getDatatypeArray($datatype->getId(), true);    // may need linked datatypes
            $df_mapping = array();

            foreach ($datafields as $num => $df_id) {
                foreach ($dt_array as $dt_id => $dt) {
                    if ( isset($dt['dataFields'][$df_id]) ) {
                        // This field isn't valid to export when it's from a child/linked datatype
                        //  that allows multiple child/linked datarecords
                        if ( !isset($valid_csv_export_datatypes[$dt_id]) )
                            throw new ODRBadRequestException('Datafield '.$df_id.' (datatype '.$dt_id.') can exist multiple times relative to top-level datatype '.$datatype->getId());

                        // Otherwise, ensure the correct delimiters are set
                        $df_mapping[$df_id] = $dt_id;

                        $df = $dt['dataFields'][$df_id];
                        $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];

                        // Require the relevant delimiter to be set if exporting File/Image/Radio/Tag typeclasses
                        if ( ($typeclass === 'File' || $typeclass === 'Image') && is_null($file_image_delimiter) )
                            throw new ODRBadRequestException('File/Image delimiter not set');
                        if ($typeclass === 'Radio' && is_null($radio_delimiter) )
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

            if ( count($datafields) !== count($df_mapping) )
                throw new ODRNotFoundException('Datafield');


            // ----------------------------------------
            // Grab datarecord list and search key from user session...didn't use the cache because
            //  that could've been cleared and would cause this to work on a different subset of
            //  datarecords
            if ( !$session->has('csv_export_datarecord_lists') )
                throw new ODRBadRequestException('Missing CSVExport session variable');

            $list = $session->get('csv_export_datarecord_lists');
            if ( !isset($list[$odr_tab_id]) )
                throw new ODRBadRequestException('Missing CSVExport session variable');

            if ( !isset($list[$odr_tab_id]['encoded_search_key'])
                || !isset($list[$odr_tab_id]['datarecord_list'])
            ) {
                throw new ODRBadRequestException('Malformed CSVExport session variable');
            }

            $search_key = $list[$odr_tab_id]['encoded_search_key'];
            if ($search_key === '')
                throw new ODRBadRequestException('Search key is blank');

            // Need a list of datarecords from the user's session to be able to export them...
            $datarecords = trim($list[$odr_tab_id]['datarecord_list']);
            if ($datarecords === '') {
                // ...but no such datarecord list exists....redirect to search results page
                return $search_redirect_service->redirectToSearchResult($search_key, 0);
            }
            $datarecords = explode(',', $datarecords);

//print '<pre>'.print_r($datarecords, true).'</pre>';    exit();

            // Shouldn't be an issue, but delete the datarecord list out of the user's session
            unset( $list[$odr_tab_id] );
            $session->set('csv_export_datarecord_lists', $list);


            // ----------------------------------------
            // Get/create an entity to track the progress of this datatype recache
            $job_type = 'csv_export';
            $target_entity = 'datatype_'.$datatype_id;
            $additional_data = array('description' => 'Exporting data from DataType '.$datatype_id);
            $restrictions = '';
            $total = count($datarecords);
            $reuse_existing = false;
//$reuse_existing = true;

            // Determine if this user already has an export job going for this datatype
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => $job_type, 'target_entity' => $target_entity, 'createdBy' => $user->getId(), 'completed' => null) );
            if ($tracked_job !== null)
                throw new \Exception('You already have an export job going for this datatype...wait until that one finishes before starting a new one');
            else
                $tracked_job = self::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);

            $tracked_job_id = $tracked_job->getId();


            // ----------------------------------------
            // Create a beanstalk job for each of these datarecords
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');     // debug purposes only
            foreach ($datarecords as $num => $datarecord_id) {

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

                        'datarecord_id' => $datarecord_id,    // top-level datarecord id
                        'datafields' => $datafields,
                        'redis_prefix' => $redis_prefix,    // debug purposes only
                        'datatype_id' => $datatype_id,
                        'url' => $url,
                        'api_key' => $api_key,
                    )
                );

//print '<pre>'.print_r($payload, true).'</pre>';    exit();

                $delay = 1; // one second
                $pheanstalk->useTube('csv_export_start')->put($payload, $priority, $delay);
            }
        }
        catch (\Exception $e) {
            $source = 0x86acf50b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Digs through the given $datatree_array starting from $top_level_datatype_id, and returns an
     * array of all child/linked datatype ids that are only permitted to have a single child/linked
     * record.
     *
     * Required because CSV format can't really describe multiple child/linked records in a single
     * sheet in a single file.
     *
     * @param array $datatree_array @see DatatypeInfoService::getDatatreeArray()
     * @param int $top_level_datatype_id
     *
     * @return array
     */
    private function getValidCSVExportDatatypes($datatree_array, $top_level_datatype_id)
    {
        // Going to build a list of child/linked datatype ids that don't allow multiple child/linked
        //  records, relative to the top-level datatype id
        $valid_datatype_ids = array($top_level_datatype_id => 1);

        $ids = array();
        $ids[] = $top_level_datatype_id;
        while ( count($ids) > 0 ) {
            foreach ($ids as $num => $current_dt_id) {
                // Going to deal with this datatype id now...
                unset( $ids[$num] );

                // See if the current datatype has any child datatypes...
                foreach ($datatree_array['descendant_of'] as $child_dt_id => $parent_dt_id) {
                    if ( $parent_dt_id === $current_dt_id ) {
                        // ...if it does, then only save the child datatatype id if the relationship
                        //  only allows single child records
                        if ( !isset($datatree_array['multiple_allowed'][$child_dt_id]) ) {
                            $ids[] = $child_dt_id;
                            $valid_datatype_ids[$child_dt_id] = 1;
                        }
                    }
                }

                // See if the current datatype links to any other datatype...
                foreach ($datatree_array['linked_from'] as $descendant_dt_id => $ancestor_dt_ids) {
                    foreach ($ancestor_dt_ids as $parent_dt_id) {
                        if ( $parent_dt_id === $current_dt_id ) {
                            // ...if it does, then only save the remote datatatype id if the relationship
                            //  only allows single linked records
                            if ( !isset($datatree_array['multiple_allowed'][$descendant_dt_id]) ) {
                                $ids[] = $descendant_dt_id;
                                $valid_datatype_ids[$descendant_dt_id] = 1;
                            }
                        }
                    }
                }
            }
        }

        // Done locating everything
        return $valid_datatype_ids;
    }


    /**
     * Given a datarecord id and a list of datafield ids, builds a line of csv data used by Ddeboer\DataImport\Writer\CsvWriter later
     *
     * @param Request $request
     *
     * @return Response
     */
    public function csvExportConstructAction(Request $request)
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
                || !isset($post['datarecord_id'])
                || !isset($post['datatype_id'])
                || !isset($post['datafields'])
                || !isset($post['api_key'])
                || !isset($post['delimiter'])
            ) {
                throw new ODRBadRequestException();
            }

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $datarecord_id = $post['datarecord_id'];
            $datatype_id = $post['datatype_id'];
            $datafields = $post['datafields'];
            $api_key = $post['api_key'];
            $delimiter = $post['delimiter'];

            // Don't need to do any additional verification on these...that was handled back in
            //  csvExportStartAction()
            $file_image_delimiter = null;
            if ( isset($post['file_image_delimiter']) )
                $file_image_delimiter = $post['file_image_delimiter'];

            $radio_delimiter = null;
            if ( isset($post['radio_delimiter']) )
                $radio_delimiter = $post['radio_delimiter'];

            $tag_delimiter = null;
            if ( isset($post['tag_delimiter']) )
                $tag_delimiter = $post['tag_delimiter'];

            $tag_hierarchy_delimiter = null;
            if ( isset($post['tag_hierarchy_delimiter']) )
                $tag_hierarchy_delimiter = $post['tag_hierarchy_delimiter'];


            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
//            $logger = $this->get('logger');

            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException();

            $url = $this->container->getParameter('site_baseurl');
            $url .= $this->container->get('router')->generate('odr_csv_export_worker');


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var TagHelperService $th_service */
            $th_service = $this->container->get('odr.tag_helper_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                throw new ODRBadRequestException('Datatype '.$datatype_id.' is not a top-level datatype');
            // This doesn't make sense on a master datatype
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to export from a master template');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            if ($datarecord->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException('Datarecord does not match Datatype');


            $datarecord_data = array();

            // ----------------------------------
            // Gather basic info about all datafields prior to actually loading data
            $dt_array = $dti_service->getDatatypeArray($datatype_id, true);    // may need linked datatypes
            $flipped_datafields = array_flip($datafields);
            $df_mapping = array();

            $fieldtype_list = array();
            foreach ($dt_array as $dt_id => $dt) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    if ( isset($flipped_datafields[$df_id]) ) {
                        $fieldtype = $df['dataFieldMeta']['fieldType'];
                        $typeclass = $fieldtype['typeClass'];
                        $typename = $fieldtype['typeName'];

                        if ($typename !== 'Markdown') {
                            if ( !isset($fieldtype_list[$typeclass]) )
                                $fieldtype_list[$typeclass] = array();
                            $fieldtype_list[$typeclass][] = $df_id;

                            $df_mapping[$df_id] = $dt_id;

                            if ($typeclass == 'File')
                                $datarecord_data[$df_id] = array('typeclass' => 'file');
                            else if ($typeclass == 'Image')
                                $datarecord_data[$df_id] = array('typeclass' => 'image');
                            else if ($typeclass == 'Radio')
                                $datarecord_data[$df_id] = array('typeclass' => 'radio');
                            else if ($typeclass == 'Tag')
                                $datarecord_data[$df_id] = array('typeclass' => 'tag');
                            else
                                $datarecord_data[$df_id] = '';
                        }
                    }
                }
            }

//print_r($df_mapping);  exit();
//print_r($fieldtype_list);  exit();
//print_r($datarecord_data);  exit();

            // ----------------------------------
            // Need to grab external id for this top-level datarecord
            $dr_array = $dri_service->getDatarecordArray($datarecord->getId(), true);    // may need links

            $external_id = $dr_array[$datarecord->getId()]['externalIdField_value'];
            if ( is_null($external_id) )
                $external_id = '';
            $tag_hierarchy = null;


            // ----------------------------------
            // Locate data from the cache entry for each of the datafields selected for export
            foreach ($fieldtype_list as $typeclass => $df_list) {
                if ($typeclass == 'File') {
                    // Load file data from the cached datarecord array
                    self::getFileData($datarecord_data, $dr_array, $df_list);
                }
                else if ($typeclass == 'Image') {
                    // Load image data from the cached datarecord array
                    self::getImageData($datarecord_data, $dr_array, $df_list);
                }
                else if ($typeclass == 'Radio') {
                    // Load radio selection data from the cached datarecord array
                    self::getRadioData($datarecord_data, $dr_array, $df_list);
                }
                else if ($typeclass == 'Tag') {
                    // Going to need the tag hierarchy for this, most likely
                    if ( is_null($tag_hierarchy) ) {
                        $tag_hierarchy = array();

                        // ...might as well check for and store tag hierarchies for each datatype
                        foreach ($dt_array as $dt_id => $dt) {
                            if ( !isset($tag_hierarchy[$dt_id]) ) {
                                // This only responds to top-level datatypes...but not worth it to
                                //  filter out child datatypes here
                                $tmp = $th_service->getTagHierarchy($dt_id);
                                foreach ($tmp as $tag_dt_id => $value)
                                    $tag_hierarchy[$tag_dt_id] = $tmp[$tag_dt_id];
                            }
                        }
                    }

                    // Load tag selection data from the cached datarecord array
                    self::getTagData($datarecord_data, $dt_array, $dr_array, $tag_hierarchy, $df_list, $tag_hierarchy_delimiter);
                }
                else {
                    // Load data for any other fieldtype from the cached datarecord array
                    self::getOtherData($datarecord_data, $dr_array, $typeclass, $df_list);
                }
            }

            foreach ($datarecord_data as $df_id => $data) {
                if ( is_null($data) ) {
                    // Ensure that a null value doesn't get passed to the next phase
                    $datarecord_data[$df_id] = '';
                }
                else if ( is_array($data) ) {
                    // Otherwise, going to need to convert the array into a string...
                    $typeclass = $data['typeclass'];
                    unset( $data['typeclass'] );

                    if ( count($data) === 0 ) {
                        // If there are no multiple entries in a field that's supposed to have them,
                        //  just convert to the empty string
                        $datarecord_data[$df_id] = '';
                    }
                    else {
                        if ($typeclass === 'file' || $typeclass === 'image') {
                            // Convert any File/Image fields from an array into a string
                            $datarecord_data[$df_id] = implode($file_image_delimiter, $data);
                        }
                        else if ($typeclass === 'radio') {
                            // Convert any Radio fields from an array into a string
                            $datarecord_data[$df_id] = implode($radio_delimiter, $data);
                        }
                        else if ($typeclass === 'tag') {
                            // Convert any Tag fields from an array into a string
                            $datarecord_data[$df_id] = implode($tag_delimiter, $data);
                        }
                    }
                }
            }


            // Sort by datafield id to ensure columns are always in same order in csv file
            ksort($datarecord_data);
//print_r($datarecord_data);  exit();
//var_dump($datarecord_data);  exit();

            // TODO - don't add this if the external id field is currently selected?
            // TODO - don't add this if the external id field is the only selection?
            $line = array();
            $line[] = $external_id;

            foreach ($datarecord_data as $df_id => $data)
                $line[] = $data;
//print_r($line);  exit();
//var_dump($line);  exit();


            // ----------------------------------------
            // Create a beanstalk job for this datarecord
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');     // debug purposes only
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    'tracked_job_id' => $tracked_job_id,
                    'user_id' => $user_id,
                    'delimiter' => $delimiter,
                    'datarecord_id' => $datarecord_id,
                    'datatype_id' => $datatype_id,
                    'datafields' => $datafields,
                    'line' => $line,
                    'redis_prefix' => $redis_prefix,    // debug purposes only
                    'url' => $url,
                    'api_key' => $api_key,
                )
            );

//print_r($payload);

            $delay = 1; // one second
            $pheanstalk->useTube('csv_export_worker')->put($payload, $priority, $delay);

            $return['d'] = '';
        }
        catch (\Exception $e) {
            $source = 0x5bd2c168;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Extracts file data for exporting from the given top-level $dr_array.
     *
     * @param array $datarecord_data
     * @param array $dr_array @see DatarecordInfoService::getDatarecordArray()
     * @param array $df_list An array of the ids of all file datafields being exported
     */
    private function getFileData(&$datarecord_data, $dr_array, $df_list)
    {
        // Going to need these to generate routes
        $baseurl = $this->container->getParameter('site_baseurl');
        $router = $this->container->get('router');

        foreach ($df_list as $num => $df_id) {
            // Need to locate the datarecord this datafield belongs to
            $dr = null;
            foreach ($dr_array as $dr_id => $dr_entry) {
                if ( isset($dr_entry['dataRecordFields']) && isset($dr_entry['dataRecordFields'][$df_id]) ) {
                    $dr = $dr_entry;
                    break;
                }
            }

            // Only continue if a datarecord has data for the datafield...
            if ( !is_null($dr) ) {
                $drf = $dr['dataRecordFields'][$df_id];
                if ( isset($drf['file']) ) {
                    foreach ($drf['file'] as $num => $file) {
                        $file_id = $file['id'];
                        $route = $baseurl.$router->generate('odr_file_download', array('file_id' => $file_id));

                        $datarecord_data[$df_id][] = $route;
                    }
                }
            }
        }
    }


    /**
     * Extracts image data for exporting from the given top-level $dr_array.
     *
     * @param array $datarecord_data
     * @param array $dr_array @see DatarecordInfoService::getDatarecordArray()
     * @param array $df_list An array of the ids of all image datafields being exported
     */
    private function getImageData(&$datarecord_data, $dr_array, $df_list)
    {
        // Going to need these to generate routes
        $baseurl = $this->container->getParameter('site_baseurl');
        $router = $this->container->get('router');

        foreach ($df_list as $num => $df_id) {
            // Need to locate the datarecord this datafield belongs to
            $dr = null;
            foreach ($dr_array as $dr_id => $dr_entry) {
                if ( isset($dr_entry['dataRecordFields']) && isset($dr_entry['dataRecordFields'][$df_id]) ) {
                    $dr = $dr_entry;
                    break;
                }
            }

            // Only continue if a datarecord has data for the datafield...
            if ( !is_null($dr) ) {
                $drf = $dr['dataRecordFields'][$df_id];
                if ( isset($drf['image']) ) {
                    foreach ($drf['image'] as $num => $image) {
                        // First level in here will be the thumbnail...want the full-size image
                        $parent_image_id = $image['parent']['id'];
                        $route = $baseurl.$router->generate('odr_image_download', array('image_id' => $parent_image_id));

                        $datarecord_data[$df_id][] = $route;
                    }
                }
            }
        }
    }


    /**
     * Extracts radio selection data for exporting from the given top-level $dr_array.
     *
     * @param array $datarecord_data
     * @param array $dr_array @see DatarecordInfoService::getDatarecordArray()
     * @param array $df_list An array of the ids of all radio datafields being exported
     */
    private function getRadioData(&$datarecord_data, $dr_array, $df_list)
    {
        foreach ($df_list as $num => $df_id) {
            // Need to locate the datarecord this datafield belongs to
            $dr = null;
            foreach ($dr_array as $dr_id => $dr_entry) {
                if ( isset($dr_entry['dataRecordFields']) && isset($dr_entry['dataRecordFields'][$df_id]) ) {
                    $dr = $dr_entry;
                    break;
                }
            }

            // Only continue if a datarecord has data for the datafield...
            if ( !is_null($dr) ) {
                $drf = $dr['dataRecordFields'][$df_id];
                if ( isset($drf['radioSelection']) ) {
                    foreach ($drf['radioSelection'] as $ro_id => $rs) {
                        // Only save in the data array if it's selected
                        if ($rs['selected'] === 1) {
                            $option_name = $rs['radioOption']['optionName'];
                            $datarecord_data[$df_id][] = $option_name;
                        }
                    }
                }
            }
        }
    }


    /**
     * Extracts tag selection data for exporting from the given top-level $dr_array.
     *
     * @param array $datarecord_data
     * @param array $dr_array @see DatarecordInfoService::getDatarecordArray()
     * @param array $dt_array @see DatatypeInfoService::getDatatypeArray()
     * @param array $tag_hierarchy @see TagHelperService::getTagHierarchy()
     * @param array $df_list An array of the ids of all tag datafields being exported
     * @param string $tag_hierarchy_delimiter
     */
    private function getTagData(&$datarecord_data, $dt_array, $dr_array, $tag_hierarchy, $df_list, $tag_hierarchy_delimiter)
    {
        foreach ($df_list as $num => $df_id) {
            // Need to locate the datarecord this datafield belongs to
            $dr = null;
            foreach ($dr_array as $dr_id => $dr_entry) {
                if ( isset($dr_entry['dataRecordFields']) && isset($dr_entry['dataRecordFields'][$df_id]) ) {
                    $dr = $dr_entry;
                    break;
                }
            }

            // Only continue if a datarecord has data for the datafield...
            if ( !is_null($dr) ) {
                // Get the tag tree for this datatype/datafield if it exists
                $dt_id = $dr['dataType']['id'];

                $tag_tree = array();
                if ( isset($tag_hierarchy[$dt_id]) && isset($tag_hierarchy[$dt_id][$df_id]) ) {
                    // Flip the tag hierarchy so it's easier to work with from child tags
                    $tmp = $tag_hierarchy[$dt_id][$df_id];
                    foreach ($tmp as $parent_tag_id => $child_tags) {
                        foreach ($child_tags as $child_tag_id => $num)
                            $tag_tree[$child_tag_id] = $parent_tag_id;
                    }
                }

                $drf = $dr['dataRecordFields'][$df_id];
                if ( isset($drf['tagSelection']) ) {
                    foreach ($drf['tagSelection'] as $t_id => $ts) {
                        // Only save in the data array if it's selected
                        // The dri_service only marks leaf tags as selected
                        if ($ts['selected'] === 1) {
                            $current_tag_id = $ts['tag']['id'];
                            $full_tag_name = null;

                            // Need to locate every parent of this tag so all of the
                            //  tag names can be concatenated together
                            $parents = array();
                            $parents[] = $current_tag_id;
                            while ( isset($tag_tree[$current_tag_id]) ) {
                                $current_tag_id = $tag_tree[$current_tag_id];
                                $parents[] = $current_tag_id;
                            }

                            // Reverse the order so the datatype_array can be traversed
                            //  from top-level to leaf-level
                            $parents = array_reverse($parents);
                            $tag_group = $dt_array[$dt_id]['dataFields'][$df_id]['tags'];
                            foreach ($parents as $num => $tag_id) {
                                // Store this part of the tag name
                                $tag_name = $tag_group[$tag_id]['tagName'];
                                if ( is_null($full_tag_name) )
                                    $full_tag_name = $tag_name;
                                else
                                    $full_tag_name .= ' '.$tag_hierarchy_delimiter.' '.$tag_name;

                                // Drop down to the next level if it exists
                                if ( isset($tag_group[$tag_id]['children']) )
                                    $tag_group = $tag_group[$tag_id]['children'];
                            }

                            // Store the full tag name
                            $datarecord_data[$df_id][] = $full_tag_name;
                        }
                    }
                }
            }
        }
    }


    /**
     * Extracts text/number/boolean data for exporting from the given top-level $dr_array.
     *
     * @param array $datarecord_data
     * @param array $dr_array @see DatarecordInfoService::getDatarecordArray()
     * @param string $typeclass
     * @param array $df_list An array of the ids of all $typeclass datafields being exported
     */
    private function getOtherData(&$datarecord_data, $dr_array, $typeclass, $df_list)
    {
        foreach ($df_list as $num => $df_id) {
            // Need to locate the datarecord this datafield belongs to
            $dr = null;
            foreach ($dr_array as $dr_id => $dr_entry) {
                if ( isset($dr_entry['dataRecordFields']) && isset($dr_entry['dataRecordFields'][$df_id]) ) {
                    $dr = $dr_entry;
                    break;
                }
            }

            // Only continue if a datarecord has data for the datafield...
            if ( !is_null($dr) ) {
                $drf = $dr['dataRecordFields'][$df_id];

                $tc = lcfirst($typeclass);
                if ( isset($drf[$tc]) ) {
                    foreach ($drf[$tc] as $num => $storage_entity) {
                        // Should only be one...
                        $value = $storage_entity['value'];

                        if ($typeclass === 'DatetimeValue')
                            $datarecord_data[$df_id] = $value->format('Y-m-d');
                        else
                            $datarecord_data[$df_id] = $value;
                    }
                }
            }
        }
    }


    /**
     * Writes a line of csv data to a file
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
                || !isset($post['line'])
                || !isset($post['datafields'])
                || !isset($post['random_key'])
                || !isset($post['api_key'])
                || !isset($post['delimiter'])
            ) {
                throw new ODRBadRequestException();
            }

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $line = $post['line'];
            $datafields = $post['datafields'];
            $random_key = $post['random_key'];  // is generated by CSVExportWorkerCommand.php
            $api_key = $post['api_key'];
            $delimiter = $post['delimiter'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
//            $logger = $this->get('logger');

            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

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
            $csv_export_path = $this->getParameter('odr_web_directory').'/uploads/csv_export/';
            if ( !file_exists($csv_export_path) )
                mkdir( $csv_export_path );

            $tmp_csv_export_path = $csv_export_path.'tmp/';
            if ( !file_exists($tmp_csv_export_path) )
                mkdir( $tmp_csv_export_path );


            // Open the indicated file
            $filename = 'f_'.$random_key.'.csv';
            $handle = fopen($tmp_csv_export_path.$filename, 'a');
            if ($handle !== false) {
                // Write the line given to the file
                // https://github.com/ddeboer/data-import/blob/master/src/Ddeboer/DataImport/Writer/CsvWriter.php
//                $delimiter = "\t";
                $enclosure = "\"";
                $writer = new CsvWriter($delimiter, $enclosure);

                $writer->setStream($handle);
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
//print $random_key_hash."\n";


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

//print 'rows affected: '.$rowsAffected."\n";

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
                $header_line = array(0 => '_external_id');
                $query = $em->createQuery(
                   'SELECT df.id AS id, dfm.fieldName AS fieldName
                    FROM ODRAdminBundle:DataFields AS df
                    JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                    WHERE df.id IN (:datafields)
                    AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
                )->setParameters( array('datafields' => $datafields) );
                $results = $query->getArrayResult();
                foreach ($results as $num => $result) {
                    $df_id = $result['id'];
                    $df_name = $result['fieldName'];

                    $header_line[$df_id] = $df_name;
                }

                // Sort by datafield id so order of header columns matches order of data
                ksort($header_line);

//print_r($header_line);

                // Make a "final" file for the export, and insert the header line
                $final_filename = 'export_'.$user_id.'_'.$tracked_job_id.'.csv';
                $final_file = fopen($csv_export_path.$final_filename, 'w');     // this should be created in the web/uploads/csv_export/ directory, not the web/uploads/csv_export/tmp/ directory

                if ($final_file !== false) {
//                    $delimiter = "\t";
                    $enclosure = "\"";
                    $writer = new CsvWriter($delimiter, $enclosure);

                    $writer->setStream($final_file);
                    $writer->writeItem($header_line);
                }
                else {
                    throw new ODRException('Could not open csv finalize export file.');
                }

                fclose($final_file);

                // ----------------------------------------
                // Now that the "final" file exists, need to splice the temporary files together into it
                $url = $this->container->getParameter('site_baseurl');
                $url .= $this->container->get('router')->generate('odr_csv_export_finalize');

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

            $return['d'] = '';
        }
        catch (\Exception $e) {
            $source = 0xc0abdfce;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Takes a list of temporary files used for csv exporting, and appends each of their contents to a "final" export file
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
            $pheanstalk = $this->get('pheanstalk');
//            $logger = $this->get('logger');

            if ($api_key !== $beanstalk_api_key)
                throw new ODRBadRequestException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();


            // -----------------------------------------
            // Append the contents of one of the temporary files to the final file
            $csv_export_path = $this->getParameter('odr_web_directory').'/uploads/csv_export/';
            $final_file = fopen($csv_export_path.$final_filename, 'a');
            if (!$final_file)
                throw new ODRException('Unable to open csv export finalize file');

            // Go through and append the contents of each of the temporary files to the "final" file
            $tracked_csv_export_id = null;
            foreach ($random_keys as $tracked_csv_export_id => $random_key) {
                $tmp_filename = 'f_'.$random_key.'.csv';
                $str = file_get_contents($csv_export_path.'tmp/'.$tmp_filename);
//print $str."\n\n";

                if ( fwrite($final_file, $str) === false )
                    print 'could not write to "'.$csv_export_path.$final_filename.'"'."\n";

                // Done with this intermediate file, get rid of it
                if ( unlink($csv_export_path.'tmp/'.$tmp_filename) === false )
                    print 'could not unlink "'.$csv_export_path.'tmp/'.$tmp_filename.'"'."\n";

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
                $url = $this->container->getParameter('site_baseurl');
                $url .= $this->container->get('router')->generate('odr_csv_export_finalize');

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
     * @param integer $user_id The user requesting the download
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
            if ( !$user->hasRole('ROLE_ADMIN') || !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This doesn't make sense on a master datatype
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to export from a master template');


            $csv_export_path = $this->getParameter('odr_web_directory').'/uploads/csv_export/';
            $filename = 'export_'.$user_id.'_'.$tracked_job_id.'.csv';

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

}
