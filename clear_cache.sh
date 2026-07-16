#!/bin/bash

#
# Clears all caches for the main ODR site
# and all linked sites.
# 
# Linked sites must be listed in 
#  /app/config/instances.list
#
# run from /home/odr/data-publisher

# Clears main ODR site
php app/console cache:clear

# Clears linked sites
php app/console-all cache:clear --env=prod
