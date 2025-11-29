<?php

/**
 * Open Data Repository Data Publisher
 * Statistics Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller handles API endpoints for logging and retrieving
 * statistics about datarecord views and file downloads.
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\StatisticsDaily;
use ODR\AdminBundle\Entity\StatisticsHourly;
use ODR\AdminBundle\Entity\StatisticsBotList;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\StatisticsService;
// Symfony
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StatisticsController extends ODRCustomController
{
    /**
     * JavaScript endpoint to log datarecord views
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function logViewAction(Request $request)
    {
        try {
            // Get request data
            $post = $request->request->all();

            if (!isset($post['datarecord_id'])) {
                throw new ODRBadRequestException('Missing datarecord_id');
            }

            $datarecord_id = intval($post['datarecord_id']);
            $is_search_result = isset($post['is_search_result']) ? (bool)$post['is_search_result'] : false;

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if (!$datarecord) {
                throw new ODRNotFoundException('Datarecord not found');
            }

            // Get datatype
            $datatype = $datarecord->getDataType();
            if (!$datatype) {
                throw new ODRNotFoundException('Datatype not found');
            }

            // Get user (may be anonymous)
            $user = null;
            $token = $this->container->get('security.token_storage')->getToken();
            if ($token && $token->getUser() instanceof ODRUser) {
                $user = $token->getUser();
            }

            // Get IP address and user agent
            $ip_address = $request->getClientIp();
            $user_agent = $request->headers->get('User-Agent', 'Unknown');

            // Log the view
            /** @var StatisticsService $statistics_service */
            $statistics_service = $this->container->get('odr.statistics_service');
            $statistics_service->logRecordView(
                $datarecord_id,
                $datatype->getId(),
                $user,
                $ip_address,
                $user_agent,
                $is_search_result
            );

            return new JsonResponse(array('success' => true));

        } catch (\Exception $e) {
            $source = 0xd8e7e394;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Log a file download
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function logDownloadAction(Request $request)
    {
        try {
            // Get request data
            $post = $request->request->all();

            if (!isset($post['file_id'])) {
                throw new ODRBadRequestException('Missing file_id');
            }

            $file_id = intval($post['file_id']);

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if (!$file) {
                throw new ODRNotFoundException('File not found');
            }

            // Get datatype from file's datafield
            $datafield = $file->getDataField();
            if (!$datafield) {
                throw new ODRNotFoundException('Datafield not found');
            }

            $datatype = $datafield->getDataType();
            if (!$datatype) {
                throw new ODRNotFoundException('Datatype not found');
            }

            // Get user (may be anonymous)
            $user = null;
            $token = $this->container->get('security.token_storage')->getToken();
            if ($token && $token->getUser() instanceof ODRUser) {
                $user = $token->getUser();
            }

            // Get IP address and user agent
            $ip_address = $request->getClientIp();
            $user_agent = $request->headers->get('User-Agent', 'Unknown');

            // Log the download
            /** @var StatisticsService $statistics_service */
            $statistics_service = $this->container->get('odr.statistics_service');
            $statistics_service->logFileDownload(
                $file_id,
                $datatype->getId(),
                $user,
                $ip_address,
                $user_agent
            );

            return new JsonResponse(array('success' => true));

        } catch (\Exception $e) {
            $source = 0x7f6a3b82;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Get statistics for a datatype
     *
     * @param Request $request
     * @param int $datatype_id
     *
     * @return JsonResponse
     */
    public function getDatatypeStatsAction(Request $request, $datatype_id)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if (!$datatype) {
                throw new ODRNotFoundException('Datatype not found');
            }

            // Check permissions - must be datatype admin
            if (!$pm_service->isDatatypeAdmin($user, $datatype)) {
                throw new ODRForbiddenException('Not authorized to view statistics for this datatype');
            }

            // Get query parameters
            $start_date_str = $request->query->get('start_date');
            $end_date_str = $request->query->get('end_date');
            $include_bots = $request->query->get('include_bots', 'false') === 'true';
            $granularity = $request->query->get('granularity', 'daily');

            // Parse dates
            $start_date = $start_date_str ? new \DateTime($start_date_str) : (new \DateTime())->modify('-30 days');
            $end_date = $end_date_str ? new \DateTime($end_date_str) : new \DateTime();

            // Get statistics
            /** @var StatisticsService $statistics_service */
            $statistics_service = $this->container->get('odr.statistics_service');
            $stats = $statistics_service->getStatisticsByDatatype(
                $datatype_id,
                $start_date,
                $end_date,
                $include_bots,
                $granularity
            );

            return new JsonResponse(array(
                'success' => true,
                'statistics' => $stats
            ));

        } catch (\Exception $e) {
            $source = 0xa4c2d9f1;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Get statistics for a datarecord
     *
     * @param Request $request
     * @param int $datarecord_id
     *
     * @return JsonResponse
     */
    public function getDatarecordStatsAction(Request $request, $datarecord_id)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if (!$datarecord) {
                throw new ODRNotFoundException('Datarecord not found');
            }

            $datatype = $datarecord->getDataType();

            // Check permissions - must be datatype admin
            if (!$pm_service->isDatatypeAdmin($user, $datatype)) {
                throw new ODRForbiddenException('Not authorized to view statistics for this datarecord');
            }

            // Get query parameters
            $start_date_str = $request->query->get('start_date');
            $end_date_str = $request->query->get('end_date');
            $include_bots = $request->query->get('include_bots', 'false') === 'true';

            // Parse dates
            $start_date = $start_date_str ? new \DateTime($start_date_str) : (new \DateTime())->modify('-30 days');
            $end_date = $end_date_str ? new \DateTime($end_date_str) : new \DateTime();

            // Get statistics
            /** @var StatisticsService $statistics_service */
            $statistics_service = $this->container->get('odr.statistics_service');
            $stats = $statistics_service->getStatisticsByRecord(
                $datarecord_id,
                $start_date,
                $end_date,
                $include_bots
            );

            return new JsonResponse(array(
                'success' => true,
                'statistics' => $stats
            ));

        } catch (\Exception $e) {
            $source = 0xb8f3a615;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Get geographic statistics
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getGeographicStatsAction(Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Get query parameters
            $datatype_id = $request->query->get('datatype_id');
            $start_date_str = $request->query->get('start_date');
            $end_date_str = $request->query->get('end_date');
            $include_bots = $request->query->get('include_bots', 'false') === 'true';

            // If datatype_id specified, check permissions
            if ($datatype_id) {
                /** @var DataType $datatype */
                $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
                if (!$datatype) {
                    throw new ODRNotFoundException('Datatype not found');
                }

                // Check permissions - must be datatype admin
                if (!$pm_service->isDatatypeAdmin($user, $datatype)) {
                    throw new ODRForbiddenException('Not authorized to view statistics for this datatype');
                }
            } else {
                // Global geographic stats - must be super admin
                if (!$user->hasRole('ROLE_SUPER_ADMIN')) {
                    throw new ODRForbiddenException('Super admin permission required for global statistics');
                }
            }

            // Parse dates
            $start_date = $start_date_str ? new \DateTime($start_date_str) : (new \DateTime())->modify('-30 days');
            $end_date = $end_date_str ? new \DateTime($end_date_str) : new \DateTime();

            // Get statistics
            /** @var StatisticsService $statistics_service */
            $statistics_service = $this->container->get('odr.statistics_service');
            $stats = $statistics_service->getGeographicStats(
                $datatype_id,
                $start_date,
                $end_date,
                $include_bots
            );

            return new JsonResponse(array(
                'success' => true,
                'geographic_stats' => $stats
            ));

        } catch (\Exception $e) {
            $source = 0xc7d4e928;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Get dashboard statistics HTML widget
     *
     * @param Request $request
     * @param int $datatype_id
     *
     * @return Response
     */
    public function getDashboardAction(Request $request, $datatype_id)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if (!$datatype) {
                throw new ODRNotFoundException('Datatype not found');
            }

            // Check permissions - must be datatype admin
            if (!$pm_service->isDatatypeAdmin($user, $datatype)) {
                throw new ODRForbiddenException('Not authorized to view statistics for this datatype');
            }

            // Get query parameters
            $days = intval($request->query->get('days', 30));

            // Get dashboard statistics
            /** @var StatisticsService $statistics_service */
            $statistics_service = $this->container->get('odr.statistics_service');
            $stats = $statistics_service->getDashboardStats($datatype_id, $days);

            // Render template
            $html = $this->renderView(
                'ODRAdminBundle:Statistics:dashboard.html.twig',
                array(
                    'datatype' => $datatype,
                    'stats' => $stats,
                    'user' => $user
                )
            );

            $response = new Response($html);
            $response->headers->set('Content-Type', 'text/html');
            return $response;

        } catch (\Exception $e) {
            $source = 0xd9e5f037;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Get overall summary (super admin only)
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getSummaryAction(Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Check permissions - must be super admin
            if (!$user->hasRole('ROLE_SUPER_ADMIN')) {
                throw new ODRForbiddenException('Super admin permission required');
            }

            // Get query parameters
            $start_date_str = $request->query->get('start_date');
            $end_date_str = $request->query->get('end_date');
            $include_bots = $request->query->get('include_bots', '0') === '1';
            $datatype_ids_str = $request->query->get('datatype_ids', '');

            // Parse dates
            $start_date = $start_date_str ? new \DateTime($start_date_str) : (new \DateTime())->modify('-30 days');
            $end_date = $end_date_str ? new \DateTime($end_date_str) : new \DateTime();

            // Parse datatype IDs filter
            $datatype_ids = array();
            if (!empty($datatype_ids_str)) {
                $datatype_ids = array_map('intval', explode(',', $datatype_ids_str));
            }

            // Build query for detailed statistics
            $qb = $em->createQueryBuilder();
            $qb->select('s')
                ->from('ODRAdminBundle:StatisticsDaily', 's')
                ->where('s.dayDate >= :start_date')
                ->andWhere('s.dayDate <= :end_date')
                ->setParameter('start_date', $start_date)
                ->setParameter('end_date', $end_date);

            if (!$include_bots) {
                $qb->andWhere('s.isBot = :is_bot')
                    ->setParameter('is_bot', false);
            }

            if (!empty($datatype_ids)) {
                $qb->andWhere('s.dataType IN (:datatype_ids)')
                    ->setParameter('datatype_ids', $datatype_ids);
            }

            /** @var StatisticsDaily[] $stats */
            $stats = $qb->getQuery()->getResult();

            // Aggregate data
            $total_views = 0;
            $total_downloads = 0;
            $search_result_views = 0;
            $timeline = array();
            $geographic = array();
            $bot_stats = array(
                'human_views' => 0,
                'human_downloads' => 0,
                'bot_views' => 0,
                'bot_downloads' => 0
            );
            $by_datatype = array();
            $countries = array();

            foreach ($stats as $stat) {
                $views = $stat->getViewCount();
                $downloads = $stat->getDownloadCount();
                $search_views = $stat->getSearchResultViewCount();
                $date = $stat->getDayDate()->format('Y-m-d');
                $country = $stat->getCountry();
                $is_bot = $stat->getIsBot();
                $dt = $stat->getDataType();
                $dt_id = $dt ? $dt->getId() : 0;

                // Totals
                $total_views += $views;
                $total_downloads += $downloads;
                $search_result_views += $search_views;

                // Timeline
                if (!isset($timeline[$date])) {
                    $timeline[$date] = array('date' => $date, 'view_count' => 0, 'download_count' => 0);
                }
                $timeline[$date]['view_count'] += $views;
                $timeline[$date]['download_count'] += $downloads;

                // Geographic
                if ($country) {
                    if (!isset($geographic[$country])) {
                        $geographic[$country] = array('view_count' => 0, 'download_count' => 0);
                        $countries[$country] = true;
                    }
                    $geographic[$country]['view_count'] += $views;
                    $geographic[$country]['download_count'] += $downloads;
                }

                // Bot stats
                if ($is_bot) {
                    $bot_stats['bot_views'] += $views;
                    $bot_stats['bot_downloads'] += $downloads;
                } else {
                    $bot_stats['human_views'] += $views;
                    $bot_stats['human_downloads'] += $downloads;
                }

                // By datatype
                if ($dt_id > 0) {
                    if (!isset($by_datatype[$dt_id])) {
                        $by_datatype[$dt_id] = array('view_count' => 0, 'download_count' => 0);
                    }
                    $by_datatype[$dt_id]['view_count'] += $views;
                    $by_datatype[$dt_id]['download_count'] += $downloads;
                }
            }

            // Sort timeline by date
            ksort($timeline);

            return new JsonResponse(array(
                'success' => true,
                'total_views' => $total_views,
                'total_downloads' => $total_downloads,
                'search_result_views' => $search_result_views,
                'unique_countries' => count($countries),
                'timeline' => array_values($timeline),
                'geographic' => $geographic,
                'bot_stats' => $bot_stats,
                'by_datatype' => $by_datatype
            ));

        } catch (\Exception $e) {
            $source = 0xe0f6a146;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * Display admin statistics dashboard
     *
     * @param Request $request
     *
     * @return Response
     */
    public function adminDashboardAction(Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Check permissions - must be super admin
            if (!$user->hasRole('ROLE_SUPER_ADMIN')) {
                throw new ODRForbiddenException('Super admin permission required');
            }

            // Get all top-level datatypes that have statistics data
            // First, get distinct datatype IDs from the statistics tables
            $datatypeIdsWithStats = $em->createQueryBuilder()
                ->select('DISTINCT IDENTITY(s.dataType) as dt_id')
                ->from('ODRAdminBundle:StatisticsDaily', 's')
                ->where('s.dataType IS NOT NULL')
                ->andWhere('s.deletedAt IS NULL')
                ->getQuery()
                ->getArrayResult();

            $datatypeIds = array_map(function($row) {
                return $row['dt_id'];
            }, $datatypeIdsWithStats);

            // Now get the datatypes with their metadata
            $datatypes = array();
            if (!empty($datatypeIds)) {
                $qb = $em->createQueryBuilder();
                $qb->select('dt')
                    ->from('ODRAdminBundle:DataType', 'dt')
                    ->join('dt.dataTypeMeta', 'dtm')
                    ->where('dt.id IN (:ids)')
                    ->andWhere('dt.deletedAt IS NULL')
                    ->andWhere('dtm.deletedAt IS NULL')
                    ->setParameter('ids', $datatypeIds)
                    ->orderBy('dtm.shortName', 'ASC');

                $datatypes = $qb->getQuery()->getResult();
            }

            // Convert to array for template
            $datatypes_array = array();
            foreach ($datatypes as $dt) {
                $datatypes_array[] = array(
                    'id' => $dt->getId(),
                    'shortName' => $dt->getShortName()
                );
            }

            // Get URL parameters for WordPress integration
            $site_baseurl = $this->container->getParameter('site_baseurl');
            $wordpress_site_baseurl = $this->container->getParameter('wordpress_site_baseurl');

            // Render template
            $html = $this->renderView(
                'ODRAdminBundle:Statistics:dashboard.html.twig',
                array(
                    'datatypes' => $datatypes_array,
                    'site_baseurl' => $site_baseurl,
                    'wordpress_site_baseurl' => $wordpress_site_baseurl
                )
            );

            $response = new Response($html);
            $response->headers->set('Content-Type', 'text/html');
            return $response;

        } catch (\Exception $e) {
            $source = 0xb3f8c421;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Store aggregated hourly statistics (called by Node.js background service)
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function storeHourlyAction(Request $request)
    {
        try {
            // Handle JSON input from Node.js background service
            $content = $request->getContent();
            if (!empty($content)) {
                $post = json_decode($content, true);
            } else {
                $post = $request->request->all();
            }

            if (!isset($post['statistics']) || !is_array($post['statistics'])) {
                throw new ODRBadRequestException('Missing or invalid statistics array');
            }

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Get the system user for created_by/updated_by
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->findOneBy(array('id' => 1));
            if (!$user) {
                // Fallback - try to get current user or use ID 1
                $token = $this->container->get('security.token_storage')->getToken();
                if ($token && $token->getUser() instanceof ODRUser) {
                    $user = $token->getUser();
                }
            }

            $statistics = $post['statistics'];
            $stored_count = 0;

            foreach ($statistics as $stat) {
                // Create new StatisticsHourly entity
                $hourly = new StatisticsHourly();

                // Set timestamp
                $hourly->setHourTimestamp(new \DateTime('@' . $stat['hour_timestamp']));

                // Set counts
                $hourly->setViewCount($stat['view_count']);
                $hourly->setDownloadCount($stat['download_count']);
                $hourly->setSearchResultViewCount($stat['search_result_view_count']);

                // Set geography
                $hourly->setCountry($stat['country']);
                $hourly->setProvince($stat['province']);

                // Set bot flag
                $hourly->setIsBot($stat['is_bot']);

                // Set relationships
                if (isset($stat['datatype_id']) && $stat['datatype_id']) {
                    $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($stat['datatype_id']);
                    if ($datatype) {
                        $hourly->setDataType($datatype);
                    }
                }

                if (isset($stat['datarecord_id']) && $stat['datarecord_id']) {
                    $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($stat['datarecord_id']);
                    if ($datarecord) {
                        $hourly->setDataRecord($datarecord);
                    }
                }

                if (isset($stat['file_id']) && $stat['file_id']) {
                    $file = $em->getRepository('ODRAdminBundle:File')->find($stat['file_id']);
                    if ($file) {
                        $hourly->setFile($file);
                    }
                }

                // Set user tracking
                $hourly->setCreatedBy($user);
                $hourly->setUpdatedBy($user);

                $em->persist($hourly);
                $stored_count++;
            }

            $em->flush();

            return new JsonResponse(array(
                'success' => true,
                'count' => $stored_count
            ));

        } catch (\Exception $e) {
            $source = 0xf1a7b259;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Get bot patterns (called by Node.js background service)
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getBotsAction(Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Get all active bot patterns
            $bots = $em->getRepository('ODRAdminBundle:StatisticsBotList')
                ->createQueryBuilder('b')
                ->where('b.isActive = true')
                ->andWhere('b.deletedAt IS NULL')
                ->getQuery()
                ->getResult();

            $patterns = array();
            foreach ($bots as $bot) {
                $patterns[] = $bot->getPattern();
            }

            return new JsonResponse(array(
                'success' => true,
                'bots' => $patterns
            ));

        } catch (\Exception $e) {
            $source = 0xa2b8c36a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Aggregate daily statistics (called by Node.js background service)
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function aggregateDailyAction(Request $request)
    {
        try {
            // Handle JSON input from Node.js background service
            $content = $request->getContent();
            if (!empty($content)) {
                $post = json_decode($content, true);
            } else {
                $post = $request->request->all();
            }

            if (!isset($post['date'])) {
                throw new ODRBadRequestException('Missing date parameter');
            }

            $date_str = $post['date'];
            $date = new \DateTime($date_str);

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Get the system user
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->findOneBy(array('id' => 1));

            // Query hourly statistics for this date
            $start_of_day = (clone $date)->setTime(0, 0, 0);
            $end_of_day = (clone $date)->setTime(23, 59, 59);

            $hourly_stats = $em->createQueryBuilder()
                ->select('s')
                ->from('ODRAdminBundle:StatisticsHourly', 's')
                ->where('s.hourTimestamp >= :start')
                ->andWhere('s.hourTimestamp <= :end')
                ->andWhere('s.deletedAt IS NULL')
                ->setParameter('start', $start_of_day)
                ->setParameter('end', $end_of_day)
                ->getQuery()
                ->getResult();

            // Aggregate by datatype, datarecord, file, country, province, is_bot
            $aggregated = array();

            foreach ($hourly_stats as $stat) {
                $key = implode('|', array(
                    $stat->getDataType() ? $stat->getDataType()->getId() : 'null',
                    $stat->getDataRecord() ? $stat->getDataRecord()->getId() : 'null',
                    $stat->getFile() ? $stat->getFile()->getId() : 'null',
                    $stat->getCountry() ?: 'null',
                    $stat->getProvince() ?: 'null',
                    $stat->getIsBot() ? '1' : '0'
                ));

                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = array(
                        'datatype' => $stat->getDataType(),
                        'datarecord' => $stat->getDataRecord(),
                        'file' => $stat->getFile(),
                        'country' => $stat->getCountry(),
                        'province' => $stat->getProvince(),
                        'is_bot' => $stat->getIsBot(),
                        'view_count' => 0,
                        'download_count' => 0,
                        'search_result_view_count' => 0
                    );
                }

                $aggregated[$key]['view_count'] += $stat->getViewCount();
                $aggregated[$key]['download_count'] += $stat->getDownloadCount();
                $aggregated[$key]['search_result_view_count'] += $stat->getSearchResultViewCount();
            }

            // Store aggregated daily statistics
            $stored_count = 0;

            foreach ($aggregated as $agg) {
                $daily = new StatisticsDaily();
                $daily->setDayDate($date);
                $daily->setViewCount($agg['view_count']);
                $daily->setDownloadCount($agg['download_count']);
                $daily->setSearchResultViewCount($agg['search_result_view_count']);
                $daily->setCountry($agg['country']);
                $daily->setProvince($agg['province']);
                $daily->setIsBot($agg['is_bot']);

                if ($agg['datatype']) {
                    $daily->setDataType($agg['datatype']);
                }
                if ($agg['datarecord']) {
                    $daily->setDataRecord($agg['datarecord']);
                }
                if ($agg['file']) {
                    $daily->setFile($agg['file']);
                }

                $daily->setCreatedBy($user);
                $daily->setUpdatedBy($user);

                $em->persist($daily);
                $stored_count++;
            }

            $em->flush();

            return new JsonResponse(array(
                'success' => true,
                'count' => $stored_count
            ));

        } catch (\Exception $e) {
            $source = 0xb3c9d47b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Clean up hourly statistics older than cutoff date (called by Node.js background service)
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function cleanupHourlyAction(Request $request)
    {
        try {
            // Handle JSON input from Node.js background service
            $content = $request->getContent();
            if (!empty($content)) {
                $post = json_decode($content, true);
            } else {
                $post = $request->request->all();
            }

            if (!isset($post['cutoff_date'])) {
                throw new ODRBadRequestException('Missing cutoff_date parameter');
            }

            $cutoff_str = $post['cutoff_date'];
            $cutoff_date = new \DateTime($cutoff_str);

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Soft delete hourly statistics older than cutoff date
            $query = $em->createQueryBuilder()
                ->update('ODRAdminBundle:StatisticsHourly', 's')
                ->set('s.deletedAt', ':now')
                ->where('s.hourTimestamp < :cutoff')
                ->andWhere('s.deletedAt IS NULL')
                ->setParameter('now', new \DateTime())
                ->setParameter('cutoff', $cutoff_date)
                ->getQuery();

            $deleted_count = $query->execute();

            return new JsonResponse(array(
                'success' => true,
                'count' => $deleted_count
            ));

        } catch (\Exception $e) {
            $source = 0xc4dad58c;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Update bot patterns (called by Node.js background service)
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function updateBotsAction(Request $request)
    {
        try {
            // Handle JSON input from Node.js background service
            $content = $request->getContent();
            if (!empty($content)) {
                $post = json_decode($content, true);
            } else {
                $post = $request->request->all();
            }

            if (!isset($post['patterns']) || !is_array($post['patterns'])) {
                throw new ODRBadRequestException('Missing or invalid patterns array');
            }

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Get the system user
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->findOneBy(array('id' => 1));

            $patterns = $post['patterns'];
            $added = 0;
            $updated = 0;
            $deactivated = 0;

            // Get existing patterns
            $existing_patterns = array();
            $existing_bots = $em->getRepository('ODRAdminBundle:StatisticsBotList')
                ->createQueryBuilder('b')
                ->where('b.deletedAt IS NULL')
                ->getQuery()
                ->getResult();

            foreach ($existing_bots as $bot) {
                $existing_patterns[$bot->getPattern()] = $bot;
            }

            // Track which patterns we've seen
            $seen_patterns = array();

            // Add or update patterns
            foreach ($patterns as $pattern_data) {
                $pattern = $pattern_data['pattern'];
                $bot_name = isset($pattern_data['bot_name']) ? $pattern_data['bot_name'] : $pattern;
                $is_active = isset($pattern_data['is_active']) ? $pattern_data['is_active'] : true;

                $seen_patterns[$pattern] = true;

                if (isset($existing_patterns[$pattern])) {
                    // Update existing
                    $bot = $existing_patterns[$pattern];
                    $bot->setBotName($bot_name);
                    $bot->setIsActive($is_active);
                    $bot->setUpdated(new \DateTime());
                    $bot->setUpdatedBy($user);
                    $updated++;
                } else {
                    // Add new
                    $bot = new StatisticsBotList();
                    $bot->setPattern($pattern);
                    $bot->setBotName($bot_name);
                    $bot->setIsActive($is_active);
                    $bot->setCreated(new \DateTime());
                    $bot->setUpdated(new \DateTime());
                    $bot->setCreatedBy($user);
                    $bot->setUpdatedBy($user);
                    $em->persist($bot);
                    $added++;
                }
            }

            // Deactivate patterns not in the new list
            foreach ($existing_patterns as $pattern => $bot) {
                if (!isset($seen_patterns[$pattern])) {
                    $bot->setIsActive(false);
                    $bot->setUpdated(new \DateTime());
                    $bot->setUpdatedBy($user);
                    $deactivated++;
                }
            }

            $em->flush();

            return new JsonResponse(array(
                'success' => true,
                'added' => $added,
                'updated' => $updated,
                'deactivated' => $deactivated
            ));

        } catch (\Exception $e) {
            $source = 0xd5ebe69d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
