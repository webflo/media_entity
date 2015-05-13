<?php

/**
 * @file
 * Contains Drupal\media_entity\MediaForm.
 */

namespace Drupal\media_entity;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the media edit forms.
 */
class MediaForm extends ContentEntityForm {

  /**
   * Default settings for this media bundle.
   *
   * @var array
   */
  protected $settings;

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\media_entity\Entity\Media
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  protected function prepareEntity() {
    $media = $this->entity;

    // If this is a new media, fill in the default values.
    if ($media->isNew()) {
      $media->setPublished(TRUE);
      $media->setPublisherId($this->currentUser()->id());
      $media->setCreatedTime(REQUEST_TIME);
    }
    else {
      // Remove the log message from the original media entity.
      $media->revision_log = NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Build the media object from the submitted values.
    parent::submitForm($form, $form_state);
    $media = $this->entity;

    // Save as a new revision if requested to do so.
    if (!$form_state->isValueEmpty('revision') && $form_state->getValue('revision') != FALSE) {
      $media->setNewRevision();
      // If a new revision is created, save the current user as revision author.
      $media->set('revision_timestamp', REQUEST_TIME);
      $media->set('revision_uid', $this->currentUser()->id());
    }
    else {
      $media->setNewRevision(FALSE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $media = $this->entity;
    $media->save();

    if ($media->id()) {
      $form_state->setValue('mid', $media->id());
      if ($media->access('view')) {
        $form_state->setRedirect('entity.media.canonical', ['media' => $media->id()]);
      }
      else {
        $form_state->setRedirect('<front>');
      }
    }
    else {
      // In the unlikely case something went wrong on save, the media will be
      // rebuilt and media form redisplayed the same way as in preview.
      drupal_set_message(t('The media could not be saved.'), 'error');
      $form_state->setRebuild();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validate(array $form, FormStateInterface $form_state) {
    parent::validate($form, $form_state);
    /** @var \Drupal\media_entity\MediaInterface $entity */
    $entity = $this->buildEntity($form, $form_state);
    /** @var \Drupal\media_entity\MediaInterface $entity_unchanged */
    $entity_unchanged = $this->entityManager->getStorage('media')->loadUnchanged($entity->id());

    if ($entity->id() && $entity_unchanged && $entity_unchanged->getChangedTime() > $entity->getChangedTime()) {
      $form_state->setErrorByName('changed', $this->t('The media on this page has either been modified by another user, or you have already submitted modifications using this form. As a result, your changes cannot be saved.'));
    }

    /** @var \Drupal\media_entity\MediaBundleInterface $bundle */
    $bundle = $this->entityManager->getStorage('media_bundle')->load($entity->bundle());
    if ($type = $bundle->getType()) {
      try {
        $type->validate($entity);
      }
      catch (MediaTypeException $e) {
        $form_state->setErrorByName($e->getElement(), $e->getMessage());
      }
    }

  }

}
