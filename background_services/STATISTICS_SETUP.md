# Statistics System Setup Guide

This guide explains how to generate mock statistics data and use the force flags for immediate execution of statistics services.

## Overview

The statistics system consists of three main components:

1. **Statistics Processor** (`statistics_processor.js`) - Processes hourly statistics from Redis logs
2. **Daily Aggregator** (`statistics_daily_aggregator.js`) - Aggregates hourly data into daily summaries
3. **Bot Updater** (`statistics_bot_updater.js`) - Updates bot/crawler detection patterns

## Prerequisites

### 1. Install PHP Redis Extension

The statistics system requires the PHP Redis extension for the standalone logging endpoints (`web/log_view.php`). This extension must be installed at the system level.

#### Ubuntu/Debian:
```bash
sudo apt-get update
sudo apt-get install php-redis
sudo service apache2 restart  # or nginx/php-fpm
```

#### CentOS/RHEL/Fedora:
```bash
sudo yum install php-pecl-redis
sudo systemctl restart httpd  # or nginx/php-fpm
```

#### macOS (with Homebrew):
```bash
brew install php-redis
brew services restart php
```

#### Using PECL (any system):
```bash
pecl install redis
# Add "extension=redis.so" to your php.ini
sudo service apache2 restart  # or appropriate web server
```

#### Verify Installation:
```bash
# Command line check
php -m | grep redis

# Or create a test file in your web root
echo "<?php phpinfo();" > /path/to/web/phpinfo.php
# Access via browser and look for "redis" section
```

### 2. Install Node.js Dependencies

```bash
cd background_services
npm install
```

This will install all required dependencies including `mysql2` for database access.

### 3. Configure Database Connection

Edit `generate_mock_statistics.js` and update the database configuration:

```javascript
const dbConfig = {
    host: 'localhost',
    user: 'root',
    password: 'your_db_password',  // UPDATE THIS
    database: 'odr'                 // UPDATE THIS if needed
};
```

You can find your database credentials in `app/config/parameters.yml`.

### 4. Configure API Access

Edit `statistics_config.js` and update the API credentials:

```javascript
api: {
    host: 'localhost',              // Your ODR domain (without https://)
    user: 'admin',                  // UPDATE THIS
    key: 'your_api_key_here'        // UPDATE THIS
}
```

## Generating Mock Data

The mock data generator creates 3 years of realistic statistics data from today's date going backward.

### Data Generated

- **Hourly Statistics**: ~100,000-150,000 records per year
- **Daily Statistics**: ~50,000-75,000 records per year
- **Total Records**: ~450,000-675,000 records for 3 years

### Features

The generated data includes:
- Realistic traffic patterns (higher during business hours, lower on weekends)
- Geographic distribution across multiple countries (US, GB, DE, FR, CA, AU, JP, CN, IN, BR)
- US state/province data for US traffic
- Bot vs. human traffic (approximately 30% bot traffic)
- Various entity types: datatypes, datarecords, and files
- View counts, download counts, and search result view counts

### Running the Generator

```bash
node generate_mock_statistics.js
```

**Important**: This is a one-time operation. The script will:
1. Connect to your MySQL database
2. Generate 3 years of data day by day
3. Insert hourly and daily statistics in batches
4. Display progress every 30 days
5. Complete in approximately 5-10 minutes depending on your system

**Warning**: This will insert a large amount of data into your database. Make sure you have:
- Sufficient disk space (approximately 500MB-1GB for the statistics tables)
- Database backup before running (recommended)

## Force Execution Flags

All statistics services now support a `--force` flag for immediate execution, bypassing the normal schedule.

### Statistics Processor (Hourly)

Normally runs every hour at the configured minute (default: 5 minutes past the hour).

Force immediate execution:
```bash
node statistics_processor.js --force
```

This will:
- Process all pending Redis log entries
- Aggregate them into hourly statistics
- Store via API
- Exit immediately (does not start the scheduler)

### Daily Aggregator

Normally runs once per day at 2:00 AM.

Force immediate execution:
```bash
node statistics_daily_aggregator.js --force
```

This will:
- Aggregate yesterday's hourly statistics into daily summaries
- Clean up hourly data older than 2 years
- Exit immediately (does not start the scheduler)

### Bot Updater

Normally runs weekly on Sunday at 3:00 AM.

Force immediate execution:
```bash
node statistics_bot_updater.js --force
```

This will:
- Fetch the latest bot patterns from GitHub
- Update the StatisticsBotList table
- Exit immediately (does not start the scheduler)

## Typical Workflow

### Initial Setup (First Time)

1. Install dependencies:
   ```bash
   npm install
   ```

2. Configure database and API credentials (see Prerequisites above)

3. Generate mock data:
   ```bash
   node generate_mock_statistics.js
   ```

4. Force bot list update (to populate bot detection patterns):
   ```bash
   node statistics_bot_updater.js --force
   ```

5. Start the services normally:
   ```bash
   # Start all services (from parent directory)
   bash start_jobs.sh

   # Or start individually for development
   node statistics_processor.js
   node statistics_daily_aggregator.js
   node statistics_bot_updater.js
   ```

### Testing Dashboard Data

After generating mock data, you can immediately view it in your statistics dashboards without waiting for scheduled runs.

If you need to reprocess data:

```bash
# Force process any pending logs
node statistics_processor.js --force

# Force aggregate into daily summaries
node statistics_daily_aggregator.js --force
```

## Database Tables

The mock data populates two main tables:

### odr_statistics_hourly
- `hour_timestamp`: Timestamp rounded to the hour
- `view_count`: Number of views
- `download_count`: Number of downloads
- `search_result_view_count`: Views from search results
- `country`: ISO country code
- `province`: State/province code (US only)
- `is_bot`: Boolean flag for bot traffic
- Foreign keys: `datatype_id`, `datarecord_id`, `file_id`

### odr_statistics_daily
- `day_date`: Date (no time component)
- Same metrics as hourly, aggregated by day
- Same foreign keys and geographic fields

## Troubleshooting

### Database Connection Issues

If you get "Access denied" errors:
1. Check your database credentials in `generate_mock_statistics.js`
2. Ensure the database user has INSERT permissions on the statistics tables
3. Verify the database name matches your ODR installation

### API Authentication Failures

If force execution shows authentication errors:
1. Check `statistics_config.js` has correct API credentials
2. Verify the API user has statistics permissions
3. Test login endpoint manually: `curl -X POST https://localhost/api/login_check -d '{"username":"admin","password":"your_key"}'`

### Large Data Volume

If the mock data generator is too slow:
1. Reduce the date range in the script (change from 3 years to 1 year)
2. Reduce `entriesPerHour` variable to generate fewer records
3. Increase batch sizes for faster inserts (change `batchSize` parameters)

### Memory Issues

If Node.js runs out of memory:
1. Increase Node.js memory limit: `node --max-old-space-size=4096 generate_mock_statistics.js`
2. Reduce batch sizes to process smaller chunks

## Sample Data Characteristics

### Geographic Distribution
- USA: ~40% of traffic
- Europe (GB, DE, FR): ~30% of traffic
- Asia (JP, CN, IN): ~15% of traffic
- Other (CA, AU, BR): ~10% of traffic
- Unknown/NULL: ~5% of traffic

### Traffic Patterns
- Business hours (9 AM - 5 PM weekdays): 60% of daily traffic
- Evening hours (5 PM - 9 PM): 25% of daily traffic
- Night/early morning: 10% of daily traffic
- Weekend: ~30% lower than weekday average

### Bot vs. Human
- Bot traffic: ~30% of total
- Human traffic: ~70% of total
- Bots have consistent traffic patterns (no time-of-day variation)
- Humans have realistic time-of-day and day-of-week patterns

## Notes

- The system user (ID 1) is used for `created_by` and `updated_by` fields
- Sample entity IDs are used for datatypes, datarecords, and files
- You may want to customize these IDs to match your actual entities
- NULL values represent site-wide statistics not tied to specific entities
