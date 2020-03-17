<?php

namespace Drupal\strawberry_runners\Entity\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Config\Entity\DraggableListBuilder;
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
   *
   * Building the header and content lines for the SBR list.
   *
   * Calling the parent::buildHeader() adds a column for the possible actions
   * and inserts the 'edit' and 'delete' links as defined for the entity type.
   */
  public function buildHeader() {
    $header['id'] = $this->t('Post Processor Config ID');
    $header['label'] = $this->t('Label');
    $header['active'] = $this->t('Is active ?');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /* @var $entity \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity */
    $row['id'] = [ '#markup' => $entity->id() ];
    $row['label'] =  $entity->label();
    $row['active'] = $entity->isActive() ? [ '#markup' => $this->t('Yes')] : [ '#markup' =>$this->t('No')];

    return $row + parent::buildRow($entity);
  }


}
