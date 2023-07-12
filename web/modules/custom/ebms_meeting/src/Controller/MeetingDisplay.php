<?php

namespace Drupal\ebms_meeting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ebms_meeting\Entity\Meeting;
use Drupal\file\Entity\File;
use Drupal\user\Entity\User;

/**
 * Display the information for a PDQ meeting.
 */
class MeetingDisplay extends ControllerBase {

  /**
   * Assemble the render array for a PDQ meeting.
   *
   * @param Meeting $meeting
   *   The meeting whose information is shown.
   *
   * @return array
   *   The render array used for the response.
   */
  public function show($meeting): array {

    // Construct the display string for the meeting's schedule.
    $start = $meeting->dates->value;
    $end = $meeting->dates->end_value;
    $start = new \DateTime($start);
    $end = new \DateTime($end);
    $start_am_pm = $start->format('a');
    $end_am_pm = $end->format('a');
    $when = $start->format('l F j, Y, g:i');
    if ($start_am_pm !== $end_am_pm) {
      $when .= " $start_am_pm";
    }
    $when .= $end->format(' - g:i a');

    // Show which boards and groups are invited.
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $participants = [];
    foreach ($meeting->boards as $board) {
      $participants[] = ['name' => $board->entity->name->value . ' Board'];
    }
    foreach ($meeting->groups as $group) {
      $query = $storage->getQuery()->accessCheck(FALSE);
      $query->condition('groups', $group->target_id);
      $query->sort('name');
      $members = [];
      $users = $storage->loadMultiple($query->execute());
      foreach ($users as $user) {
        $members[] = $user->name->value;
      }
      $participants[] = [
        'name' => $group->entity->name->value,
        'members' => $members,
      ];
    }

    // Create some buttons for navigation and meeting creation.
    $user = User::load($this->currentUser()->id());
    $options = ['query' => \Drupal::request()->query->all()];
    $buttons = [
      [
        'url' => Url::fromRoute('ebms_meeting.calendar', ['month' => $start->format('Y-m')], $options),
        'label' => 'Calendar',
      ],
    ];

    // Handle the extremely rare edge case: multiple meetings at exactly
    // the same date and time, all visible to the current user.
    $storage = $this->entityTypeManager()->getStorage('ebms_meeting');
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('dates', $meeting->dates->value)
      ->condition('id', $meeting->id(), '<')
      ->sort('id', 'DESC')
      ->range(0, 1);
    Meeting::applyMeetingFilters($query, $user);
    $ids = $query->execute();
    if (empty($ids)) {

      // The normal case: previous meeting is actually earier than this one.
      $query = $storage->getQuery()->accessCheck(FALSE)
        ->condition('dates', $meeting->dates->value, '<')
        ->sort('dates.value', 'DESC')
        ->sort('id', 'DESC')
        ->range(0, 1);
      Meeting::applyMeetingFilters($query, $user);
      $ids = $query->execute();
    }
    if (!empty($ids)) {
      $buttons[] = [
        'url' => Url::fromRoute('ebms_meeting.meeting', ['meeting' => reset($ids)], $options),
        'label' => 'Previous',
      ];
    }

    // Same approach for the Next button.
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('dates', $meeting->dates->value)
      ->condition('id', $meeting->id(), '>')
      ->sort('id')
      ->range(0, 1);
    Meeting::applyMeetingFilters($query, $user);
    $ids = $query->execute();
    if (empty($ids)) {
      $query = $storage->getQuery()->accessCheck(FALSE)
        ->condition('dates', $meeting->dates->value, '>')
        ->sort('dates.value')
        ->sort('id')
        ->range(0, 1);
      Meeting::applyMeetingFilters($query, $user);
      $ids = $query->execute();
    }
    if (!empty($ids)) {
      $buttons[] = [
        'url' => Url::fromRoute('ebms_meeting.meeting', ['meeting' => reset($ids)], $options),
        'label' => 'Next',
      ];
    }
    if ($this->currentUser()->hasPermission('manage meetings')) {
      $buttons[] = [
        'url' => Url::fromRoute('ebms_meeting.edit_meeting', ['meeting' => $meeting->id()], $options),
        'label' => 'Edit',
      ];
    }

    // Find the documents attached to the meeting.
    $docs = [];
    foreach ($meeting->documents as $document) {
      $file = File::load($document->target_id);
      $docs[] = [
        'url' => $file->createFileUrl(),
        'name' => $file->getFilename(),
      ];
    }
    $archive = NULL;
    if ($meeting->getFiles(TRUE)) {
      $archive = Url::fromRoute('ebms_meeting.archive', ['meeting' => $meeting->id()], $options)->toString();
    }

    // Determine if the user should see the meeting's agenda.
    $agenda_visible = FALSE;
    if ($meeting->agenda_published->value || $this->currentUser()->hasPermission('view all meetings')) {
      $agenda_visible = TRUE;
    }
    $agenda = $agenda_visible ? $meeting->agenda->value : '';

    // Assemble and return the render array for the page.
    return [
      '#title' => $meeting->name->value,
      '#attached' => [
        'library' => ['ebms_meeting/meeting-display'],
      ],
      'top-buttons' => [
        '#theme' => 'ebms_buttons',
        '#buttons' => $buttons,
      ],
      'meeting' => [
        '#theme' => 'meeting',
        '#meeting' => [
          'scheduled' => $when,
          'type' => $meeting->type->entity->name->value,
          'participants' => $participants,
          'agenda' => $agenda,
          'notes' => $meeting->notes->value,
          'user' => $meeting->user->entity->name->value,
          'submitted' => substr($meeting->entered->value, 0, 10),
          'docs' => $docs,
          'ical' => Url::fromRoute('ebms_meeting.ical_event', ['meeting' => $meeting->id()], $options),
          'archive' => $archive,
        ],
      ],
    ];
  }

}
