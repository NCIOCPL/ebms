<?php

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$query = $storage->getQuery()->accessCheck(FALSE);
$query->condition('vid', 'states');
$query->condition('field_text_id', 'working_group_decision');
$ids = $query->execute();
if (empty($ids)) {
  $values = [
    'vid' => 'states',
    'field_text_id' => 'working_group_decision',
    'name' => 'Working group decision',
    'description' => 'Article was discussed by a working group and a provisional decision was reached.',
    'field_terminal' => FALSE,
    'status' => TRUE,
    'field_sequence' => 85,
    'weight' => 85,
  ];
  \Drupal\taxonomy\Entity\Term::create($values)->save();
  echo "installed new working_group_decision state\n";
}
else {
  echo "the new working_group_decision state has already been installed\n";
}
$query = $storage->getQuery()->accessCheck(FALSE);
$query->condition('vid', 'working_group_decisions');
$existing = [];
foreach ($storage->loadMultiple($query->execute()) as $term) {
  $existing[] = $term->name->value;
}
$loaded = 0;
$names = [
  'Cited (citation only)',
  'Hold',
  'Not cited',
  'Text approved',
  'Text needs to be revised',
  'Text needs to be written',
];
foreach ($names as $name) {
  if (!in_array($name, $existing)) {
    $values = [
      'vid' => 'working_group_decisions',
      'name' => $name,
      'status' => TRUE,
    ];
    \Drupal\taxonomy\Entity\Term::create($values)->save();
    $loaded++;
  }
}
if (empty($loaded)) {
  echo "working group decisions have already been loaded\n";
}
else {
  echo "$loaded working group decisions loaded\n";
}
