<?php

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$query = $storage->getQuery()->accessCheck(FALSE);
$query->condition('vid', 'dispositions');
$term_ids = $query->execute();
$dispositions = $storage->loadMultiple($term_ids);
$disposition_map = [];
foreach ($dispositions as $disposition) {
  $disposition_map[$disposition->name->value] = $disposition->id();
}
$json_path = __DIR__ . '/../testdata/board_dispositions.json';
$board_dispositions_json = file_get_contents($json_path);
$board_dispositions = json_decode($board_dispositions_json, TRUE);
$boards = \Drupal\ebms_board\Entity\Board::loadMultiple();
foreach ($boards as $board) {
  $disposition_ids = [];
  foreach ($board_dispositions[$board->name->value] as $disposition_name) {
    $disposition_ids[] = $disposition_map[$disposition_name];
  }
  $board->set('review_dispositions', $disposition_ids);
  $board->save();
}
echo 'assigned review dispositions to ' . count($boards) . " boards\n";
