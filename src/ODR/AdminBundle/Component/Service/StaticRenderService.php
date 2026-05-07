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
     * @var string Absolute base URL to use when constructing the worker target
     *             URL (e.g. "https://dev.rruff.net").
     */
    private $site_baseurl;

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
        LoggerInterface $logger
    ) {
        $this->em = $entity_manager;
        $this->pheanstalk = $pheanstalk;
        $this->redis = $redis;
        $this->odr_web_dir = rtrim($odr_web_directory, '/');
        // site_baseurl can be a protocol-relative '//host'; force https for the worker.
        $this->site_baseurl = self::normalizeBaseurl($site_baseurl);
        // Same source used to derive the per-site folder name under
        // web/uploads/static/, so a shared web root can host multiple sites.
        $this->site_folder = self::deriveSiteFolder($site_baseurl);
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

        $url = $this->site_baseurl . '/view/record/' . $datarecord_uuid;
        $output_path = self::getOutputPathByUuid($datatype_uuid, $datarecord_uuid);

        $payload = json_encode(array(
            'datatype_uuid' => $datatype_uuid,
            'datarecord_uuid' => $datarecord_uuid,
            'datarecord_id' => $datarecord_id,
            'version' => $version,
            'url' => $url,
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
     * @throws \InvalidArgumentException if $datatype is not a top-level datatype
     */
    public function enqueueDatatype(DataType $datatype)
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
             FROM ODRAdminBundle:DataRecord AS dr
             LEFT JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
             WHERE dr.dataType = :datatype_id
               AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL
               AND drm.publicDate != :public_date
             ORDER BY dr.id ASC'
        )->setParameters(array(
            'datatype_id' => $datatype_id,
            'public_date' => '2200-01-01 00:00:00',
        ));

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
}
