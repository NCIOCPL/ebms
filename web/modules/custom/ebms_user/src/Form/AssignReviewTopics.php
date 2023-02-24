<?php

namespace Drupal\ebms_user\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\user\Entity\User;

/**
 * Switch a native Drupal account to NIH SSO.
 *
 * @ingroup ebms
 */
class AssignReviewTopics extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ebms_assign_review_topics_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, User $user = NULL): array {

    $boards = [];
    foreach ($user->boards as $board) {
      $boards[] = $board->target_id;
    }
    $topics = Topic::topics($boards, TRUE);
    $assigned = [];
    foreach ($user->topics as $topic) {
      $topic_id = $topic->target_id;
      if (array_key_exists($topic_id, $topics)) {
        $assigned[] = $topic_id;
      }
    }
    return [
      '#title' => 'Assign Review Topics for ' . $user->name->value,
      'uid' => [
        '#type' => 'hidden',
        '#value' => $user->id(),
      ],
      'topics' => [
        '#type' => 'checkboxes',
        '#title' => 'Topics',
        '#options' => $topics,
        '#default_value' => $assigned,
        '#description' => 'Topics assigned to this user for review.',
      ],
      'submit' => [
        '#type' => 'submit',
        '#value' => 'Submit',
      ],
      'cancel' => [
        '#type' => 'submit',
        '#value' => 'Cancel',
        '#submit' => ['::cancelSubmit'],
        '#limit_validation_errors' => [],
      ],
    ];
  }

  /**
   * Retreat to the user editing page page.
   */
  public function cancelSubmit(array &$form, FormStateInterface $form_state) {
    $request_id = $this->getRequest()->query->get('request_id');
    $params = [];
    if (!empty($request_id)) {
      $params['request_id'] = $request_id;
    }
    $form_state->setRedirect('ebms_user.manage_topic_assignments', $params);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $form_state->getValue('uid');
    $user = User::load($uid);
    $topics = array_diff($form_state->getValue('topics'), [0]);
    $user->set('topics', $topics);
    $user->save();
    $request_id = $this->getRequest()->query->get('request_id');
    $params = [];
    if (!empty($request_id)) {
      $params['request_id'] = $request_id;
    }
    $form_state->setRedirect('ebms_user.manage_topic_assignments', $params);
  }

}
