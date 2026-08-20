#!/bin/bash

killall php
#killall phantomjs
killall node

rm -rf ./app/logs/*
rm -rf ./app/cache/dev/
rm -rf ./app/cache/prod/
rm -rf ./app/cache/test/
rm -rf ./web/uploads/files/*.html

./clear_cache.sh
./clear_cache_prod.sh

killall node

