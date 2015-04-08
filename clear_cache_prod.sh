#!/bin/bash

php app/console doctrine:cache:clear-query
php app/console doctrine:cache:clear-result
php app/console doctrine:cache:clear-metadata

php app/console --env=prod cache:clear

