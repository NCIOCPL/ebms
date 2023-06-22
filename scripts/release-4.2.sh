# Script for deploying EBMS Release 4.2 ("Glacier") to a CBIIT tier.

echo Verifying account running script
if [ $(whoami) != "drupal" ]
then
    echo This script must be run using the drupal account.
    echo Aborting script.
    exit
fi

echo Setting locations
export NCIOCPL=https://api.github.com/repos/NCIOCPL
export URL=$NCIOCPL/ebms/tarball/glacier
export WORKDIR=/tmp/ebms-4.2
export SCRIPTS=$WORKDIR/ebms/scripts
export CONFIG=$WORKDIR/config
export BASEDIR=/local/drupal/ebms
export BACKUP=`/bin/date +"/tmp/ebms-backup-%Y%m%d%H%M%S.tgz"`
export CURL="curl -L -s -k"
echo "Base directory is $BASEDIR"

echo Creating a working directory at $WORKDIR
if [ -d $WORKDIR ]; then
    TIMESTAMPED=`/bin/date +"/tmp/ebms-4.2-%Y%m%d%H%M%S"`
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

echo Clearing out obsolete files and directories
cd $BASEDIR
rm -rf migration testdata templates install.sh phpunit.xml
rm -rf docker-compose.yml Dockerfile .gitattributes .gitignore

echo Clearing files and directories which will be refreshed from GitHub
cd $BASEDIR
rm -rf README.md composer.* scheduled/* web/modules/custom/*
rm -rf web/themes/custom/ebms/config web/themes/custom/ebms/js

echo Refreshing those directories
cd $WORKDIR/ebms
cp composer.* $BASEDIR/ || { echo copying composer files failed; exit; }
cp -r web/modules/custom $BASEDIR/web/modules/ || {
  echo cp custom modules failed; exit;
}
cp -r web/themes/custom/ebms/config $BASEDIR/web/themes/custom/ebms/ || {
  echo cp custom theme config failed; exit;
}
cp -r web/themes/custom/ebms/js $BASEDIR/web/themes/custom/ebms/ || {
  echo cp custom theme js failed; exit;
}
cp -r web/themes/custom/ebms/ebms.info.* $BASEDIR/web/themes/custom/ebms/ || {
  echo cp custom theme info failed; exit;
}
cp scheduled/* $BASEDIR/scheduled/ || { echo cp scheduled failed; exit; }
cp README.md $BASEDIR/ || { echo cp README.md failed; exit; }

echo Applying PHP upgrades
composer config --no-plugins allow-plugins.drupal/core-project-message true
echo Ignore warnings about abandoned packages
cd $BASEDIR
chmod +w web/sites/default || { chmod sites-default failed; exit; }
chmod +w web/sites/default/*.yml || { chmod sites-default-yml failed; exit; }
chmod +w web/sites/default/*.php || { chmod sites-default-php failed; exit; }
composer install || { echo composer install failed; exit; }
cat <<EOF > web/sites/default/services.yml
parameters:
  session.storage.options:
    cookie_samesite: Lax
EOF
chmod -w web/sites/default || { chmod sites-default failed; exit; }
chmod -w web/sites/default/*.yml || { chmod sites-default-yml failed; exit; }
chmod -w web/sites/default/*.php || { chmod sites-default-php failed; exit; }

echo Running the database update script
drush updatedb -y

echo Installing working group decisions
mkdir $CONFIG || { echo mkdir config failed; exit; }
cp $WORKDIR/ebms/web/modules/custom/ebms_core/config/install/*work*.yml \
   $CONFIG/ || {
  echo configuration copy failed; exit;
}
drush config:import --source=$CONFIG --partial -y -q || {
  echo configuration import failed; exit;
}
drush php:script --script-path=$SCRIPTS add-wg-decisions || {
  echo failure adding working group decisions; exit;
}

echo Clearing Drupal caches
drush cr

echo Putting site back into live mode
drush state:set system.maintenance_mode 0 || {
  echo failure leaving maintenance mode; exit;
}

echo Done
