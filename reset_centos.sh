#!/bin/bash

sudo systemctl restart memcached
redis-cli flushall
sudo rm -rf app/cache/dev
sudo rm -rf app/cache/prod
sudo rm -rf app/cache/_*
pkill php
./start_jobs.sh
