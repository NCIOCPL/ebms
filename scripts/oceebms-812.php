<?php

$sql = "
SELECT DISTINCT state.article, topic.id, topic.name
  FROM ebms_state AS state
  JOIN ebms_article_topic__states AS states
    ON states.states_target_id = state.id
  JOIN ebms_article_topic AS article_topic
    ON article_topic.id = states.entity_id
  JOIN ebms_topic AS topic
    ON topic.id = article_topic.topic
 WHERE state.board = 4 /* Screening and Prevention */
   AND state.current = 1
   AND state.value = 102 /* Published */
   AND article_topic.cycle <= '2023-10-01'
   AND states.deleted = 0
 ORDER BY 1, 3
";
$comment = 'Removing article/topic from queue (OCEEBMS-812)';
$results = \Drupal::database()->query($sql);
$rows = $results->fetchAll();
foreach ($rows as $row) {
  echo "{$row->article}\t{$row->id}\t{$row->name}\n";
  $article = \Drupal\ebms_article\Entity\Article::load($row->article);
  $article->addState('full_end', $row->id, 259, NULL, NULL, $comment);
}
