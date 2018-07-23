#!/bin/bash


sudo /etc/init.d/memcached restart
redis-cli flushall
cd app/cache
rm -rf dev
rm -rf prod
rm -rf _*
cd ../../
killall php
./start_jobs_debug.sh
