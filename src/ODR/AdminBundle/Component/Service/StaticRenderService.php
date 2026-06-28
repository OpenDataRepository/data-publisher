<?php

/**
 * Open Data Repository Data Publisher
 * StaticRenderService
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Enqueues "render this record to a static HTML file" jobs onto the
 * `odr_static_render` beanstalkd tube, which is consumed by the Node
 * daemon at background_services/static_render_daemon.js.
 *
 * Job payload format (JSON):
 *   {
 *     "datatype_uuid":  "<uuid>",
 *     "datarecord_uuid":"<uuid>",
 *     "datarecord_id":  <int>,
 *     "version":        <int>,           // monotonically increasing per record
 *     "url":            "<absolute url>",// what the worker should goto()
 *     "output_path":    "<abs path>"     // where the worker writes the file
 *   }
 *
 * The version is stored in Redis as `static_render:version:{record_id}` and
 * incremented on every enqueue. Workers compare their job's version against
 * the current value when picking the job up; if the job is older than the
 * stored value, the job is dropped (a newer enqueue is already pending).
 * That's how we de-duplicate without stalking the tube directly.
 */

namespace ODR\AdminBundle\Component\Service;

use Doctrine\ORM\EntityManager;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use Pheanstalk\Pheanstalk;
use Psr\Log\LoggerInterface;

class StaticRenderService
{
    /**
     * Tube name used for static-render jobs.
     */
    const TUBE_NAME = 'odr_static_render';

    /**
     * Redis key prefix for per-record version counters.
     */
    const VERSION_KEY_PREFIX = 'static_render:version:';

    /**
     * Job priority when enqueueing. Lower number = higher priority.
     */
    const JOB_PRIORITY = 1024;

    /**
     * Max URLs allowed per child sitemap by the sitemaps.org / Google /
     * Bing spec. Exceeding this causes search engines to reject the
     * sitemap. We split per-datatype output into pages of this size.
     */
    const SITEMAP_MAX_URLS = 50000;

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var Pheanstalk
     */
    private $pheanstalk;

    /**
     * @var mixed Redis client from SncRedisBundle (Predis or PhpRedis client).
     *            Only `get()` and `incr()` are used, and both libraries
     *            implement those identically.
     */
    private $redis;

    /**
     * @var string Absolute path to the data-publisher web/ directory.
     */
    private $odr_web_dir;

    /**
     * @var string Absolute base URL the *worker* uses to fetch the live
     *             page for rendering. WP-integrated installs need to hit
     *             the WordPress host (e.g. https://dev.rruff.net) so the
     *             whole WP page chrome loads. Non-integrated installs
     *             just use site_baseurl.
     */
    private $fetch_baseurl;

    /**
     * @var string Absolute base URL used in the *sitemap* and any other
     *             public link to the cached static file. Always derived
     *             from site_baseurl, never from the WP host — the static
     *             files live under the ODR web root, served directly by
     *             Apache. Multiple WP-integrated sites can share a single
     *             ODR backend, so all their cached pages live under the
     *             one site_baseurl.
     */
    private $sitemap_baseurl;

    /**
     * @var string Per-site sub-directory under web/uploads/static/ derived
     *             from the host portion of site_baseurl. Lets a single web
     *             root host static output for multiple sites without
     *             collisions, e.g.
     *               web/uploads/static/dev.rruff.net/{dt_uuid}/{r_uuid}.html
     *               web/uploads/static/dev.odr.io/{dt_uuid}/{r_uuid}.html
     */
    private $site_folder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        EntityManager $entity_manager,
        Pheanstalk $pheanstalk,
        $redis,
        $odr_web_directory,
        $site_baseurl,
        $wordpress_site_baseurl,
        $odr_wordpress_integrated,
        LoggerInterface $logger
    ) {
        $this->em = $entity_manager;
        $this->pheanstalk = $pheanstalk;
        $this->redis = $redis;
        $this->odr_web_dir = rtrim($odr_web_directory, '/');

        $integrated = !empty($odr_wordpress_integrated)
            && $odr_wordpress_integrated !== '0'
            && strtolower((string)$odr_wordpress_integrated) !== 'false';

        // The worker fetches via the WP host when integrated so the full
        // WordPress chrome / theme renders around the ODR content. The
        // sitemap, on the other hand, always points at the cached file
        // via site_baseurl — the static files are served by Apache from
        // the ODR web root and don't need to go through WordPress at all.
        $fetch_source = $integrated && !empty($wordpress_site_baseurl)
            ? $wordpress_site_baseurl
            : $site_baseurl;
        $this->fetch_baseurl = self::normalizeBaseurl($fetch_source);
        $this->sitemap_baseurl = self::normalizeBaseurl($site_baseurl);

        // The per-site sub-directory still derives from the *site identity*
        // (i.e. the WP host when integrated) — multiple WP-integrated sites
        // can share one ODR backend, so the folder is what disambiguates
        // their cached output under the shared web root.
        $this->site_folder = self::deriveSiteFolder($fetch_source);
        $this->logger = $logger;
    }

    /**
     * Convert "//host" or "host" forms to "https://host".
     */
    private static function normalizeBaseurl($baseurl)
    {
        $baseurl = trim($baseurl);
        if (strpos($baseurl, '//') === 0) {
            return 'https:' . $baseurl;
        }
        if (strpos($baseurl, 'http://') === 0 || strpos($baseurl, 'https://') === 0) {
            return $baseurl;
        }
        return 'https://' . $baseurl;
    }

    /**
     * Strips protocol/leading-slashes/trailing-slashes from site_baseurl so
     * the result is safe to use as a directory name. e.g.:
     *   "//dev.rruff.net"        -> "dev.rruff.net"
     *   "https://dev.rruff.net/" -> "dev.rruff.net"
     *   "rruff.net"              -> "rruff.net"
     */
    private static function deriveSiteFolder($baseurl)
    {
        $folder = trim($baseurl);
        $folder = preg_replace('#^https?:#', '', $folder);
        $folder = ltrim($folder, '/');
        $folder = rtrim($folder, '/');
        // Defensive — if anything weird gets through, fall back to a sane default.
        if ($folder === '')
            $folder = 'default';
        return $folder;
    }

    /**
     * Returns the disk path where the static HTML for the given UUIDs will
     * live. Path is webroot-accessible:
     *   web/uploads/static/{site_folder}/{dt_uuid}/{r_uuid}.html
     * The site_folder is derived from site_baseurl so a shared web root
     * can host multiple sites without collisions.
     */
    public function getOutputPathByUuid($datatype_uuid, $record_uuid)
    {
        return $this->odr_web_dir . '/uploads/static/'
            . $this->site_folder . '/'
            . $datatype_uuid . '/'
            . $record_uuid . '.html';
    }

    /**
     * Convenience overload that takes entity instances.
     */
    public function getOutputPath(DataType $datatype, DataRecord $record)
    {
        return self::getOutputPathByUuid($datatype->getUniqueId(), $record->getUniqueId());
    }

    /**
     * Internal: build the job payload + push to beanstalkd. Used by both the
     * single-record enqueue and the bulk datatype enqueue, neither of which
     * needs the full DataRecord entity to get the job onto the tube.
     *
     * @return int The new version number assigned to this enqueue.
     */
    private function enqueueByIds($datatype_uuid, $datarecord_id, $datarecord_uuid)
    {
        // Bump the per-record version. Workers will use this to decide whether
        // they're picking up a stale (superseded) job.
        $version = (int)$this->redis->incr(self::VERSION_KEY_PREFIX . $datarecord_id);

        $url = $this->fetch_baseurl . '/view/record/' . $datarecord_uuid;
        // The cached page injects a script that calls this endpoint to
        // detect whether the visitor is logged in (then redirects to the
        // dynamic URL if so). Same hostname as the dynamic page so the
        // browser sends the right session cookies.
        $auth_check_url = $this->fetch_baseurl . '/api/v1/auth/status';
        $output_path = self::getOutputPathByUuid($datatype_uuid, $datarecord_uuid);

        $payload = json_encode(array(
            'datatype_uuid' => $datatype_uuid,
            'datarecord_uuid' => $datarecord_uuid,
            'datarecord_id' => $datarecord_id,
            'version' => $version,
            'url' => $url,
            'auth_check_url' => $auth_check_url,
            'output_path' => $output_path,
        ));

        $this->pheanstalk->useTube(self::TUBE_NAME)->put($payload, self::JOB_PRIORITY, 0);

        if ($this->logger !== null)
            $this->logger->info('StaticRenderService: enqueued record '.$datarecord_id.' v'.$version);

        return $version;
    }

    /**
     * Enqueues a single record for static rendering. Returns the new version
     * number assigned to this enqueue (callers don't usually need it).
     */
    public function enqueueRecord(DataRecord $record)
    {
        $datatype = $record->getDataType();
        if ($datatype === null)
            return null;

        return self::enqueueByIds(
            $datatype->getUniqueId(),
            $record->getId(),
            $record->getUniqueId()
        );
    }

    /**
     * Enqueues every public, non-deleted top-level record under this
     * (top-level) datatype. Returns the count of records queued.
     *
     * Mirrors APIController::getDatarecordListAction's query so we use the
     * same definition of "visible record" the public API does — id+uuid
     * pulled in one pass, no per-record entity hydration.
     *
     * @param DataType $datatype
     * @param int      $limit Maximum number of records to enqueue (0 = no
     *                        cap, the default). Useful for smoke tests.
     * @return int            Count of records queued.
     *
     * @throws \InvalidArgumentException if $datatype is not a top-level datatype
     */
    public function enqueueDatatype(DataType $datatype, $limit = 0)
    {
        // Top-level datatypes have no parent, or are their own parent.
        // Static rendering only makes sense for top-level datatypes —
        // child-datatype records are rendered as part of their grandparent.
        $parent = $datatype->getParent();
        if ($parent !== null && $parent->getId() !== $datatype->getId())
            throw new \InvalidArgumentException('Datatype must be top-level (id='.$datatype->getId().' is a child of '.$parent->getId().')');

        $datatype_id = $datatype->getId();
        $datatype_uuid = $datatype->getUniqueId();

        // Same query as APIController::getDatarecordListAction (anonymous
        // viewer path). Pulls every public, non-deleted record of this
        // top-level datatype as id+uuid pairs in one shot.
        $query = $this->em->createQuery(
            'SELECT dr.id AS dr_id, dr.unique_id AS dr_uuid
             FROM ODR\AdminBundle\Entity\DataRecord AS dr
             LEFT JOIN ODR\AdminBundle\Entity\DataRecordMeta AS drm WITH drm.dataRecord = dr
             WHERE dr.dataType = :datatype_id
               AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL
               AND drm.publicDate != :public_date
             ORDER BY dr.id ASC'
        )->setParameters(array(
            'datatype_id' => $datatype_id,
            'public_date' => '2200-01-01 00:00:00',
        ));
        if ($limit > 0)
            $query->setMaxResults((int)$limit);

        $rows = $query->getArrayResult();
        $count = 0;
        foreach ($rows as $row) {
            self::enqueueByIds($datatype_uuid, $row['dr_id'], $row['dr_uuid']);
            $count++;
        }

        return $count;
    }

    /**
     * Returns the current version for a record, or 0 if never enqueued.
     * The Node daemon needs this exposed via... well, via Redis directly,
     * but PHP callers can use this too.
     */
    public function getCurrentVersion(DataRecord $record)
    {
        $value = $this->redis->get(self::VERSION_KEY_PREFIX . $record->getId());
        return $value === null ? 0 : (int)$value;
    }

    /**
     * Deletes the rendered static HTML file for a record (no-op if the
     * file doesn't exist). Used by the delete / made-private event hooks.
     */
    public function deleteStaticFileByUuid($datatype_uuid, $record_uuid)
    {
        $path = self::getOutputPathByUuid($datatype_uuid, $record_uuid);
        if (is_file($path) && @unlink($path)) {
            if ($this->logger !== null)
                $this->logger->info('StaticRenderService: deleted static file '.$path);
            return true;
        }
        return false;
    }

    /**
     * @see deleteStaticFileByUuid
     */
    public function deleteStaticFile(DataType $datatype, DataRecord $record)
    {
        return self::deleteStaticFileByUuid($datatype->getUniqueId(), $record->getUniqueId());
    }

    /**
     * Returns the beanstalkd `stats-tube` snapshot for the static-render
     * tube, useful for a queue-status endpoint. Returns null if the tube
     * is empty/unknown.
     */
    public function getQueueStatus()
    {
        try {
            $stats = $this->pheanstalk->statsTube(self::TUBE_NAME);
            return array(
                'name'           => self::TUBE_NAME,
                'ready'          => isset($stats['current-jobs-ready'])    ? (int)$stats['current-jobs-ready']    : 0,
                'reserved'       => isset($stats['current-jobs-reserved']) ? (int)$stats['current-jobs-reserved'] : 0,
                'delayed'        => isset($stats['current-jobs-delayed'])  ? (int)$stats['current-jobs-delayed']  : 0,
                'buried'         => isset($stats['current-jobs-buried'])   ? (int)$stats['current-jobs-buried']   : 0,
                'total_jobs'     => isset($stats['total-jobs'])            ? (int)$stats['total-jobs']            : 0,
                'workers'        => isset($stats['current-watching'])      ? (int)$stats['current-watching']      : 0,
            );
        } catch (\Exception $e) {
            if ($this->logger !== null)
                $this->logger->warning('StaticRenderService::getQueueStatus failed: '.$e->getMessage());
            return null;
        }
    }

    /**
     * Returns a flat list of every rendered static file (relative to
     * web/uploads/static/{site_folder}/) along with its mtime. Used by
     * the sitemap controller. Skips empty / non-html files.
     *
     * @return array of [ 'datatype_uuid' => string, 'record_uuid' => string,
     *                    'mtime' => int, 'path' => string ]
     */
    public function listRenderedFiles()
    {
        $base = $this->odr_web_dir . '/uploads/static/' . $this->site_folder;
        $out = array();
        if (!is_dir($base))
            return $out;

        // First level under {site_folder}/ is datatype_uuid dirs.
        foreach (glob($base . '/*', GLOB_ONLYDIR) as $dt_dir) {
            $datatype_uuid = basename($dt_dir);
            foreach (glob($dt_dir . '/*.html') as $file_path) {
                $record_uuid = basename($file_path, '.html');
                $out[] = array(
                    'datatype_uuid' => $datatype_uuid,
                    'record_uuid'   => $record_uuid,
                    'mtime'         => filemtime($file_path),
                    'path'          => $file_path,
                );
            }
        }
        return $out;
    }

    /**
     * Returns the public URL (under site_baseurl) for a rendered static
     * file, suitable for a sitemap entry. Always uses site_baseurl so
     * Googlebot fetches the cached HTML directly from the ODR host
     * without going through WordPress, regardless of whether this
     * install is WP-integrated.
     */
    public function getPublicUrlForFile($datatype_uuid, $record_uuid)
    {
        return $this->sitemap_baseurl . '/uploads/static/'
            . $this->site_folder . '/'
            . $datatype_uuid . '/'
            . $record_uuid . '.html';
    }

    /**
     * Like listRenderedFiles() but groups the result by datatype_uuid and
     * sorts each group by record_uuid (so pagination across requests is
     * stable). Used by the sitemap-index controller to know how many
     * pages each datatype needs.
     *
     * @return array<string, array<int, array{record_uuid:string, mtime:int, path:string}>>
     */
    public function listRenderedFilesByDatatype()
    {
        $base = $this->odr_web_dir . '/uploads/static/' . $this->site_folder;
        $out = array();
        if (!is_dir($base))
            return $out;

        foreach (glob($base . '/*', GLOB_ONLYDIR) as $dt_dir) {
            $datatype_uuid = basename($dt_dir);
            $files = array();
            foreach (glob($dt_dir . '/*.html') as $file_path) {
                $files[] = array(
                    'record_uuid' => basename($file_path, '.html'),
                    'mtime'       => filemtime($file_path),
                    'path'        => $file_path,
                );
            }
            if (empty($files))
                continue;
            usort($files, function ($a, $b) {
                return strcmp($a['record_uuid'], $b['record_uuid']);
            });
            $out[$datatype_uuid] = $files;
        }
        return $out;
    }

    /**
     * Returns the public URL of a child sitemap for a datatype. Page 1
     * is `/sitemap-{uuid}.xml`; subsequent pages append `-{N}`.
     *
     * Uses fetch_baseurl (the WP host when WP-integrated) rather than
     * sitemap_baseurl, because /sitemap-*.xml is a *dynamic* Symfony
     * route — the request has to enter through WordPress to reach
     * Symfony in WP-integrated mode. Only the cached `/uploads/static/`
     * file URLs use sitemap_baseurl; those are served by Apache
     * directly without going through Symfony at all.
     */
    public function getChildSitemapUrl($datatype_uuid, $page = 1)
    {
        $name = 'sitemap-' . $datatype_uuid;
        if ($page > 1)
            $name .= '-' . (int)$page;
        return $this->fetch_baseurl . '/' . $name . '.xml';
    }
}
