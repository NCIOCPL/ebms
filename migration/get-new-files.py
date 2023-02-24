#!/usr/bin/env python3

"""Fetch files which have been added to the EBMS since the last run.

This script determines which files named in ../unversioned/exported/files.json
are not already in our set of managed EBMS files, which are stored in the
../unversioned/files.tar archive file. The assumption is that all of the files
in that TAR file have a checksum recorded in the files.sums file.
The ../unversioned/exported/files.json file is generated by the export.py
script, which pulls the information from the file_managed database table.
This script fetches the new files and writes them to the ../unversioned/files
subdirectory.

Perform these steps each time this script is run.

  mv ../unversioned/exported ../unversioned/baseline
  ./export.py
  rm -rf ../unversioned/files
  mkdir ../unversioned/files
  ./get-new-files.py
  cd ../unversioned
  tar -rf files.tar files
  rsync -a files ../web/sites/default/

After the final migration to the new production site, in order to confirm
that the files were transferred intact, you can perform the following steps:

  # On nciws-p2154-v using the drupal login account:
  NAME=`/bin/date +"/local/home/drupal/ebms-files-%Y%m%d.sums.gz"`
  cd /local/drupal/sites/ebms.nci.nih.gov
  /bin/find files -type f -exec /bin/sha1sum '{}' \; | /bin/gzip > $NAME

  # On the new production EBMS web server, to which the file created
  # on nciws-p2154-v has been copied:
  cd /local/drupal/ebms/migration
  /bin/gzip < FILE_COPIED_FROM_NCIWS-P2154-V | ./verify-file-checksums.py
"""

from datetime import datetime
from hashlib import sha1
from json import loads
from pathlib import Path
from urllib.parse import quote
from requests import get

HOST = "ebms.nci.nih.gov"
FILES = f"https://{HOST}/sites/ebms.nci.nih.gov/files"
FETCH_FILES_ERR = "../unversioned/fetch-files.err"

# Collect the existing checksums.
start = datetime.now()
sums = {}
path = Path("../unversioned/files.sums")
if path.exists():
    with path.open(encoding="utf-8") as fp:
        for line in fp:
            checksum, path = line.strip().split(None, 1)
            sums[path] = checksum
original_count = len(sums)
print(f"loaded {original_count} existing checksums")

# Find out which new files we need to fetch.
with open("../unversioned/exported/files.json", encoding="utf-8") as fp:
    for line in fp:
        values = loads(line)
        fid = values["fid"]
        uri = values.get("uri")
        if uri and uri.startswith("public://"):
            filepath = uri.replace("public://", "")
            key = f"files/{filepath}"
            if key not in sums:
                url = f"{FILES}/{quote(filepath)}"
                response = get(url)
                content = response.content
                filesize = len(content)
                expected = values["filesize"]
                if filesize != expected:
                    err = (f"fid {fid}: expected {expected} bytes, "
                           "got {filesize}")
                    with open(FETCH_FILES_ERR, "a", encoding="utf-8") as fp:
                        fp.write(f"{err} ({url})\n")
                    print(err)
                    continue
                print(f"{fid}: fetched {key}")
                path = Path(f"../unversioned/{key}")
                if len(path.parts) > 4:
                    directory = Path("/".join(path.parts[:-1]))
                    if not directory.exists():
                        directory.mkdir(parents=True)
                path.write_bytes(content)
                sums[key] = sha1(content).hexdigest()
added = len(sums) - original_count
if added:
    path = Path("../unversioned/files.sums")
    if path.exists():
        stamp = datetime.now().strftime("%Y%m%d%H%M%S")
        path.rename(f"../unversioned/files-{stamp}.sums")
    with open("../unversioned/files.sums", "w", encoding="utf-8") as fp:
        for path in sorted(sums):
            fp.write(f"{sums[path]} {path}\n")
    print(f"added {added} new files")
else:
    print("no new files found")
elapsed = datetime.now() - start
print(f"elapsed: {elapsed}")
