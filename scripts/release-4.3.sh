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
export REPO_URL=$NCIOCPL/ebms/tarball/harpers-ferry
export WORKDIR=/tmp/ebms-4.3
export SCRIPTS=$WORKDIR/ebms/scripts
export CONFIG=$WORKDIR/config
export BASEDIR=/local/drupal/ebms
export BACKUP=`/bin/date +"/tmp/ebms-backup-%Y%m%d%H%M%S.tgz"`
export USWDS_VERSION=3.7.1
export DOWNLOAD=https://github.com/uswds/uswds/releases/download
export USWDS_URL=$DOWNLOAD/v$USWDS_VERSION/uswds-uswds-$USWDS_VERSION.tgz
export THEME=$BASEDIR/web/themes/custom/ebms
export USWDS_TARFILE=/tmp/uswds-$USWDS_VERSION.tgz
export TAR=/bin/tar
export CURL="/bin/curl -L -s -k"
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
$TAR -czf $BACKUP composer.* scheduled web/modules/custom web/themes/custom || {
    echo "Backup to $BACKUP failed"
    exit 1
}

echo Fetching the branch from GitHub
cd $WORKDIR
$CURL $REPO_URL | $TAR -xzf - || {
    echo fetch $REPO_URL failed
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
rm -rf README.md composer.* scheduled/* web/modules/custom/* vendor
rm -rf web/themes/custom/ebms/templates
rm -rf web/themes/custom/ebms/css
rm -rf web/themes/custom/ebms/package

echo Refreshing those directories
cd $WORKDIR/ebms
cp composer.* $BASEDIR/ || { echo copying composer files failed; exit; }
cp -r web/modules/custom $BASEDIR/web/modules/ || {
  echo cp custom modules failed; exit;
}
cp -r web/themes/custom/ebms/templates $BASEDIR/web/themes/custom/ebms/ || {
  echo cp custom theme templates failed; exit;
}
cp -r web/themes/custom/ebms/css $BASEDIR/web/themes/custom/ebms/ || {
  echo cp custom theme css failed; exit;
}
cp scheduled/* $BASEDIR/scheduled/ || { echo cp scheduled failed; exit; }
cp README.md $BASEDIR/ || { echo cp README.md failed; exit; }
cd $THEME || {
    echo unable to switch to custom EBMS theme directory
    exit 1
}
$CURL $USWDS_URL | $TAR -xzf - || {
    echo fetch $USWDS_URL failed
    exit 1
}

echo Applying PHP upgrades
# composer config --no-plugins allow-plugins.drupal/core-project-message true
echo Ignore warnings about abandoned packages
cd $BASEDIR
chmod +w web/sites/default || { echo chmod sites-default failed; exit; }
composer install --no-dev || { echo composer install failed; exit; }
chmod -w web/sites/default || { echo chmod sites-default failed; exit; }

echo Running the database update script
drush updatedb -y

echo Installing per-board review dispositions
drush php:script --script-path=$SCRIPTS add-board-dispositions || {
  echo failure adding per-board review dispositions; exit;
}

echo Clearing Drupal caches
drush cr

echo Putting site back into live mode
drush state:set system.maintenance_mode 0 || {
  echo failure leaving maintenance mode; exit;
}

echo Done
