<?php

namespace Drupal\ebms_report\Controller;

require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ebms_board\Entity\Board;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report on the last time each board member logged in.
 */
class BoardMemberLastLogins extends ControllerBase {

  /**
   * Create an Excel workbook for the report (no form needed).
   *
   * Rewritten to use the database API instead of the entity query API,
   * in order to speed up the report, which was taking over 3 minutes
   * on my own server and between 7 and 9 (or more) minutes on the
   * CBIIT servers. Now it finishes in under 2 seconds.
   */
  public function report() {

    // Time the report.
    $start = microtime(TRUE);

    // See if the externalauth module is enabled.
    $have_external_auth = \Drupal::moduleHandler()->moduleExists('externalauth');

    // Get the board names.
    $query = \Drupal::database()->select('ebms_board', 'b');
    $query->condition('b.active', 1);
    $query->fields('b', ['id', 'name']);
    $query->orderBy('b.name');
    $rows = $query->execute();
    $board_names = [];
    foreach ($rows as $row) {
      $board_names[$row->id] = $row->name;
    }

    // Find all the active board members.
    $query = \Drupal::database()->select('users_field_data', 'u');
    $query->fields('u', ['uid', 'name', 'login']);
    $query->addExpression("FROM_UNIXTIME(u.login, '%Y-%m-%d')", 'last');
    $query->condition('u.status', 1);
    $query->join('user__roles', 'r', 'r.entity_id = u.uid');
    $query->condition('r.roles_target_id', 'board_member');
    if ($have_external_auth) {
      $query->leftJoin('authmap', 'a', 'a.uid = u.uid');
      $query->addField('a', 'authname', 'authname');
    }
    $query->orderBy('u.name');
    $rows = $query->execute();
    $board_members = [];
    foreach ($rows as $row) {
      $board_members[$row->uid] = $row;
    }

    // Get board memberships.
    $query = \Drupal::database()->select('user__boards', 'b');
    $query->join('users_field_data', 'u', 'u.uid = b.entity_id');
    $query->condition('u.status', 1);
    $query->fields('b', ['entity_id', 'boards_target_id']);
    $rows = $query->execute();
    $memberships = [];
    foreach ($rows as $row) {
      $memberships[$row->entity_id][] = $row->boards_target_id;
    }

    // Collect counts of outstanding reviews per board per member.
    $boards = [];
    $homeless = [];
    foreach (array_keys($board_members) as $uid) {
      if (empty($memberships[$uid])) {
        $homeless[$uid] = 0;
      }
      else {
        foreach ($memberships[$uid] as $board_id) {
          $boards[$board_id][$uid] = $this->count_unreviewed_articles($uid, $board_id);
        }
      }
    }

    // Create a new workbook and clear out any default worksheets.
    $book = new Spreadsheet();
    while ($book->getSheetCount() > 0) {
      $book->removeSheetByIndex(0);
    }
    $properties = $book->getProperties();
    $properties->setCustomProperty('EBMS Server', php_uname('n'));

    // Create a worksheet for each board.
    foreach ($board_names as $board_id => $board_name) {
      if ($board_name === 'Integrative, Alternative, and Complementary Therapies') {
        $board_name = 'IACT';
      }
      $this->addSheet($book, $have_external_auth, $board_name, $boards[$board_id], $board_members);
    }

    // Add a final worksheet for those not yet assigned a board.
    $this->addSheet($book, $have_external_auth, 'No Boards', $homeless, $board_members);

    // Wrap things up and send the workbook to the client.
    $book->setActiveSheetIndex(0);
    $writer = new Xlsx($book);
    ob_start();
    $writer->save('php://output');
    $stamp = date('YmdHis');
    $elapsed = microtime(TRUE) - $start;
    ebms_debug_log("Board Member Last Logins: elapsed time: $elapsed", 1);
    return new Response(
      ob_get_clean(),
      200,
      [
        'Content-type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'Content-disposition' => 'attachment; filename="board-members-last-login-' . $stamp . '.xlsx"',
      ],
    );
  }

  /**
   * Find out how many unreviewed articles a user has for a given board.
   *
   * @param $uid int
   *   ID of board member's Drupal user account
   * @param $board_id int
   *   ID of board
   *
   * @return int
   *   Number of reviews we're still waiting for
   */
  private function count_unreviewed_articles(int $uid, int $board_id = 0) {

    // Cache these so we only look them up once.
    static $fyi = NULL;
    static $max_sequence = NULL;
    if (empty($fyi)) {
      $term_lookup = \Drupal::service('ebms_core.term_lookup');
      $fyi = $term_lookup->getState('fyi')->id();
      $passed_full_review = $term_lookup->getState('passed_full_review');
      $max_sequence = $passed_full_review->field_sequence->value;
    }

    // Find the board member's reviews.
    $subquery = \Drupal::database()->select('ebms_packet_article__reviews', 'reviews');
    $subquery->join('ebms_review', 'review', 'review.id = reviews.reviews_target_id');
    $subquery->condition('review.reviewer', $uid);
    $subquery->addField('reviews', 'reviews_target_id');
    $subquery->distinct();

    // Create the query.
    $query = \Drupal::database()->select('ebms_packet_article', 'a');
    $query->addField('a', 'article', 'id');
    $query->condition('a.dropped', 0);
    $query->isNull('a.archived');
    $query->join('ebms_packet__articles', 'pa', 'pa.articles_target_id = a.id');
    $query->join('ebms_packet', 'p', 'p.id = pa.entity_id');
    $query->condition('p.active', 1);
    $query->join('ebms_packet__reviewers', 'u', 'u.entity_id = p.id');
    $query->condition('u.reviewers_target_id', $uid);
    $query->join('ebms_state', 's', 's.article = a.article AND s.topic = p.topic');
    $query->condition('s.current', 1);
    $query->condition('s.value', $fyi, '<>');
    $query->join('taxonomy_term__field_sequence', 'q', 'q.entity_id = s.value');
    $query->condition('q.field_sequence_value', $max_sequence, '<=');
    if (!empty($board_id)) {
      $query->condition('s.board', $board_id);
    }
    $query->condition('a.id', $subquery, 'NOT IN');
    $query->distinct();
    $query = $query->countQuery();
    return $query->execute()->fetchField();
  }

  /**
   * Add a sheet to the workbook and populate it.
   */
  private function addSheet($book, $have_external_auth, $board_name, &$unreviewed, &$board_members) {

    // Create the sheet and configure its settings.
    $sheet = $book->createSheet();
    $sheet->setTitle($board_name);
    $sheet->setCellValue('A1', 'Name');
    if ($have_external_auth) {
      $sheet->setCellValue('B1', 'AuthName');
      $sheet->setCellValue('C1', 'Last Login');
      $sheet->setCellValue('D1', 'Outstanding Reviews');
      $range = 'A1:D1';
      $sheet->getColumnDimension('D')->setAutoSize(TRUE);
    }
    else {
      $sheet->setCellValue('B1', 'Last Login');
      $sheet->setCellValue('C1', 'Outstanding Reviews');
      $range = 'A1:C1';
    }
    $sheet->getStyle($range)->applyFromArray([
      'fill' => [
        'fillType' => 'solid',
        'color' => ['argb' => 'FF0101DF'],
      ],
      'font' => [
        'bold' => TRUE,
        'color' => ['argb' => 'FFFFFFFF'],
      ],
      'alignment' => [
        'horizontal' => 'center',
      ],
    ]);
    $sheet->getColumnDimension('A')->setAutoSize(TRUE);
    $sheet->getColumnDimension('B')->setAutoSize(TRUE);
    $sheet->getColumnDimension('C')->setAutoSize(TRUE);

    // Add the user rows.
    $row = 2;
    foreach ($unreviewed as $uid => $count) {
      $sheet->setCellValue("A$row", $board_members[$uid]->name);
      if ($have_external_auth) {
        $sheet->setCellValue("B$row", $board_members[$uid]->authname ?? '');
        $sheet->setCellValue("C$row", $board_members[$uid]->login ? $board_members[$uid]->last : '');
        $sheet->setCellValue("D$row", $count);
      }
      else {
        $sheet->setCellValue("B$row", $board_members[$uid]->login ? $board_members[$uid]->last : '');
        $sheet->setCellValue("C$row", $count);
      }
      ++$row;
    }

    // Not sure why this cell was picked, but that's what the original did.
    $sheet->setSelectedCell('A2');
  }

}
