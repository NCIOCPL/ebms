# Script for deploying EBMS Release 4.3 ("Harpers Ferry") to a CBIIT tier.

echo Verifying account running script
if [ $(whoami) != "drupal" ]
then
    echo This script must be run using the drupal account.
    echo Aborting script.
    exit
fi

echo Setting locations
export NCIOCPL=https://api.github.com/repos/NCIOCPL
export URL=$NCIOCPL/ebms/tarball/harpers-ferry
export WORKDIR=/tmp/ebms-4.3
export SCRIPTS=$WORKDIR/ebms/scripts
export CONFIG=$WORKDIR/config
export BASEDIR=/local/drupal/ebms
export BACKUP=`/bin/date +"/tmp/ebms-backup-%Y%m%d%H%M%S.tgz"`
export CURL="curl -L -s -k"
echo "Base directory is $BASEDIR"

echo Creating a working directory at $WORKDIR
if [ -d $WORKDIR ]; then
    TIMESTAMPED=`/bin/date +"/tmp/ebms-4.3-%Y%m%d%H%M%S"`
    echo moving old $WORKDIR to $TIMESTAMPED
    mv $WORKDIR $TIMESTAMPED || { echo move failed; exit; }
fi
mkdir $WORKDIR || {
    echo creating $WORKDIR failed
    exit 1
}

echo Backing up existing files
cd $BASEDIR
tar -czf $BACKUP composer.* scheduled web/modules/custom web/themes/custom || {
    echo "Backup to $BACKUP failed"
    exit 1
}

echo Fetching the branch from GitHub
cd $WORKDIR
$CURL $URL | tar -xzf - || {
    echo fetch $URL failed
    exit 1
}
mv NCIOCPL-ebms* ebms || {
    echo rename failed
    exit 1
}

echo Putting site into maintenance mode
cd $BASEDIR
drush state:set system.maintenance_mode 1 || {
  echo failure setting maintenance mode; exit;
}

echo Clearing files and directories which will be refreshed from GitHub
cd $BASEDIR
rm -rf README.md composer.* scheduled/* web/modules/custom/*
rm -rf web/themes/templates

echo Refreshing those directories
cd $WORKDIR/ebms
cp composer.* $BASEDIR/ || { echo copying composer files failed; exit; }
cp -r web/modules/custom $BASEDIR/web/modules/ || {
  echo cp custom modules failed; exit;
}
cp -r web/themes/custom/ebms/templates $BASEDIR/web/themes/custom/ebms/ || {
  echo cp custom theme templates failed; exit;
}
cp scheduled/* $BASEDIR/scheduled/ || { echo cp scheduled failed; exit; }
cp README.md $BASEDIR/ || { echo cp README.md failed; exit; }

echo Applying PHP upgrades
# composer config --no-plugins allow-plugins.drupal/core-project-message true
echo Ignore warnings about abandoned packages
cd $BASEDIR
chmod +w web/sites/default || { chmod sites-default failed; exit; }
composer install || { echo composer install failed; exit; }
chmod -w web/sites/default || { chmod sites-default failed; exit; }

echo Running the database update script
drush updatedb -y

echo Clearing Drupal caches
drush cr

echo Putting site back into live mode
drush state:set system.maintenance_mode 0 || {
  echo failure leaving maintenance mode; exit;
}

echo Done
