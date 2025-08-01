<?php /** @noinspection ALL */

namespace Drupal\ebms_article\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\ebms_article\Entity\Article;
use Drupal\ebms_review\Entity\Review;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Show a page for a single article.
 *
 * The display consists of two portions. The top of the page displays
 * information pertaining to the entire article (the citation, general
 * import information, IDs, review cycles, etc.). The rest of the page
 * shows the topic-specific activity, grouped by board.
 */
final class ArticleController extends ControllerBase {

  /**
   * Single characters used to characterize an action or state.
   *
   * For example, a green check mark to indicate approval, or a red X
   * to represent a rejection or other halt.
   */
  const FLAGS = [
    'yes' => "\u{2705}",
    'no' => "\u{274C}",
    'info' => "\u{1F4D8}",
    'hold' => "\u{270B}",
    'write' => "\u{270D}",
    'waiting' => " \u{231B}",
    'ongoing' => "\u{2652}"
  ];

  /**
   * Rejection state text IDs.
   */
  const REJECTION_STATES = [
    'reject_journal_title',
    'reject_init_review',
    'reject_bm_review',
    'reject_full_review',
    'full_end',
  ];

  /**
   * Lookup table to find the board name for a given topic name.
   *
   * @var array
   */
  protected $topicBoards = [];

  /**
   * Import actions shown on the article's page.
   *
   * @var array
   */
  protected $actions = [];

  /**
   * The last time the article's data was refreshed from NLM.
   *
   * @var string
   */
  protected $refreshed = '';

  /**
   * States, indexed by board, then by topic.
   *
   * @var array
   */
  protected $states = [];

  /**
   * Flag indicating whether display of later states should be suppressed.
   *
   * @var boolean
   */
  protected $brief = FALSE;

  /**
   * The term_lookup service.
   *
   * @var \Drupal\ebms_core\TermLookup
   */
  protected $termLookup;

  /**
   * Storage service for taxonomy terms.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $termStorage;

  /**
   * Storage service for user entities.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $userStorage;

  /**
   * The current page requests.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->termLookup = $container->get('ebms_core.term_lookup');
    $instance->termStorage = $instance->entityTypeManager()->getStorage('taxonomy_term');
    $instance->userStorage = $instance->entityTypeManager()->getStorage('user');
    $instance->currentRequest = $container->get('request_stack')->getCurrentRequest();
    $instance->database = $container->get('database');
    $instance->messenger = $container->get('messenger');
    return $instance;
  }

  /**
   * Display article information.
   *
   * It's important to call `getStateRenderArrays()` first, because that
   * method populates the `actions` and `refreshes` properties plugged into
   * the render array. We can't rely on the language's order of evaluation
   * of the calls.
   *
   * @param \Drupal\ebms_article\Entity\Article $article
   *   Article entity for which the render array is assembled.
   *
   * @return array
   *   Render array for the page.
   */
  public function display(Article $article) {
    ebms_debug_log('top of ArticleController::display()', 3);
    $states = $this->getStateRenderArrays($article);
    return [
      '#title' => 'Full Article History',
      '#attached' => ['library' => ['ebms_article/article']],
      'top-actions' => [
        '#theme' => 'ebms_local_actions',
        '#actions' => $this->getArticleActionButtons($article),
      ],
      'citation' => [
        '#theme' => 'citation',
        '#title' => 'EBMS Article',
        '#authors' => implode(', ', $article->getAuthors()),
        '#article_title' => $article->get('title')->value,
        '#publication' => $article->getLabel(),
        '#ids' => $this->getArticleIds($article),
        '#cycles' => $article->getCycles(),
        '#full_text' => $this->getArticleFullTextInformation($article),
        '#related' => $this->getRelatedArticles($article),
        '#tags' => $this->getArticleTags($article, $article->id()),
        '#internal' => [
          'tags' => $this->getInternalTags($article),
          'comments' => $this->getinternalComments($article),
        ],
        '#import' => [
          'user' => $article->imported_by->entity->getDisplayName(),
          'date' => $article->import_date->value,
        ],
        '#refreshed' => $this->refreshed,
        '#cache' => ['max-age' => 0],
      ],
      'states' => $states,
    ];
  }

  /**
   * Find the articles related in some way to this one.
   *
   * @param object $article
   * @return array
   */
  private function getRelatedArticles($article) {
    ebms_debug_log('top of ArticleController::getRelatedArticles()', 3);
    $article_id = $article->id();
    $storage = $this->entityTypeManager()->getStorage('ebms_article_relationship');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $group = $query->orConditionGroup()
      ->condition('related', $article_id)
      ->condition('related_to', $article_id);
    $query->condition($group);
    $query->condition('inactivated', NULL, 'IS NULL');
    $ids = $query->execute();
    ebms_debug_log('getRelatedArticles(): got ' . count($ids) . ' ID rows', 3);
    $relationships = $storage->loadMultiple($ids);
    $storage = $this->entityTypeManager()->getStorage('ebms_article');
    $values = [];
    $request_query_parms = $this->currentRequest->query->all();
    foreach ($relationships as $relationship) {
      $related = $relationship->related->target_id;
      $related_to = $relationship->related_to->target_id;
      $other_id = $related == $article_id ? $related_to : $related;
      $other_article = $storage->load($other_id);
      $pmid = $other_article->source_id->value;
      $uri = "https://pubmed.ncbi.nlm.nih.gov/$pmid";
      $options = [
        'attributes' => [
          'target' => '_blank',
          'title' => 'View abstract of related article in a separate browser tab.',
        ],
      ];
      $url = Url::fromUri($uri, $options);
      $pubmed = Link::fromTextAndUrl($pmid, $url);
      $route = 'ebms_article.article';
      $options['attributes']['title'] = 'View related article in a separate browser tab';
      $parms = ['article' => $other_id];
      $url = Url::fromRoute($route, $parms, $options);
      $related = Link::fromTextAndUrl($other_id, $url);
      $route = 'ebms_article.delete_article_relationship';
      $parms = ['ebms_article_relationship' => $relationship->id()];
      $opts = ['query' => $request_query_parms];
      $opts['query']['article'] = $article_id;
      $delete = Url::fromRoute($route, $parms, $opts);
      $route = 'ebms_article.edit_article_relationship';
      $parms = ['relationship_id' => $relationship->id()];
      $edit = Url::fromRoute($route, $parms, $opts);
      $values[] = [
        'related' => $related,
        'type' => $relationship->type->entity->name->value,
        'comment' => $relationship->comment->value,
        'pmid' => $pubmed,
        'delete' => $delete,
        'edit' => $edit,
      ];
    }
    ebms_debug_log('returning ' . count($values) . ' relationships', 3);
    return $values;
  }

  /**
   * Assemble information about how the article first entered the system.
   *
   * Populates the `actions` and `refreshed` properties of the controller.
   *
   * @param \Drupal\ebms_article\Entity\Article $article
   *   Article entity for which the information is collected.
   */
  private function collectImportEvents(object $article) {

    // Another place where the entity query API falls short.
    // See https://tracker.nci.nih.gov/browse/OCEEBMS-718.
    $query = $this->database->select('ebms_import_batch__actions', 'a');
    $query->join('ebms_import_batch', 'b', 'b.id = a.entity_id');
    $query->join('taxonomy_term_field_data', 'd', 'd.tid = a.actions_disposition');
    $query->join('taxonomy_term_field_data', 't', 't.tid = b.import_type');
    $query->leftJoin('users_field_data', 'u', 'u.uid = b.user');
    $query->leftJoin('ebms_topic', 'topic', 'topic.id = b.topic');
    $query->condition('a.actions_article', $article->id());
    $query->addField('d', 'name', 'disposition');
    $query->addField('t', 'name', 'import_type');
    $query->addField('topic', 'name', 'topic_name');
    $query->addField('u', 'name', 'user_name');
    $query->fields('b', ['id', 'imported', 'cycle']);
    $query->orderBy('b.imported');
    $actions = $query->execute();
    $batches = [];
    $this->refreshed = '';
    $refresh_count = 0;
    foreach ($actions as $action) {
      if (empty($action->topic_name)) {
        if (str_contains(strtolower($action->import_type), 'refresh')) {
          $user = $action->user_name ?: 'scheduled job';
          $date = substr($action->imported, 0, 10);
          $refresh_count++;
          if ($refresh_count === 1) {
            $msg = "Refreshed from PubMed $date by $user";
          }
          elseif ($refresh_count === 2) {
            $msg = "Refreshed twice from PubMed, most recently $date by $user";
          }
          else {
            $msg = "Refreshed $refresh_count times from PubMed, most recently $date by $user";
          }
          $this->refreshed = $msg;
        }
      }
      else {
        if (!array_key_exists($action->id, $batches)) {
          $batches[$action->id] = [
            'date' => $action->imported,
            'user' => $action->user_name,
            'type' => $action->import_type,
            'cycle' => $action->cycle,
            'topic' => $action->topic_name,
            'dispositions' => [],
          ];
        }
        $batches[$action->id]['dispositions'][] = $action->disposition;
      }
    }

    // Index the import events by topic.
    $this->actions = [];
    foreach ($batches as $batch) {

      // Pick the most salient disposition value for each event, testing
      // the values in the order tive in the `$dispositions` array below.
      $dispositions = [
        'Error',
        'Imported',
        'Summary Topic Added',
        'Replaced',
        'NOT Listed',
        'Ready For Review',
        'Duplicate, Not Imported',
      ];
      $disposition = 'unknown';
      foreach ($dispositions as $candidate) {
        if (in_array($candidate, $batch['dispositions'])) {
          $disposition = $candidate;
          break;
        }
      }
      $this->actions[$batch['topic']][] = [
        'date' => $batch['date'],
        'user' => $batch['user'],
        'type' => $batch['type'],
        'cycle' => $batch['cycle'],
        'disposition' => $disposition,
      ];
    }
  }

  /**
   * Organize the state information for each of the article's topics.
   *
   * Populates the `states` and `topicBoards` properties of the controller.
   *
   * @param \Drupal\ebms_article\Entity\Article $article
   *   Article entity for which the information is collected.
   */
  private function collectStateInformation($article) {

    // Clear the decks.
    $this->topicBoards = [];
    $this->states = [];

    // Find the threshold for the states the brief version will show.
    $full_text_approval = $this->termLookup->getState('passed_full_review');
    $full_text_approval_sequence = $full_text_approval->field_sequence->value;

    // Get all the state entities connected with this article.
    $storage = $this->entityTypeManager()->getStorage('ebms_state');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('article', $article->id());
    $states = $storage->loadMultiple($query->execute());

    // Extract the display information from each state.
    $opts = ['query' => $this->currentRequest->query->all()];
    $opts['query']['article'] = $article->id();
    foreach ($states as $state) {
      if ($this->brief) {
        if ($state->value->entity->field_sequence->value > $full_text_approval_sequence) {
          continue;
        }
      }
      $topic = $state->topic->entity;
      $board = $topic->board->entity->name->value;
      $topic = $topic->getName();
      $this->topicBoards[$topic] = $board;
      $route = 'ebms_article.add_state_comment';
      $parms = ['state_id' => $state->id()];
      $url = Url::fromRoute($route, $parms, $opts);
      $this->states[$board][$topic][] = [
        '#theme' => 'article_state',
        '#state' => $state->value->entity->getName(),
        '#date' => $state->entered->value,
        '#user' => empty($state->user) ? 'Unknown User' : $state->user->entity->getDisplayName(),
        '#active' => $state->active->value,
        '#current' => $state->current->value,
        '#terminal' => $state->value->entity->field_terminal->value,
        '#legend' => $this->getStateLegend($state),
        '#notes' => $this->getStateNotes($state),
        '#comments' => $this->getStateComments($state),
        '#button' => $url,
      ];
    }
  }

  /**
   * Collect the comments entered for a particular state.
   *
   * @param \Drupal\ebms_state\Entity\State $state
   *   Entity for a state entered for one of the article's topics.
   * @return array
   *   Render array for the state's comments.
   */
  private function getStateComments($state) {
    $comments = [];
    if (!empty($state->comments)) {
      foreach ($state->comments as $comment) {
        $user = $this->userStorage->load($comment->user);
        if (!empty($user)) {
          $user = $user->name->value;
        }
        else {
          $user = 'Unknown User';
        }
        $comments[] = [
          '#theme' => 'state_comment',
          '#entered' => $comment->entered,
          '#user' => $user,
          '#body' => $comment->body,
        ];
      }
      usort($comments, function ($a, $b) {
        return $a['#entered'] <=> $b['#entered'];
      });
    }
    return $comments;
  }

  /**
   * Collect additional information about a later state.
   *
   * @param \Drupal\ebms_state\Entity\State $state
   *   Entity for a state entered for one of the article's topics.
   * @return array
   *   Array of strings to be displayed below the main line for a state.
   */
  private function getStateNotes(object $state) {
    $notes = [];
    foreach ($state->decisions as $decision) {
      $name = $this->termStorage->load($decision->decision)->name->value;
      if (!empty($decision->discussed->value)) {
        $name .= ' (article discussed)';
      }
      $notes[] = "Decision: $name";
    }
    foreach ($state->wg_decisions as $decision) {
      $name = $decision->entity->name->value;
      $notes[] = "Decision: $name";
    }
    $deciders = [];
    foreach ($state->deciders as $decider) {
      $deciders[] = $decider->entity->getDisplayName();
    }
    if (!empty($deciders)) {
      $notes[] = 'Board members: ' . implode(', ', $deciders);
    }
    foreach ($state->meetings as $meeting) {
      $meeting = $meeting->entity;
      $name = $meeting->name->value;
      $date = new \DateTime($meeting->dates->value);
      $date = $date->format('Y-m-d');
      $notes[] = "Meeting: $name - $date";
    }
    return $notes;
  }

  /**
   * Find out what character to display to convey the nature of this state.
   *
   * There won't usually be more than one decision, if any, but it's possible.
   * If there are, they're likely to be inconsistent, so it really doesn't
   * make much difference which on we pick.
   *
   * @param \Drupal\ebms_state\Entity\State $state
   *   Entity for a state entered for one of the article's topics.
   * @return string
   *   Value used to map to the character to be displayed for the state.
   */
  private function getStateLegend(object $state) {
    $text_id = $state->value->entity->field_text_id->value;
    $legend = '';
    if (in_array($text_id, self::REJECTION_STATES)) {
      $legend = 'no';
    }
    elseif (in_array($text_id, ['full_review_hold', 'on_hold'])) {
      $legend = 'hold';
    }
    elseif ($text_id === 'fyi') {
      $legend = 'info';
    }
    foreach ($state->wg_decisions as $decision) {
      $name = $decision->entity->name->value;
      if ($name === 'Not cited') {
        $legend = 'no';
      }
      elseif ($name === 'Hold') {
        $legend = 'hold';
      }
      elseif (str_contains($name, 'Text needs to be')) {
        $legend = 'write';
      }
      else {
        $legend = 'yes';
      }
    }
    foreach ($state->decisions as $decision) {
      $name = $this->termStorage->load($decision->decision)->name->value;
      if ($name === 'Not cited') {
        $legend = 'no';
      }
      elseif ($name === 'Hold') {
        $legend = 'hold';
      }
      elseif (str_contains($name, 'Text needs to be')) {
        $legend = 'write';
      }
      else {
        $legend = 'yes';
      }
    }
    return $legend;
  }

  /**
   * Collect information on all the packets in which the article appears.
   *
   * Populates the controller's `states` attribute with render arrays for
   * the packets so they get sorted by date with the other activity for the
   * topic. A different theme is used so that the packet information has a
   * different rendering than the states themselves.
   *
   * @param \Drupal\ebms_article\Entity\Article $article
   *   Article entity for which the information is collected.
   */
  private function collectPacketInformation($article) {

    // Find all the packets in which this article is assigned for review.
    $storage = $this->entityTypeManager()->getStorage('ebms_packet');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition('articles.entity.article', $article->id());
    $packets = $storage->loadMultiple($query->execute());

    // Walk through each one, assembling its render array.
    foreach ($packets as $packet) {

      // Get the keys used to add the render array to `$this->states`.
      $topic = $packet->topic->entity->getName();
      if (key_exists($topic, $this->topicBoards)) {
        $board = $this->topicBoards[$topic];
      }
      else {
        $board = $packet->topic->entity->board->entity->getName();
        $this->topicBoards[$topic] = $board;
      }

      // Create a collapsible block for the packet.
      $when = $packet->created->value;
      $who = $packet->created_by->entity->getDisplayName();
      $packet_details = [
        '#type' => 'details',
        '#date' => $when,
        'info' => [
          '#theme' => 'item_list',
          '#list_type' => 'ul',
          '#items' => [
            "- Assigned for review $when by $who",
          ],
        ],
        'reviews' => [],
      ];

      // Add the board members assigned to review the articles in the packet.
      $reviewers = [];
      foreach ($packet->reviewers as $reviewer) {
        $reviewers[] = $reviewer->entity->getDisplayName();
      }
      if (!empty($reviewers)) {
        $reviewers = implode(', ', $reviewers);
        $packet_details['info']['#items'][] = "- Reviewers: $reviewers";
      }

      // Find our article in the packet.
      $yes = $no = FALSE;
      foreach ($packet->articles as $packet_article) {
        $packet_article = $packet_article->entity;
        if ($packet_article->article->target_id == $article->id()) {

          // This is our article. Add each of the reviews as a nested "state"
          // inside the packet's collapsible block, determining whether the
          // reviews are positive (`$yes`) or rejections (`$no`) or a mix.
          // These flags are used to add one or more graphic characters to the
          // packet block's label so the user can see the nature of the
          // reviews without expanding the packet block's collapsed display.
          // If any rejections are found for an individual review, that
          // review's line is marked with a visual rejection indicator. See
          // `ArticleController::FLAGS`.
          foreach ($packet_article->reviews as $review) {
            $rejected = FALSE;
            $review = $review->entity;
            $state = [
              '#theme' => 'article_state',
              '#state' => 'Reviewed by board member',
              '#date' => $review->posted->value,
              '#user' => $review->reviewer->entity->getDisplayName(),
            ];
            $dispositions = [];
            foreach ($review->dispositions as $disposition) {
              $disposition = $disposition->entity->getName();
              if ($disposition === Review::NO_CHANGES) {
                $no = $rejected = TRUE;
              }
              else {
                $yes = TRUE;
              }
              $dispositions[] = $disposition;
            }
            $notes = ['Recommendations: ' . implode('; ', $dispositions)];
            $comment = $review->comments->value;
            if (!empty($comment)) {
              $notes[] = [
                'value' => "Comment: $comment",
                'raw' => TRUE,
              ];
            }
            $state['#notes'] = $notes;
            if ($rejected) {
              $state['#legend'] = 'no';
            }
            $packet_details['reviews'][] = $state;
          }

          // An article only appears once in a packet, so we're done here.
          break;
        }
      }

      // Create the label for the packet's collapsible block and add the
      // packet to `$this->states`.
      $title = $packet->title->value;
      if (!$packet->active->value) {
        $title .= ' [archived]';
      }
      if ($yes) {
        $title .= ' ' . self::FLAGS['yes'];
      }
      if ($no) {
        $title .= ' ' . self::FLAGS['no'];
      }
      if (!$yes && !$no) {
        $title .= ' ' . self::FLAGS['waiting'];
      }
      $packet_details['#title'] = "Packet $title";
      $this->states[$board][$topic][] = $packet_details;
    }
  }

  /**
   * Assemble board manager comments entered for the article's topic.
   *
   * Populates the controller's `states` attribute with render arrays for
   * the comments so they get sorted by date with the other activity for the
   * topic.
   *
   * @param \Drupal\ebms_article\Entity\Article $article
   *   Article entity for which the information is collected.
   */
  private function collectTopicComments($article) {
    // We're adding these using the 'state' structure so they get sorted with
    // the other activity for this topic.
    foreach ($article->topics as $topic) {
      $article_topic = $topic->entity;
      $topic = $board = NULL;
      foreach ($article_topic->comments as $comment) {
        $state = [
          '#theme' => 'article_state',
          '#state' => 'Topic-level comment added by board manager',
          '#date' => $comment->entered,
          '#user' => $this->userStorage->load($comment->user)->name->value,
          '#active' => TRUE,
          '#notes' => [$comment->comment],
        ];
        if (!empty($comment->modified)) {
          $user = $this->userStorage->load($comment->modified_by)->name->value;
          $when = substr($comment->modified, 0, 10);
          $state['#notes'][] ="(Comment modified $when by $user.)";
        }
        if (is_null($topic)) {
          $topic = $article_topic->topic->entity;
          $board = $topic->board->entity->getName();
          $topic = $topic->getName();
        }
        $this->states[$board][$topic][] = $state;
      }
    }
  }

  /**
   * Assemble the buttons used for article-wide actions.
   *
   * These will be displayed at the top of the article page.
   *
   * @param Drupal\ebms_article\Entity\Article $article
   * @return array
   */
  private function getArticleActionButtons($article) {
    $options = ['query' => $this->currentRequest->query->all()];
    $options_with_article = $options;
    $options_with_article['query']['article'] = $article->id();
    $actions = [
      [
        'url' => Url::fromRoute('ebms_article.add_article_tag', ['article_id' => $article->id()], $options),
        'label' => 'Add Tag',
        'attributes' => ['title' => 'Assign a new tag directly to the article (not topic specific).'],
      ],
      [
        'url' => Url::fromRoute('ebms_article.add_article_topic', ['article_id' => $article->id()], $options),
        'label' => 'Add Topic',
        'attributes' => ['title' => 'Assign a new topic to the article.'],
      ],
      [
        'url' => Url::fromRoute('ebms_article.add_full_text', ['article_id' => $article->id()], $options),
        'label' => 'Full Text',
        'attributes' => ['title' => 'Upload PDF or mark as unavailable.'],
      ],
      [
        'url' => Url::fromRoute('ebms_article.add_article_relationship', [], $options_with_article),
        'label' => 'Related',
        'attributes' => ['title' => 'Capture relationships between this and other articles.'],
      ],
      [
        'url' => Url::fromRoute('ebms_article.internal_tags', ['article_id' => $article->id()], $options),
        'label' => 'Internal Tags',
        'attributes' => ['title' => 'Mark this article for internal use instead of board member review.'],
      ],
      [
        'url' => Url::fromRoute('ebms_article.add_internal_comment', ['article_id' => $article->id()], $options),
        'label' => 'Internal Comment',
        'attributes' => ['title' => 'Provide notes about how this article may be of interest to internal PDQ staff.'],
      ],
    ];
    if (!empty($options['query']['search'])) {
      $search_id = $options['query']['search'];
      unset($options['query']['search']);
      $options['fragment'] = 'article-search-result-' . $article->id();
      $actions[] = [
        'url' => Url::fromRoute('ebms_article.search_form', ['search_id' => $search_id]),
        'label' => 'Refine Search',
        'attributes' => ['title' => 'Return to the current search form.'],
      ];
      $actions[] = [
        'url' => Url::fromRoute('ebms_article.search_results', ['request_id' => $search_id], $options),
        'label' => 'Search Results',
        'attributes' => ['title' => 'Return to the current search results.'],
      ];
    }
    if (!empty($options['query']['queue'])) {
      $queue_id = $options['query']['queue'];
      unset($options['query']['queue']);
      $options['fragment'] = 'review-queue-article-' . $article->id();
      $actions[] = [
        'url' => Url::fromRoute('ebms_review.review_queue', ['queue_id' => $queue_id], $options),
        'label' => 'Review Queue',
        'attributes' => ['title' => 'Return to the review queue.'],
      ];
    }
    ebms_debug_log('returning ' . count($actions) . ' action buttons', 3);
    return $actions;
  }

  /**
   * Assemble the values about the article's full-text PDF file.
   *
   * @param \Drupal\ebms_article\Entity\Article $article
   *   Article entity for which the information is collected.
   * @return array
   *   Values used to display the name and link of the file, or
   *   (if appropriate), information about unavailability of the file.
   */
  private function getArticleFullTextInformation(Article $article): array {
    $ft_filename = $ft_url = $ft_user = $ft_date = $ft_note = NULL;
    if (!empty($article->full_text->file)) {
      $file = File::load($article->full_text->file);
      $ft_url = $file->createFileUrl();
      $ft_filename = $file->getFilename();
      $ft_user = $file->uid->entity->getDisplayName();
      $ft_date = date('Y-m-d', $file->created->value);
    }
    elseif ($article->full_text->unavailable) {
      $ft_date = $article->full_text->flagged_as_unavailable;
      $user = $this->userStorage->load($article->full_text->flagged_by);
      $ft_user = $user->name->value;
      $ft_note = $article->full_text->notes;
    }
    ebms_debug_log('returning article full text information', 3);
    return [
      'name' => $ft_filename,
      'url' => $ft_url,
      'user' => $ft_user,
      'date' => $ft_date,
      'note' => $ft_note,
    ];
  }

  /**
   * Get the values for the article's IDs.
   *
   * @param \Drupal\ebms_article\Entity\Article $article
   *   Article entity for which the information is collected.
   * @return array
   *   The IDs to be displayed for the article.
   */
  private function getArticleIds(Article $article): array {
    $pmid = $article->source_id->value;
    $uri = "https://pubmed.ncbi.nlm.nih.gov/$pmid";
    $options = [
      'attributes' => [
        'target' => '_blank',
        'title' => 'View abstract in a separate browser tab.',
      ],
    ];
    $url = Url::fromUri($uri, $options);
    ebms_debug_log('returning article IDs', 3);
    return [
      'ebms' => $article->id(),
      'pubmed' => Link::fromTextAndUrl($pmid, $url),
      'legacy' => $article->legacy_id->value,
    ];
  }

  /**
   * Collect the information about tags assigned to the article.
   *
   * This handles both tags which are attached directly to the article,
   * as well as topic-specific tags.
   *
   * @param object $article_or_topic
   *   Article or topic entity for which the information is collected.
   * @param int $article_id
   *   Needed for forms to be able to return to this page.
   * @return array
   *   Values used for the article tag display.
   */
  private function getArticleTags(object $article_or_topic, int $article_id) {
    ebms_debug_log('top of ArticleController::getArticleTags()', 3);
    $tags = [];
    foreach ($article_or_topic->get('tags') as $tag) {
      $tag = $tag->entity;
      $comments = [];
      foreach ($tag->get('comments') as $comment) {
        $user = $this->userStorage->load($comment->user);
        $comments[] = [
          // Don't know why 'user' => $comment->user->entity->getDisplayName(),
          // does not work, when the similar construction immediately below for
          // the tag user (in fact, the same user) works perfectly.
          'user' => $user->name->value,
          'entered' => $comment->entered, //->value,
          'body' => $comment->body,
        ];
      }
      $route = 'ebms_article.add_article_tag_comment';
      $parms = ['tag_id' => $tag->id()];
      $opts = ['query' => $this->currentRequest->query->all()];
      $opts['query']['article'] = $article_id;
      $add_comment = Url::fromRoute($route, $parms, $opts);
      $inactivate = '';
      if (!empty($tag->active->value)) {
        $route = 'ebms_article.inactivate_article_tag';
        $parms = ['ebms_article_tag' => $tag->id()];
        $inactivate = Url::fromRoute($route, $parms, $opts);
      }
      $tags[] = [
        'name' => $tag->tag->entity->getName(),
        'user' => $tag->user->entity->getDisplayName(),
        'active' => $tag->active->value,
        'assigned' => $tag->assigned->value,
        'comments' => $comments,
        'add_comment' => $add_comment,
        'inactivate' => $inactivate,
      ];
    }
    ebms_debug_log('returning ' . count($tags) . ' article tags', 3);
    return $tags;
  }

  /**
   * Collect the tags marking this article as not for board member review.
   *
   * @param \Drupal\ebms_article\Entity\Article $article
   *   Article entity for which the information is collected.
   * @return array
   *   Values to be plugged into the render array for the article's page.
   */
  private function getInternalTags(Article $article) {
    $internal_tags = [];
    foreach ($article->get('internal_tags') as $tag) {
      $term = $this->termStorage->load($tag->tag);
      $internal_tags[] = [
        'name' => $term->name->value,
        'assigned' => $tag->added,
      ];
    }
    ebms_debug_log('returning ' . count($internal_tags) . ' internal tags', 3);
    return $internal_tags;
  }

  /**
   * Collect comments about the article's designation for internal use only.
   *
   * Note that these comments are stored independently of the individual
   * internal tags, so we collect them separately.
   *
   * @param \Drupal\ebms_article\Entity\Article $article
   *   Article entity for which the information is collected.
   * @return array
   *   Values to be plugged into the render array for the article's page.
   */
  private function getInternalComments(Article $article): array {
    $values = [];
    $comments = $article->get('internal_comments');
    if ($comments->count() > 0) {
      $parms = ['article_id' => $article->id()];
      $opts = ['query' => $this->currentRequest->query->all()];
      foreach ($comments as $delta => $comment) {
        // Delta is passed as 1-based, even though Drupal has them as 0-based,
        // because of PHP's very odd behavior with `empty("0")`.
        $opts['query']['delta'] = $delta + 1;
        $values[] = [
          'user' => $this->userStorage->load($comment->user)->name->value,
          'entered' => $comment->entered,
          'body' => $comment->body,
          'edit' => Url::fromRoute('ebms_article.edit_internal_comment', $parms, $opts),
          'delete' => Url::fromRoute('ebms_article.delete_internal_comment', $parms, $opts),
        ];
      }
    }
    ebms_debug_log('returning ' . count($values) . ' internal comments', 3);
    return $values;
  }

  /**
   * Collect and organize the article's per-topic state information.
   *
   * @param \Drupal\ebms_article\Entity\Article $article
   *   Article entity for which the information is collected.
   *
   * @return array
   *   Render arrays indexed by board, and then by topic.
   */
  private function getStateRenderArrays(Article $article): array {

    // Ensure that this index starts fresh.
    ebms_debug_log('top of ArticleController::getStateRenderArrays()', 3);
    $this->topicBoards = [];

    // Populate the controller's `states` property.
    $this->collectImportEvents($article);
    ebms_debug_log('import events collected', 3);
    $this->collectStateInformation($article);
    ebms_debug_log('state information collected', 3);
    // Suppressing this for now.
    // $this->collectTopicComments($article);
    // ... to prevent duplicate display of these comments.
    $this->collectPacketInformation($article);
    ebms_debug_log('packet information collected', 3);

    // Walk through the sorted states and create the render arrays for each.
    ksort($this->states);
    ebms_debug_log('found ' . count($this->states) . ' sets of states', 3);
    $render_arrays = [];
    foreach ($this->states as $board_name => $topics) {

      // Create a collapsible block for the board's topics.
      ebms_debug_log("board $board_name has " . count($topics) . ' topics', 3);
      $board = [
        '#type' => 'details',
        '#open' => FALSE,
        '#title' => $board_name,
        'board-topics' => [],
      ];
      ksort($topics);

      // Add nested blocks for each of the board's topics.
      foreach ($topics as $topic_name => $states_for_topic) {
        usort($states_for_topic, function ($a, $b) {
          return $a['#date'] <=> $b['#date'];
        });
        $flag = self::FLAGS['yes'];
        if (!empty($states_for_topic)) {
          $last_state = $states_for_topic[count($states_for_topic) - 1];
          $legend = $last_state['#legend'] ?? 'yes';
          if (!empty($legend) && $legend !== 'yes' || !empty($last_state['#terminal'])) {
            $flag = self::FLAGS[$legend];
          }
          else {
            $flag = self::FLAGS['ongoing'];
          }
        }

        // For the topic element, a '#type' key has been added, because
        // without it, the `devel` module dumps a stack trace complaining
        // that it's missing. If we use '#type' *instead* of '#theme' the
        // stack trace goes away, but so does the display for the topic's
        // states. So we've got both of them there. Unfortunately, the
        // documentation on what the difference is between the two keys
        // is more than a little vague.
        // See https://www.drupal.org/project/drupal/issues/1382350 and
        // https://www.drupal.org/project/ideas/issues/2702061, for starters.
        // Note that although the first ticket was opened in 2011, and the
        // second was opened in 2016 (and is still open as of late 2021),
        // the first was closed because it was allegedly a "duplicate" of
        // the second ticket. It's not clear to me how something can duplicate
        // something else which doesn't yet exist, but this mislabeling is a
        // common practice in the Drupal community.
        $article_topic = $article->findArticleTopic($board_name, $topic_name);
        if (empty($article_topic)) {
            $message = "Unable to find article-topic for $board_name/$topic_name.";
            ebms_debug_log($message);
            $this->messenger->addWarning($message);
            continue;
        }
        $topic_id = $article_topic->topic->target_id;
        $options = ['query' => $this->currentRequest->query->all()];
        $add_tag_opts = $options;
        $add_tag_opts['query']['topic'] = $topic_id;
        $parms = ['article_id' => $article->id()];
        $add_state_parms = $parms;
        $add_state_parms['article_topic_id'] = $article_topic->id();
        $actions = [
          [
            'url' => Url::fromRoute('ebms_article.add_article_tag', $parms, $add_tag_opts),
            'label' => 'Add Tag',
            'attributes' => ['title' => 'Assign a new tag to the article for this topic.'],
          ],
          [
            'url' => Url::fromRoute('ebms_article.add_new_state', $add_state_parms, $options),
            'label' => 'Add State',
            'attributes' => ['title' => 'Assign a new state to the article for this topic.'],
          ],
        ];
        $comments = [];
        $parms = ['article_topic_id' => $article_topic->id()];
        foreach ($article_topic->comments as $delta => $modifiable_comment) {
          $opts = $options;
          $opts['query']['article'] = $article->id();
          // Delta is passed as 1-based, even though Drupal implements them as
          // 0-based, because of PHP's very odd behavior with `empty("0")`.
          // We adjust back down in the form's code.
          $opts['query']['delta'] = $delta + 1;
          $body = $modifiable_comment->comment;
          $comment_id = $modifiable_comment->target_id;
          $route = 'ebms_article.edit_manager_topic_comment';
          $edit = Url::fromRoute($route, $parms, $opts);
          $route = 'ebms_article.delete_manager_topic_comment';
          $delete = Url::fromRoute($route, $parms, $opts);
          $comments[] = [
            'edit' => $edit,
            'delete' => $delete,
            'body' => $body,
          ];
        }
        if (empty($comments)) {
          $route = 'ebms_article.add_manager_topic_comment';
          $opts = $options;
          $opts['query']['article'] = $article->id();
          $parms = ['article_topic_id' => $article_topic->id()];
          $actions[] = [
            'url' => Url::fromRoute($route, $parms, $opts),
            'label' => 'Add Board Manager Comment',
            'attributes' => ['title' => 'Add a topic-specific comment for this article'],
          ];
        }
        $board['board-topics'][] = [
          '#type' => 'details',
          '#title' => "$topic_name $flag",
          'topic' => [
            '#type' => 'topic_article_states',
            '#theme' => 'topic_article_states',
            '#topic' => $topic_name,
            '#tags' => $this->getArticleTags($article_topic, $article->id()),
            '#buttons' => [
              '#theme' => 'ebms_local_actions',
              '#actions' => $actions,
            ],
            '#comments' => $comments,
            '#states' => $states_for_topic,
            '#imports' => $this->actions[$topic_name] ?? [],
            '#cache' => ['max-age' => 0],
          ],
        ];
      }
      $render_arrays[] = $board;
    }
    ebms_debug_log('returning ' . count($render_arrays) . ' state render arrays', 3);
    return $render_arrays;
  }

}
