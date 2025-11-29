/**
 * Open Data Repository Data Publisher
 * Statistics Bot List Updater (Node.js Background Service)
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Updates bot/crawler user agent patterns:
 * - Runs once per week on Sunday at 3 AM
 * - Fetches bot list from GitHub
 * - Updates StatisticsBotList table via API
 * - Marks new patterns as active, old patterns as inactive
 */
const https = require('https');
const config = require('./statistics_config');
let authToken = null;


/**
 * Fetch bot list from GitHub
 */
async function fetchBotList() {
    return new Promise((resolve, reject) => {
        const url = new URL(config.botListUrl);

        const options = {
            hostname: url.hostname,
            port: 443,
            path: url.pathname,
            method: 'GET',
            headers: {
                'User-Agent': 'ODR-Statistics-Bot-Updater/1.0'
            }
        };

        const req = https.request(options, (res) => {
            let data = '';

            res.on('data', (chunk) => {
                data += chunk;
            });

            res.on('end', () => {
                try {
                    const bots = JSON.parse(data);
                    resolve(bots);
                } catch (e) {
                    reject(new Error('Failed to parse bot list JSON: ' + e.message));
                }
            });
        });

        req.on('error', (e) => {
            reject(e);
        });

        req.end();
    });
}

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
                    console.error('Raw API response (first 500 chars):', responseData.substring(0, 500));
                    console.error('HTTP Status:', res.statusCode);
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
 * Update bot patterns
 */
async function updateBotPatterns() {
    console.log('\n========================================');
    console.log('Bot List Updater Start:', new Date().toISOString());
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

        // Fetch bot list from GitHub
        console.log('Fetching bot list from GitHub...');
        console.log('URL:', config.botListUrl);

        const botList = await fetchBotList();

        if (!Array.isArray(botList)) {
            console.error('Bot list is not an array');
            return;
        }

        console.log('Fetched ' + botList.length + ' bot patterns from GitHub');

        // Process bot list - only use the regex patterns, not the full instance strings
        const patterns = [];

        for (let i = 0; i < botList.length; i++) {
            const bot = botList[i];

            // Each entry has 'pattern' (regex) and optionally 'instances' (full user-agent strings)
            // We only need the pattern for matching - instances are too long for storage
            if (bot.pattern) {
                patterns.push({
                    pattern: bot.pattern,
                    bot_name: bot.pattern,  // Use pattern as the name
                    is_active: true
                });
            }
        }

        console.log('Processed ' + patterns.length + ' bot patterns');

        // Send to API for storage
        console.log('Sending patterns to API...');

        const response = await apiCall(config.getEndpoint('updateBots'), {
            patterns: patterns
        });

        if (response && response.success) {
            console.log('Successfully updated bot patterns');
            console.log('Added: ' + (response.added || 0));
            console.log('Updated: ' + (response.updated || 0));
            console.log('Deactivated: ' + (response.deactivated || 0));
        } else {
            console.error('Bot pattern update failed:', response.message || 'Unknown error');
            console.error('Full response:', JSON.stringify(response, null, 2));
        }

    } catch (e) {
        console.error('Error updating bot patterns:', e.message);
        console.error(e.stack);
    }

    console.log('\n========================================');
    console.log('Bot List Updater Complete:', new Date().toISOString());
    console.log('========================================\n');
}

/**
 * Calculate milliseconds until next run day at configured time
 */
function getTimeUntilNextRun() {
    const now = new Date();
    const nextRun = new Date(now);

    // Calculate days until next run day
    const currentDay = now.getDay();
    let daysUntilRun = config.schedule.botUpdaterDay - currentDay;

    if (daysUntilRun < 0) {
        daysUntilRun += 7;
    } else if (daysUntilRun === 0) {
        // It's the run day - check if we've passed the run time
        nextRun.setHours(config.schedule.botUpdaterHour, config.schedule.botUpdaterMinute, 0, 0);

        if (nextRun <= now) {
            // Already passed, schedule for next week
            daysUntilRun = 7;
        }
    }

    nextRun.setDate(now.getDate() + daysUntilRun);
    nextRun.setHours(config.schedule.botUpdaterHour, config.schedule.botUpdaterMinute, 0, 0);

    return nextRun - now;
}

/**
 * Main execution
 */
async function main() {
    // Check for --force flag
    const forceRun = process.argv.includes('--force');

    console.log('Statistics Bot List Updater initialized');
    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    console.log('Will run weekly on ' + days[config.schedule.botUpdaterDay] + ' at ' +
                config.schedule.botUpdaterHour + ':' + config.schedule.botUpdaterMinute.toString().padStart(2, '0'));

    if (forceRun) {
        console.log('\n--force flag detected, running immediately...');
        await updateBotPatterns();
        console.log('\nForced run complete. Exiting...\n');
        process.exit(0);
    }

    // Check if we should run immediately (if it's the run day at the scheduled time)
    const now = new Date();
    if (now.getDay() === config.schedule.botUpdaterDay && now.getHours() === config.schedule.botUpdaterHour &&
        now.getMinutes() < config.schedule.botUpdaterMinute + 5) {
        console.log('Running immediately as it is the scheduled time');
        await updateBotPatterns();
    }

    // Schedule next run
    function scheduleNext() {
        const delay = getTimeUntilNextRun();
        const days = Math.floor(delay / 1000 / 60 / 60 / 24);
        const hours = Math.floor((delay / 1000 / 60 / 60) % 24);
        const minutes = Math.floor((delay / 1000 / 60) % 60);

        console.log('Next run scheduled in ' + days + ' days, ' + hours + ' hours, ' + minutes + ' minutes');

        setTimeout(async () => {
            await updateBotPatterns();
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
