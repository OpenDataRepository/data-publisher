#!/bin/bash

php app/console doctrine:cache:clear-metadata

php app/console doctrine:generate:entities ODR

php app/console doctrine:schema:update --force

#sudo chmod -R 777 app/cache/
