#!/usr/bin/env python3

"""Refresh EBMS articles from NLM's PubMed XML.

Processing logic.

    1. Collect information about all the articles we have.
    2. Find out from NLM which articles have been updated recently.
    3. Compare their dates with ours.
    4. Refresh articles for which our dates are not newer than NLM's.

The actual refresh is handled by the PHP code for the site, which also
takes care of notifying those who are registered for the report.

The last step in the logic refreshes any article for which our date for
the last update and NLM's date for the most recent change are the same,
in case NLM changed the article later on the same day when we last picked
up the XML. Won't happen very often, but it's important not to miss those
updates.

See https://tracker.nci.nih.gov/browse/OCEEBMS-87
and https://tracker.nci.nih.gov/browse/OCEEBMS-687.
"""

from argparse import ArgumentParser
from datetime import datetime
from functools import cached_property
from logging import basicConfig, getLogger
from pathlib import Path
from sys import stdin, stderr
from time import sleep
from lxml import etree
from requests import get, post


class Control:

    ESEARCH = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi"
    ESEARCH_PARMS = "db=pubmed&retmax=5000&term="
    ESEARCH_BATCH_SIZE = 1000
    EFETCH = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi"
    EFETCH_PARMS = "db=pubmed&id="
    EFETCH_BATCH_SIZE = 100
    ROOTS = "/local/drupal/ebms", "/var/www", "/var/www/ebms"
    IMPORT_DATES = "articles/import/dates"
    IMPORT_REFRESH = "articles/import/refresh"
    EBMS_CORE = "web/modules/custom/ebms_core"
    LOG_NAME = "update-pubmed-data.log"
    OPEN, CLOSE = b"<PubmedArticle>", b"</PubmedArticle>"
    ARTICLE_SET = b"<PubmedArticleSet>"
    DAYS_TO_CHECK = 15

    def main(self):
        started = datetime.now()
        self.logger.info("-" * 40)
        self.logger.info("job started")
        try:
            if self.stale_articles:
                url = f"{self.base_url}/{self.IMPORT_REFRESH}"
                pmids = ",".join(sorted(self.stale_articles, key=int))
                response = post(url, data=dict(pmids=pmids))
                self.logger.info(response.text)
        except Exception:
            self.logger.exception("refresh job failure")
        elapsed = datetime.now() - started
        self.logger.info("job finished (%s)", elapsed)
        self.logger.info("-" * 40)

    @cached_property
    def base_url(self):
        """Find the address for talking to the EBMS."""

        if self.opts.base_url:
            return self.opts.base_url
        path = self.root / "unversioned/sitehost"
        if path.exists():
            host = path.read_text().strip()
            scheme = "http" if "localhost" in host else "https"
            return f"{scheme}://{host}"
        raise Exception("Unable to find site host")

    @cached_property
    def ebms_dates(self):
        """Dictionary of last-refresh dates indexed by Pubmed IDs."""

        if self.opts.pipe_dates:
            lines = stdin
        else:
            url = f"{self.base_url}/{self.IMPORT_DATES}"
            response = get(url)
            lines = response.text.splitlines()
        dates = {}
        for line in lines:
            id, pmid, refreshed = line.strip().split("\t")
            pmid = pmid.strip()
            dates[pmid] = refreshed
        self.logger.info("fetched dates for %d articles", len(dates))
        return dates

    @cached_property
    def logger(self):
        """Used to record what we do."""

        fmt = "%(asctime)s [%(levelname)s] %(message)s"
        path = f"{self.root}/logs/{self.LOG_NAME}"
        basicConfig(format=fmt, level="INFO", filename=path)
        return getLogger()

    @cached_property
    def opts(self):
      """Run-time options."""

      parser = ArgumentParser()
      parser.add_argument("--verbose", "-v", action="store_true")
      parser.add_argument("--pipe-dates", "-p", action="store_true")
      parser.add_argument("--root", "-r")
      parser.add_argument("--base-url", "-b")
      return parser.parse_args()

    @cached_property
    def root(self):
        """Find the base directory for the site."""

        if self.opts.root:
            return self.opts.root
        for root in self.ROOTS:
            path = Path(root)
            if (path / self.EBMS_CORE).exists():
                return path
        raise Exception("Unable to locate base directory for site")

    @cached_property
    def recently_changed_articles(self):
        """Sequence of PubMed IDs for articles which were modified recently."""

        pmids = sorted(self.ebms_dates, key=int)
        offset = 0
        recent = set()
        if self.verbose:
          msg = f"Checking {len(pmids)} articles to see which are changed\n"
          stderr.write(msg)
        while offset < len(pmids):
            batch = pmids[offset:offset+self.ESEARCH_BATCH_SIZE]
            offset += len(batch)
            term = "+OR+".join([f"{pmid}[pmid]" for pmid in batch])
            term = f'({term})+AND+"last {self.DAYS_TO_CHECK} days"[mdat]'
            parms = f"{self.ESEARCH_PARMS}{term}"
            tries = 10
            snooze = .5
            while tries > 0:
                try:
                    response = post(self.ESEARCH, data=parms)
                    root = etree.fromstring(response.content)
                    for node in root.findall("IdList/Id"):
                        pmid = node.text
                        if pmid:
                            pmid = pmid.strip()
                            if pmid:
                                recent.add(pmid)
                    break
                except Exception:
                    tries -= 0
                    self.logger.exception("MDAT search; %d tries left", tries)
                    if tries < 1:
                        raise Exception("Failure searching PubMed articles")
                    sleep(snooze)
                    snooze *= 2
            if offset < len(pmids):
                sleep(.35)
            if self.verbose:
                percent = offset / len(pmids)
                left = "=" * int(72 * percent)
                right = " " * (72 - len(left))
                stderr.write(f"\r[{left}>{right}]")
        self.logger.info("found %d recently changed articles", len(recent))
        return recent

    @cached_property
    def stale_articles(self):
        """Sequence of PubMed IDs for articles which need to be refreshed."""

        pmids = sorted(self.recently_changed_articles, key=int)
        n = len(pmids)
        offset = 0
        stale = set()
        if self.verbose and pmids:
              stderr.write(f"\nChecking {n} articles to see which are stale\n")
        while offset < n:
            chunk = pmids[offset:offset+self.EFETCH_BATCH_SIZE]
            offset += len(chunk)
            response = self._fetch_articles(chunk)
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
                    if article.pmid in self.ebms_dates:
                        if article.revised >= self.ebms_dates[article.pmid]:
                            stale.add(article.pmid)
                except Exception as e:
                    self.logger.exception("parsing Pubmed article")
            if offset < n:
                sleep(.35)
            if self.verbose:
                percent = offset / n
                left = "=" * int(72 * percent)
                right = " " * (72 - len(left))
                stderr.write(f"\r[{left}>{right}]")
        if self.verbose:
            if stale:
                msg = f"\nwaiting for refresh of {len(stale)} articles\n"
                stderr.write(msg)
            else:
                stderr.write("\nno articles need refreshing\n")
        self.logger.info("identified %d stale articles", len(stale))
        return stale

    @cached_property
    def verbose(self):
      """Should we display progress?"""
      return self.opts.verbose

    def _fetch_articles(self, pmids):
        """Retrieve the XML for a batch of PubMed articles from NLM.

        Required positional argument:
            pmids - sequence of strings for the PubMed IDs for the articles

        Return:
            bytes for the response from NLM's API
        """

        parms = self.EFETCH_PARMS + ",".join(pmids)
        tries = 10
        snooze = .5
        while tries > 0:
            try:
                response = post(self.EFETCH, data=parms)
                if self.ARTICLE_SET in response.content:
                    return response.content
                raise Exception("fetch response: %s", response.content)
            except Exception as e:
                tries -= 1
                self.logger.exception("fetch: %d tries left", tries)
                if tries < 1:
                    raise Exception("Failure fetching PubMed articles")
                sleep(snooze)
                snooze *= 2


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
