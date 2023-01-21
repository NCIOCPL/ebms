<?php

namespace Drupal\ebms_article\Form;

use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Form for inactivating an article tag.
 *
 * The user interface presents this as a deletion, but behind the scenes
 * the software retains the entity and marks it as deactivated. The users
 * indicated they might want to see when relationships were "deleted" and
 * by whom at some point in the future.
 *
 * See https://tracker.nci.nih.gov/browse/OCEEBMS-706.
 */
class ArticleTagInactivationForm extends ContentEntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return 'Inactivate this tag?';
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $tag = htmlspecialchars($this->entity->tag->entity->name->value);
    return "Inactivating tag <em>$tag</em>. This action cannot be undone.";
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return 'Inactivate';
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $query = $this->getRequest()->query->all();
    $article_id = $query['article'];
    unset($query['article']);
    $opts = ['query' => $query];
    $parms = ['article' => $article_id];
    return Url::fromRoute('ebms_article.article', $parms, $opts);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $tag = htmlspecialchars($this->entity->tag->entity->name->value);
    $this->entity->active = FALSE;
    $this->entity->save();
    $this->messenger()->addMessage($this->t('Inactivated tag <em>@tag</em>.', ['@tag' => $tag]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
