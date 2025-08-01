#!/bin/bash

start_time=${SECONDS}
date
SUDO=/usr/bin/sudo
export REPO_BASE=/var/www/html
DRUSH=${REPO_BASE}/vendor/bin/drush
DATA=${REPO_BASE}/testdata
SITE=${REPO_BASE}/web/sites/default
UNVERSIONED=${REPO_BASE}/unversioned
DBURL=mysql://db:db@db/db
SITEHOST=ebms.ddev.site
SETTINGS=${SITE}/settings.php
FILES=${SITE}/files
EMAIL=ebms@cancer.gov
USERPW=$(openssl rand -base64 12)
mkdir -p ${UNVERSIONED}
echo ${USERPW} > ${UNVERSIONED}/userpw
echo options: > ${REPO_BASE}/drush/drush.yml
echo "  uri: https://${SITEHOST}" >> ${REPO_BASE}/drush/drush.yml
$SUDO chmod a+w ${SITE}
$SUDO rm -rf ${REPO_BASE}/logs
$SUDO mkdir ${REPO_BASE}/logs
$SUDO chmod 777 ${REPO_BASE}/logs
[ -d ${FILES} ] && $SUDO chmod -R a+w ${FILES} && rm -rf ${FILES}/*
[ -f ${SETTINGS} ] && $SUDO chmod +w ${SETTINGS}
cp -f ${SITE}/default.settings.php ${SETTINGS}
$SUDO chmod +w ${SETTINGS}
echo "\$settings['trusted_host_patterns'] = ['^${SITEHOST}\$'];" >> ${SETTINGS}
$DRUSH si -y --site-name=EBMS --account-pass=${USERPW} --db-url=${DBURL} \
       --site-mail=${EMAIL}
$SUDO chmod -w ${SETTINGS}
$DRUSH state:set system.maintenance_mode 1
$DRUSH pmu contact
$DRUSH then uswds_base
$DRUSH then ebms
$DRUSH en datetime_range
$DRUSH en devel
$DRUSH en linkit
$DRUSH en role_delegation
$DRUSH en editor_advanced_link
$DRUSH en ebms_core
$DRUSH en ebms_board
$DRUSH en ebms_journal
$DRUSH en ebms_group
$DRUSH en ebms_message
$DRUSH en ebms_topic
$DRUSH en ebms_user
$DRUSH en ebms_meeting
$DRUSH en ebms_state
$DRUSH en ebms_article
$DRUSH en ebms_import
$DRUSH en ebms_doc
$DRUSH en ebms_review
$DRUSH en ebms_summary
$DRUSH en ebms_travel
$DRUSH en ebms_home
$DRUSH en ebms_menu
$DRUSH en ebms_report
$DRUSH en ebms_breadcrumb
$DRUSH en ebms_help
$DRUSH cset -y -q system.theme default ebms
$DRUSH cset -y -q system.site page.front /home
$DRUSH cset -y -q system.date country.default US
$DRUSH cset -y -q system.date timezone.default America/New_York
$DRUSH cset -y -q system.date timezone.user.configurable false
$DRUSH scr --script-path=$DATA vocabularies
$DRUSH scr --script-path=$DATA users
$DRUSH scr --script-path=$DATA files
$DRUSH scr --script-path=$DATA boards
$DRUSH scr --script-path=$DATA journals
$DRUSH scr --script-path=$DATA groups
$DRUSH scr --script-path=$DATA topics
$DRUSH scr --script-path=$DATA meetings
$DRUSH scr --script-path=$DATA docs
$DRUSH scr --script-path=$DATA articles
$DRUSH scr --script-path=$DATA imports
$DRUSH scr --script-path=$DATA reviews
$DRUSH scr --script-path=$DATA summaries
$DRUSH scr --script-path=$DATA travel
$DRUSH scr --script-path=$DATA messages
$DRUSH scr --script-path=$DATA assets
$DRUSH scr --script-path=$DATA pubtypes
$DRUSH scr --script-path=$DATA help
$DRUSH scr --script-path=$DATA about
$DRUSH state:set system.maintenance_mode 0
$SUDO chmod -R 777 ${FILES}
date
elapsed=$(( SECONDS - start_time ))
eval "echo Elapsed time: $(date -ud "@$elapsed" +'$((%s/3600/24)) days %H hr %M min %S sec')"
