#!/bin/sh

# Credit: http://symfony.com/doc/current/setup/file_permissions.html

# Symfony 7 puts the cache and logs under var/ (was app/cache + app/logs in
# Symfony 3.4). Create the per-env cache subdirs up front so the ACLs below
# apply to them too. app/ itself is ACL'd so symlinked instances (ODR_APP_DIR)
# can write their config/cache there — see AppKernel::getProjectDir().
mkdir -p var/cache
mkdir -p var/cache/dev
mkdir -p var/cache/prod
mkdir -p var/log
#mkdir -p app/crypto_dir
mkdir -p app/tmp
#mkdir -p web/uploads

HTTPDUSER=`ps axo user,comm | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d\  -f1`

echo $HTTPDUSER

sudo setfacl -R -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX app var/cache var/log app/tmp
#sudo setfacl -R -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX app var/cache var/log app/crypto_dir app/tmp web/uploads
sudo setfacl -dR -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX app var/cache var/log app/tmp
#sudo setfacl -dR -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX app var/cache var/log app/crypto_dir app/tmp web/uploads
