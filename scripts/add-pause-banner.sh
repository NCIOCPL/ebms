# Script for applying a banner to the EBMS for the PDQ pause.

echo Verifying account running script
if [ $(whoami) != "drupal" ]
then
    echo This script must be run using the drupal account.
    echo Aborting script.
    exit
fi

echo Setting locations
export BASEDIR=/local/drupal/ebms
export DRUSH=$BASEDIR/vendor/bin/drush
export EBMS_HOME_MODULE=$BASEDIR/web/modules/custom/ebms_home
echo "Base directory is $BASEDIR"

echo Installing banner
cp HomePage.php $EBMS_HOME_MODULE/src/Controller/
cp activity-cards.html.twig $EBMS_HOME_MODULE/templates/

echo Clearing Drupal cache
cd $BASEDIR
$DRUSH cr

echo Done
