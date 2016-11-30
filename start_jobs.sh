#!/bin/bash

#export ODR_PATH=/home/planetary
# export XDEBUG_CONFIG="idekey=phpstorm_xdebug"

#cd $ODR_PATH
#php app/console odr_cache:recache_record >> app/logs/recache_record.log 2>&1 &
#php app/console odr_cache:recache_record >> app/logs/recache_record_2.log 2>&1 &
#php app/console odr_cache:recache_type >> app/logs/recache_type.log 2>&1 &
#php app/console odr_cache:recache_type >> app/logs/recache_type_2.log 2>&1 &
php app/console odr_record:migrate >> app/logs/migrate.log 2>&1 &
php app/console odr_record:mass_edit >> app/logs/mass_edit.log 2>&1 &

php app/console odr_crypto:worker >> app/logs/crypto_worker.log 2>&1 &
php app/console odr_crypto:worker >> app/logs/crypto_worker_2.log 2>&1 &

php app/console odr_csv_import:validate >> app/logs/csv_import_validate.log 2>&1 &
php app/console odr_csv_import:worker >> app/logs/csv_import.log 2>&1 &
#php app/console odr_csv_import:worker >> app/logs/csv_import_2.log 2>&1 &

php app/console odr_csv_export:start >> app/logs/csv_export_start.log 2>&1 &
php app/console odr_csv_export:worker >> app/logs/csv_export_worker.log 2>&1 &
php app/console odr_csv_export:worker >> app/logs/csv_export_worker_2.log 2>&1 &
php app/console odr_csv_export:finalize >> app/logs/csv_export_finalize.log 2>&1 &
