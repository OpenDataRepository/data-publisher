<?php

/**
 * Open Data Repository Data Publisher
 * Default Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Default controller handles the loading of the base template
 * and AJAX handlers that the rest of the site uses.  It also
 * handles the creation of the information displayed on the site's
 * dashboard.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class DefaultController extends ODRCustomController
{

    /**
     * Triggers the loading of base.html.twig, and sets up session cookies.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function indexAction(Request $request)
    {

        try {
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Grab the current user
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Render the base html for the page...$this->render() apparently creates and automatically returns a full Reponse object
            $html = $this->renderView(
                'ODRAdminBundle:Default:index.html.twig',
                array(
                    'user' => $user,
                    'user_permissions' => $datatype_permissions,
                    'site_baseurl' => $this->container->getParameter('site_baseurl')
                )
            );

            $response = new Response($html);
            $response->headers->set('Content-Type', 'text/html');
            return $response;
        }
        catch (\Exception $e) {
            $source = 0xe75008d8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Loads the dashboard blurbs about the most populous datatypes on the site.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function dashboardAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // ----------------------------------------
//            // Ensure user has correct set of permissions, since this is immediately called after login...
//            /** @var \Doctrine\ORM\EntityManager $em */
//            $em = $this->getDoctrine()->getManager();
//
//            /** @var CacheService $cache_service */
//            $cache_service = $this->container->get('odr.cache_service');
//            /** @var DatatypeInfoService $dti_service */
//            $dti_service = $this->container->get('odr.datatype_info_service');
//            /** @var PermissionsManagementService $pm_service */
//            $pm_service = $this->container->get('odr.permissions_management_service');
//
//
//            /** @var User $user */
//            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
//            $datatype_permissions = $pm_service->getDatatypePermissions($user);
//
//
//            // ----------------------------------------
//            // Only want to create dashboard html graphs for top-level datatypes...
//            $datatypes = $dti_service->getTopLevelDatatypes();
//
//            $dashboard_order = array();
//            $dashboard_headers = array();
//            $dashboard_graphs = array();
//            foreach ($datatypes as $num => $datatype_id) {
//                // "Manually" finding permissions to avoid having to load each datatype from doctrine
//                $can_view_datatype = false;
//                if ( isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dt_view' ]) )
//                    $can_view_datatype = true;
//
//                $can_view_datarecord = false;
//                if ( isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dr_view' ]) )
//                    $can_view_datarecord = true;
//
//                // Determine whether this datatype is public
//                $include_links = false;
//                $datatype_data = $dti_service->getDatatypeArray($datatype_id, $include_links);
//
//                $public_date  = $datatype_data[$datatype_id]['dataTypeMeta']['publicDate']
//                    ->format('Y-m-d H:i:s');
//
//
//                // Don't display if the datatype isn't public and the user doesn't have permission to view it
//                if ($public_date == '2200-01-01 00:00:00' && !$can_view_datatype)
//                    continue;
//
//                // Also don't display on dashboard if this is a "master template" datatype
//                if ($datatype_data[$datatype_id]['is_master_type'] == 1)
//                    continue;
//
//
//                // Attempt to load existing cache entry for this datatype's dashboard html
//                $cache_entry = 'dashboard_'.$datatype_id;
//                if (!$can_view_datarecord)
//                    $cache_entry .= '_public_only';
//
//                $data = $cache_service->get($cache_entry);
//                if ($data == false) {
//                    // Rebuild the cached entry if it doesn't exist
//                    self::getDashboardHTML($em, $datatype_id);
//
//                    // Cache entry should now exist, reload it
//                    $data = $cache_service->get($cache_entry);
//                }
//
//                $total = $data['total'];
//                $header = $data['header'];
//                $graph = $data['graph'];
//
//                $dashboard_order[$datatype_id] = $total;
//                $dashboard_headers[$datatype_id] = $header;
//                $dashboard_graphs[$datatype_id] = $graph;
//            }
//
//            // Sort by number of datarecords
//            arsort($dashboard_order);
//
//            $header_str = '';
//            $graph_str = '';
//            $count = 0;
//            foreach ($dashboard_order as $datatype_id => $total) {
//                // Only display the top 9 datatypes with the most datarecords
//                $count++;
//                if ($count > 9)
//                    continue;
//
//                $header_str .= $dashboard_headers[$datatype_id];
//                $graph_str .= $dashboard_graphs[$datatype_id];
//            }
//
//            // Finally, render the main dashboard page
//            $templating = $this->get('templating');
//            $html = $templating->render(
//                'ODRAdminBundle:Default:dashboard.html.twig',
//                array(
//                    'dashboard_headers' => $header_str,
//                    'dashboard_graphs' => $graph_str,
//                )
//            );

            $html = "<h2>DASHBOARD HERE</h2>";
            $return['d'] = array('html' => $html);

        }
        catch (\Exception $e) {
            $source = 0x4406ae1a;
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
     * Recalculates the dashboard blurb for a specified datatype.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $datatype_id             Which datatype is having its dashboard blurb rebuilt.
     */
    private function getDashboardHTML($em, $datatype_id)
    {
        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        $datatype_name = $datatype->getShortName();

        // Temporarily disable the code that prevents the following query from returning deleted rows
        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT dr.id AS datarecord_id, dr.created AS created, dr.deletedAt AS deleted, dr.updated AS updated, drm.publicDate AS public_date
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
            JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
            WHERE dr.dataType = :datatype AND dr.provisioned = false
            AND drm.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('datatype' => $datatype_id) );
        $results = $query->getArrayResult();
        $em->getFilters()->enable('softdeleteable');    // Re-enable it


        // Build the array of date objects so datarecords created/deleted in the past 6 weeks can be counted
        $cutoff_dates = array();
        for ($i = 1; $i < 7; $i++) {
            $tmp_date = new \DateTime();
            $str = 'P'.($i*7).'D';

            $cutoff_dates[($i-1)] = $tmp_date->sub(new \DateInterval($str));
        }

        $total_datarecords = 0;
        $total_public_datarecords = 0;

        // Initialize the created/updated date arrays
        $tmp = array(
            'created' => array(),
            'updated' => array(),
        );
        for ($i = 0; $i < 6; $i++) {
            $tmp['created'][$i] = 0;
            $tmp['updated'][$i] = 0;
        }
        // Works since php arrays are assigned via copy
        $values = $tmp;
        $public_values = $tmp;


        // Classify each datarecord of this datatype
        foreach ($results as $num => $dr) {
            $create_date = $dr['created'];
            $delete_date = $dr['deleted'];
            if ($delete_date == '')
                $delete_date = null;
            $modify_date = $dr['updated'];
            $public_date = $dr['public_date']->format('Y-m-d H:i:s');

            // Determine whether the datarecord is public or not
            $is_public = true;
            if ($public_date == '2200-01-01 00:00:00')
                $is_public = false;

            // Don't count deleted datarecords towards the total number of datarecords for this datatype
            if ($delete_date == null) {
                $total_datarecords++;

                if ($is_public)
                    $total_public_datarecords++;
            }

            // If this datarecord was created in the past 6 weeks, store which week it was created in
            for ($i = 0; $i < 6; $i++) {
                if ($create_date > $cutoff_dates[$i]) {
                    $values['created'][$i]++;

                    if ($is_public)
                        $public_values['created'][$i]++;

                    break;
                }
            }

            // If this datarecord was deleted in the past 6 weeks, store which week it was deleted in
            if ($delete_date != null) {
                for ($i = 0; $i < 6; $i++) {
                    if ($delete_date > $cutoff_dates[$i]) {
                        $values['created'][$i]--;

                        if ($is_public)
                            $public_values['created'][$i]--;

                        break;
                    }
                }
            }

            // If this datarecord was deleted in the past 6 weeks, store which week it was deleted in
            if ($delete_date == null) {
                for ($i = 0; $i < 6; $i++) {
                    if ($modify_date > $cutoff_dates[$i]) {
                        $values['updated'][$i]++;

                        if ($is_public)
                            $public_values['updated'][$i]++;

                        break;
                    }
                }
            }
        }

//print $datatype_name."\n";
//print_r($values);

        // Calculate the total added/deleted since six weeks ago
        $total_created = 0;
        $total_public_created = 0;
        $total_updated = 0;
        $total_public_updated = 0;

        for ($i = 0; $i < 6; $i++) {
            $total_created += $values['created'][$i];
            $total_updated += $values['updated'][$i];

            $total_public_created += $public_values['created'][$i];
            $total_public_updated += $public_values['updated'][$i];
        }

        $value_str = $values['created'][5].':'.$values['updated'][5];
        $public_value_str = $public_values['created'][5].':'.$public_values['updated'][5];
        for ($i = 4; $i >= 0; $i--) {
            $value_str .= ','.$values['created'][$i].':'.$values['updated'][$i];
            $public_value_str .= ','.$public_values['created'][$i].':'.$public_values['updated'][$i];
        }

        $created_str = '';
        $public_created_str = '';
        if ( $total_created < 0 ) {
            $created_str = abs($total_created).' deleted';
            $public_created_str = abs($total_public_created).' deleted';
        }
        else {
            $created_str = $total_created.' created';
            $public_created_str = $total_public_created.' created';
        }

        $updated_str = $total_updated.' modified';
        $public_updated_str = $total_public_updated.' modified';


        // Render the actual html
        $templating = $this->get('templating');
        $header = $templating->render(
            'ODRAdminBundle:Default:dashboard_header.html.twig',
            array(
                'search_slug' => $datatype->getSearchSlug(),
                'datatype_id' => $datatype_id,
                'total_datarecords' => $total_datarecords,
                'datatype_name' => $datatype_name,
            )
        );
        $public_header = $templating->render(
            'ODRAdminBundle:Default:dashboard_header.html.twig',
            array(
                'search_slug' => $datatype->getSearchSlug(),
                'datatype_id' => $datatype_id,
                'total_datarecords' => $total_public_datarecords,
                'datatype_name' => $datatype_name,
            )
        );

        $graph = $templating->render(
            'ODRAdminBundle:Default:dashboard_graph.html.twig',
            array(
                'datatype_name' => $datatype_name,
                'created_str' => $created_str,
                'updated_str' => $updated_str,
                'value_str' => $value_str,
            )
        );
        $public_graph = $templating->render(
            'ODRAdminBundle:Default:dashboard_graph.html.twig',
            array(
                'datatype_name' => $datatype_name,
                'created_str' => $public_created_str,
                'updated_str' => $public_updated_str,
                'value_str' => $public_value_str,
            )
        );

        $data = array(
            'total' => $total_datarecords,
            'header' => $header,
            'graph' => $graph,
        );
        $public_data = array(
            'total' => $total_public_datarecords,
            'header' => $public_header,
            'graph' => $public_graph,
        );

        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');

        // Store the dashboard data for all datarecords of this datatype
        $cache_service->set('dashboard_'.$datatype_id, $data);
        $cache_service->expire('dashboard_'.$datatype_id, 1*24*60*60);    // Cache this dashboard entry for upwards of one day

        // Store the dashboard data for all public datarecords of this datatype
        $cache_service->set('dashboard_'.$datatype_id.'_public_only', $public_data);
        $cache_service->expire('dashboard_'.$datatype_id.'_public_only', 1*24*60*60);    // Cache this dashboard entry for upwards of one day
    }

}
