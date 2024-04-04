<?php

namespace Drupal\ebms_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * List of when each article was last imported, refreshed, or checked.
 */
class ArticleImportDates extends ControllerBase {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ArticleImportDates {
    // Instantiates this form class.
    $instance = parent::create($container);
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * Return a plain-text response.
   */
  public function list(): StreamedResponse {
    $response = new StreamedResponse();
    $response->headers->set('Content-type', 'text/plain');
    $response->setCallback(function () {
      $query = $this->database->select('ebms_article', 'article');
      $query->fields('article', [
        'id', 'source_id', 'import_date', 'update_date', 'data_checked'
      ]);
      $query->distinct();
      $query->orderBy('article.id');
      $results = $query->execute();
      ebms_debug_log('streaming rows');
      $count = 0;
      foreach ($results as $result) {
        $date = $result->update_date ?: $result->import_date;
        if (!empty($result->data_checked) && $result->data_checked > $date) {
          $date = $result->data_checked;
        }
        echo "{$result->id}\t{$result->source_id}\t{$date}\n";
        flush();
        $count++;
      }
      ebms_debug_log("finished streaming $count rows");
    });
    return $response;
  }

}
