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
    $files = [];
    $entity_storage = $this->entityTypeManager->getStorage('file');
    /** @var \Drupal\search_api\Item\ItemInterface[] $items */
    foreach ($items as $item) {
      // This only affects to the SBF datasource as it probably multiple per
      // entity.
      if ($item->getDatasourceId() == 'strawberryfield_flavor_datasource') {
        $data = $item->getOriginalObject()->getValue();
        $files[$data['file_uuid']] = $data['file_uuid'];
      }
    }

    // There could be several different entities in a given batch.
    foreach ($files as $file_uuid) {
      $entity = $entity_storage->loadByProperties(['uuid' => $file_uuid]);
      if ($entity) {
        // This will clear the cache more times than we actually want (one per
        // batch iteration), but it's the best way at the moment as Search API
        // doesn't provide an event when ALL elements are indexed. We don't want
        // that event either because the index might not finish anyways.
        $entity = reset($entity);
        Cache::invalidateTags($entity->getCacheTagsToInvalidate());
      }
    }
  }

}
