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
 * Test the full-text retrieval queue.
 *
 * @group mysql
 */
class FullTextTest extends WebDriverTestBase {

  protected static $modules = ['ebms_article', 'ebms_import'];

  /**
   * Use a very simple theme.
   */
  protected $defaultTheme = 'stark';

  /**
   * Test the queue.
   */
  public function testFullTextQueue() {


    // Create the taxonomy term needed by the tests.
    $state_values = [
      ['published', 'Published', 40],
      ['passed_bm_review', 'Passed Board Manager', 50],
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

    // Create a board, a few topics, and pair of articles.
    Board::create(['id' => 1, 'name' => 'Test Board'])->save();
    Topic::create(['id' => 1, 'name' => 'Test Topic 1', 'board' => 1])->save();
    for ($i = 1; $i <= 2; ++$i) {
      $article = Article::create([
        'id' => $i,
        'title' => "Test Article $i",
        'source_id' => 10000000 + $i,
      ]);
      $article->addState('passed_bm_review', 1);
      $article->save();
    }
    // Log on as an admin assistant,
    $admin_assistant = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['admin_assistant']]);
    $this->drupalLogin($admin_assistant);

    // Bring up the full-text retrieval queue page.
    $url = Url::fromRoute('ebms_article.full_text_queue')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/full-text-queue.png');
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Full Text Retrieval Queue');
    $assert_session->pageTextContains('Articles which require PDFs (2)');
    $assert_session->pageTextContains('Test Article 1');
    $assert_session->pageTextContains('Test Article 2');
    $assert_session->pageTextMatchesCount(2, '/Boards: Test Board/');

    // Attach the files to the form.
    $form = $this->getSession()->getPage();
    $test_directory = '/usr/local/share/testdata';
    $test_filename = 'test.pdf';
    $form->findField('files[full-text-1]')->attachFile("$test_directory/$test_filename");
    $form->findField('files[full-text-2]')->attachFile("$test_directory/$test_filename");
    $this->createScreenshot('../testdata/screenshots/full-text-queue-files-queued.png');

    // Upload the files
    $submit_button = $form->findButton('Upload');
    $submit_button->click();
    $this->createScreenshot('../testdata/screenshots/full-text-queue-files-uploaded.png');
    $assert_session->pageTextContains('Posted test.pdf for article 1.');
    $assert_session->pageTextContains('Posted test.pdf for article 2.');
    $assert_session->pageTextContains('Articles which require PDFs (0)');
    $assert_session->pageTextNotContains('Test Article 1');
    $assert_session->pageTextNotContains('Test Article 2');
    $assert_session->pageTextMatchesCount(0, '/Boards: Test Board/');

    // Verify the stored values.
    $article = Article::load(1);
    $this->assertEmpty($article->full_text->unavailable);
    $this->assertNotEmpty($article->full_text->file);
    $file = File::load($article->full_text->file);
    $this->assertNotEmpty($file);
    $this->assertEquals('test.pdf', $file->filename->value);
    $this->assertEquals('public://test.pdf', $file->uri->value);
    $this->assertEquals('application/pdf', $file->filemime->value);
    $this->assertEquals(965, $file->filesize->value);
    $this->assertNotEmpty($file->status->value);
    $article = Article::load(2);
    $this->assertEmpty($article->full_text->unavailable);
    $this->assertNotEmpty($article->full_text->file);
    $file = File::load($article->full_text->file);
    $this->assertNotEmpty($file);
    $this->assertEquals('test_0.pdf', $file->filename->value);
    $this->assertEquals('public://test_0.pdf', $file->uri->value);
    $this->assertEquals('application/pdf', $file->filemime->value);
    $this->assertEquals(965, $file->filesize->value);
    $this->assertNotEmpty($file->status->value);
  }

}

