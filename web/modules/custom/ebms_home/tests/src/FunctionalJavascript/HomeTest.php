<?php

namespace Drupal\Tests\ebms_home\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\ebms_article\Entity\Article;
use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_doc\Entity\Doc;
use Drupal\ebms_meeting\Entity\Meeting;
use Drupal\ebms_message\Entity\Message;
use Drupal\ebms_review\Entity\Packet;
use Drupal\ebms_review\Entity\PacketArticle;
use Drupal\ebms_review\Entity\Review;
use Drupal\ebms_summary\Entity\BoardSummaries;
use Drupal\ebms_summary\Entity\SummaryPage;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\ebms_travel\Entity\HotelRequest;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;

/**ß
 * Test the EBMS home pages.
 *
 * @group ebms
 */
class HomeTest extends WebDriverTestBase {

  const TEST_ROLES = [
    'board_manager',
    'board_member',
    'medical_librarian',
    'admin_assistant',
  ];

  protected static $modules = [
    'ebms_home',
    'ebms_breadcrumb',
    'ebms_help',
    'ebms_menu',
    'ebms_user',
  ];
  protected $defaultTheme = 'ebms';

  private $test_users = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create some users.
    foreach (self::TEST_ROLES as $role) {
      $user = $this->createUser();
      $user->addRole($role);
      $user->set('boards', [1]);
      $user->save();
      $this->test_users[$role] = $user;
    }

    // Create some vocabularies.
    $term_ids = [];
    $vocabularies = [
      [
        'vid' => 'meeting_categories',
        'values' => ['Board', 'Subgroup'],
      ],
      [
        'vid' => 'meeting_statuses',
        'values' => ['Scheduled', 'Canceled'],
      ],
      [
        'vid' => 'meeting_types',
        'values' => ['In Person', 'Webex/Phone Conf.'],
      ],
    ];
    foreach ($vocabularies as $vocabulary) {
      $weight = 10;
      $vid = $vocabulary['vid'];
      $term_ids[$vid] = [];
      foreach ($vocabulary['values'] as $name) {
        $values = [
          'vid' => $vid,
          'name' => $name,
          'status' => TRUE,
          'weight' => $weight,
        ];
        $weight += 10;
        $term = Term::create($values);
        $term->save();
        $term_ids[$vid][$name] = $term->id();
      }
    }
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

    // Create a packet.
    Board::create([
      'id' => 1,
      'name' => 'Test Board',
      'manager' => $this->test_users['board_manager']->id(),
    ])->save();
    Topic::create(['id' => 1, 'name' => 'Test Topic', 'board' => 1])->save();
    for ($i = 1; $i <= 2; ++$i) {
      $article = Article::create(['id' => $i, 'title' => "Test Article $i"]);
      $article->save();
      $article->addState('passed_full_review', 1);
      $article->save();
    }
    PacketArticle::create(['id' => 1, 'article' => 1])->save();
    PacketArticle::create(['id' => 2, 'article' => 2])->save();
    Packet::create([
      'id' => 1,
      'title' => 'Test Packet',
      'topic' => 1,
      'reviewers' => [$this->test_users['board_member']->id()],
      'active' => TRUE,
      'articles' => [1, 2],
    ])->save();


    // Create a meeting.
    $next_week = date('Y-m-d', strtotime('+1 week'));
    Meeting::create([
      'name' => 'Test Meeting',
      'dates' => [
        'value' => "{$next_week}T13:00:00",
        'end_value' => "{$next_week}T16:00:00",
      ],
      'type' => $term_ids['meeting_types']['In Person'],
      'category' => $term_ids['meeting_categories']['Board'],
      'status' => $term_ids['meeting_statuses']['Scheduled'],
      'published' => 1,
      'agenda_published' => 1,
      'boards' => [1],
      'agenda' => [
        'value' => 'Yada yada',
        'format' => 'filtered_html',
      ],
    ])->save();

    // Create some messages.
    $now = date('Y-m-d H:i:s');
    Message::create([
      'message_type' => Message::PACKET_CREATED,
      'posted' => $now,
      'individuals' => [$this->test_users['board_member']->id()],
      'extra_values' => json_encode([
        'packet_id' => 1,
        'title' => 'Test Packet',
      ]),
    ])->save();
    for ($i = 1; $i <= 10; ++$i) {
      Message::create([
        'message_type' => Message::SUMMARY_POSTED,
        'user' => $this->test_users[($i % 2 == 1) ? 'board_member' : 'board_manager']->id(),
        'posted' => $now,
        'boards' => [1],
        'extra_values' => json_encode([
          'summary_url' => "/some/file-{$i}.docx",
          'title' => "Test Summary $i",
          'notes' => $i === 3 ? 'Nota bene!' : '',
        ]),
      ])->save();
    }
    Message::create([
      'message_type' => Message::MEETING_PUBLISHED,
      'posted' => $now,
      'boards' => [1],
      'extra_values' => json_encode([
        'meeting_id' => 1,
        'title' => 'Test Meeting',
      ]),
    ])->save();
    Message::create([
      'message_type' => Message::AGENDA_PUBLISHED,
      'posted' => $now,
      'boards' => [1],
      'extra_values' => json_encode([
        'meeting_id' => 1,
        'title' => 'Test Meeting',
      ]),
    ])->save();

    // Create a couple of hotel requests.
    HotelRequest::create(['user' => $this->test_users['board_member']->id(), 'submitted' => $now])->save();
    HotelRequest::create(['user' => $this->test_users['board_member']->id(), 'submitted' => $now])->save();

    // Post a member document to a board summaries page.
    Doc::create(['id' => 1, 'posted' => $now, 'boards' => [1]])->save();
    SummaryPage::create(['id' => 1, 'member_docs' => ['doc' => 1, 'active' => TRUE], 'active' => TRUE])->save();
    BoardSummaries::create(['board' => 1, 'pages' => [1]])->save();

    // Adjust the configuration of the site.
    $config = $this->config('system.site');
    $config->set('page.front', '/home');
    $config->save();
    $this->getSession()->resizeWindow(1024, 1424, 'current');
    $parameters = $this->container->getParameter('twig.config');
    $parameters['debug'] = TRUE;
    $this->setContainerParameter('twig.config', $parameters);
  }

  /**
   * Test the home pages and the common theme elements.
   */
  public function testHome() {

    // Test the components common to all pages.
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $this->drupalGet(Url::fromRoute('ebms_core.login')->toString());
    $banner = $assert_session->waitForElementVisible('css', '.usa-banner');
    $this->assertNotEmpty($banner);
    $header = $assert_session->waitForElementVisible('css', 'header h1');
    $this->assertNotEmpty($header);
    $this->assertEquals('EBMS', $header->getText());
    $footer_text_links = $assert_session->waitForElementVisible('css', 'footer div#footer-text-links');
    $this->assertNotEmpty($footer_text_links);
    $footer_image_links = $assert_session->waitForElementVisible('css', 'footer div#footer-image-links');
    $this->assertNotEmpty($footer_image_links);
    $assert_session->pageTextContains('You need either a Google account or an NIH account to log into this system.');
    $assert_session->pageTextContains('An official website of the United States government');
    $page = $this->getSession()->getPage();
    $page->findButton("Here's how you know")->click();
    $this->createScreenshot('../testdata/screenshots/heres-how-you-know.png');
    $assert_session->pageTextContains('Official websites use .gov');
    $assert_session->pageTextContains('Secure .gov websites use HTTPS');
    $this->createScreenshot('../testdata/screenshots/login-page.png');

    // Log in as a board member.
    $this->login('board_member');
    $this->createScreenshot('../testdata/screenshots/member-home-page.png');
    $assert_session->pageTextContains('Alerts');
    $assert_session->pageTextContains('Next meeting: Test Meeting at 1pm');
    $assert_session->pageTextContains('You have 2 articles assigned for review');
    $assert_session->linkExists('assigned for review');
    $assert_session->linkExists('Test Meeting');
    $assert_session->pageTextContains('In Person (Agenda posted)');
    $assert_session->pageTextContains('Literature Activity');
    $assert_session->pageTextContains('Test Packet literature posted');
    $assert_session->linkExists('Test Packet');
    $assert_session->pageTextContains('Document Activity');
    $assert_session->pageTextContains('posted Test Summary 5');
    $assert_session->linkExists('Test Summary 5');
    $assert_session->linkExists('More');
    $assert_session->pageTextContains('Meeting Activity');
    $assert_session->pageTextContains('New meeting Test Meeting posted');
    $assert_session->pageTextContains('Agenda published for Test Meeting');
    $this->clickLink('More');
    $this->createScreenshot('../testdata/screenshots/more-document-activity.png');
    $assert_session->pageTextContains('Recent Document Activity');

    // Add a review and verify the change to the home page.
    Review::create(['reviewer' => $this->test_users['board_member']->id, 'posted' => date('Y-m-d H:i:s')])->save();
    $packet_article = PacketArticle::load(1);
    $packet_article->set('reviews', [1]);
    $packet_article->save();
    $this->clickLink('EBMS');
    $this->createScreenshot('../testdata/screenshots/home-page-with-added-review.png');
    $assert_session->pageTextContains('You have 1 articles assigned for review');
    $assert_session->linkExists('assigned for review');
    $this->drupalGet(Url::fromRoute('user.logout')->toString());
    $this->createScreenshot('../testdata/screenshots/logged-out.png');
    $assert_session->linkExists('Go To Login Page');

    // Log in as a board manager.
    $this->login('board_manager');
    $this->createScreenshot('../testdata/screenshots/manager-home-page.png');
    $assert_session->pageTextContains('Alerts');
    $assert_session->pageTextContains("1 new review has been posted for your board's review packets.");
    $assert_session->linkExists('new review');
    $assert_session->pageTextContains('2 hotel requests have been submitted by your board members in the past 60 days.');
    $assert_session->linkExists('hotel requests');
    $assert_session->pageTextContains('1 new summary has been posted by the members of your board.');
    $assert_session->linkExists('new summary');
    $assert_session->pageTextContains('Literature Activity');
    $assert_session->pageTextContains('New Test Board articles posted');
    $assert_session->pageTextContains('Document Activity');
    $assert_session->pageTextContains('Meeting Activity');
    $this->drupalGet(Url::fromRoute('user.logout')->toString());
    $assert_session->linkExists('Go To Login Page');

    // Log in as a librarian.
    $this->login('medical_librarian');
    $this->createScreenshot('../testdata/screenshots/librarian-home-page.png');
    $assert_session->pageTextNotContains('Alerts');
    $assert_session->pageTextNotContains('Literature Activity');
    $assert_session->pageTextNotContains('Document Activity');
    $assert_session->pageTextNotContains('Meeting Activity');
    $assert_session->pageTextMatches('/Admin\s+Articles.+Journals\s+Reports\s+Calendar\s+About\s+Help/');
    $librarian_image = $assert_session->waitForElementVisible('css', 'div#block-ebms-content > img');
    $this->assertNotEmpty($librarian_image);
    $this->drupalGet(Url::fromRoute('user.logout')->toString());
    $assert_session->linkExists('Go To Login Page');

    // Log in as an admin assistant.
    $this->login('admin_assistant');
    $this->createScreenshot('../testdata/screenshots/admin-assistant-home-page.png');
    $assert_session->pageTextContains('Alerts');
    $assert_session->pageTextContains('Literature Activity');
    $assert_session->pageTextContains('Document Activity');
    $assert_session->pageTextContains('Meeting Activity');
    $assert_session->pageTextMatches('/Admin\s+Articles.+Journals\s+Packets\s+Summaries\s+Reports\s+Travel\s+Calendar\s+About\s+Help/');
    $this->drupalGet(Url::fromRoute('user.logout')->toString());
    $assert_session->linkExists('Go To Login Page');
  }

  /**
   * Bring up the native Drupal login form and submit the user's credentials.
   *
   * @param string $role
   *   key to the dictionary of test users
   *
   * @return User
   *   object for the user account
   */
  private function login(string $role): User {
    $this->clickLInk('Go To Login Page');
    if ($role === 'board_member') {
      $this->createScreenshot('../testdata/screenshots/login-form.png');
    }
    $user = $this->test_users[$role];
    $form = $this->getSession()->getPage();
    $form->fillField('name', $user->name->value);
    $form->fillField('pass', $user->passRaw);
    $form->findButton('Log in')->click();
    return $user;
  }

}
