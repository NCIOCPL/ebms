<?php

namespace Drupal\Tests\ebms_review\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\ebms_article\Entity\Article;
use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_review\Form\ReviewQueue;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\file\Entity\File;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;

/**
 * Test the review/processing queues.
 *
 * @group ebms
 */
class QueueTest extends WebDriverTestBase {

  protected static $modules = ['ebms_review', 'ebms_import'];

  /**
   * Use a very simple theme.
   */
  protected $defaultTheme = 'stark';

  /**
   * Test the queues.
   */
  public function testQueues() {


    // Create the taxonomy terms needed by the tests.
    $state_values = [
      ['ready_init_review', 'Ready for initial review', 10],
      ['reject_init_review', 'Rejected in initial review', 30],
      ['passed_init_review', 'Passed initial review', 30],
      ['published', 'Published', 40],
      ['reject_bm_review', 'Rejected by Board Manager', 50],
      ['passed_bm_review', 'Passed Board Manager', 50],
      ['reject_full_review', 'Rejected after full fext review', 60],
      ['passed_full_review', 'Passed full text review', 60],
      ['fyi', 'Flagged as FYI', 60],
      ['on_hold', 'On Hold', 70]
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
    Topic::create(['id' => 2, 'name' => 'Test Topic 2', 'board' => 1])->save();
    Topic::create(['id' => 3, 'name' => 'Test Topic 3', 'board' => 1])->save();
    for ($i = 1; $i <= 2; ++$i) {
      File::create([
        'fid' => 1000 + $i,
        'uid' => 1,
        'filename' => "file-$i.pdf",
        'uri' => "public://file-$i.pdf",
        'status' => 1,
      ])->save();
      $article = Article::create([
        'id' => $i,
        'title' => "Test Article $i",
        'source_id' => 10000000 + $i,
        'full_text' => ['file' => 1000 + $i],
      ]);
      $article->addState('ready_init_review', 1);
      $article->save();
      $article->addState('ready_init_review', 2);
      $article->save();
    }

    // Load the article type hierarchy.
    $types = file_get_contents('../testdata/pubtypes.json');
    $connection = \Drupal::service('database');
    $connection->insert('on_demand_config')
      ->fields([
        'name' => 'article-type-ancestors',
        'value' => $types,
      ])
      ->execute();

    // Log on as a medical librarian,
    $librarian = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['medical_librarian']]);
    $this->drupalLogin($librarian);

    // Bring up the librarian's review queue page.
    $url = Url::fromRoute('ebms_review.review_queue')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/librarian-review-queue.png');
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Librarian Review Queue');
    $assert_session->pageTextContains('Articles Waiting for Librarian Review (2)');
    $assert_session->pageTextContains('Test Article 1');
    $assert_session->pageTextContains('Test Article 2');

    // Record a decision.
    $decision_keys = [];
    foreach (ReviewQueue::DECISION_STATES['Librarian Review'] as $key => $text_id) {
      $decision_keys[$text_id] = $key;
    }
    $form = $this->getSession()->getPage();
    $passed_init_review = $decision_keys['passed_init_review'];
    $form->findById("topic-action-1-1-$passed_init_review")->click();
    $assert_session->assertWaitOnAjaxRequest();
    // For debugging.
    // file_put_contents('../testdata/screenshots/librarian-queue.html', $form->getHtml());
    $this->createScreenshot('../testdata/screenshots/librarian-queue-topic-approved.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/librarian-queue-first-decision-submitted.png');

    // Verify the decision.
    // As it turns out, the testing harness has some of its own caching going on,
    // preventing it from seeing the correct entity values. Clear these caches
    // or the tests will fail.
    \Drupal::entityTypeManager()->getStorage('ebms_article')->resetCache();
    \Drupal::entityTypeManager()->getStorage('ebms_article_topic')->resetCache();
    $assert_session->pageTextContains('Queued decisions have been applied.');
    $article = Article::load(1);
    $this->assertEquals(2, $article->topics->count());
    $this->assertEquals('ready_init_review', $article->getCurrentState(2)->value->entity->field_text_id->value);
    $this->assertEquals('passed_init_review', $article->getCurrentState(1)->value->entity->field_text_id->value);

    // Filter the queue.
    $form = $this->getSession()->getPage();
    foreach (['Filter', 'Display'] as $name) {
      $accordion = $form->findButton("$name Options");
      $this->assertNotEmpty($accordion);
      $accordion->click();
    }
    $form->selectFieldOption('board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->pageTextContains('Test Topic 1 (1)');
    $assert_session->pageTextContains('Test Topic 2 (2)');
    $assert_session->pageTextContains('Test Topic 3 (0)');
    $this->createScreenshot('../testdata/screenshots/librarian-review-queue-topics.png');
    $form->selectFieldOption('topic[]', 1);
    $this->createScreenshot('../testdata/screenshots/librarian-review-queue-topic-selected.png');
    $form->findButton('Filter')->click();
    $form = $this->getSession()->getPage();
    $form->findButton('Filter Options')->click();
    $this->createScreenshot('../testdata/screenshots/librarian-review-queue-filtered.png');

    // Confirm that the filtering worked.
    $assert_session->pageTextContains('Articles Waiting for Librarian Review (1)');
    $assert_session->pageTextContains('Test Article 2');
    $assert_session->pageTextNotContains('Test Article 1');

    // Add a new topic to the article in the filtered queue.
    $form->findButton('Add Topic')->click();
    $form->selectFieldOption('board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('topic', 3);
    $form->selectFieldOption('cycle', date('Y-m-01'));
    $this->createScreenshot('../testdata/screenshots/librarian-review-queue-add-topic-form.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/librarian-review-queue-new-topic-added.png');
    $assert_session->pageTextContains('Articles Waiting for Librarian Review (1)');
    $assert_session->pageTextContains('Test Article 2');
    $assert_session->pageTextNotContains('Test Article 1');
    $assert_session->pageTextContains('Test Topic 1');
    $assert_session->pageTextContains('Test Topic 2');
    $assert_session->pageTextContains('Test Topic 3');

    // Undo the filtering.
    $form = $this->getSession()->getPage();
    $form->findButton('Reset')->click();
    $this->createScreenshot('../testdata/screenshots/librarian-review-queue-reset.png');
    $assert_session->pageTextContains('Articles Waiting for Librarian Review (2)');
    $assert_session->pageTextContains('Test Article 1');
    $assert_session->pageTextContains('Test Article 2');
    $form = $this->getSession()->getPage();

    // Submit multiple decisions in a batch.
    $reject_init_review = $decision_keys['reject_init_review'];
    $form->findById("topic-action-2-1-$passed_init_review")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findById("topic-action-2-2-$reject_init_review")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findById("topic-action-2-3-$passed_init_review")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/librarian-queue-multiple-decisions-queued.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/librarian-queue-multiple-decisions-submitted.png');
    $assert_session->pageTextContains('Queued decisions have been applied.');
    $assert_session->pageTextContains('Articles Waiting for Librarian Review (1)');
    $assert_session->pageTextContains('Test Article 1');
    $assert_session->pageTextNotContains('Test Article 2');
    \Drupal::entityTypeManager()->getStorage('ebms_article')->resetCache();
    \Drupal::entityTypeManager()->getStorage('ebms_article_topic')->resetCache();
    $article = Article::load(2);
    $this->assertEquals(3, $article->topics->count());
    $this->assertEquals('passed_init_review', $article->getCurrentState(1)->value->entity->field_text_id->value);
    $this->assertEquals('reject_init_review', $article->getCurrentState(2)->value->entity->field_text_id->value);
    $this->assertEquals('passed_init_review', $article->getCurrentState(3)->value->entity->field_text_id->value);

    // Make the last decision in the queue.
    $form = $this->getSession()->getPage();
    $form->findById("topic-action-1-2-$passed_init_review")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/librarian-queue-last-decision-queued.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/librarian-queue-last-decision-submitted.png');
    $assert_session->pageTextContains('Queued decisions have been applied.');
    $assert_session->pageTextContains('Articles Waiting for Librarian Review (0)');
    $assert_session->pageTextContains('No articles match the filtering criteria.');
    \Drupal::entityTypeManager()->getStorage('ebms_article')->resetCache();
    \Drupal::entityTypeManager()->getStorage('ebms_article_topic')->resetCache();
    $article = Article::load(1);
    $this->assertEquals(2, $article->topics->count());
    $this->assertEquals('passed_init_review', $article->getCurrentState(1)->value->entity->field_text_id->value);
    $this->assertEquals('passed_init_review', $article->getCurrentState(2)->value->entity->field_text_id->value);

    // Bring up the "publication" queue.
    $url = Url::fromRoute('ebms_review.publish')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/publication-queue.png');
    $assert_session->pageTextContains('Publish Articles');
    $assert_session->pageTextContains('Unpublished Articles (2)');
    $assert_session->pageTextContains('Test Article 1');
    $assert_session->pageTextContains('Test Article 2');
    $assert_session->pageTextMatchesCount(2, '/Test Board: Test Topic 1/');
    $assert_session->pageTextMatchesCount(1, '/Test Board: Test Topic 2/');
    $assert_session->pageTextMatchesCount(1, '/Test Board: Test Topic 3/');

    // Publish all but one of the article-topic combinations.
    $form = $this->getSession()->getPage();
    $form->findButton('Select All')->click();
    $this->createScreenshot('../testdata/screenshots/publication-queue-all-selected.png');
    $form = $this->getSession()->getPage();
    $form->findById('unpublished-topic-1-1')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/publication-queue-all-but-one-seleccted.png');
    $form->findButton('Batch Publish')->click();
    $this->createScreenshot('../testdata/screenshots/publication-queue-all-but-one-published.png');
    $assert_session->pageTextContains('Unpublished Articles (1)');
    $assert_session->pageTextContains('Test Article 1');
    $assert_session->pageTextNotContains('Test Article 2');
    $assert_session->pageTextMatchesCount(1, '/Test Board: Test Topic 1/');
    $assert_session->pageTextMatchesCount(0, '/Test Board: Test Topic 2/');
    $assert_session->pageTextMatchesCount(0, '/Test Board: Test Topic 3/');

    // Publish the last article-topic combination.
    $form = $this->getSession()->getPage();
    $form->findButton('Select All')->click();
    $this->createScreenshot('../testdata/screenshots/publication-queue-last-selected.png');
    $form = $this->getSession()->getPage();
    $form->findButton('Batch Publish')->click();
    $this->createScreenshot('../testdata/screenshots/publication-queue-all-published.png');
    $assert_session->pageTextContains('Unpublished Articles (0)');
    $assert_session->pageTextContains('No unpublished articles match the filtering criteria.');

    // Check the current statuses.
    \Drupal::entityTypeManager()->getStorage('ebms_article')->resetCache();
    \Drupal::entityTypeManager()->getStorage('ebms_article_topic')->resetCache();
    $article = Article::load(1);
    $this->assertEquals(2, $article->topics->count());
    $this->assertEquals('published', $article->getCurrentState(1)->value->entity->field_text_id->value);
    $this->assertEquals('published', $article->getCurrentState(2)->value->entity->field_text_id->value);
    $article = Article::load(2);
    $this->assertEquals(3, $article->topics->count());
    $this->assertEquals('published', $article->getCurrentState(1)->value->entity->field_text_id->value);
    $this->assertEquals('reject_init_review', $article->getCurrentState(2)->value->entity->field_text_id->value);
    $this->assertEquals('published', $article->getCurrentState(3)->value->entity->field_text_id->value);

    // Switch to a board manager account.
    $board_manager = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['board_manager'], 'boards' => [1]]);
    $this->drupalLogin($board_manager);

    // Navigate to the review queue.
    $url = Url::fromRoute('ebms_review.review_queue')->toString();
    $this->drupalGet($url);
    $form = $this->getSession()->getPage();
    foreach (['Queue Selection', 'Filter Options'] as $label) {
      $accordion = $form->findButton($label);
      $this->assertNotEmpty($accordion);
      $accordion->click();
    }
    $this->createScreenshot('../testdata/screenshots/abstract-review-queue.png');

    // The default queue for a board manager should be the abstract review queue.
    $assert_session->pageTextContains('Abstract Review Queue');
    $assert_session->pageTextContains('Articles Waiting for Abstract Review (2)');
    $assert_session->pageTextContains('Test Article 1');
    $assert_session->pageTextContains('Test Article 2');
    $assert_session->pageTextMatchesCount(2, '/Test Board Topics/');
    $assert_session->pageTextMatchesCount(2, '/Test Topic 1/');
    $assert_session->pageTextMatchesCount(2, '/Test Topic 2/');
    $assert_session->pageTextMatchesCount(1, '/Test Topic 3/');
    $assert_session->pageTextMatchesCount(4, '/None\s+Reject\s+Approve/');

    // Make the abstract review decisions.
    $decision_keys = [];
    foreach (ReviewQueue::DECISION_STATES['Abstract Review'] as $key => $text_id) {
      $decision_keys[$text_id] = $key;
    }
    $rejected_from_abstract = $decision_keys['reject_bm_review'];
    $approved_from_abstract = $decision_keys['passed_bm_review'];
    $form->findById("topic-action-1-1-$approved_from_abstract")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findById("topic-action-1-2-$rejected_from_abstract")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findById("topic-action-2-1-$approved_from_abstract")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findById("topic-action-2-3-$approved_from_abstract")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/abstract-review-queue-decisions-ready.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/abstract-review-queue-decisions-stored.png');
    $assert_session->pageTextContains('Queued decisions have been applied.');
    $assert_session->pageTextContains('Test Topic 1 (0)');
    $assert_session->pageTextContains('Test Topic 2 (0)');
    $assert_session->pageTextContains('Test Topic 3 (0)');
    $assert_session->pageTextContains('Articles Waiting for Abstract Review (0)');
    $assert_session->pageTextContains('No articles match the filtering criteria.');
    $assert_session->pageTextNotContains('Test Article 1');
    $assert_session->pageTextNotContains('Test Article 2');

    // Check the current statuses.
    \Drupal::entityTypeManager()->getStorage('ebms_article')->resetCache();
    \Drupal::entityTypeManager()->getStorage('ebms_article_topic')->resetCache();
    $article = Article::load(1);
    $this->assertEquals(2, $article->topics->count());
    $this->assertEquals('passed_bm_review', $article->getCurrentState(1)->value->entity->field_text_id->value);
    $this->assertEquals('reject_bm_review', $article->getCurrentState(2)->value->entity->field_text_id->value);
    $article = Article::load(2);
    $this->assertEquals(3, $article->topics->count());
    $this->assertEquals('passed_bm_review', $article->getCurrentState(1)->value->entity->field_text_id->value);
    $this->assertEquals('reject_init_review', $article->getCurrentState(2)->value->entity->field_text_id->value);
    $this->assertEquals('passed_bm_review', $article->getCurrentState(3)->value->entity->field_text_id->value);

    // Move to the Full Text Review queue.
    $form = $this->getSession()->getPage();
    $form->findById('edit-type-full-text-review')->click();
    $form->findButton('Filter')->click();
    $form = $this->getSession()->getPage();
    foreach (['Queue Selection', 'Filter Options', 'Display Options'] as $label) {
      $accordion = $form->findButton($label);
      $this->assertNotEmpty($accordion);
      $accordion->click();
    }
    $this->createScreenshot('../testdata/screenshots/full-text-review-queue.png');
    $assert_session->pageTextContains('Full Text Review Queue');
    $assert_session->pageTextContains('Articles Waiting for Full Text Review (2)');
    $assert_session->pageTextContains('Test Article 1');
    $assert_session->pageTextContains('Test Article 2');
    $assert_session->pageTextMatchesCount(2, '/Test Board Topics/');
    $assert_session->pageTextMatchesCount(2, '/Test Topic 1/');
    $assert_session->pageTextMatchesCount(2, '/Test Topic 2/');
    $assert_session->pageTextMatchesCount(1, '/Test Topic 3/');
    $assert_session->pageTextMatchesCount(3, '/None\s+FYI\s+On Hold\s+Reject\s+Approve/');

    // Apply the full-text-review decisions.
    $decision_keys = [];
    foreach (ReviewQueue::DECISION_STATES['Full Text Review'] as $key => $text_id) {
      $decision_keys[$text_id] = $key;
    }
    $fyi = $decision_keys['fyi'];
    $on_hold = $decision_keys['on_hold'];
    $form->findById("topic-action-1-1-$on_hold")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findById("topic-action-2-1-$fyi")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findById("topic-action-2-3-$on_hold")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/full-text-review-queue-decisions-ready.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/full-text-review-queue-decisions-stored.png');
    $assert_session->pageTextContains('Queued decisions have been applied.');
    $assert_session->pageTextContains('Test Topic 1 (0)');
    $assert_session->pageTextContains('Test Topic 2 (0)');
    $assert_session->pageTextContains('Test Topic 3 (0)');
    $assert_session->pageTextContains('Articles Waiting for Full Text Review (0)');
    $assert_session->pageTextContains('No articles match the filtering criteria.');
    $assert_session->pageTextNotContains('Test Article 1');
    $assert_session->pageTextNotContains('Test Article 2');

    // Check the current statuses.
    \Drupal::entityTypeManager()->getStorage('ebms_article')->resetCache();
    \Drupal::entityTypeManager()->getStorage('ebms_article_topic')->resetCache();
    $article = Article::load(1);
    $this->assertEquals(2, $article->topics->count());
    $this->assertEquals('on_hold', $article->getCurrentState(1)->value->entity->field_text_id->value);
    $this->assertEquals('reject_bm_review', $article->getCurrentState(2)->value->entity->field_text_id->value);
    $article = Article::load(2);
    $this->assertEquals(3, $article->topics->count());
    $this->assertEquals('fyi', $article->getCurrentState(1)->value->entity->field_text_id->value);
    $this->assertEquals('reject_init_review', $article->getCurrentState(2)->value->entity->field_text_id->value);
    $this->assertEquals('on_hold', $article->getCurrentState(3)->value->entity->field_text_id->value);

    // Switch to the On Hold Review queue.
    $form = $this->getSession()->getPage();
    $form->findById('edit-type-on-hold-review')->click();
    $form->findButton('Filter')->click();
    $form = $this->getSession()->getPage();
    foreach (['Queue Selection', 'Filter Options', 'Display Options'] as $label) {
      $accordion = $form->findButton($label);
      $this->assertNotEmpty($accordion);
      $accordion->click();
    }
    $this->createScreenshot('../testdata/screenshots/on-hold-review-queue.png');
    $assert_session->pageTextContains('On Hold Review Queue');
    $assert_session->pageTextContains('Articles Waiting for On Hold Review (2)');
    $assert_session->pageTextContains('Test Article 1');
    $assert_session->pageTextContains('Test Article 2');
    $assert_session->pageTextMatchesCount(2, '/Test Board Topics/');
    $assert_session->pageTextMatchesCount(2, '/Test Topic 1/');
    $assert_session->pageTextMatchesCount(2, '/Test Topic 2/');
    $assert_session->pageTextMatchesCount(1, '/Test Topic 3/');
    $assert_session->pageTextMatchesCount(2, '/None\s+Reject\s+Approve/');

    // Apply decisions to the topics which are on hold for the articles.
    $decision_keys = [];
    foreach (ReviewQueue::DECISION_STATES['On Hold Review'] as $key => $text_id) {
      $decision_keys[$text_id] = $key;
    }
    $reject_full_review = $decision_keys['reject_full_review'];
    $passed_full_review = $decision_keys['passed_full_review'];
    $form->findById("topic-action-1-1-$passed_full_review")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findById("topic-action-2-3-$reject_full_review")->click();
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/on-hold-review-queue-decisions-ready.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/on-hold-review-queue-decisions-stored.png');
    $assert_session->pageTextContains('Queued decisions have been applied.');
    $assert_session->pageTextContains('Articles Waiting for On Hold Review (0)');
    $assert_session->pageTextContains('No articles match the filtering criteria.');
    $assert_session->pageTextNotContains('Test Article 1');
    $assert_session->pageTextNotContains('Test Article 2');

    // Check the current statuses.
    \Drupal::entityTypeManager()->getStorage('ebms_article')->resetCache();
    \Drupal::entityTypeManager()->getStorage('ebms_article_topic')->resetCache();
    $article = Article::load(1);
    $this->assertEquals(2, $article->topics->count());
    $this->assertEquals('passed_full_review', $article->getCurrentState(1)->value->entity->field_text_id->value);
    $this->assertEquals('reject_bm_review', $article->getCurrentState(2)->value->entity->field_text_id->value);
    $article = Article::load(2);
    $this->assertEquals(3, $article->topics->count());
    $this->assertEquals('fyi', $article->getCurrentState(1)->value->entity->field_text_id->value);
    $this->assertEquals('reject_init_review', $article->getCurrentState(2)->value->entity->field_text_id->value);
    $this->assertEquals('reject_full_review', $article->getCurrentState(3)->value->entity->field_text_id->value);
  }

}

