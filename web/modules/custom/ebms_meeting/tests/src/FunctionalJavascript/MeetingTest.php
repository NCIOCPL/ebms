<?php

namespace Drupal\Tests\ebms_meeting\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_group\Entity\Group;
use Drupal\ebms_meeting\Entity\Meeting;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;

/**
 * Test calendar and meeting pages.
 *
 * @group ebms
 */
class MeetingTest extends WebDriverTestBase {


  protected static $modules = ['ebms_meeting'];

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create some vocabularies.
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
      foreach ($vocabulary['values'] as $name) {
        $values = [
          'vid' => $vid,
          'name' => $name,
          'status' => TRUE,
          'weight' => $weight,
        ];
        $weight += 10;
        Term::create($values)->save();
      }
    }

    // Create a couple of boards and a couple of groups.
    for ($i = 1; $i <= 2; $i++) {
      Board::create(['id' => $i, 'name' => "Board $i"])->save();
      Group::create(['id' => $i, 'name' => "Group $i"])->save();
    }
  }

  /**
   * Test meeting creation and calendar navigation.
   */
  public function testMeetings() {

    // Create accounts with the necessary permissions.
    $member = $this->drupalCreateUser(['view calendar']);
    $member->set('boards', [1]);
    $member->save();
    $permissions = [
      'manage meetings',
      'view calendar',
      'view all meetings',
      'use text format filtered_html',
    ];
    $manager = $this->drupalCreateUser($permissions);
    $manager->set('boards', [1]);
    $manager->save();

    // Create a meeting with some default values, some explicit values.
    $this->drupalLogin($manager);
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $calendar_url = Url::fromRoute('ebms_meeting.calendar')->toString();
    $this->drupalGet($calendar_url);
    $this->createScreenshot('../testdata/screenshots/month-page.png');
    $assert_session->pageTextContains(date('F Y'));
    $this->clickLink('Create Meeting');
    $form = $this->getSession()->getPage();
    $form->fillField('name', 'Board 1 First Meeting This Month');
    $form->findButton('Meeting Schedule')->click();

    // Drupal doesn't allow setting the date widget's format.
    // See https://www.drupal.org/project/drupal/issues/2936268.
    $meeting_date = sprintf('%02d/05/%04d', date('n'), date('Y'));
    $form->fillField('date', $meeting_date);

    // It looks like there's a space between the time and 'AM' on the form,
    // but that's a deceptive trap. If the space is included here the wrong
    // time is recorded. As with the date, ISO format isn't supported.
    $form->fillField('start', '09:30AM');
    $form->fillField('end', '04:00PM');
    $form->findButton('Participants')->click();
    $form->findField('boards[]')->selectOption('1', TRUE);
    $form->findField('groups[]')->selectOption('2', TRUE);
    $form->findButton('Participants')->click();
    $selector = '.form-item-agenda-value .ck-editor__editable';
    $agenda = '<h2>Agenda</h2><p>Yada yada</p>';
    $this->setRichTextValue($selector, $agenda);
    $selector = '.form-item-notes-value .ck-editor__editable';
    $notes = '<h3>Presenters</h3><ul><li>Larry</li><li>Moe</li><li>Curly</li></ul>';
    $this->setRichTextValue($selector, $notes);
    $form->findButton('Meeting Files')->click();
    $file_field = $form->findField('files[files][]');
    $file_field->attachFile('/usr/local/share/testdata/test.docx');
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/create-meeting-form.png');
    $form->findButton('Save')->click();
    $this->createScreenshot('../testdata/screenshots/new-meeting-created.png');
    $assert_session->pageTextContains('New meeting added.');

    // Verify that the Meeting entity's values are what we expect them to be.
    $entity = Meeting::load(1);
    $meeting_date = sprintf('%04d-%02d-05', date('Y'), date('n'));
    $this->assertEquals($manager->id(), $entity->user->target_id);
    $this->assertEquals('Board 1 First Meeting This Month', $entity->name->value);
    $this->assertEquals("{$meeting_date}T09:30", $entity->dates->value);
    $this->assertEquals("{$meeting_date}T16:00", $entity->dates->end_value);
    $this->assertEquals('In Person', $entity->type->entity->name->value);
    $this->assertEquals('Scheduled', $entity->status->entity->name->value);
    $this->assertEquals('Board', $entity->category->entity->name->value);
    $this->assertCount(1, $entity->boards);
    $this->assertEquals('Board 1', $entity->boards[0]->entity->name->value);
    $this->assertCount(1, $entity->groups);
    $this->assertEquals('Group 2', $entity->groups[0]->entity->name->value);
    $this->assertCount(0, $entity->individuals);
    $this->assertEquals($agenda, $entity->agenda->value);
    $this->assertEquals('filtered_html', $entity->agenda->format);
    $this->assertEquals($notes, $entity->notes->value);
    $this->assertEquals('filtered_html', $entity->notes->format);
    $this->assertEquals(1, $entity->published->value);
    $this->assertEquals(0, $entity->agenda_published->value);
    $this->assertCount(1, $entity->documents);
    $this->assertEquals('test.docx', $entity->documents[0]->entity->filename->value);

    // Add a few more meetings.
    $names = [
      'Meeting for the Previous Month',
      'Emergency Meeting',
      'Meeting for the Following Month',
    ];
    foreach ($names as $i => $name) {
      $year = date('Y');
      $month = date('n') - 1 + $i;
      if ($month < 1) {
        $month = 12;
        $year--;
      }
      elseif ($month > 12) {
        $month = 1;
        $year++;
      }
      for ($j = 1; $j <= 2; $j++) {
        $this->clickLink('Calendar');
        $this->clickLink('Create Meeting');
        $form = $this->getSession()->getPage();
        $form->fillField('name', "Board $j $name");
        $form->findButton('Meeting Schedule')->click();
        $meeting_date = sprintf('%02d/%02d/%04d', $month, 12 + $j * 2, $year);
        $form->fillField('date', $meeting_date);
        $form->fillField('start', '01:30PM');
        $form->fillField('end', '05:00PM');
        $form->findButton('Participants')->click();
        $form->findField('boards[]')->selectOption($j, TRUE);
        $form->findButton('Publication')->click();
        $form->checkField('agenda-published');
        $selector = '.form-item-agenda-value .ck-editor__editable';
        $agenda = '<h2>Agenda</h2><p>Yada yada ' . ($i + 1) . '</p>';
        $this->setRichTextValue($selector, $agenda);
        $form->findButton('Save')->click();
      }
    }

    // Test the board filter ("my boards" vs. "all boards").
    $this->drupalGet($calendar_url);
    $this->createScreenshot('../testdata/screenshots/calendar-with-meetings.png');
    $assert_session->linkExists('Show All Boards');
    $assert_session->linkNotExists('Restrict To My Boards');
    $assert_session->pageTextNotContains('Board 2 Emergency Meeting');
    $this->clickLink('Show All Boards');
    $this->createScreenshot('../testdata/screenshots/calendar-all-boards.png');
    $assert_session->pageTextContains('Board 2 Emergency Meeting');
    $assert_session->linkNotExists('Show All Boards');
    $assert_session->linkExists('Restrict To My Boards');

    // Visit the Calendar landing page as a board member.
    $this->drupalLogin($member);
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $this->drupalGet($calendar_url);
    $this->createScreenshot('../testdata/screenshots/board-member-calendar.png');
    $assert_session->pageTextMatches('/5\s+9:30am Eastern Time\s+Board 1 First Meeting This Month/');
    $assert_session->pageTextMatches('/14\s+1:30pm Eastern Time\s+Board 1 Emergency Meeting/');
    $assert_session->pageTextNotContains('Board 2 Emergency Meeting');
    $assert_session->linkNotExists('Show All Boards');

    // Bring up the first meeting display as a board member.
    $this->clickLink('Board 1 First Meeting This Month');
    $this->createScreenshot('../testdata/screenshots/first-meeting.png');
    $assert_session->pageTextContains('Board 1 First Meeting This Month');
    $assert_session->pageTextMatches('/When.*9:30 am - 4:00 pm \(Eastern Time\)\s+How\s+In Person\s+Who\sBoard 1 Board; Group 2\s+Notes\s+Presenters.+Larry.+Moe.+Curly\s+Meeting Documents\s+test\.docx/');
    $assert_session->pageTextNotContains('Agenda');

    // Navigate throught the next couple of meetings.
    $assert_session->linkExists('Next');
    $this->clickLink('Next');
    $this->createScreenshot('../testdata/screenshots/next-meeting.png');
    $assert_session->pageTextContains('Board 1 Emergency Meeting');
    $assert_session->pageTextMatches('/Agenda\s+Yada yada 2/');
    $assert_session->linkExists('Next');
    $this->clickLink('Next');
    $this->createScreenshot('../testdata/screenshots/last-meeting.png');
    $assert_session->pageTextContains('Board 1 Meeting for the Following Month');
    $assert_session->pageTextMatches('/Agenda\s+Yada yada 3/');
    $assert_session->linkNotExists('Next');

    // Check out mavigation by month.
    $this->drupalGet($calendar_url);
    $assert_session->linkExists('Next');
    $assert_session->linkExists('Previous');
    $this->clickLink('Previous');
    $this->createScreenshot('../testdata/screenshots/previous-month.png');
    $assert_session->pageTextContains('Upcoming Meetings');
    $assert_session->pageTextContains('Board 1 Meeting for the Previous Month');
  }

  /**
   * Assign a new value to a rich text field.
   *
   * For some reason, the CKEditor 5 tests are able to call $page->fieldField()
   * for formatted text fields. When we
   *
   */
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
