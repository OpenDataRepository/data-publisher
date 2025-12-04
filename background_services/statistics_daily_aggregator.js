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
 * Aggregate hourly statistics into daily summaries for today
 * Runs frequently to provide near real-time daily statistics
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

        // Aggregate today's data for near real-time statistics
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const dateStr = today.toISOString().split('T')[0];  // YYYY-MM-DD format

        console.log('Aggregating statistics for date:', dateStr);

        // Call API to perform aggregation
        const response = await apiCall(config.getEndpoint('aggregateDaily'), {
            date: dateStr
        });

        if (response && response.success) {
            console.log('Successfully aggregated ' + (response.total || 0) + ' daily statistics entries');
            console.log('  - Created: ' + (response.count || 0));
            console.log('  - Updated: ' + (response.updated || 0));
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
 * Main execution
 */
async function main() {
    // Check for --force flag
    const forceRun = process.argv.includes('--force');
    const cleanupOnly = process.argv.includes('--cleanup');

    const intervalMinutes = config.schedule.aggregatorIntervalMinutes || 3;
    const intervalMs = intervalMinutes * 60 * 1000;

    console.log('Statistics Daily Aggregator initialized');
    console.log('Will aggregate every ' + intervalMinutes + ' minute(s) for near real-time statistics');

    if (forceRun) {
        console.log('\n--force flag detected, running immediately...');
        if (cleanupOnly) {
            await cleanupOldData();
        } else {
            await aggregateDaily();
        }
        console.log('\nForced run complete. Exiting...\n');
        process.exit(0);
    }

    // Run immediately on startup
    await aggregateDaily();

    // Schedule recurring runs at the configured interval
    function scheduleNext() {
        console.log('Next run scheduled in ' + intervalMinutes + ' minute(s)');

        setTimeout(async () => {
            await aggregateDaily();
            scheduleNext();
        }, intervalMs);
    }

    scheduleNext();

    // Schedule cleanup to run once per day at 2 AM
    function scheduleCleanup() {
        const now = new Date();
        const nextCleanup = new Date(now);
        nextCleanup.setHours(2, 0, 0, 0);

        // If we've passed 2 AM today, schedule for tomorrow
        if (nextCleanup <= now) {
            nextCleanup.setDate(nextCleanup.getDate() + 1);
        }

        const delay = nextCleanup - now;
        const hours = Math.floor(delay / 1000 / 60 / 60);

        console.log('Daily cleanup scheduled in ' + hours + ' hours (at 2:00 AM)');

        setTimeout(async () => {
            await cleanupOldData();
            scheduleCleanup();  // Reschedule for next day
        }, delay);
    }

    scheduleCleanup();
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
