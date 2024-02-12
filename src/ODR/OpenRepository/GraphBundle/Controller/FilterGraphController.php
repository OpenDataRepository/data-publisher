<?php

/**
 * Open Data Repository Data Publisher
 * FilterGraph Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The FilterGraph plugin is rather convenient for a bunch of smilar files, so somebody got the idea
 * that it should be applicable to generic groups of search results.  As such, this controller exists
 * to gaslight the FilterGraph plugin into working in situations it really shouldn't be put into.
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\OpenRepository\GraphBundle\Plugins\Base\FilterGraphPlugin;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Templating\EngineInterface;


class FilterGraphController extends ODRCustomController
{

    /**
     * TODO
     *
     * @param string $search_key
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function setupAction($search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');


            // ----------------------------------------
            // Check whether the search key is valid first...
            $search_key_service->validateSearchKey($search_key);
            $search_params = $search_key_service->decodeSearchKey($search_key);

            // ...if it's valid, then it'll have a datatype identifier in it
            $dt_id = $search_params['dt_id'];

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($dt_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');

            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                $datatype = $datatype->getGrandparent();
            if ( $datatype->getDeletedAt() !== null )
                throw new ODRNotFoundException('Datatype');

            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // The user is allowed to view the datatype, but the search key may still contain items
            //  they're not allowed to see...
            $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
            if ( $search_key !== $filtered_search_key ) {
                // ...if that's the case, then silently redirect them to a filtered version
                return $search_redirect_service->redirectToFilterGraphSetup($filtered_search_key, $offset);
            }


//            // ----------------------------------------
//            // Unfortunately, need to also check session stuff because of page offset...
//            $params = $request->query->all();
//            $odr_tab_id = '';
//            if ( !isset($params['odr_tab_id']) ) {
//                // If the tab id doesn't exist, then kick them back to a search results page to
//                //  set that up...
//                return $search_redirect_service->redirectToSearchResult($filtered_search_key, 0);
//            }
//
//            // Otherwise, can use the existing tab to get the page length from the user's session
//            $odr_tab_id = $params['odr_tab_id'];
//            $page_length = $odr_tab_service->getPageLength($odr_tab_id);
//
//            // Ignoring the existence of table themes and the "display all records" here to silently
//            //  force a local page length maximum of 100 datarecords
//            if ( $page_length > 100 )
//                $page_length = 100;


            // ----------------------------------------
            // So the entire point of these shennanigans is to "fake" an environment for the FilterGraph
            //  plugin...to make it think it's executing on all the search results described by the
            //  search key.  Therefore, it needs the datatype, datarecord, and theme arrays...

            // For the time being, only allow this to work on datatypes that would execute the Graph
            //  or FilterGraph plugins if they were rendered normally
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getId());    // do need links
            $datarecords = array();
            $permissions_service->filterByGroupPermissions($datatype_array, $datarecords, $user_permissions);

            // Because the renderPluginInstance entry doesn't store all the data about the file field,
            //  need to locate the datafield's entry in the datatype array as well
            $relevant_datafields = self::findRelevantDatafields($datatype_array);
            if ( empty($relevant_datafields) )
                throw new ODRBadRequestException('Not allowed to use this feature when none of the related datatypes use a graph-type plugin');

            /** @var EngineInterface $templating */
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODROpenRepositoryGraphBundle:Base:FilterGraph/something.html.twig',
                    array(
                        'search_key' => $search_key,
                        'relevant_datafields' => $relevant_datafields,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x0fac1f4f;
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
     * Don't want this to run on datatypes that aren't already set up for a graph-type plugin.
     * TODO - need to display this as a tree
     *
     * @param array $datatype_array
     *
     * @return array
     */
    private function findRelevantDatafields($datatype_array)
    {
        $relevant_datafields = array();
        foreach ($datatype_array as $dt_id => $dt) {
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                if ( $plugin_classname === 'odr_plugins.base.graph' || $plugin_classname === 'odr_plugins.base.filter_graph' ) {
                    $primary_graph_file_id = $secondary_graph_file_id = null;

                    // The GraphPlugin has the file fields in renderPluginMap...
                    if ( isset($rpi['renderPluginMap']['Graph File']) )
                        $primary_graph_file_id = $rpi['renderPluginMap']['Graph File']['id'];
                    if ( isset($rpi['renderPluginMap']['Secondary Graph File']) )
                        $secondary_graph_file_id = $rpi['renderPluginMap']['Secondary Graph File']['id'];

                    // ...but the FilterGraphPlugin has them in a different location
                    if ( isset($rpi['renderPluginOptionsMap']['plugin_config']) ) {
                        // The config is stored as a string...three keys separated by commas
                        $config_tmp = explode(',', $rpi['renderPluginOptionsMap']['plugin_config']);

                        // If there aren't three entries, then the config is invalid
                        if ( count($config_tmp) === 3 ) {
                            if ( $config_tmp[1] !== '' && is_numeric($config_tmp[1]) )
                                $primary_graph_file_id = $config_tmp[1];
                            if ( $config_tmp[2] !== '' && is_numeric($config_tmp[2]) )
                                $secondary_graph_file_id = $config_tmp[2];
                        }
                    }

                    // TODO
                    if ( !is_null($primary_graph_file_id) ) {
                        foreach ($datatype_array as $dt_id => $dt) {
                            if ( isset($dt['dataFields'][$primary_graph_file_id]) ) {
                                $relevant_datafields[$primary_graph_file_id] = $dt['dataFields'][$primary_graph_file_id];
                                $relevant_datafields[$primary_graph_file_id]['graph_rpi'] = $rpi;
                                break;
                            }
                        }
                    }
                    if ( !is_null($secondary_graph_file_id) ) {
                        foreach ($datatype_array as $dt_id => $dt) {
                            if ( isset($dt['dataFields'][$secondary_graph_file_id]) ) {
                                $relevant_datafields[$secondary_graph_file_id] = $dt['dataFields'][$secondary_graph_file_id];
                                $relevant_datafields[$secondary_graph_file_id]['graph_rpi'] = $rpi;
                                break;
                            }
                        }
                    }
                }
            }
        }

        return $relevant_datafields;
    }


    /**
     * TODO
     *
     * @param Request $request
     *
     * @return Response
     */
    public function asdfAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            $post = $request->request->all();

            $search_key = $post['search_key'];
            $target = explode('_', $post['target']);
            $target_graph_rpi_id = intval($target[0]);
            $target_df_id = intval($target[1]);


            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->container->get('odr.datarecord_info_service');
            /** @var DatatreeInfoService $datatree_info_service */
            $datatree_info_service = $this->container->get('odr.datatree_info_service');
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

            // ----------------------------------------
            // Check whether the search key is valid first...
            $search_key_service->validateSearchKey($search_key);
            $search_params = $search_key_service->decodeSearchKey($search_key);

            // ...if it's valid, then it'll have a datatype identifier in it
            $dt_id = $search_params['dt_id'];

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($dt_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype;
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                $grandparent_datatype = $datatype->getGrandparent();

            if ( $grandparent_datatype->getDeletedAt() !== null )
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $permissions_service->getUserPermissionsArray($user);
            $is_datatype_admin = $permissions_service->isDatatypeAdmin($user, $grandparent_datatype);

            if ( !$permissions_service->canViewDatatype($user, $grandparent_datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // The user is allowed to view the datatype, but the search key may still contain items
            //  they're not allowed to see...
            $filtered_search_key = $search_api_service->filterSearchKeyForUser($grandparent_datatype, $search_key, $user_permissions);
            if ( $search_key !== $filtered_search_key ) {
                // ...if that's the case, then silently redirect them to a filtered version
                return $search_redirect_service->redirectToFilterGraphSetup($filtered_search_key, 1);
            }


            // ----------------------------------------


            // TODO
            $datatype_array = $database_info_service->getDatatypeArray($grandparent_datatype->getId());    // Do want links...
            // ...but find the requested renderPluginInstance before it gets stacked
            $relevant_datafields = self::findRelevantDatafields($datatype_array);
            if ( empty($relevant_datafields) )
                throw new ODRBadRequestException('Not allowed to use this feature when none of the related datatypes use a graph-type plugin');
            if ( !isset($relevant_datafields[$target_df_id]) )
                throw new ODRBadRequestException('TODO');

            // The datatype to be passed to the plugin should not be wrapped with its id
            $plugin_dt_array = $database_info_service->stackDatatypeArray($datatype_array, $grandparent_datatype->getId());

            // Need to tweak the provided renderPluginInstance array so the FilterGraph plugin
            //  ends up operating on the correct datafield...
            $plugin_rpi_array = $relevant_datafields[$target_df_id]['graph_rpi'];
            self::tweakRenderPluginInstanceArray($plugin_rpi_array, $plugin_dt_array, $target_df_id);

            // ...but also need to replace the top-level datatype's array of renderPluginInstances
            // so that the GraphFilter plugin works properly
            $plugin_rpi_id = $plugin_rpi_array['id'];
            $plugin_dt_array['renderPluginInstances'] = array(
                $plugin_rpi_id => $plugin_rpi_array
            );


            // TODO - get this via the session if possible
            $sort_datafields = array();
            $sort_directions = array();
            $original_datarecord_list = $search_api_service->performSearch(
                $grandparent_datatype,
                $search_key,
                $user_permissions,
                false,  // only want the grandparent datarecord ids that match the search
                $sort_datafields,
                $sort_directions
            );

            // TODO - need to figure out a limit here
            $datarecord_list = array_slice($original_datarecord_list, 0, 100);

            // So, for each datarecord on this page of the search results...
            $related_datarecord_array = array();
            foreach ($datarecord_list as $num => $dr_id) {
                // ...load the list of any datarecords it links to (this always includes $dr_id)...
                $associated_dr_ids = $datatree_info_service->getAssociatedDatarecords($dr_id);

                foreach ($associated_dr_ids as $num => $a_dr_id) {
                    // If this record is going to be displayed, and it hasn't already been loaded...
                    if ( /*isset($acceptable_dr_ids[$a_dr_id]) && */!isset($related_datarecord_array[$a_dr_id]) ) {
                        // ...then load just this record
                        $dr_data = $datarecord_info_service->getDatarecordArray($a_dr_id, false);
                        // ...and save this record and all its children so they can get stacked
                        foreach ($dr_data as $local_dr_id => $data)
                            $related_datarecord_array[$local_dr_id] = $data;
                    }
                }
            }

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $permissions_service->filterByGroupPermissions($datatype_array, $related_datarecord_array, $user_permissions);

            // Only stack the top-level datarecords of this datatype
            $plugin_dr_array = array();
            foreach ($related_datarecord_array as $dr_id => $dr) {
                if ( $dr['dataType']['id'] == $grandparent_datatype->getId() )
                    $plugin_dr_array[$dr_id] = $datarecord_info_service->stackDatarecordArray($related_datarecord_array, $dr_id);
            }

            // Don't care which theme the user is currently using...
            $master_theme = $theme_info_service->getDatatypeMasterTheme($grandparent_datatype->getId());
            $plugin_theme_array = $theme_info_service->getThemeArray($master_theme->getId());
            // ...but it probably should be stacked
            $plugin_theme_array = $theme_info_service->stackThemeArray($plugin_theme_array, $master_theme->getId());




            // ----------------------------------------
            // TODO
            $rendering_options = array(
                'is_top_level' => 1,
                'is_link' => 0,
                'display_type' => ThemeDataType::ACCORDION_HEADER,
                'multiple_allowed' => 1,
                'context' => 'display',
                'is_datatype_admin' => $is_datatype_admin,
            );

            // ----------------------------------------
            // Now that all the setup work is completed, execute the plugin with the (heavily)
            //  modified arrays
            /** @var FilterGraphPlugin $plugin_svc */
            $plugin_svc = $this->container->get('odr_plugins.base.filter_graph');
            $html = $plugin_svc->execute($plugin_dr_array, $plugin_dt_array, $plugin_rpi_array, $plugin_theme_array, $rendering_options);    // the parent datarecord and permissions arrays aren't needed

            $return['d'] = array(
                'html' => $html,
            );

        }
        catch (\Exception $e) {
            $source = 0x42ff5530;
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
     * TODO
     *
     * @param array $rpi_array
     * @param array $dt_array
     * @param integer $target_df_id
     */
    private function tweakRenderPluginInstanceArray(&$rpi_array, $dt_array, $target_df_id)
    {
        // The FilterGraph plugin doesn't use renderPluginMap entries
        $rpi_array['renderPluginMap'] = array();

        // Need to build the prefix so the plugin can properly categorize filter values
        $prefix = self::getPluginPrefix($dt_array, $target_df_id);
        $rpi_array['renderPluginOptionsMap']['plugin_config'] = $prefix.','.$target_df_id.',';

        // TODO
        $filter_config = '';
        $rpi_array['renderPluginOptionsMap']['filter_config'] = $filter_config;
    }


    /**
     * TODO
     *
     * @param array $dt_array
     * @param integer $target_df_id
     *
     * @return string|null
     */
    private function getPluginPrefix($dt_array, $target_df_id)
    {
        $dt_id = $dt_array['id'];
        if ( isset($dt_array['dataFields']) ) {
            foreach ($dt_array['dataFields'] as $df_id => $df) {
                if ( intval($df_id) === $target_df_id )
                    return $dt_id;
            }
        }

        if ( isset($dt_array['descendants']) ) {
            foreach ($dt_array['descendants'] as $child_dt_id => $child_dt_info) {
                $child_dt_array = $child_dt_info['datatype'][$child_dt_id];
                $ret = self::getPluginPrefix($child_dt_array, $target_df_id);
                if ( !is_null($ret) )
                    return $dt_id.'_'.$ret;
            }
        }

        return null;
    }
}
