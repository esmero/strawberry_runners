<?php

namespace Drupal\strawberry_runners\Plugin\search_api\processor;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes elements when indexing.
 *
 * @SearchApiProcessor(
 *   id = "strawberry_runners_processor",
 *   label = @Translation("Strawberry Runners index pocessor"),
 *   description = @Translation("Enables processing for strawberry runners, i.e. to clear entity cache when indexed"),
 *   stages = {
 *     "preprocess_index" = -10
 *   }
 * )
 */
class StrawberryRunnersIndexProcessor extends ProcessorPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|null
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $processor->setEntityTypeManager($container->get('entity_type.manager'));

    return $processor;
  }

  /**
   * Retrieves the entity type manager service.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager service.
   */
  public function getEntityTypeManager() {
    return $this->entityTypeManager ?: \Drupal::entityTypeManager();
  }

  /**
   * Sets the entity type manager service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   *
   * @return $this
   */
  public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $formState) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessIndexItems(array $items) {
    // When adding SBF flavor data in Solr, ensure that the cache is
    // invalidated so all the related information can be served fresh.
    $entities = [];
    $node_storage = $this->entityTypeManager->getStorage('node');
    /** @var \Drupal\search_api\Item\ItemInterface[] $items */
    foreach ($items as $item) {
      // This only affects to the SBF datasource as it probably multiple per
      // entity.
      if ($item->getDatasourceId() == 'strawberryfield_flavor_datasource') {
        $data = $item->getOriginalObject()->getValue();
        if (empty($entities[$data['target_id']])) {
          $entities[$data['target_id']] = $node_storage->load($data['target_id']);
        }
      }
    }

    // There could be several different entities in a given batch.
    foreach ($entities as $entity) {
      Cache::invalidateTags($entity->getCacheTagsToInvalidate());
    }
  }

}
