<?php

namespace Drupal\Tests\ebms_meeting\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Proof of concept.
 *
 * @group ebms
 */
class MonthTest extends BrowserTestBase {
  protected static $modules = ['ebms_meeting'];
  protected $defaultTheme = 'stark';
  public function setUp(): void {
    parent::setUp();
  }
  public function testMonthPage() {
    $account = $this->drupalCreateUser(['view calendar']);
    $this->drupalLogin($account);
    $this->drupalGet('calendar');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains(date('F Y'));
  }
}
