# Script for deploying EBMS Release 4.1 ("Fiordland") to a CBIIT tier.

echo Verifying account running script
if [ $(whoami) != "drupal" ]
then
    echo This script must be run using the drupal account.
    echo Aborting script.
    exit
fi

echo Setting locations
export NCIOCPL=https://api.github.com/repos/NCIOCPL
export URL=$NCIOCPL/ebms/tarball/fiordland
export WORKDIR=/tmp/ebms-4.1
export CONFIG=$WORKDIR/config
export BASEDIR=/local/drupal/ebms
export BACKUP=`/bin/date +"/tmp/ebms-backup-%Y%m%d%H%M%S.tgz"`
export CURL="curl -L -s -k"
echo "Base directory is $BASEDIR"

echo Creating a working directory at $WORKDIR
if [ -d $WORKDIR ]; then
    TIMESTAMPED=`/bin/date +"/tmp/ebms-4.1-%Y%m%d%H%M%S"`
    echo moving old $WORKDIR to $TIMESTAMPED
    mv $WORKDIR $TIMESTAMPED || { echo move failed; exit; }
fi
mkdir $WORKDIR || {
    echo creating $WORKDIR failed
    exit 1
}

echo Backing up existing files
cd $BASEDIR
tar -czf $BACKUP composer.* scheduled web/modules/custom || {
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

echo Refreshing those directories
cd $WORKDIR/ebms
cp composer.* $BASEDIR/ || { echo copying composer files failed; exit; }
cp -r web/modules/custom $BASEDIR/web/modules/ || {
  echo cp custom modules failed; exit;
}
cp scheduled/* $BASEDIR/scheduled/ || { echo cp scheduled failed; exit; }
cp README.md $BASEDIR/ || { echo cp README.md failed; exit; }

echo Applying PHP upgrades
cd $BASEDIR
# This shouldn't be necessary in theory, but in practice ...
# See "Known issues and workarounds" on the Drupal documentation page
# https://www.drupal.org/docs/updating-drupal/updating-drupal-core-via-composer
rm -rf web/core web/modules/contrib web/themes/contrib
chmod +w web/sites/default || { chmod sites-default failed; exit; }
composer install || { echo composer install failed; exit; }
chmod -w web/sites/default || { chmod sites-default failed; exit; }

echo Disabling obsolete module
drush cr
drush pmu ckeditor

table=information_schema.statistics
cond="INDEX_NAME = 'article_journal_title_key'"
query="SELECT COUNT(*) FROM $table WHERE $cond"
count=`drush sqlq --extra=--skip-column-names "$query"`
if [ $count = "0" ]
then
  echo Indexing journal titles
  drush sqlc <<EOF
CREATE INDEX article_journal_title_key ON ebms_article (journal_title);
EOF
else
  echo Journal title index already created
fi

echo Clearing Drupal caches
drush cr

echo Running the database update script
drush updb -y

echo Updating site configuration
mkdir $CONFIG || { echo mkdir config failed; exit; }
cp $WORKDIR/ebms/web/modules/custom/ebms_core/config/install/editor*.yml \
   $WORKDIR/ebms/web/modules/custom/ebms_core/config/install/filter*.yml \
   $WORKDIR/ebms/web/modules/custom/ebms_core/config/install/linkit*.yml \
   $WORKDIR/ebms/web/modules/custom/ebms_help/config/install/*.yml \
   $CONFIG/ || {
  echo configuration copy failed; exit;
}
drush config:import --source=$CONFIG --partial -y -q || {
  echo configuration import failed; exit;
}
drush role:perm:add site_manager 'use text format full_html' || {
  echo permission assignment failed; exit;
}
drush cr

echo Putting site back into live mode
drush state:set system.maintenance_mode 0 || {
  echo failure leaving maintenance mode; exit;
}

echo Done
