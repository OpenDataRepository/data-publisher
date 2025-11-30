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
    // Database Configuration
    // Update these values based on your app/config/parameters.yml
    database: {
        host: 'localhost',
        user: 'odr_prod_20251103',
        password: 'alsdkfjhsuaoijofslkafsd',
        database: 'odr_prod_20251103'
    },

    // Redis Configuration
    redis: {
        host: '127.0.0.1',
        port: 6379,
        // Must match memcached_key_prefix from app/config/parameters.yml
        prefix: 'odr_rruff_net.'
    },

    // API Configuration
    api: {
        // Your ODR domain (without https://)
        host: 'beta.rruff.net',

        // API version (v3, v4, v5, etc.)
        version: 'v5',

        // API credentials
        // IMPORTANT: Update these with your actual API user credentials
        // This user should have appropriate permissions to create statistics entries
        user: 'rruff-prod-api@odr.io',
        key: 'XUY*bkd.rzd4qta6pcd'
    },

    // Helper function to get versioned API endpoint
    getEndpoint: function(endpoint) {
        const version = this.api.version;
        const endpoints = {
            login: `/odr_rruff/api/${version}/token`,
            storeHourly: `/odr_rruff/api/${version}/statistics/store_hourly`,
            getBots: `/odr_rruff/api/${version}/statistics/get_bots`,
            aggregateDaily: `/odr_rruff/api/${version}/statistics/aggregate_daily`,
            cleanupHourly: `/odr_rruff/api/${version}/statistics/cleanup_hourly`,
            updateBots: `/odr_rruff/api/${version}/statistics/update_bots`
        };
        return endpoints[endpoint];
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
