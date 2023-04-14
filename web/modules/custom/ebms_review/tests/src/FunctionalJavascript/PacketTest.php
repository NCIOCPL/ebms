<?php

namespace Drupal\Tests\ebms_review\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\ebms_article\Entity\Article;
use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_review\Entity\Packet;
use Drupal\ebms_review\Entity\Review;
use Drupal\ebms_review\Entity\ReviewerDoc;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\file\Entity\File;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;

/**
 * Test the creation and use of review packets.
 *
 * @group ebms
 */
class PacketTest extends WebDriverTestBase {

  protected static $modules = ['ebms_review', 'ebms_report', 'ebms_summary'];

  /**
   * Use a very simple theme.
   */
  protected $defaultTheme = 'stark';

  /**
   * Lookup term ID by decision name.
   */
  private $decisions = [];

  /**
   * Lookup term ID by reason name.
   */
  private $reasons = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create the taxonomy terms needed by the tests.
    $states = [
      'published' => 40,
      'passed_full_review' => 60,
      'fyi' => 60,
    ];
    foreach ($states as $key => $sequence) {
      Term::create([
        'vid' => 'states',
        'field_text_id' => $key,
        'name' => strtolower(str_replace('_', ' ', $key)),
        'field_sequence' => $sequence,
      ])->save();
    }

    // Create a board, a couple of topics, and pair of articles.
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
    for ($i = 1; $i <= 3; ++$i) {
      File::create([
        'fid' => 1000 + $i,
        'uid' => 1,
        'filename' => "file-$i.pdf",
        'uri' => "public://file-$i.pdf",
        'status' => 1,
      ])->save();
      $article = Article::create([
        'id' => $i,
        'title' => "Article $i",
        'source_id' => 10000000 + $i,
        'full_text' => ['file' => 1000 + $i],
      ]);
      $article->addState($i === 3 ? 'fyi' : 'passed_full_review', 2);
      $article->save();
    }

    $decisions = [
      ['Warrants no changes to the summary', NULL],
      ['Deserves citation in the summary', 'placement'],
      ['Merits revision of the text', 'changes'],
      ['Merits discussion', NULL],
    ];
    foreach ($decisions as $i => list($name, $what)) {
      $values = [
        'vid' => 'dispositions',
        'name' => $name,
        'weight' => $i + 1,
        'status' => 1,
      ];
      if (!empty($what)) {
        $values['description'] = "indicate $what in the summary document";
      }
      $term = Term::create($values);
      $term->save();
      $this->decisions[$name] = $term->id();
    }
    $reasons = ['Boring', 'Sloppy work', 'Too long'];
    foreach ($reasons as $i => $reason) {
      $term = Term::create([
        'vid' => 'rejection_reasons',
        'name' => $reason,
        'weight' => $i + 1,
        'status' => 1,
      ]);
      $term->save();
      $this->reasons[$reason] = $term->id();
    }
  }

  public function testPackets() {

    // Create some users with the right permissions.
    $board_manager = $this->createUser(['manage review packets', 'record print responses']);
    $board_member = $this->createUser(['review literature']);
    $admin_assistant = $this->createUser(['print packets']);
    $board_manager_name = $board_manager->name->value;
    $board_member_name = $board_member->name->value;
    $board_member->set('boards', [1]);
    $board_member->set('topics', [1, 2]);
    $board_member->addRole('board_member');
    $board_member->save();
    $second_board_member = $this->createUser(['review literature']);
    $second_board_member->set('boards', [1]);
    $second_board_member->set('topics', [2]);
    $second_board_member->addRole('board_member');
    $second_board_member->save();
    $second_board_member_name = $second_board_member->name->value;
    $third_board_member = $this->createUser(['review literature']);
    $third_board_member->set('boards', [1]);
    $third_board_member->set('topics', [2]);
    $third_board_member->addRole('board_member');
    $third_board_member->save();
    $third_board_member_name = $third_board_member->name->value;

    // Start out logged in as the board manager.
    $this->drupalLogin($board_manager);

    // Create a new packet.
    $this->getSession()->resizeWindow(800, 1000, 'current');
    $url = Url::fromRoute('ebms_review.packet_form')->toString();
    $this->drupalGet($url);
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Add New Literature Surveillance Packet');
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('topic', '2');
    $assert_session->assertWaitOnAjaxRequest();
    $form->uncheckField($second_board_member_name);
    $this->createScreenshot('../testdata/screenshots/create-packet-page.png');
    $today = date('Y-m-d');
    $form->findButton('Submit')->click();

    // Verify the Packet entity fields.
    $packet = Packet::load(1);
    $month_year = date('F Y');
    $packet_title = "Topic 2 ($month_year)";
    $this->assertCount(3, $packet->articles);
    $this->assertCount(2, $packet->reviewers);
    $this->assertEquals($packet_title, $packet->title->value);
    $this->assertEquals(2, $packet->topic->target_id);
    $this->assertEmpty($packet->starred->value);
    $this->assertEmpty($packet->last_seen->value);

    // Do the same for the PacketArticle entity fields.
    $packet_article = $packet->articles[0]->entity;
    $this->assertEmpty($packet_article->archived->value);
    $this->assertEmpty($packet_article->dropped->value);
    $this->assertCount(0, $packet_article->reviews);
    $this->assertEquals('Article 1', $packet_article->article->entity->title->value);

    // Confirm expected text/form sections on the View/Edit Packages page.
    $assert_session->pageTextContains('Literature Review Packets');
    $assert_session->pageTextContains('Packets (1)');
    $form = $this->getSession()->getPage();
    foreach (['Filtering', 'Display'] as $name) {
      $accordion = $form->findButton("$name Options");
      $this->assertNotEmpty($accordion);
      $accordion->click();
    }
    $this->createScreenshot('../testdata/screenshots/packet-created.png');

    // Test filtering of the packets.
    $form->findField('boards[]')->selectOption('1', TRUE);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findField('topics[]')->selectOption('1', TRUE);
    $form->findButton('Filter')->click();
    $assert_session->pageTextContains('Packets (0)');
    $this->createScreenshot('../testdata/screenshots/filtered-packets-0.png');
    $form = $this->getSession()->getPage();
    $form->findButton('Filtering Options')->click();
    $form->findField('topics[]')->selectOption('2', TRUE);
    $form->findButton('Filter')->click();
    $assert_session->pageTextContains('Packets (1)');
    $this->createScreenshot('../testdata/screenshots/filtered-packets-1.png');

    // Check the Unreviewed Packets page.
    $url = Url::fromRoute('ebms_review.unreviewed_packets')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/unreviewed-packets.png');
    $assert_session->pageTextContains('Unreviewed Packets');
    $assert_session->pageTextContains('Packets (1, most-recently-created first');

    // Check the Unreviewed Packet page.
    $this->clickLink("$packet_title [Packet #1]");
    $this->createScreenshot('../testdata/screenshots/unreviewed-packet.png');
    $assert_session->pageTextContains("Unreviewed Packet $packet_title");
    $assigned_to = [$board_member_name, $third_board_member_name];
    sort($assigned_to);
    $assigned_to = implode(', ', $assigned_to);
    $assert_session->pageTextContains("Assigned $today for review to: $assigned_to");

    // Try the Reviewed Packets page and make sure it's empty.
    $url = Url::fromRoute('ebms_review.reviewed_packets')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/empty-reviewed-packets.png');
    $assert_session->pageTextContains('Reviewed Packets');
    $assert_session->pageTextContains('No packets match');

    // Try the FYI packets page.
    $this->drupalLogin($second_board_member);
    $url = Url::fromRoute('ebms_review.fyi_packets')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/fyi-packets.png');
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('FYI Packets');

    // Bring up the page for the specific FYI packet.
    $this->clickLink($packet_title);
    $this->createScreenshot('../testdata/screenshots/fyi-packet.png');
    $assert_session->pageTextContains($packet_title);
    $assert_session->pageTextContains('No summaries have been posted for this packet.');
    $assert_session->pageTextContains('PMID: 10000001');
    $assert_session->pageTextContains('PMID: 10000002');
    $assert_session->pageTextContains('PMID: 10000003');

    // These tests fail because NLM blocks test user agents.
    // Bug report submitted (CAS-1089548-T2C3V3).
    $form = $this->getSession()->getPage();
    $form->clickLink('View Abstract');
    $tabs = $this->getSession()->getWindowNames();
    $this->getSession()->switchToWindow($tabs[1]);
    $this->createScreenshot('../testdata/screenshots/pubmed-article.png');
    // $assert_session->pageTextContains('National Library of Medicine');
    // $assert_session->pageTextContains('Quantum-optical properties of polariton waves');

    // Test the Assigned Packets page.
    $this->drupalLogin($board_member);
    $url = Url::fromRoute('ebms_review.assigned_packets')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/assigned-packets.png');
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Assigned Packets');
    $assert_session->pageTextContains($packet_title);

    // Drill down to the specific packet page.
    $this->clickLink("$packet_title (3 articles)");
    $this->createScreenshot('../testdata/screenshots/assigned-packet.png');
    $assert_session->pageTextContains($packet_title);
    $assert_session->pageTextContains('Review articles. Use the REJECT button');
    $assert_session->pageTextContains($board_member_name);

    // Post a document to the packet.
    $this->clickLink('Post Reviewer Document');
    $this->createScreenshot('../testdata/screenshots/post-reviewer-document.png');
    $assert_session->pageTextContainsOnce("Post document for $packet_title");
    $form = $this->getSession()->getPage();
    $file_field = $form->findField('files[doc]');
    $file_field->attachFile('/usr/local/share/testdata/test.docx');
    $form->fillField('notes', 'Yada yada some doc yada');
    $now = date('Y-m-d H:i:s');
    $form->findButton('Upload File')->click();
    $this->createScreenshot('../testdata/screenshots/reviewer-document-posted.png');
    $assert_session->pageTextContainsOnce('Yada yada some doc yada');

    // Check the ReviewerDoc entity's properties.
    $reviewer_doc = ReviewerDoc::load(1);
    $this->assertNotEmpty($reviewer_doc);
    $this->assertEmpty($reviewer_doc->dropped->value);
    $this->assertGreaterThanOrEqual($now, $reviewer_doc->posted->value);
    $this->assertEquals($board_member->id(), $reviewer_doc->reviewer->target_id);
    $this->assertEquals('test.docx', $reviewer_doc->file->entity->filename->value);
    $this->assertEquals('Yada yada some doc yada', $reviewer_doc->description->value);

    // Review the first article in the packet.
    $this->clickLink('Review');
    $this->createScreenshot('../testdata/screenshots/article-review-form.png');
    $assert_session->pageTextContains($packet_title);
    $assert_session->pageTextContains('Review of PMID 10000001');
    $assert_session->pageTextContains('Article 1');
    $assert_session->pageTextContains('Make your suggested changes directly');
    $assert_session->pageTextContains('in the summary (indicate placement');
    $form = $this->getSession()->getPage();
    $form->checkField('Warrants no changes to the summary');
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/rejected-checked.png');
    $assert_session->pageTextContains('Boring');
    $form->checkField('Merits discussion');
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/merits-discussion-checked.png');
    $assert_session->pageTextNotContains('Boring');
    $this->setRichTextValue('.ck-editor__editable', 'Exciting!');
    $form->fillField('loe', 'Levels of evidence yada yada yada');
    $today = date('Y-m-d');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/review-submitted.png');
    $assert_session->pageTextContains('Review successfully stored.');
    $assert_session->pageTextContains("Review posted $today");

    // Verify that the Review entity is as it ought to be.
    $review = Review::load(1);
    $this->assertEquals($board_member->id(), $review->reviewer->target_id);
    $this->assertEquals('Levels of evidence yada yada yada', $review->loe_info->value);
    $this->assertEquals('<p>Exciting!</p>', $review->comments->value);
    $this->assertEquals($this->decisions['Merits discussion'], $review->dispositions[0]->target_id);

    // Test the packet printing page. Must be done before packet completed.
    $this->drupalLogin($admin_assistant);
    $url = Url::fromRoute('ebms_review.print_packet')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/print-packet-form.png');
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('Board', '1');
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Board Member', $board_member_name);
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Packet', 1);
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/print-packet-submitted.png');
    $form = $this->getSession()->getPage();
    $link = $form->findLink('Download');
    $this->assertNotEmpty($link);

    // Make sure the packet shows up on the Reviewed Packets page.
    $this->drupalLogin($board_manager);
    $url = Url::fromRoute('ebms_review.reviewed_packets')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/reviewed-packets.png');
    $link_label = "$packet_title [Packet #1]";
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $assert_session->pageTextContainsOnce('Reviewed Packets');
    $assert_session->pageTextContainsOnce('Packets (1, most-recently-reviewed first)');
    $assert_session->pageTextContainsOnce($link_label);
    $assert_session->pageTextContainsOnce("Reviewed by $board_member_name (latest review $today)");

    // Navigate to the specific reviewed packet.
    $this->clickLink($link_label);
    $this->createScreenshot('../testdata/screenshots/reviewed-packet.png');
    $assert_session->pageTextContainsOnce("Reviews for $packet_title");
    $assert_session->pageTextContainsOnce("Latest review posted $today");

    // Drill down into the page for the details of a single article's reviews.
    $this->clickLink('Show Details');
    $this->createScreenshot('../testdata/screenshots/reviewed-article-details.png');
    $assert_session->pageTextContainsOnce($packet_title);
    $assert_session->pageTextContainsOnce('Reviews for Article');
    $assert_session->pageTextMatches("/Reviewer.+$board_member_name/");
    $assert_session->pageTextMatches("/Review Date.+$today/");
    $assert_session->pageTextMatches('/Review Dispositions.+Merits discussion/');
    $assert_session->pageTextMatches('/Comments\s+Exciting!/');
    $assert_session->pageTextMatches('/LOE Information\s+Levels of evidence yada/');

    // Bring up the Record Responses page and pick a board and reviewer.
    $url = Url::fromRoute('ebms_review.record_responses')->toString();
    $this->drupalGet($url);
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('Board', '1');
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Board Member', $board_member_name);
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/record-responses.png');
    $assert_session->pageTextContainsOnce('Record Responses');
    $assert_session->pageTextMatches('/Select the board for which.+Select the board member.+Select a packet/');

    // Move to the page for the reviewer's packets.
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reviewer-obo-packets.png');
    $link_label = "$packet_title (3 articles)";
    $assert_session->pageTextContainsOnce("Assigned Packets for $board_member_name");
    $assert_session->pageTextContainsOnce($link_label);

    // Drill down into the packet's page.
    $this->clickLink($link_label);
    $this->createScreenshot('../testdata/screenshots/reviewer-obo-packet.png');
    $assert_session->pageTextContainsOnce("$packet_title (on behalf of $board_member_name)");

    // Bring up the form for a quick rejection of the article.
    $this->clickLink('Reject');
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/review-obo.png');
    $form = $this->getSession()->getPage();
    $form->checkField('Boring');
    $form->checkField('Too long');
    $this->setRichTextValue('.ck-editor__editable', 'Ennuyeux!');
    $this->createScreenshot('../testdata/screenshots/review-obo-filled-out.png');

    // Submit the form and check the review.
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/review-obo-submitted.png');
    $assert_session->pageTextContains('Review successfully stored.');
    $review = Review::load(2);
    $comment = "<p>Ennuyeux!</p><p><i>Recorded by $board_manager_name on behalf of $board_member_name.</i></p>";
    $rejected = $this->decisions['Warrants no changes to the summary'];
    $this->assertEquals($board_member->id(), $review->reviewer->target_id);
    $this->assertEquals($comment, $review->comments->value);
    $this->assertEquals($rejected, $review->dispositions[0]->target_id);
    $this->assertEquals($this->reasons['Boring'], $review->reasons[0]->target_id);
    $this->assertEquals($this->reasons['Too long'], $review->reasons[1]->target_id);

    // Make sure the packet is gone from the Assigned Packets page.
    $this->drupalLogin($board_member);
    $assert_session = $this->assertSession();
    $url = Url::fromRoute('ebms_review.assigned_packets')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/no-assigned-packets.png');
    $assert_session->pageTextContainsOnce('Assigned Packets');
    $assert_session->pageTextContainsOnce('There are no review packets in your queue.');

    // Now the packet should turn up on the Completed Packets page.
    $url = Url::fromRoute('ebms_review.completed_packets')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/completed-packets.png');
    $assert_session->pageTextContainsOnce('Completed Packets');

    // Drill down to the single packet's page.
    $this->clickLink($packet_title);
    $this->createScreenshot('../testdata/screenshots/completed-packet.png');
    $assert_session->pageTextContainsOnce($packet_title);
    $assert_session->pageTextContainsOnce('No summaries have been posted for this packet.');
    $assert_session->pageTextMatches('/Comments\s+Exciting!/');
    $assert_session->pageTextContainsOnce('LOE Info: Levels of evidence yada yada yada');

    // Now complicate things by having another board member add a review.
    $this->drupalLogin($third_board_member);
    $url = Url::fromRoute('ebms_review.assigned_packets')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/assigned-packets-reviewer3.png');
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Assigned Packets');
    $assert_session->pageTextContains($packet_title);

    // Drill down to the specific packet page.
    $this->clickLink("$packet_title (3 articles)");
    $this->createScreenshot('../testdata/screenshots/assigned-packet-reviewer3.png');
    $this->clickLink('Reject');
    $assert_session->assertWaitOnAjaxRequest();
    $form = $this->getSession()->getPage();
    $form->checkField('Sloppy work');
    $this->setRichTextValue('.ck-editor__editable', 'Be more careful');
    $this->createScreenshot('../testdata/screenshots/reject-reviewer3.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/review-submitted-reviewer3.png');
    $assert_session->pageTextContains('Review successfully stored.');

    // The packet should still appear on the Completed Packets page for the first reviewer.
    $this->drupalLogin($board_member);
    $assert_session = $this->assertSession();
    $url = Url::fromRoute('ebms_review.completed_packets')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/completed-packets-final.png');
    $assert_session->pageTextContainsOnce('Completed Packets');
    $this->clickLink($packet_title);
    $this->createScreenshot('../testdata/screenshots/completed-packet-final.png');
    $assert_session->pageTextContainsOnce($packet_title);
    $assert_session->pageTextContainsOnce('No summaries have been posted for this packet.');
    $assert_session->pageTextMatches('/Comments\s+Exciting!/');
    $assert_session->pageTextContainsOnce('LOE Info: Levels of evidence yada yada yada');

    // Make sure the packet is still gone from the Assigned Packets page for the first reviewer.
    $url = Url::fromRoute('ebms_review.assigned_packets')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/no-assigned-packets-final.png');
    $assert_session->pageTextContainsOnce('Assigned Packets');
    $assert_session->pageTextContainsOnce('There are no review packets in your queue.');
  }

  private function setRichTextValue(string $selector, string $value) {
    $this->getSession()->executeScript(<<<JS
      const domEditableElement = document.querySelector("$selector");
      if (domEditableElement) {
        const editorInstance = domEditableElement.ckeditorInstance;
        if (editorInstance) {
          editorInstance.setData("$value");
        } else {
          throw new Exception('Could not get the editor instance!');
        }
      } else {
        throw new Exception('could not find the element!');
      }
    JS);
  }

}

