/**
 * Open Data Repository Data Publisher
 * Statistics Daily Aggregator (Node.js Background Service)
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Aggregates hourly statistics into daily summaries:
 * - Runs once per day at 2 AM
 * - Fetches previous day's hourly statistics via API
 * - Aggregates by day, entity, geography, and bot status
 * - Stores in StatisticsDaily table via API
 * - Deletes hourly data older than 2 years
 */
const https = require('https');
const config = require('./statistics_config');
let authToken = null;

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
 * Aggregate yesterday's hourly statistics into daily summaries
 */
async function aggregateDaily() {
    console.log('\n========================================');
    console.log('Daily Aggregator Start:', new Date().toISOString());
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

        // Calculate yesterday's date
        const yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);
        yesterday.setHours(0, 0, 0, 0);

        const dateStr = yesterday.toISOString().split('T')[0];  // YYYY-MM-DD format

        console.log('Aggregating statistics for date:', dateStr);

        // Call API to perform aggregation
        const response = await apiCall(config.getEndpoint('aggregateDaily'), {
            date: dateStr
        });

        if (response && response.success) {
            console.log('Successfully aggregated ' + (response.count || 0) + ' daily statistics entries');
        } else {
            console.error('Aggregation failed:', response.message || 'Unknown error');
        }

    } catch (e) {
        console.error('Error during daily aggregation:', e.message);
        console.error(e.stack);
    }

    console.log('\n========================================');
    console.log('Daily Aggregator Complete:', new Date().toISOString());
    console.log('========================================\n');
}

/**
 * Clean up hourly statistics older than 2 years
 */
async function cleanupOldData() {
    console.log('\n========================================');
    console.log('Cleanup Old Data Start:', new Date().toISOString());
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

        // Calculate cutoff date
        const cutoffDate = new Date();
        cutoffDate.setFullYear(cutoffDate.getFullYear() - config.retention.hourlyYears);
        cutoffDate.setHours(0, 0, 0, 0);

        const dateStr = cutoffDate.toISOString().split('T')[0];

        console.log('Cleaning up hourly statistics older than:', dateStr);

        // Call API to perform cleanup
        const response = await apiCall(config.getEndpoint('cleanupHourly'), {
            cutoff_date: dateStr
        });

        if (response && response.success) {
            console.log('Successfully deleted ' + (response.count || 0) + ' old hourly statistics entries');
        } else {
            console.error('Cleanup failed:', response.message || 'Unknown error');
        }

    } catch (e) {
        console.error('Error during cleanup:', e.message);
        console.error(e.stack);
    }

    console.log('\n========================================');
    console.log('Cleanup Old Data Complete:', new Date().toISOString());
    console.log('========================================\n');
}

/**
 * Run daily aggregation and cleanup
 */
async function runDaily() {
    await aggregateDaily();
    await cleanupOldData();
}

/**
 * Calculate milliseconds until next scheduled run time
 */
function getTimeUntilNextRun() {
    const now = new Date();
    const nextRun = new Date(now);

    // Set to today's run time
    nextRun.setHours(config.schedule.aggregatorHour, config.schedule.aggregatorMinute, 0, 0);

    // If we've already passed today's run time, schedule for tomorrow
    if (nextRun <= now) {
        nextRun.setDate(nextRun.getDate() + 1);
    }

    return nextRun - now;
}

/**
 * Main execution
 */
async function main() {
    // Check for --force flag
    const forceRun = process.argv.includes('--force');

    console.log('Statistics Daily Aggregator initialized');
    console.log('Will run daily at ' + config.schedule.aggregatorHour + ':' + config.schedule.aggregatorMinute.toString().padStart(2, '0'));

    if (forceRun) {
        console.log('\n--force flag detected, running immediately...');
        await runDaily();
        console.log('\nForced run complete. Exiting...\n');
        process.exit(0);
    }

    // Check if we should run immediately (if it's the scheduled time)
    const now = new Date();
    if (now.getHours() === config.schedule.aggregatorHour && now.getMinutes() < config.schedule.aggregatorMinute + 5) {
        console.log('Running immediately as it is the scheduled time');
        await runDaily();
    }

    // Schedule next run
    function scheduleNext() {
        const delay = getTimeUntilNextRun();
        const hours = Math.floor(delay / 1000 / 60 / 60);
        const minutes = Math.floor((delay / 1000 / 60) % 60);

        console.log('Next run scheduled in ' + hours + ' hours ' + minutes + ' minutes');

        setTimeout(async () => {
            await runDaily();
            scheduleNext();
        }, delay);
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
    process.exit(0);
});

process.on('SIGTERM', () => {
    console.log('\nReceived SIGTERM, shutting down gracefully...');
    process.exit(0);
});
