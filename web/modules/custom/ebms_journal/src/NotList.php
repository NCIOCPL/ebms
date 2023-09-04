<?php

namespace Drupal\ebms_journal;

use Drupal\Core\Database\Connection;

/**
 * Service for identifying blacklisted journals.
 */
class NotList {

  /**
   * Connection to the SQL database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $db;

  /**
   * Constructs a new \Drupal\ebms_journal\NotList service object.
   *
   * @param \Drupal\Core\Database\Connection
   *   Connection to the SQL database.
   */
  public function __construct(Connection $connection) {
    $this->db = $connection;
  }

  /**
   * Find the journals this board doesn't want.
   *
   * First implementation of this method used the entity query API.
   * That blew up (as recorded in OCEEBMS-643), so we're falling back
   * on the Database API.
   *
   * @param int $board_id
   *   ID of the board whose rejected journals we collect.
   */
  public function getNotList(int $board_id): array {
    $now = date('Y-m-d H:i:s');
    $query = $this->db->select('ebms_journal', 'journal');
    $query->addField('journal', 'source_id', 'journal_id');
    $query->join('ebms_journal__not_lists', 'not_list', 'not_list.entity_id = journal.id');
    $query->condition('not_list.not_lists_board', $board_id);
    $query->condition('not_list.not_lists_start', $now, '<=');
    $not_list = $query->execute()->fetchCol();
    return array_unique($not_list);
  }

}
