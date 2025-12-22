#!/bin/sh

# Credit: http://symfony.com/doc/current/setup/file_permissions.html

mkdir -p app/cache
mkdir -p app/logs
#mkdir -p app/crypto_dir
mkdir -p app/tmp
#mkdir -p web/uploads

HTTPDUSER=`ps axo user,comm | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d\  -f1`

sudo setfacl -R -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX app/cache app/logs app/tmp
#sudo setfacl -R -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX app/cache app/logs app/crypto_dir app/tmp web/uploads
sudo setfacl -dR -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX app/cache app/logs app/tmp
#sudo setfacl -dR -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX app/cache app/logs app/crypto_dir app/tmp web/uploads

