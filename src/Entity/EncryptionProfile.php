<?php

/**
 * @file
 * Contains Drupal\encrypt\Entity\EncryptionProfile.
 */

namespace Drupal\encrypt\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Component\Utility\Random;
use Drupal\encrypt\EncryptionProfileInterface;
use Drupal\encrypt\Exception\EncryptException;
use Drupal\encrypt\EncryptionMethodInterface;
use Drupal\key\Entity\Key;

/**
 * Defines the EncryptionProfile entity.
 *
 * @ConfigEntityType(
 *   id = "encryption_profile",
 *   label = @Translation("Encryption Profile"),
 *   handlers = {
 *     "list_builder" = "Drupal\encrypt\Controller\EncryptionProfileListBuilder",
 *     "form" = {
 *       "add" = "Drupal\encrypt\Form\EncryptionProfileForm",
 *       "edit" = "Drupal\encrypt\Form\EncryptionProfileForm",
 *       "delete" = "Drupal\encrypt\Form\EncryptionProfileDeleteForm",
 *       "default" = "Drupal\encrypt\Form\EncryptionProfileDefaultForm"
 *     }
 *   },
 *   config_prefix = "profile",
 *   admin_permission = "administer encrypt",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "canonical" = "/admin/config/system/encryption/profiles/{encryption_profile}",
 *     "add-form" = "/admin/config/system/encryption/profiles/add",
 *     "edit-form" = "/admin/config/system/encryption/profiles/manage/{encryption_profile}",
 *     "delete-form" = "/admin/config/system/encryption/profiles/manage/{encryption_profile}/delete",
 *     "collection" = "/admin/config/system/encryption/profiles"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "encryption_method",
 *     "encryption_key",
 *   }
 * )
 */
class EncryptionProfile extends ConfigEntityBase implements EncryptionProfileInterface {
  /**
   * The encryption profile ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The label.
   *
   * @var string
   */
  protected $label;

  /**
   * The ID of EncryptionMethod plugin.
   *
   * @var string
   */
  protected $encryption_method;

  /**
   * Stores a reference to the EncryptionMethod plugin for this profile.
   *
   * @var \Drupal\encrypt\EncryptionMethodInterface
   */
  protected $encryption_method_plugin;

  /**
   * The ID of Key entity.
   *
   * @var string
   */
  protected $encryption_key;

  /**
   * Stores a reference to the Key entity for this profile.
   *
   * @var \Drupal\key\Entity\Key.
   */
  protected $encryption_key_entity;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    $errors = $this->validate();
    if (!empty($errors)) {
      throw new EncryptException(implode(';', $errors));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEncryptionMethod() {
    if (!isset($this->encryption_method_plugin) || $this->encryption_method_plugin->getPluginId() != $this->getEncryptionMethodId()) {
      $this->encryption_method_plugin = $this->getEncryptionMethodManager()->createInstance($this->getEncryptionMethodId());
    }
    return $this->encryption_method_plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function getEncryptionMethodId() {
    return $this->encryption_method;
  }

  /**
   * {@inheritdoc}
   */
  public function setEncryptionMethod(EncryptionMethodInterface $encryption_method) {
    $this->encryption_method_plugin = $encryption_method;
    $this->encryption_method = $encryption_method->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function getEncryptionKey() {
    if (!isset($this->encryption_key_entity) || $this->encryption_key_entity->id() != $this->getEncryptionKeyId()) {
      $this->encryption_key_entity = $this->getKeyRepository()->getKey($this->getEncryptionKeyId());
    }
    return $this->encryption_key_entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getEncryptionKeyId() {
    return $this->encryption_key;
  }

  /**
   * {@inheritdoc}
   */
  public function setEncryptionKey(Key $key) {
    $this->encryption_key_entity = $key;
    $this->encryption_key = $key->id();
  }

  /**
   * {@inheritdoc}
   */
  public function validate() {
    $random = new Random();
    $errors = [];

    // Check if the object properties are set correctly.
    if (!$this->getEncryptionMethodId()) {
      $errors[] = t('No encryption method selected.');
    }

    if (!$this->getEncryptionKeyId()) {
      $errors[] = t('No encryption key selected.');
    }

    // If the properties are set, continue validation.
    if ($this->getEncryptionMethodId() && $this->getEncryptionKeyId()) {
      // Check if the linked encryption method is valid.
      $encryption_method_definition = $this->getEncryptionMethodManager()->getDefinition($this->getEncryptionMethodId());
      if (!$encryption_method_definition) {
        $errors[] = t('The encryption method linked to this encryption profile does not exist.');
      }

      // Check if the linked encryption key is valid.
      $selected_key = $this->getEncryptionKey();
      if (!$selected_key) {
        $errors[] = t('The key linked to this encryption profile does not exist.');
      }

      // If the encryption method and key are valid, continue validation.
      if (empty($errors)) {
        // Check if the selected key type matches encryption method settings.
        $allowed_key_types = $encryption_method_definition['key_type'];
        if (!empty($allowed_key_types)) {
          $selected_key_type = $selected_key->getKeyType();
          if (!in_array($selected_key_type->getPluginId(), $allowed_key_types)) {
            $errors[] = t('The selected key cannot be used with the selected encryption method.');
          }
        }
        // Check if encryption method dependencies are met.
        $encryption_method = $this->getEncryptionMethod();
        $dependency_errors = $encryption_method->checkDependencies($random->string(), $selected_key->getKeyValue());
        $errors = array_merge($errors, $dependency_errors);
      }
    }

    return $errors;
  }

  /**
   * Gets the encryption method manager.
   *
   * @return \Drupal\encrypt\EncryptionMethodManager
   *   The EncryptionMethodManager.
   */
  protected function getEncryptionMethodManager() {
    return \Drupal::service('plugin.manager.encrypt.encryption_methods');
  }

  /**
   * Gets the key repository service.
   *
   * @return \Drupal\Key\KeyRepository
   *   The Key repository service.
   */
  protected function getKeyRepository() {
    return \Drupal::service('key.repository');
  }

}
