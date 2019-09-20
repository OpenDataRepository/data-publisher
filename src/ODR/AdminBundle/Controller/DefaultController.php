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
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Doctrine\DBAL\Connection as DBALConnection;
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);


            // Render the base html for the page...$this->render() apparently creates and automatically returns a full Reponse object
            $html = $this->renderView(
                'ODRAdminBundle:Default:index.html.twig',
                array(
                    'user' => $user,
                    'user_permissions' => $datatype_permissions,
                )
            );

            $response = new Response($html);
            $response->headers->set('Content-Type', 'text/html');
            return $response;
        }
        catch (\Exception $e) {
            $source = 0xe75008d8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
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
            // Ensure user has correct set of permissions, since this is immediately called after login...
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            $templating = $this->get('templating');

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = $pm_service->getDatatypePermissions($user);


            // ----------------------------------------
            // Going to use native SQL queries for this...
            $conn = $em->getConnection();

            // Only want to create dashboard html graphs for top-level datatypes...
            $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();

            // Get the public dates for the top-level datatypes...
            $query_str =
               'SELECT dt.id, dtm.public_date, dtm.short_name, dtm.search_slug
                FROM odr_data_type AS dt
                JOIN odr_data_type_meta AS dtm ON dtm.data_type_id = dt.id
                WHERE dt.is_master_type = 0 AND dt.id IN (?)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL';
            $parameters = array(1 => $top_level_datatype_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $result = $conn->executeQuery($query_str, $parameters, $types);
            $results = $result->fetchAll();

            $filtered_datatype_ids = array();
            foreach ($results as $num => $dt) {
                $dt_id = $dt['id'];
                $public_date = $dt['public_date'];

                // "Manually" finding permissions to avoid having to load each datatype from doctrine
                $can_view_datatype = false;
                if (isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_view']))
                    $can_view_datatype = true;

                // Save the id if the datatype is public or the user has permissions to view it
                if ($public_date !== '2200-01-01 00:00:00' || $can_view_datatype) {    // TODO - non-public shouldn't be a single date
                    $filtered_datatype_ids[$dt_id] = array(
                        'dt_name' => $dt['short_name'],
                        'search_slug' => $dt['search_slug']
                    );
                }
            }


            // ----------------------------------------
            // Determine the nine datatypes with the most datarecords...intentionally ignoring
            //  whether the user can see them or not
            $query_str =
               'SELECT dr.data_type_id AS dt_id, COUNT(*) AS dr_count
                FROM odr_data_record dr
                WHERE dr.data_type_id IN (?)
                AND dr.deletedAt IS NULL
                GROUP BY dr.data_type_id
                ORDER BY dr_count DESC
                LIMIT 0,9';
            $parameters = array(1 => array_keys($filtered_datatype_ids));
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $result = $conn->executeQuery($query_str, $parameters, $types);
            $results = $result->fetchAll();

            $datatype_counts = array();
            foreach ($results as $num => $dt) {
                $dt_id = $dt['dt_id'];
                $count = $dt['dr_count'];

                $datatype_counts[$dt_id] = $count;
            }

            // Load or generate the HTML for the headers for these nine datatypes
            $header_str = '';
            foreach ($datatype_counts as $dt_id => $count) {
                $header_str .= $templating->render(
                    'ODRAdminBundle:Default:dashboard_header.html.twig',
                    array(
                        'search_slug' => $filtered_datatype_ids[$dt_id]['search_slug'],
                        'datatype_id' => $dt_id,
                        'total_datarecords' => $count,
                        'datatype_name' => $filtered_datatype_ids[$dt_id]['dt_name'],
                    )
                );
            }


            // ----------------------------------------
            // Determine the nine datatypes with the most datarecords that were modified in the past
            //  six weeks...still intentionally ignoring whether the user can see them or not
            $query_str =
               'SELECT dr.data_type_id AS dt_id, COUNT(*) AS dr_count
                FROM odr_data_record dr
                WHERE dr.data_type_id IN (?) AND dr.updated > DATE_SUB(NOW(), INTERVAL 6*7 DAY)
                AND dr.deletedAt IS NULL
                GROUP BY dr.data_type_id
                ORDER BY dr_count DESC
                LIMIT 0,9';
            $parameters = array(1 => array_keys($filtered_datatype_ids));
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $result = $conn->executeQuery($query_str, $parameters, $types);
            $results = $result->fetchAll();

            $updated_counts = array();
            foreach ($results as $num => $dt) {
                $dt_id = $dt['dt_id'];
//                $count = $dt['dr_count'];

                $updated_counts[$dt_id] = $filtered_datatype_ids[$dt_id]['dt_name'];
            }

            // Load or generate each of the blurbs
            $graph_str = self::getDashboardBlurb($updated_counts, $conn);


            // ----------------------------------------
            // Finally, render the main dashboard page
            $templating = $this->get('templating');
            $html = $templating->render(
                'ODRAdminBundle:Default:dashboard.html.twig',
                array(
                    'dashboard_headers' => $header_str,
                    'dashboard_graphs' => $graph_str,
                )
            );

            $return['d'] = array('html' => $html);
        }
        catch (\Exception $e) {
            $source = 0x4406ae1a;
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
     * Recalculates the dashboard blurb for a specified datatype.  Caching barely speeds this up.
     *
     * @param array $datatype_ids
     * @param DBALConnection $conn
     *
     * @return string
     */
    private function getDashboardBlurb($datatype_ids, $conn)
    {
        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');
        $templating = $this->get('templating');

        $graph_str = '';
        foreach ($datatype_ids as $dt_id => $count) {
            $str = $cache_service->get('dashboard_'.$dt_id);
            if ( $str === false || $str === ''  ) {
                // Going to need to run queries to figure out these values...
                $created = array();
                $total_created = 0;
                $updated = array();
                $total_updated = 0;

                for ($i = 1; $i <= 6; $i++) {
                    // Created...
                    $query_str =
                       'SELECT COUNT(*) AS dr_count
                        FROM odr_data_record dr
                        WHERE dr.data_type_id = '.$dt_id.'
                        AND dr.created >= DATE_SUB(NOW(), INTERVAL '.($i).'*7 DAY)
                        AND dr.created < DATE_SUB(NOW(), INTERVAL '.($i-1).'*7 DAY)
                        AND dr.deletedAt IS NULL';

                    $result = $conn->executeQuery($query_str);
                    $results = $result->fetchAll();

                    $num = $results[0]['dr_count'];
                    $total_created += $num;
                    $created[] = $num;

                    // Updated...
                    $query_str =
                       'SELECT COUNT(*) AS dr_count
                        FROM odr_data_record dr
                        WHERE dr.data_type_id = '.$dt_id.'
                        AND dr.updated >= DATE_SUB(NOW(), INTERVAL '.($i).'*7 DAY)
                        AND dr.updated < DATE_SUB(NOW(), INTERVAL '.($i-1).'*7 DAY)
                        AND dr.deletedAt IS NULL';

                    $result = $conn->executeQuery($query_str);
                    $results = $result->fetchAll();

                    $num = $results[0]['dr_count'];
                    $total_updated += $num;
                    $updated[] = $num;
                }

                $created_str = $total_created.' created';
                $updated_str = $total_updated.' modified';

                $value_str = '';
                for ($i = 5; $i >= 0; $i--)
                    $value_str .= $created[$i].':'.$updated[$i].',';
                $value_str = substr($value_str, 0, -1);

                $graph = $templating->render(
                    'ODRAdminBundle:Default:dashboard_graph.html.twig',
                    array(
                        'datatype_name' => $datatype_ids[$dt_id],
                        'created_str' => $created_str,
                        'updated_str' => $updated_str,
                        'value_str' => $value_str,
                    )
                );

                $cache_service->set('dashboard_'.$dt_id, $graph);
                $cache_service->expire('dashboard_'.$dt_id, 1*24*60*60);    // Cache this dashboard entry for upwards of one day

                $graph_str .= $graph;
            }
            else {
                $graph_str .= $str;
            }
        }

        return $graph_str;
    }
}
