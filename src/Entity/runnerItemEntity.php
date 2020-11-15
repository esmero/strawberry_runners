<?php

namespace Drupal\strawberry_runners\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use InvalidArgumentException;
use Drupal\strawberry_runners\Entity\runnerItemEntityInterface;
use Drupal\user\UserInterface;

/**
 * Defines the Runners Item Entity Content entity.
 *
 * @ingroup strawberry_runners
 *
 * This is the main definition of the entity type. From it, an entityType is
 * derived. The most important properties in this example are listed below.
 *
 * id: The unique identifier of this entityType. It follows the pattern
 * 'moduleName_xyz' to avoid naming conflicts.
 *
 * label: Human readable name of the entity type.
 *
 * handlers: Handler classes are used for different tasks. You can use
 * standard handlers provided by D8 or build your own, most probably derived
 * from the standard class. In detail:
 *
 * - view_builder: we use the standard controller to view an instance. It is
 *   called when a route lists an '_entity_view' default for the entityType
 *   (see routing.yml for details. The view can be manipulated by using the
 *   standard drupal tools in the settings.
 *
 * - list_builder: We derive our own list builder class from the
 *   entityListBuilder to control the presentation.
 *   If there is a view available for this entity from the views module, it
 *   overrides the list builder. @todo: any view? naming convention?
 *
 * - form: We derive our own forms to add functionality like additional fields,
 *   redirects etc. These forms are called when the routing list an
 *   '_entity_form' default for the entityType. Depending on the suffix
 *   (.add/.edit/.delete) in the route, the correct form is called.
 *
 * - access: Our own accessController where we determine access rights based on
 *   permissions.
 *
 * More properties:
 *
 *  - base_table: Define the name of the table used to store the data. Make sure
 *    it is unique. The schema is automatically determined from the
 *    BaseFieldDefinitions below. The table is automatically created during
 *    installation.
 *
 *  - fieldable: Can additional fields be added to the entity via the GUI?
 *    Analog to content types.
 *
 *  - entity_keys: How to access the fields. Analog to 'nid' or 'uid'.
 *
 *  - links: Provide links to do standard tasks. The 'edit-form' and
 *    'delete-form' links are added to the list built by the
 *    entityListController. They will show up as action buttons in an additional
 *    column.
 *
 * There are many more properties to be used in an entity type definition. For
 * a complete overview, please refer to the '\Drupal\Core\Entity\EntityType'
 * class definition.
 *
 * The following construct is the actual definition of the entity type which
 * is read and cached. Don't forget to clear cache after changes.
 *
 * @ContentEntityType(
 *   id = "runneritem_entity",
 *   label = @Translation("Runner Item"),
 *   label_collection = @Translation("Runner Items"),
 *   label_singular = @Translation("Runner Item"),
 *   label_plural = @Translation("Runner Items"),
 *   label_count = @PluralTranslation(
 *     singular = "@count runner item",
 *     plural = "@count runner items",
 *   ),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\strawberry_runners\Entity\Controller\runnerItemEntityListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "add" = "Drupal\strawberry_runners\Form\runnerItemEntityForm",
 *       "edit" = "Drupal\strawberry_runners\Form\runnerItemEntityForm",
 *       "delete" = "Drupal\strawberry_runners\Form\runnerItemEntityDeleteForm",
 *       "enqueue" = "Drupal\strawberry_runners\Form\runnerItemEntityEnqueueForm",
 *       "process" = "Drupal\strawberry_runners\Form\runnerItemEntityProcessForm"
 *     },
 *     "access" = "Drupal\strawberry_runners\runnerItemEntityAccessControlHandler",
 *   },
 *   base_table = "runneritem_entity",
 *   admin_permission = "administer runneritem entity",
 *   fieldable = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "setid" = "setid",
 *     "label" = "name",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "canonical" = "/strawberryrunneritem/{runneritem_entity}",
 *     "edit-form" = "/strawberryrunneritem/{runneritem_entity}/edit",
 *     "delete-form" = "/strawberryrunneritem/{runneritem_entity}/delete",
 *     "enqueue-form" = "/strawberryrunneritem/{runneritem_entity}/enqueue",
 *     "process-form" = "/strawberryrunneritem/{runneritem_entity}/process",
 *     "collection" = "/strawberryrunneritem/list"
 *   },
 *   field_ui_base_route = "strawberry_runners.runneritem_entity_settings",
 * )
 *
 * The 'links' above are defined by their path. For core to find the
 * route, the route name must follow the correct pattern:
 *
 * entity.<entity-name>.<link-name> (replace dashes with underscores)
 * Example: 'entity.content_entity_example_contact.canonical'
 *
 * See routing file above for the corresponding implementation
 *
 * This class defines methods and fields for the  Metadata Display Entity
 *
 * Being derived from the ContentEntityBase class, we can override the methods
 * we want. In our case we want to provide access to the standard fields about
 * creation and changed time stamps.
 *
 * MetadataDisplayInterface also exposes the EntityOwnerInterface.
 * This allows us to provide methods for setting and providing ownership
 * information.
 *
 * The most important part is the definitions of the field properties for this
 * entity type. These are of the same type as fields added through the GUI, but
 * they can by changed in code. In the definition we can define if the user with
 * the rights privileges can influence the presentation (view, edit) of each
 * field.
 */
class runnerItemEntity extends ContentEntityBase implements runnerItemEntityInterface{

  // Implements methods defined by EntityChangedInterface.
  use EntityChangedTrait;

  public function processItem() {
    // TODO: Implement processItem() method.
  }

  public function enqueueItem() {
    // TODO: Implement enqueueItem() method.
  }

  public function getNextSetId() {
    // TODO: Implement getNextSetId() method.
  }


  /**
   * {@inheritdoc}
   *
   * When a new entity instance is added, set the user_id entity reference to
   * the current user as the creator of the instance.
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values) {
    parent::preCreate($storage_controller, $values);
    $values += [
      'user_id' => \Drupal::currentUser()->id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('user_id')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->get('user_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('user_id', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('user_id', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Define the field properties here.
   *
   * Field name, type and size determine the table structure.
   *
   * In addition, we can define how the field and its content can be manipulated
   * in the GUI. The behaviour of the widgets used can be determined here.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {

    // Standard field, used as unique if primary index.
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The ID of the Strawberry Runner Item.'))
      ->setReadOnly(TRUE);
    // Automatic set by a processor, should not be user editable.
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The label of the Strawberry Runner Item.'))
      ->setRevisionable(FALSE)
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);
    // Standard field, used as unique if primary index.
    $fields['setid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('SetId'))
      ->setDescription(t('The UUID of the set this item belongs to.'))
      ->setReadOnly(TRUE);

    // Standard field, unique outside of the scope of the current project.
    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The UUID of the Strawberry Runner Item.'));
    // Standard field, unique outside of the scope of the current project.
    $fields['node_uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('The UUID of the ADO this item is acting on'))
      ->setDescription(t('The UUID of an ADO this Strawberry Runner Item is processing data for. Can be existing or future too.'))
      ->setReadOnly(TRUE);

    // Holds the actual Twig template.
    // @TODO see https://twig.symfony.com/doc/2.x/api.html#sandbox-extension
    $fields['data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('The Post Processor data needed to process an Item'))
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'text_plain',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'settings' => [
          'text_processing' => FALSE,
          'rows' => 10,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE)
      ->addConstraint('NotBlank');

    // Owner field of the Metadata Display Entity.
    // Entity reference field, holds the reference to the user object.
    // The view shows the user name field of the user.
    // The form presents a auto complete field for the user name.
    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User Name'))
      ->setDescription(t('The Name of the associated user.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the Runner Item was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the Runner Item was last edited.'));

    $fields['postprocessor'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Post processor Plugin'))
      ->setCardinality(1)
      ->setDescription(t('The roles the user has.'))
      ->setSetting('target_type', 'strawberry_runners_postprocessor')
      ->setDisplayConfigurable('view', TRUE);
    // Standard field, used as unique if primary index.
    $fields['retries'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Number of attempts made to run this'))
      ->setDescription(t('Keeps track of the number of previous attemps on running this processor that were made. 0 means it has never been executed.'))
      ->setReadOnly(TRUE);
    // What type of output is expected from the twig template processing.
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('This Item last known status'))
      ->setDescription(t('Failed Postprocessor Items will get automatic "failed" status, "done" status will mark this ones for removal'))
      ->setSettings([
        'default_value' => 'pending',
        'max_length' => 64,
        'cardinality' => 1,
        'allowed_values' => [
          'pending' => 'pending',
          'failed' => 'failed',
          'done' => 'done',
          'retrying' => 'retrying',
        ],
      ])
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'region' => 'hidden',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->addConstraint('NotBlank');

    return $fields;
  }

}
