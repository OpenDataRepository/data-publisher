#!/bin/bash

rm -rf ./app/cache/dev/

php app/console doctrine:cache:clear-query
php app/console doctrine:cache:clear-result
php app/console doctrine:cache:clear-metadata

php app/console cache:clear
