<?php
$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$query = $storage->getQuery()->accessCheck(FALSE);
$query->condition('vid', 'dispositions');
$all_dispositions = $query->execute();
sort($all_dispositions, SORT_NUMERIC);
$original_dispositions = array_slice($all_dispositions, 0, 4);
$boards = \Drupal\ebms_board\Entity\Board::loadMultiple();
foreach ($boards as $board) {
  $board->set('review_dispositions', $original_dispositions);
  $board->save();
}
echo 'assigned review dispositions to ' . count($boards) . " boards\n";
