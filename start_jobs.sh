#!/bin/bash

#export ODR_PATH=/home/planetary
# export XDEBUG_CONFIG="idekey=phpstorm_xdebug"
#cd $ODR_PATH

cd /home/odr/data-publisher

php app/console odr_record:migrate >> app/logs/migrate.log 2>&1 &
php app/console odr_record:mass_edit >> app/logs/mass_edit.log 2>&1 &

php app/console odr_crypto:worker >> app/logs/crypto_worker.log 2>&1 &
#php app/console odr_crypto:worker >> app/logs/crypto_worker_2.log 2>&1 &    # seems to screw up when second job is active

php app/console odr_datatype:clone_and_link_datatype >> app/logs/clone_and_link_datatype.log 2>&1 &
php app/console odr_datatype:clone_master >> app/logs/datatype_create.log 2>&1 &
#php app/console odr_theme:clone >> app/logs/theme_create.log 2>&1 &    # theme clone requests don't go through background jobs currently

php app/console odr_csv_import:validate >> app/logs/csv_import_validate.log 2>&1 &
php app/console odr_csv_import:worker >> app/logs/csv_import.log 2>&1 &
#php app/console odr_csv_import:worker >> app/logs/csv_import_2.log 2>&1 &    # seems to screw up when second job is active

#php app/console odr_csv_export:worker >> app/logs/csv_export_worker.log 2>&1 &
##php app/console odr_csv_export:worker >> app/logs/csv_export_worker_2.log 2>&1 &    # seems to screw up when second job is active
#php app/console odr_csv_export:finalize >> app/logs/csv_export_finalize.log 2>&1 &

# temporary kludge to flush cache for clone processes
php app/console odr_datatype:clone_monitor >> app/logs/clone_monitor.log 2>&1 &
php app/console odr_datatype:clone_and_link_monitor >> app/logs/clone_and_link_monitor.log 2>&1 &
#php app/console odr_datatype:clone_datatype_preloader_monitor >> app/logs/clone_datatype_preloader_monitor.log 2>&1 &

php app/console odr_datatype:sync_template >> app/logs/sync_template.log 2>&1 &

# Improved CSV Exports
php app/console odr_csv_export:worker_express >> app/logs/export_worker_express.log 2>&1 &
php app/console odr_csv_export:express_finalize >> app/logs/export_express_finalize.log 2>&1 &

cd /home/odr/data-publisher/background_services
node graph_renderer_daemon.js >> ../app/logs/graph_preview_1.log 2>&1 &
node graph_renderer_daemon.js >> ../app/logs/graph_preview_2.log 2>&1 &
node graph_renderer_daemon.js >> ../app/logs/graph_preview_3.log 2>&1 &
node seed_elastic_record_daemon.js >> ../app/logs/seed_elastic_record_daemon.log 2>&1 &
node record_precache_daemon.js >> ../app/logs/record_precache_daemon_1.log 2>&1 &
node record_precache_daemon.js >> ../app/logs/record_precache_daemon_2.log 2>&1 &

