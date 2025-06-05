<?php

namespace Drupal\strawberry_runners\Plugin\Action;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\Utility;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\views\ViewExecutable;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionCompletedTrait;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\strawberryfield\Plugin\Action\StrawberryfieldJsonPatch;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsPreconfigurationInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an action that can Modify Entity attached SBFs via JSON Patch.
 *
 * @Action(
 *   id = "entity:sbr_flavordelete_action",
 *   action_label = @Translation("Delete and untrack Search API indexed Strawberry Flavors (e.g OCR) for Archipelago Digital Objects"),
 *   label = @Translation("Delete and untrack Search API indexed Strawberry Flavors (e.g OCR) for Archipelago Digital Objects"),
 *   category = @Translation("Strawberry Runners"),
 *   deriver = "Drupal\strawberry_runners\Plugin\Action\Derivative\EntitySbfActionDeriver",
 *   type = "node",
 *   confirm = "true"
 * )
 */
class StrawberryRunnersDeleteFlavors extends StrawberryfieldJsonPatch implements ViewsBulkOperationsActionInterface, ViewsBulkOperationsPreconfigurationInterface, PluginFormInterface {

  use ViewsBulkOperationsActionCompletedTrait;

  /**
   * Action context.
   *
   * @var array
   *   Contains view data and optionally batch operation context.
   */
  protected $context;

  /**
   * The processed view.
   *
   * @var \Drupal\views\ViewExecutable
   */
  protected $view;

  /**
   * The Strawberry Runners Utility Service.
   *
   * @var \Drupal\strawberry_runners\strawberryRunnerUtilityServiceInterface
   */
  protected $strawberryRunnerUtilityService;

  /**
   * Configuration array.
   *
   * @var array
   */
  protected $configuration;

  /**
   * The Strawberryfield Key value service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValue;


  public static function create(ContainerInterface $container,
    array $configuration, $plugin_id, $plugin_definition
  ) {
    $instance = parent::create(
      $container, $configuration, $plugin_id, $plugin_definition
    );
    $instance->strawberryRunnerUtilityService = $container->get(
      'strawberry_runner.utility'
    );
    $instance->keyValue = $container->get(
      'strawberryfield.keyvalue.database'
    );

    return $instance;
  }


  /**
   * {@inheritdoc}
   */
  public function setContext(array &$context):void {
    $this->context['sandbox'] = &$context['sandbox'];
    foreach ($context as $key => $item) {
      if ($key === 'sandbox') {
        continue;
      }
      $this->context[$key] = $item;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setView(ViewExecutable $view):void {
    $this->view = $view;
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $objects) {
    $results = [];
    foreach ($objects as $entity) {

      $results[] = $this->execute($entity);
    }
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $count = 0;
    $message = NULL;
    if ($entity) {
      if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
        $entity
      )
      ) {
        $filter = $this->configuration['plugins'] ?? [];
        $filter = array_filter($filter);
        if (!empty($filter)) {
          $count = $this->trackDeleted($entity, $filter);
          $message =  $this->t("We deleted and untracked @total Strawberry Flavors for @label",
            [
              '@total' =>$count,
              '@label' => $entity->label()
            ]
          );
        }
      }
    }

    return $message;
  }



  public function buildPreConfigurationForm(array $element, array $values, FormStateInterface $form_state):array {
    return $element;
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $active_plugins = $this->strawberryRunnerUtilityService->getActivePluginConfigs(FALSE);
    $options = [];
    foreach ($active_plugins as $source => $processors) {
      $options = array_merge($options, array_combine(array_keys($processors), array_keys($processors)));
    }

    $options = array_unique($options);
    $form['plugins'] = [
      '#type' => 'checkboxes',
      '#title' => t('Processors For which you want to delete generated Flavors from both tracker and Search API Index.'),
      '#default_value' => $this->configuration['plugins'] ?? [],
      '#options' => $options,
      '#description' => t('Warning, Danger ZONE! This action can not be undone and is permanent. Once deleted you can re-generate them but it might take long time. This action can only delete permanently Flavors that are already Indexed into your Saerch API Index. Please make sure your Index is up to date before running this or you might omit Flavors pending to be Indexed. Not all processors listed might have generated or even Generate Flavors. Please Check your settings.'),
    ];

    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['plugins'] = $form_state->getValue('plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {

  }


  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'plugins' => [],
      'force' => FALSE,
    ];
  }

  /**
   * Default custom access callback.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user the access check needs to be preformed against.
   * @param \Drupal\views\ViewExecutable $view
   *   The View Bulk Operations view data.
   *
   * @return bool
   *   Has access.
   */
  public static function customAccess(AccountInterface $account, ViewExecutable $view) {
    return TRUE;
  }

  public function getPluginId() {
    return parent::getPluginId(); // TODO: Change the autogenerated stub
  }

  public function getPluginDefinition() {
    return parent::getPluginDefinition(); // TODO: Change the autogenerated stub
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

  /**
   * Deletes all documents tracked on Search Api for an ADO.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to delete all flavors from.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function trackDeleted(EntityInterface $entity, array $processors = []) {

    if (empty($processors)) {
      // Never delete everything blindly oK?
      return 0;
    }
    $datasource_id = 'strawberryfield_flavor_datasource';
    $limit = 200;
    $parent_entity_index_needs_update = FALSE;
    $deleted = 0;
    foreach (StrawberryfieldFlavorDatasource::getValidIndexes() as $index) {
      $query = $index->query(['offset' => 0, 'limit' => $limit]);
      $query->addCondition('search_api_datasource', $datasource_id)
        ->addCondition('uuid', $entity->uuid())->addCondition('processor_id', $processors, 'IN');
      $query->setOption('search_api_retrieved_field_values', ['id' => 'id']);
      // Query breaks if not, because standard hl is enabled for all fields.
      // and normal hl offsets on OCR HL specific ones.
      $query->setOption('ocr_highlight', 'on');
      // We want all documents removed. No server access needed here.
      $query->setOption('search_api_bypass_access', TRUE);
      $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);
      try {
        $results = $query->execute();
        $max = $newcount = $results->getResultCount();
        $tracked_ids = [];
        $i = 0;
        // Only reason we use $newcount and $max is in the rare case
        // that while untracking deletion is happening in real time
        // and the actual $newcount decreases "live"
        while (count($tracked_ids) < $max && $newcount > 0) {
          $i++;
          foreach ($results->getResultItems() as $item) {
            // The tracker methods above prepend the datasource id, so we need to
            // workaround it by removing it beforehand.
            [$unused, $raw_id] = Utility::splitCombinedId($item->getId());
            $tracked_ids[] = $raw_id;
          }
          // If there are still more left, change the range and query again.
          if (count($tracked_ids) < $max) {
            $query = $query->getOriginalQuery();
            $query->range($limit * $i, $limit);
            $results = $query->execute();
            $newcount = $results->getResultCount();
          }
        }
        // Untrack after all possible query calls with offsets.
        if (count($tracked_ids) > 0) {
          $parent_entity_index_needs_update = TRUE;
          $index->trackItemsDeleted($datasource_id, $tracked_ids);
          // Removes temporary stored Flavors from Key Collection
          $this->keyValue
            ->get(StrawberryfieldFlavorDatasource::SBFL_KEY_COLLECTION)
            ->deleteMultiple($tracked_ids);
          $deleted = $deleted + count($tracked_ids);
        }
      }
      catch (SearchApiException $searchApiException) {
        watchdog_exception('strawberryfield', $searchApiException, 'We could not delete and untrack Strawberry Flavor Documents from Index because the Solr Query returned an exception at server level.');
      }
    }

    if ($parent_entity_index_needs_update && $entity->field_sbf_nodetonode instanceof EntityReferenceFieldItemListInterface) {
      $indexes = [];
      /** @var \Drupal\search_api\Plugin\search_api\datasource\ContentEntityTrackingManager $tracking_manager */
      $tracking_manager = \Drupal::getContainer()
        ->get('search_api.entity_datasource.tracking_manager');
      foreach ($entity->field_sbf_nodetonode->referencedEntities() as $key => $referencedEntity) {
        if (!isset($indexes[$referencedEntity->getType()])) {
          $indexes[$referencedEntity->getType()]
            = $tracking_manager->getIndexesForEntity($referencedEntity);
        }
        $entity_id = $referencedEntity->id();
        $languages = $referencedEntity->getTranslationLanguages();
        $combine_id = function ($langcode) use ($entity_id) {
          return $entity_id . ':' . $langcode;
        };
        $updated_item_ids = array_map($combine_id, array_keys($languages));
        if (isset($indexes[$referencedEntity->getType()])) {
          foreach ($indexes[$referencedEntity->getType()] as $index) {
            $index->trackItemsUpdated('entity:node', $updated_item_ids);
          }
        }
      }
    }
    return $deleted;
  }
}
