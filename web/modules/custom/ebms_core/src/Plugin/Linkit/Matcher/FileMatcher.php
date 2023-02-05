<?php

namespace Drupal\ebms_core\Plugin\Linkit\Matcher;

use Drupal\file\Entity\File;
use Drupal\linkit\MatcherBase;
use Drupal\linkit\Suggestion\SimpleSuggestion;
use Drupal\linkit\Suggestion\SuggestionCollection;

/**
 * Finds matching files for documents and articles.
 *
 * @Matcher(
 *   id = "ebms_matcher",
 *   label = "EBMS Matcher",
 * )
 */
class FileMatcher extends MatcherBase {

  /**
   * {@inheritdoc}
   */
  public function execute($string) {
    ebms_debug_log("looking for $string", 3);
    $suggestions = new SuggestionCollection();
    $query = \Drupal::database()->select('ebms_article', 'a');
    $query->condition('a.search_title', "%{$string}%", 'LIKE');
    $query->isNotNull('a.full_text__file');
    $query->fields('a', ['title', 'full_text__file']);
    $query->orderBy('a.search_title');
    $query->range(0, 100);
    $results = $query->execute();
    $counter = 0;
    foreach ($results as $result) {
      $file = File::load($result->full_text__file);
      $url = $file->createFileUrl();
      $suggestion = new SimpleSuggestion();
      $suggestion->setLabel($result->title)
        ->setPath($url)
        ->setGroup('Article Full Text');
      $suggestions->addSuggestion($suggestion);
      $counter++;
    }
    ebms_debug_log("found $counter matching articles", 3);
    $query = \Drupal::database()->select('ebms_doc', 'd');
    $query->condition('d.description', "%{$string}%", 'LIKE');
    $query->condition('d.dropped', 0);
    $query->isNotNull('d.file');
    $query->fields('d', ['description', 'file']);
    $query->orderBy('d.description');
    $query->range(0, 100);
    $results = $query->execute();
    $counter = 0;
    foreach ($results as $result) {
      $file = File::load($result->file);
      $url = $file->createFileUrl();
      $suggestion = new SimpleSuggestion();
      $suggestion->setLabel($result->description)
        ->setPath($url)
        ->setGroup('Documents');
      $suggestions->addSuggestion($suggestion);
      $counter++;
    }
    ebms_debug_log("found $counter matching documents", 3);
    return $suggestions;
  }

}
