<?php

namespace Drupal\Tests\ebms_doc\FunctionalJavascript;

use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_doc\Entity\Doc;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Url;

/**
 * Test the parts of import which need a browser with JavaScript.
 *
 * @group ebms
 */
class DocTest extends WebDriverTestBase {

  protected static $modules = ['ebms_doc', 'ebms_report'];

  /**
   * Use a very simple theme.
   */
  protected $defaultTheme = 'stark';

  /**
   * Lookup map for document tag IDs.
   */
  private $tag_ids = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

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

    // Create a board and a couple of topics.
    $board = Board::create([
      'id' => 1,
      'name' => 'Test Board',
      'auto_imports' => TRUE,
    ]);
    $board->save();
    for ($i = 1; $i <= 2; $i++) {
      $values = [
        'id' => $i,
        'name' => "Topic $i",
        'board' => 1,
      ];
      Topic::create($values)->save();
    }
  }

  public function testDoc() {

    // Log in as a user with the necessary permissions.
    $permissions = ['manage documents', 'view all reports'];
    $user = $this->createUser($permissions);
    $user_name = $user->name->value;
    $this->drupalLogin($user);
    $assert_session = $this->assertSession();

    // Bring up the form.
    $post_new_doc_url = Url::fromRoute('ebms_doc.create')->toString();
    $this->drupalGet($post_new_doc_url);
    $this->createScreenshot('../testdata/screenshots/create-doc.png');
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Post New Document');

    // Fill out and submit the form.
    $form = $this->getSession()->getPage();
    $file_field = $form->findField('files[file]');
    $file_field->attachFile('/usr/local/share/testdata/test.docx');
    $form->fillField('EBMS Name', 'My test document');
    $form->checkField('boards[1]');
    $assert_session->assertWaitOnAjaxRequest();
    $form->checkField('tags[' .  $this->tag_ids['Summary'] . ']');
    $assert_session->assertWaitOnAjaxRequest();
    $form->checkField('tags[' .  $this->tag_ids['Support'] . ']');
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/create-doc-almost-ready.png');
    $form->checkField('topics[1]');
    $form->checkField('topics[2]');
    $this->createScreenshot('../testdata/screenshots/create-doc-ready.png');
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $submit_button = $form->findButton('Submit');
    $submit_button->click();
    $this->createScreenshot('../testdata/screenshots/create-doc-submitted.png');

    // Check the Doc entity's properties.
    $doc = Doc::load(1);
    $this->assertNotEmpty($doc);
    $this->assertEmpty($doc->dropped->value);
    $this->assertGreaterThanOrEqual($now, $doc->posted->value);
    $this->assertEquals('test.docx', $doc->file->entity->filename->value);
    $this->assertEquals('My test document', $doc->description->value);
    $this->assertCount(1, $doc->boards);
    $this->assertCount(2, $doc->tags);
    $this->assertCount(2, $doc->topics);
    $this->assertEquals('Test Board', $doc->boards[0]->entity->name->value);
    $this->assertEquals('Topic 1', $doc->topics[0]->entity->name->value);
    $this->assertEquals('Topic 2', $doc->topics[1]->entity->name->value);
    $this->assertEquals('Summary', $doc->tags[0]->entity->name->value);
    $this->assertEquals('Support', $doc->tags[1]->entity->name->value);

    // Check the Documents report.
    $assert_session->pageTextContainsOnce('Documents Report');
    $assert_session->pageTextContainsOnce('Documents (1)');
    $assert_session->pageTextContainsOnce('test.docx');
    $assert_session->pageTextContainsOnce("Uploaded $today by $user_name");
    $assert_session->pageTextContainsOnce('Boards: Test Board');
    $assert_session->pageTextContainsOnce('Topics: Topic 1, Topic 2');
    $assert_session->pageTextContainsOnce('Tags: Summary, Support');
    $this->clickLink('Archive');
    $this->createScreenshot('../testdata/screenshots/document-archived.png');
    $assert_session->pageTextContainsOnce('Documents (0)');
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->checkField('Include Archived Documents');
    $form->findButton('Filter')->click();
    $this->createScreenshot('../testdata/screenshots/documents-report.png');
    $this->clickLink('Restore');
    $this->createScreenshot('../testdata/screenshots/document-restored.png');

    // Navigate to the Manage Documents page. The users didn't like this
    // being on the main menu, so users have to get to the page through
    // the breadcrumbs on the Post New Document page. The Stark theme
    // doesn't show the breadcrumbs so we navigate directly.
    $manage_docs_url = Url::fromRoute('ebms_doc.list')->toString();
    $this->drupalGet($manage_docs_url);
    $this->createScreenshot('../testdata/screenshots/manage-docs.png');
    $assert_session->pageTextContainsOnce('Documents');
    $assert_session->pageTextContainsOnce('Post Document');
    $assert_session->pageTextMatches("/test.docx\\s+$today\\s+$user_name\\sEdit\\s*Archive/");
  }

}

