#!/bin/bash

php app/console doctrine:cache:clear-metadata

php app/console doctrine:generate:entities ODR

php app/console doctrine:schema:update --force

#sudo chmod -R 777 app/cache/
sudo chown odr:odr ./src/ODR/AdminBundle/Entity/*
sudo chown odr:odr ./src/ODR/ODROpenRepository/UserBundle/Entity/*
sudo chown odr:odr ./src/ODR/ODROpenRepository/OAuthBundle/Entity/*
