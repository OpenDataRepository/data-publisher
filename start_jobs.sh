#!/bin/bash

#export ODR_PATH=/home/planetary

#cd $ODR_PATH
php app/console odr_cache:recache_record >> app/logs/recache_record.log 2>&1 &
#php app/console odr_cache:recache_record >> app/logs/recache_record_2.log 2>&1 &
php app/console odr_cache:recache_type >> app/logs/recache_type.log 2>&1 &
php app/console odr_cache:recache_type >> app/logs/recache_type_2.log 2>&1 &
php app/console odr_record:migrate >> app/logs/migrate.log 2>&1 &
php app/console odr_record:mass_edit >> app/logs/mass_edit.log 2>&1 &


