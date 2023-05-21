<?php

namespace Drupal\Tests\ebms_report\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\ebms_article\Entity\Article;
use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_group\Entity\Group;
use Drupal\ebms_import\Entity\Batch;
use Drupal\ebms_import\Entity\ImportRequest;
use Drupal\ebms_meeting\Entity\Meeting;
use Drupal\ebms_review\Entity\Packet;
use Drupal\ebms_message\Entity\Message;
use Drupal\ebms_review\Entity\PacketArticle;
use Drupal\ebms_review\Entity\Review;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\ebms_travel\Entity\HotelRequest;
use Drupal\ebms_travel\Entity\ReimbursementRequest;
use Drupal\file\Entity\File;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;

/**
 * Test the journal maintenance page.
 *
 * We can't test the journal refresh command, because NLM refuses
 * most connections from test clients. :-(
 *
 * @group ebms
 */
class ReportsTest extends WebDriverTestBase {

  protected static $modules = [
    'ebms_article',
    'ebms_import',
    'ebms_report',
    'ebms_review',
    'ebms_travel',
  ];

  protected $defaultTheme = 'stark';


  public function testReports() {

    // Test the hotel requests report.
    $user = $this->createUser(['view all reports', 'manage topics'], 'Test Board Manager');
    Term::create(['tid' => 1001, 'vid' => 'hotels', 'name' => 'Test Hotel 1'])->save();
    Term::create(['tid' => 1002, 'vid' => 'hotels', 'name' => 'Test Hotel 2'])->save();
    Term::create(['tid' => 1003, 'vid' => 'meeting_types', 'name' => 'In Person'])->save();
    Term::create(['tid' => 1004, 'vid' => 'meeting_categories', 'name' => 'Board'])->save();
    Board::create(['id' => 1, 'name' => 'Test Board 1'])->save();
    Board::create(['id' => 2, 'name' => 'Test Board 2'])->save();
    User::create(['uid' => 101, 'name' => 'Test Board Member 1', 'boards' => [1], 'topics' => [1], 'roles' => ['board_member'], 'status' => 1])->save();
    User::create(['uid' => 102, 'name' => 'Test Board Member 2', 'boards' => [2], 'topics' => [2], 'roles' => ['board_member'], 'status' => 1])->save();
    User::create(['uid' => 103, 'name' => 'Test Board Member 3', 'boards' => [1], 'topics' => [1, 3], 'roles' => ['board_member'], 'status' => 1])->save();
    User::create(['uid' => 104, 'name' => 'Test Board Member 4', 'boards' => [1, 2], 'topics' => [1, 2], 'roles' => ['board_member'], 'status' => 0])->save();
    Meeting::create(['id' => 101, 'name' => 'Test Meeting 1', 'boards' => [1], 'dates' => ['value' => '2023-02-14'], 'type' => 1003, 'category' => 1004, 'published' => 1])->save();
    Meeting::create(['id' => 102, 'name' => 'Test Meeting 2', 'boards' => [2], 'dates' => ['value' => '2023-03-14'], 'type' => 1003, 'category' => 1004, 'published' => 1])->save();
    Meeting::create(['id' => 103, 'name' => 'Test Meeting 3', 'boards' => [1], 'dates' => ['value' => '2023-02-14'], 'type' => 1003, 'category' => 1004, 'published' => 1])->save();
    $values = [
      [101, '2023-01-14 10:00', 101, '2023-02-14', '2023-02-15', 1001, 'Yada yada'],
      [102, '2023-02-14 09:00', 102, '2023-03-14', '2023-03-15', 1002, 'Test comment'],
      [103, '2023-01-14 09:00', 103, '2023-02-14', '2023-02-15', 1001, 'More yada yada'],
    ];
    foreach ($values as list($uid, $submitted, $meeting, $in, $out, $hotel, $comments)) {
      HotelRequest::create([
        'user' => $uid,
        'submitted' => $submitted,
        'meeting' => $meeting,
        'check_in' => $in,
        'check-out' => $out,
        'preferred_hotel' => $hotel,
        'comments' => $comments,
      ])->save();
    }
    $this->drupalLogin($user);
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $this->drupalGet(Url::fromRoute('ebms_report.hotel_requests'));
    $this->createScreenshot('../testdata/screenshots/reports-hotel-requests.png');
    $assert_session->pageTextContains('Requests (3)');
    $assert_session->pageTextMatches('/Test Board Member 2.+Test Board Member 1.+Test Board Member 3/');
    $assert_session->pageTextMatches('/Test comment.+Yada yada.+More yada yada/');
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/reports-hotel-requests-board-selected.png');
    $form->findButton('edit-filter')->click();
    $this->createScreenshot('../testdata/screenshots/reports-hotel-requests-filtered-by-board.png');
    $assert_session->pageTextContains('Requests (2)');
    $assert_session->pageTextMatches('/Test Board Member 1.+Test Board Member 3/');
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('meeting', 101);
    $form->findButton('edit-filter')->click();
    $this->createScreenshot('../testdata/screenshots/reports-hotel-requests-filtered-by-meeting.png');
    $assert_session->pageTextContains('Requests (1)');
    $assert_session->pageTextContains('Test Board Member 1');
    $this->getSession()->getPage()->findButton('Reset')->click();
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->fillField('request-start', '02/01/2023');
    $form->fillField('request-end', '02/28/2023');
    $this->createScreenshot('../testdata/screenshots/reports-hotel-requests-filtering-by-date-range.png');
    $form->findButton('edit-filter')->click();
    $this->createScreenshot('../testdata/screenshots/reports-hotel-requests-filtered-by-date-range.png');
    $assert_session->pageTextContains('Requests (1)');
    $assert_session->pageTextContains('Test Board Member 2');

    // Test the travel reimbursement report.
    Term::create(['tid' => 2001, 'vid' => 'hotel_payment_methods', 'name' => 'NCI paid for my hotel'])->save();
    Term::create(['tid' => 2002, 'vid' => 'meals_and_incidentals', 'name' => 'Per diem requested'])->save();
    Term::create(['tid' => 2003, 'vid' => 'reimbursement_to', 'name' => 'Home'])->save();
    Term::create(['tid' => 2004, 'vid' => 'transportation_expense_types', 'name' => 'Taxi'])->save();
    Term::create(['tid' => 2005, 'vid' => 'parking_or_toll_expense_types', 'name' => 'Airport Parking'])->save();
    $values = [
      [1, '2023-02-16 09:00', '2023-02-14', '2023-02-15'],
      [2, '2023-03-16 11:00', '2023-03-14', '2023-03-15'],
      [3, '2023-02-16 10:00', '2023-02-14', '2023-02-15'],
    ];
    foreach ($values as list($i, $submitted, $arrival, $departure)) {
      ReimbursementRequest::create([
        'user' => 100 + $i,
        'meeting' => 100 + $i,
        'submitted' => $submitted,
        'arrival' => $arrival,
        'departure' => $departure,
        'transportation' => [['date' => $arrival, 'type' => 2004, 'amount' => 12 * $i]],
        'parking_and_tolls' => [['date' => $departure, 'type' => 2005, 'amount' => 8 * $i]],
        'hotel_payment' => 2001,
        'nights_stayed' => 1,
        'meals_and_incidentals' => 2002,
        'honorarium_requested' => TRUE,
        'reimburse_to' => 2003,
        'comments' => "Test Comment $i",
        'certified' => TRUE,
        'confirmation_email' => "board-member-$i@example.com",
      ])->save();
    }
    $this->drupalGet(Url::fromRoute('ebms_report.reimbursement_requests'));
    $this->createScreenshot('../testdata/screenshots/reports-reimbursement-requests.png');
    $assert_session->pageTextContains('Requests (3)');
    $assert_session->pageTextMatches('/Test Board Member 2.+Test Board Member 3.+Test Board Member 1/');
    $assert_session->pageTextContains('Test Board Member 2 (2023-03-16) for Test Meeting 2 - 2023-03-14');
    $assert_session->pageTextContains('Test Board Member 3 (2023-02-16) for Test Meeting 3 - 2023-02-14');
    $assert_session->pageTextContains('Test Board Member 1 (2023-02-16) for Test Meeting 1 - 2023-02-14');
    $assert_session->pageTextMatchesCount(2, '/Arrival\s+2023-02-14\s+Departure\s+2023-02-15/');
    $assert_session->pageTextMatchesCount(1, '/Arrival\s+2023-03-14\s+Departure\s+2023-03-15/');
    $assert_session->pageTextMatches('/Transportation Expenses\s+Taxi - 2023-03-14 - \$24 \(52-04\)/');
    $assert_session->pageTextMatches('/Transportation Expenses\s+Taxi - 2023-02-14 - \$36 \(52-04\)/');
    $assert_session->pageTextMatches('/Transportation Expenses\s+Taxi - 2023-02-14 - \$12 \(52-04\)/');
    $assert_session->pageTextMatches('/Parking and Toll Expenses\s+Airport Parking - 2023-03-15 - \$16 \(52-04\)/');
    $assert_session->pageTextMatches('/Parking and Toll Expenses\s+Airport Parking - 2023-02-15 - \$24 \(52-04\)/');
    $assert_session->pageTextMatches('/Parking and Toll Expenses\s+Airport Parking - 2023-02-15 - \$8 \(52-04\)/');
    $assert_session->pageTextMatchesCount(3, '/Hotel Payment\s+NCI paid for my hotel\s+Nights Stayed\s+1/');
    $assert_session->pageTextMatchesCount(3, '/Meals and Incidentals\s+Per diem requested\s+Honorarium\s+Requested\s+Reimburse To\s+Home/');
    $assert_session->pageTextMatchesCount(1, '/Comments\s+Test Comment 2/');
    $assert_session->pageTextMatchesCount(1, '/Comments\s+Test Comment 3/');
    $assert_session->pageTextMatchesCount(1, '/Comments\s+Test Comment 1/');
    $assert_session->pageTextMatchesCount(1, '/Confirmation Email\s+board-member-2@example.com/');
    $assert_session->pageTextMatchesCount(1, '/Confirmation Email\s+board-member-3@example.com/');
    $assert_session->pageTextMatchesCount(1, '/Confirmation Email\s+board-member-1@example.com/');
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/reports-reimbursement-requests-board-selected.png');
    $form->findButton('edit-filter')->click();
    $this->createScreenshot('../testdata/screenshots/reports-reimbursement-requests-filtered-by-board.png');
    $assert_session->pageTextContains('Requests (2)');
    $assert_session->pageTextMatches('/Test Board Member 3.+Test Board Member 1/');
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('meeting', 101);
    $form->findButton('edit-filter')->click();
    $this->createScreenshot('../testdata/screenshots/reports-reimbursement-requests-filtered-by-meeting.png');
    $assert_session->pageTextContains('Requests (1)');
    $assert_session->pageTextContains('Test Board Member 1');
    $this->getSession()->getPage()->findButton('Reset')->click();
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->fillField('request-start', '03/01/2023');
    $form->fillField('request-end', '03/31/2023');
    $this->createScreenshot('../testdata/screenshots/reports-reimbursement-requests-filtering-by-date-range.png');
    $form->findButton('edit-filter')->click();
    $this->createScreenshot('../testdata/screenshots/reports-reimbursement-requests-filtered-by-date-range.png');
    $assert_session->pageTextContains('Requests (1)');
    $assert_session->pageTextContains('Test Board Member 2');

    // Test the Board Membership report.
    Group::create(['id' => 1, 'name' => 'Test Group 1', 'status' => 1, 'boards' => [1]])->save();
    Group::create(['id' => 2, 'name' => 'Test Group 2', 'status' => 1, 'boards' => [2]])->save();
    Group::create(['id' => 3, 'name' => 'Test Group 3', 'status' => 1, 'boards' => [1]])->save();
    for ($i = 1; $i <= 3; ++$i) {
      $board_member = User::load(100 + $i);
      $board_member->groups->appendItem($i);
      $board_member->save();
    }
    $this->drupalGet(Url::fromRoute('ebms_report.board_members'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filter')->click();
    $form->selectFieldOption('board', 1);
    $this->createScreenshot('../testdata/screenshots/reports-board-members.png');
    $form->findButton('Report')->click();
    $this->createScreenshot('../testdata/screenshots/reports-board-members-board-1.png');
    $assert_session->pageTextMatches('/Test Board 1\s+Test Board Member 1\s+Test Board Member 3/');
    $assert_session->pageTextNotContains('Test Board Member 2');
    $assert_session->pageTextNotContains('Test Board Member 4');
    $assert_session->pageTextNotContains('Test Group');
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->findById('edit-include-groups')->click();
    $this->createScreenshot('../testdata/screenshots/reports-board-members-group-checkbox-checked.png');
    $form->findButton('Report')->click();
    $this->createScreenshot('../testdata/screenshots/reports-board-members-board-1-with-groups.png');
    $assert_session->pageTextMatches('/Test Group 1\s+Test Board Member 1\s+Test Group 3\s+Test Board Member 3/');
    $form = $this->getSession()->getPage();
    $form->findButton('Filter')->click();
    $form->selectFieldOption('board', 2);
    $form->findButton('Report')->click();
    $this->createScreenshot('../testdata/screenshots/reports-board-members-board-2-with-groups.png');
    $assert_session->pageTextMatches('/Test Board 2\s+Test Board Member 2\s+Test Group 2\s+Test Board Member 2/');

    // Test the Recent Activity report.
    $when = new \DateTime('2023-01-16 12:00:00');
    File::create(['fid' => 1001, 'filename' => 'Test File 1', 'uri' => 'public://Test File 1.pdf', 'created' => $when->getTimestamp()])->save();
    Term::create(['tid' => 3001, 'vid' => 'dispositions', 'name' => 'Warrants no changes to the summary'])->save();
    Term::create(['tid' => 3002, 'vid' => 'dispositions', 'name' => 'Deserves citation in the summary'])->save();
    Term::create(['tid' => 3003, 'vid' => 'rejection_reasons', 'name' => 'Already cited in the PDQ summary'])->save();
    Article::create([
      'id' => 1,
      'title' => 'Test Article 1',
      'authors' => [['last_name' => 'Parmigiani', 'initials' => 'G']],
      'full_text' => ['file' => 1001],
      'source_id' => 10000001,
      'brief_journal_title' => 'Obsc Med',
      'volume' => 101,
      'issue' => 8,
      'pagination' => '207-214',
      'year' => 2022,
    ])->save();
    Article::create([
      'id' => 2,
      'title' => 'Test Article 2',
      'authors' => [['last_name' => 'Liu', 'initials' => 'M']],
      'full_text' => ['file' => 1001],
      'source_id' => 35775213,
      'brief_journal_title' => 'Quark Redux',
      'volume' => 9,
      'pagination' => '5-9',
      'year' => 2023,
    ])->save();
    Review::create(['id' => 1, 'reviewer' => 101, 'posted' => '2022-05-26 12:08:22', 'dispositions' => [3002]])->save();
    Review::create(['id' => 2, 'reviewer' => 103, 'posted' => '2023-02-23 13:09:22', 'dispositions' => [3001], 'reasons' => [3003], 'comments' => 'Yada'])->save();
    Review::create(['id' => 3, 'reviewer' => 102, 'posted' => '2023-09-26 15:10:22', 'dispositions' => [3002], 'loe_info' => 'evidently'])->save();
    PacketArticle::create(['id' => 1, 'article' => 1, 'reviews' => [1, 2]])->save();
    PacketArticle::create(['id' => 2, 'article' => 2, 'reviews' => [3]])->save();
    Topic::create(['id' => 1, 'name' => 'Test Topic 1', 'board' => 1])->save();
    Topic::create(['id' => 2, 'name' => 'Test Topic 2', 'board' => 2])->save();
    Packet::create(['title' => 'Test Packet 1', 'articles' => [1], 'topic' => 1, 'created' => '2023-01-29', 'created_by' => $user->id(), 'reviewers' => [101, 102]])->save();
    Packet::create(['title' => 'Test Packet 2', 'articles' => [2], 'topic' => 2, 'created' => '2023-01-30', 'created_by' => $user->id(), 'reviewers' => [101, 102]])->save();
    Message::create([
      'message_type' => Message::SUMMARY_POSTED,
      'posted' => '2022-06-01 12:00:00',
      'boards' => [1],
      'user' => 101,
      'extra_values' => json_encode([
        'summary_url' => '/sites/default/files/whatever.docx',
        'notes' => 'Yada yada 1',
        'title' => 'Test doc 1',
      ]),
    ])->save();
    Message::create([
      'message_type' => Message::SUMMARY_POSTED,
      'posted' => '2023-03-01 12:00:00',
      'boards' => [2],
      'user' => 102,
      'extra_values' => json_encode([
        'summary_url' => '/sites/default/files/whatever.docx',
        'notes' => 'Yada yada 2',
        'title' => 'Test doc 2',
      ]),
    ])->save();
    Message::create([
      'message_type' => Message::SUMMARY_POSTED,
      'posted' => '2023-07-01 13:00:00',
      'boards' => [1],
      'user' => 103,
      'extra_values' => json_encode([
        'summary_url' => '/sites/default/files/whatever.docx',
        'notes' => 'Yada yada 3',
        'title' => 'Test doc 3',
      ]),
    ])->save();
    Message::create([
      'message_type' => Message::MEETING_PUBLISHED,
      'posted' => '2022-12-31 23:59:59',
      'boards' => [1],
      'user' => $user->id(),
      'extra_values' => json_encode(['meeting_id' => 101]),
    ])->save();
    Message::create([
      'message_type' => Message::MEETING_PUBLISHED,
      'posted' => '2023-01-01 00:00:00',
      'boards' => [2],
      'user' => $user->id(),
      'extra_values' => json_encode(['meeting_id' => 102]),
    ])->save();
    Message::create([
      'message_type' => Message::MEETING_PUBLISHED,
      'posted' => '2023-01-02 14:00:00',
      'boards' => [1],
      'user' => $user->id(),
      'extra_values' => json_encode(['meeting_id' => 103]),
    ])->save();
    Message::create([
      'message_type' => Message::AGENDA_PUBLISHED,
      'posted' => '2023-01-02 14:00:01',
      'boards' => [1],
      'user' => $user->id(),
      'extra_values' => json_encode(['meeting_id' => 103]),
    ])->save();
    $this->drupalGet(Url::fromRoute('ebms_report.recent_activity'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->findById('edit-boards-1')->click();
    $form->fillField('date-start', '01/01/2023');
    $form->fillField('date-end', '12/31/2023');
    $form->findById('edit-types-meeting')->click();
    $this->createScreenshot('../testdata/screenshots/reports-recent-activity.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-recent-activity-board-1.png');
    $assert_session->pageTextContains('Recent Activity (2023-01-01 through 2023-12-31)');
    $assert_session->pageTextContains('Recent Activity for the Test Board 1 Board');
    $assert_session->pageTextMatches('/Document posted 2023-07-01 13:00:00 by Test Board Member 3\s+Test doc 3 \(Yada yada 3\)/');
    $assert_session->pageTextContains('Review posted 2023-02-23 13:09:22 by Test Board Member 3');
    $assert_session->pageTextContains('[Packet: Test Packet 1]');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1/');
    $assert_session->pageTextContains('Obsc Med 101(8): 207-214, 2022');
    $assert_session->pageTextContains('PMID: 10000001');
    $assert_session->pageTextContains('Disposition(s): Warrants no changes to the summary');
    $assert_session->pageTextContains('Exclusion Reasons: Already cited in the PDQ summary');
    $assert_session->pageTextContains('Comments: Yada');
    $assert_session->pageTextMatches('/Meeting added to calendar 2023-01-02 14:00:00 by Test Board Manager\s+Test Meeting 3 - 2023-02-14 - In Person/');
    $assert_session->pageTextNotContains('Test Board Member 1');
    $assert_session->pageTextNotContains('Test Board Member 2');
    $this->drupalGet(Url::fromRoute('ebms_report.recent_activity'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->findById('edit-boards-2')->click();
    $form->fillField('date-start', '01/01/2023');
    $form->fillField('date-end', '12/31/2023');
    $form->findById('edit-types-meeting')->click();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-recent-activity-board-2.png');
    $assert_session->pageTextContains('Recent Activity for the Test Board 2 Board');
    $assert_session->pageTextContains('Test Board Member 2');
    $assert_session->pageTextNotContains('Test Board Member 1');
    $assert_session->pageTextNotContains('Test Board Member 3');

    // Test the Import report.
    Term::create(['tid' => 4001, 'vid' => 'import_dispositions', 'name' => 'Imported', 'field_text_id' => 'imported'])->save();
    Term::create(['tid' => 4002, 'vid' => 'import_dispositions', 'name' => 'Topic Added', 'field_text_id' => 'topic_added'])->save();
    Batch::create([
      'id' => 1,
      'topic' => 1,
      'cycle' => '2023-01-01',
      'imported' => '2022-12-15 12:34:56',
      'article_count' => 2,
      'user' => $user->id(),
    ])->save();
    Batch::create([
      'id' => 2,
      'topic' => 2,
      'cycle' => '2023-01-01',
      'imported' => '2022-12-30 12:35:46',
      'article_count' => 1,
      'user' => $user->id(),
    ])->save();
    ImportRequest::create([
      'batch' => 1,
      'report' => json_encode([
        'actions' => [
          ['disposition' => 4001, 'source_id' => 10000001, 'article' => 1, 'message' => 'foo'],
          ['disposition' => 4001, 'source_id' => 35775213, 'article' => 2, 'message' => 'bar'],
        ],
      ]),
    ])->save();
    ImportRequest::create([
      'batch' => 2,
      'report' => json_encode([
        'actions' => [
          ['disposition' => 4002, 'source_id' => 10000001, 'article' => 1, 'message' => 'foobar'],
        ],
      ]),
    ])->save();
    $this->drupalGet(Url::fromRoute('ebms_report.import'));
    $form->findButton('Filters')->click();
    $this->createScreenshot('../testdata/screenshots/reports-import.png');
    $this->getSession()->getPage()->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-import-jobs.png');
    $assert_session->pageTextContains('Import Jobs (2)');
    $assert_session->pageTextMatches('/2\s+2022-12-30\s+Test Board 2\s+Test Topic 2\s+1/');
    $assert_session->pageTextMatches('/1\s+2022-12-15\s+Test Board 1\s+Test Topic 1\s+2/');
    $this->getSession()->getPage()->findLink('1')->click();
    $this->createScreenshot('../testdata/screenshots/reports-import-job-1.png');
    $assert_session->pageTextContains('Job 1 Imported 2022-12-15 by Test Board Manager');
    $form = $this->getSession()->getPage();
    $form->findButton('2 Unique IDs in batch')->click();
    $form->findButton('2 Articles imported')->click();
    $assert_session->pageTextContains('0 Articles NOT listed');
    $assert_session->pageTextContains('0 Duplicate articles');
    $assert_session->pageTextContains('0 Articles ready for review');
    $assert_session->pageTextContains('0 Articles with topic added');
    $assert_session->pageTextContains('0 Articles replaced');
    $assert_session->pageTextContains('0 Articles with errors');
    $this->createScreenshot('../testdata/screenshots/reports-import-job-1-articles.png');
    $assert_session->pageTextMatchesCount(2, '/10000001\s+1\s+Obsc Med 101\(8\): 207-214, 2022/');
    $assert_session->pageTextMatchesCount(2, '/35775213\s+2\s+Quark Redux 9: 5-9, 2023/');
    $assert_session->pageTextMatchesCount(1, '/10000001\s+1\s+Obsc Med 101\(8\): 207-214, 2022\s+foo/');
    $assert_session->pageTextMatchesCount(1, '/35775213\s+2\s+Quark Redux 9: 5-9, 2023\s+bar/');
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('board', 2);
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/reports-import-board-selected.png');
    $this->getSession()->getPage()->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-import-board-filtered.png');
    $assert_session->pageTextContains('Import Jobs (1)');
    $assert_session->pageTextMatches('/2\s+2022-12-30\s+Test Board 2\s+Test Topic 2\s+1/');
    $assert_session->pageTextNotContains('Test Board 1');

    // Add state terms needed for the Article Statistics reports.
    Term::create(['tid' => 5001, 'vid' => 'states', 'name' => 'Ready for initiali librarian review', 'field_text_id' => 'ready_init_review', 'field_sequence' => 10])->save();
    Term::create(['tid' => 5002, 'vid' => 'states', 'name' => 'Rejected by NOT list', 'field_text_id' => 'reject_journal_title', 'field_sequence' => 20])->save();
    Term::create(['tid' => 5003, 'vid' => 'states', 'name' => 'Passed initial librarian review', 'field_text_id' => 'passed_init_review', 'field_sequence' => 30])->save();
    Term::create(['tid' => 5004, 'vid' => 'states', 'name' => 'Rejected in initial librarian review', 'field_text_id' => 'reject_init_review', 'field_sequence' => 30])->save();
    Term::create(['tid' => 5005, 'vid' => 'states', 'name' => 'Published', 'field_text_id' => 'published', 'field_sequence' => 40])->save();
    Term::create(['tid' => 5006, 'vid' => 'states', 'name' => 'Passed abstract review', 'field_text_id' => 'passed_bm_review', 'field_sequence' => 50])->save();
    Term::create(['tid' => 5007, 'vid' => 'states', 'name' => 'Rejected after abstract review', 'field_text_id' => 'reject_bm_review', 'field_sequence' => 50])->save();
    Term::create(['tid' => 5008, 'vid' => 'states', 'name' => 'Flagged as FYI', 'field_text_id' => 'fyi', 'field_sequence' => 60])->save();
    Term::create(['tid' => 5009, 'vid' => 'states', 'name' => 'Held after full text review', 'field_text_id' => 'full_review_hold', 'field_sequence' => 60])->save();
    Term::create(['tid' => 5010, 'vid' => 'states', 'name' => 'Passed full text review', 'field_text_id' => 'passed_full_review', 'field_sequence' => 60])->save();
    Term::create(['tid' => 5011, 'vid' => 'states', 'name' => 'Rejected after full text review', 'field_text_id' => 'reject_full_review', 'field_sequence' => 60])->save();
    Term::create(['tid' => 5012, 'vid' => 'states', 'name' => 'On hold', 'field_text_id' => 'on_hold', 'field_sequence' => 70])->save();
    Term::create(['tid' => 5013, 'vid' => 'states', 'name' => 'No further action', 'field_text_id' => 'full_end', 'field_sequence' => 70])->save();
    Term::create(['tid' => 5014, 'vid' => 'states', 'name' => 'Paper for board discussion', 'field_text_id' => 'agenda_board_discuss', 'field_sequence' => 70])->save();
    Term::create(['tid' => 5015, 'vid' => 'states', 'name' => 'On agenda', 'field_text_id' => 'on_agenda', 'field_sequence' => 80])->save();
    Term::create(['tid' => 5016, 'vid' => 'states', 'name' => 'Final board decision', 'field_text_id' => 'final_board_decision', 'field_sequence' => 70])->save();

    // Test the Articles Imported report.
    $article = Article::load(1);
    $article->addState('ready_init_review', 1, $user->id(), '2022-12-30', '2023-01-01');
    $article->save();
    $article = Article::load(2);
    $article->addState('ready_init_review', 2, $user->id(), '2022-12-30', '2023-01-01');
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Articles Imported');
    $form->selectFieldOption('Review Cycle', '2023-01-01');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-articles-imported.png');
    $assert_session->pageTextMatches('/Test Board 1\s+Topic\s+Imported\s+Test Topic 1\s+1\s+Total\s+1/');
    $assert_session->pageTextMatches('/Test Board 2\s+Topic\s+Imported\s+Test Topic 2\s+1\s+Total\s+1/');

    // Test the Articles Excluded by Journal report.
    $article = Article::load(1);
    $article->addState('reject_journal_title', 1, $user->id(), '2023-01-05', '2023-01-01');
    $article->save();
    $article = Article::load(2);
    $article->addState('reject_journal_title', 2, $user->id(), '2023-01-05', '2023-01-01');
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Articles Excluded by Journal');
    $form->selectFieldOption('Review Cycle', '2023-01-01');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-articles-excluded-by-journal.png');
    $assert_session->pageTextContains('Articles Rejected By Journal Title');
    $assert_session->pageTextContains('Review cycle: January 2023');
    $assert_session->pageTextMatches('/Test Board 1\s+Topic\s+Rejected\s+Test Topic 1\s+1\s+Total\s+1/');
    $assert_session->pageTextMatches('/Test Board 2\s+Topic\s+Rejected\s+Test Topic 2\s+1\s+Total\s+1/');
    $assert_session->pageTextMatches('/Grand Total\s+All Boards\s+2/');

    // Test the Articles Rejected/Accepted for Publication report.
    $article = Article::load(1);
    $article->addState('passed_init_review', 1, $user->id(), '2023-01-10', '2023-01-01');
    $article->save();
    $article = Article::load(2);
    $article->addState('reject_init_review', 2, $user->id(), '2023-01-10', '2023-01-01');
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Articles Rejected/Accepted for Publishing');
    $form->selectFieldOption('Review Cycle', '2023-01-01');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-articles-initial-review.png');
    $assert_session->pageTextContains('Articles Rejected/Accepted for Publication');
    $assert_session->pageTextContains('Review cycle: January 2023');
    $assert_session->pageTextMatches('/Test Board 1\s+Topic\s+Rejected\s+Accepted\s+Test Topic 1\s+0\s+1\s+Total\s+0\s+1/');
    $assert_session->pageTextMatches('/Test Board 2\s+Topic\s+Rejected\s+Accepted\s+Test Topic 2\s+1\s+0\s+Total\s+1\s+0/');

    // Test the Articles Reviewed by Medical Librarian report.
    $this->drupalGet(Url::fromRoute('ebms_report.articles'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Articles Reviewed by Medical Librarian');
    $form->selectFieldOption('Review Cycle', '2023-01-01');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-articles-reviewed-by-medical-librarian.png');
    $assert_session->pageTextContains('Articles Reviewed By Medical Librarian');
    $assert_session->pageTextContains('Review cycle: January 2023');
    $assert_session->pageTextMatches('/Test Board 1\s+Topic\s+Reviewed\s+Test Topic 1\s+1\s+Total\s+1/');
    $assert_session->pageTextMatches('/Test Board 2\s+Topic\s+Reviewed\s+Test Topic 2\s+1\s+Total\s+1/');
    $assert_session->pageTextMatches('/Grand Total\s+All Boards\s+2/');

    // Test the Articles Published report.
    $article = Article::load(1);
    $article->addState('published', 1, $user->id(), '2023-01-13', '2023-01-01');
    $article->save();
    $article = Article::load(2);
    $article->addState('published', 2, $user->id(), '2023-01-13', '2023-01-01');
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Articles Published');
    $form->selectFieldOption('Review Cycle', '2023-01-01');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-articles-published.png');
    $assert_session->pageTextContains('Articles Published');
    $assert_session->pageTextContains('Review cycle: January 2023');
    $assert_session->pageTextMatches('/Test Board 1\s+Topic\s+Published\s+Test Topic 1\s+1\s+Total\s+1/');
    $assert_session->pageTextMatches('/Test Board 2\s+Topic\s+Published\s+Test Topic 2\s+1\s+Total\s+1/');

    // Test the Articles Rejected In Review From Abstract report.
    $article = Article::load(1);
    $article->addState('reject_bm_review', 1, $user->id(), '2023-01-14', '2023-01-01');
    $article->save();
    $article = Article::load(2);
    $article->addState('reject_bm_review', 2, $user->id(), '2023-01-14', '2023-01-01');
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Articles Rejected In Review From Abstract');
    $form->selectFieldOption('Review Cycle', '2023-01-01');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-articles-rejected-from-abstract-articles.png');
    $assert_session->pageTextContains('Articles Rejected During Review From Abstract');
    $form->selectFieldOption('Review Cycle', '2023-01-01');
    $assert_session->pageTextMatches('/Rejected Articles\s+EBMS ID\s+PMID\s+Topic\s+Reviewer\s+Comments/');
    $assert_session->pageTextMatches('/1\s+10000001\s+Test Topic 1\s+Test Board Manager/');
    $assert_session->pageTextMatches('/2\s+35775213\s+Test Topic 2\s+Test Board Manager/');
    $this->drupalGet(Url::fromRoute('ebms_report.articles'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Articles Rejected In Review From Abstract');
    $form->selectFieldOption('cycle-start', '2023-01-01');
    $form->selectFieldOption('cycle-end', '2023-03-01');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-articles-rejected-from-abstract-topic-counts.png');
    $assert_session->pageTextContains('Articles Rejected During Review From Abstract');
    $assert_session->pageTextContains('Review cycle range: January 2023 through March 2023');
    $assert_session->pageTextMatches('/Rejections By Topic\s+Topic\s+Count\s+Test Topic 1\s+1\s+Test Topic 2\s+1/');

    // Test the Article Summary Topic Changes report.
    // Note that it is correct that "After Librarian Review" has no topics for
    // article #2, because the librarian rejected the article.
    $article = Article::load(1);
    $article->addState('passed_bm_review', 1, $user->id(), '2023-01-15', '2023-01-01');
    $article->addState('passed_bm_review', 2, $user->id(), '2023-01-15', '2023-01-01');
    $article->save();
    $article = Article::load(2);
    $article->addState('passed_bm_review', 1, $user->id(), '2023-01-15', '2023-01-01');
    $article->addState('passed_bm_review', 2, $user->id(), '2023-01-15', '2023-01-01');
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Article Summary Topic Changes');
    $form->fillField('decision-date-start', '01/01/2023');
    $form->fillField('decision-date-end', '01/31/2023');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-article-topic-changes.png');
    $assert_session->pageTextContains('Articles Summary Topic Changes');
    $assert_session->pageTextContains('Report Date: ' . date('Y-m-d'));
    $assert_session->pageTextContains('Decision Date Range: 2023-01-01 through 2023-01-31');
    $assert_session->pageTextContains('Article Summary Topic Changes (2)');
    $assert_session->pageTextMatches('/EBMS ID\s+PMID\s+After Librarian Review\s+After NCI Review/');
    $assert_session->pageTextMatches('/1\s+10000001\s+Test Topic 1\s+Test Topic 1\s+Test Topic 2/');
    $assert_session->pageTextMatches('/2\s+35775213\s+Test Topic 1\s+Test Topic 2/');

    // Test the Articles Approved During Review From Abstract report.
    $this->drupalGet(Url::fromRoute('ebms_report.articles'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Articles Approved in Review From Abstract');
    $form->selectFieldOption('Review Cycle', '2023-01-01');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-articles-approved-from-abstract.png');
    $assert_session->pageTextContains('Articles Approved During Review From Abstract');
    $assert_session->pageTextContains('Review cycle: January 2023');
    $assert_session->pageTextMatches('/Test Board 1\s+Topic\s+Approved\s+Test Topic 1\s+2\s+Total\s+2/');
    $assert_session->pageTextMatches('/Test Board 2\s+Topic\s+Approved\s+Test Topic 2\s+2\s+Total\s+2/');
    $assert_session->pageTextMatches('/Grand Total\s+All Boards\s+2/');

    // Test the Abstract Decision Status report.
    $this->drupalGet(Url::fromRoute('ebms_report.articles_by_status'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Abstract Decision');
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-status-abstract-decision.png');
    $assert_session->pageTextContains('Abstract Review Decisions (2 Articles, 2 Decisions)');
    $assert_session->pageTextMatches('/Liu M\s+Test Article 2\s+Quark Redux 9: 5-9, 2023\s+PMID: 35775213/');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001/');
    $assert_session->pageTextMatchesCount(2, '/Approved for Test Topic 1 2023-01-15 by Test Board Manager in the January 2023 review cycle\./');

    // Test the Full Text Retrieved Status report.
    $this->drupalGet(Url::fromRoute('ebms_report.articles_by_status'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Full Text Retrieved');
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Disposition', 'retrieved');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-status-full-text-retrieved.png');
    $assert_session->pageTextContains('Full Text Retrieved (2)');
    $assert_session->pageTextMatches('/Liu M\s+Test Article 2\s+Quark Redux 9: 5-9, 2023\s+PMID: 35775213/');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001/');
    $assert_session->pageTextMatchesCount(2, '/Topic Test Topic 1 for the January 2023 review cycle\s+Test File 1 retrieved 2023-01-16/');

    // Test the Full Text Decision Status report.
    $article = Article::load(1);
    $article->addState('passed_full_review', 1, $user->id(), '2023-01-17', '2023-01-01');
    $article->addState('passed_full_review', 2, $user->id(), '2023-01-17', '2023-01-01');
    $article->save();
    $article = Article::load(2);
    $article->addState('reject_full_review', 1, $user->id(), '2023-01-17', '2023-01-01');
    $article->addState('passed_full_review', 2, $user->id(), '2023-01-17', '2023-01-01');
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles_by_status'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Full Text Decision');
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-status-full-text-decision.png');
    $assert_session->pageTextContains('Full-Text Review Decisions (2 Articles, 2 Decisions)');
    $assert_session->pageTextMatches('/Liu M\s+Test Article 2\s+Quark Redux 9: 5-9, 2023\s+PMID: 35775213\s+Rejected/');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001\s+Approved/');
    $assert_session->pageTextMatchesCount(2, '/ for Test Topic 1 2023-01-17 by Test Board Manager in the January 2023 review cycle\./');

    // Test the Assigned For Review Status report.
    $packet_article = PacketArticle::load(1);
    $packet_article->set('reviews', []);
    $packet_article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles_by_status'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Assigned For Review');
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-status-assigned-for-review.png');
    $assert_session->pageTextContains('Articles Assigned For Review (1 Article, 1 Assignment)');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001/');
    $assert_session->pageTextContains('Assigned 2023-01-29 by Test Board Manager for Test Topic 1');
    $assert_session->pageTextContains('Review Cycle: January 2023');
    $assert_session->pageTextContains('Reviewers: Test Board Member 1, Test Board Member 2');

    // Test the Board Member Responses Status report.
    $packet_article->set('reviews', [1, 2]);
    $packet_article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles_by_status'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Board Member Responses');
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-status-board-member-responses.png');
    $assert_session->pageTextContains('Reviewer Responses (1 Article, 2 Reviews)');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001/');
    $assert_session->pageTextMatches('/Reviewed 2022-05-26 by Test Board Member 1 for Test Topic 1\s+Dispositions: Deserves citation in the summary/');
    $assert_session->pageTextMatches('/Reviewed 2023-02-23 by Test Board Member 3 for Test Topic 1\s+Dispositions: Warrants no changes to the summary\s+Yada/');

    // Test the Board Manager Action Status report.
    $article = Article::load(1);
    $article->addState('full_end', 1, $user->id(), '2023-01-20', '2023-01-01');
    $article->save();
    $article = Article::load(2);
    $article->addState('agenda_board_discuss', 1, $user->id(), '2023-01-21', '2023-01-01');
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles_by_status'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Board Manager Action');
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-status-board-manager-action.png');
    $assert_session->pageTextContains('Board Manager Actions (2 Articles, 2 Actions)');
    $assert_session->pageTextMatches('/Liu M\s+Test Article 2\s+Quark Redux 9: 5-9, 2023\s+PMID: 35775213/');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001/');
    $assert_session->pageTextContains('Assigned disposition Paper for board discussion 2023-01-21 by Test Board Manager for Test Topic 1 (review cycle January 2023)');
    $assert_session->pageTextContains('Assigned disposition No further action 2023-01-20 by Test Board Manager for Test Topic 1 (review cycle January 2023)');

    // Test the On Agenda Status report.
    $article = Article::load(1);
    $state = $article->addState('on_agenda', 1, $user->id(), '2023-01-22', '2023-01-01', 'Agenda test comment');
    $state->meetings->appendItem(101);
    $state->save();
    $article->save();
    $article = Article::load(2);
    $state = $article->addState('on_agenda', 1, $user->id(), '2023-01-23', '2023-01-01', 'Another agenda test comment');
    $state->meetings->appendItem(103);
    $state->save();
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles_by_status'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'On Agenda');
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-status-on-agenda.png');
    $assert_session->pageTextContains('On Agenda (2 Articles, 2 Topics, 2 Meetings)');
    $assert_session->pageTextMatches('/Liu M\s+Test Article 2\s+Quark Redux 9: 5-9, 2023\s+PMID: 35775213/');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001/');
    $assert_session->pageTextMatchesCount(2, '/On agenda for Test Topic 1 in review cycle January 2023/');
    $assert_session->pageTextContains('Board meeting Test Meeting 3 scheduled for 2023-02-14');
    $assert_session->pageTextContains('Board meeting Test Meeting 1 scheduled for 2023-02-14');
    $assert_session->pageTextMatches('/Another agenda test comment.+Agenda test comment/');

    // Test the Editorial Board Decisions status report.
    Term::create(['tid' => 6001, 'vid' => 'board_decisions', 'name' => 'Cited (citation only)'])->save();
    Term::create(['tid' => 6002, 'vid' => 'board_decisions', 'name' => 'Text approved'])->save();
    $article = Article::load(1);
    $state = $article->addState('final_board_decision', 1, $user->id(), '2023-01-24', '2023-01-01', 'Final decision test comment');
    $state->decisions->appendItem(6001);
    $state->save();
    $article->save();
    $article = Article::load(2);
    $state = $article->addState('final_board_decision', 1, $user->id(), '2023-01-25', '2023-01-01', 'Another final decision test comment');
    $state->decisions->appendItem(6002);
    $state->save();
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles_by_status'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Report', 'Editorial Board Decision');
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-status-editorial-board-decision.png');
    $assert_session->pageTextContains('Editorial Board Decisions (2 Articles, 2 Topics, 2 Decisions)');
    $assert_session->pageTextMatches('/Liu M\s+Test Article 2\s+Quark Redux 9: 5-9, 2023\s+PMID: 35775213/');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001/');
    $assert_session->pageTextMatches('/Final decisions for Test Topic 1 taken 2023-01-25 in review cycle January 2023\s+Text approved/');
    $assert_session->pageTextMatches('/Final decisions for Test Topic 1 taken 2023-01-24 in review cycle January 2023\s+Cited \(citation only\)/');
    $assert_session->pageTextMatches('/Another final decision test comment.+Final decision test comment/');

    // Test the Articles by Tag report.
    Term::create(['tid' => 7001, 'vid' => 'article_tags', 'name' => 'High priority', 'field_text_id' => 'high_priority', 'topic_allowed' => TRUE, 'topic_required' => TRUE])->save();
    Term::create(['tid' => 7002, 'vid' => 'article_tags', 'name' => 'Comment for librarian', 'field_text_id' => 'librarian_cmt', 'topic_allowed' => TRUE, 'topic_required' => TRUE])->save();
    $article = Article::load(1);
    $article->addTag('high_priority', 1, 0, '2023-01-26 12:34:56', 'Test tag comment');
    $article->save();
    $article = Article::load(2);
    $article->addTag('high_priority', 1, 0, '2023-01-26 13:35:57', 'Another tag comment');
    $article->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles_by_tag'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->selectFieldOption('Tag', 7001);
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-articles-by-tag.png');
    $assert_session->pageTextMatches("/Tag 'High priority' assigned for 1 Topic in 2 Articles\s+Test Topic 1/");
    $assert_session->pageTextMatches('/Liu M\s+Test Article 2\s+Quark Redux 9: 5-9, 2023\s+PMID: 35775213/');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001/');
    $assert_session->pageTextContains('Current state: Editorial Board Decision (Text approved)');
    $assert_session->pageTextContains('Current state: Editorial Board Decision (Cited (citation only))');
    $assert_session->pageTextMatchesCount(2, '/Tag assigned 2023-01-26/');
    $assert_session->pageTextContains('Comment: Final decision test comment');
    $assert_session->pageTextContains('Comment: Another final decision test comment');

    // Test the Literature Reviews report.
    $this->drupalGet(Url::fromRoute('ebms_report.literature_reviews'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-literature-reviews.png');
    $assert_session->pageTextContains('Literature Reviews (1 Article with 2 Reviews in 1 Packet)');
    $assert_session->pageTextNotContains('Test Article 2');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001\s+High Priority/');
    $assert_session->pageTextContains('Packet Test Packet 1 created 2023-01-29 for cycle January 2023');
    $assert_session->pageTextMatches('/✅\s+Review posted 2022-05-26 by Test Board Member 1\sDisposition\(s\): Deserves citation in the summary/');
    $assert_session->pageTextMatches('/❌\s+Review posted 2023-02-23 by Test Board Member 3\sDisposition\(s\): Warrants no changes to the summary\s+Reason\(s\) for rejection: Already cited in the PDQ summary\s+Comment: Yada/');

    // Test the Responses By Reviewer report.
    $this->drupalGet(Url::fromRoute('ebms_report.responses_by_reviewer'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-reponses-by-reviewer.png');
    $assert_session->pageTextContains('Review Statistics (1 Packet for 2 Reviewers)');
    $assert_session->pageTextMatches('/Packet Name\s+Created.+Reviewer\s+Assigned\s+Completed\s+Not Completed/');
    $assert_session->pageTextMatches('/Test Packet 1\s+2023-01-29\s+Test Board Member 1\s+1\s+1\s+0/');
    $assert_session->pageTextMatches('/Test Packet 1\s+2023-01-29\s+Test Board Member 2\s+1\s+0\s+1/');
    $assert_session->pageTextMatches('/Totals\s+2\s+1\s+1/');

    // Test the Articles Without Responses report.
    Topic::create(['id' => 3, 'name' => 'Test Topic 3', 'board' => 1])->save();
    $article = Article::load(1);
    $article->addState('passed_full_review', 3, $user->id(), '2023-01-31', '2023-02-01');
    $article->save();
    $article = Article::load(2);
    $article->addState('passed_full_review', 3, $user->id(), '2023-01-31', '2023-02-01');
    $article->save();
    PacketArticle::create(['id' => 3, 'article' => 1])->save();
    PacketArticle::create(['id' => 4, 'article' => 2])->save();
    Packet::create(['title' => 'Test Packet 3', 'articles' => [3, 4], 'topic' => 3, 'created' => '2023-01-31', 'created_by' => $user->id(), 'reviewers' => [101, 102]])->save();
    $this->drupalGet(Url::fromRoute('ebms_report.articles_without_responses'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-articles-without-responses.png');
    $assert_session->pageTextContains('2 Articles in 1 Packet Assigned to 2 Reviewers');
    $assert_session->pageTextMatches('/Liu M\s+Test Article 2\s+Quark Redux 9: 5-9, 2023\s+PMID: 35775213/');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001/');
    $assert_session->pageTextMatchesCount(2, '/Topic: Test Topic 3/');
    $assert_session->pageTextMatchesCount(1, '/Topic: Test Topic 3 \(tagged High Priority\)/');
    $assert_session->pageTextMatchesCount(2, '/Assigned 2023-01-31 to Test Board Member 1, Test Board Member 2/');
    $this->getSession()->getPage()->findById('edit-member-version-button')->click();
    $tabs = $this->getSession()->getWindowNames();
    $this->getSession()->switchToWindow($tabs[1]);
    $this->createScreenshot('../testdata/screenshots/reports-articles-without-responses-member-version.png');
    $assert_session->pageTextContains('Articles Awaiting Reviews');
    $assert_session->pageTextContains('2 Articles in 1 Packet Assigned to 2 Reviewers');
    $assert_session->pageTextMatches('/Liu M\s+Test Article 2\s+Quark Redux 9: 5-9, 2023\s+PMID: 35775213/');
    $assert_session->pageTextMatches('/Parmigiani G\s+Test Article 1\s+Obsc Med 101\(8\): 207-214, 2022\s+PMID: 10000001/');
    $assert_session->pageTextMatchesCount(2, '/Topic: Test Topic 3/');
    $assert_session->pageTextMatchesCount(1, '/Topic: Test Topic 3 \(tagged High Priority\)/');
    $assert_session->pageTextMatchesCount(2, '/Assigned 2023-01-31 to Test Board Member 1, Test Board Member 2/');

    // Test the Topic Reviewers report.
    $this->drupalGet(Url::fromRoute('ebms_report.topic_reviewers'));
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->selectFieldOption('Editorial Board', 1);
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-topic-reviewers-by-topic.png');
    // For testing.
    // $html = $this->getSession()->getPage()->getHtml();
    // file_put_contents('../dross/topic-reviewers-report.html', $html);
    $assert_session->pageTextContains('Test Board 1 Board (2 Topics, 2 Reviewers)');
    $assert_session->pageTextMatches('/Topic\s+Board Members/');
    $assert_session->pageTextMatches('/Test Topic 1\s+Test Board Member 1\s+Test Board Member 3/');
    $assert_session->pageTextMatches('/Test Topic 3\s+Test Board Member 3/');
    $form = $this->getSession()->getPage();
    $form->findButton('Display Options')->click();
    $form->findById('edit-grouping-member')->click();
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-topic-reviewers-by-reviewer.png');
    $assert_session->pageTextContains('Test Board 1 Board (2 Topics, 2 Reviewers)');
    $assert_session->pageTextMatches('/Board Member\s+Topics/');
    $assert_session->pageTextMatches('/Test Board Member 1\s+Test Topic 1/');
    $assert_session->pageTextMatches('/Test Board Member 3\s+Test Topic 1\s+Test Topic 3/');

    // Test the Invalid PMID report.
    $this->drupalGet(Url::fromRoute('ebms_report.abandoned_articles'));
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reports-abandoned-articles.png');
    $assert_session->pageTextContains('1 Invalid PubMed ID (Checked 2 Active Articles)');
    $assert_session->pageTextContains('35775213 (EBMS ID 2)');
  }

}
