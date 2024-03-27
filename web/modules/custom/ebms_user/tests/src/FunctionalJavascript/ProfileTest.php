<?php

namespace Drupal\Tests\ebms_user\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_group\Entity\Group;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Test the user profile page.
 *
 * Note that this test triggers a deprecation message. Will be fixed (we hope)
 * by https://www.drupal.org/project/drupal/issues/3281667, which will allow
 * sites based on the drupal/core-recommended template to upgrade to Guzzle 7.
 *
 * @group mysql
 */
class ProfileTest extends WebDriverTestBase {

  protected static $modules = ['ebms_user'];

  /**
   * Use a very simple theme.
   */
  protected $defaultTheme = 'stark';

  /**
   * Test the profile page.
   */
  public function testProfile() {

    // Create some supporting entities.
    Board::create(['id' => 1, 'name' => 'Cancer Genetics'])->save();
    Group::create(['id' => 1, 'boards' => [1], 'name' => 'Breast-Gyn WG'])->save();
    Group::create(['id' => 2, 'boards' => [1], 'name' => 'Genetics of Breast and Ovarian Cancer'])->save();
    Topic::create(['id' => 1, 'board' => 1, 'name' => 'Cancer Genetics Overview'])->save();
    Topic::create(['id' => 2, 'board' => 1, 'name' => 'Genetics of Breast and Ovarian Cancer'])->save();

    // Create the user account and log in.
    $user = $this->drupalCreateUser([], 'Test Board Member', FALSE, [
      'boards' => [1],
      'groups' => [1, 2],
      'topics' => [1, 2],
      'roles' => ['board_member'],
    ]);
    $today = date('Y-m-d');
    $this->drupalLogin($user);

    // Load the profile page and verify its content.
    $url = Url::fromRoute('ebms_user.profile', ['user' => $user->id()]);
    $this->drupalGet($url->toString());
    $assert_session = $this->assertSession();
    // Use for debugging.
    // $html = $this->getSession()->getPage()->getHtml();
    // file_put_contents('../testdata/screenshots/user-profile.html', $html);
    $assert_session->pageTextContains('Test Board Member');
    $assert_session->pageTextMatches('/Account Status\s+Active/');
    $assert_session->pageTextMatches("/EBMS User Since\s+{$today}/");
    $assert_session->pageTextMatches("/Most Recent Login\s+{$today}/");
    $assert_session->pageTextMatches("/Last Access\s+{$today}/");
    $assert_session->pageTextMatches('/Roles.+Board Member/');
    $assert_session->pageTextMatches('/Boards.+Cancer Genetics/');
    $assert_session->pageTextMatches('/Groups.+Breast-Gyn WG.+Genetics of Breast and Ovarian Cancer/');
    $assert_session->pageTextMatches('/Default Reviewer For Topics.+Cancer Genetics Overview.+Genetics of Breast and Ovarian Cancer/');
  }

}
