<?php

namespace Drupal\Tests\ebms_topic\FunctionalJavascript;

use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;

/**
 * Test the pages for managing EBMS topics.
 *
 * @group ebms
 */
class TopicTest extends WebDriverTestBase {

  protected static $modules = ['ebms_topic', 'ebms_core']; //, 'ebms_menu'];

  protected $defaultTheme = 'stark';
  protected $profile = 'minimal';


  public function testTopics() {

    // Create some entities we'll need.
    Board::create(['name' => 'Test Board'])->save();
    Term::create(['vid' => 'topic_groups', 'name' => 'Test Topic Group'])->save();

    // Log in as a user with the necessary permissions.
    $this->drupalLogin($this->createUser(['manage topics'], 'Sally Site Admin', FALSE, ['roles' => ['board_manager', 'site_manager']]));

    // Navigate to the EBMS Topics page.
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $this->drupalGet('admin/config/ebms');
    $this->createScreenshot('../testdata/screenshots/topics-ebms-config.png');
    $this->getSession()->getPage()->findLink('Topics')->click();
    $this->createScreenshot('../testdata/screenshots/topics-empty.png');
    $assert_session->pageTextContains('Topic entities');
    $assert_session->pageTextMatches('/Board\s+Name\s+Operations/');
    $assert_session->pageTextContains('There are no topic entities yet');

    // Add a test topic.
    $this->getSession()->getPage()->findLink('Add EBMS topic')->click();
    $this->createScreenshot('../testdata/screenshots/topics-add-form.png');
    $form = $this->getSession()->getPage();
    $form->fillField('Name', 'First Test Topic');
    $field = $assert_session->waitForElement('css', '[name="board[0][target_id]"].ui-autocomplete-input');
    $field->setValue('Test');
    $assert_session->waitForElement('css', '#ui-id-1.ui-autocomplete .ui-menu-item');
    $suggestion = $form->find('css', '#ui-id-1.ui-autocomplete .ui-menu-item');
    $suggestion->click();
    $field = $assert_session->waitForElement('css', '[name="nci_reviewer[0][target_id]"].ui-autocomplete-input');
    $field->setValue('Sally');
    $assert_session->waitForElement('css', '#ui-id-2.ui-autocomplete .ui-menu-item');
    $suggestion = $form->find('css', '#ui-id-2.ui-autocomplete .ui-menu-item');
    $suggestion->click();
    $field = $assert_session->waitForElement('css', '[name="topic_group[0][target_id]"].ui-autocomplete-input');
    $field->setValue('Test');
    $assert_session->waitForElement('css', '#ui-id-3.ui-autocomplete .ui-menu-item');
    $suggestion = $form->find('css', '#ui-id-3.ui-autocomplete .ui-menu-item');
    $suggestion->click();
    $this->createScreenshot('../testdata/screenshots/topics-add-form-first-filled.png');
    $form->findButton('Save')->click();
    $this->createScreenshot('../testdata/screenshots/topics-add-form-first-saved.png');

    // Confirm that the topic display and values are as expected.
    $topic = Topic::load(1);
    $this->assertEquals('First Test Topic', $topic->name->value);
    $this->assertEquals('Test Board', $topic->board->entity->name->value);
    $this->assertEquals('Sally Site Admin', $topic->nci_reviewer->entity->name->value);
    $this->assertEquals('Test Topic Group', $topic->topic_group->entity->name->value);
    $assert_session->pageTextContains('Created the First Test Topic PDQ topic');
    $assert_session->pageTextMatches('/Name\s+First Test Topic/');
    $assert_session->pageTextMatches('/PDQ® Editorial Board\s+Test Board\s+NCI Reviewer\s+Sally Site Admin\s+Topic Group\s+Test Topic Group\s+Active\s+On/');

    // Add a second topic.
    $this->drupalGet('admin/config/ebms/topic/add');
    $form = $this->getSession()->getPage();
    $form->fillField('Name', 'Another Test Topic');
    $field = $assert_session->waitForElement('css', '[name="board[0][target_id]"].ui-autocomplete-input');
    $field->setValue('Test');
    $assert_session->waitForElement('css', '#ui-id-1.ui-autocomplete .ui-menu-item');
    $suggestion = $form->find('css', '#ui-id-1.ui-autocomplete .ui-menu-item');
    $suggestion->click();
    $field = $assert_session->waitForElement('css', '[name="nci_reviewer[0][target_id]"].ui-autocomplete-input');
    $field->setValue('Sally');
    $assert_session->waitForElement('css', '#ui-id-2.ui-autocomplete .ui-menu-item');
    $suggestion = $form->find('css', '#ui-id-2.ui-autocomplete .ui-menu-item');
    $suggestion->click();
    $this->createScreenshot('../testdata/screenshots/topics-add-form-second-filled.png');
    $form->findButton('Save')->click();

    // Check the topic's values.
    $this->createScreenshot('../testdata/screenshots/topics-add-form-second-saved.png');
    $topic = Topic::load(2);
    $this->assertEquals('Another Test Topic', $topic->name->value);
    $this->assertNull($topic->topic_group->target_id);

    // Verify that the topic list shows both topics.
    $this->drupalGet('admin/config/ebms/topic');
    $this->createScreenshot('../testdata/screenshots/topics-list.png');
    $assert_session->pageTextMatches('/Another Test Topic.+First Test Topic/');
  }

}

