<?php

/**
 * Open Data Repository Data Publisher
 * Statistics Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service handles logging and retrieval of statistics for datarecord views
 * and file downloads.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\StatisticsDaily;
use ODR\AdminBundle\Entity\StatisticsHourly;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class StatisticsService
{
    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * StatisticsService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatatreeInfoService $datatree_info_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatreeInfoService $datatree_info_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->datatree_info_service = $datatree_info_service;
        $this->logger = $logger;
    }


    /**
     * Generate a UUID v4
     *
     * @return string
     */
    private function generateUUID()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }


    /**
     * Log a datarecord view to Redis
     * No database lookups - all IDs are provided by the client
     *
     * @param int $datarecord_id
     * @param int $datatype_id
     * @param string $ip_address
     * @param string $user_agent
     * @param bool $is_search_result
     */
    public function logRecordView(
        $datarecord_id,
        $datatype_id,
        $ip_address,
        $user_agent,
        $is_search_result = false
    ) {
        try {
            // Check deduplication key (only by IP address)
            $dedup_key = 'stats_dedup:' . md5($ip_address) . ':view:' . $datarecord_id;
            if ($this->cache_service->exists($dedup_key)) {
                // Already logged within the last minute, skip
                return;
            }

            // Prepare log data (no user tracking)
            $log_data = array(
                'type' => 'view',
                'datarecord_id' => intval($datarecord_id),
                'file_id' => null,
                'datatype_id' => intval($datatype_id),
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'is_search_result' => $is_search_result ? true : false,
                'timestamp' => time()
            );

            // Store in Redis with unique key
            $key = 'stats_log:view:' . time() . ':' . $this->generateUUID();
            $this->cache_service->set($key, json_encode($log_data));

            // Set deduplication key with 60-second expiration
            $this->cache_service->set($dedup_key, '1');
            $this->cache_service->expire($dedup_key, 60);

        } catch (\Exception $e) {
            // Log error but don't throw - statistics logging should not break the application
            $this->logger->error(
                'StatisticsService::logRecordView() - Error logging view',
                array('exception' => $e->getMessage())
            );
        }
    }


    /**
     * Log a file download to Redis
     *
     * @param int $file_id
     * @param int $datatype_id
     * @param ODRUser|null $user
     * @param string $ip_address
     * @param string $user_agent
     * @param int|null $datarecord_id
     */
    public function logFileDownload(
        $file_id,
        $datatype_id,
        $user,
        $ip_address,
        $user_agent,
        $datarecord_id = null
    ) {
        try {
            // Check deduplication key
            $dedup_key = 'stats_dedup:' . md5($ip_address) . ':download:' . $file_id;
            if ($this->cache_service->exists($dedup_key)) {
                // Already logged within the last minute, skip
                return;
            }

            // Prepare log data
            $log_data = array(
                'type' => 'download',
                'datarecord_id' => ($datarecord_id !== null) ? intval($datarecord_id) : null,
                'file_id' => intval($file_id),
                'datatype_id' => intval($datatype_id),
                'user_id' => ($user !== null && $user instanceof ODRUser) ? $user->getId() : null,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'is_search_result' => false,
                'timestamp' => time()
            );

            // Store in Redis with unique key
            $key = 'stats_log:download:' . time() . ':' . $this->generateUUID();
            $this->cache_service->set($key, json_encode($log_data));

            // Set deduplication key with 60-second expiration
            $this->cache_service->set($dedup_key, '1');
            $this->cache_service->expire($dedup_key, 60);

        } catch (\Exception $e) {
            // Log error but don't throw - statistics logging should not break the application
            $this->logger->error(
                'StatisticsService::logFileDownload() - Error logging download',
                array('exception' => $e->getMessage())
            );
        }
    }


    /**
     * Get statistics for a specific datatype
     *
     * @param int $datatype_id
     * @param \DateTime $start_date
     * @param \DateTime $end_date
     * @param bool $include_bots
     * @param string|null $granularity 'hourly' or 'daily'
     * @return array
     */
    public function getStatisticsByDatatype(
        $datatype_id,
        \DateTime $start_date,
        \DateTime $end_date,
        $include_bots = false,
        $granularity = 'daily'
    ) {
        try {
            // Determine which entity to query
            if ($granularity === 'hourly') {
                $query = $this->em->createQueryBuilder()
                    ->select('s')
                    ->from('ODRAdminBundle:StatisticsHourly', 's')
                    ->where('s.dataType = :datatype_id')
                    ->andWhere('s.hourTimestamp >= :start_date')
                    ->andWhere('s.hourTimestamp <= :end_date')
                    ->andWhere('s.deletedAt IS NULL');

                if (!$include_bots) {
                    $query->andWhere('s.isBot = false');
                }

                $query->setParameters(array(
                    'datatype_id' => $datatype_id,
                    'start_date' => $start_date,
                    'end_date' => $end_date
                ))
                ->orderBy('s.hourTimestamp', 'ASC');

                $results = $query->getQuery()->getResult();
                return $this->formatHourlyResults($results);

            } else {
                // Daily granularity
                $query = $this->em->createQueryBuilder()
                    ->select('s')
                    ->from('ODRAdminBundle:StatisticsDaily', 's')
                    ->where('s.dataType = :datatype_id')
                    ->andWhere('s.dayDate >= :start_date')
                    ->andWhere('s.dayDate <= :end_date')
                    ->andWhere('s.deletedAt IS NULL');

                if (!$include_bots) {
                    $query->andWhere('s.isBot = false');
                }

                $query->setParameters(array(
                    'datatype_id' => $datatype_id,
                    'start_date' => $start_date->format('Y-m-d'),
                    'end_date' => $end_date->format('Y-m-d')
                ))
                ->orderBy('s.dayDate', 'ASC');

                $results = $query->getQuery()->getResult();
                return $this->formatDailyResults($results);
            }

        } catch (\Exception $e) {
            $this->logger->error(
                'StatisticsService::getStatisticsByDatatype() - Error retrieving statistics',
                array('exception' => $e->getMessage())
            );
            return array();
        }
    }


    /**
     * Get statistics for a specific datarecord (including all child records and files)
     *
     * @param int $datarecord_id
     * @param \DateTime $start_date
     * @param \DateTime $end_date
     * @param bool $include_bots
     * @return array
     */
    public function getStatisticsByRecord(
        $datarecord_id,
        \DateTime $start_date,
        \DateTime $end_date,
        $include_bots = false
    ) {
        try {
            // Get the datarecord and all its descendants
            /** @var DataRecord $datarecord */
            $datarecord = $this->em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if (!$datarecord) {
                return array();
            }

            // Get all descendant datarecords
            $descendant_ids = $this->getDescendantDatarecordIds($datarecord_id);
            $all_record_ids = array_merge(array($datarecord_id), $descendant_ids);

            // Query daily statistics for these records
            $query = $this->em->createQueryBuilder()
                ->select('s')
                ->from('ODRAdminBundle:StatisticsDaily', 's')
                ->where('s.dataRecord IN (:record_ids)')
                ->andWhere('s.dayDate >= :start_date')
                ->andWhere('s.dayDate <= :end_date')
                ->andWhere('s.deletedAt IS NULL');

            if (!$include_bots) {
                $query->andWhere('s.isBot = false');
            }

            $query->setParameters(array(
                'record_ids' => $all_record_ids,
                'start_date' => $start_date->format('Y-m-d'),
                'end_date' => $end_date->format('Y-m-d')
            ))
            ->orderBy('s.dayDate', 'ASC');

            $results = $query->getQuery()->getResult();
            return $this->formatDailyResults($results);

        } catch (\Exception $e) {
            $this->logger->error(
                'StatisticsService::getStatisticsByRecord() - Error retrieving statistics',
                array('exception' => $e->getMessage())
            );
            return array();
        }
    }


    /**
     * Get all descendant datarecord IDs for a given datarecord
     *
     * @param int $datarecord_id
     * @return array
     */
    private function getDescendantDatarecordIds($datarecord_id)
    {
        $descendant_ids = array();

        try {
            // Get direct children
            $children = $this->em->createQueryBuilder()
                ->select('dr.id')
                ->from('ODRAdminBundle:DataRecord', 'dr')
                ->where('dr.parent = :parent_id')
                ->andWhere('dr.deletedAt IS NULL')
                ->setParameter('parent_id', $datarecord_id)
                ->getQuery()
                ->getResult();

            foreach ($children as $child) {
                $child_id = $child['id'];
                $descendant_ids[] = $child_id;

                // Recursively get descendants of this child
                $sub_descendants = $this->getDescendantDatarecordIds($child_id);
                $descendant_ids = array_merge($descendant_ids, $sub_descendants);
            }

        } catch (\Exception $e) {
            $this->logger->error(
                'StatisticsService::getDescendantDatarecordIds() - Error getting descendants',
                array('exception' => $e->getMessage())
            );
        }

        return $descendant_ids;
    }


    /**
     * Get geographic statistics
     *
     * @param int|null $datatype_id (null for all datatypes)
     * @param \DateTime $start_date
     * @param \DateTime $end_date
     * @param bool $include_bots
     * @return array ['country' => ['US' => 100, 'UK' => 50], 'province' => [...]]
     */
    public function getGeographicStats(
        $datatype_id,
        \DateTime $start_date,
        \DateTime $end_date,
        $include_bots = false
    ) {
        try {
            $query = $this->em->createQueryBuilder()
                ->select('s.country, s.province, SUM(s.viewCount) as total_views, SUM(s.downloadCount) as total_downloads')
                ->from('ODRAdminBundle:StatisticsDaily', 's')
                ->where('s.dayDate >= :start_date')
                ->andWhere('s.dayDate <= :end_date')
                ->andWhere('s.deletedAt IS NULL');

            if ($datatype_id !== null) {
                $query->andWhere('s.dataType = :datatype_id')
                      ->setParameter('datatype_id', $datatype_id);
            }

            if (!$include_bots) {
                $query->andWhere('s.isBot = false');
            }

            $query->setParameter('start_date', $start_date->format('Y-m-d'))
                  ->setParameter('end_date', $end_date->format('Y-m-d'))
                  ->groupBy('s.country, s.province')
                  ->orderBy('total_views', 'DESC');

            $results = $query->getQuery()->getResult();

            // Format results by country and province
            $country_stats = array();
            $province_stats = array();

            foreach ($results as $row) {
                $country = $row['country'] ?: 'Unknown';
                $province = $row['province'] ?: 'Unknown';
                $views = intval($row['total_views']);
                $downloads = intval($row['total_downloads']);

                if (!isset($country_stats[$country])) {
                    $country_stats[$country] = array('views' => 0, 'downloads' => 0);
                }
                $country_stats[$country]['views'] += $views;
                $country_stats[$country]['downloads'] += $downloads;

                if ($province !== 'Unknown') {
                    $key = $country . '/' . $province;
                    if (!isset($province_stats[$key])) {
                        $province_stats[$key] = array('views' => 0, 'downloads' => 0);
                    }
                    $province_stats[$key]['views'] += $views;
                    $province_stats[$key]['downloads'] += $downloads;
                }
            }

            return array(
                'country' => $country_stats,
                'province' => $province_stats
            );

        } catch (\Exception $e) {
            $this->logger->error(
                'StatisticsService::getGeographicStats() - Error retrieving geographic statistics',
                array('exception' => $e->getMessage())
            );
            return array('country' => array(), 'province' => array());
        }
    }


    /**
     * Get dashboard statistics for admin view
     *
     * @param int $datatype_id
     * @param int $days Number of days to look back
     * @return array
     */
    public function getDashboardStats($datatype_id, $days = 30)
    {
        try {
            $end_date = new \DateTime();
            $start_date = (clone $end_date)->modify('-' . $days . ' days');

            // Get daily statistics for VIEWS (only for the specific datatype)
            $stats = $this->getStatisticsByDatatype(
                $datatype_id,
                $start_date,
                $end_date,
                false,  // Exclude bots
                'daily'
            );

            // Calculate totals for views (only for this datatype)
            $total_views = 0;
            $total_search_views = 0;
            $total_downloads = 0;

            foreach ($stats as $day) {
                $total_views += intval($day['view_count']);
                $total_search_views += intval($day['search_result_view_count']);
                $total_downloads += intval($day['download_count']);
            }

            // Get all associated datatypes (including child and linked datatypes) for download aggregation
            $associated_datatype_ids = $this->datatree_info_service->getAssociatedDatatypes(
                $datatype_id,
                true  // deep = true to get all descendants
            );

            // Remove the current datatype from the list since we already have its stats
            $descendant_datatype_ids = array_diff($associated_datatype_ids, array($datatype_id));

            $this->logger->info(
                'StatisticsService::getDashboardStats() - Associated datatype check',
                array(
                    'datatype_id' => $datatype_id,
                    'associated_datatypes' => $associated_datatype_ids,
                    'descendant_datatypes' => $descendant_datatype_ids,
                    'count' => count($descendant_datatype_ids)
                )
            );

            // If there are descendant datatypes, aggregate their downloads
            if (!empty($descendant_datatype_ids)) {
                $this->logger->info(
                    'StatisticsService::getDashboardStats() - Aggregating downloads from descendant datatypes',
                    array('datatype_id' => $datatype_id, 'descendant_datatypes' => $descendant_datatype_ids)
                );

                // Get download statistics for each descendant datatype
                foreach ($descendant_datatype_ids as $descendant_dt_id) {
                    $descendant_stats = $this->getStatisticsByDatatype(
                        $descendant_dt_id,
                        $start_date,
                        $end_date,
                        false,  // Exclude bots
                        'daily'
                    );

                    $descendant_downloads = 0;
                    // Add descendant datatype downloads to the total
                    foreach ($descendant_stats as $day) {
                        $descendant_downloads += intval($day['download_count']);
                    }

                    $this->logger->info(
                        'StatisticsService::getDashboardStats() - Descendant datatype downloads',
                        array(
                            'descendant_datatype_id' => $descendant_dt_id,
                            'downloads' => $descendant_downloads,
                            'stats_count' => count($descendant_stats)
                        )
                    );

                    $total_downloads += $descendant_downloads;
                }

                $this->logger->info(
                    'StatisticsService::getDashboardStats() - Final download count after aggregation',
                    array('total_downloads' => $total_downloads)
                );
            }

            return array(
                'total_views' => $total_views,
                'total_search_views' => $total_search_views,
                'total_downloads' => $total_downloads,
                'daily_stats' => $stats,
                'period_days' => $days
            );

        } catch (\Exception $e) {
            $this->logger->error(
                'StatisticsService::getDashboardStats() - Error retrieving dashboard statistics',
                array('exception' => $e->getMessage())
            );
            return array(
                'total_views' => 0,
                'total_search_views' => 0,
                'total_downloads' => 0,
                'daily_stats' => array(),
                'period_days' => $days
            );
        }
    }


    /**
     * Get overall summary statistics (super admin only)
     *
     * @param \DateTime $start_date
     * @param \DateTime $end_date
     * @return array [datatype_id => ['views' => X, 'downloads' => Y]]
     */
    public function getOverallSummary(\DateTime $start_date, \DateTime $end_date)
    {
        try {
            $query = $this->em->createQueryBuilder()
                ->select('IDENTITY(s.dataType) as datatype_id, SUM(s.viewCount) as total_views, SUM(s.downloadCount) as total_downloads')
                ->from('ODRAdminBundle:StatisticsDaily', 's')
                ->where('s.dayDate >= :start_date')
                ->andWhere('s.dayDate <= :end_date')
                ->andWhere('s.deletedAt IS NULL')
                ->andWhere('s.isBot = false')
                ->setParameter('start_date', $start_date->format('Y-m-d'))
                ->setParameter('end_date', $end_date->format('Y-m-d'))
                ->groupBy('datatype_id')
                ->orderBy('total_views', 'DESC');

            $results = $query->getQuery()->getResult();

            $summary = array();
            foreach ($results as $row) {
                $datatype_id = intval($row['datatype_id']);
                $summary[$datatype_id] = array(
                    'views' => intval($row['total_views']),
                    'downloads' => intval($row['total_downloads'])
                );
            }

            return $summary;

        } catch (\Exception $e) {
            $this->logger->error(
                'StatisticsService::getOverallSummary() - Error retrieving overall summary',
                array('exception' => $e->getMessage())
            );
            return array();
        }
    }


    /**
     * Format hourly results into a consistent array structure
     *
     * @param StatisticsHourly[] $results
     * @return array
     */
    private function formatHourlyResults($results)
    {
        $formatted = array();

        foreach ($results as $stat) {
            $formatted[] = array(
                'timestamp' => $stat->getHourTimestamp()->format('Y-m-d H:i:s'),
                'view_count' => $stat->getViewCount(),
                'download_count' => $stat->getDownloadCount(),
                'search_result_view_count' => $stat->getSearchResultViewCount(),
                'country' => $stat->getCountry(),
                'province' => $stat->getProvince(),
                'is_bot' => $stat->getIsBot()
            );
        }

        return $formatted;
    }


    /**
     * Format daily results into a consistent array structure
     *
     * @param StatisticsDaily[] $results
     * @return array
     */
    private function formatDailyResults($results)
    {
        $formatted = array();

        foreach ($results as $stat) {
            $formatted[] = array(
                'date' => $stat->getDayDate()->format('Y-m-d'),
                'view_count' => $stat->getViewCount(),
                'download_count' => $stat->getDownloadCount(),
                'search_result_view_count' => $stat->getSearchResultViewCount(),
                'country' => $stat->getCountry(),
                'province' => $stat->getProvince(),
                'is_bot' => $stat->getIsBot()
            );
        }

        return $formatted;
    }
}
