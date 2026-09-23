#!/bin/bash

ps auxww |grep php |grep export | awk '{print $2}' | xargs kill

# Improved CSV Exports
cd /home/odr/data-publisher
php app/console odr_csv_export:monitor >> app/logs/export_monitor.log 2>&1 &
php app/console odr_csv_export:worker_express >> app/logs/export_worker_express_1.log 2>&1 &
php app/console odr_csv_export:worker_express >> app/logs/export_worker_express_2.log 2>&1 &
php app/console odr_csv_export:worker_express >> app/logs/export_worker_express_3.log 2>&1 &
php app/console odr_csv_export:express_finalize >> app/logs/export_express_finalize.log 2>&1 &


