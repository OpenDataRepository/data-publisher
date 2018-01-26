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

// Controllers/Classes
use ODR\OpenRepository\SearchBundle\Controller\DefaultController as SearchController;
// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // If $search_theme_id is set...
            if ($search_theme_id != 0) {
                // ...require a search key to also be set
                if ($search_key == '')
                    throw new ODRBadRequestException();

                // ...require the referenced theme to exist
                /** @var Theme $search_theme */
                $search_theme = $em->getRepository('ODRAdminBundle:Theme')->find($search_theme_id);
                if ($search_theme == null)
                    throw new ODRNotFoundException('Search Theme');

                // ...require it to match the datatype being rendered
                if ($search_theme->getDataType()->getId() !== $datatype->getId())
                    throw new ODRBadRequestException();
            }


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);
            $datafield_permissions = $pm_service->getDatafieldPermissions($user);

            // Ensure user has permissions to be doing this
            if ( !$user->hasRole('ROLE_ADMIN') || !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];


            // ----------------------------------------
            // If this datarecord is being viewed from a search result list, attempt to grab the list of datarecords from that search result
            $encoded_search_key = '';
            if ($search_key !== '') {
                // 
                $data = parent::getSavedSearch($em, $user, $datatype_permissions, $datafield_permissions, $datatype->getId(), $search_key, $request);
                $encoded_search_key = $data['encoded_search_key'];
                $datarecord_list = $data['datarecord_list'];

                // If there is no tab id for some reason, or the user is attempting to view a datarecord from a search that returned no results...
                if ( $odr_tab_id === '' || $data['redirect'] == true || ($encoded_search_key !== '' && $datarecord_list === '') ) {
                    // ...get the search controller to redirect to "no results found" page
                    $url = $this->generateUrl(
                        'odr_search_render',
                        array(
                            'search_theme_id' => $search_theme_id,
                            'search_key' => $data['encoded_search_key']
                        )
                    );

                    return parent::searchPageRedirect($user, $url);
                }

                // Store the datarecord list in the user's session...there is a chance that it could get wiped if it was only stored in memcached
                $session = $request->getSession();
                $list = $session->get('csv_export_datarecord_lists');
                if ($list == null)
                    $list = array();

                $list[$odr_tab_id] = array('datarecord_list' => $datarecord_list, 'encoded_search_key' => $encoded_search_key);
                $session->set('csv_export_datarecord_lists', $list);
            }

            // Generate the HTML required for a header
            $templating = $this->get('templating');
            $header_html = $templating->render(
                'ODRAdminBundle:CSVExport:csvexport_header.html.twig',
                array(
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $encoded_search_key,
                    'offset' => $offset,
                )
            );

            // Get the mass edit page rendered
            $page_html = self::csvExportRender($datatype_id, $odr_tab_id, $request);
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
     * Renders and returns the html used for performing csv exporting
     * 
     * @param integer $datatype_id    The database id that the search was performed on.
     * @param string $odr_tab_id
     * @param Request $request
     * 
     * @return string
     */
    private function csvExportRender($datatype_id, $odr_tab_id, Request $request)
    {
        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');
        /** @var ThemeInfoService $theme_service */
        $theme_service = $this->container->get('odr.theme_info_service');


        // All of these should already exist
        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        /** @var Theme $theme */
//        $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );


        // --------------------
        // Determine user privileges
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        $user_permissions = $pm_service->getUserPermissionsArray($user);
        // --------------------


        // ----------------------------------------
        // Grab the cached version of the desired datatype
        $include_links = false;
        $datatype_data = $dti_service->getDatatypeArray($datatype->getId(), $include_links);
//print '<pre>'.print_r($datatype_data, true).'</pre>'; exit();

        // Filter by user permissions
        $datarecord_data = array();
        $pm_service->filterByGroupPermissions($datatype_data, $datarecord_data, $user_permissions);

        $theme_array = $theme_service->getThemesForDatatype($datatype->getId(), $user, 'master', $include_links);


        // ----------------------------------------
        // Render the CSVExport page
        $templating = $this->get('templating');
        $html = $templating->render(
            'ODRAdminBundle:CSVExport:csvexport_ajax.html.twig',
            array(
                'datatype_array' => $datatype_data,
                'initial_datatype_id' => $datatype_id,
                'theme_array' => $theme_array,

                'odr_tab_id' => $odr_tab_id,
            )
        );

        return $html;
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

            if ( !isset($post['odr_tab_id']) || !isset($post['datafields']) || !isset($post['datatype_id']) || !isset($post['csv_export_delimiter']) )
                throw new ODRBadRequestException();

            $odr_tab_id = $post['odr_tab_id'];
            $datafields = $post['datafields'];
            $datatype_id = $post['datatype_id'];
            $delimiter = $post['csv_export_delimiter'];

            $secondary_delimiter = null;
            if ( isset($post['csv_export_secondary_delimiter']) )
                $secondary_delimiter = $post['csv_export_secondary_delimiter'];

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            $session = $request->getSession();
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');

            $url = $this->container->getParameter('site_baseurl');
            $url .= $this->container->get('router')->generate('odr_csv_export_construct');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$user->hasRole('ROLE_ADMIN') || !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Ensure datafield ids are valid
            foreach ($datafields as $num => $datafield_id) {
                /** @var DataFields $datafield */
                $datafield = $repo_datafields->find($datafield_id);
                if ($datafield == null)
                    throw new ODRNotFoundException('Datafield');
                if ($datafield->getDataType()->getId() != $datatype->getId() )
                    throw new ODRBadRequestException('Invalid Datafield');
            }

            // Translate delimiter from string to character
            switch ($delimiter) {
                case 'tab':
                    $delimiter = "\t";
                    break;
                case 'space':
                    $delimiter = " ";
                    break;
                case 'comma':
                    $delimiter = ",";
                    break;
                case 'semicolon':
                    $delimiter = ";";
                    break;
                case 'colon':
                    $delimiter = ":";
                    break;
                case 'pipe':
                    $delimiter = "|";
                    break;
                default:
                    throw new ODRBadRequestException('Invalid delimiter');
                    break;
            }
            switch ($secondary_delimiter) {
/*
                case 'tab':
                    $secondary_delimiter = "\t";
                    break;
                case 'space':
                    $secondary_delimiter = " ";
                    break;
                case 'comma':
                    $secondary_delimiter = ",";
                    break;
*/
                case 'semicolon':
                    $secondary_delimiter = ";";
                    break;
                case 'colon':
                    $secondary_delimiter = ":";
                    break;
                case 'pipe':
                    $secondary_delimiter = "|";
                    break;
                case null:
                    break;
                default:
                    throw new ODRBadRequestException('Invalid secondary delimiter');
                    break;
            }


            // ----------------------------------------
            // Grab datarecord list and search key from user session...not using memcached because the possibility exists that it could be deleted
            $list = $session->get('csv_export_datarecord_lists');

            $datarecords = '';
            $encoded_search_key = null;

            if ( isset($list[$odr_tab_id]) ) {
                $datarecords = $list[$odr_tab_id]['datarecord_list'];
                $encoded_search_key = $list[$odr_tab_id]['encoded_search_key'];
            }

            // If the datarecord list doesn't exist for some reason, or the user is attempting to view a datarecord from a search that returned no results...
            if ( !isset($list[$odr_tab_id]) || ($encoded_search_key !== '' && $datarecords === '') ) {
                // ...redirect to "no results found" page
                /** @var SearchController $search_controller */
                $search_controller = $this->get('odr_search_controller', $request);
                $search_controller->setContainer($this->container);

                /** @var ThemeInfoService $theme_info_service */
                $theme_info_service = $this->container->get('odr.theme_info_service');
                $search_theme_id = $theme_info_service->getPreferredTheme($user, $datatype->getId(), 'search_results');

                return $search_controller->renderAction($search_theme_id, $encoded_search_key, 1, 'searching', $request);
            }

            // TODO - delete the datarecord list/search key out of the user's session?

            $datarecords = explode(',', $datarecords);
/*
print_r($datarecords);
print_r($datafields);
return;
*/

            if ( count($datarecords) > 0 ) {
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
                            'secondary_delimiter' => $secondary_delimiter,
                            'datarecord_id' => $datarecord_id,
                            'datafields' => $datafields,
                            'redis_prefix' => $redis_prefix,    // debug purposes only
                            'datatype_id' => $datatype_id,
                            'url' => $url,
                            'api_key' => $api_key,
                        )
                    );

//print_r($payload);
//return;

                    $delay = 1; // one second
                    $pheanstalk->useTube('csv_export_start')->put($payload, $priority, $delay);
                }

            }
            else {
                throw new ODRException('No datarecords selected to export');
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

            if ( !isset($post['tracked_job_id']) || !isset($post['user_id']) || !isset($post['datarecord_id']) || !isset($post['datatype_id']) || !isset($post['datafields']) || !isset($post['api_key']) || !isset($post['delimiter']) )
                throw new ODRBadRequestException();

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $user_id = $post['user_id'];
            $datarecord_id = $post['datarecord_id'];
            $datatype_id = $post['datatype_id'];
            $datafields = $post['datafields'];
            $api_key = $post['api_key'];
            $delimiter = $post['delimiter'];

            $secondary_delimiter = null;
            if ( isset($post['secondary_delimiter']) )
                $secondary_delimiter = $post['secondary_delimiter'];

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

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            $datarecord_data = array();

            // ----------------------------------
            // Load FieldTypes of the datafields
            $query = $em->createQuery(
               'SELECT df.id AS df_id, dfm.fieldName AS fieldname, ft.typeClass AS typeclass, ft.typeName AS typename
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
                WHERE df IN (:datafields)
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
            )->setParameters( array('datafields' => $datafields) );
            $results = $query->getArrayResult();
//print_r($results);

            $typeclasses = array();
            foreach ($results as $num => $result) {
                $typeclass = $result['typeclass'];
                $typename = $result['typename'];

                if ($typeclass !== 'File' && $typeclass !== 'Image' && $typename !== 'Markdown') {
                    if ( !isset($typeclasses[ $result['typeclass'] ]) )
                        $typeclasses[ $result['typeclass'] ] = array();

                    $typeclasses[ $result['typeclass'] ][] = $result['df_id'];

                    if ($typeclass == 'Radio') {
                        $datarecord_data[ $result['df_id'] ] = array();

                        if ( ($typename == "Multiple Radio" || $typename == "Multiple Select") && $secondary_delimiter == null)
                            throw new \Exception('Invalid Form');
                    }
                    else {
                        $datarecord_data[ $result['df_id'] ] = '';
                    }
                }
            }

//print_r($typeclasses);
//return;

            // ----------------------------------
            // Need to grab external id for this datarecord
/*
            $query = $em->createQuery(
               'SELECT dr.external_id AS external_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.id = :datarecord AND dr.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $datarecord_id) );
            $result = $query->getArrayResult();
//print_r($result);
            $external_id = $result[0]['external_id'];
*/
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $external_id = $datarecord->getExternalId();        // TODO - marked as deprecated, but should it actually be used here?

            // ----------------------------------
            // Grab data for each of the datafields selected for export
            foreach ($typeclasses as $typeclass => $df_list) {
                if ($typeclass == 'Radio') {
                    $query = $em->createQuery(
                       'SELECT df.id AS df_id, rom.optionName AS option_name
                        FROM ODRAdminBundle:RadioSelection AS rs
                        JOIN ODRAdminBundle:RadioOptions AS ro WITH rs.radioOption = ro
                        JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH rs.dataRecordFields = drf
                        JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                        WHERE rs.selected = 1 AND drf.dataRecord = :datarecord AND df.id IN (:datafields)
                        AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL'
                    )->setParameters( array('datarecord' => $datarecord_id, 'datafields' => $df_list) );
                    $results = $query->getArrayResult();
//print_r($results);

                    foreach ($results as $num => $result) {
                        $df_id = $result['df_id'];
                        $option_name = $result['option_name'];

                        $datarecord_data[$df_id][] = $option_name;
                    }
                }
                else {
                    $query = $em->createQuery(
                       'SELECT df.id AS df_id, e.value AS value
                        FROM ODRAdminBundle:'.$typeclass.' AS e
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                        JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                        JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                        JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                        JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
                        WHERE dr.id = :datarecord AND df.id IN (:datafields) AND ft.typeClass = :typeclass
                        AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
                    )->setParameters( array('datarecord' => $datarecord_id, 'datafields' => $df_list, 'typeclass' => $typeclass) );
                    $results = $query->getArrayResult();

                    foreach ($results as $num => $result) {
                        $df_id = $result['df_id'];
                        $value = $result['value'];

                        // TODO - special handling for boolean?

                        if ($typeclass == 'DatetimeValue') {
                            $date = $value->format('Y-m-d');
                            if ($date == '9999-12-31')
                                $date = '';

                            $datarecord_data[$df_id] = $date;
                        }
                        else {
                            // Change nulls to empty string so they get passed to beanstalk properly
                            if ($value == null)
                                $value = '';

                            $datarecord_data[$df_id] = $value;
                        }
                    }
                }
            }

            // Convert any Radio fields from an array into a string
            foreach ($datarecord_data as $df_id => $data) {
                if ( is_array($data) ) {
                    if ( count($data) > 0 )
                        $datarecord_data[$df_id] = implode($secondary_delimiter, $data);
                    else
                        $datarecord_data[$df_id] = '';
                }
            }

            // Sort by datafield id to ensure columns are always in same order in csv file
            ksort($datarecord_data);
//print_r($datarecord_data);  exit();

            $line = array();
            $line[] = $external_id;

            foreach ($datarecord_data as $df_id => $data)
                $line[] = $data;
//print_r($line);  exit();


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


            if ( !isset($post['tracked_job_id']) || !isset($post['user_id']) || !isset($post['line']) || !isset($post['datafields']) || !isset($post['random_key']) || !isset($post['api_key']) || !isset($post['delimiter']) )
                throw new ODRBadRequestException();

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


            if ( !isset($post['tracked_job_id']) || !isset($post['final_filename']) || !isset($post['random_keys']) || !isset($post['user_id']) || !isset($post['api_key']) )
                throw new ODRBadRequestException();

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
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$user->hasRole('ROLE_ADMIN') || !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


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
