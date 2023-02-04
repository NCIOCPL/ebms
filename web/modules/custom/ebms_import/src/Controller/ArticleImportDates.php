<?php

namespace Drupal\ebms_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

/**
 * List of when each article was last imported or refreshed.
 */
class ArticleImportDates extends ControllerBase {

  /**
   * Return a plain-text response.
   */
  public function list(): Response {
    $query = \Drupal::database()->select('ebms_article', 'article');
    $query->fields('article', [
      'id', 'source_id', 'import_date', 'update_date'
    ]);
    $query->distinct();
    $query->orderBy('article.id');
    $results = $query->execute();
    $ids = [];
    foreach ($results as $result) {
      $date = $result->update_date ?: $result->import_date;
      $ids[] = "{$result->id}\t{$result->source_id}\t{$date}";
    }
    $response = new Response(implode("\n", $ids) . "\n");
    $response->headers->set('Content-type', 'text/plain');
    return $response;
  }

}
