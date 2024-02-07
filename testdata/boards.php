<?php

require(__DIR__ . '/console-log.php');

// Find out where the data is.
$repo_base = getenv('REPO_BASE') ?: '/var/www/ebms';

// Load the maps.
$json = file_get_contents("$repo_base/testdata/maps.json");
$maps = json_decode($json, true);

// Get the default review dispositions.
$dispositions = [];
foreach ($maps['dispositions'] as $disposition) {
  $dispositions[] = $disposition;
}

// Get the full list of dispositions.
$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$query = $storage->getQuery()->accessCheck(FALSE);
$query->condition('vid', 'dispositions');
$all_dispositions = $query->execute();
sort($all_dispositions, SORT_NUMERIC);

$json = file_get_contents("$repo_base/testdata/boards.json");
$boards = json_decode($json, true);
foreach ($boards as $values) {
  if ($values['name'] === 'Screening and Prevention') {
    $values['review_dispositions'] = $all_dispositions;
  }
  else {
    $values['review_dispositions'] = $dispositions;
  }

  $board = \Drupal\ebms_board\Entity\Board::create($values);
  $board->save();
}
$n = count($boards);
log_success("Successfully loaded: $n boards");
