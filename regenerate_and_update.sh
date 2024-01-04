#!/bin/bash

rm -rf ./app/cache/dev/
rm -rf ./app/cache/prod/
service memcached restart

php app/console doctrine:cache:clear-metadata

php app/console doctrine:generate:entities ODR

php app/console doctrine:schema:update --dump-sql
php app/console doctrine:schema:update --force

#
# TODO - Why the ownership changes?
# Lines below reset ownership to current user.
# Unsure why this is necessary.
#
USER=$(whoami)
echo "Verfiying permissions are set to: $USER"
sudo chown $USER:$USER ./src/ODR/AdminBundle/Entity/*
sudo chown $USER:$USER ./src/ODR/OpenRepository/UserBundle/Entity/*
sudo chown $USER:$USER ./src/ODR/OpenRepository/OAuthServerBundle/Entity/*
sudo chown $USER:$USER ./src/ODR/OpenRepository/OAuthClientBundle/Entity/*
echo "Done."
