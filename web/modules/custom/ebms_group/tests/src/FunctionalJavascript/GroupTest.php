<?php

namespace Drupal\Tests\ebms_group\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_group\Entity\Group;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**ß
 * Test the group management page.
 *
 * @group ebms
 */
class GroupTest extends WebDriverTestBase {

  protected static $modules = ['ebms_group'];
  protected $defaultTheme = 'stark';

  /**
   * Test the group management page.
   */
  public function testGroupPage() {

    // Create some board entities.
    Board::create(['id' => 1, 'name' => 'First Test Board'])->save();
    Board::create(['id' => 2, 'name' => 'Second Test Board'])->save();
    Board::create(['id' => 3, 'name' => 'Third Test Board'])->save();

    // Log in as an administrator.
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration'], 'testadmin', TRUE));
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();

    // Confirm that we're starting out without any groups.
    $this->drupalGet(Url::fromRoute('entity.ebms_group.collection')->toString());
    $this->createScreenshot('../testdata/screenshots/no-groups.png');
    $assert_session->pageTextContains('Group entities');
    $assert_session->pageTextMatches('/Group ID\s+Name\s+Operations/');
    $assert_session->pageTextContains('There are no group entities yet.');

    // Add a group.
    $this->drupalGet(Url::fromRoute('entity.ebms_group.add_form')->toString());
    $form = $this->getSession()->getPage();
    $form->fillField('name[0][value]', 'My First Test Group');
    $field = $assert_session->waitForElement('css', '[name="boards[0][target_id]"].ui-autocomplete-input');
    $field->setValue('Second');
    $assert_session->waitForElement('css', '.ui-autocomplete .ui-menu-item');
    $suggestion = $form->find('css', '.ui-autocomplete .ui-menu-item');
    $suggestion->click();
    $this->createScreenshot('../testdata/screenshots/add-group-form.png');
    $form->findButton('Save')->click();
    $this->createScreenshot('../testdata/screenshots/group-added.png');

    // Confirm the evidence that the new group was added successfully.
    $assert_session->pageTextContains('Created the My First Test Group group.');
    $assert_session->pageTextMatches('/Name\sMy First Test Group\sPDQ® Editorial Boards\sSecond Test Board/');
    $this->drupalGet(Url::fromRoute('entity.ebms_group.collection')->toString());
    $this->createScreenshot('../testdata/screenshots/one-group.png');
    $assert_session->pageTextContains('Group entities');
    $assert_session->pageTextMatches('/Group ID\s+Name\s+Operations\s+1\s+My First Test Group/');
    $assert_session->pageTextNotContains('There are no group entities yet.');
    $group = Group::load(1);
    $this->assertEquals('My First Test Group', $group->name->value);
    $this->assertEquals(1, $group->boards->count());
    $this->assertEquals('Second Test Board', $group->boards[0]->entity->name->value);

    // Add a second group.
    $this->drupalGet(Url::fromRoute('entity.ebms_group.add_form')->toString());
    $form = $this->getSession()->getPage();
    $form->fillField('name[0][value]', 'Another Test Group');
    $field = $assert_session->waitForElement('css', '[name="boards[0][target_id]"].ui-autocomplete-input');
    $field->setValue('First');
    $assert_session->waitForElement('css', '.ui-autocomplete .ui-menu-item');
    $suggestion = $form->find('css', '.ui-autocomplete .ui-menu-item');
    $suggestion->click();
    $form->findButton('Add another item')->click();
    $field = $assert_session->waitForElement('css', '[name="boards[1][target_id]"].ui-autocomplete-input');
    $field->setValue('Third');
    $assert_session->waitForElement('css', '.ui-autocomplete .ui-menu-item');
    $suggestion = $form->find('css', '.ui-autocomplete .ui-menu-item');
    $suggestion->click();
    $this->createScreenshot('../testdata/screenshots/add-second-group.png');
    $form->findButton('Save')->click();

    // Verify the presence and values for the second group.
    $this->createScreenshot('../testdata/screenshots/second-group-added.png');
    $assert_session->pageTextContains('Created the Another Test Group group.');
    $assert_session->pageTextMatches('/Name\sAnother Test Group\sPDQ® Editorial Boards\sFirst Test Board\sThird Test Board/');
    $this->drupalGet(Url::fromRoute('entity.ebms_group.collection')->toString());
    $this->createScreenshot('../testdata/screenshots/two-groups.png');
    $assert_session->pageTextContains('Group entities');
    $assert_session->pageTextMatches('/Group ID\s+Name\s+Operations\s+1\s+My First Test Group\s+Edit.+2\s+Another Test Group\s+Edit/');
    $assert_session->pageTextNotContains('There are no group entities yet.');
    $group = Group::load(2);
    $this->assertEquals('Another Test Group', $group->name->value);
    $this->assertEquals(2, $group->boards->count());
    $this->assertEquals('First Test Board', $group->boards[0]->entity->name->value);
    $this->assertEquals('Third Test Board', $group->boards[1]->entity->name->value);
  }

}
