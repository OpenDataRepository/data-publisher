#!/bin/bash


sudo /etc/init.d/memcached restart
redis-cli flushall
sudo rm -rf app/cache/dev
sudo rm -rf app/cache/prod
sudo rm -rf app/cache/_*
#killall php
# ./start_jobs_debug.sh
#./start_jobs.sh
