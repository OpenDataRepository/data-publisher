<?php
/**
 * Open Data Repository Data Publisher
 * Standalone Statistics Logger
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Lightweight standalone endpoint for logging datarecord views
 * Bypasses Symfony/WordPress overhead by connecting directly to Redis
 */

// Prevent direct browser access
if (php_sapi_name() !== 'cli' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    // Allow it - some clients may not send this header
}

include("./log_view_config.php");

/**
 * Generate a UUID v4
 * @return string
 */
function generateUUID() {
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
 * Get client IP address
 * @return string
 */
function getClientIP() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        // X-Forwarded-For can contain multiple IPs, take the first one
        if (strpos($ip, ',') !== false) {
            $ips = explode(',', $ip);
            $ip = trim($ips[0]);
        }
        return $ip;
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        return $_SERVER['REMOTE_ADDR'];
    }
    return 'unknown';
}

/**
 * Get user agent
 * @return string
 */
function getUserAgent() {
    return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
}

/**
 * Log a single record view to Redis
 *
 * @param Redis $redis Redis connection
 * @param int $datarecord_id
 * @param int $datatype_id
 * @param string $ip_address
 * @param string $user_agent
 * @param bool $is_search_result
 * @return bool Success status
 */
function logRecordView($redis, $datarecord_id, $datatype_id, $ip_address, $user_agent, $is_search_result = false) {
    try {
        // Check deduplication key (only by IP address)
        $dedup_key = REDIS_PREFIX . 'stats_dedup:' . md5($ip_address) . ':view:' . $datarecord_id;
        if ($redis->exists($dedup_key)) {
            // Already logged within the last minute, skip
            return false;
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
        // Must match CacheService format: gzcompress(serialize($json_string))
        $key = REDIS_PREFIX . 'stats_log:view:' . time() . ':' . generateUUID();
        $json_string = json_encode($log_data);

        // Serialize the JSON string (PHP serialization)
        $serialized = serialize($json_string);

        // Compress using gzcompress (zlib format for inflate in Node.js)
        $compressed = gzcompress($serialized);

        // Store the compressed, serialized data
        $redis->set($key, $compressed);

        // Set deduplication key with 60-second expiration
        $redis->setex($dedup_key, DEDUP_EXPIRATION, '1');

        return true;

    } catch (Exception $e) {
        // Log error but don't throw - statistics logging should not break the application
        error_log('log_view.php - Error logging view: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send JSON response
 *
 * @param array $data
 * @param int $status_code
 */
function sendJsonResponse($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Main execution
try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(array(
            'success' => false,
            'error' => 'Only POST requests are accepted'
        ), 405);
    }

    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        sendJsonResponse(array(
            'success' => false,
            'error' => 'Invalid JSON input'
        ), 400);
    }

    // Connect to Redis
    $redis = new Redis();
    if (!$redis->connect(REDIS_HOST, REDIS_PORT)) {
        throw new Exception('Failed to connect to Redis');
    }

    // Get client info
    $ip_address = getClientIP();
    $user_agent = getUserAgent();

    // Check if this is a batch request or single request
    if (isset($data['records']) && is_array($data['records'])) {
        // Batch logging
        $is_search_result = isset($data['is_search_result']) ? (bool)$data['is_search_result'] : false;

        $logged = 0;
        $skipped = 0;
        $total = count($data['records']);

        foreach ($data['records'] as $record) {
            if (!isset($record['datarecord_id']) || !isset($record['datatype_id'])) {
                $skipped++;
                continue;
            }

            $success = logRecordView(
                $redis,
                intval($record['datarecord_id']),
                intval($record['datatype_id']),
                $ip_address,
                $user_agent,
                $is_search_result
            );

            if ($success) {
                $logged++;
            } else {
                $skipped++;
            }
        }

        $redis->close();

        sendJsonResponse(array(
            'success' => true,
            'logged' => $logged,
            'skipped' => $skipped,
            'total' => $total
        ));

    } else {
        // Single record logging
        if (!isset($data['datarecord_id']) || !isset($data['datatype_id'])) {
            sendJsonResponse(array(
                'success' => false,
                'error' => 'Missing datarecord_id or datatype_id'
            ), 400);
        }

        $datarecord_id = intval($data['datarecord_id']);
        $datatype_id = intval($data['datatype_id']);
        $is_search_result = isset($data['is_search_result']) ? (bool)$data['is_search_result'] : false;

        $success = logRecordView(
            $redis,
            $datarecord_id,
            $datatype_id,
            $ip_address,
            $user_agent,
            $is_search_result
        );

        $redis->close();

        sendJsonResponse(array(
            'success' => $success,
            'message' => $success ? 'View logged successfully' : 'View already logged (deduplicated)'
        ));
    }

} catch (Exception $e) {
    error_log('log_view.php - Fatal error: ' . $e->getMessage());
    sendJsonResponse(array(
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ), 500);
}
