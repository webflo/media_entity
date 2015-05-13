<?php

/**
 * @file
 * Contains \Drupal\media_entity\Entity\Media.
 */

namespace Drupal\media_entity\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\media_entity\MediaInterface;

/**
 * Defines the media entity class.
 *
 * @ContentEntityType(
 *   id = "media",
 *   label = @Translation("Media"),
 *   bundle_label = @Translation("Media bundle"),
 *   handlers = {
 *     "storage" = "Drupal\media_entity\MediaStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "access" = "Drupal\media_entity\MediaAccessController",
 *     "form" = {
 *       "default" = "Drupal\media_entity\MediaForm",
 *       "delete" = "Drupal\media_entity\Form\MediaDeleteForm",
 *       "edit" = "Drupal\media_entity\MediaForm"
 *     },
 *     "translation" = "Drupal\content_translation\ContentTranslationHandler"
 *   },
 *   base_table = "media",
 *   data_table = "media_field_data",
 *   revision_table = "media_revision",
 *   revision_data_table = "media_field_revision",
 *   translatable = TRUE,
 *   render_cache = TRUE,
 *   entity_keys = {
 *     "id" = "mid",
 *     "revision" = "vid",
 *     "bundle" = "bundle",
 *     "label" = "name",
 *     "langcode" = "langcode",
 *     "uuid" = "uuid"
 *   },
 *   bundle_entity_type = "media_bundle",
 *   permission_granularity = "entity_type",
 *   admin_permission = "administer media",
 *   field_ui_base_route = "entity.media_bundle.edit_form",
 *   links = {
 *     "canonical" = "/media/{media}",
 *     "delete-form" = "/media/{media}/delete",
 *     "edit-form" = "/media/{media}/edit",
 *     "admin-form" = "/admin/structure/media/manage/{media_bundle}"
 *   }
 * )
 */
class Media extends ContentEntityBase implements MediaInterface {

  /**
   * Value that represents the media being published.
   */
  const PUBLISHED = 1;

  /**
   * Value that represents the media being unpublished.
   */
  const NOT_PUBLISHED = 0;

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getChangedTime() {
    return $this->get('changed')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isPublished() {
    return (bool) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setPublished($published) {
    $this->set('status', $published ? Media::PUBLISHED : Media::NOT_PUBLISHED);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPublisher() {
    return $this->get('uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getPublisherId() {
    return $this->get('uid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setPublisherId($uid) {
    $this->set('uid', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getType() {
    return $this->get('type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setType($type) {
    $this->set('type', $type);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    // If no owner has been set explicitly, make the current user the owner.
    if (!$this->getPublisher()) {
      $this->setPublisherId(\Drupal::currentUser()->id());
    }
    // If no revision author has been set explicitly, make the media owner the
    // revision author.
    if (!$this->get('revision_uid')->entity) {
      $this->set('revision_uid', $this->getPublisherId());
    }

    /** @var \Drupal\media_entity\MediaBundleInterface $bundle */
    $bundle = $this->entityManager()->getStorage('media_bundle')->load($this->bundle());

    // Set thumbnail.
    if (!$this->get('thumbnail')->entity) {
      $thumbnail_uri = $bundle->getType()->thumbnail($this);

      $existing = \Drupal::entityQuery('file')
        ->condition('uri', $thumbnail_uri)
        ->execute();

      if ($existing) {
        $this->thumbnail->target_id = reset($existing);
      }
      else {
        /** @var \Drupal\file\FileInterface $file */
        $file = $this->entityManager()->getStorage('file')->create(['uri' => $thumbnail_uri]);
        $file->setOwner($this->getPublisher());
        $file->setPermanent();
        $file->save();
        $this->thumbnail->target_id = $file->id();
      }

      // TODO - We should probably use something smarter (tokens, ...).
      $this->thumbnail->alt = t('Thumbnail');
      $this->thumbnail->title = $this->label();
    }

    // Try to set fields provided by type plugin and mapped in bundle
    // configuration.
    foreach ($bundle->field_map as $source_field => $destination_field) {
      // Only save value in entity field if empty. Do not overwrite existing data.
      // @TODO We might modify that in the future but let's leave it like this
      // for now.
      if ($this->hasField($destination_field) && $this->{$destination_field}->isEmpty() && ($value = $bundle->getType()->getField($this, $source_field))) {
        $this->set($destination_field, $value);
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function preSaveRevision(EntityStorageInterface $storage, \stdClass $record) {
    parent::preSaveRevision($storage, $record);

    if (!$this->isNewRevision() && isset($this->original) && (!isset($record->revision_log) || $record->revision_log === '')) {
      // If we are updating an existing node without adding a new revision, we
      // need to make sure $entity->revision_log is reset whenever it is empty.
      // Therefore, this code allows us to avoid clobbering an existing log
      // entry with an empty one.
      $record->revision_log = $this->original->revision_log->value;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields['mid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Media ID'))
      ->setDescription(t('The media ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The media UUID.'))
      ->setReadOnly(TRUE);

    $fields['vid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Revision ID'))
      ->setDescription(t('The media revision ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['bundle'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Bundle'))
      ->setDescription(t('The media bundle.'))
      ->setSetting('target_type', 'media_bundle')
      ->setReadOnly(TRUE);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language code'))
      ->setDescription(t('The media language code.'))
      ->setRevisionable(TRUE);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Media name'))
      ->setDescription(t('The name of this media.'))
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDefaultValue('')
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', array(
        'type' => 'string_textfield',
        'weight' => -5,
      ))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ))
      ->setDisplayConfigurable('view', TRUE);

    $fields['thumbnail'] = BaseFieldDefinition::create('image')
      ->setLabel(t('Thumbnail'))
      ->setDescription(t('The thumbnail of the media.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', array(
        'type' => 'image',
        'weight' => 1,
        'label' => 'hidden',
        'settings' => array(
          'image_style' => 'thumbnail',
        ),
      ))
      ->setDisplayConfigurable('view', TRUE)
      ->setReadOnly(TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Publisher ID'))
      ->setDescription(t('The user ID of the media publisher.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(0)
      ->setSetting('target_type', 'user')
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'author',
        'weight' => 0,
      ))
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Publishing status'))
      ->setDescription(t('A boolean indicating whether the media is published.'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the media was created.'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'timestamp',
        'weight' => 0,
      ))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', array(
        'type' => 'datetime_timestamp',
        'weight' => 10,
      ))
      ->setDisplayConfigurable('form', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the media was last edited.'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE);

    $fields['type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Type'))
      ->setDescription(t('The type of this media.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE);

    $fields['revision_timestamp'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Revision timestamp'))
      ->setDescription(t('The time that the current revision was created.'))
      ->setQueryable(FALSE)
      ->setRevisionable(TRUE);

    $fields['revision_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Revision publisher ID'))
      ->setDescription(t('The user ID of the publisher of the current revision.'))
      ->setSetting('target_type', 'user')
      ->setQueryable(FALSE)
      ->setRevisionable(TRUE);

    $fields['revision_log'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Revision Log'))
      ->setDescription(t('The log entry explaining the changes in this revision.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE);

    return $fields;
  }

}
