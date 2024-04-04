<?php

namespace Drupal\ebms_topic;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;

/**
 * Defines a class to build a listing of EBMS topics.
 *
 * @ingroup ebms
 */
final class ListBuilder extends EntityListBuilder {

  /**
   * The current page requests.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $type_id = $container->get('entity_type.manager')->getStorage($entity_type->id());
    $instance = new static($entity_type, $type_id);
    $instance->currentRequest = $container->get('request_stack')->getCurrentRequest();
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['board'] = $this->t('Board');
    $header['name'] = $this->t('Name');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $row['board'] = $entity->board->entity->name->value;
    $row['name'] = Link::createFromRoute(
      $entity->label(),
      'entity.ebms_topic.edit_form',
      ['ebms_topic' => $entity->id()]
    );
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityIds() {
    $storage = $this->getStorage();
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->sort('board.entity.name', 'ASC');
    $query->sort('name', 'ASC');
    $parms = $this->currentRequest->query;
    return $query->execute();
  }

}
