#!/bin/bash

rm -rf ./app/cache/dev/
rm -rf ./app/cache/prod/
service memcached restart

php app/console doctrine:cache:clear-metadata

php app/console doctrine:generate:entities ODR

php app/console doctrine:schema:update --dump-sql
php app/console doctrine:schema:update --force

#sudo chmod -R 777 app/cache/
sudo chown odr:odr ./src/ODR/AdminBundle/Entity/*
sudo chown odr:odr ./src/ODR/OpenRepository/UserBundle/Entity/*
sudo chown odr:odr ./src/ODR/OpenRepository/OAuthServerBundle/Entity/*
sudo chown odr:odr ./src/ODR/OpenRepository/OAuthClientBundle/Entity/*
