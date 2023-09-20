<?php

namespace Drupal\Tests\ebms_article\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\ebms_article\Entity\Article;
use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\file\Entity\File;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;

/**
 * Test the board member search page.
 *
 * @group ebms
 */
class SearchTest extends WebDriverTestBase {

  protected static $modules = ['ebms_article'];
  protected $defaultTheme = 'stark';

  /**
   * Test the search page.
   */
  public function testBoardMemberSearch() {


    // Create the taxonomy term needed by the tests.
    $state_values = [
      ['published', 'Published', 40],
      ['passed_full_review', 'Passed full text review', 60],
    ];
    $states = [];
    foreach ($state_values as list($text_id, $name, $sequence)) {
      $term = Term::create([
        'vid' => 'states',
        'field_text_id' => $text_id,
        'name' => $name,
        'field_sequence' => $sequence,
      ]);
      $term->save();
      $states[$text_id] = $term->id();
    }

    // Create some boards, topics, and articles.
    Board::create(['id' => 1, 'name' => 'Test Board 1'])->save();
    Board::create(['id' => 2, 'name' => 'Test Board 2'])->save();
    Topic::create(['id' => 1, 'name' => 'Test Topic 1', 'board' => 1])->save();
    Topic::create(['id' => 2, 'name' => 'Test Topic 2', 'board' => 1])->save();
    Topic::create(['id' => 3, 'name' => 'Test Topic 3', 'board' => 2])->save();
    Topic::create(['id' => 4, 'name' => 'Test Topic 4', 'board' => 2])->save();
    $surnames = ['Wang', 'Kholová', 'Fuentes', 'Smith'];
    $journals = ['Adiktologie', 'Advances in neurology', 'Cancer science'];
    $brief = ['Adiktologie', 'Adv Neurol', 'Cancer Sci'];
    for ($i = 1; $i <= 10; ++$i) {
      File::create(['fid' => 1000 + $i, 'uri' => "public://foo-$i.pdf"])->save();
      $article = Article::create([
        'id' => $i,
        'title'  => "Test Article $i",
        'authors' => [
          ['last_name' => $surnames[($i+0) % 4], 'initials' => chr($i*5%26+65) . chr($i*13%26+65)],
          ['last_name' => $surnames[($i+1) % 4], 'initials' => chr($i*7%26+65) . chr($i*11%26+65)],
          ['last_name' => $surnames[($i+2) % 4], 'initials' => chr($i*9%26+65) . chr($i*15%26+65)],
        ],
        'source' => 'Pubmed',
        'source_id' => 10000000 + $i,
        'journal_title' => $journals[$i % 3],
        'brief_journal_title' => $brief[$i % 3],
        'year' => 2020 + $i % 2,
        'pub_date' => ['year' => 2020 + $i % 2, 'month' => sprintf("%02d", $i % 5 + 1)],
        'full_text' => ['file' => 1000 + $i],
      ]);
      $article->addState('passed_full_review', $i % 4 + 1);
      $article->save();
    }

    // Log on as an board_member,
    $board_member = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['board_member']]);
    $this->drupalLogin($board_member);
    $this->getSession()->resizeWindow(1024, 1024, 'current');

    // Bring up the search page.
    $url = Url::fromRoute('ebms_article.search_form')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/board-member-search.png');
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Article Search');

    // Search without criteria should find all articles.
    $this->getSession()->getPage()->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-search-everything.png');
    $assert_session->pageTextContains('10 Articles Found');

    // Try narrowing by board.
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('board[]', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/board-member-search-selected-board.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-search-articles-for-board.png');
    $assert_session->pageTextContains('5 Articles Found');
    $assert_session->pageTextMatches('/10000001.+10000004.+10000005.+10000008.+10000009/');

    // Test changing the sort order for the results set.
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form = $this->getSession()->getPage();
    $form->findButton('Display Options')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-search-sort-options.png');
    if (is_dir('../dross')) {
      file_put_contents('../dross/search-display-options.html', $form->getHtml());
    }
    $form->findById('edit-sort-author')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-search-sort-by-author.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-search-sorted-by-author.png');
    $assert_session->pageTextMatches('/10000001.+10000009.+10000005.+10000008.+10000004/');

    // Narrow further by topic.
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('board[]', 2);
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->pageTextMatches('/Test Topic 3\s+Test Topic 4/');
    $form->selectFieldOption('topic[]', 3);
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-search-articles-for-topic.png');
    $assert_session->pageTextContains('3 Articles Found');
    $assert_session->pageTextMatches('/10000006.+10000002.+10000010/');

    // Add a second topic.
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('topic[]', 4, TRUE);
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-two-topics-selected.png');
    $assert_session->pageTextContains('5 Articles Found');
    $assert_session->pageTextMatches('/10000006.+10000002.+10000010.+10000007.+10000003/');

    // Switch the topic logic to AND, which should produce an empty set.
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form = $this->getSession()->getPage();
    $form->findById('edit-topic-logic-and')->click();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-topic-logic-and.png');
    $assert_session->pageTextContains('0 Articles Found');
    $assert_session->pageTextContains('No articles match the search criteria');

    // Add a second topic to one of the articles and try again.
    $article = Article::load(2);
    $article->addState('passed_full_review', 4);
    $article->save();
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $this->getSession()->getPage()->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-article-with-both-topics.png');
    $assert_session->pageTextContains('1 Article Found');
    $assert_session->pageTextContains('Test Article 2');
    $assert_session->pageTextContains('Topics: Test Topic 3; Test Topic 4');

    // Search by PubMed ID. Can't test the quick PMID search here because
    // of bug https://www.drupal.org/project/uswds_base/issues/3359804.
    // So instead we test the quick PMID search as part of the tests of
    // the home page, where we can use the EBMS theme without triggering
    // that bug.
    $this->drupalGet($url);
    $form = $this->getSession()->getPage();
    $form->fillField('PubMed ID', '10000002');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-search-by-pmid.png');
    $assert_session->pageTextContains('1 Article Found');
    $assert_session->pageTextContains('Fuentes KA; Smith OW; Wang SE');
    $assert_session->pageTextContains('Test Article 2');
    $assert_session->pageTextContains('PMID: 10000002');
    $assert_session->pageTextContains('Topics: Test Topic 3');

    // Test searching by the first author using a wildcard.
    $this->drupalGet($url);
    $form = $this->getSession()->getPage();
    $form->fillField('Author', 'Kholová%');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-first-author-search-with-wildcard.png');
    $assert_session->pageTextContains('3 Articles Found');
    $assert_session->pageTextMatches('/10000001.+10000005.+10000009/');

    // Same thing, but for the author in the last position.
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form->findById('edit-author-position-last')->click();
    $this->getSession()->getPage()->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-last-author-search-with-wildcard.png');
    $assert_session->pageTextContains('2 Articles Found');
    $assert_session->pageTextMatches('/10000003.+10000007/');

    // Same thing again, but with the author in any position.
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form->findById('edit-author-position-any')->click();
    $this->getSession()->getPage()->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-any-author-search-with-wildcard.png');
    $assert_session->pageTextContains('7 Articles Found');
    $assert_session->pageTextMatches('/10000001.+10000003.+10000004.+10000005.+10000007.+10000008.+10000009/');

    // Search for an exact author (no wildcards).
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form = $this->getSession()->getPage();
    $form->fillField('Author', 'Kholová CS');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-any-author-search-no-wildcards.png');
    $assert_session->pageTextContains('1 Article Found');
    $assert_session->pageTextContains('Wang UA; Kholová CS; Fuentes KI');
    $assert_session->pageTextContains('Test Article 4');
    $assert_session->pageTextContains('PMID: 10000004');
    $assert_session->pageTextContains('Topics: Test Topic 1');

    // Search by the exact title (no wildcards).
    $this->drupalGet($url);
    $form = $this->getSession()->getPage();
    $form->fillField('Title', 'Test Article 8');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-search-exact-title.png');
    $assert_session->pageTextContains('1 Article Found');
    $assert_session->pageTextContains('PMID: 10000008');

    // Try an article title search with a wildcard.
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form = $this->getSession()->getPage();
    $form->fillField('Title', 'Test Article 1%');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-title-search-with-wildcard.png');
    $assert_session->pageTextContains('2 Articles Found');
    $assert_session->pageTextMatches('/10000001.+10000010/');

    // Test a journal title search without wildcards.
    $this->drupalGet($url);
    $form->fillField('Journal Title', 'Adiktologie');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-exact-journal-title-search.png');
    $assert_session->pageTextContains('3 Articles Found');
    $assert_session->pageTextMatches('/10000003.+10000006.+10000009/');

    // Try a journal title search with a wildcard.
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form = $this->getSession()->getPage();
    $form->fillField('Journal Title', 'Ad%');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-journal-title-search-with-wildcard.png');
    $assert_session->pageTextContains('7 Articles Found');
    $assert_session->pageTextMatches('/10000001.+10000003.+10000004.+10000006.+10000007.+10000009.+10000010/');

    // Search by publication year for the article.
    $this->drupalGet($url);
    $form->selectFieldOption('publication-year', 2020);
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-search-publication-year.png');
    $assert_session->pageTextContains('5 Articles Found');
    $assert_session->pageTextMatches('/10000002.+10000004.+10000006.+10000008.+10000010/');

    // Refine the search by adding the month of publication.
    $this->getSession()->getPage()->findLink('Refine Search')->click();
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('publication-month', 2);
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/board-member-search-publication-year-and-month.png');
    $assert_session->pageTextContains('1 Article Found');
    $assert_session->pageTextContains('Test Article 6');
    $assert_session->pageTextContains('PMID: 10000006');
  }

}

