<?php

namespace Drupal\Tests\ebms_meeting\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Proof of concept.
 *
 * @group ebms
 */
class MonthTest extends BrowserTestBase {
  protected static $modules = [
    'datetime',
    'datetime_range',
    'ebms_board',
    'ebms_core',
    'ebms_group',
    'ebms_meeting',
    'ebms_topic',
    'ebms_user',
    'file',
    'options',
    'role_delegation',
    'taxonomy',
    'user',
  ];
  protected $defaultTheme = 'stark';
  public function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
  }
  public function testMonthPage() {
    $account = $this->drupalCreateUser(['view calendar']);
    $this->drupalLogin($account);
    $this->drupalGet('calendar');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains(date('F Y'));
  }
}
