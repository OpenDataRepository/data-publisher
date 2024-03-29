#!/bin/bash

#export ODR_PATH=/home/planetary

#cd $ODR_PATH
php app/console --env=dev odr_record:migrate >> app/logs/migrate.log 2>&1 &
php app/console --env=dev odr_record:mass_edit >> app/logs/mass_edit.log 2>&1 &

php app/console --env=dev odr_crypto:worker >> app/logs/crypto_worker.log 2>&1 &
#php app/console --env=dev odr_crypto:worker >> app/logs/crypto_worker_2.log 2>&1 &    # seems to screw up when second job is active

#php app/console --env=dev odr_theme:clone >> app/logs/theme_create.log 2>&1 &    # theme clone requests don't go through background jobs currently

php app/console --env=dev odr_csv_import:validate >> app/logs/csv_import_validate.log 2>&1 &
php app/console --env=dev odr_csv_import:worker >> app/logs/csv_import.log 2>&1 &
#php app/console --env=dev odr_csv_import:worker >> app/logs/csv_import_2.log 2>&1 &    # seems to screw up when second job is active

php app/console --env=dev odr_csv_export:start >> app/logs/csv_export_start.log 2>&1 &
php app/console --env=dev odr_csv_export:worker >> app/logs/csv_export_worker.log 2>&1 &
#php app/console --env=dev odr_csv_export:worker >> app/logs/csv_export_worker_2.log 2>&1 &    # seems to screw up when second job is active
php app/console --env=dev odr_csv_export:finalize >> app/logs/csv_export_finalize.log 2>&1 &


php app/console --env=dev odr_datatype:clone_and_link_datatype >> app/logs/clone_and_link_datatype.log 2>&1 &
# php app/console --env=dev odr_datatype:clone_master >> app/logs/datatype_create.log 2>&1 &

export XDEBUG_CONFIG="idekey=phpstorm_xdebug"
# php -dxdebug.remote_autostart=On app/console --env=dev odr_datatype:clone_and_link >> app/logs/clone_and_link_datatype.log 2>&1 &
php -dxdebug.remote_autostart=On -dxdebug.remote_enable=1 -dxdebug.remote_mode=req -dxdebug.remote_port=9000 -dxdebug.remote_host=127.0.0.1 -dxdebug.remote_connect_back=0 app/console --env=dev odr_datatype:clone_master >> app/logs/datatype_create.log 2>&1 &

#php -dxdebug.remote_autostart=On app/console --env=dev odr_crypto:worker  >> app/logs/crypto_worker.log 2>&1 &


# temporary kludge to flush cache for clone processes
php app/console odr_datatype:clone_monitor >> app/logs/clone_monitor.log 2>&1 &
php app/console odr_datatype:clone_and_link_monitor >> app/logs/clone_and_link_monitor.log 2>&1 &

php app/console odr_datatype:sync_template >> app/logs/sync_template.log 2>&1 &
