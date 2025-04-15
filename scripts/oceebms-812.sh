#!/usr/bin/bash

echo Verifying account running script
if [ $(whoami) != "drupal" ]
then
    echo This script must be run using the drupal account.
    echo Aborting script.
    exit
fi

echo Navigating to the scripts directory
cd /local/drupal/ebms/scripts

echo Fetching the update script
NCIOCPL=https://raw.githubusercontent.com/NCIOCPL
IRONWOOD=$NCIOCPL/ebms/refs/heads/ironwood
SCRIPTS=$IRONWOOD/scripts
CURL="/bin/curl -L -s -k"
$CURL -o oceebms-812.php $SCRIPTS/oceebms-812.php

echo Running the update script
DRUSH=/local/drupal/ebms/vendor/bin/drush
$DRUSH php:script --script-path=/local/drupal/ebms/scripts oceebms-812 > oceebms-812.log

echo Done. Please post the oceebms-812.log to the ticket.
