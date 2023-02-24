# Apply patch to fix last-minute bug and work around database limitations.

echo Verifying account running script
if [ $(whoami) != "drupal" ]
then
    echo This script must be run using the drupal account.
    echo Aborting script.
    exit
fi

echo Setting locations
export NCIOCPL=https://api.github.com/repos/NCIOCPL
export URL=$NCIOCPL/ebms/tarball/uat
export WORKDIR=/tmp/ebms-uat-patch
export BASEDIR=/local/drupal/ebms
export BACKUP=`/bin/date +"/tmp/ebms-backup-%Y%m%d%H%M%S.tgz"`
export CURL="curl -L -s -k"
echo "Work directory is $WORKDIR"
echo "Base directory is $BASEDIR"

echo Creating a working directory at $WORKDIR
mkdir $WORKDIR || {
    echo creating $WORKDIR failed
    exit 1
}

echo Backing up existing files
cd $BASEDIR
tar -czf $BACKUP migration web/modules/custom web/themes/custom/ebms/images || {
    echo "tar of migration scripts and custom modules failed"
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

echo Clearing directories which will be refreshed from GitHub
cd $BASEDIR
rm -rf migration/* web/modules/custom/* web/themes/custom/ebms/images/*

echo Refreshing those directories
cd $WORKDIR/ebms
cp -r migration $BASEDIR/ || { echo cp migration failed; exit; }
cp -r web/modules/custom $BASEDIR/web/modules/ || {
  echo cp custom modules failed; exit;
}
cp -r web/themes/custom/ebms/images $BASEDIR/web/themes/custom/ebms/ || {
  echo cp images failed; exit;
}

echo Done
