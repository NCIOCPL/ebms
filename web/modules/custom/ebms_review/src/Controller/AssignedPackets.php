<?php

namespace Drupal\ebms_review\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Url;
use Drupal\ebms_review\Entity\Packet;
use Drupal\user\Entity\User;

/**
 * Provide a list of packets assigned for review to the current user.
 */
class AssignedPackets extends ControllerBase {

  /**
   * Create the render array for the packets list.
   */
  public function display() {

    // Start with some defaults.
    $title = 'Assigned Packets';
    $options = ['query' => \Drupal::request()->query->all()];
    $uid = $this->currentUser()->id();

    // Override defaults if working on behalf of a board member.
    $obo = $options['query']['obo'] ?? '';
    if (!empty($obo)) {
      $uid = $obo;
      $user = User::load($uid);
      $name = $user->name->value;
      $title .= " for $name";
    }

    // Find the review packets for the board member. This is a case in which
    // performance was not a problem, but complexity of the query was. Doing
    // this with the entity query API was so difficult to comprehend from the
    // code that bugs were inevitable (see, for example, OCEEBMS-761). Doing
    // this with the Drupal query API is much more straigntforward. And it
    // works correctly.
    $query = Packet::makeUnreviewedPacketsQuery($uid);
    $query = $query->extend(PagerSelectExtender::class);
    $result = $query->execute();
    $ids = [];
    foreach ($result as $row) {
      $ids[$row->packet_id] = $row->packet_id;
    }
    $storage = $this->entityTypeManager()->getStorage('ebms_packet');
    $packets = $storage->loadMultiple($ids);
    $items = [];
    foreach ($packets as $packet) {
      $articles = count($packet->articles);
      $s = $articles === 1 ? '' : 's';
      $items[] = [
        'url' => Url::fromRoute('ebms_review.assigned_packet', ['packet_id' => $packet->id()], $options),
        'name' => $packet->title->value,
        'count' => "$articles article$s",
      ];
    }

    // Assemble the render array for the page.
    return [
      '#title' => $title,
      '#cache' => ['max-age' => 0],
      'packets' => [
        '#theme' => 'assigned_packets',
        '#packets' => $items,
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

}
