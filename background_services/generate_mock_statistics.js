/**
 * Open Data Repository Data Publisher
 * Mock Statistics Data Generator
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Generates 3 years of mock statistics data for testing dashboards.
 * Run this script once to populate historical statistics data.
 *
 * Usage: node generate_mock_statistics.js
 */

const mysql = require('mysql2/promise');
const config = require('./statistics_config');

// Sample data pools for realistic statistics (geographic data)
const countries = ['US', 'GB', 'DE', 'FR', 'CA', 'AU', 'JP', 'CN', 'IN', 'BR', null];
const usProvinces = ['CA', 'NY', 'TX', 'FL', 'IL', 'PA', 'OH', 'GA', 'NC', 'MI'];

// These will be populated from the database
let datatypeIds = [];
let datarecordIds = [];
let fileIds = [];

/**
 * Load valid IDs from the database to satisfy foreign key constraints
 */
async function loadValidIds(connection) {
    console.log('Loading valid IDs from database...');

    // Get a sample of datatype IDs (limit to avoid too much data)
    const [datatypes] = await connection.query(
        'SELECT id FROM odr_data_type WHERE deletedAt IS NULL LIMIT 20'
    );
    datatypeIds = datatypes.map(r => r.id);
    datatypeIds.push(null); // Include null for site-wide stats
    console.log(`  Found ${datatypeIds.length - 1} datatypes`);

    // Get a sample of datarecord IDs
    const [datarecords] = await connection.query(
        'SELECT id FROM odr_data_record WHERE deletedAt IS NULL LIMIT 100'
    );
    datarecordIds = datarecords.map(r => r.id);
    datarecordIds.push(null); // Include null for datatype-level stats
    console.log(`  Found ${datarecordIds.length - 1} datarecords`);

    // Get a sample of file IDs
    const [files] = await connection.query(
        'SELECT id FROM odr_file WHERE deletedAt IS NULL LIMIT 50'
    );
    fileIds = files.map(r => r.id);
    fileIds.push(null); // Include null for non-file stats
    console.log(`  Found ${fileIds.length - 1} files`);

    // Validate we have enough data to generate statistics
    if (datatypeIds.length <= 1) {
        throw new Error('No datatypes found in database. Cannot generate mock statistics.');
    }

    console.log('Valid IDs loaded successfully\n');
}

/**
 * Generate a random integer between min and max (inclusive)
 */
function randomInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

/**
 * Pick a random element from an array
 */
function randomPick(array) {
    return array[Math.floor(Math.random() * array.length)];
}

/**
 * Generate realistic view/download counts based on hour and day
 * - Higher traffic during business hours (9 AM - 5 PM)
 * - Lower traffic on weekends
 * - Lower bot traffic overall
 */
function generateCounts(date, isBot) {
    const hour = date.getHours();
    const dayOfWeek = date.getDay();
    const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
    const isBusinessHours = hour >= 9 && hour <= 17;

    let baseViewCount, baseDownloadCount;

    if (isBot) {
        // Bots have steady traffic, less variation by time
        baseViewCount = randomInt(5, 30);
        baseDownloadCount = randomInt(0, 5);
    } else {
        // Human traffic varies by time
        if (isBusinessHours && !isWeekend) {
            baseViewCount = randomInt(50, 200);
            baseDownloadCount = randomInt(5, 30);
        } else if (!isWeekend) {
            baseViewCount = randomInt(20, 80);
            baseDownloadCount = randomInt(2, 15);
        } else {
            baseViewCount = randomInt(10, 40);
            baseDownloadCount = randomInt(1, 8);
        }
    }

    // Search result views are ~30% of total views
    const searchResultViewCount = Math.floor(baseViewCount * (randomInt(20, 40) / 100));

    return {
        viewCount: baseViewCount,
        downloadCount: baseDownloadCount,
        searchResultViewCount
    };
}

/**
 * Generate hourly statistics for a given hour
 */
function generateHourlyStats(hourDate, isBot) {
    const country = randomPick(countries);
    const province = (country === 'US') ? randomPick(usProvinces) : null;
    const counts = generateCounts(hourDate, isBot);

    // Pick a datatype, and optionally a related datarecord/file
    const datatypeId = randomPick(datatypeIds);

    // Only assign datarecord if we have a datatype (makes logical sense)
    const datarecordId = datatypeId ? randomPick(datarecordIds) : null;

    // Only assign file for download-related stats (randomly ~40% of the time)
    const fileId = (Math.random() < 0.4) ? randomPick(fileIds) : null;

    return {
        hour_timestamp: hourDate,
        data_type_id: datatypeId,
        data_record_id: datarecordId,
        file_id: fileId,
        country,
        province,
        is_bot: isBot,
        view_count: counts.viewCount,
        download_count: counts.downloadCount,
        search_result_view_count: counts.searchResultViewCount,
        created: new Date(),
        updated: new Date(),
        createdBy: null,
        updatedBy: null
    };
}

/**
 * Aggregate hourly stats into daily totals
 */
function aggregateDailyStats(hourlyStatsForDay) {
    const aggregated = {};

    for (const hourStat of hourlyStatsForDay) {
        const key = [
            hourStat.data_type_id || 'null',
            hourStat.data_record_id || 'null',
            hourStat.file_id || 'null',
            hourStat.country || 'null',
            hourStat.province || 'null',
            hourStat.is_bot ? '1' : '0'
        ].join('|');

        if (!aggregated[key]) {
            aggregated[key] = {
                data_type_id: hourStat.data_type_id,
                data_record_id: hourStat.data_record_id,
                file_id: hourStat.file_id,
                country: hourStat.country,
                province: hourStat.province,
                is_bot: hourStat.is_bot,
                view_count: 0,
                download_count: 0,
                search_result_view_count: 0
            };
        }

        aggregated[key].view_count += hourStat.view_count;
        aggregated[key].download_count += hourStat.download_count;
        aggregated[key].search_result_view_count += hourStat.search_result_view_count;
    }

    return Object.values(aggregated);
}

/**
 * Insert hourly statistics in batches
 */
async function insertHourlyStats(connection, stats, batchSize = 100) {
    if (stats.length === 0) return;

    const sql = `
        INSERT INTO odr_statistics_hourly
        (hour_timestamp, data_type_id, data_record_id, file_id, country, province,
         is_bot, view_count, download_count, search_result_view_count,
         created, updated, createdBy, updatedBy)
        VALUES ?
    `;

    for (let i = 0; i < stats.length; i += batchSize) {
        const batch = stats.slice(i, i + batchSize);
        const values = batch.map(s => [
            s.hour_timestamp,
            s.data_type_id,
            s.data_record_id,
            s.file_id,
            s.country,
            s.province,
            s.is_bot ? 1 : 0,
            s.view_count,
            s.download_count,
            s.search_result_view_count,
            s.created,
            s.updated,
            s.createdBy,
            s.updatedBy
        ]);

        await connection.query(sql, [values]);
        console.log(`  Inserted ${batch.length} hourly stats (batch ${Math.floor(i / batchSize) + 1})`);
    }
}

/**
 * Insert daily statistics in batches
 */
async function insertDailyStats(connection, dayDate, stats, batchSize = 100) {
    if (stats.length === 0) return;

    const sql = `
        INSERT INTO odr_statistics_daily
        (day_date, data_type_id, data_record_id, file_id, country, province,
         is_bot, view_count, download_count, search_result_view_count,
         created, updated, createdBy, updatedBy)
        VALUES ?
    `;

    for (let i = 0; i < stats.length; i += batchSize) {
        const batch = stats.slice(i, i + batchSize);
        const values = batch.map(s => [
            dayDate,
            s.data_type_id,
            s.data_record_id,
            s.file_id,
            s.country,
            s.province,
            s.is_bot ? 1 : 0,
            s.view_count,
            s.download_count,
            s.search_result_view_count,
            new Date(),
            new Date(),
            null,
            null
        ]);

        await connection.query(sql, [values]);
        console.log(`  Inserted ${batch.length} daily stats (batch ${Math.floor(i / batchSize) + 1})`);
    }
}

/**
 * Main generation function
 */
async function generateMockData() {
    console.log('========================================');
    console.log('Mock Statistics Data Generator');
    console.log('========================================\n');

    let connection;

    try {
        // Connect to database
        console.log('Connecting to database...');
        connection = await mysql.createConnection(config.database);
        console.log('Connected successfully\n');

        // Load valid IDs from database to satisfy foreign key constraints
        await loadValidIds(connection);

        // Calculate date range (3 years back from today)
        const endDate = new Date();
        endDate.setHours(0, 0, 0, 0);

        const startDate = new Date(endDate);
        startDate.setFullYear(startDate.getFullYear() - 3);

        console.log('Generating statistics data:');
        console.log('  Start date:', startDate.toISOString().split('T')[0]);
        console.log('  End date:', endDate.toISOString().split('T')[0]);
        console.log('  Duration: 3 years\n');

        let totalHourlyStats = 0;
        let totalDailyStats = 0;
        let daysProcessed = 0;

        // Generate data day by day
        const currentDate = new Date(startDate);

        while (currentDate < endDate) {
            const dateStr = currentDate.toISOString().split('T')[0];
            console.log(`Processing ${dateStr}...`);

            const hourlyStatsForDay = [];

            // Generate hourly stats for each hour of the day
            for (let hour = 0; hour < 24; hour++) {
                const hourDate = new Date(currentDate);
                hourDate.setHours(hour, 0, 0, 0);

                // Generate multiple entries per hour with different combinations
                // of datatype/record/file/country/bot status
                const entriesPerHour = randomInt(5, 15);

                for (let i = 0; i < entriesPerHour; i++) {
                    const isBot = Math.random() < 0.3; // 30% bot traffic
                    const stat = generateHourlyStats(hourDate, isBot);
                    hourlyStatsForDay.push(stat);
                }
            }

            // Insert hourly stats
            await insertHourlyStats(connection, hourlyStatsForDay);
            totalHourlyStats += hourlyStatsForDay.length;

            // Aggregate and insert daily stats
            const dailyStats = aggregateDailyStats(hourlyStatsForDay);
            await insertDailyStats(connection, currentDate, dailyStats);
            totalDailyStats += dailyStats.length;

            daysProcessed++;

            // Move to next day
            currentDate.setDate(currentDate.getDate() + 1);

            // Progress update every 30 days
            if (daysProcessed % 30 === 0) {
                console.log(`\n  Progress: ${daysProcessed} days processed`);
                console.log(`  Hourly stats: ${totalHourlyStats.toLocaleString()}`);
                console.log(`  Daily stats: ${totalDailyStats.toLocaleString()}\n`);
            }
        }

        console.log('\n========================================');
        console.log('Data Generation Complete!');
        console.log('========================================');
        console.log(`Total days processed: ${daysProcessed}`);
        console.log(`Total hourly statistics: ${totalHourlyStats.toLocaleString()}`);
        console.log(`Total daily statistics: ${totalDailyStats.toLocaleString()}`);
        console.log('========================================\n');

    } catch (error) {
        console.error('Error generating mock data:', error.message);
        console.error(error.stack);
        process.exit(1);
    } finally {
        if (connection) {
            await connection.end();
            console.log('Database connection closed');
        }
    }
}

// Run the generator
generateMockData();
