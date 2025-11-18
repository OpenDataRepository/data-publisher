/**
 * Open Data Repository Data Publisher
 * Statistics Services Configuration
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Centralized configuration for all statistics background services
 */
module.exports = {
    // Redis Configuration
    redis: {
        host: '127.0.0.1',
        port: 6379,
        // Must match memcached_key_prefix from app/config/parameters.yml
        prefix: 'odr_'
    },

    // API Configuration
    api: {
        // Your ODR domain (without https://)
        host: 'localhost',

        // API credentials
        // IMPORTANT: Update these with your actual API user credentials
        // This user should have appropriate permissions to create statistics entries
        user: 'admin',
        key: 'your_api_key_here',

        // API endpoints
        endpoints: {
            login: '/api/login_check',
            storeHourly: '/api/statistics/store_hourly',
            getBots: '/api/statistics/get_bots',
            aggregateDaily: '/api/statistics/aggregate_daily',
            cleanupHourly: '/api/statistics/cleanup_hourly',
            updateBots: '/api/statistics/update_bots'
        }
    },

    // Bot List Source
    botListUrl: 'https://raw.githubusercontent.com/monperrus/crawler-user-agents/master/crawler-user-agents.json',

    // Schedule Configuration
    schedule: {
        // Hourly processor: runs every hour at N minutes past the hour
        processorMinute: 5,

        // Daily aggregator: runs daily at this hour (24-hour format)
        aggregatorHour: 2,
        aggregatorMinute: 0,

        // Bot updater: day of week (0 = Sunday, 6 = Saturday)
        botUpdaterDay: 0,  // Sunday
        botUpdaterHour: 3,
        botUpdaterMinute: 0
    },

    // Data Retention
    retention: {
        // Hourly data retention in years (will be converted to cutoff date)
        hourlyYears: 2
    }
};
