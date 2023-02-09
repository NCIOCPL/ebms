#!/bin/bash

start_time=${SECONDS}
date
if grep --quiet drupal /etc/passwd;
then
    echo Running on CBIIT hosting.
    if [ $(whoami) != "drupal" ]
    then
        echo This script must be run using the drupal account on CBIIT hosting.
        echo Aborting script.
        exit
    fi
    SUDO=
else
    echo Running on non-CBIIT hosting.
    SUDO=$(which sudo)
fi
REPO_BASE=$(pwd)
while getopts r: flag
do
    case "${flag}" in
        r) REPO_BASE=${OPTARG};;
    esac
done
export EBMS_MIGRATION_LOAD=1
export REPO_BASE
DRUSH=${REPO_BASE}/vendor/bin/drush
MIGRATION=${REPO_BASE}/migration
SITE=${REPO_BASE}/web/sites/default
UNVERSIONED=${REPO_BASE}/unversioned
DBURL=$(cat ${UNVERSIONED}/dburl)
ADMINPW=$(cat ${UNVERSIONED}/adminpw)
SITEHOST=$(cat ${UNVERSIONED}/sitehost)
$DRUSH scr --script-path=$MIGRATION articles
$DRUSH scr --script-path=$MIGRATION relationships
$DRUSH scr --script-path=$MIGRATION imports
$DRUSH scr --script-path=$MIGRATION reviews
$DRUSH scr --script-path=$MIGRATION travel
$DRUSH scr --script-path=$MIGRATION messages
$DRUSH scr --script-path=$MIGRATION assets
$DRUSH scr --script-path=$MIGRATION pubtypes
$DRUSH scr --script-path=$MIGRATION help
$DRUSH scr --script-path=$MIGRATION about
date
elapsed=$(( SECONDS - start_time ))
eval "echo Elapsed time: $(date -ud "@$elapsed" +'$((%s/3600/24)) days %H hr %M min %S sec')"
