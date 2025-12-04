/**
 * Open Data Repository Data Publisher
 * Statistics Processor (Node.js Background Service)
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Processes statistics log entries from Redis hourly:
 * - Reads all stats_log:* keys from Redis
 * - Performs GeoIP lookup for country/province
 * - Detects bots via user agent matching
 * - Aggregates by hour, entity, geography, and bot status
 * - Stores aggregated data via API
 * - Deletes processed Redis keys
 */
const https = require('https');
const geoip = require('geoip-lite');
const Redis = require('ioredis');
const zlib = require('zlib');
const PHPUnserialize = require('php-serialize');
const config = require('./statistics_config');
let authToken = null;
let botPatterns = [];

// Redis client
const redis = new Redis({
    host: config.redis.host,
    port: config.redis.port
});

/**
 * Make an authenticated API call
 */
async function apiCall(path, data, method = 'POST') {
    return new Promise((resolve, reject) => {
        const postData = JSON.stringify(data);

        const options = {
            hostname: config.api.host,
            port: 443,
            path: path,
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(postData)
            }
        };

        // Add auth token if available
        if (authToken) {
            options.headers['Authorization'] = 'Bearer ' + authToken;
        }

        const req = https.request(options, (res) => {
            let responseData = '';

            res.on('data', (chunk) => {
                responseData += chunk;
            });

            res.on('end', () => {
                try {
                    const parsed = JSON.parse(responseData);
                    resolve(parsed);
                } catch (e) {
                    reject(new Error('Failed to parse API response: ' + e.message));
                }
            });
        });

        req.on('error', (e) => {
            reject(e);
        });

        req.write(postData);
        req.end();
    });
}

/**
 * Authenticate with the API
 */
async function authenticate() {
    try {
        console.log('Authenticating with API...');
        const response = await apiCall(config.getEndpoint('login'), {
            username: config.api.user,
            password: config.api.key
        });

        if (response && response.token) {
            authToken = response.token;
            console.log('Authentication successful');
            return true;
        } else {
            console.error('Authentication failed: No token in response');
            return false;
        }
    } catch (e) {
        console.error('Authentication error:', e.message);
        return false;
    }
}

/**
 * Fetch bot patterns from API
 */
async function fetchBotPatterns() {
    try {
        console.log('Fetching bot patterns...');
        const response = await apiCall(config.getEndpoint('getBots'), {}, 'GET');

        if (response && response.bots && Array.isArray(response.bots)) {
            botPatterns = response.bots;
            console.log('Loaded ' + botPatterns.length + ' bot patterns');
            return true;
        } else {
            console.warn('No bot patterns returned, using empty list');
            botPatterns = [];
            return false;
        }
    } catch (e) {
        console.error('Error fetching bot patterns:', e.message);
        botPatterns = [];
        return false;
    }
}

/**
 * Check if a user agent is a bot
 */
function isBot(userAgent) {
    if (!userAgent) return false;

    const lowerAgent = userAgent.toLowerCase();

    // Check against loaded patterns
    for (let i = 0; i < botPatterns.length; i++) {
        const pattern = botPatterns[i].toLowerCase();
        if (lowerAgent.includes(pattern)) {
            return true;
        }
    }

    // Fallback common bot patterns
    const commonBots = [
        'bot', 'crawler', 'spider', 'scraper', 'slurp', 'mediapartners',
        'googlebot', 'bingbot', 'yahoo', 'baiduspider', 'yandexbot',
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp',
        'wget', 'curl', 'python-requests', 'java/', 'apache-httpclient'
    ];

    for (let i = 0; i < commonBots.length; i++) {
        if (lowerAgent.includes(commonBots[i])) {
            return true;
        }
    }

    return false;
}

/**
 * Perform GeoIP lookup
 */
function getGeoLocation(ipAddress) {
    try {
        const geo = geoip.lookup(ipAddress);

        if (geo) {
            return {
                country: geo.country || null,
                province: geo.region || null
            };
        }
    } catch (e) {
        console.debug('GeoIP lookup failed for ' + ipAddress + ':', e.message);
    }

    return {
        country: null,
        province: null
    };
}

/**
 * Round timestamp down to the hour
 */
function roundToHour(timestamp) {
    const date = new Date(timestamp * 1000);
    date.setMinutes(0, 0, 0);
    return Math.floor(date.getTime() / 1000);
}

/**
 * Process all statistics log entries from Redis
 */
async function processStatistics() {
    console.log('\n========================================');
    console.log('Statistics Processor Start:', new Date().toISOString());
    console.log('========================================\n');

    try {
        // Authenticate if needed
        if (!authToken) {
            const authSuccess = await authenticate();
            if (!authSuccess) {
                console.error('Cannot proceed without authentication');
                return;
            }
        }

        // Fetch bot patterns if empty
        if (botPatterns.length === 0) {
            await fetchBotPatterns();
        }

        // Scan for all stats_log:* keys
        const pattern = config.redis.prefix + 'stats_log:*';
        console.log('Scanning Redis for statistics logs: ', pattern);
        const keys = [];

        // Use SCAN to iterate keys
        let cursor = '0';
        do {
            const result = await redis.scan(cursor, 'MATCH', pattern, 'COUNT', 100);
            cursor = result[0];
            keys.push(...result[1]);
        } while (cursor !== '0');

        console.log('Found ' + keys.length + ' log entries to process');

        if (keys.length === 0) {
            console.log('No statistics to process');
            return;
        }

        // Fetch all log entries
        const logEntries = [];
        for (let i = 0; i < keys.length; i++) {
            try {
                // Use getBuffer to get raw binary data (gzip compressed)
                const value = await redis.getBuffer(keys[i]);
                if (value) {
                    // CacheService stores data as gzcompress(serialize($value))
                    // First decompress the gzip data
                    const decompressed = zlib.inflateSync(value);
                    // Then unserialize the PHP serialized string
                    // The value stored is already JSON string, so after unserialize we get the JSON string
                    const unserialized = PHPUnserialize.unserialize(decompressed.toString('utf8'));
                    // Parse the JSON string
                    const logData = JSON.parse(unserialized);
                    logEntries.push({
                        key: keys[i],
                        data: logData
                    });
                }
            } catch (e) {
                console.error('Error parsing log entry ' + keys[i] + ':', e.message);
            }
        }

        console.log('Parsed ' + logEntries.length + ' valid log entries');

        // Aggregate statistics
        const aggregated = {};

        for (let i = 0; i < logEntries.length; i++) {
            const entry = logEntries[i].data;

            // Round timestamp to hour
            const hourTimestamp = roundToHour(entry.timestamp);

            // Perform GeoIP lookup
            const geo = getGeoLocation(entry.ip_address);

            // Detect bot
            const bot = isBot(entry.user_agent);

            // Create aggregation key
            // Note: PHP stores fields as datatype_id and datarecord_id (no underscore between words)
            const aggKey = [
                hourTimestamp,
                entry.datatype_id || 'null',
                entry.datarecord_id || 'null',
                entry.file_id || 'null',
                geo.country || 'null',
                geo.province || 'null',
                bot ? '1' : '0'
            ].join('|');

            // Initialize aggregation entry if needed
            if (!aggregated[aggKey]) {
                aggregated[aggKey] = {
                    hour_timestamp: hourTimestamp,
                    // Note: API expects datatype_id and datarecord_id (no underscore in the middle)
                    datatype_id: entry.datatype_id || null,
                    datarecord_id: entry.datarecord_id || null,
                    file_id: entry.file_id || null,
                    country: geo.country,
                    province: geo.province,
                    is_bot: bot,
                    view_count: 0,
                    download_count: 0,
                    search_result_view_count: 0
                };
            }

            // Increment counters
            if (entry.type === 'view') {
                aggregated[aggKey].view_count++;
                if (entry.is_search_result) {
                    aggregated[aggKey].search_result_view_count++;
                }
            } else if (entry.type === 'download') {
                aggregated[aggKey].download_count++;
            }
        }

        // Convert to array
        const aggregatedArray = Object.values(aggregated);
        console.log('Aggregated into ' + aggregatedArray.length + ' statistics entries');

        // Store via API in batches
        const batchSize = 100;
        for (let i = 0; i < aggregatedArray.length; i += batchSize) {
            const batch = aggregatedArray.slice(i, i + batchSize);

            try {
                console.log('Storing batch ' + (Math.floor(i / batchSize) + 1) + '...');
                await apiCall(config.getEndpoint('storeHourly'), {
                    statistics: batch
                });
            } catch (e) {
                console.error('Error storing batch:', e.message);
                // Continue processing other batches even if one fails
            }
        }

        // Delete processed Redis keys
        console.log('Deleting processed log entries from Redis...');
        for (let i = 0; i < keys.length; i++) {
            try {
                await redis.del(keys[i]);
            } catch (e) {
                console.error('Error deleting key ' + keys[i] + ':', e.message);
            }
        }

        console.log('Successfully processed ' + logEntries.length + ' log entries');

    } catch (e) {
        console.error('Error processing statistics:', e.message);
        console.error(e.stack);
    }

    console.log('\n========================================');
    console.log('Statistics Processor Complete:', new Date().toISOString());
    console.log('========================================\n');
}

/**
 * Main execution
 */
async function main() {
    // Check for --force flag
    const forceRun = process.argv.includes('--force');

    const intervalMinutes = config.schedule.processorIntervalMinutes || 3;
    const intervalMs = intervalMinutes * 60 * 1000;

    console.log('Statistics Processor initialized');
    console.log('Will process logs every ' + intervalMinutes + ' minute(s)');

    if (forceRun) {
        console.log('\n--force flag detected, running immediately...');
        await processStatistics();
        console.log('\nForced run complete. Exiting...\n');
        process.exit(0);
    }

    // Run immediately on startup
    await processStatistics();

    // Schedule recurring runs at the configured interval
    function scheduleNext() {
        console.log('Next run scheduled in ' + intervalMinutes + ' minute(s)');

        setTimeout(async () => {
            await processStatistics();
            scheduleNext();
        }, intervalMs);
    }

    scheduleNext();
}

// Start the service
main().catch((e) => {
    console.error('Fatal error:', e);
    process.exit(1);
});

// Handle graceful shutdown
process.on('SIGINT', () => {
    console.log('\nReceived SIGINT, shutting down gracefully...');
    redis.quit();
    process.exit(0);
});

process.on('SIGTERM', () => {
    console.log('\nReceived SIGTERM, shutting down gracefully...');
    redis.quit();
    process.exit(0);
});
