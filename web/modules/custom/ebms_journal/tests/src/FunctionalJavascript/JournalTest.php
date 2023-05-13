<?php

namespace Drupal\Tests\ebms_journal\FunctionalJavascript;

use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_journal\Entity\Journal;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Test the journal maintenance page.
 *
 * We can't test the journal refresh command, because NLM refuses
 * most connections from test clients. :-(
 *
 * @group ebms
 */
class JournalTest extends WebDriverTestBase {

  protected static $modules = ['ebms_journal', 'ebms_core'];

  protected $defaultTheme = 'stark';


  public function testJournals() {

    // Create some entities we'll need.
    $librarian = $this->createUser([], NULL, FALSE, ['roles' => ['medical_librarian']]);
    Board::create(['name' => 'Test Board'])->save();
    $now = date('Y-m-d H:i:s');
    for ($i = 1; $i <= 10; ++$i) {
      Journal::create([
        'source' => 'Pubmed',
        'source_id' => sprintf("%07d", 10000 + $i),
        'title' => sprintf('Test Journal %02d', $i),
        'brief_title' => sprintf('Jnl %02d', $i),
        'not_lists' => $i % 2 ? [] : [['board' => 1, 'start' => $now, 'user' => $librarian->id()]],
      ])->save();
    }

    // Log in as a medical librarian.
    $this->drupalLogin($librarian);

    // Navigate to the journal management page.
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $this->drupalGet('journals');
    $this->createScreenshot('../testdata/screenshots/journals.png');

    // Show the rejected journals
    $this->getSession()->getPage()->findButton('Filter')->click();
    $assert_session->pageTextContains('Journal Maintenance');
    $assert_session->pageTextMatches('/Queued Changes\s+No changes have been queued./');
    $assert_session->pageTextContains('Journals (5)');
    $assert_session->pageTextMatches('/0010002.+0010004.+0010006.+0010008.+0010010/');
    $this->createScreenshot('../testdata/screenshots/journals-filtered.png');

    // Show all the journals.
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->findById('edit-inclusion-exclusion-all')->click();
    $this->createScreenshot('../testdata/screenshots/journals-filtering-all.png');
    $this->getSession()->getPage()->findButton('Filter')->click();
    $this->createScreenshot('../testdata/screenshots/journals-filtered-all.png');
    $assert_session->pageTextContains('Journals (10)');

    // queue up some changes to the included/rejected settings.
    $form = $this->getSession()->getPage();
    $form->findById('journal-2-excluded')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findById('journal-4-excluded')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $form->findById('journal-3-included')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $this->createScreenshot('../testdata/screenshots/journals-queued-changes.png');
    $assert_session->pageTextContains('Queued Changes');
    $assert_session->pageTextContains('Journal Jnl 02 (0010002) will be included.');
    $assert_session->pageTextContains('Journal Jnl 03 (0010003) will be excluded.');
    $assert_session->pageTextContains('Journal Jnl 04 (0010004) will be included.');

    // Apply the queued changes.
    $form->findButton('Apply Queued Changes')->click();
    $this->createScreenshot('../testdata/screenshots/journals-queued-changes-applied.png');
    $assert_session->pageTextContains('3 queued changes successfully saved.');

    // Check the included journals.
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->findById('edit-inclusion-exclusion-included')->click();
    $this->createScreenshot('../testdata/screenshots/journals-filtering-included.png');
    $this->getSession()->getPage()->findButton('Filter')->click();
    $this->createScreenshot('../testdata/screenshots/journals-filtered-included.png');
    $assert_session->pageTextContains('Journals (6)');
    $assert_session->pageTextMatches('/0010001.+0010002.+0010004.+0010005.+0010007.+0010009/');

    // Check the excluded journals.
    $form = $this->getSession()->getPage();
    $form->findButton('Filters')->click();
    $form->findById('edit-inclusion-exclusion-excluded')->click();
    $this->createScreenshot('../testdata/screenshots/journals-filtering-excluded.png');
    $this->getSession()->getPage()->findButton('Filter')->click();
    $this->createScreenshot('../testdata/screenshots/journals-filtered-excluded.png');
    $assert_session->pageTextContains('Journals (4)');
    $assert_session->pageTextMatches('/0010003.+0010006.+0010008.+0010010/');
  }

}

