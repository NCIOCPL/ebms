<?php

namespace Drupal\ebms_review\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Bundle of articles to be reviewed for a given topic.
 *
 * Article/topic combinations which have passed the board manager full-text
 * review are eligible to be assigned to board members for review. Review
 * packets are created for this purpose, with each packet containing articles
 * to be reviewed for a specific topic by one or more board member reviewers.
 * The packets are typically created by the board managers.
 *
 * @ingroup ebms
 *
 * @ContentEntityType(
 *   id = "ebms_packet",
 *   label = @Translation("Packet"),
 *   base_table = "ebms_packet",
 *   admin_permission = "access ebms article overview",
 *   handlers = {
 *     "form" = {
 *       "archive" = "Drupal\ebms_review\Form\ArchivePacket",
 *     },
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "published" = "active",
 *   },
 * )
 */
class Packet extends ContentEntityBase implements ContentEntityInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['topic'] = BaseFieldDefinition::create('entity_reference')
      ->setRequired(TRUE)
      ->setDescription('Entity reference for the Topic for which these articles are to be reviewed.')
      ->setSetting('target_type', 'ebms_topic');
    $fields['created_by'] = BaseFieldDefinition::create('entity_reference')
      ->setRequired(TRUE)
      ->setDescription('Board manager (or other user) who created this review packet.')
      ->setSetting('target_type', 'user');
    $fields['created'] = BaseFieldDefinition::create('datetime')
      ->setDescription('When the packet was created.')
      ->setRequired(TRUE);
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setDescription('Display name for the packet.')
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 255]);
    $fields['articles'] = BaseFieldDefinition::create('entity_reference')
      ->setRequired(TRUE)
      ->setDescription('Entity references for the information about the articles in this packet.')
      ->setSetting('target_type', 'ebms_packet_article')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $fields['reviewers'] = BaseFieldDefinition::create('entity_reference')
      ->setRequired(TRUE)
      ->setDescription('Board members assigned to review the articles in the packet.')
      ->setSetting('target_type', 'user')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $fields['last_seen'] = BaseFieldDefinition::create('datetime')
      ->setDescription("When the manager of the packet's board last viewed it.");
    $fields['active'] = BaseFieldDefinition::create('boolean')
      ->setDescription("If FALSE don't bother soliciting any more reviews.")
      ->setDefaultValue(TRUE);
    $fields['starred'] = BaseFieldDefinition::create('boolean')
      ->setDescription('Flag to let the board manager know which packets she needs to come back to.');
    $fields['summaries'] = BaseFieldDefinition::create('entity_reference')
      ->setSetting('target_type', 'ebms_doc')
      ->setDescription('Documents (usually summaries) associated with the packet by the board manager.')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $fields['reviewer_docs'] = BaseFieldDefinition::create('entity_reference')
      ->setSetting('target_type', 'ebms_reviewer_doc')
      ->setDescription('Documents attached to the packet by the reviewers.')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    return $fields;
  }

  /**
   * Find the articles in the packet which haven't been dropped.
   *
   * These are `Article` entities, not `PacketArticle` entities being returned.
   *
   * @return array
   *   `Article` entities not dropped from the packet.
   */
  public function activeArticles() {
    $articles = [];
    foreach ($this->articles as $article) {
      $packet_article = $article->entity;
      if (empty($packet_article->dropped->value)) {
        $article = $packet_article->article->entity;
        $articles[$article->id()] = $article;
      }
    }
    return $articles;
  }

  /**
   * Determine how many articles await review by specified user.
   *
   * Used by reviewer's home page and by the login report, so
   * hoisted out here for common usage.
   *
   * To count unreviewed articles, we need to know:
   *    1.  is the article in a packet assigned to this board member?
   *    2.  is the packet still active?
   *    3.  has the board member already posted a review for the article?
   *    4.  is the article marked FYI for this topic?
   *    5.  is the current state's seq# for this topic no higher than that
   *        assigned to the passed_full_review state?
   * (#4 and #5 added by OCEEBMS-402)
   *
   * The last two conditions are handled directly in the entity query created
   * here. The first three are taken care of by the hook implementation to
   * alter the query, to get around limitations of the entity query API.
   *
   * Note that this logic is not perfectly aligned with the logic for
   * figuring out which packets to display for the board member's "Assigned
   * Packets" page, which only excludes the FYI and Final board decision
   * states. This means that a board member's home page can say "You have
   * 0 articles assigned for review" but the "Assigned Packets" page might
   * have one or packets with articles available for review.
   * @todo Ask the users some day if this discrepancy is intentional.
   *
   * @param int $user_id
   *   ID of user for whom we are calculating the total.
   *
   * @return int
   *   Number of unreviewed articles.
   */
  public static function getReviewerArticleCount(int $user_id): int {
    $term_lookup = \Drupal::service('ebms_core.term_lookup');
    static $max_sequence = NULL;
    static $passed_full_review = NULL;
    static $fyi = NULL;
    if (empty($passed_full_review)) {
      $passed_full_review = $term_lookup->getState('passed_full_review');
      $max_sequence = $passed_full_review->field_sequence->value;
    }
    if (empty($fyi)) {
      $fyi = $term_lookup->getState('fyi')->id();
    }
    $storage = \Drupal::entityTypeManager()->getStorage('ebms_article');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition('topics.entity.states.entity.current', 1);
    $query->condition('topics.entity.states.entity.value', $fyi, '<>');
    $query->condition('topics.entity.states.entity.value.entity.field_sequence', $max_sequence, '<=');
    $query->addTag('articles_awaiting_review');
    $query->addMetaData('uid', $user_id);
    return $query->count()->execute();
  }

  /**
   * Create a database query to find packets which a need review.
   *
   * @param $uid string
   *   ID of the board member whose unreviewed packets we want
   *
   * @return object
   *  Ddatabase query object.
   */
  public static function makeUnreviewedPacketsQuery($uid): object {
    $db = \Drupal::database();
    $subquery = $db->select('ebms_packet_article__reviews', 'reviews')->distinct();
    $subquery->addField('reviews', 'entity_id');
    $subquery->join('ebms_review', 'review', 'review.id = reviews.reviews_target_id');
    $subquery->condition('review.reviewer', $uid);
    $query = $db->select('ebms_packet', 'packet');
    $query->addField('packet', 'id', 'packet_id');
    $query->addExpression('MAX(packet.created)', 'created');
    $query->join('ebms_packet__reviewers', 'reviewers', 'reviewers.entity_id = packet.id');
    $query->join('ebms_packet__articles', 'articles', 'articles.entity_id = packet.id');
    $query->join('ebms_packet_article', 'article', 'article.id = articles.articles_target_id');
    $query->join('ebms_state', 'state', 'state.article = article.article AND state.topic = packet.topic');
    $query->join('taxonomy_term__field_text_id', 'state_id', 'state_id.entity_id = state.value');
    $query->where('packet.active = 1');
    $query->where('article.dropped = 0');
    $query->where('state.current = 1');
    $query->where("state_id.field_text_id_value NOT IN ('fyi', 'final_board_decision')");
    $query->condition('reviewers.reviewers_target_id', $uid);
    $query->condition('article.id', $subquery, 'NOT IN');
    $query->groupBy('packet.id');
    $query->orderBy('created', 'DESC');
    return $query;
  }

}
