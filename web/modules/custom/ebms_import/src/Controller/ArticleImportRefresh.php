<?php

namespace Drupal\ebms_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ebms_import\Entity\Batch;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fetch and apply fresh XML from PubMed.
 */
class ArticleImportRefresh extends ControllerBase {

  const COMMENT = 'BATCH REPLACEMENT OF UPDATED ARTICLES FROM PUBMED';

  /**
   * Return a plain-text response.
   */
  public function run(): Response {

    // Remember when we started so we can report the elapsed time.
    $start = microtime(TRUE);
    try {

      // Get the PubMed IDs.
      $pmids = \Drupal::request()->request->get('pmids');
      ebms_debug_log("pmids=$pmids", 3);

      // Prevent a rogue process from tricking us into adding new articles.
      $query = \Drupal::database()->select('ebms_article', 'article');
      $query->addField('article', 'source_id');
      $query->distinct();
      $source_ids = $query->execute()->fetchCol();
      $count = count($source_ids);
      ebms_debug_log("we currently have $count PMIDs in the EBMS");
      $pmids = array_intersect(explode(',', $pmids), $source_ids);

      // If we have any to update, do it.
      if (!empty($pmids)) {
        $matched = [];
        $request = [
          'article-ids' => $pmids,
          'import-comments' => self::COMMENT,
        ];
        $batch = Batch::process($request);

        // Check for failure.
        if (empty($batch->success->value)) {
          if ($batch->messages->count() < 1) {
            $report = 'Import of fresh XML failed for unspecified reasons.';
          }
          else {
            foreach ($batch->messages as $message) {
              $report = $message->value;
              ebms_debug_log($report, 1);
              break;
            }
          }
        }

        // Find out how many we updated.
        else {
          $imported = [];
          $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
          $query = $storage->getQuery()->accessCheck(FALSE);
          $query->condition('vid', 'import_dispositions');
          $query->condition('field_text_id', 'error');
          $ids = $query->execute();
          $error_id = reset($ids);
          foreach ($batch->actions as $action) {
            if ($action->disposition != $error_id && !empty($action->article)) {
              $imported[$action->article] = 1;
            }
          }
          $count = count($imported);
          $report = "Refreshed $count articles.";
        }
      }
      else {
        $report = 'No PubMed IDs submitted.';
      }
      $report = [$report];
    }
    catch (\Exception $e) {
      $report = "Failure: $e";
    }

    // Notify the development team of the outcome.
    $this->send_report($report, $start);

    // Tell the caller what happened.
    if (is_array($report)) {
      $report = implode("\n", $report);
    }
    $response = new Response($report);
    $response->headers->set('Content-type', 'text/plain');
    return $response;
  }

  /**
   * Email the report to the usual suspects.
   *
   * @param string|array $report
   *   Report lines array or error message string.
   * @param float $start
   *   When the job started.
   */
  private function send_report($report, $start) {

    @ebms_debug_log('Starting Article XML Refresh report', 1);
    $to = \Drupal::config('ebms_core.settings')->get('dev_notif_addr');
    if (empty($to)) {
      \Drupal::logger('ebms_review')->error('No recipients for article XML refresh report.');
      @ebms_debug_log('Aborting Article XML Refresh report: no recipients registered.', 1);
      return;
    }
    $server = php_uname('n');
    $subject = "EBMS Article XML Refresh ($server)";
    $message = '';
    if (is_array($report)) {
      foreach ($report as $line) {
        $message .= '<p>' . htmlspecialchars($line) . "</p>\n";
      }
    }
    else {
      $message = "<p style=\"color: red; font-size: 1rem; font-weight: bold\">$report</p>\n";
      $subject .= ' [FAILURE]';
    }
    $elapsed = microtime(TRUE) - $start;
    $message .= '<p style="color: green; font-size: .8rem; font-style: italic;">Processing time: ';
    $message .= $elapsed;
    $message .= ' seconds.</p>';

    // Send the report.
    $site_mail = \Drupal::config('system.site')->get('mail');
    $site_name = \Drupal::config('system.site')->get('name');
    $from = "$site_name <$site_mail>";
    $headers = implode("\r\n", [
      'MIME-Version: 1.0',
      'Content-type: text/html; charset=utf-8',
      "From: $from",
    ]);
    $rc = mail($to, $subject, $message, $headers);
    if (empty($rc)) {
      \Drupal::logger('ebms_review')->error('Unable to send Article XML Refresh report.');
      @ebms_debug_log('Failure sending report.', 1);
    }
    @ebms_debug_log('Finished Article XML Refresh report', 1);
  }

}
