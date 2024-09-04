<?php

namespace Drupal\Tests\ebms_summary\FunctionalJavascript;

use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_doc\Entity\Doc;
use Drupal\ebms_summary\Entity\BoardSummaries;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\file\Entity\File;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Url;

/**
 * Test the summary pages and entities.
 *
 * @group ebms
 */
class SummaryTest extends WebDriverTestBase {

  const SUMMARY_URL = 'https://www.cancer.gov/types/breast/hp/breast-treatment-pdq';

  protected static $modules = ['ebms_summary']; // , 'ebms_report'];

  /**
   * Use a very simple theme.
   */
  protected $defaultTheme = 'stark';

  /**
   * Lookup map for document tag IDs.
   */
  private $tag_ids = [];

  /**
   * Users with the appropriate permissions.
   */
  private $board_manager = NULL;
  private $board_member = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a couple of boards and a couple of topics.
    for ($i = 1; $i <= 2; $i++) {
      Board::create([
        'id' => $i,
        'name' => "Board $i",
      ])->save();
      Topic::create([
        'id' => $i,
        'name' => "Topic $i",
        'board' => 1,
      ])->save();
    }

    // Create some users with the appropriate permissions.
    $this->board_manager = $this->createUser(['manage summaries', 'view summary pages']);
    $this->board_member = $this->createUser(['review literature', 'view summary pages']);
    $this->board_manager->set('boards', [1, 2]);
    $this->board_member->set('boards', [1]);
    $this->board_manager->save();
    $this->board_member->save();

    // Create the taxonomy terms needed by the tests.
    $tags = ['Agenda', 'Help', 'Minutes', 'Roster', 'Summary', 'Support'];
    foreach ($tags as $tag) {
      $term = Term::create([
        'vid' => 'doc_tags',
        'field_text_id' => strtolower($tag),
        'name' => $tag,
      ]);
      $term->save();
      $this->tag_ids[$tag] = $term->id();
    }

    // Create two Doc entities, one for general support.
    $now = date('Y-m-d H:i:s');
    $file = File::create([
      'uid' => $this->board_manager->id(),
      'filename' => 'support-document.txt',
      'filemime' => 'text/plain',
      'uri' => 'public://support-document.txt',
    ]);
    $file->save();
    Doc::create([
      'id' => 1,
      'file' => $file->id(),
      'description' => 'This is the support document',
      'tags' => [$this->tag_ids['Summary'], $this->tag_ids['Support']],
      'boards' => [1],
      'posted' => $now,
    ])->save();

    // And the other for the summary page.
    $file = File::create([
      'uid' => $this->board_manager->id(),
      'filename' => 'summary-document.txt',
      'filemime' => 'text/plain',
      'uri' => 'public://summary-document.txt',
    ]);
    $file->save();
    Doc::create([
      'id' => 2,
      'file' => $file->id(),
      'description' => 'This is the summary document',
      'tags' => [$this->tag_ids['Summary']],
      'boards' => [1],
      'topics' => [2],
      'posted' => $now,
    ])->save();
  }

  public function testSummaries() {

    // Bring up the landing page for summaries.
    $this->drupalLogin($this->board_manager);
    $assert_session = $this->assertSession();
    $url = Url::fromRoute('ebms_summary.board')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/choose-summaries-board.png');

    // Users with multiple boards should have to choose a board.
    $assert_session->pageTextMatches('/Summaries\s+Select a board\..+Board 1.+Board 2/');
    $this->clickLink('Board 1');
    $this->createScreenshot('../testdata/screenshots/summaries-board-1.png');
    $assert_session->pageTextMatches('/Board 1\s+Summary Pages.+This board has no summary pages yet\./');
    $assert_session->pageTextContains('Supporting Documents');
    $assert_session->pageTextMatches('/File Name\s+Notes\s+Uploaded By\s+Date\s+Archived/');
    $assert_session->pageTextContainsOnce('No supporting documents have been posted for this board yet.');
    $assert_session->pageTextContains('Post Document');

    // Add a supporting document.
    $this->clickLink('Post Document');
    $this->createScreenshot('../testdata/screenshots/supporting-document-form.png');
    $assert_session->pageTextContainsOnce('Post Document for Board 1 Summaries Page');
    $assert_session->pageTextMatches('/Select supporting document.+Add optional notes/');
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('doc', 1);
    $form->fillField('Notes', 'Yada yada yada');
    $this->createScreenshot('../testdata/screenshots/supporting-document-form-completed.png');
    $form->findButton('Save')->click();
    $this->createScreenshot('../testdata/screenshots/summaries-board-1-with-supporting-doc.png');
    $assert_session->pageTextNotContains('Post Document');
    $assert_session->pageTextMatches(
      '/This is the support document\s+Yada yada yada\s+' .
      $this->board_manager->name->value . '\s+' . date('Y-m-d') . '/'
    );

    // Create a summaries page.
    $this->clickLink('Add New Summaries Page');
    $this->createScreenshot('../testdata/screenshots/add-summaries-page-form.png');
    $assert_session->pageTextContainsOnce('Add Summary Page');
    $form = $this->getSession()->getPage();
    $form->fillField('name', 'Topics 1 & 2');
    $form->checkField('topics[1]');
    $form->checkField('topics[2]');
    $form->findButton('Save')->click();
    $this->createScreenshot('../testdata/screenshots/summaries-page-added.png');
    $assert_session->pageTextContainsOnce("Saved page 'Topics 1 & 2'");

    // Navigate to the new page.
    $this->clickLink('Topics 1 & 2');
    $this->createScreenshot('../testdata/screenshots/summaries-page.png');
    $assert_session->pageTextMatches('/Topics 1 & 2\s+Cancer.gov Summaries/');
    $assert_session->pageTextContainsOnce('No summary links are available for this page yet.');
    $assert_session->pageTextContainsOnce('No documents have been posted by NCI for this page yet.');
    $assert_session->pageTextContainsOnce('No documents are currently posted by Board members.');

    // Add a summary link.
    $this->clickLink('Add New Summary Link');
    $this->createScreenshot('../testdata/screenshots/add-summary-link.png');
    $assert_session->pageTextContainsOnce('Add Topics 1 & 2 Summary Link');
    $form = $this->getSession()->getPage();
    $form->fillField('display', 'Breast Cancer Treatment');
    $form->fillField('url', self::SUMMARY_URL);
    $form->findButton('Save')->click();
    $this->createScreenshot('../testdata/screenshots/summary-link-added.png');
    $assert_session->pageTextContainsOnce("Saved link 'Breast Cancer Treatment'");

    // Post a document to the summaries page.
    $this->clickLink('Post Document');
    $this->createScreenshot('../testdata/screenshots/post-document-to-summary-page.png');
    $assert_session->pageTextContainsOnce('Post NCI Document for Topics 1 & 2 Page');
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('doc', 2);
    $form->fillField('Notes', 'The topic 1/2 summary doc');
    $form->findButton('Save')->click();
    $this->createScreenshot('../testdata/screenshots/summary-doc-posted.png');
    $assert_session->pageTextContainsOnce('Added NCI document.');
    $assert_session->pageTextNotContains('Post Document');
    $assert_session->pageTextMatches(
      '#This is the summary document\s+The topic 1/2 summary doc\s+' .
      $this->board_manager->name->value . '\s+' . date('Y-m-d') . '#'
    );

    // Bring up the summary landing page as a board member.
    $this->drupalLogin($this->board_member);
    $assert_session = $this->assertSession();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/summaries-landing-page.png');

    // Users a single board should bypass the board choice page.
    $assert_session->pageTextMatches('/Board 1\s+Access the pages below to view the current summaries on Cancer.gov/');
    $assert_session->pageTextMatches(
      '/This is the support document\s+Yada yada yada\s+' .
      $this->board_manager->name->value . '\s+' . date('Y-m-d') . '/'
    );

    // Navigate to the summaries page.
    $this->clickLink('Topics 1 & 2');
    $this->createScreenshot('../testdata/screenshots/board-member-on-summaries-page.png');
    $assert_session->pageTextContainsOnce('No documents are currently posted by Board members.');
    $assert_session->pageTextMatches(
      '#This is the summary document\s+The topic 1/2 summary doc\s+' .
      $this->board_manager->name->value . '\s+' . date('Y-m-d') . '#'
    );
    $assert_session->pageTextMatches('/Topics 1 & 2\s+Cancer.gov Summaries.+Breast Cancer Treatment.+Documents Posted by NCI/');

    // Add a board member document.
    $this->clickLink('Post Document');
    $this->createScreenshot('../testdata/screenshots/board-member-summary-document-form.png');
    $assert_session->pageTextContainsOnce('Board Member Upload');
    $form = $this->getSession()->getPage();
    $file_field = $form->findField('files[file]');
    $file_field->attachFile('/usr/local/share/testdata/test.docx');
    $form->fillField('Notes', "I'm a little teapot");
    $form->findButton('Upload File')->click();
    $this->createScreenshot('../testdata/screenshots/summary-board-member-doc-posted.png');
    $assert_session->pageTextNotContains('No documents have been posted by board members for this page yet');
    $assert_session->pageTextMatches('/Posted document test.docx\.\s+Topics 1 & 2\s+Cancer.gov Summaries.+Breast Cancer Treatment.+Documents Posted by NCI/');
    $assert_session->pageTextMatches(
      '/test\s+I\'m a little teapot\s+' .
      $this->board_member->name->value . '\s+' . date('Y-m-d') . '/'
    );

    // Follow the summary link.
    $this->clickLink('Breast Cancer Treatment');
    $tabs = $this->getSession()->getWindowNames();
    $this->getSession()->switchToWindow($tabs[1]);
    $this->createScreenshot('../testdata/screenshots/breast-cancer-treatment.png');
    $assert_session->pageTextMatches('/Breast Cancer Treatment.*Health Professional Version/');

    // Check the properties of the BoardSummaries entity.
    $board_summaries = BoardSummaries::load(1);
    $this->assertEquals('Board 1', $board_summaries->board->entity->name->value);
    $this->assertCount(1, $board_summaries->pages);
    $this->assertEquals(1, $board_summaries->docs[0]->doc);
    $this->assertEquals('Yada yada yada', $board_summaries->docs[0]->notes);
    $this->assertEquals(1, $board_summaries->docs[0]->active);

    // Drill down into the SummaryPage entity.
    $page = $board_summaries->pages[0]->entity;
    $this->assertEquals(1, $page->id());
    $this->assertEquals('Topics 1 & 2', $page->name->value);
    $this->assertCount(2, $page->topics);
    $this->assertEquals('Topic 1', $page->topics[0]->entity->name->value);
    $this->assertEquals('Topic 2', $page->topics[1]->entity->name->value);
    $this->assertCount(1, $page->links);
    $this->assertEquals(self::SUMMARY_URL, $page->links[0]->uri);
    $this->assertEquals('Breast Cancer Treatment', $page->links[0]->title);
    $this->assertEmpty($page->links[0]->options);
    $this->assertCount(1, $page->manager_docs);
    $this->assertEquals(2, $page->manager_docs[0]->doc);
    $this->assertEquals('The topic 1/2 summary doc', $page->manager_docs[0]->notes);
    $this->assertEquals(1, $page->manager_docs[0]->active);
    $this->assertCount(1, $page->member_docs);
    $this->assertEquals("I'm a little teapot", $page->member_docs[0]->notes);
    $this->assertEquals(1, $page->member_docs[0]->active);
  }

}
