<?php

namespace Drupal\ebms_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for posting a fresh hierarchy for publication types.
 *
 * Knowing the publication type(s) NLM has assigned to a PubMed article
 * helps the reviewers streamline the process without having to bring up
 * the PubMed web page for the article.
 *
 * @ingroup ebms
 */
class PubtypeAncestors extends FormBase
{

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): PubtypeAncestors {
    // Instantiates this form class.
    $instance = parent::create($container);
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ebms_post_pubtype_ancestors';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {

    return [
      '#title' => 'MeSH Publication Type Hierarchy',
      'file' => [
        '#title' => 'File',
        '#type' => 'managed_file',
        '#required' => TRUE,
        '#validated' => TRUE,
        '#upload_validators' => ['file_validate_extensions' => ['json']],
        '#description' => 'Post a fresh set of JSON-encoded MeSH publication type terms.',
      ],
      'submit' => [
        '#type' => 'submit',
        '#value' => 'Submit',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $file_ids = $form_state->getValue('file')['fids'];
    if (empty($file_ids)) {
      $form_state->setErrorByName('file', 'No file selected.');
    }
    else {
      $file = File::load($file_ids[0]);
      if (empty($file)) {
        $form_state->setErrorByName('file', 'File upload failed.');
      }
      else {
        $payload = file_get_contents($file->getFileUri());
        if (empty($payload)) {
          $form_state->setErrorByName('file', 'No JSON found in file.');
        }
        else {
          $form_state->setValue('payload', $payload);
          ebms_debug_log('MeSH publication type hierarchy payload size is ' . strlen($payload) . ' bytes');
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $value = $form_state->getValue('payload');
    $upsert = $this->database->upsert('on_demand_config')
      ->fields(['name', 'value'])
      ->key('name');
    $upsert->values([
      'name' => 'article-type-ancestors',
      'value' => $value,
    ]);
    $rc = $upsert->execute();
    if (empty($rc)) {
      $this->messenger()->addError('Unable to store the term hierarchy values.');
    }
    else {
      $this->messenger()->addMessage('Term hierarchy information successfully stored');
    }
    $form_state->setRedirect('ebms_core.admin_config_ebms');
  }

}
