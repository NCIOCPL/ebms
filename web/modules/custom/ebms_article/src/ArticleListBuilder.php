<?php

namespace Drupal\ebms_article;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a class to build a listing of EBMS articles.
 *
 * @ingroup ebms
 */
final class ArticleListBuilder extends EntityListBuilder {

  /**
   * The current page requests.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * Object for creating a Drupal form.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $type_id = $container->get('entity_type.manager')->getStorage($entity_type->id());
    $instance = new static($entity_type, $type_id);
    $instance->currentRequest = $container->get('request_stack')->getCurrentRequest();
    $instance->formBuilder = $container->get('form_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('Article');
    $header['source-id'] = $this->t('PMID');
    $header['citation'] = $this->t('Citation');
    $header['import-date'] = $this->t('Imported');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build['form'] = $this->formBuilder->getForm('Drupal\ebms_article\Form\ArticleListForm');
    $build += parent::render();
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string {
    // Broken, I think. https://www.drupal.org/project/drupal/issues/2892334.
    return $this->t('Articles');
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityIds() {
    $storage = $this->getStorage();
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->sort('import_date', 'DESC');
    $parms = $this->currentRequest->query;
    $board = $parms->get('board');
    $state = $parms->get('state');
    if (!empty($board) || !empty($state)) {
      $query->condition('topics.entity.states.entity.current', 1);
      if (!empty($board)) {
        $query->condition('topics.entity.states.entity.board', $board);
      }
      if (!empty($state)) {
        $query->condition('topics.entity.states.entity.value', $state);
      }
    }
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ebms_article\Entity\Article */
    $article = $entity;
    $row['id'] = $article->id();
    $row['source-id'] = $article->source_id->value;
    $row['citation'] = Link::createFromRoute(
      $article->getLabel(),
      'ebms_article.article',
      ['article' => $article->id()]
    );
    $row['import-date'] = $article->import_date->value;
    return $row + parent::buildRow($entity);
  }

}
