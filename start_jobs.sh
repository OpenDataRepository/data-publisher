#!/bin/bash

#export ODR_PATH=/home/planetary
# export XDEBUG_CONFIG="idekey=phpstorm_xdebug"

#cd $ODR_PATH
php app/console odr_record:migrate >> app/logs/migrate.log 2>&1 &
php app/console odr_record:mass_edit >> app/logs/mass_edit.log 2>&1 &

php app/console odr_crypto:worker >> app/logs/crypto_worker.log 2>&1 &
#php app/console odr_crypto:worker >> app/logs/crypto_worker_2.log 2>&1 &    # seems to screw up when second job is active

php app/console odr_datatype:clone >> app/logs/datatype_create.log 2>&1 &
#php app/console odr_theme:clone >> app/logs/theme_create.log 2>&1 &    # theme clone requests don't go through background jobs currently

php app/console odr_csv_import:validate >> app/logs/csv_import_validate.log 2>&1 &
php app/console odr_csv_import:worker >> app/logs/csv_import.log 2>&1 &
#php app/console odr_csv_import:worker >> app/logs/csv_import_2.log 2>&1 &    # seems to screw up when second job is active

php app/console odr_csv_export:start >> app/logs/csv_export_start.log 2>&1 &
php app/console odr_csv_export:worker >> app/logs/csv_export_worker.log 2>&1 &
#php app/console odr_csv_export:worker >> app/logs/csv_export_worker_2.log 2>&1 &    # seems to screw up when second job is active
php app/console odr_csv_export:finalize >> app/logs/csv_export_finalize.log 2>&1 &
