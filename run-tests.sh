REPO_BASE=/var/www
CUSTOM=${REPO_BASE}/web/modules/custom
SQLITE_URL=sqlite://localhost/sites/simpletest/.ht.sqlite
TEMPLATE=phpunit.xml.template
cd ${REPO_BASE}
mkdir -p web/sites/simpletest
chown www-data web/sites/simpletest
/usr/bin/sed "s#@@SIMPLETEST_DB@@#${SQLITE_URL}#" < $TEMPLATE > phpunit.xml
su www-data -s /bin/bash -c 'vendor/bin/phpunit web/modules/custom/repro'
