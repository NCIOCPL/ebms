<?php

namespace Drupal\Tests\ebms_core\FunctionalJavascript;

use Drupal\Component\Utility\Random;
use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Test EBMS vocabularies.
 *
 * @group mysql
 */
class VocabularyTest extends WebDriverTestBase {

  const VOCABULARIES = [
    'article_tags',
    'board_decisions',
    'dispositions',
    'doc_tags',
    'hotel_payment_methods',
    'hotels',
    'import_dispositions',
    'import_types',
    'internal_tags',
    'meals_and_incidentals',
    'meeting_categories',
    'meeting_statuses',
    'meeting_types',
    'parking_or_toll_expense_types',
    'reimbursement_to',
    'rejection_reasons',
    'relationship_types',
    'states',
    'topic_groups',
    'transportation_expense_types',
  ];

  const VOCABULARIES_WITH_TEXT_ID = [
    'article_tags',
    'agenda',
    'doc_tags',
    'hotel_payment_methods',
    'hotels',
    'import_dispositions',
    'import_types',
    'meals_and_incidentals',
    'parking_or_toll_expense_types',
    'reimbursement_to',
    'states',
    'transportation_expense_types',
  ];

  const OTHER_EXTRA_FIELDS = [
    'article_tags' => [
      'topic_required' => 'boolean',
      'topic_allowed' => 'boolean',
    ],
    'states' => [
      'sequence' => 'integer',
      'terminal' => 'boolean'
    ],
  ];

  protected static $modules = ['ebms_core'];

  /**
   * Use the administrative theme.
   */
  protected $defaultTheme = 'claro';

  /**
   * Avoid breakage from https://www.drupal.org/project/drupal/issues/3469309.
   */
  protected bool $useOneTimeLoginLinks = FALSE;

  /**
   * Test creation of relationships between articles.
   */
  public function testVocabularies() {

    // Walk through each of the vocabularies creating terms with random values.
    $random = new Random();
    $vocabularies = [];
    foreach (self::VOCABULARIES as $vid) {

      // Get the characteristics of this vocabulary.
      $has_text_id = in_array($vid, self::VOCABULARIES_WITH_TEXT_ID);
      $extra_fields = self::OTHER_EXTRA_FIELDS[$vid] ?? [];

      // Log in as a user who can create terms for this vocabulary.
      $permissions = [
        "create terms in $vid",
        "edit terms in $vid",
      ];
      $account = $this->drupalCreateUser($permissions);
      $url = Url::fromRoute('entity.taxonomy_term.add_form', ['taxonomy_vocabulary' => $vid]);
      $this->drupalLogin($account);

      // Create a handful of terms for the vocabulary.
      $vocabularies[$vid] = [];
      $unique_names = [];
      for ($i = 0; $i < 5; $i++) {
        $term_name = $random->sentences(rand(1, 5), TRUE);
        while (in_array(strtoupper($term_name), $unique_names)) {
          $term_name = $random->sentences(rand(1, 5), TRUE);
        }
        $unique_names[] = strtoupper($term_name);
        $this->drupalGet($url);
        //$this->assertSession()->statusCodeEquals(200);
        $form = $this->getSession()->getPage();
        $values = [
          'name' => $term_name,
          'description' => $random->sentences(10),
          'status' => rand(0, 1),
        ];
        $form->fillField('Name', $values['name']);
        $selector = '.form-item--description-0-value .ck-editor__editable';
        $this->setRichTextValue($selector, $values['description']);
        // $form->fillField('Description', $values['description']);
        if (!empty($values['status'])) {
          $form->checkField("Published");
        }
        else {
          $form->uncheckField('Published');
        }

        // Add extra fields where appropriate.
        if ($has_text_id) {
          $values['field_text_id'] = $random->name(rand(10, 20));
          $form->fillField('Text ID', $values['field_text_id']);
        }
        foreach ($extra_fields as $field_name => $field_type) {
          $this->assertSession()->assert(in_array($field_type, ['boolean', 'integer']), "Unexpected field type $field_type");
          if ($field_type === 'boolean') {
            $value = !empty(rand(0, 1));
            $values["field_$field_name"] = $value;
            if ($value) {
              $form->checkField(ucwords(str_replace('_', ' ', $field_name)));
            }
            else {
              $form->uncheckField(ucwords(str_replace('_', ' ', $field_name)));
            }
          }
          elseif ($field_type === 'integer') {
            $value = rand(10, 100);
            $values["field_$field_name"] = $value;
            $form->fillField(ucwords(str_replace('_', ' ', $field_name)), $value);
          }
          else {
            // Shouldn't happen, if the assert above is kept up to date.
            throw new \Exception("unexpected field type $field_type");
          }
        }

        // Save and remember the values so we can check them later.
        $vocabularies[$vid][$term_name] = $values;
        $form->pressButton('Save');
        // $this->assertSession()->statusCodeEquals(200);
      }
    }

    // Make sure the terms landed safely.
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    foreach ($vocabularies as $vid => $terms) {
      $extra_fields = self::OTHER_EXTRA_FIELDS[$vid] ?? [];
      foreach ($terms as $name => $values) {
        $query = $storage->getQuery()->accessCheck(FALSE);
        $query->condition('vid', $vid);
        $query->condition('name', $name);
        $ids = $query->execute();
        $count = count($ids);
        $this_term = "[{$vid}] $name";
        $this->assertSession()->assert($count === 1, "Found {$count} matching terms, but expected exactly 1 term for $this_term.");
        $term = $storage->load(reset($ids));
        $this->assertSession()->assert($term->name->value === $values['name'], 'Mismatched name.');
        // $this->assertSession()->assert($term->description->value === $values['description'], 'Mismatched description.');
        $this->assertEquals('<p>' . $values['description'] . '</p>', $term->description->value);
        $this->assertSession()->assert($term->status->value == $values['status'], 'Mismatched status.');

        // Check the extra values if present.
        if (array_key_exists('field_text_id', $values)) {
          $this->assertSession()->assert($term->field_text_id->value === $values['field_text_id'], "Mismatched field ID for $this_term");
        }
        foreach ($extra_fields as $field_name => $field_type) {
          $key = "field_$field_name";
          $message = "$field_type value mismatch for $key in $this_term";
          $this->assertSession()->assert($term->get($key)->value == $values[$key], $message);
        }
      }
    }
  }

  /**
   * Assign a new value to a rich text field.
   *
   * For some reason, the CKEditor 5 tests are unable to call $page->findField()
   * for formatted text fields.
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
