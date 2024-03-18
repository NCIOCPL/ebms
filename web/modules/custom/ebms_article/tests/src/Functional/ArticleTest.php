<?php

namespace Drupal\Tests\ebms_article\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\ebms_article\Entity\Article;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\Yaml\Yaml;

/**
 * Test EBMS article functionality.
 *
 * Note that this test triggers a deprecation message. Will be fixed (we hope)
 * by https://www.drupal.org/project/drupal/issues/3281667, which will allow
 * sites based on the drupal/core-recommended template to upgrade to Guzzle 7.
 *
 * @group mysql
 */
class ArticleTest extends BrowserTestBase {

  protected static $modules = [
    'ebms_article',
    'ebms_import',
    'ebms_review',
  ];

  /**
   * Use a very simple theme.
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    // Load the article state values.
    $module = $this->container->get('extension.list.module')->getPath('ebms_article');
    $states = Yaml::parseFile("$module/tests/config/states.yml");
    foreach ($states as $values) {
      $state = Term::create($values);
      $state->save();
    }
  }

  /**
   * Test creation of relationships between articles.
   */
  public function testRelationships() {

    // Create the relationship type terms.
    $names = ['Duplicate', 'Article/Editorial', 'Other'];
    $types = [];
    $id = 1;
    foreach ($names as $name) {
      $values = [
        'vid' => 'relationship_types',
        'field_text_id' => str_replace('/', '_', strtolower($name)),
        'name' => $name,
        'status' => TRUE,
        'description' => "Yada yada $name relationship.",
      ];
      $term = Term::create($values);
      $term->save();
      $types[$name] = $term->id();
    }

    // Create a user and some article entities.
    $account = $this->drupalCreateUser(['manage articles', 'perform full search']);
    for ($i = 0; $i < 3; $i++) {
      $values = [
        'id' => $i + 500001,
        'imported_by' => $account->id(),
        'import_date' => date('Y-m-d H:i:s'),
        'title' => 'Article ' . substr('ABC', $i, 1),
        'source_id' => 10000001 + $i,
      ];
      Article::create($values)->save();
    }

    // Navigate to the full history page for the first article.
    $this->drupalLogin($account);
    $this->drupalGet('articles/500001');
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Article A');

    // Bring up the form to link this article to others.
    $this->clickLink('Related');
    $assert_session->statusCodeEquals(200);

    // Fill in the form and submit it.
    $form = $this->getSession()->getPage();
    $form->fillField('related', '500002, 500003');
    $form->selectFieldOption('type', $types['Article/Editorial']);
    $form->fillField('comments', 'Yadissimo!');
    $form->checkField('edit-options-suppress');
    $form->pressButton('Submit');

    // Confirm that the relationships appear on the first article's page.
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Article A');
    $assert_session->pageTextMatches('#Article/Editorial.+Yadissimo!.+Article/Editorial.+Yadissimo!#');
    $assert_session->linkExists('500002');
    $assert_session->linkExists('500003');
    $assert_session->linkExists('10000002');
    $assert_session->linkExists('10000003');
  }

}
