<?php

namespace Drupal\strawberry_runners\Entity\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;



/**
 * Provides a list controller for the MetadataDisplay entity.
 *
 * @ingroup format_strawberryfield
 */
class strawberryRunnerPostProcessorEntityListBuilder extends DraggableListBuilder {

  protected $entitiesKey = 'strawberry_runners_postprocessor';

  /**
   * Name of the entity's depth field.
   *
   * @var string
   */
  protected $depthKey = 'depth';


  /**
   * Name of the entity's depth field.
   *
   * @var string|bool
   */
  protected $parentKey = 'parent';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'runnerpostprocessor_list';
  }

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new strawberryRunnerPostProcessorEntityListBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage class.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, ConfigFactoryInterface $config_factory, MessengerInterface $messenger) {
    parent::__construct($entity_type, $storage);

    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('config.factory'),
      $container->get('messenger')
    );
  }
  /**
   * {@inheritdoc}
   *
   * We override ::render() so that we can add our own content above the table.
   * parent::render() is where EntityListBuilder creates the table using our
   * buildHeader() and buildRow() implementations.
   */
  public function render() {
    $build['description'] = [
      '#markup' => $this->t(
        'Strawberry Runners Module implements Post processor Plugins that enhance Metadata or do fun things with Files present in each Node that contains a Strawberryfield type of field (ADO).'
      ),
    ];

    $build += parent::render();
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function load() {
    $entity_ids = $this->getEntityIds();
    /* @var $entities strawberryRunnerPostprocessorEntityInterface[] */
    $entities = $this->storage->loadMultipleOverrideFree($entity_ids);

    // Sort the entities using the entity class's sort() method.
    // See \Drupal\Core\Config\Entity\ConfigEntityBase::sort().
    uasort($entities, [$this->entityType->getClass(), 'sort']);
    foreach ($entities as $key => $entity) {
      $hash[$entity->id()] = $key;
    }
    $hash_inverse = array_flip($hash);

    $hierarchical = [];

    foreach ($hash as $entity_id => $original_numeric_id) {
      $id_of_parent = $entities[$original_numeric_id]->getParent();
      if ($id_of_parent !== '') {
          $hierarchical[$hash[$id_of_parent]] = $hierarchical[$hash[$id_of_parent]] ?? [];
          $hierarchical[$hash[$id_of_parent]][$hash[$entity_id]]  = $hash[$entity_id];
      }
      else {
        $hierarchical[$hash[$entity_id]] = $hierarchical[$hash[$entity_id]] ?? [];
      }
    }
    $added = [];
    foreach($hierarchical as $entity_id => $all_children) {
      $this->sortTreeByChildren($entity_id, $hierarchical, $added);
    }
    $added = array_flip($added);
    uksort($entities,
      function ($a, $b) use ($added) {
        return $added[$a] <=> $added[$b];
      });

    return $entities;
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $form[$this->entitiesKey]['#tabledrag'][] =
      [
        'action' => 'match',
        'relationship' => 'parent',
        'group' => 'tabledrag-postprocessor-parent',
        'subgroup' => 'tabledrag-postprocessor-parent',
        'source' => 'tabledrag-postprocessor-id',
        'hidden' => TRUE,
        'limit' => 2,
      ];
    $form[$this->entitiesKey]['#tabledrag'][] =
        [
          'action' => 'depth',
          'relationship' => 'group',
          'group' => 'tabledrag-postprocessor-depth',
          'hidden' => TRUE,
        ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Building the header and content lines for the SBR list.
   *
   * Calling the parent::buildHeader() adds a column for the possible actions
   * and inserts the 'edit' and 'delete' links as defined for the entity type.
   */
  public function buildHeader() {
    $header['title'] = $this->t('Post Processor Label');
    $header['id'] = $this->t('ID');
    $header['parent'] = $this->t('Parent');
    $header['depth'] = $this->t('Depth');
    $header['active'] = $this->t('Is active ?');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /* @var $entity \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity */
    $row['title'] = [
        'indentation' => [
          '#theme' => 'indentation',
          '#size' => $entity->getDepth(),
        ],
        '#plain_text' => $entity->label(),
    ];
    $row['id'] = [
      'hidden' => [
      '#type' => 'hidden',
      '#value' => $entity->id(),
        '#parents' => [$this->entitiesKey, $entity->id(), 'id'],
      '#attributes' => ['class' => ['tabledrag-postprocessor-id']],
        ],
      'text' => [
        '#markup' =>  $entity->id(),
      ]
    ];
    $row['parent'] =  [
      'hidden' => [
          '#type' => 'hidden',
          '#default_value' => $entity->getParent(),
          '#parents' => [$this->entitiesKey, $entity->id(), 'parent'],
          '#attributes' => ['class' => ['tabledrag-postprocessor-parent']],
      ],
      'text' => [
            '#markup' => $entity->getParent(),
      ]
    ];
    $row['depth'] = [
      'hidden' => [
        '#type' => 'hidden',
        '#default_value' =>  $entity->getDepth(),
        '#parents' => [$this->entitiesKey, $entity->id(), 'depth'],
        '#attributes' => ['class' => ['tabledrag-postprocessor-depth']],
        ],
       'text' => [
      '#markup' =>  $entity->getDepth(),
    ]
    ];
    $row['active'] = $entity->isActive() ? [ '#markup' => $this->t('Yes')] : [ '#markup' =>$this->t('No')];

    return $row + parent::buildRow($entity);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    foreach ($form_state->getValue($this->entitiesKey) as $id => $value) {
      if (isset($this->entities[$id]) && (
        $this->entities[$id]->get($this->weightKey) != $value['weight'] ||
        $this->entities[$id]->getDepth() != $value['depth'] ||
        $this->entities[$id]->getParent() != $value['parent']
        )
      ) {
        // Save entity only when its weight or depth or parent was changed.
        $this->entities[$id]->set($this->weightKey, $value['weight']);
        $this->entities[$id]->setDepth($value['depth']);
        $this->entities[$id]->setParent($value['parent']);
        $this->entities[$id]->save();
      }
    }
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state); // TODO: Change the autogenerated stub
  }

  protected function sortTreeByChildren($row_id, $tree, &$ordered) {
    if (isset($tree[$row_id]) && !in_array($row_id, $ordered)) {
      $subtree[] = $row_id;
      $offset = NULL;
      foreach ($tree[$row_id] as $child_id) {
        if (in_array($child_id, $ordered)) {
          // When a child is found, we don't add it again to the main order.
          // Maybe we should delete it?
          $child_offset = array_search($child_id, $ordered, TRUE);
          // When multiple offsets are present we take the one that inserts the subtree earlier.
          if ($offset !== NULL) {
            $offset = min($offset, $child_offset);
          }
          else {
            $offset = $child_offset;
          }
        }
        else {
          // Only add of course if not there already.
          $subtree[] = $child_id;
        }
      }
      if ($offset !== NULL) {
        array_splice($ordered, $offset,0, $subtree);
      }
      else {
        $ordered = array_merge($ordered, $subtree);
      }
    }
  }


}
