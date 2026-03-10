#!/bin/bash
#
export PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
export PUPPETEER_EXECUTABLE_PATH=$(which chromium)

cd /home/nate/data-publisher/background_services

node graph_renderer_daemon.js >> ../app/logs/graph_preview_1.log 2>&1 &
node graph_renderer_daemon.js >> ../app/logs/graph_preview_2.log 2>&1 &
node graph_renderer_daemon.js >> ../app/logs/graph_preview_3.log 2>&1 &


#node seed_elastic_record_daemon.js >> ../app/logs/seed_elastic_record_daemon.log 2>&1 &
#node record_precache_daemon.js >> ../app/logs/record_precache_daemon_1.log 2>&1 &
#node record_precache_daemon.js >> ../app/logs/record_precache_daemon_2.log 2>&1 &
#node statistics_processor.js >> ../app/logs/statistics_processor.log 2>&1 &
#node statistics_daily_aggregator.js >> ../app/logs/statistics_daily_aggregator.log 2>&1 &

