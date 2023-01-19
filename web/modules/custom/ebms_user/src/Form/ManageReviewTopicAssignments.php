<?php

namespace Drupal\ebms_user\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_core\Entity\SavedRequest;
use Drupal\user\Entity\User;

/**
 * Pick a user for whom to make topic assignments.
 *
 * @ingroup ebms
 */
class ManageReviewTopicAssignments extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'manage_review_topics_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $request_id = 0): array {

    // Get the board and rôle value lists.
    $boards = Board::boards();
    $roles = [];
    foreach (_ebms_core_roles() as $role) {
      $roles[$role['id']] = $role['label'];
    }

    // If we have a job request, fetch its parameters.
    $parameters = empty($request_id) ? [] : SavedRequest::loadParameters($request_id);
    $name = $parameters['name'] ?? '';
    $board = array_diff($parameters['board'] ?? [], [0]);
    $role = array_diff($parameters['role'] ?? ['board_member'], [0]);

    // Find the users to display in the table below the form.
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->sort('name');
    if (!empty($board)) {
      $query->condition('boards', $board, 'IN');
    }
    if (!empty($name)) {
      $query->condition('name', "%{$name}%", 'LIKE');
    }
    if (!empty($role)) {
      $query->condition('roles', $role, 'IN');
    }
    $query->condition('status', 1);
    ebms_debug_log('manage topic assignments query: ' . (string) $query);
    ebms_debug_log("user: $user");
    $users = [];
    $opts = [];
    if (!empty($request_id)) {
      $opts['query'] = ['request_id' => $request_id];
    }
    foreach ($storage->loadMultiple($query->execute()) as $user) {
      $topics = [];
      foreach ($user->topics as $topic) {
        $topics[] = $topic->entity->name->value;
      }
      if (empty($topics)) {
        $topics = ['None'];
      }
      $parms = ['user' => $user->id()];
      $users[] = [
        'name' => $user->name->value,
        'topics' => $topics,
        'url' => Url::fromRoute('ebms_user.assign_review_topics', $parms, $opts),
      ];
    }

    return [
      '#title' => 'Manage Review Topic Assignments',
      'name' => [
        '#type' => 'textfield',
        '#title' => 'User Name',
        '#maxlength' => 128,
        '#description' => "Enter a portion of the user's name.",
        '#default_value' => $name,
      ],
      'role' => [
        '#type' => 'checkboxes',
        '#title' => 'Rôle(s)',
        '#options' => $roles,
        '#default_value' => $role,
        '#description' => 'Narrow the list of users to those with specific rôles.',
      ],
      'board' => [
        '#type' => 'checkboxes',
        '#title' => 'Board(s)',
        '#options' => $boards,
        '#default_value' => $board,
        '#description' => 'Only list users on specific boards.',
      ],
      'submit' => [
        '#type' => 'submit',
        '#value' => 'Filter',
      ],
      'users' => [
        '#theme' => 'user_topic_assignments',
        '#users' => $users,
      ],
    ];
  }

  /**
   * Retreat to the user editing page page.
   */
  public function cancelSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('ebms_user.manage_topic_assignments');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $request = SavedRequest::saveParameters('manage topic assignments', $form_state->getValues());
    $form_state->setRedirect('ebms_user.manage_topic_assignments', ['request_id' => $request->id()]);
  }

}
