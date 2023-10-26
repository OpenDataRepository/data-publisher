#!/bin/bash

ps auxww |grep php |grep export | awk '{print $2}' | xargs kill

# Improved CSV Exports
cd /home/rruff/data-publisher
php app/console odr_csv_export:worker_express >> app/logs/export_worker_express.log 2>&1 &
php app/console odr_csv_export:worker_express >> app/logs/export_worker_express.log 2>&1 &
php app/console odr_csv_export:worker_express >> app/logs/export_worker_express.log 2>&1 &
php app/console odr_csv_export:express_finalize >> app/logs/export_express_finalize.log 2>&1 &


