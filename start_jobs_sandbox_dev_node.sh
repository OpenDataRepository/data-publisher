#!/bin/bash
#
#
#
# Puppeteer ships no arm64 Chromium, and the only system Chromium on Ubuntu is the
# snap (which Puppeteer can't drive). Use Playwright's arm64 Chromium instead, and
# work around Ubuntu 24.04's unprivileged-user-namespace restriction with --no-sandbox.
# See data-publisher/background_services/DEV_SETUP_ARM.md for details.
export PUPPETEER_EXECUTABLE_PATH="$(ls -d "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux/chrome 2>/dev/null | sort -V | tail -1)"
export ODR_CHROME_NO_SANDBOX=1
export ODR_CHROME_IGNORE_CERT=1            # dev boxes serve odr.io with an untrusted (self-signed/staging) cert

cd /home/nate/data-publisher/background_services

nodemon graph_renderer_daemon.js >> ../app/logs/graph_preview_1.log 2>&1 &
#nodemon graph_renderer_daemon.js >> ../app/logs/graph_preview_2.log 2>&1 &
#nodemon graph_renderer_daemon.js >> ../app/logs/graph_preview_3.log 2>&1 &
nodemon seed_elastic_record_daemon.js >> ../app/logs/seed_elastic_record_daemon.log 2>&1 &
nodemon record_precache_daemon.js >> ../app/logs/record_precache_daemon_1.log 2>&1 &
nodemon record_precache_daemon.js >> ../app/logs/record_precache_daemon_2.log 2>&1 &
nodemon statistics_processor.js >> ../app/logs/statistics_processor.log 2>&1 &
nodemon statistics_daily_aggregator.js >> ../app/logs/statistics_daily_aggregator.log 2>&1 &

