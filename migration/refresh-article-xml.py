#!/usr/bin/env python3

"""Get new and changed PubMed article XML for the migration to Drupal 9.

We have a set of article XML, a manifest file with PubMed IDs matched
to the date/time we last fetched fresh XML for each, and an MD5
checkums file.  We find out from NLM which articles have changed since
we last ran this job.  Then we fetch the fresh XML and save it. We do
the same for articles which have been added to the production EBMS
since the last run (and so were not in the manifest). For any of these
new articles which NLM can't find, we get the XML from the EBMS
database instead. The original manifest and checksums files are backed
up using timestamp-based names, and fresh files are written.

The format of each line in ../unversioned/articles.manifest is:

<PubMedID><TAB><ISO-DATE-TIME><NEWLINE>

The format of each line in ../unversioned/articles.sums is

<MD5-CHECKSUM><SPACE><SPACE>articles/<PubMedID>.xml<NEWLILNE>

The command-line option --only-new can be specified to just pick up
articles which aren't already in the manifest. The full version takes
about 20-30 minutes. Only fetching XML for new articles is much quicker.
"""

from argparse import ArgumentParser
from datetime import datetime
from functools import cached_property
from hashlib import md5
from logging import basicConfig, getLogger
from pathlib import Path
from sys import stderr
from time import sleep
from lxml import etree
from requests import post
from ebms_db import DBMS


class Control:
    """Object which manages the job's steps."""

    ESEARCH = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi"
    ESEARCH_PARMS = "db=pubmed&retmax=5000&term="
    ESEARCH_BATCH_SIZE = 1000
    EFETCH = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi"
    EFETCH_PARMS = "db=pubmed&id="
    EFETCH_BATCH_SIZE = 100
    OPEN, CLOSE = b"<PubmedArticle>", b"</PubmedArticle>"
    ARTICLE_SET = b"<PubmedArticleSet>"
    ROOT = "/local/drupal/ebms"
    UNVERSIONED = f"{ROOT}/unversioned"
    ARTICLES = f"{UNVERSIONED}/articles"
    CHECKSUMS = f"{UNVERSIONED}/articles.sums"
    MANIFEST = f"{UNVERSIONED}/articles.manifest"
    LOG_FORMAT = "%(asctime)s [%(levelname)s] %(message)s"

    def main(self):
        """Find and save new and changed article XML."""

        start = datetime.now()
        self.logger.info("updating articles changed since %s", self.latest)
        if not self.opts.only_new:
            refreshed = self.refresh()
        added = self.add()
        self.update_manifest()
        self.update_checksums()
        elapsed = datetime.now() - start
        if self.opts.only_new:
            self.logger.info("added %d in %s", len(added), elapsed)
        else:
            args = len(refreshed), len(added), elapsed
            self.logger.info("updated %d, added %d in %s", *args)

    def add(self):
        """Add articles which weren't in the manifest."""

        saved = self.fetch_and_save(self.new)
        missing = self.new - saved
        if missing:
            self.logger.info("NLM has lost %d articles", len(missing))
            select = (
                "SELECT source_data, import_date, update_date"
                "  FROM ebms_article"
                " WHERE article_id = %s"
                " ORDER BY article_id"
            )
            for pmid in sorted(missing, key=int):
                self.logger.info("fetching XML for %s from EBMS", pmid)
                self.cursor.execute(select, (pmid,))
                rows = self.cursor.fetchall()
                if rows:
                    xml = rows[0]["source_data"]
                    imported = rows[0]["import_date"]
                    update_date = rows[0]["update_date"]
                    self.save(pmid, xml, str(update_date or imported))
                    saved.add(pmid)
                else:
                    self.logger.warning("unable to fetch article %s", pmid)
        return saved

    def fetch(self, pmids):
        """Retrieve the XML for a batch of PubMed articles from NLM.

        Required positional argument:
        pmids - sequence of strings for the PubMed IDs for the articles

        Return:
            bytes for the response from NLM's API
        """

        parms = self.EFETCH_PARMS + ",".join(pmids)
        if len(pmids) == 1:
            articles = f"article {pmids[0]}"
        else:
            articles = f"{len(pmids)} articles ({pmids[0]} ... {pmids[-1]})"
        self.logger.info("asking NLM for %s", articles)
        tries = 5
        snooze = .5
        while tries > 0:
            try:
                response = post(self.EFETCH, data=parms)
                if self.ARTICLE_SET in response.content:
                    return response.content
                raise Exception("bad response from NLM")
            except Exception as e:
                tries -= 1
                self.logger.warning("fetch: %s (%d tries left)", e, tries)
                if tries < 1:
                    raise Exception("Failure fetching PubMed articles")
                sleep(snooze)
                snooze *= 2

    def fetch_and_save(self, pmid_set):
        """Fetch fresh XML for articles from PubMed and save it.

        Required positional argument:
            pmid_set - set of PubMed ID strings

        Return:
            set of PubMed IDs for articles which have been successfully
            fetched and saved
        """

        saved = set()
        offset = 0
        now = datetime.now()
        pmids = sorted(pmid_set, key=int)
        while offset < len(pmids):
            chunk = pmids[offset:offset+self.EFETCH_BATCH_SIZE]
            offset += len(chunk)
            response = self.fetch(chunk)
            start = response.find(self.OPEN)
            while start > 0:
                end = response.find(self.CLOSE, start)
                if end < 0:
                    break
                end += len(self.CLOSE)
                xml = response[start:end]
                start = response.find(self.OPEN, end)
                try:
                    article = self.PubmedArticle(xml)
                    if article.pmid in pmids:
                        manifest_date = self.manifest.get(article.pmid, "")
                        if article.revised >= manifest_date[:10]:
                            self.save(article.pmid, xml, now)
                            saved.add(article.pmid)
                    else:
                        message = "received unrequested article %s"
                        self.logger.warning(message, article.pmid)
                except Exception as e:
                    self.logger.warning("parsing Pubmed article: %s", e)
            if offset < len(pmids):
                sleep(.35)
        return saved

    def refresh(self):
        """Fetch and save article XML which has changed."""
        return self.fetch_and_save(self.recently_changed)

    def save(self, pmid, xml, refreshed):
        """Save article XML and record it in the manifest and checksum files.

        Required positional arguments:
            pmid - string for the article's PubMed ID
            xml - bytes or string for the serialized article document
            refreshed - string for the date/time when the XML was fetched
        """

        if isinstance(xml, str):
            xml = xml.encode("utf-8")
        with open(f"{self.UNVERSIONED}/articles/{pmid}.xml", "wb") as fp:
            fp.write(xml)
        self.checksums[pmid] = md5(xml).hexdigest()
        self.manifest[pmid] = refreshed

    def update_checksums(self):
        """Archive the old checksum file and write a fresh one."""

        checksums = self.checksums
        path = Path(self.CHECKSUMS)
        mtime = datetime.fromtimestamp(int(path.stat().st_mtime))
        stamp = mtime.strftime("%Y%m%d%H%M%S")
        path.rename(f"{self.CHECKSUMS}.{stamp}")
        with open(self.CHECKSUMS, "w", encoding="ascii") as fp:
            for pmid in sorted(checksums, key=int):
                fp.write(f"{checksums[pmid]}  articles/{pmid}.xml\n")

    def update_manifest(self):
        """Archive the old manifest file and write a fresh one."""

        path = Path(self.MANIFEST)
        mtime = datetime.fromtimestamp(int(path.stat().st_mtime))
        stamp = mtime.strftime("%Y%m%d%H%M%S")
        path.rename(f"{self.MANIFEST}.{stamp}")
        with open(self.MANIFEST, "w", encoding="ascii") as fp:
            for pmid in sorted(self.manifest, key=int):
                fp.write(f"{pmid}\t{self.manifest[pmid]}\n")

    @cached_property
    def checksums(self):
        """MD5 checksums indexed by PubMed IDs."""

        path = Path(self.CHECKSUMS)
        with path.open(encoding="ascii") as fp:
            checksums = {}
            for line in fp:
                checksum, xml_path = line.strip().split()
                pmid = xml_path.split("/")[1].split(".")[0]
                checksums[pmid] = checksum
        return checksums

    @cached_property
    def cursor(self):
        """Access to the EBMS database."""
        return DBMS().connect().cursor()

    @cached_property
    def latest(self):
        """Most recent date found in the manifest file."""
        return sorted(self.manifest.values())[-1][:10]

    @cached_property
    def logger(self):
        """Keep the operator informed"""

        basicConfig(format=self.LOG_FORMAT, level="INFO")
        return getLogger()

    @cached_property
    def new(self):
        """PubMed IDs for articles added to the EBMS since the last run."""

        select = "SELECT DISTINCT source_id FROM ebms_article WHERE import"
        self.cursor.execute(
            "SELECT DISTINCT source_id"
            "  FROM ebms_article"
            " WHERE import_date > '2023-01-01'"
        )
        pmids = {row["source_id"] for row in self.cursor.fetchall()}
        return pmids - set(self.manifest)

    @cached_property
    def manifest(self):
        """Dates/times last refreshed, indexed by PubMed IDs."""

        path = Path(self.MANIFEST)
        with path.open(encoding="ascii") as fp:
            manifest = {}
            for line in fp:
                pmid, refreshed = line.strip().split("\t")
                manifest[pmid.strip()] = refreshed
        self.logger.info("manifest has %d articles", len(manifest))
        return manifest

    @cached_property
    def opts(self):
        """Command-line optional arguments."""

        parser = ArgumentParser()
        parser.add_argument("--only-new", "-n", action="store_true")
        return parser.parse_args()

    @cached_property
    def recently_changed(self):
        """Set of PubMed IDs for articles changed since our last run."""

        recently_changed = set()
        offset = 0
        pmids = sorted(self.manifest, key=int)
        self.logger.info("Checking PubMed for changed articles")
        mdat = f"{self.latest}:3000-01-01[mdat]"
        while offset < len(pmids):
            batch = pmids[offset:offset+self.ESEARCH_BATCH_SIZE]
            offset += len(batch)
            term = "+OR+".join([f"{pmid}[pmid]" for pmid in batch])
            term = f"({term})+AND+{mdat}"
            parms = f"{self.ESEARCH_PARMS}{term}"
            tries = 5
            snooze = .5
            while tries > 0:
                try:
                    response = post(self.ESEARCH, data=parms)
                    root = etree.fromstring(response.content)
                    for node in root.findall("IdList/Id"):
                        recently_changed.add(node.text.strip())
                    break
                except Exception:
                    tries -= 0
                    if tries < 1:
                        raise Exception("failure searching PubMed articles")
                    sleep(snooze)
                    snooze *= 2
            if offset < len(pmids):
                sleep(.35)
            percent = offset / len(pmids)
            left = "=" * int(72 * percent)
            right = " " * (72 - len(left))
            stderr.write(f"\r[{left}>{right}]")
        stderr.write("\n")
        arg = len(recently_changed)
        self.logger.info("NLM reports %d recently changed articles", arg)
        return recently_changed


    class PubmedArticle:
        """Article fetched from NLM."""

        def __init__(self, xml):
            """Extract the PubMed ID and the data last revised.

            Required positional argument:
                xml - bytes for the serialized article record
            """

            root = etree.fromstring(xml)
            self.pmid = root.find("MedlineCitation/PMID").text.strip()
            self.revised = ""
            child = root.find("MedlineCitation/DateRevised")
            if child is not None:
                year = int(child.find("Year").text)
                month = int(child.find("Month").text)
                day = int(child.find("Day").text)
                self.revised = f"{year:04d}-{month:02d}-{day:02d}"


if __name__ == "__main__":
    Control().main()
