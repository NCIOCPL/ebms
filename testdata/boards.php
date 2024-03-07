<?php

require(__DIR__ . '/console-log.php');

// Find out where the data is.
$repo_base = getenv('REPO_BASE') ?: '/var/www/ebms';

// Get the full list of dispositions.
$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$query = $storage->getQuery()->accessCheck(FALSE);
$query->condition('vid', 'dispositions');
$term_ids = $query->execute();
$dispositions = $storage->loadMultiple($term_ids);
$disposition_map = [];
foreach ($dispositions as $disposition) {
  $disposition_map[$disposition->name->value] = $disposition->id();
}

// Load the per-board review dispositions.
$json_path = __DIR__ . '/../testdata/board_dispositions.json';
$board_dispositions_json = file_get_contents($json_path);
$board_dispositions = json_decode($board_dispositions_json, TRUE);

// Create the board entities.
$json = file_get_contents("$repo_base/testdata/boards.json");
$boards = json_decode($json, TRUE);
foreach ($boards as $values) {
  $disposition_ids = [];
  foreach ($board_dispositions[$values['name']] as $disposition_name) {
    $disposition_ids[] = $disposition_map[$disposition_name];
  }
  $values['review_dispositions'] = $disposition_ids;
  $board = \Drupal\ebms_board\Entity\Board::create($values);
  $board->save();
}
$n = count($boards);
log_success("Successfully loaded: $n boards");
