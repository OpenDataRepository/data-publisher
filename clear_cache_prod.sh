#!/bin/bash

rm -rf ./app/cache/prod/

php app/console doctrine:cache:clear-query
php app/console doctrine:cache:clear-result
php app/console doctrine:cache:clear-metadata

php app/console --env=prod cache:clear

