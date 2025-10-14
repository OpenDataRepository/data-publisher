<?php

/**
 * Open Data Repository Data Publisher
 * Debug Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * There are certain pieces of ODR functionality which sometimes need debugging...but their code is
 * off in an inconvenient location (such as CSVExport, MassEdit, etc).  This controller provides
 * alternate routes to ease testing.
 *
 * These controller actions should be blocked until they actually need to be debugged.
 *
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CSVExportHelperService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;


class DebugController extends ODRCustomController
{

    /**
     * Renders a barebones page to set up a CSVExport for the purpose of debugging the actual export
     * process.
     *
     * @param integer $datatype_id
     * @param string $search_key
     * @param Request $request
     *
     * @return Response
     */
    public function debugcsvexportstartAction($datatype_id, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // If the search key is blank, then silently provide the default search key to the form
            if ( trim($search_key) === '' )
                $search_key = $search_key_service->encodeSearchKey( array('dt_id' => $datatype_id) );

            // Need the datatype array to be able to render datafield selection...
            $dt_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), true);    // need links

            // ...can't get away with not stacking it, unfortunately, due to ODR's linking rules
            //  permitting mulitple "paths" to reach the same linked descendent datatype
            // e.g. A links to B links to C, A links to C
            $dt_array = $database_info_service->stackDatatypeArray($dt_array, $datatype_id);

            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Debug:debug_csv_export_start.html.twig',
                    array(
                        'initial_datatype_id' => $datatype_id,
                        'datatype_array' => $dt_array,
                        'search_key' => $search_key,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x5c1eaf8b;
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
     * Entry point for debugging a fake CSVExport request
     *
     * @param Request $request
     *
     * @return Response
     */
    public function debugcsvexportAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();

            if ( !isset($post['search_key'])
                || !isset($post['datafields'])
                || !isset($post['delimiter'])
            ) {
                throw new ODRBadRequestException();
            }

            $datafields = $post['datafields'];    // TODO - plugin_datafields?
            $search_key = $post['search_key'];

            $delimiter = trim($post['delimiter']);
            if ($delimiter === 'tab')
                $delimiter = "\t";

            $file_image_delimiter = null;
            if ( isset($post['file_image_delimiter']) )
                $file_image_delimiter = trim($post['file_image_delimiter']);

            $radio_delimiter = null;
            if ( isset($post['radio_delimiter']) )
                $radio_delimiter = trim($post['radio_delimiter']);
            if ($radio_delimiter === 'space')
                $radio_delimiter = ' ';

            $tag_delimiter = null;
            if ( isset($post['tag_delimiter']) )
                $tag_delimiter = trim($post['tag_delimiter']);

            $tag_hierarchy_delimiter = null;
            if ( isset($post['tag_hierarchy_delimiter']) )
                $tag_hierarchy_delimiter = trim($post['tag_hierarchy_delimiter']);


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CSVExportHelperService $csv_export_helper_service */
            $csv_export_helper_service = $this->container->get('odr.csv_export_helper_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');

            // Should validate the search key first...
            $search_params = $search_key_service->validateSearchKey($search_key);
            // ...if it's valid, get the datatype id out of it
            $datatype_id = $search_params['dt_id'];

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            $user_permissions = $permissions_service->getUserPermissionsArray($user);


            // ----------------------------------------
            // Need to re-run the search to get a couple lists of datarecords for the export
            $export_search_results = $csv_export_helper_service->getExportSearchResults($datatype, $search_key, $user_permissions);
            $grandparent_datarecord_list = $export_search_results['grandparent_datarecord_list'];
            $complete_datarecord_list = $export_search_results['complete_datarecord_list'];
            $inflated_list = $export_search_results['inflated_list'];


            // ----------------------------------------
            // Create the required url and the parameters to send
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');     // debug purposes only

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
                if(
                    $counter % 200 === 0
                    || $counter === count($grandparent_datarecord_list)
                ) {
                    $parameters = array(
                        'tracked_job_id' => -1,    // don't create database entries to track this
                        'user_id' => $user->getId(),

                        'delimiter' => $delimiter,
                        'file_image_delimiter' => $file_image_delimiter,
                        'radio_delimiter' => $radio_delimiter,
                        'tag_delimiter' => $tag_delimiter,
                        'tag_hierarchy_delimiter' => $tag_hierarchy_delimiter,

                        'datatype_id' => $datatype_id,
                        'datarecord_id' => $datarecord_ids,
                        'complete_datarecord_list' => $complete_datarecord_list_array,
                        'datafields' => $datafields,

                        'redis_prefix' => $redis_prefix,    // debug purposes only
                        'job_order' => $job_order,
                        'api_key' => $api_key,
                    );

                    $csv_export_helper_service->execute($parameters);

                    $datarecord_ids = [];
                    $complete_datarecord_list_array = [];
                    $job_order++;
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x6733a76c;
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
