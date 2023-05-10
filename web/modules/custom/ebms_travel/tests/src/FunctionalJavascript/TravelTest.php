<?php

namespace Drupal\Tests\ebms_travel\FunctionalJavascript;

use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_meeting\Entity\Meeting;
use Drupal\ebms_travel\Entity\HotelRequest;
use Drupal\ebms_travel\Entity\ReimbursementRequest;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Url;

/**
 * Test the travel forms.
 *
 * @group ebms
 */
class TravelTest extends WebDriverTestBase {

  protected static $modules = ['ebms_travel'];

  /**
   * Use a very simple theme.
   */
  protected $defaultTheme = 'stark';

  /**
   * Lookup map for vocabulary term IDs.
   */
  private $term_ids = [];

  /**
   * Users with the appropriate permissions.
   */
  private $board_manager = NULL;
  private $board_member = NULL;
  private $site_manager = NULL;

  /**
   * Random pick of preferred hotel.
   */
  private $preferred_hotel_name = '';
  private $preferred_hotel_key = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create some vocabularies.
    $vocabularies = [
      [
        'vid' => 'hotel_payment_methods',
        'values' => [
          ['nci_paid', 'NCI paid for my hotel'],
          ['i_paid', 'I paid for my hotel'],
          ['no_hotel', 'I did not stay in a hotel'],
        ],
      ],
      [
        'vid' => 'hotels',
        'values' => [
          ['gbcourtyard', 'Courtyard Gaithersburg Washingtonian Center'],
          ['gbmarriot', 'Gaithersburg Marriott Washingtonian Center'],
          ['hhsuites', 'Homewood Suites by Hilton Rockville-Gaithersburg'],
          ['gbresidence', 'Residence Inn Gaithersburg Washingtonian Center'],
        ],
      ],
      [
        'vid' => 'meals_and_incidentals',
        'values' => [
          ['per_diem', 'Per diem requested'],
          ['per_diem_declined', 'Per diem declined'],
          ['per_diem_ineligible', 'I am not eligible to receive a per diem'],
        ],
      ],
      [
        'vid' => 'parking_or_toll_expense_types',
        'values' => [
          ['airport', 'Airport Parking'],
          ['hotel', 'Hotel Parking'],
          ['toll', 'Toll'],
        ],
      ],
      [
        'vid' => 'reimbursement_to',
        'values' => [
          ['work', 'Work'],
          ['home', 'Home'],
          ['other', 'Other'],
        ],
      ],
      [
        'vid' => 'transportation_expense_types',
        'values' => [
          ['taxi', 'Taxi'],
          ['metro', 'Metro'],
          ['shuttle', 'Shuttle'],
          ['private', 'Privately Owned Vehicle'],
        ],
      ],
      [
        'vid' => 'meeting_categories',
        'values' => [
          ['', 'Board'],
          ['', 'Subgroup'],
        ],
      ],
      [
        'vid' => 'meeting_statuses',
        'values' => [
          ['', 'Scheduled'],
          ['', 'Canceled'],
        ],
      ],
      [
        'vid' => 'meeting_types',
        'values' => [
          ['', 'In Person'],
          ['', 'Webex/Phone Conf.'],
        ],
      ],
    ];
    foreach ($vocabularies as $vocabulary) {
      $weight = 10;
      $vid = $vocabulary['vid'];
      $this->term_ids[$vid] = [];
      if ($vid === 'hotels') {
        $hotels = $vocabulary['values'];
        $hotel = $hotels[rand(0, count($hotels) - 1)];
        list($this->preferred_hotel_key, $this->preferred_hotel_name) = $hotel;
      }
      foreach ($vocabulary['values'] as list($text_id, $name)) {
        $values = [
          'vid' => $vid,
          'name' => $name,
          'status' => TRUE,
          'weight' => $weight,
        ];
        $key = $name;
        if (!empty($text_id)) {
          $values['field_text_id'] = $key = $text_id;
        }
        $weight += 10;
        $term = Term::create($values);
        $term->save();
        $this->term_ids[$vid][$key] = $term->id();
      }
    }

    // Create a board entity.
    Board::create([
      'id' => 1,
      'name' => 'Test Board',
    ])->save();

    // Create some users with the appropriate permissions.
    $this->board_manager = $this->createUser([
      'enter travel requests',
      'view travel pages',
    ]);
    $this->board_member = $this->createUser([
      'submit travel requests',
      'view travel pages',
    ]);
    $this->site_manager = $this->createUser([
      'administer site configuration',
      'use text format filtered_html',
      'view travel pages',
    ]);

    $this->board_manager->set('boards', [1]);
    $this->board_member->set('boards', [1]);
    $this->board_manager->save();
    $this->board_member->save();

    // Create some meetings.
    $day = 4;
    $month = date('n') - 6;
    $year = date('Y');
    if ($month < 1) {
      $month += 12;
      $year--;
    }
    for ($i = 0; $i < 12; ++$i) {
      $y = $year;
      $m = $month + $i;
      if ($m > 12) {
        $m -= 12;
        $y += 1;
      }
      $date = sprintf('%04d-%02d-%02d', $y, $m, $day);
      $start = "{$date}T13:00:00";
      $end = "{$date}T16:00:00";
      $values = [
        'user' => $this->board_manager->id(),
        'entered' => date('Y-m-d H:i:s'),
        'name' => 'Test Board Meeting ' . ($i + 1),
        'dates' => [
          'value' => $start,
          'end_value' => $end,
        ],
        'boards' => [1],
        'published' => 1,
        'type' => $this->term_ids['meeting_types']['In Person'],
        'category' => $this->term_ids['meeting_categories']['Board'],
        'status' => $this->term_ids['meeting_statuses']['Scheduled'],
      ];
      Meeting::create($values)->save();
    }
  }

  /**
   * Test the travel form pages.
   */
  public function testTravel() {

    // Bring up the landing page for summaries.
    $this->drupalLogin($this->board_member);
    $assert_session = $this->assertSession();
    $url = Url::fromRoute('ebms_travel.landing_page')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/travel-landing-page.png');
    $assert_session->linkExists('Policies & Procedures');
    $assert_session->linkExists('Directions');
    $assert_session->linkExists('Hotel Request');
    $assert_session->linkExists('Reimbursement Request');
    $assert_session->linkExists('Policies & Procedures');

    // Submit a hotel request.
    $year = date('Y');
    $month = date('m') + 2;
    $this->clickLink('Hotel Request');
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session->pageTextContains('please complete this hotel request form');
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('Meeting', 9);
    $form->fillField('check-in', date('m/d/Y', mktime(0, 0, 0, $month, 3, $year)));
    $form->fillField('check-out', date('m/d/Y', mktime(0, 0, 0, $month, 4, $year)));
    $form->fillField('comments', 'Chocolates on the pillow, please!');
    $form->selectFieldOption('hotel', $this->term_ids['hotels'][$this->preferred_hotel_key]);
    $this->createScreenshot('../testdata/screenshots/hotel-request.png');
    $now = date('Y-m-d H:i:s');
    $form->findButton('Save')->click();
    $this->createScreenshot('../testdata/screenshots/hotel-request-submitted.png');
    $assert_session->pageTextContainsOnce('Successfully submitted hotel reservation request.');

    // Examine the HotelRequest entity.
    $request = HotelRequest::load(1);
    $this->assertEquals($this->board_member->id(), $request->user->target_id);
    $this->assertGreaterThanOrEqual($now, $request->submitted->value);
    $this->assertEquals(9, $request->meeting->target_id);
    $this->assertEquals(date('Y-m-d', mktime(0, 0, 0, $month, 3, $year)), $request->check_in->value);
    $this->assertEquals(date('Y-m-d', mktime(0, 0, 0, $month, 4, $year)), $request->check_out->value);
    $this->assertEquals($this->preferred_hotel_name, $request->preferred_hotel->entity->name->value);
    $this->assertEquals('Chocolates on the pillow, please!', $request->comments->value);

    // Submit a reimbursement request.
    $this->getSession()->resizeWindow(800, 1600, 'current');
    $month = date('m') - 1;
    $arrival = date('m/d/Y', mktime(0, 0, 0, $month, 3, $year));
    $departure = date('m/d/Y', mktime(0, 0, 0, $month, 4, $year));
    $this->clickLink('Reimbursement Request');
    $assert_session->pageTextContains('please complete this form to request reimbursement for your travel-related expenses');
    $form = $this->getSession()->getPage();
    $form->selectFieldOption('Meeting', 6);
    $form->fillField('arrival', $arrival);
    $form->fillField('departure', $departure);
    $form->findButton('Add Transportation Expense')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->fillField('transportation-date-1', $arrival);
    $form->selectFieldOption('transportation-type-1', $this->term_ids['transportation_expense_types']['taxi']);
    $assert_session->assertWaitOnAjaxRequest();
    $form->fillField('transportation-amount-1', '25.99');
    $form->findButton('Add Transportation Expense')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->fillField('transportation-date-2', $departure);
    $form->selectFieldOption('transportation-type-2', $this->term_ids['transportation_expense_types']['private']);
    $assert_session->assertWaitOnAjaxRequest();
    $form->fillField('transportation-mileage-2', '500');
    $form->findButton('Add Transportation Expense')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->fillField('transportation-date-3', $arrival);
    $form->selectFieldOption('transportation-type-3', $this->term_ids['transportation_expense_types']['shuttle']);
    $assert_session->assertWaitOnAjaxRequest();
    $form->fillField('transportation-amount-3', '10');
    $form->findButton('Remove Last Transportation Expense')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findButton('Add Parking Or Toll Expense')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->fillField('parking-or-toll-date-1', $arrival);
    $form->selectFieldOption('parking-or-toll-type-1', $this->term_ids['parking_or_toll_expense_types']['toll']);
    $form->fillField('parking-or-toll-amount-1', '.50');
    $form->selectFieldOption('hotel-payment-method', $this->term_ids['hotel_payment_methods']['i_paid']);
    $form->fillField('nights-stayed', 1);
    $form->fillField('hotel-amount', '250');
    $form->selectFieldOption('meals-and-incidentals', $this->term_ids['meals_and_incidentals']['per_diem']);
    $form->selectFieldOption('honorarium', 'requested');
    $form->selectFieldOption('reimbursement-to', $this->term_ids['reimbursement_to']['work']);
    $form->fillField('total-amount', '499.99');
    $form->fillField('comments', 'Small unmarked bills, please.');
    $form->checkField('certification[certified]');
    $form->fillField('email', 'dr_joe@mercy-me-hospital.org');
    $this->createScreenshot('../testdata/screenshots/reimbursement-request.png');
    $form->findButton('Submit')->click();
    $this->createScreenshot('../testdata/screenshots/reimbursement-request-submitted.png');
    $assert_session->pageTextContainsOnce('Successfully submitted reimbursement request.');

    // Examine the ReimbursementRequest entity.
    $request = ReimbursementRequest::load(1);
    $arrival = date('Y-m-d', mktime(0, 0, 0, $month, 3, $year));
    $departure = date('Y-m-d', mktime(0, 0, 0, $month, 4, $year));
    $this->assertEquals($this->board_member->id(), $request->user->target_id);
    $this->assertGreaterThanOrEqual($now, $request->submitted->value);
    $this->assertEquals(6, $request->meeting->target_id);
    $this->assertEquals($arrival, $request->arrival->value);
    $this->assertEquals($departure, $request->departure->value);
    $this->count(2, $request->transportation);
    $this->assertEquals($arrival, $request->transportation[0]->date);
    $this->assertEquals($this->term_ids['transportation_expense_types']['taxi'], $request->transportation[0]->type);
    $this->assertEquals(25.99, $request->transportation[0]->amount);
    $this->assertEmpty($request->transportation[0]->mileage);
    $this->assertEquals($departure, $request->transportation[1]->date);
    $this->assertEquals($this->term_ids['transportation_expense_types']['private'], $request->transportation[1]->type);
    $this->assertEquals(500, $request->transportation[1]->mileage);
    $this->assertEmpty($request->transportation[1]->amount);
    $this->count(1, $request->parking_and_tolls);
    $this->assertEquals($arrival, $request->parking_and_tolls[0]->date);
    $this->assertEquals($this->term_ids['parking_or_toll_expense_types']['toll'], $request->parking_and_tolls[0]->type);
    $this->assertEquals(.50, $request->parking_and_tolls[0]->amount);
    $this->assertEquals('I paid for my hotel', $request->hotel_payment->entity->name->value);
    $this->assertEquals(1, $request->nights_stayed->value);
    $this->assertEquals(250, $request->hotel_amount->value);
    $this->assertEquals('Per diem requested', $request->meals_and_incidentals->entity->name->value);
    $this->assertNotEmpty($request->honorarium_requested->value);
    $this->assertEquals('Work', $request->reimburse_to->entity->name->value);
    $this->assertEquals(499.99, $request->total_amount->value);
    $this->assertEquals('Small unmarked bills, please.', $request->comments->value);
    $this->assertNotEmpty($request->certified->value);

    // Test the travel configuration form.
    // @todo Get ckeditor5 module to fix behavior which ignores the results of
    // the setData() call inside setRichTextValue() below. That works correctly
    // only if the form field does not have a an existing value (that is, a
    // #default_value property), even though the ckeditor documentation (see
    // https://ckeditor.com/docs/ckeditor5/latest/) says that setData() "will
    // replace the editor content with new data" (which is confirmed by the
    // screenshots, which show that the new values are correctly contained in
    // the fields). Reported via Slack (in the ckeditor5 channel) 2023-04-14.
    // Waiting for a resolution. In the meantime the failing tests are disabled
    // below. The fields behave correctly on the live site. Only the tests were
    // failing.
    $this->drupalLogin($this->site_manager);
    $assert_session = $this->assertSession();
    $url = Url::fromRoute('ebms_travel.configuration')->toString();
    $this->drupalGet($url);
    $form = $this->getSession()->getPage();
    $assert_session->pageTextContains('please complete this hotel request form');
    $assert_session->pageTextContains('please complete this form to request reimbursement');
    $this->setRichTextValue('.form-item-hotel-value .ck-editor__editable', 'modified hotel text');
    $this->setRichTextValue('.form-item-reimbursement-value .ck-editor__editable', 'modified reimbursement text');
    $this->createScreenshot('../testdata/screenshots/travel-configuration-form.png');
    $form->findButton('Save')->click();
    $this->createScreenshot('../testdata/screenshots/travel-configuration-form-submitted.png');
    $this->drupalLogin($this->board_member);
    $assert_session = $this->assertSession();
    $this->drupalGet(Url::fromRoute('ebms_travel.hotel_request')->toString());
    $this->createScreenshot('../testdata/screenshots/modified-hotel-request-form.png');
    // $assert_session->pageTextNotContains('please complete this hotel request form');
    // $assert_session->pageTextContains('Fill out the hotel request form and submit it.');
    $this->drupalGet(Url::fromRoute('ebms_travel.reimbursement_request')->toString());
    $this->createScreenshot('../testdata/screenshots/modified-reimbursement-request-form.png');
    // $assert_session->pageTextNotContains('please complete this form to request reimbursement');
    // $assert_session->pageTextContains('Fill out the reimbursement request form and submit it.');
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
